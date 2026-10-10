(function () {
  var $ = function (id) { return document.getElementById(id); };
  if (!$('voiceCard') || !window.SSMSVoice) return;

  var settings = SSMSVoice.load(), transcript = '', mode = null;
  var bar = $('vBar'), gateEl = $('vGate'), status = $('vStatus'), note = $('vNote'), box = $('vText');

  function setStatus(t, cls) { status.textContent = t; status.className = 'badge ' + (cls || 'badge-grey'); }
  function showNote(t, bad) { note.hidden = !t; note.textContent = t || ''; note.className = 'vnote' + (bad ? ' bad' : ''); }
  function render(final, interim) {
    transcript = final;
    box.innerHTML = '';
    if (!final && !interim) { box.innerHTML = '<span class="ph">Listening&hellip; start speaking.</span>'; }
    else { box.appendChild(document.createTextNode(final + (final && interim ? ' ' : ''))); var i = document.createElement('span'); i.className = 'interim'; i.textContent = interim; box.appendChild(i); }
    $('vInsert').disabled = $('vClear').disabled = !final;
  }

  var engine = new SSMSVoice.Engine({
    lang: 'en-PH',
    onLevel: function (lvl, open, g) {
      bar.style.width = (lvl * 100) + '%'; bar.className = open ? 'open' : 'closed';
      gateEl.textContent = g === 'off' ? 'Gate: off' : (open ? 'Voice detected' : 'Noise blocked');
      gateEl.className = 'vgate ' + (g === 'off' ? '' : (open ? 'on' : 'off'));
    },
    onText: render,
    onError: function (e) {
      var m = { 'not-allowed': 'Microphone permission was denied. Allow mic access in your browser and try again.', 'service-not-allowed': 'Speech service is blocked in this browser.', 'network': 'Speech recognition needs an internet connection.', 'audio-capture': 'No microphone found.' };
      showNote(m[e] || ('Speech error: ' + e), true);
    },
    onStart: function (processed) {
      setStatus('Listening', 'badge-ok');
      showNote(processed ? 'Transcribing your processed (isolated) audio.' : 'Your browser transcribes from the default mic with its built-in noise suppression; custom filters affect the level meter only. Update Chrome/Edge for full isolation.');
    },
    onEnd: function () { reset(); }
  });
  settings = engine.settings;

  function reset() {
    engine.stop(); setStatus('Idle'); bar.style.width = '0'; gateEl.textContent = 'Gate: —'; gateEl.className = 'vgate';
    $('vStart').innerHTML = '<i class="fa-solid fa-microphone"></i> Start dictation'; $('vStart').classList.remove('btn-danger'); $('vStart').classList.add('btn-navy');
    $('vTest').innerHTML = '<i class="fa-solid fa-gauge-high"></i> Test mic'; testing = false; $('vStart').disabled = false;
    if (!transcript) box.innerHTML = '<span class="ph">Press <b>Start dictation</b> and begin speaking&hellip;</span>';
  }

  /* ---- mode switch ---- */
  function applyModeUI() {
    document.querySelectorAll('.vmode-opt').forEach(function (b) { var on = b.dataset.mode === settings.mode; b.classList.toggle('on', on); b.setAttribute('aria-checked', on); });
    $('vCustom').hidden = settings.mode !== 'custom';
  }
  function commit() { engine.setSettings(settings); }
  document.querySelectorAll('.vmode-opt').forEach(function (b) { b.addEventListener('click', function () { settings.mode = b.dataset.mode; applyModeUI(); commit(); if (settings.mode === 'custom') fillDevices(); }); });

  /* ---- custom controls ---- */
  var c = settings.custom;
  function bindCheck(id, key) { var el = $(id); el.checked = !!c[key]; el.addEventListener('change', function () { c[key] = el.checked; commit(); syncOut(); }); }
  function bindRange(id, key, out, fmt) { var el = $(id); el.value = c[key]; var o = $(out); var f = function () { o.textContent = fmt(+el.value); }; f(); el.addEventListener('input', function () { c[key] = +el.value; f(); commit(); }); }
  bindCheck('cNS', 'noiseSuppression'); bindCheck('cEC', 'echoCancellation'); bindCheck('cAG', 'autoGain'); bindCheck('cGate', 'gate');
  bindRange('cGateT', 'gateThreshold', 'oGate', function (v) { return v + '%'; });
  bindRange('cHP', 'highPass', 'oHP', function (v) { return v + ' Hz'; });
  bindRange('cLP', 'lowPass', 'oLP', function (v) { return (v / 1000).toFixed(1) + ' kHz'; });
  bindRange('cPres', 'presence', 'oPres', function (v) { return '+' + v + ' dB'; });
  function syncOut() { $('cGateT').disabled = !c.gate; }
  syncOut();
  // NS / EC / AGC are applied by the browser at capture time, so they take effect on next start.
  ['cNS', 'cEC', 'cAG'].forEach(function (id) { $(id).addEventListener('change', function () { if (engine.running) showNote('Mic option changed — stop and start again to apply.'); }); });

  async function fillDevices() {
    var sel = $('vDevice'), devs = await engine.listDevices();
    sel.innerHTML = '<option value="">System default</option>';
    devs.forEach(function (d, i) { var o = document.createElement('option'); o.value = d.deviceId; o.textContent = d.label || ('Microphone ' + (i + 1)); sel.appendChild(o); });
    sel.value = settings.deviceId || '';
  }
  $('vDevice').addEventListener('change', function () { settings.deviceId = this.value; commit(); });

  /* ---- actions ---- */
  var testing = false;
  $('vStart').addEventListener('click', async function () {
    if (engine.running) { reset(); return; }
    if (!engine.supported()) { showNote('Voice dictation needs Chrome or Edge with microphone access (and HTTPS or localhost).', true); return; }
    showNote('');
    try {
      setStatus('Starting…', 'badge-warn'); await engine.start();
      this.innerHTML = '<i class="fa-solid fa-stop"></i> Stop'; this.classList.remove('btn-navy'); this.classList.add('btn-danger');
      render(transcript, '');
      if (settings.mode === 'custom') fillDevices();
    } catch (e) { reset(); showNote(e && e.name === 'NotAllowedError' ? 'Microphone permission was denied.' : (e.message || 'Could not start the microphone.'), true); }
  });
  $('vTest').addEventListener('click', async function () {
    if (testing) { reset(); return; }
    if (engine.running) return;
    try { await engine.testStart(); testing = true; this.innerHTML = '<i class="fa-solid fa-stop"></i> Stop test'; $('vStart').disabled = true; setStatus('Testing mic', 'badge-warn'); showNote('Speak normally, then make some background noise — the meter turns grey when noise is being blocked.'); }
    catch (e) { reset(); showNote('Could not access the microphone.', true); }
  });
  $('vInsert').addEventListener('click', function () {
    var f = $('noteField'); if (!f || !transcript) return;
    f.value = (f.value ? f.value.replace(/\s*$/, ' ') : '') + transcript; transcript = ''; render('', ''); box.innerHTML = '<span class="ph">Inserted. Review the entry below, then Add Entry.</span>'; f.focus(); f.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });
  $('vClear').addEventListener('click', function () { transcript = ''; engine.finalText = ''; render('', ''); if (!engine.running) box.innerHTML = '<span class="ph">Press <b>Start dictation</b> and begin speaking&hellip;</span>'; });

  applyModeUI(); if (settings.mode === 'custom') fillDevices();
  window.addEventListener('beforeunload', function () { engine.stop(); });
})();
