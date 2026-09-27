(function () {
  if (window.MDT_IMG_PICKER) return;

  var UPLOAD_API = '/upload_api.php';
  var cb = null, isVideo = false, page = 1, pages = 1, busy = false, built = false;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }
  function token() { try { return localStorage.getItem('mdt_auth_token') || ''; } catch (e) { return ''; } }
  function headers(extra) {
    var h = extra || {};
    var t = token(); if (t) h['Authorization'] = 'Bearer ' + t;
    return h;
  }
  function notify(msg, ok) {
    if (window.MDT_TOAST) { ok ? MDT_TOAST.ok(msg) : MDT_TOAST.err(msg); }
    else if (typeof window.toast === 'function') { try { window.toast(msg, ok); } catch (e) {} }
  }
  function $(id) { return document.getElementById(id); }

  function injectCSS() {
    if ($('mdt-imgpick-css')) return;
    var css = ''
      + '.sip-ov{position:fixed;inset:0;background:rgba(2,6,23,.74);display:none;align-items:flex-start;justify-content:center;padding:70px 16px 30px;z-index:10040;overflow-y:auto;}'
      + '.sip-ov.open{display:flex;}'
      + '.sip-box{width:100%;max-width:560px;background:var(--surface,#111a2e);border:1px solid var(--border,#1e293b);border-radius:16px;padding:20px;box-shadow:0 24px 60px rgba(0,0,0,.55);font-family:var(--font,inherit);color:var(--text,#e2e8f0);}'
      + '.sip-box h3{margin:0 0 14px;font-size:1.02em;display:flex;align-items:center;gap:9px;color:var(--text-strong,#f1f5f9);}'
      + '.sip-tabs{display:flex;gap:6px;margin-bottom:14px;}'
      + '.sip-tabs button{flex:1;padding:9px 10px;border-radius:10px;border:1px solid var(--border,#1e293b);background:transparent;color:var(--muted,#94a3b8);font-weight:700;font-size:.8em;cursor:pointer;font-family:inherit;transition:.15s;}'
      + '.sip-tabs button.on{background:rgba(99,102,241,.14);border-color:#6366f1;color:#818cf8;}'
      + '.sip-drop{border:2px dashed var(--border,#1e293b);border-radius:14px;padding:30px 16px;text-align:center;color:var(--muted,#94a3b8);cursor:pointer;transition:.15s;font-size:.88em;}'
      + '.sip-drop:hover,.sip-drop.over{border-color:#6366f1;color:#818cf8;background:rgba(99,102,241,.06);}'
      + '.sip-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(96px,1fr));gap:8px;max-height:300px;overflow-y:auto;}'
      + '.sip-grid .sip-i{position:relative;aspect-ratio:1;border-radius:10px;overflow:hidden;border:2px solid transparent;cursor:pointer;background:var(--surface-2,#0f172a);}'
      + '.sip-grid .sip-i:hover{border-color:#6366f1;}'
      + '.sip-grid .sip-i img,.sip-grid .sip-i video{width:100%;height:100%;object-fit:cover;display:block;}'
      + '.sip-grid .sip-vt{position:absolute;bottom:4px;right:4px;background:rgba(0,0,0,.7);color:#fff;font-size:.58em;font-weight:800;padding:2px 5px;border-radius:4px;}'
      + '.sip-pager{display:flex;align-items:center;justify-content:center;gap:12px;margin-top:12px;font-size:.8em;color:var(--muted-2,#64748b);}'
      + '.sip-pager button{background:var(--surface-2,#0f172a);border:1px solid var(--border,#1e293b);color:var(--muted,#94a3b8);border-radius:8px;padding:5px 11px;cursor:pointer;font-family:inherit;}'
      + '.sip-pager button:disabled{opacity:.4;cursor:default;}'
      + '.sip-link{display:flex;gap:8px;}'
      + '.sip-link input{flex:1;min-width:0;background:var(--surface-2,#0f172a);border:1px solid var(--border,#1e293b);border-radius:10px;padding:10px 12px;color:var(--text,#e2e8f0);font-family:inherit;font-size:.88em;}'
      + '.sip-link input:focus{outline:none;border-color:#6366f1;}'
      + '.sip-btn{background:#6366f1;color:#fff;border:none;border-radius:10px;padding:10px 15px;font-weight:700;font-size:.84em;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:7px;white-space:nowrap;}'
      + '.sip-btn:hover{background:#818cf8;} .sip-btn:disabled{opacity:.6;cursor:default;}'
      + '.sip-ghost{background:var(--surface-2,#0f172a);color:var(--text,#e2e8f0);border:1px solid var(--border,#1e293b);}'
      + '.sip-hint{font-size:.76em;color:var(--muted-2,#64748b);margin-top:7px;line-height:1.45;}'
      + '.sip-empty{text-align:center;padding:34px 14px;color:var(--muted-2,#64748b);font-size:.86em;}'
      + '.sip-foot{display:flex;justify-content:flex-end;margin-top:16px;}'
      + '.sip-paste{border:2px dashed var(--border,#1e293b);border-radius:14px;padding:26px 16px;text-align:center;color:var(--muted,#94a3b8);font-size:.88em;}';
    var st = document.createElement('style');
    st.id = 'mdt-imgpick-css'; st.textContent = css;
    (document.head || document.documentElement).appendChild(st);
  }

  function build() {
    if (built) return;
    injectCSS();
    var ov = document.createElement('div');
    ov.className = 'sip-ov'; ov.id = 'sip-ov';
    ov.innerHTML =
      '<div class="sip-box">' +
        '<h3><i class="fa-solid fa-images"></i> Choisir un média</h3>' +
        '<div class="sip-tabs">' +
          '<button type="button" id="sip-t-upload" class="on"><i class="fa-solid fa-upload"></i> Envoyer</button>' +
          '<button type="button" id="sip-t-lib"><i class="fa-solid fa-photo-film"></i> Mes médias</button>' +
          '<button type="button" id="sip-t-link"><i class="fa-solid fa-link"></i> Lien</button>' +
          '<button type="button" id="sip-t-paste"><i class="fa-solid fa-paste"></i> Coller</button>' +
        '</div>' +
        '<div id="sip-c-upload">' +
          '<div class="sip-drop" id="sip-drop"></div>' +
          '<input type="file" id="sip-file" style="display:none">' +
        '</div>' +
        '<div id="sip-c-lib" style="display:none;">' +
          '<div class="sip-grid" id="sip-grid"><div class="sip-empty">Chargement…</div></div>' +
          '<div class="sip-pager"><button type="button" id="sip-prev">&larr;</button><span id="sip-info"></span><button type="button" id="sip-next">&rarr;</button></div>' +
        '</div>' +
        '<div id="sip-c-link" style="display:none;">' +
          '<div class="sip-link"><input type="text" id="sip-url" placeholder="https://exemple.com/image.png"><button type="button" class="sip-btn" id="sip-import"><i class="fa-solid fa-download"></i> Importer</button></div>' +
          '<div class="sip-hint">L\'image est téléchargée, nettoyée puis rangée dans votre Hébergement (jpg, png, webp, gif — 25 Mo max).</div>' +
        '</div>' +
        '<div id="sip-c-paste" style="display:none;">' +
          '<div class="sip-paste" id="sip-pastezone"><i class="fa-solid fa-clipboard" style="font-size:1.5em;display:block;margin-bottom:9px;"></i>' +
            'Faites <b>Ctrl + V</b> pour coller une capture d\'écran<br><span style="font-size:.82em;">ou une image copiée depuis une autre page</span></div>' +
          '<div class="sip-hint">Fonctionne avec l\'outil Capture de Windows (Win + Maj + S).</div>' +
        '</div>' +
        '<div class="sip-foot"><button type="button" class="sip-btn sip-ghost" id="sip-cancel">Annuler</button></div>' +
      '</div>';
    document.body.appendChild(ov);

    $('sip-cancel').addEventListener('click', close);
    ['upload', 'lib', 'link', 'paste'].forEach(function (k) {
      $('sip-t-' + k).addEventListener('click', function () { tab(k); });
    });
    $('sip-prev').addEventListener('click', function () { turn(-1); });
    $('sip-next').addEventListener('click', function () { turn(1); });
    $('sip-import').addEventListener('click', importUrl);
    $('sip-drop').addEventListener('click', function () { $('sip-file').click(); });
    $('sip-file').addEventListener('change', function () {
      var f = this.files[0]; this.value = '';
      if (f) sendFile(f);
    });
    var drop = $('sip-drop');
    ['dragover', 'dragenter'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); });
    });
    drop.addEventListener('drop', function (e) {
      var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if (f) sendFile(f);
    });
    document.addEventListener('paste', function (e) {
      if (!$('sip-ov') || !$('sip-ov').classList.contains('open')) return;
      var items = (e.clipboardData && e.clipboardData.items) || [];
      for (var i = 0; i < items.length; i++) {
        if (items[i].kind === 'file') {
          var f = items[i].getAsFile();
          if (f) { e.preventDefault(); tab('paste'); sendFile(f); return; }
        }
      }
    });
    document.addEventListener('keydown', function (e) {
      if ((e.key === 'Escape' || e.keyCode === 27) && $('sip-ov') && $('sip-ov').classList.contains('open')) close();
    });
    built = true;
  }

  function resetDrop() {
    $('sip-drop').innerHTML = '<i class="fa-solid fa-cloud-arrow-up" style="font-size:1.6em;display:block;margin-bottom:8px;"></i>' +
      'Cliquez ou glissez un fichier ici<br><span style="font-size:.8em;">Rangé dans votre Hébergement</span>';
  }
  function tab(t) {
    ['upload', 'lib', 'link', 'paste'].forEach(function (k) {
      $('sip-t-' + k).classList.toggle('on', k === t);
      $('sip-c-' + k).style.display = k === t ? '' : 'none';
    });
    if (t === 'lib') load();
  }
  function close() {
    if ($('sip-ov')) $('sip-ov').classList.remove('open');
    cb = null;
  }
  function done(url, kind) {
    var f = cb;
    close();
    if (f) f(url, kind || 'image');
  }
  function load() {
    var g = $('sip-grid');
    g.innerHTML = '<div class="sip-empty">Chargement…</div>';
    fetch(UPLOAD_API + '?action=list&page=' + page + '&per_page=24', { headers: headers() })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.success) { g.innerHTML = '<div class="sip-empty">Erreur de chargement.</div>'; return; }
        pages = d.pages || 1;
        var items = (d.items || []).filter(function (it) { return it.kind === 'image' || (isVideo && it.kind === 'video'); });
        $('sip-info').textContent = 'Page ' + (d.page || 1) + ' / ' + pages;
        $('sip-prev').disabled = page <= 1;
        $('sip-next').disabled = page >= pages;
        if (!items.length) { g.innerHTML = '<div class="sip-empty">Aucun média dans votre Hébergement.</div>'; return; }
        g.innerHTML = items.map(function (it) {
          var m = it.kind === 'video'
            ? '<video src="' + esc(it.url) + '" muted></video><span class="sip-vt">VIDÉO</span>'
            : '<img src="' + esc(it.url) + '" loading="lazy" alt="">';
          return '<div class="sip-i" title="' + esc(it.name || '') + '" data-u="' + esc(it.url) + '" data-k="' + esc(it.kind) + '">' + m + '</div>';
        }).join('');
        Array.prototype.forEach.call(g.querySelectorAll('.sip-i'), function (el) {
          el.addEventListener('click', function () { done(el.getAttribute('data-u'), el.getAttribute('data-k')); });
        });
      })
      .catch(function () { g.innerHTML = '<div class="sip-empty">Erreur réseau.</div>'; });
  }
  function turn(d) { var p = page + d; if (p < 1 || p > pages) return; page = p; load(); }

  function sendFile(f) {
    if (busy || !f) return;
    busy = true;
    tab('upload');
    var drop = $('sip-drop');
    drop.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="font-size:1.6em;display:block;margin-bottom:8px;"></i>Envoi en cours…';
    var fd = new FormData(); fd.append('file', f, f.name || 'image.png');
    var xhr = new XMLHttpRequest();
    xhr.open('POST', UPLOAD_API + '?action=upload');
    var t = token(); if (t) xhr.setRequestHeader('Authorization', 'Bearer ' + t);
    xhr.upload.onprogress = function (ev) {
      if (ev.lengthComputable) {
        drop.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="font-size:1.6em;display:block;margin-bottom:8px;"></i>Envoi ' +
          Math.round(ev.loaded / ev.total * 100) + '%';
      }
    };
    xhr.onload = function () {
      busy = false; resetDrop();
      var d = null; try { d = JSON.parse(xhr.responseText); } catch (e) {}
      if (xhr.status === 200 && d && d.success && d.item) done(d.item.url, d.item.kind);
      else notify((d && d.error) || 'Envoi refusé', false);
    };
    xhr.onerror = function () { busy = false; resetDrop(); notify('Erreur réseau', false); };
    xhr.send(fd);
  }

  function importUrl() {
    if (busy) return;
    var url = ($('sip-url').value || '').trim();
    if (!url) { notify('Collez un lien d\'image', false); return; }
    busy = true;
    var b = $('sip-import');
    b.disabled = true; b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Import…';
    fetch(UPLOAD_API + '?action=import', {
      method: 'POST', headers: headers({ 'Content-Type': 'application/json' }), body: JSON.stringify({ url: url })
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        busy = false; b.disabled = false; b.innerHTML = '<i class="fa-solid fa-download"></i> Importer';
        if (d && d.success && d.item) done(d.item.url, d.item.kind);
        else notify((d && d.error) || 'Import échoué', false);
      })
      .catch(function () {
        busy = false; b.disabled = false; b.innerHTML = '<i class="fa-solid fa-download"></i> Importer';
        notify('Erreur réseau', false);
      });
  }

  window.MDT_IMG_PICKER = function (callback, opts) {
    build();
    cb = callback; isVideo = !!(opts && opts.video); page = 1;
    $('sip-file').setAttribute('accept', isVideo
      ? 'image/png,image/jpeg,image/gif,image/webp,video/mp4,video/webm,video/quicktime'
      : 'image/png,image/jpeg,image/gif,image/webp');
    $('sip-url').value = '';
    resetDrop();
    tab('upload');
    $('sip-ov').classList.add('open');
  };
  window.MDT_IMG_PICKER_CLOSE = close;
  if (!window.openImgPicker) window.openImgPicker = window.MDT_IMG_PICKER;
})();
