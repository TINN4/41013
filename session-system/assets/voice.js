/**
 * SSMS Voice-to-Text with input processing profiles (2 modes, Discord-style):
 *
 *  - "isolation": one-tap Voice Isolation. Browser noise suppression + echo cancel + auto gain,
 *                 plus a Web Audio chain: high-pass (cuts rumble/AC hum), low-pass (cuts hiss),
 *                 speech-band presence boost, and an ADAPTIVE noise gate that learns the room's
 *                 noise floor and only opens for speech-level audio.
 *  - "custom":    the user picks the microphone and tunes each stage by hand.
 *
 * The processed stream is what gets transcribed (SpeechRecognition.start(track), Chrome/Edge
 * with support). Where the browser can't accept a custom track, it falls back to the default mic
 * (browser's own noise suppression still applies) and the UI says so.
 */
(function () {
  var KEY = 'ssms_voice_settings_v1';
  var DEFAULTS = {
    mode: 'isolation', deviceId: '',
    custom: { noiseSuppression: true, echoCancellation: true, autoGain: true,
              highPass: 120, lowPass: 7600, gate: true, gateThreshold: 35, presence: 3 }
  };

  function load() {
    try { var s = JSON.parse(localStorage.getItem(KEY)); if (s) return { mode: s.mode === 'custom' ? 'custom' : 'isolation', deviceId: s.deviceId || '', custom: Object.assign({}, DEFAULTS.custom, s.custom) }; } catch (e) {}
    return JSON.parse(JSON.stringify(DEFAULTS));
  }
  function save(s) { try { localStorage.setItem(KEY, JSON.stringify(s)); } catch (e) {} }

  function profile(s) {
    if (s.mode === 'isolation') return { noiseSuppression: true, echoCancellation: true, autoGain: true, highPass: 100, lowPass: 7800, gate: 'adaptive', gateThreshold: 0, presence: 3 };
    var c = s.custom; return { noiseSuppression: c.noiseSuppression, echoCancellation: c.echoCancellation, autoGain: c.autoGain, highPass: c.highPass, lowPass: c.lowPass, gate: c.gate ? 'manual' : 'off', gateThreshold: c.gateThreshold, presence: c.presence };
  }

  function VoiceEngine(opts) { this.o = opts || {}; this.settings = load(); this.running = false; }

  VoiceEngine.prototype.setSettings = function (s) { this.settings = s; save(s); if (this.running && this.live) this._applyLive(); };

  VoiceEngine.prototype.supported = function () { return !!(window.SpeechRecognition || window.webkitSpeechRecognition) && !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia); };

  VoiceEngine.prototype.listDevices = async function () {
    try { var d = await navigator.mediaDevices.enumerateDevices(); return d.filter(function (x) { return x.kind === 'audioinput'; }); } catch (e) { return []; }
  };

  VoiceEngine.prototype._build = async function () {
    var p = profile(this.settings);
    var audio = { noiseSuppression: !!p.noiseSuppression, echoCancellation: !!p.echoCancellation, autoGainControl: !!p.autoGain, channelCount: 1 };
    if (this.settings.deviceId) audio.deviceId = { exact: this.settings.deviceId };
    this.raw = await navigator.mediaDevices.getUserMedia({ audio: audio });

    var AC = window.AudioContext || window.webkitAudioContext;
    var ctx = this.ctx = new AC();
    var src = ctx.createMediaStreamSource(this.raw);
    var hp = this.hp = ctx.createBiquadFilter(); hp.type = 'highpass'; hp.Q.value = 0.707;
    var lp = this.lp = ctx.createBiquadFilter(); lp.type = 'lowpass'; lp.Q.value = 0.707;
    var pres = this.pres = ctx.createBiquadFilter(); pres.type = 'peaking'; pres.frequency.value = 2800; pres.Q.value = 0.9;
    var an = this.an = ctx.createAnalyser(); an.fftSize = 1024;
    var gate = this.gateNode = ctx.createGain();
    var comp = ctx.createDynamicsCompressor(); comp.threshold.value = -24; comp.ratio.value = 3; comp.attack.value = 0.005; comp.release.value = 0.2;
    var dest = this.dest = ctx.createMediaStreamDestination();
    src.connect(hp); hp.connect(lp); lp.connect(pres); pres.connect(an); pres.connect(gate); gate.connect(comp); comp.connect(dest);
    this.floor = 0.004; this.gateOpen = true;
    this._applyLive();

    var self = this, buf = new Float32Array(an.fftSize);
    this.timer = setInterval(function () {
      an.getFloatTimeDomainData(buf);
      var sum = 0; for (var i = 0; i < buf.length; i++) sum += buf[i] * buf[i];
      var rms = Math.sqrt(sum / buf.length), pr = self.live, open = true;
      if (pr.gate === 'adaptive') {
        // learn floor: fall fast to quieter levels, creep up slowly (so speech isn't learned as noise)
        self.floor = rms < self.floor ? self.floor * 0.9 + rms * 0.1 : Math.min(self.floor * 1.0015 + 1e-6, 0.05);
        var thr = Math.max(self.floor * 2.8, 0.008);
        open = rms > thr || (self.gateOpen && rms > thr * 0.6);
      } else if (pr.gate === 'manual') {
        var t = 0.004 + (pr.gateThreshold / 100) * 0.06;   // 0..100 slider -> ~ -48..-18 dBFS
        open = rms > t || (self.gateOpen && rms > t * 0.6);
      }
      if (open !== self.gateOpen || self._first !== true) { self.gateOpen = open; self._first = true; }
      gate.gain.setTargetAtTime(open ? 1 : 0.0, ctx.currentTime, open ? 0.01 : 0.12);
      if (self.o.onLevel) self.o.onLevel(Math.min(1, rms * 9), open, pr.gate);
    }, 25);
    return dest.stream.getAudioTracks()[0];
  };

  VoiceEngine.prototype._applyLive = function () {
    var p = this.live = profile(this.settings);
    if (!this.hp) return;
    this.hp.frequency.value = p.highPass; this.lp.frequency.value = p.lowPass; this.pres.gain.value = p.presence;
  };

  VoiceEngine.prototype.start = async function () {
    if (this.running) return;
    var SR = window.SpeechRecognition || window.webkitSpeechRecognition, self = this;
    if (!SR) throw new Error('Speech recognition is not supported in this browser. Use Chrome or Edge.');
    var track = await this._build();
    this.running = true; this.finalText = '';
    var rec = this.rec = new SR();
    rec.lang = this.o.lang || 'en-PH'; rec.continuous = true; rec.interimResults = true;
    rec.onresult = function (e) {
      var interim = '';
      for (var i = e.resultIndex; i < e.results.length; i++) {
        var t = e.results[i][0].transcript;
        if (e.results[i].isFinal) self.finalText += t.trim() + ' '; else interim += t;
      }
      if (self.o.onText) self.o.onText(self.finalText.trim(), interim.trim());
    };
    rec.onerror = function (e) { if (e.error !== 'no-speech' && e.error !== 'aborted' && self.o.onError) self.o.onError(e.error); };
    rec.onend = function () { if (self.running) { try { self._startRec(track); } catch (e) {} } else if (self.o.onEnd) self.o.onEnd(); };
    this.processedUsed = this._startRec(track);
    if (this.o.onStart) this.o.onStart(this.processedUsed);
  };

  // Try feeding the processed track (newer Chromium); fall back to default mic.
  VoiceEngine.prototype._startRec = function (track) {
    try { this.rec.start(track); return true; } catch (e) {
      if (e && e.name === 'InvalidStateError') return this.processedUsed;
      try { this.rec.start(); } catch (e2) {} return false;
    }
  };

  VoiceEngine.prototype.stop = function () {
    this.running = false;
    try { this.rec && this.rec.stop(); } catch (e) {}
    clearInterval(this.timer);
    try { this.raw && this.raw.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
    try { this.ctx && this.ctx.close(); } catch (e) {}
    this.hp = null; this.ctx = null;
  };

  /** Mic test: level meter only, no transcription. */
  VoiceEngine.prototype.testStart = async function () { await this._build(); this.running = true; this.testing = true; };

  window.SSMSVoice = { Engine: VoiceEngine, load: load, save: save, defaults: DEFAULTS };
})();
