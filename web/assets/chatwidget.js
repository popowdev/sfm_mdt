(function () {
  if (window.MDT_CHATWIDGET) return;
  if (/^\/messagerie(\/|$)/.test(location.pathname)) return;
  var token = null;
  try { token = localStorage.getItem('mdt_auth_token'); } catch (e) {}
  if (!token) return;
  window.MDT_CHATWIDGET = true;

  var CSS = '' +
    '.scw-bubble{position:fixed;right:22px;bottom:22px;width:58px;height:58px;border-radius:50%;background:var(--accent,#6366f1);color:#fff;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1.35rem;box-shadow:0 10px 28px rgba(99,102,241,.5);z-index:9995;transition:transform .16s;}' +
    '.scw-bubble:hover{transform:scale(1.06);}' +
    '.scw-bubble.open i.scw-chat{display:none;}' +
    '.scw-bubble i.scw-close{display:none;}' +
    '.scw-bubble.open i.scw-close{display:block;}' +
    '.scw-badge{position:absolute;top:-2px;right:-2px;min-width:22px;height:22px;padding:0 6px;border-radius:999px;background:#ef4444;color:#fff;font-size:.66rem;font-weight:800;display:none;align-items:center;justify-content:center;border:2px solid var(--bg,#0f172a);}' +
    '.scw-badge.show{display:flex;}' +
    '.scw-panel{position:fixed;right:22px;bottom:92px;width:720px;height:540px;max-width:calc(100vw - 28px);max-height:calc(100vh - 120px);background:var(--surface,#1e293b);border:1px solid var(--border,#334155);border-radius:16px;box-shadow:0 30px 70px rgba(0,0,0,.55);z-index:9994;display:none;overflow:hidden;}' +
    '.scw-panel.open{display:flex;flex-direction:column;}' +
    '.scw-head{flex:0 0 52px;box-sizing:border-box;display:flex;align-items:center;gap:10px;padding:0 14px;border-bottom:1px solid var(--border,#334155);background:linear-gradient(90deg,rgba(99,102,241,.14),transparent);}' +
    '.scw-head .scw-ic{width:28px;height:28px;border-radius:8px;background:var(--accent,#6366f1);display:flex;align-items:center;justify-content:center;color:#fff;font-size:.85rem;}' +
    '.scw-head .scw-t{flex:1;font-weight:800;color:var(--text-strong,#f8fafc);font-size:.95rem;}' +
    '.scw-head button{width:30px;height:30px;border:none;background:none;color:var(--muted,#94a3b8);border-radius:8px;cursor:pointer;font-size:.9rem;}' +
    '.scw-head button:hover{color:var(--accent-2,#818cf8);background:rgba(255,255,255,.06);}' +
    '.scw-frame{flex:1 1 auto;min-height:0;width:100%;border:none;background:var(--surface-2,#0f172a);display:block;}' +
    '@media (max-width:520px){ .scw-panel{ right:8px; left:8px; width:auto; bottom:84px; } .scw-bubble{ right:14px; bottom:14px; } }';
  var st = document.createElement('style'); st.textContent = CSS; document.head.appendChild(st);

  var bubble = document.createElement('button');
  bubble.className = 'scw-bubble'; bubble.setAttribute('aria-label', 'Messagerie');
  bubble.innerHTML = '<i class="scw-chat fa-solid fa-comment-dots"></i><i class="scw-close fa-solid fa-xmark"></i><span class="scw-badge" id="scwBadge"></span>';
  var panel = document.createElement('div');
  panel.className = 'scw-panel';
  panel.innerHTML =
    '<div class="scw-head"><div class="scw-ic"><i class="fa-solid fa-comments"></i></div><div class="scw-t">Messagerie</div>' +
    '<button title="Ouvrir en plein écran" id="scwFull"><i class="fa-solid fa-up-right-and-down-left-from-center"></i></button>' +
    '<button title="Fermer" id="scwCloseBtn"><i class="fa-solid fa-minus"></i></button></div>' +
    '<iframe class="scw-frame" id="scwFrame" title="Messagerie" src="about:blank"></iframe>';
  document.body.appendChild(panel);
  document.body.appendChild(bubble);

  var badge = document.getElementById('scwBadge');
  var frame = document.getElementById('scwFrame');
  var loaded = false, open = false;

  function setBadge(n) {
    n = parseInt(n, 10) || 0;
    if (n > 0) { badge.textContent = n > 9 ? '9+' : String(n); badge.classList.add('show'); }
    else badge.classList.remove('show');
  }
  function openPanel() {
    if (!loaded) { frame.src = '/messagerie/?embed=1&_=' + (new Date()).getTime(); loaded = true; }
    panel.classList.add('open'); bubble.classList.add('open'); open = true;
  }
  function closePanel() { panel.classList.remove('open'); bubble.classList.remove('open'); open = false; poll(); }
  bubble.addEventListener('click', function () { open ? closePanel() : openPanel(); });
  document.getElementById('scwCloseBtn').addEventListener('click', closePanel);
  document.getElementById('scwFull').addEventListener('click', function () { location.href = '/messagerie/'; });

  function poll() {
    fetch('/messagerie/messagerie_api.php?action=unread_total', { headers: { Authorization: 'Bearer ' + token } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d && d.success) setBadge(d.total); })
      .catch(function () {});
  }
  poll();
  setInterval(function () { if (!open) poll(); }, 15000);
  window.addEventListener('message', function (e) {
    if (e && e.data && e.data.type === 'mdt-msg-unread') setBadge(e.data.total);
  });
})();
