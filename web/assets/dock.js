(function () {
  if (window.__MDT_DOCK) return;
  window.__MDT_DOCK = true;

  var SIZE = 46;
  var MAX_SCALE = 1.62;
  var DISTANCE = 140;
  var STIFF = 300;
  var DAMP = 20;
  var MASS = 0.5;
  var HIDE_KEY = 'mdt_dock_hidden';

  function esc(s) {
    if (s == null) return '';
    var d = document.createElement('div');
    d.textContent = String(s);
    return d.innerHTML;
  }

  function build(visibleMods) {
    if (!window.MDT_MODULES_DOCK) return;
    var mods = window.MDT_MODULES_DOCK();
    if (visibleMods) {
      var okKeys = {};
      visibleMods.forEach(function (m) { okKeys[m.key] = 1; });
      mods = mods.filter(function (m) { return okKeys[m.key]; });
    }
    if (window.MDT_MODULE_HIDDEN_GET) {
      var hiddenKeys = {};
      window.MDT_MODULE_HIDDEN_GET().forEach(function (k) { hiddenKeys[k] = 1; });
      mods = mods.filter(function (m) { return !hiddenKeys[m.key]; });
    }
    if (window.MDT_MODULES_SORTED) mods = window.MDT_MODULES_SORTED(mods);
    var path = window.location.pathname;

    var items = [{
      key: '__home', label: 'Menu', icon: 'fa-house', color: '#94a3b8',
      href: '/', ready: true, active: path === '/' || path.indexOf('/index') === 0
    }];
    mods.forEach(function (m) {
      var ready = window.MDT_MODULE_READY ? window.MDT_MODULE_READY(m) : (m.status === 'v2');
      items.push({
        key: m.key, label: m.label, icon: m.icon, color: m.color, href: m.href,
        ready: ready,
        active: ready && path.indexOf(m.href) === 0
      });
    });
    items.push({
      key: '__settings', label: 'Paramètres', icon: 'fa-gear', color: '#94a3b8',
      href: '/parametres', ready: true, active: path.indexOf('/parametres') === 0
    });

    var dock = document.createElement('div');
    dock.className = 'dock';
    dock.setAttribute('role', 'navigation');
    dock.setAttribute('aria-label', 'Navigation globale');

    var panel = document.createElement('div');
    panel.className = 'dock-panel';

    var inner = document.createElement('div');
    inner.className = 'dock-inner';

    items.forEach(function (it) {
      var a = document.createElement('a');
      a.className = 'dock-item' + (it.active ? ' active' : '') + (it.ready ? '' : ' pending');
      a.href = it.ready ? it.href : '#';
      a.style.setProperty('--dock-color', it.color);
      a.setAttribute('aria-label', it.label);
      a.setAttribute('data-modkey', it.key);
      a.innerHTML =
        '<span class="dock-tip">' + esc(it.label) +
          (it.ready ? '' : '<em>pas encore migré</em>') + '</span>' +
        '<span class="dock-ico"><i class="fa-solid ' + esc(it.icon) + '"></i></span>' +
        '<span class="dock-dot"></span>';
      a.addEventListener('click', function (e) {
        if (!it.ready) {
          e.preventDefault();
          if (window.MDT_TOAST) {
            window.MDT_TOAST.info(it.label + ' — pas encore migré',
              'Ce module tourne toujours en V1 sur exemple.tld.');
          }
          return;
        }
        if (window.MDT_ROUTER && window.MDT_ROUTER.owns && window.MDT_ROUTER.owns(it.href)) {
          e.preventDefault();
          window.MDT_ROUTER.go(it.href);
        }
      });
      inner.appendChild(a);
    });

    var toggle = document.createElement('button');
    toggle.className = 'dock-toggle';
    toggle.type = 'button';
    toggle.innerHTML = '<i class="fa-solid fa-chevron-down"></i>';

    panel.appendChild(inner);
    dock.appendChild(panel);
    dock.appendChild(toggle);
    document.body.appendChild(dock);
    document.body.classList.add('has-dock');

    function apply(h, save) {
      dock.classList.toggle('hidden', h);
      document.body.classList.toggle('dock-off', h);
      toggle.setAttribute('aria-label', h ? 'Afficher la barre de navigation' : 'Masquer la barre de navigation');
      toggle.setAttribute('title', h ? 'Afficher la navigation' : 'Masquer');
      if (save) localStorage.setItem(HIDE_KEY, h ? '1' : '0');
    }

    apply(localStorage.getItem(HIDE_KEY) === '1', false);
    toggle.addEventListener('click', function () {
      apply(!dock.classList.contains('hidden'), true);
    });

    magnetize(panel, inner);
    return dock;
  }

  function magnetize(dock, inner) {
    var nodes = Array.prototype.slice.call(inner.querySelectorAll('.dock-item'));
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
    if (reduce || coarse) return;

    var springs = nodes.map(function () { return { x: 1, v: 0, target: 1 }; });
    var mouseX = Infinity;
    var raf = null;
    var last = 0;

    function targetFor(node) {
      if (mouseX === Infinity) return 1;
      var r = node.getBoundingClientRect();
      var c = r.left + r.width / 2;
      var d = Math.abs(mouseX - c);
      if (d > DISTANCE) return 1;
      var t = 1 - d / DISTANCE;
      return 1 + (MAX_SCALE - 1) * (t * t * (3 - 2 * t));
    }

    function frame(now) {
      var dt = last ? Math.min((now - last) / 1000, 0.032) : 0.016;
      last = now;
      var moving = false;

      for (var i = 0; i < nodes.length; i++) {
        var s = springs[i];
        s.target = targetFor(nodes[i]);
        var f = (-STIFF * (s.x - s.target) - DAMP * s.v) / MASS;
        s.v += f * dt;
        s.x += s.v * dt;
        if (Math.abs(s.x - s.target) > 0.001 || Math.abs(s.v) > 0.001) moving = true;

        var px = SIZE * s.x;
        var ico = nodes[i].firstElementChild.nextElementSibling;
        ico.style.width = px + 'px';
        ico.style.height = px + 'px';
        ico.style.fontSize = (px * 0.44) + 'px';
        nodes[i].style.width = px + 'px';
        nodes[i].style.transform = 'translateY(' + ((s.x - 1) * -9) + 'px)';
      }

      if (moving || mouseX !== Infinity) {
        raf = requestAnimationFrame(frame);
      } else {
        raf = null; last = 0;
      }
    }

    function kick() {
      if (raf == null) { last = 0; raf = requestAnimationFrame(frame); }
    }

    dock.addEventListener('mousemove', function (e) { mouseX = e.clientX; kick(); });
    dock.addEventListener('mouseleave', function () { mouseX = Infinity; kick(); });
  }

  function rebuild(visibleMods) {
    var old = document.querySelector('.dock');
    if (old) old.remove();
    document.body.classList.remove('has-dock', 'dock-off');
    build(visibleMods);
  }

  var lastVisible = null;
  function boot() {
    if (window.location.pathname.indexOf('/login/connexion') !== -1) return;
    if (!localStorage.getItem('mdt_auth_token') && !localStorage.getItem('mdt_token')) return;
    if (window.MDT_MODULES_ALLOWED) {
      window.MDT_MODULES_ALLOWED(function (visible) { lastVisible = visible; build(visible); });
    } else {
      build();
    }
  }

  window.addEventListener('mdt_module_order_changed', function () {
    if (window.__dockDragKey) return;
    if (document.querySelector('.dock')) rebuild(lastVisible);
  });
  window.MDT_DOCK_REBUILD = function () { rebuild(lastVisible); };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
