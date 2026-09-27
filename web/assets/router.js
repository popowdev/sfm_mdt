(function () {
  if (window.MDT_ROUTER) return;

  var cfg = null;
  var routes = [];
  var current = null;
  var started = false;

  function norm(p) {
    if (!p) return '/';
    p = String(p).split('#')[0].split('?')[0];
    if (p.length > 1 && p.charAt(p.length - 1) === '/') p = p.slice(0, -1);
    return p || '/';
  }

  function baseOf() { return norm(cfg && cfg.base ? cfg.base : '/'); }

  function inBase(path) {
    var b = baseOf();
    if (b === '/') return true;
    path = norm(path);
    return path === b || path.indexOf(b + '/') === 0;
  }

  function rel(path) {
    var b = baseOf();
    path = norm(path);
    if (b === '/') return path;
    var r = path.slice(b.length);
    return r === '' ? '/' : r;
  }

  function compile(pattern) {
    var keys = [];
    var src = pattern.split('/').map(function (seg) {
      if (seg.charAt(0) === ':') {
        var optional = seg.charAt(seg.length - 1) === '?';
        keys.push(optional ? seg.slice(1, -1) : seg.slice(1));
        return optional ? '(?:/([^/]+))?' : '/([^/]+)';
      }
      return seg ? '/' + seg.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') : '';
    }).join('');
    return { re: new RegExp('^' + (src || '/') + '$', 'i'), keys: keys };
  }

  function match(path) {
    var r = rel(path);
    for (var i = 0; i < routes.length; i++) {
      var m = routes[i].re.exec(r);
      if (!m) continue;
      var params = {};
      for (var k = 0; k < routes[i].keys.length; k++) {
        params[routes[i].keys[k]] = m[k + 1] != null ? decodeURIComponent(m[k + 1]) : null;
      }
      return { route: routes[i].def, params: params, path: norm(path) };
    }
    return null;
  }

  function parseQuery(search) {
    var out = {};
    var s = (search || window.location.search).replace(/^\?/, '');
    if (!s) return out;
    s.split('&').forEach(function (pair) {
      if (!pair) return;
      var i = pair.indexOf('=');
      var k = decodeURIComponent(i < 0 ? pair : pair.slice(0, i)).replace(/\+/g, ' ');
      var v = i < 0 ? '' : decodeURIComponent(pair.slice(i + 1).replace(/\+/g, ' '));
      out[k] = v;
    });
    return out;
  }

  function buildQuery(obj) {
    var parts = [];
    Object.keys(obj || {}).forEach(function (k) {
      var v = obj[k];
      if (v == null || v === '') return;
      parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
    });
    return parts.length ? '?' + parts.join('&') : '';
  }

  function allowed(def) {
    if (!def || !def.perm) return true;
    if (typeof cfg.can !== 'function') return true;
    return !!cfg.can(def.perm);
  }

  function resolve(path, search, opts) {
    opts = opts || {};
    var m = match(path);

    if (!m) {
      current = null;
      if (typeof cfg.onNotFound === 'function') cfg.onNotFound(norm(path));
      return;
    }
    if (typeof m.route.redirect === 'function') {
      var to = m.route.redirect(m.params);
      if (to && norm(to) !== norm(path)) { replace(to); return; }
    }
    if (!allowed(m.route)) {
      current = m;
      if (typeof cfg.onDenied === 'function') cfg.onDenied(m.route, m.params);
      return;
    }

    current = m;
    m.query = parseQuery(search);
    if (typeof cfg.onRoute === 'function') cfg.onRoute(m, opts);
  }

  function nav(to, mode, opts) {
    var url = String(to);
    var hash = '';
    var hi = url.indexOf('#');
    if (hi >= 0) { hash = url.slice(hi); url = url.slice(0, hi); }
    var qi = url.indexOf('?');
    var search = qi >= 0 ? url.slice(qi) : '';
    var path = qi >= 0 ? url.slice(0, qi) : url;

    if (!inBase(path)) { window.location.href = to; return; }

    var full = norm(path) + search + hash;
    if (mode === 'replace') window.history.replaceState({ r: 1 }, '', full);
    else window.history.pushState({ r: 1 }, '', full);
    resolve(path, search, opts);
  }

  function go(to, opts) { nav(to, 'push', opts); }
  function replace(to, opts) { nav(to, 'replace', opts); }

  function setQuery(patch, mode) {
    var q = parseQuery();
    Object.keys(patch || {}).forEach(function (k) {
      if (patch[k] == null || patch[k] === '') delete q[k];
      else q[k] = patch[k];
    });
    nav(norm(window.location.pathname) + buildQuery(q), mode === 'push' ? 'push' : 'replace');
  }

  function onClick(e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest ? e.target.closest('a') : null;
    if (!a) return;
    if (a.target && a.target !== '_self') return;
    if (a.hasAttribute('download') || a.getAttribute('rel') === 'external' || a.hasAttribute('data-native')) return;
    var href = a.getAttribute('href');
    if (!href || href.charAt(0) === '#' || /^[a-z][a-z0-9+.-]*:/i.test(href)) return;
    if (a.host && a.host !== window.location.host) return;
    if (!inBase(a.pathname)) return;
    e.preventDefault();
    go(a.pathname + a.search + a.hash);
  }

  function start() {
    if (started) return;
    started = true;
    window.addEventListener('popstate', function () {
      resolve(window.location.pathname, window.location.search, { pop: true });
    });
    document.addEventListener('click', onClick);
    resolve(window.location.pathname, window.location.search, { boot: true });
  }

  window.MDT_ROUTER = {
    define: function (c) {
      cfg = c || {};
      routes = (cfg.routes || []).map(function (def) {
        var c2 = compile(norm(def.path) === '/' ? '/' : def.path);
        return { re: c2.re, keys: c2.keys, def: def };
      });
      return this;
    },
    start: start,
    go: go,
    replace: replace,
    owns: function (href) {
      if (!cfg || !started) return false;
      try {
        var u = new URL(href, window.location.origin);
        if (u.host !== window.location.host) return false;
        return inBase(u.pathname) && !!match(u.pathname);
      } catch (e) { return false; }
    },
    current: function () { return current; },
    query: parseQuery,
    setQuery: setQuery,
    href: function (p, q) { return norm(p) + buildQuery(q); },
    refresh: function () { resolve(window.location.pathname, window.location.search, { refresh: true }); }
  };
})();
