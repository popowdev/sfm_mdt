(function () {
  if (window.MDT_TACPANEL) return;

  var POS_KEY = 'mdt_tac_pos', SIZE_KEY = 'mdt_tac_size', MIN_TOP = 60;
  var panel = null, frame = null, loaded = false, open = false;

  var CSS =
    '.tacw{position:fixed;z-index:9996;width:940px;height:620px;min-width:420px;min-height:340px;' +
    'background:#1e293b;border:1px solid #334155;border-radius:14px;box-shadow:0 24px 70px rgba(0,0,0,.55);' +
    'display:none;flex-direction:column;overflow:hidden;font-family:"Inter",sans-serif;}' +
    '.tacw.show{display:flex;}' +
    '.tacw-head{display:flex;align-items:center;gap:10px;padding:10px 12px;background:#0f172a;' +
    'border-bottom:1px solid #334155;cursor:move;user-select:none;flex:0 0 auto;}' +
    '.tacw-head b{font-size:.86rem;color:#e2e8f0;font-weight:700;flex:1;}' +
    '.tacw-head i.lead{color:#22d3ee;}' +
    '.tacw-head button{border:none;background:none;color:#94a3b8;width:28px;height:28px;border-radius:7px;' +
    'cursor:pointer;font-size:.85rem;display:flex;align-items:center;justify-content:center;}' +
    '.tacw-head button:hover{background:#1e293b;color:#e2e8f0;}' +
    '.tacw-body{flex:1;position:relative;min-height:0;background:#0b1220;}' +
    '.tacw-body iframe{position:absolute;inset:0;width:100%;height:100%;border:0;display:block;}' +
    '.tacw-grip{position:absolute;right:0;bottom:0;width:20px;height:20px;cursor:nwse-resize;z-index:5;}' +
    '.tacw-grip::after{content:"";position:absolute;right:4px;bottom:4px;width:8px;height:8px;' +
    'border-right:2px solid #64748b;border-bottom:2px solid #64748b;}' +
    '@media (max-width:700px){.tacw{left:8px!important;right:8px;top:64px!important;width:auto!important;height:70vh!important;}}';

  function injectCSS() {
    if (document.getElementById('tacw-css')) return;
    var st = document.createElement('style');
    st.id = 'tacw-css';
    st.textContent = CSS;
    document.head.appendChild(st);
  }

  function clampInt(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

  function frameGuard(on) {
    if (frame) frame.style.pointerEvents = on ? 'none' : '';
  }

  function build() {
    injectCSS();
    panel = document.createElement('div');
    panel.className = 'tacw';
    panel.innerHTML =
      '<div class="tacw-head" id="tacwHead">' +
        '<i class="fa-solid fa-map-location-dot lead"></i><b>Carte tactique</b>' +
        '<button type="button" id="tacwOpen" title="Ouvrir en pleine page"><i class="fa-solid fa-up-right-from-square"></i></button>' +
        '<button type="button" id="tacwClose" title="Fermer"><i class="fa-solid fa-xmark"></i></button>' +
      '</div>' +
      '<div class="tacw-body">' +
        '<iframe id="tacwFrame" src="about:blank" title="Carte tactique"></iframe>' +
        '<div class="tacw-grip" id="tacwGrip"></div>' +
      '</div>';
    document.body.appendChild(panel);
    frame = panel.querySelector('#tacwFrame');

    var size = null, pos = null;
    try { size = JSON.parse(localStorage.getItem(SIZE_KEY) || 'null'); } catch (e) {}
    try { pos = JSON.parse(localStorage.getItem(POS_KEY) || 'null'); } catch (e) {}
    if (size && size.w && size.h) {
      panel.style.width = clampInt(size.w, 420, window.innerWidth - 20) + 'px';
      panel.style.height = clampInt(size.h, 340, window.innerHeight - 20) + 'px';
    }
    if (pos && typeof pos.top === 'number') {
      panel.style.top = clampInt(pos.top, MIN_TOP, window.innerHeight - 120) + 'px';
      panel.style.left = clampInt(pos.left, 0, window.innerWidth - 200) + 'px';
    } else {
      panel.style.top = '80px';
      panel.style.left = Math.max(12, (window.innerWidth - 940) / 2) + 'px';
    }

    panel.querySelector('#tacwClose').onclick = function () { toggle(false); };
    panel.querySelector('#tacwOpen').onclick = function () { window.open('/carte/', '_blank'); };

    var head = panel.querySelector('#tacwHead');
    head.addEventListener('mousedown', function (e) {
      if (e.target.closest('button')) return;
      e.preventDefault();
      frameGuard(true);
      var sx = e.clientX, sy = e.clientY, st = panel.offsetTop, sl = panel.offsetLeft;
      var move = function (ev) {
        panel.style.top = clampInt(st + ev.clientY - sy, MIN_TOP, window.innerHeight - 60) + 'px';
        panel.style.left = clampInt(sl + ev.clientX - sx, -panel.offsetWidth + 140, window.innerWidth - 140) + 'px';
      };
      var up = function () {
        document.removeEventListener('mousemove', move);
        document.removeEventListener('mouseup', up);
        frameGuard(false);
        try { localStorage.setItem(POS_KEY, JSON.stringify({ top: panel.offsetTop, left: panel.offsetLeft })); } catch (err) {}
      };
      document.addEventListener('mousemove', move);
      document.addEventListener('mouseup', up);
    });

    panel.querySelector('#tacwGrip').addEventListener('pointerdown', function (e) {
      e.preventDefault();
      e.stopPropagation();
      frameGuard(true);
      var sx = e.clientX, sy = e.clientY, sw = panel.offsetWidth, sh = panel.offsetHeight;
      var move = function (ev) {
        panel.style.width = clampInt(sw + ev.clientX - sx, 420, window.innerWidth - 20) + 'px';
        panel.style.height = clampInt(sh + ev.clientY - sy, 340, window.innerHeight - 20) + 'px';
      };
      var up = function () {
        document.removeEventListener('pointermove', move);
        document.removeEventListener('pointerup', up);
        frameGuard(false);
        try { localStorage.setItem(SIZE_KEY, JSON.stringify({ w: panel.offsetWidth, h: panel.offsetHeight })); } catch (err) {}
        wake();
      };
      document.addEventListener('pointermove', move);
      document.addEventListener('pointerup', up);
    });
  }

  function wake() {
    if (!frame || !frame.contentWindow) return;
    try { frame.contentWindow.postMessage({ type: 'mdt-tac', cmd: 'wake' }, location.origin); } catch (e) {}
  }

  var curUrl = '';

  function toggle(force, boardId) {
    if (!panel) build();
    open = (typeof force === 'boolean') ? force : !open;
    panel.classList.toggle('show', open);
    if (!open) return;
    var url = '/carte/?embed=1' + (boardId ? '&board=' + encodeURIComponent(boardId) : '');
    if (!loaded) {
      frame.addEventListener('load', function () { setTimeout(wake, 120); });
      frame.src = url;
      curUrl = url;
      loaded = true;
    } else {
      if (url !== curUrl) { frame.src = url; curUrl = url; }
      setTimeout(wake, 60);
    }
  }

  window.MDT_TACPANEL = { toggle: toggle, isOpen: function () { return open; } };
})();
