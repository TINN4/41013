<?php
require_once __DIR__ . '/includes/auth.php';
$admin = ssms_require('settings.manage');
$set = ssms_settings();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = (string)($_POST['qtype'] ?? 'majority');
    if (!in_array($type, ['majority', 'fraction', 'fixed'], true)) $type = 'majority';
    $num = max(1, min(100, (int)($_POST['numerator'] ?? 1))); $den = max(1, min(100, (int)($_POST['denominator'] ?? 2)));
    $fixed = max(1, min(500, (int)($_POST['fixed'] ?? 1)));
    $confirmed = !empty($_POST['confirmed']);
    $new = $set;
    $new['quorum_rule'] = ['basis' => 'eligible_members', 'type' => $type, 'numerator' => $num, 'denominator' => $den, 'fixed' => $type === 'fixed' ? $fixed : null,
        'confirmed_by_office' => $confirmed, 'confirmed_by' => $confirmed ? ssms_text((string)($_POST['confirmed_by'] ?? ''), 120) : null,
        'note' => ssms_text((string)($_POST['note'] ?? ''), 300)];
    $new['default_session_minutes'] = max(15, min(720, (int)($_POST['dur'] ?? 180)));
    $new['require_final_agenda_to_start'] = !empty($_POST['req_final']);
    if ($confirmed && $new['quorum_rule']['confirmed_by'] === '') { ssms_flash('bad', 'Enter who at the authorized office confirmed this rule.'); ssms_redirect('settings.php'); }
    ssms_mutate_obj('settings', fn($old) => $new);
    ssms_audit('settings.changed', 'quorum_rule', 'ok', ['before' => $set['quorum_rule'], 'after' => $new['quorum_rule']]);
    ssms_flash('ok', 'Settings saved.');
    ssms_redirect('settings.php');
}
$q = $set['quorum_rule'];
$page_title = 'System Settings'; $page_sub = 'Quorum rule and scheduling defaults'; $active_page = 'settings';
include __DIR__ . '/includes/header.php';
?>
<div class="notice-box"><strong>Quorum rule is configurable.</strong> This system does not assume the legal quorum. The default (majority of eligible members) is a placeholder; the authorized office must confirm the rule from its rules of procedure and record who confirmed it. Until confirmed, quorum displays carry a warning.</div>
<div class="card">
  <div class="card-head"><div><h3>Quorum &amp; session rules</h3><p>Members counted in the basis are those marked eligible in the member list (all members by default).</p></div></div>
  <form method="POST" style="max-width:520px;">
    <?= ssms_csrf_field() ?>
    <div class="form-group"><label for="qtype">Quorum rule</label>
      <select id="qtype" name="qtype">
        <option value="majority" <?= $q['type'] === 'majority' ? 'selected' : '' ?>>Majority of basis (more than half)</option>
        <option value="fraction" <?= $q['type'] === 'fraction' ? 'selected' : '' ?>>Fraction of basis (numerator / denominator, rounded up)</option>
        <option value="fixed" <?= $q['type'] === 'fixed' ? 'selected' : '' ?>>Fixed number of members</option>
      </select></div>
    <div class="form-row three">
      <div class="form-group"><label for="numerator">Numerator</label><input type="number" id="numerator" name="numerator" min="1" max="100" value="<?= (int)$q['numerator'] ?>"></div>
      <div class="form-group"><label for="denominator">Denominator</label><input type="number" id="denominator" name="denominator" min="1" max="100" value="<?= (int)$q['denominator'] ?>"></div>
      <div class="form-group"><label for="fixed">Fixed count</label><input type="number" id="fixed" name="fixed" min="1" max="500" value="<?= (int)($q['fixed'] ?? 1) ?>"></div>
    </div>
    <div class="form-group"><label for="note">Source / note</label><input type="text" id="note" name="note" maxlength="300" value="<?= ssms_e($q['note'] ?? '') ?>"></div>
    <div class="form-group"><label><input type="checkbox" name="confirmed" value="1" <?= !empty($q['confirmed_by_office']) ? 'checked' : '' ?>> Confirmed by the authorized office</label></div>
    <div class="form-group"><label for="confirmed_by">Confirmed by (name / office)</label><input type="text" id="confirmed_by" name="confirmed_by" maxlength="120" value="<?= ssms_e($q['confirmed_by'] ?? '') ?>"></div>
    <div class="form-group"><label for="dur">Assumed session length when no end time is given (minutes, used for conflict checks)</label><input type="number" id="dur" name="dur" min="15" max="720" value="<?= (int)$set['default_session_minutes'] ?>"></div>
    <div class="form-group"><label><input type="checkbox" name="req_final" value="1" <?= !empty($set['require_final_agenda_to_start']) ? 'checked' : '' ?>> Require a finalized agenda before a session can be started</label></div>
    <button class="btn btn-navy" type="submit">Save settings</button>
  </form>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
