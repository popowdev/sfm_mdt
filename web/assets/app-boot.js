(function () {
  var _f = window.fetch;
  var _throttle = {};
  var MDT_RELOGIN = false;
  function report(code, msg, opts) {
    opts = opts || {};
    try { window.MDT_LAST_ERR = { code: code, msg: msg || '', page: location.pathname, module: opts.module || '' }; } catch (e) {}
    if (opts.show) { try { if (window.MDT_TOAST && MDT_TOAST.err) MDT_TOAST.err('Erreur ' + code + (msg ? ' : ' + msg : '')); } catch (e) {} }
    try {
      var now = (new Date()).getTime();
      var key = code + '|' + (opts.module || '');
      if (_throttle[key] && (now - _throttle[key]) < 5000) return;
      _throttle[key] = now;
      var t = localStorage.getItem('mdt_auth_token'); if (!t) return;
      _f.call(window, '/log_api.php?action=client_error', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + t },
        body: JSON.stringify({ code: code, msg: (msg || '').slice(0, 255), page: location.pathname, module: opts.module || '', stack: (opts.stack || '').slice(0, 4000) })
      }).catch(function () {});
    } catch (e) {}
  }
  window.MDT_REPORT_ERR = report;
  window.fetch = function (i, init) {
    var u = '';
    try {
      u = (typeof i === 'string') ? i : (i && i.url) || '';
      if (/_api\.php/.test(u)) {
        init = init || {};
        var t = localStorage.getItem('mdt_auth_token');
        if (t) {
          var h = new Headers((init && init.headers) || {});
          if (!h.has('Authorization')) h.set('Authorization', 'Bearer ' + t);
          init.headers = h;
        }
      }
    } catch (e) {}
    var isApi = /_api\.php/.test(u) && !/(log|presence|maintenance)_api\.php/.test(u);
    var sentToken = false;
    try { sentToken = !!localStorage.getItem('mdt_auth_token'); } catch (e) {}
    return _f.call(this, i, init).then(function (resp) {
      try {
        if (isApi && sentToken && resp && resp.status === 401 && !MDT_RELOGIN) {
          resp.clone().json().then(function (d) {
            if (!d || d.code !== 'E-SEC-401' || MDT_RELOGIN) return;
            MDT_RELOGIN = true;
            try {
              localStorage.removeItem('mdt_auth_token'); localStorage.removeItem('mdt_user');
              localStorage.removeItem('mdt_token'); localStorage.removeItem('mdt_session');
            } catch (e) {}
            try {
              if (!/\/login\//.test(location.pathname)) {
                location.href = '/login/connexion?expired=1&next=' + encodeURIComponent(location.pathname + location.search);
              }
            } catch (e) {}
          }).catch(function () {});
        }
        if (isApi && resp && resp.status >= 400) {
          resp.clone().json().then(function (d) {
            var code = (d && d.code) ? d.code : ('E-HTTP-' + resp.status);
            report(code, (d && d.error) || ('HTTP ' + resp.status), { show: resp.status >= 500 });
          }).catch(function () { report('E-HTTP-' + resp.status, 'HTTP ' + resp.status, { show: resp.status >= 500 }); });
        }
      } catch (e) {}
      return resp;
    }, function (err) {
      if (isApi) { try { report('E-NET-000', 'Réseau indisponible', { show: true }); } catch (e) {} }
      throw err;
    });
  };
  window.addEventListener('error', function (e) { try { report('E-JS-500', (e && e.message) || 'Erreur JS', { show: true, stack: (e && e.error && e.error.stack) || '' }); } catch (_) {} });
  window.addEventListener('unhandledrejection', function (e) { try { var r = e && e.reason; report('E-JS-501', (r && r.message) || String(r), { show: false, stack: (r && r.stack) || '' }); } catch (_) {} });
})();

(function () {
  var SEL = 'input:not([type=password]):not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]):not([type=submit]):not([type=button]):not([type=color]):not([type=range]), textarea';
  function stamp(el) {
    if (!el || el.__naf) return;
    if (el.hasAttribute && el.hasAttribute('data-autofill-keep')) return;
    el.__naf = true;
    if (!el.getAttribute('autocomplete')) el.setAttribute('autocomplete', 'off');
    el.setAttribute('data-lpignore', 'true');
    el.setAttribute('data-1p-ignore', 'true');
    el.setAttribute('data-form-type', 'other');
  }
  function sweep(root) { try { Array.prototype.forEach.call((root || document).querySelectorAll(SEL), stamp); } catch (e) {} }
  function init() {
    sweep(document);
    try {
      var mo = new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
          var added = muts[i].addedNodes;
          for (var j = 0; j < added.length; j++) {
            var el = added[j];
            if (el.nodeType !== 1) continue;
            if (el.matches && el.matches(SEL)) stamp(el);
            if (el.querySelectorAll) sweep(el);
          }
        }
      });
      mo.observe(document.documentElement || document.body, { childList: true, subtree: true });
    } catch (e) {}
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

(function(){ window.MDT_ESC_CLOSE = true; document.addEventListener('keydown', function(e){
  if (e.key === 'Escape' || e.keyCode === 27){ var o = document.querySelectorAll('.ov.open'); if (o.length){ for (var i=0;i<o.length;i++) o[i].classList.remove('open'); } }
}); })();

(function () {
  function run() {
    if (document.body && document.body.classList.contains('embed')) return;
    if (document.querySelector('.msg-root')) return;
    var wrap = document.querySelector('.wrap');
    if (!wrap) return;
    try { if (!localStorage.getItem('mdt_auth_token')) return; } catch (e) { return; }
    if (!window.MutationObserver) return;

    var rendered = false, overlay = null, killed = false, shownAt = 0;
    function isOwn(n) { return overlay && (n === overlay || (overlay.contains && overlay.contains(n))); }
    function meaningful(muts) {
      for (var i = 0; i < muts.length; i++) {
        if (isOwn(muts[i].target)) continue;
        var added = muts[i].addedNodes;
        for (var j = 0; j < added.length; j++) {
          var n = added[j];
          if (n.nodeType === 1 && !isOwn(n)) return true;
        }
      }
      return false;
    }
    function doHide() {
      if (overlay) {
        overlay.classList.add('hide');
        setTimeout(function () { if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay); wrap.classList.remove('sk-booting'); }, 260);
      } else {
        wrap.classList.remove('sk-booting');
      }
    }
    function remove() {
      if (killed) return;
      killed = true;
      try { obs.disconnect(); } catch (e) {}
      if (overlay && shownAt) {
        var elapsed = Date.now() - shownAt;
        if (elapsed < 420) { setTimeout(doHide, 420 - elapsed); return; }
      }
      doHide();
    }
    var obs = new MutationObserver(function (muts) { if (!killed && meaningful(muts)) { rendered = true; remove(); } });
    try { obs.observe(wrap, { childList: true, subtree: true }); } catch (e) { return; }

    setTimeout(function () {
      if (rendered || killed) return;
      overlay = document.createElement('div');
      overlay.className = 'sk-boot';
      var rows = '';
      for (var i = 0; i < 6; i++) {
        rows += '<div class="sk-item sk-row"><span class="sk" style="width:34px;height:34px;border-radius:50%;flex:0 0 auto"></span>' +
          '<div class="sk-lines"><span class="sk" style="height:12px;width:' + (42 + i * 6) + '%"></span>' +
          '<span class="sk" style="height:10px;width:' + (26 + i * 5) + '%"></span></div></div>';
      }
      overlay.innerHTML = '<div class="sk-boot-inner">' + rows + '</div>';
      wrap.classList.add('sk-booting');
      wrap.appendChild(overlay);
      shownAt = Date.now();
    }, 550);
    setTimeout(remove, 10000);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
  else run();
})();

(function () {
  var last = 0;
  function ping(force) {
    try {
      if (!localStorage.getItem('mdt_auth_token')) return;
      var now = (new Date()).getTime();
      if (!force && (now - last) < 8000) return;
      last = now;
      window.fetch('/presence_api.php?action=ping', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'visible=' + (document.hidden ? '0' : '1')
      }).catch(function () {});
    } catch (e) {}
  }
  window.MDT_PRESENCE_PING = ping;
  ping(true);
  setInterval(function () { ping(false); }, 25000);
  document.addEventListener('visibilitychange', function () { ping(true); });
  window.addEventListener('focus', function () { ping(true); });
})();

(function () {
  try {
    if (!document.querySelector('link[rel="manifest"]')) {
      var l = document.createElement('link');
      l.rel = 'manifest'; l.href = '/manifest.webmanifest';
      (document.head || document.documentElement).appendChild(l);
    }
    if (!document.querySelector('meta[name="theme-color"]')) {
      var m = document.createElement('meta');
      m.name = 'theme-color'; m.content = '#0b1220';
      (document.head || document.documentElement).appendChild(m);
    }
    if ('serviceWorker' in navigator && location.protocol === 'https:') {
      window.addEventListener('load', function () {
        navigator.serviceWorker.register('/sw.js').catch(function () {});
      });
    }
    window.addEventListener('beforeinstallprompt', function (e) {
      e.preventDefault();
      window.MDT_INSTALL_PROMPT = e;
      document.documentElement.setAttribute('data-installable', '1');
    });
    window.addEventListener('appinstalled', function () {
      window.MDT_INSTALL_PROMPT = null;
      document.documentElement.removeAttribute('data-installable');
    });
    window.MDT_INSTALL = function () {
      var p = window.MDT_INSTALL_PROMPT;
      if (!p) return false;
      p.prompt();
      window.MDT_INSTALL_PROMPT = null;
      return true;
    };
  } catch (e) {}
})();
