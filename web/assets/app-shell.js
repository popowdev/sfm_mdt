(function () {
  function escHtml(s) { if (s == null) return ''; var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; }
  function mdtMD(raw) {
    if (raw == null) return '';
    var s = escHtml(raw);
    s = s.replace(/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/g, function (m, txt, url) {
      return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + txt + '</a>';
    });
    s = s.replace(/(^|[^"'>=\]/])(https?:\/\/[^\s<]+)/g, function (m, pre, url) {
      var trail = '', mt = url.match(/[),.;:!?»"'’]+$/);
      if (mt) { trail = mt[0]; url = url.slice(0, -trail.length); }
      return pre + '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + url + '</a>' + trail;
    });
    return s.replace(/\n/g, '<br>');
  }
  window.mdtMD = mdtMD;
  function mdtRenderMarkdown(root) {
    var nodes = (root || document).querySelectorAll('[data-md]');
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      if (el.getAttribute('data-md-done') === '1') continue;
      el.setAttribute('data-md-done', '1');
      var src = el.getAttribute('data-md');
      if (!src) src = el.textContent;
      el.innerHTML = mdtMD(src);
    }
  }
  window.mdtRenderMarkdown = mdtRenderMarkdown;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { mdtRenderMarkdown(); });
  else mdtRenderMarkdown();
})();

(function () {
  if (window.location.pathname.indexOf('/login/connexion') !== -1) return;
  if (window.__MDT_SHELL_MOUNTED) return;
  window.__MDT_SHELL_MOUNTED = true;

  function esc(s) { if (!s) return ''; var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; }

  var user = null;
  try { var raw = localStorage.getItem('mdt_user'); if (raw) user = JSON.parse(raw); } catch (e) {}
  if (!user) {
    try {
      var old = localStorage.getItem('mdt_token');
      if (old) {
        var p = JSON.parse(old);
        user = { username: p.username, discord_avatar: null, discord_nick: null, discord_username: p.username, discord_id: p.id };
        if (p.avatar && p.id) user.discord_avatar = 'https://cdn.discordapp.com/avatars/' + p.id + '/' + p.avatar + '.png?size=64';
      }
    } catch (e) {}
  }
  if (!user) return;

  var SHELL = window.MDT_SHELL || null;
  if (SHELL && SHELL.module) document.documentElement.setAttribute('data-module', SHELL.module);

  var path = window.location.pathname;
  var isRoot = path === '/' || path === '/index' || path === '/index.html';
  var displayName = user.discord_nick || user.discord_username || user.username || 'Utilisateur';
  var initial = esc(displayName.charAt(0).toUpperCase());

  var avatarHtml = user.discord_avatar
    ? '<img class="ash-avatar" src="' + esc(user.discord_avatar) + '" alt="" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'"><div class="ash-avatar-ph" style="display:none">' + initial + '</div>'
    : '<div class="ash-avatar-ph">' + initial + '</div>';
  var ddAvatarHtml = user.discord_avatar ? '<img src="' + esc(user.discord_avatar) + '" alt="" onerror="this.style.display=\'none\'">' : '';
  var discordLabel = user.discord_id ? '<div class="ash-dd-sub">Discord lié</div>' : '<div class="ash-dd-sub" style="color:#f59e0b">Discord non lié</div>';

  var shell = document.createElement('div');
  shell.className = 'app-shell';

  var brand;
  if (SHELL) {
    var mIcon = SHELL.icon
      ? (/\.(png|jpe?g|svg|webp)$/i.test(SHELL.icon)
          ? '<span class="ash-mod-ic"><img src="' + esc(SHELL.icon) + '" alt=""></span>'
          : '<span class="ash-mod-ic"><i class="fa-solid ' + esc(SHELL.icon) + '"></i></span>')
      : '';
    brand =
      '<button class="ash-back" id="ashBack" title="Retour"><i class="fa-solid fa-arrow-left"></i></button>' +
      '<a href="/" class="ash-mod" title="Menu principal">' + mIcon +
        '<span class="ash-mod-title">' + esc(SHELL.title || '') + '</span>' +
      '</a>';
  } else {
    brand =
      (!isRoot ? '<a href="/" class="ash-back" title="Menu"><i class="fa-solid fa-arrow-left"></i></a>' : '') +
      '<a href="/" class="ash-brand"><span class="ash-brand-accent">MDT</span> TOOL</a>';
  }

  var navHtml = SHELL
    ? '<nav class="ash-tabs"><div class="asn-items">' + renderItems(Array.isArray(SHELL.nav) ? SHELL.nav : []) + '</div></nav>'
    : '<div class="ash-tabs"></div>';

  var topbar =
    '<div class="app-topbar">' +
      '<div class="ash-left">' + brand + '</div>' +
      navHtml +
      '<div class="ash-right">' +
        '<button class="ash-bell" id="ashBell" title="Notifications"><i class="fa-solid fa-bell"></i></button>' +
        '<div class="ash-notif-dd" id="ashNotifDd">' +
          '<div class="ash-nd-head"><h4>Notifications</h4><button id="ashNdAll">Tout marquer lu</button></div>' +
          '<div class="ash-nd-list" id="ashNdList"><div class="ash-nd-empty">Chargement…</div></div>' +
        '</div>' +
        '<button class="ash-profile" id="ashProfileBtn">' + avatarHtml +
          '<span class="ash-name">' + esc(displayName) + '</span>' +
          '<i class="fa-solid fa-chevron-down ash-chevron"></i>' +
        '</button>' +
        '<div class="ash-dd" id="ashDropdown">' +
          '<div class="ash-dd-head">' + ddAvatarHtml +
            '<div><div class="ash-dd-name">' + esc(displayName) + '</div>' + discordLabel + '</div>' +
          '</div>' +
          '<a class="ash-dd-item" href="/login/profil"><i class="fa-solid fa-gear"></i> Paramètres</a>' +
          '<div class="ash-dd-sep"></div>' +
          '<button class="ash-dd-item danger" id="ashLogoutBtn"><i class="fa-solid fa-right-from-bracket"></i> Déconnexion</button>' +
        '</div>' +
      '</div>' +
    '</div>';

  shell.innerHTML = topbar;
  document.body.insertBefore(shell, document.body.firstChild);
  document.body.classList.add('has-app-shell');

  wireProfile();
  wireBell();
  if (SHELL) wireSubnav(SHELL);
  wireBack();

  function wireBack() {
    var b = document.getElementById('ashBack');
    if (!b) return;
    b.addEventListener('click', function (e) {
      e.preventDefault();
      var target = '/';
      if (typeof window.MDT_BACK === 'function') { try { target = window.MDT_BACK() || '/'; } catch (err) { target = '/'; } }
      if (window.MDT_ROUTER && window.MDT_ROUTER.owns && window.MDT_ROUTER.owns(target)) window.MDT_ROUTER.go(target);
      else window.location.href = target;
    });
  }

  function wireSubnav(s) {
    if (!s || !Array.isArray(s.nav)) return;
    var host = shell.querySelector('.asn-items');
    if (!host) return;
    host.querySelectorAll('.asn-item').forEach(function (el) {
      var idx = parseInt(el.getAttribute('data-idx'), 10);
      var it = s.nav[idx];
      if (!it || it.href) return;
      el.addEventListener('click', function (e) {
        if (typeof it.onClick === 'function') { try { it.onClick(); } catch (err) {} }
        if (it.view && typeof window.MDT_SHELL_NAV === 'function') window.MDT_SHELL_NAV(it.view, idx);
        if (!it.keepActive) {
          host.querySelectorAll('.asn-item').forEach(function (x) { x.classList.remove('active'); });
          el.classList.add('active');
        }
      });
    });
  }

  function renderItems(nav) {
    return nav.map(function (it, idx) {
      var cls = 'asn-item' + (it.active ? ' active' : '');
      var ic = it.icon ? '<i class="fa-solid ' + esc(it.icon) + '"></i>' : '';
      var count = (it.count != null) ? '<span class="asn-count" data-count>' + esc(it.count) + '</span>' : '';
      var inner = ic + '<span>' + esc(it.label) + '</span>' + count;
      if (it.href) return '<a class="' + cls + '" data-idx="' + idx + '" href="' + esc(it.href) + '">' + inner + '</a>';
      return '<button class="' + cls + '" data-idx="' + idx + '" type="button">' + inner + '</button>';
    }).join('');
  }

  window.MDT_SHELL_SET_NAV = function (nav) {
    if (!SHELL || !Array.isArray(nav)) return;
    SHELL.nav = nav;
    var host = shell.querySelector('.asn-items');
    if (!host) return;
    host.innerHTML = renderItems(nav);
    wireSubnav(SHELL);
  };

  window.MDT_SHELL_SET_ACTIVE = function (idx) {
    var host = shell.querySelector('.asn-items');
    if (!host) return;
    host.querySelectorAll('.asn-item').forEach(function (x, i) { x.classList.toggle('active', i === idx); });
  };
  window.MDT_SHELL_SET_BADGE = function (text, tone) {
    var b = document.getElementById('ashBadge');
    if (!b) return;
    b.textContent = text; b.className = 'badge ' + (tone === 'danger' ? 'badge-danger' : tone === 'muted' ? 'badge-muted' : '');
  };

  function wireProfile() {
    var btn = document.getElementById('ashProfileBtn');
    var dd = document.getElementById('ashDropdown');
    var chev = btn.querySelector('.ash-chevron');
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = dd.classList.toggle('show');
      chev.style.transform = open ? 'rotate(180deg)' : '';
    });
    document.addEventListener('click', function (e) {
      if (!e.target.closest('.ash-right')) { dd.classList.remove('show'); chev.style.transform = ''; }
    });
    document.getElementById('ashLogoutBtn').addEventListener('click', function () {
      if (typeof window.MDT_LOGOUT === 'function') window.MDT_LOGOUT();
      else {
        localStorage.removeItem('mdt_auth_token'); localStorage.removeItem('mdt_user');
        localStorage.removeItem('mdt_token'); localStorage.removeItem('mdt_session');
        window.location.href = '/login/connexion';
      }
    });
  }

  function wireBell() {
    var bell = document.getElementById('ashBell');
    var ndd = document.getElementById('ashNotifDd');
    var list = document.getElementById('ashNdList');
    var allBtn = document.getElementById('ashNdAll');
    var dd = document.getElementById('ashDropdown');
    var chev = document.querySelector('.ash-chevron');
    if (!bell) return;

    var lastSeen = {};
    var primed = false;

    function ago(s) {
      if (!s) return '';
      var d = new Date(String(s).replace(' ', 'T'));
      if (isNaN(d)) return s;
      var diff = (Date.now() - d.getTime()) / 1000;
      if (diff < 60) return "à l'instant";
      if (diff < 3600) return Math.floor(diff / 60) + ' min';
      if (diff < 86400) return Math.floor(diff / 3600) + ' h';
      if (diff < 604800) return Math.floor(diff / 86400) + ' j';
      return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
    }
    function authFetch(url, opts) {
      opts = opts || {}; opts.headers = opts.headers || {};
      var t = localStorage.getItem('mdt_auth_token'); if (t) opts.headers['Authorization'] = 'Bearer ' + t;
      return fetch(url, opts).then(function (r) { return r.json(); });
    }
    function setBadge(n, urgent) {
      var b = bell.querySelector('.ash-badge');
      if (n > 0) {
        if (!b) { b = document.createElement('span'); b.className = 'ash-badge'; bell.appendChild(b); }
        b.textContent = n > 99 ? '99+' : n;
        bell.classList.toggle('has-urgent', !!urgent);
      } else { if (b) b.remove(); bell.classList.remove('has-urgent'); }
    }
    function render(items) {
      if (!items || !items.length) { list.innerHTML = '<div class="ash-nd-empty"><i class="fa-regular fa-bell-slash" style="font-size:1.6em;opacity:.5"></i><br><br>Aucune notification</div>'; return; }
      list.innerHTML = items.map(function (n) {
        return '<div class="ash-nd-item ' + (n.lu ? 'read' : 'unread') + ' ' + (n.urgent ? 'urgent' : '') + '" data-id="' + n.id + '" data-lien="' + esc(n.lien || '') + '">' +
          '<span class="ash-nd-dot"></span>' +
          '<div class="ash-nd-body"><div class="ash-nd-title">' + esc(n.titre) + '</div>' +
          (n.corps ? '<div class="ash-nd-desc">' + esc(n.corps) + '</div>' : '') +
          '<div class="ash-nd-time">' + ago(n.created_at) + '</div></div></div>';
      }).join('');
      list.querySelectorAll('.ash-nd-item').forEach(function (it) {
        it.addEventListener('click', function () {
          var id = it.getAttribute('data-id'), lien = it.getAttribute('data-lien');
          authFetch('/notifications/notif_api.php?action=mark_read', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: parseInt(id, 10) }) }).then(function () { fetchCount(); });
          if (lien) window.location.href = lien;
        });
      });
    }
    function loadNotifs() {
      authFetch('/notifications/notif_api.php?action=list&limit=25')
        .then(function (d) { if (d && d.ok) { render(d.notifications); setBadge(d.unread, d.notifications.some(function (n) { return !n.lu && n.urgent; })); maybeToast(d.notifications); } })
        .catch(function () { list.innerHTML = '<div class="ash-nd-empty">Erreur de chargement</div>'; });
    }
    function maybeToast(items) {
      if (!items) return;
      if (!primed) { items.forEach(function (n) { lastSeen[n.id] = 1; }); primed = true; return; }
      items.slice().reverse().forEach(function (n) {
        if (!n.lu && !lastSeen[n.id]) {
          lastSeen[n.id] = 1;
          if (window.MDT_TOAST) window.MDT_TOAST.push({ title: n.titre, body: n.corps || '', tone: n.urgent ? 'danger' : 'accent', href: n.lien || '' });
          try {
            if (document.hidden && window.Notification && Notification.permission === 'granted') {
              var no = new Notification(n.titre || 'RP MDT', { body: (n.corps || '').slice(0, 140), tag: 'mdt-' + n.id });
              no.onclick = function () { try { window.focus(); } catch (e) {} if (n.lien) location.href = n.lien; no.close(); };
            }
          } catch (e) {}
        }
      });
    }
    var prevUnread = -1;
    function fetchCount() {
      authFetch('/notifications/notif_api.php?action=unread_count')
        .then(function (d) { if (d && d.ok) { setBadge(d.unread, d.urgent > 0); if (prevUnread >= 0 && d.unread > prevUnread) loadNotifs(); prevUnread = d.unread; } })
        .catch(function () {});
    }
    bell.addEventListener('click', function (e) {
      e.stopPropagation();
      if (dd) dd.classList.remove('show'); if (chev) chev.style.transform = '';
      var open = ndd.classList.toggle('show');
      if (open) loadNotifs();
    });
    document.addEventListener('click', function (e) { if (!e.target.closest('.ash-right')) ndd.classList.remove('show'); });
    allBtn.addEventListener('click', function (e) { e.stopPropagation(); authFetch('/notifications/notif_api.php?action=mark_all', { method: 'POST' }).then(function () { loadNotifs(); fetchCount(); }); });

    window.MDT_NOTIF = { refresh: function () { fetchCount(); if (ndd.classList.contains('show')) loadNotifs(); } };

    fetchCount();
    setInterval(fetchCount, 25000);
    if (window.Notification && Notification.permission === 'default') {
      var askOnce = function () { try { Notification.requestPermission(); } catch (e) {} document.removeEventListener('click', askOnce); };
      document.addEventListener('click', askOnce);
    }
  }
})();
