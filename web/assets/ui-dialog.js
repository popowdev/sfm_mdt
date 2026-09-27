(function () {
  if (window.MDT_CONFIRM) return;
  var CSS = '' +
    '.sdlg-ov{position:fixed;inset:0;background:rgba(2,6,23,.72);display:flex;align-items:flex-start;justify-content:center;padding:80px 16px 30px;z-index:3000;overflow-y:auto;font-family:var(--font);}' +
    '.sdlg-box{width:100%;max-width:440px;background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:22px;box-shadow:0 24px 60px rgba(0,0,0,.5);}' +
    '.sdlg-box h3{font-size:1.1em;margin:0 0 14px;display:flex;align-items:center;gap:9px;color:var(--text-strong);}' +
    '.sdlg-box h3 i{color:var(--accent-2,#818cf8);}' +
    '.sdlg-msg{font-size:.92em;color:var(--muted);line-height:1.55;margin-bottom:18px;white-space:pre-wrap;}' +
    '.sdlg-lbl{display:block;font-size:.8em;font-weight:700;color:var(--muted);margin-bottom:6px;}' +
    '.sdlg-in{width:100%;background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:10px 12px;color:var(--text);font-family:inherit;font-size:.9em;}' +
    '.sdlg-in:focus{outline:none;border-color:var(--accent);}' +
    'textarea.sdlg-in{min-height:110px;resize:vertical;line-height:1.5;}' +
    '.sdlg-foot{display:flex;justify-content:flex-end;gap:9px;margin-top:18px;}' +
    '.sdlg-btn{padding:10px 16px;border:none;border-radius:10px;font-weight:700;font-size:.88em;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:7px;}' +
    '.sdlg-ghost{background:var(--surface-2);color:var(--text);border:1px solid var(--border);}' +
    '.sdlg-ghost:hover{border-color:var(--accent);}' +
    '.sdlg-primary{background:var(--accent);color:#fff;}' +
    '.sdlg-danger{background:#dc2626;color:#fff;}' +
    '.sdlg-danger:hover{background:#ef4444;}';
  var styled = false;
  function injectCSS() { if (styled) return; styled = true; var st = document.createElement('style'); st.textContent = CSS; document.head.appendChild(st); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }

  function build(kind, message, def, opts) {
    injectCSS();
    opts = opts || {};
    return new Promise(function (resolve) {
      var ov = document.createElement('div'); ov.className = 'sdlg-ov';
      var isPrompt = kind === 'prompt', isConfirm = kind === 'confirm';
      var icon = opts.icon || (isConfirm ? (opts.danger ? 'fa-triangle-exclamation' : 'fa-circle-question') : (isPrompt ? 'fa-pen' : 'fa-circle-info'));
      var title = opts.title || (isConfirm ? 'Confirmer' : (isPrompt ? '' : 'Information'));
      var okLabel = opts.okLabel || (isConfirm ? 'Confirmer' : (isPrompt ? 'Valider' : 'OK'));
      var inHtml = '';
      if (isPrompt) {
        inHtml = opts.multiline
          ? '<label class="sdlg-lbl">' + esc(message) + '</label><textarea class="sdlg-in" id="sdlgIn" placeholder="' + esc(opts.placeholder || '') + '"></textarea>'
          : '<label class="sdlg-lbl">' + esc(message) + '</label><input type="text" class="sdlg-in" id="sdlgIn" placeholder="' + esc(opts.placeholder || '') + '">';
      } else {
        inHtml = '<div class="sdlg-msg">' + esc(message) + '</div>';
      }
      var cancelBtn = (isPrompt || isConfirm) ? '<button class="sdlg-btn sdlg-ghost" id="sdlgCancel">Annuler</button>' : '';
      var okCls = 'sdlg-primary'; if (opts.danger) okCls = 'sdlg-danger';
      ov.innerHTML = '<div class="sdlg-box" role="dialog">' +
        (title ? '<h3><i class="fa-solid ' + icon + '"></i> ' + esc(title) + '</h3>' : '') +
        inHtml +
        '<div class="sdlg-foot">' + cancelBtn + '<button class="sdlg-btn ' + okCls + '" id="sdlgOk">' + esc(okLabel) + '</button></div>' +
      '</div>';
      document.body.appendChild(ov);
      var inp = ov.querySelector('#sdlgIn');
      if (inp && def != null) inp.value = def;
      function done(val) { document.removeEventListener('keydown', onKey, true); ov.remove(); resolve(val); }
      function ok() { done(isPrompt ? (inp ? inp.value : '') : true); }
      function cancel() { done(isPrompt ? null : false); }
      function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); cancel(); } else if (e.key === 'Enter' && (!isPrompt || !opts.multiline)) { e.preventDefault(); ok(); } }
      ov.querySelector('#sdlgOk').onclick = ok;
      var cb = ov.querySelector('#sdlgCancel'); if (cb) cb.onclick = cancel;
      document.addEventListener('keydown', onKey, true);
      setTimeout(function () { if (inp) { inp.focus(); inp.select && inp.select(); } else { ov.querySelector('#sdlgOk').focus(); } }, 40);
    });
  }
  window.MDT_CONFIRM = function (message, opts) { return build('confirm', message, null, opts); };
  window.MDT_PROMPT = function (message, def, opts) { return build('prompt', message, def, opts); };
  window.MDT_ALERT = function (message, opts) { return build('alert', message, null, opts); };
})();
