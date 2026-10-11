<?php
/**
 * PHP sessions kept in the database (table ssms_sessions) so a redeploy or container restart does not sign everybody out.
 * A named lock per session id gives the same "one request at a time per session" behaviour as file sessions.
 */
class SsmsDbSessionHandler implements SessionHandlerInterface, SessionIdInterface, SessionUpdateTimestampHandlerInterface {
    private ?string $lock = null;
    private function pdo(): PDO { return ssms_pdo('session'); }
    private function acquire(string $id): void {
        if ($this->lock !== null) return;
        $name = 'ssms_sess_' . substr(sha1($id), 0, 32);
        if ((int)$this->pdo()->query("SELECT GET_LOCK('$name', 30)")->fetchColumn() !== 1) throw new RuntimeException('session lock timeout');
        $this->lock = $name;
    }
    private function release(): void {
        if ($this->lock === null) return;
        $this->pdo()->query("SELECT RELEASE_LOCK('{$this->lock}')")->fetchColumn();
        $this->lock = null;
    }
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { $this->release(); return true; }
    public function read(string $id): string|false {
        $this->acquire($id);
        $st = $this->pdo()->prepare('SELECT data FROM ssms_sessions WHERE id = ?'); $st->execute([$id]);
        $d = $st->fetchColumn();
        return $d === false ? '' : (string)$d;
    }
    public function write(string $id, string $data): bool {
        $this->pdo()->prepare('INSERT INTO ssms_sessions (id, data, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)')
            ->execute([$id, $data, time()]);
        return true;
    }
    public function updateTimestamp(string $id, string $data): bool {
        $this->pdo()->prepare('UPDATE ssms_sessions SET updated_at = ? WHERE id = ?')->execute([time(), $id]);
        return true;
    }
    public function validateId(string $id): bool {
        $st = $this->pdo()->prepare('SELECT 1 FROM ssms_sessions WHERE id = ?'); $st->execute([$id]);
        return (bool)$st->fetchColumn();
    }
    public function create_sid(): string { return bin2hex(random_bytes(24)); }
    public function destroy(string $id): bool {
        $this->pdo()->prepare('DELETE FROM ssms_sessions WHERE id = ?')->execute([$id]);
        return true;
    }
    public function gc(int $max_lifetime): int|false {
        $st = $this->pdo()->prepare('DELETE FROM ssms_sessions WHERE updated_at < ?'); $st->execute([time() - max(60, $max_lifetime)]);
        return $st->rowCount();
    }
}
