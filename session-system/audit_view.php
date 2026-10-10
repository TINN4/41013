<?php
require_once __DIR__ . '/includes/auth.php';
$u = ssms_require('audit.view');
$filter = ssms_text((string)($_GET['action'] ?? ''), 40);
$rows = ssms_audit_read(300, $filter ?: null);
[$intact, $brokenAt] = ssms_audit_verify();
$page_title = 'Audit Log'; $page_sub = 'Read-only record of security and legislative-record events'; $active_page = 'audit';
include __DIR__ . '/includes/header.php';
?>
<div class="alert <?= $intact ? 'alert-ok' : 'alert-bad' ?>" role="status"><i class="fa-solid <?= $intact ? 'fa-link' : 'fa-triangle-exclamation' ?>"></i>
  <?= $intact ? 'Hash chain verified: no log entry has been altered or removed.' : 'INTEGRITY WARNING: the audit chain is broken near entry #' . (int)$brokenAt . '. Preserve the file and investigate.' ?></div>
<div class="card">
  <form method="GET" class="select-session-bar"><div class="form-group"><label for="action">Filter by action prefix (e.g. auth, mfa, minutes, session)</label><input type="text" id="action" name="action" value="<?= ssms_e($filter) ?>"></div><button class="btn btn-navy" type="submit">Filter</button></form>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>#</th><th>Time</th><th>Actor</th><th>Action</th><th>Target</th><th>Result</th><th>IP</th><th>Context</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= (int)$r['id'] ?></td><td class="mono" style="font-size:11px;"><?= ssms_e($r['ts']) ?></td>
        <td><?= ssms_e(($r['actor_id'] ?? '—') . ($r['actor_role'] ? ' (' . $r['actor_role'] . ')' : '')) ?></td>
        <td><?= ssms_e($r['action']) ?></td><td><?= ssms_e($r['target'] ?? '') ?></td>
        <td><span class="badge <?= $r['result'] === 'ok' ? 'badge-ok' : ($r['result'] === 'failed' || $r['result'] === 'denied' ? 'badge-bad' : 'badge-warn') ?>"><?= ssms_e($r['result']) ?></span></td>
        <td class="mono" style="font-size:11px;"><?= ssms_e($r['ip']) ?></td>
        <td style="font-size:11.5px;max-width:300px;word-break:break-word;"><?= ssms_e(json_encode($r['ctx'], JSON_UNESCAPED_UNICODE)) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8">No entries.</td></tr><?php endif; ?>
    </tbody></table></div>
  <p style="font-size:12px;color:var(--ink-600);">Showing the most recent 300 matching entries. The log can only be appended to by the application. Retention period: to be set by the authorized office (not defined by this system).</p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
