<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/store.php';

$sessions = ssms_read('sessions');
usort($sessions, fn($a, $b) => strcmp($b['date'], $a['date']));

$selectedId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : ($_POST['session_id'] ?? null);
if (!$selectedId && $sessions) {
    $ongoing = array_values(array_filter($sessions, fn($s) => $s['status'] === 'Ongoing'));
    $selectedId = $ongoing ? $ongoing[0]['id'] : $sessions[0]['id'];
}
$selectedId = (int)$selectedId;

if (isset($_POST['action']) && $_POST['action'] === 'add_log') {
    $note = trim($_POST['note'] ?? '');
    if ($note !== '') {
        ssms_insert('proceedings', [
            'session_id' => $selectedId,
            'timestamp' => date('Y-m-d H:i'),
            'author' => $_SESSION['ssms_name'] ?? "Secretary's Office",
            'note' => $note,
        ]);
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    ssms_delete('proceedings', (int)$_POST['id']);
}

$logs = ssms_where('proceedings', 'session_id', $selectedId);
usort($logs, fn($a, $b) => strcmp($b['timestamp'], $a['timestamp']));
$currentSession = ssms_find('sessions', $selectedId);

$page_title = 'Proceedings Documentation';
$page_sub   = 'Log what happens during the session as it happens';
$active_page = 'proceedings';
include __DIR__ . '/../includes/header.php';
?>

<div class="card">
  <div class="select-session-bar">
    <div class="form-group">
      <label>Session</label>
      <form method="GET" id="sessSwitch">
        <select name="session_id" onchange="document.getElementById('sessSwitch').submit()">
          <?php foreach ($sessions as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $s['id'] == $selectedId ? 'selected' : '' ?>>
              <?= htmlspecialchars($s['title'], ENT_QUOTES) ?> &mdash; <?= htmlspecialchars($s['date'], ENT_QUOTES) ?> (<?= htmlspecialchars($s['status'], ENT_QUOTES) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  </div>
</div>

<?php if (!$currentSession): ?>
  <div class="empty-state"><i class="fa-solid fa-calendar-xmark"></i>No sessions available. Create one in Session Scheduling first.</div>
<?php else: ?>


<!-- ===== Voice to Text ===== -->
<div class="card" id="voiceCard">
  <div class="card-head">
    <div><h3><i class="fa-solid fa-microphone-lines" style="color:var(--blue-600);"></i> Voice to Text</h3><p>Dictate proceedings hands-free. Choose how your microphone is cleaned up.</p></div>
    <span class="badge badge-grey" id="vStatus">Idle</span>
  </div>

  <div class="vmode" role="radiogroup" aria-label="Voice input mode">
    <button type="button" class="vmode-opt" data-mode="isolation" role="radio">
      <span class="vm-ic"><i class="fa-solid fa-wand-magic-sparkles"></i></span>
      <span><b>Voice Isolation</b><small>Automatically removes background noise, keyboard clicks, fans and room echo. Recommended.</small></span>
    </button>
    <button type="button" class="vmode-opt" data-mode="custom" role="radio">
      <span class="vm-ic"><i class="fa-solid fa-sliders"></i></span>
      <span><b>Custom</b><small>Pick your mic and fine-tune each filter yourself.</small></span>
    </button>
  </div>

  <div id="vCustom" class="vcustom" hidden>
    <div class="form-group"><label>Microphone</label><select id="vDevice"><option value="">System default</option></select></div>
    <div class="vtoggles">
      <label><input type="checkbox" id="cNS"> Noise suppression</label>
      <label><input type="checkbox" id="cEC"> Echo cancellation</label>
      <label><input type="checkbox" id="cAG"> Auto gain</label>
      <label><input type="checkbox" id="cGate"> Noise gate</label>
    </div>
    <div class="vsliders">
      <div><label>Gate sensitivity <output id="oGate"></output></label><input type="range" id="cGateT" min="0" max="100"></div>
      <div><label>Cut low rumble (high-pass) <output id="oHP"></output></label><input type="range" id="cHP" min="20" max="400" step="10"></div>
      <div><label>Cut hiss (low-pass) <output id="oLP"></output></label><input type="range" id="cLP" min="3000" max="12000" step="100"></div>
      <div><label>Voice clarity boost <output id="oPres"></output></label><input type="range" id="cPres" min="0" max="9" step="1"></div>
    </div>
  </div>

  <div class="vmeter-row">
    <div class="vmeter"><div id="vBar"></div><div id="vGateTick"></div></div>
    <span class="vgate" id="vGate">Gate: &mdash;</span>
  </div>

  <div class="vtranscript" id="vText" aria-live="polite"><span class="ph">Press <b>Start dictation</b> and begin speaking&hellip;</span></div>
  <div id="vNote" class="vnote" hidden></div>

  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
    <button type="button" class="btn btn-navy" id="vStart"><i class="fa-solid fa-microphone"></i> Start dictation</button>
    <button type="button" class="btn btn-ghost" id="vTest"><i class="fa-solid fa-gauge-high"></i> Test mic</button>
    <button type="button" class="btn btn-gold" id="vInsert" disabled><i class="fa-solid fa-arrow-down"></i> Insert into entry</button>
    <button type="button" class="btn btn-ghost" id="vClear" disabled><i class="fa-solid fa-eraser"></i> Clear</button>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <div><h3>Add Proceedings Entry</h3><p>For: <?= htmlspecialchars($currentSession['title'], ENT_QUOTES) ?></p></div>
  </div>
  <form method="POST">
    <input type="hidden" name="action" value="add_log">
    <input type="hidden" name="session_id" value="<?= $selectedId ?>">
    <div class="form-group">
      <label>What just happened?</label>
      <textarea name="note" id="noteField" placeholder="e.g. Motion to approve the ordinance was seconded and carried unanimously." required></textarea>
    </div>
    <button type="submit" class="btn btn-navy"><i class="fa-solid fa-plus"></i> Add Entry</button>
  </form>
</div>

<div class="card">
  <div class="card-head">
    <div><h3>Session Log</h3><p><?= count($logs) ?> entr<?= count($logs) === 1 ? 'y' : 'ies' ?>, most recent first.</p></div>
  </div>
  <?php if ($logs): ?>
  <div class="log-feed">
    <?php foreach ($logs as $l): ?>
    <div class="log-entry">
      <div class="dot"></div>
      <div class="body">
        <div class="meta"><?= htmlspecialchars($l['timestamp'], ENT_QUOTES) ?> &middot; <?= htmlspecialchars($l['author'], ENT_QUOTES) ?>
          <form method="POST" style="display:inline;float:right;" onsubmit="return confirm('Delete this entry?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $l['id'] ?>"><input type="hidden" name="session_id" value="<?= $selectedId ?>"><button class="btn btn-ghost btn-sm" style="padding:2px 7px;"><i class="fa-solid fa-xmark"></i></button></form>
        </div>
        <div class="txt"><?= nl2br(htmlspecialchars($l['note'], ENT_QUOTES)) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
    <div class="empty-state"><i class="fa-solid fa-file-lines"></i>No proceedings logged yet for this session.</div>
  <?php endif; ?>
</div>

<?php endif; ?>
<script src="../assets/voice.js"></script>
<script src="../assets/voice-ui.js"></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
