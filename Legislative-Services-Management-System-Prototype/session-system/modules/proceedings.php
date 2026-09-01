<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/store.php';
ssms_require_staff(); // secretary/admin only — council members get redirected to Attendance

$sessions = array_values(array_filter(ssms_read('sessions'), fn($s) => empty($s['archived_at']))); // archived sessions live in the Archive page instead
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

<div class="card">
  <div class="card-head">
    <div><h3>Add Proceedings Entry</h3><p>For: <?= htmlspecialchars($currentSession['title'], ENT_QUOTES) ?></p></div>
  </div>

  <div class="form-group" style="margin-bottom:14px;">
    <label>How do you want to add this entry?</label>
    <div style="display:flex;gap:16px;flex-wrap:wrap;">
      <label style="display:flex;align-items:center;gap:6px;font-weight:500;font-size:13.5px;cursor:pointer;">
        <input type="radio" name="input_method" id="input-method-type" value="type" checked onchange="ssmsSetInputMethod()"> <i class="fa-solid fa-keyboard"></i> Type manually
      </label>
      <label style="display:flex;align-items:center;gap:6px;font-weight:500;font-size:13.5px;cursor:pointer;">
        <input type="radio" name="input_method" id="input-method-voice" value="voice" onchange="ssmsSetInputMethod()"> <i class="fa-solid fa-microphone"></i> Voice dictation
      </label>
    </div>
  </div>

  <div id="voice-panel" style="display:none;padding:12px 14px;border:1px dashed var(--line);border-radius:12px;margin-bottom:16px;background:var(--paper-alt);">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
      <label for="rec-lang-select" style="font-size:12px;font-weight:600;color:var(--ink-600);white-space:nowrap;"><i class="fa-solid fa-language"></i> Spoken language:</label>
      <select id="rec-lang-select" style="flex:1;min-width:220px;padding:7px 10px;border-radius:8px;border:1px solid var(--line);font-size:12.5px;background:#fff;">
        <option value="en-US">English</option>
        <option value="fil-PH">Tagalog / Filipino</option>
      </select>
    </div>
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
      <button type="button" id="rec-btn" class="btn btn-gold btn-sm" onclick="ssmsToggleRecording()"><i class="fa-solid fa-microphone"></i> Record &amp; Transcribe</button>
      <span id="rec-status" style="font-size:12.5px;color:var(--ink-600);">Pick your spoken language above, click Record, then allow microphone access when your browser asks.</span>
    </div>
  </div>

  <form method="POST" id="proc-form"><?php ssms_csrf_field(); ?>
    <input type="hidden" name="action" value="add_log">
    <input type="hidden" name="session_id" value="<?= $selectedId ?>">
    <div class="form-group">
      <label>What just happened?</label>
      <textarea name="note" id="proc-note" placeholder="e.g. Motion to approve the ordinance was seconded and carried unanimously." required></textarea>
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
          <form method="POST" style="display:inline;float:right;" onsubmit="return confirm('Delete this entry?');"><?php ssms_csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $l['id'] ?>"><input type="hidden" name="session_id" value="<?= $selectedId ?>"><button class="btn btn-ghost btn-sm" style="padding:2px 7px;"><i class="fa-solid fa-xmark"></i></button></form>
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

<script>
// --- Input method toggle ---------------------------------------------------
function ssmsSetInputMethod() {
  const voiceChosen = document.getElementById('input-method-voice').checked;
  const panel = document.getElementById('voice-panel');
  panel.style.display = voiceChosen ? 'block' : 'none';
  // Leaving voice mode mid-recording should stop it cleanly rather than
  // leaving the microphone open in the background.
  if (!voiceChosen && ssmsRecording) {
    ssmsStopRecordingUI();
  }
}

// --- Speech-to-text using the browser's built-in engine -------------------
// Real-time, word-by-word auto-type, powered entirely by the browser
// (Web Speech API / SpeechRecognition) — no server, no external service.
// This works on any host, including plain PHP hosting, because nothing
// leaves the browser except the page itself.
//
// Trade-offs: this engine locks to ONE language per recording session
// (pick English or Tagalog/Filipino from the dropdown before you start).
// It requires Chrome or Edge — Firefox/Safari have limited or no support.
// It also requires HTTPS (or localhost) and an active internet connection,
// since the browser sends audio to its speech-recognition backend.

let ssmsRecognition = null;
let ssmsRecording = false;
let ssmsBaseNoteText = ''; // note text present before this recording session started
let ssmsFinalizedText = ''; // finalized transcript accumulated across restarts
let ssmsRestartFailCount = 0; // guards against a silent infinite restart loop
let ssmsIntentionalStop = false; // distinguishes "user clicked Stop" from an unexpected end

function ssmsToggleRecording() {
  if (!ssmsRecording) {
    ssmsStartRecording();
  } else {
    ssmsIntentionalStop = true;
    ssmsStopRecordingUI();
  }
}

function ssmsStartRecording() {
  const status = document.getElementById('rec-status');
  const SpeechRecognitionCtor = window.SpeechRecognition || window.webkitSpeechRecognition;

  if (!SpeechRecognitionCtor) {
    status.textContent = 'Voice recording isn\'t supported in this browser. Please use Chrome or Edge, or switch to "Type manually" above.';
    return;
  }
  if (!window.isSecureContext) {
    status.textContent = 'Voice recording needs a secure (https://) connection. Please use "Type manually" instead.';
    return;
  }

  // Ask for microphone access explicitly first. Doing this ourselves (rather
  // than letting SpeechRecognition trigger the prompt implicitly) gives a
  // clear, specific error message instead of a silent failure if the mic is
  // blocked, missing, or already in use by another app/tab.
  status.textContent = 'Requesting microphone access…';
  navigator.mediaDevices.getUserMedia({ audio: true })
    .then((stream) => {
      stream.getTracks().forEach(track => track.stop()); // we only needed the permission prompt, not this stream — SpeechRecognition opens its own
      ssmsBeginRecognition();
    })
    .catch((err) => {
      if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
        status.textContent = 'Microphone access was blocked. Click the padlock icon in your address bar, allow the microphone, then try again.';
      } else if (err.name === 'NotFoundError') {
        status.textContent = 'No microphone was found on this device.';
      } else if (err.name === 'NotReadableError') {
        status.textContent = 'The microphone is already in use by another app or browser tab.';
      } else {
        status.textContent = 'Could not access the microphone (' + err.name + '). Please try again, or switch to "Type manually" above.';
      }
    });
}

function ssmsBeginRecognition() {
  const btn = document.getElementById('rec-btn');
  const status = document.getElementById('rec-status');
  const langSelect = document.getElementById('rec-lang-select');
  const textarea = document.getElementById('proc-note');
  const SpeechRecognitionCtor = window.SpeechRecognition || window.webkitSpeechRecognition;

  ssmsBaseNoteText = textarea.value ? textarea.value.trim() + ' ' : '';
  ssmsFinalizedText = '';
  ssmsRestartFailCount = 0;
  ssmsIntentionalStop = false;

  ssmsRecognition = new SpeechRecognitionCtor();
  ssmsRecognition.lang = langSelect.value;
  ssmsRecognition.continuous = true;
  ssmsRecognition.interimResults = true;

  ssmsRecognition.onresult = (event) => {
    let interim = '';
    for (let i = event.resultIndex; i < event.results.length; i++) {
      const transcript = event.results[i][0].transcript;
      if (event.results[i].isFinal) {
        ssmsFinalizedText += transcript + ' ';
      } else {
        interim += transcript;
      }
    }
    textarea.value = (ssmsBaseNoteText + ssmsFinalizedText + interim).trim();
    ssmsRestartFailCount = 0; // getting real results means the engine is healthy again
  };

  ssmsRecognition.onerror = (event) => {
    if (event.error === 'not-allowed' || event.error === 'permission-denied') {
      status.textContent = 'Microphone access was blocked. Please allow microphone access and try again.';
      ssmsIntentionalStop = true;
      ssmsStopRecordingUI();
    } else if (event.error === 'no-speech') {
      // Silence is normal mid-recording — onend below will auto-restart.
    } else if (event.error === 'audio-capture') {
      status.textContent = 'No microphone detected. Please check your device and try again.';
      ssmsIntentionalStop = true;
      ssmsStopRecordingUI();
    } else if (event.error === 'network') {
      status.textContent = 'Network issue reaching the speech service — retrying…';
      // let onend's auto-restart logic handle recovery
    } else if (event.error === 'aborted') {
      // Happens on our own stop() calls — not a real error, ignore.
    } else {
      status.textContent = 'Recording error: ' + event.error + '. Click Record to try again.';
      ssmsIntentionalStop = true;
      ssmsStopRecordingUI();
    }
  };

  // The browser engine stops itself after a pause in speech, or sometimes
  // unexpectedly. If the user hasn't clicked Stop, restart it so recording
  // feels continuous — but give up after repeated immediate failures
  // instead of spinning forever and pretending to still be recording.
  ssmsRecognition.onend = () => {
    if (ssmsRecording && !ssmsIntentionalStop) {
      ssmsRestartFailCount++;
      if (ssmsRestartFailCount > 5) {
        status.textContent = 'Recording stopped unexpectedly after repeated errors. Your text so far is kept below — click Record to try again.';
        ssmsStopRecordingUI();
        return;
      }
      try {
        ssmsRecognition.start();
      } catch (err) {
        status.textContent = 'Recording stopped. Click Record to try again.';
        ssmsStopRecordingUI();
      }
    }
  };

  try {
    ssmsRecognition.start();
    ssmsRecording = true;
    btn.innerHTML = '<i class="fa-solid fa-stop"></i> Stop &amp; Transcribe';
    btn.classList.add('btn-danger');
    langSelect.disabled = true;
    status.textContent = 'Listening — speak now. Click "Stop & Transcribe" when done.';
  } catch (err) {
    status.textContent = 'Could not start recording. Please try again.';
  }
}

function ssmsStopRecordingUI() {
  ssmsRecording = false;
  if (ssmsRecognition) {
    try { ssmsRecognition.stop(); } catch (err) { /* already stopped, ignore */ }
  }
  const btn = document.getElementById('rec-btn');
  const status = document.getElementById('rec-status');
  const langSelect = document.getElementById('rec-lang-select');
  btn.innerHTML = '<i class="fa-solid fa-microphone"></i> Record &amp; Transcribe';
  btn.classList.remove('btn-danger');
  langSelect.disabled = false;
  if (status.textContent.indexOf('error') === -1 && status.textContent.indexOf('blocked') === -1) {
    status.textContent = 'Transcribed live in your browser. Review the text below, then click Add Entry.';
  }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
