(function () {
  if (window.MDT_TOAST) return;

  var MAX = 5;
  var DEFAULT_MS = 5000;
  var host = null;
  var items = [];

  (function injectCSS() {
    if (document.getElementById('mdt-toast-css')) return;
    var css = ''
      + '.stack-toasts{position:fixed;left:16px;bottom:16px;z-index:10050;display:flex;flex-direction:column-reverse;gap:10px;align-items:flex-start;pointer-events:none;max-width:min(380px,calc(100vw - 32px));}'
      + '.toast{pointer-events:auto;position:relative;display:flex;align-items:flex-start;gap:11px;width:100%;padding:12px 12px 13px;background:var(--surface,#111a2e);border:1px solid var(--border,#1e293b);border-left:3px solid var(--accent,#dc2626);border-radius:var(--radius,12px);box-shadow:var(--shadow-lg,0 18px 40px rgba(0,0,0,.45));opacity:0;transform:translateX(24px) scale(.96);transition:opacity .26s ease,transform .26s ease;overflow:hidden;}'
      + '.toast.in{opacity:1;transform:none;}.toast.leaving{opacity:0;transform:translateX(24px) scale(.96);}'
      + '.toast-success{border-left-color:var(--success,#10b981);}.toast-danger{border-left-color:var(--danger,#ef4444);}.toast-warning{border-left-color:var(--warning,#f59e0b);}'
      + '.toast-ic{flex:0 0 auto;width:22px;height:22px;display:flex;align-items:center;justify-content:center;border-radius:50%;font-size:.72rem;background:rgba(220,38,38,.16);color:var(--accent-2,#f87171);}'
      + '.toast-success .toast-ic{background:rgba(16,185,129,.16);color:var(--success,#10b981);}'
      + '.toast-danger .toast-ic{background:rgba(239,68,68,.16);color:var(--danger-2,#f87171);}'
      + '.toast-warning .toast-ic{background:rgba(245,158,11,.16);color:var(--warning,#f59e0b);}'
      + '.toast-body{flex:1;min-width:0;}'
      + '.toast-title{font-size:.845rem;font-weight:600;color:var(--text-strong,#f1f5f9);line-height:1.35;word-wrap:break-word;}'
      + '.toast-desc{margin-top:2px;font-size:.78rem;color:var(--muted,#94a3b8);line-height:1.4;}'
      + '.toast-x{flex:0 0 auto;background:none;border:0;color:var(--muted-2,#64748b);cursor:pointer;padding:2px 3px;border-radius:5px;font-size:.78rem;}'
      + '.toast-x:hover{color:var(--text,#e2e8f0);}'
      + '.toast-bar{position:absolute;left:0;bottom:0;height:2px;width:100%;background:var(--accent,#dc2626);opacity:.5;transform-origin:left center;}'
      + '.toast-success .toast-bar{background:var(--success,#10b981);}.toast-danger .toast-bar{background:var(--danger,#ef4444);}';
    var st = document.createElement('style');
    st.id = 'mdt-toast-css';
    st.textContent = css;
    var h = document.head || document.documentElement;
    h.insertBefore(st, h.firstChild);
  })();

  function ensureHost() {
    if (host && document.body.contains(host)) return host;
    host = document.createElement('div');
    host.className = 'stack-toasts';
    host.setAttribute('role', 'status');
    host.setAttribute('aria-live', 'polite');
    document.body.appendChild(host);
    return host;
  }

  function esc(s) {
    if (s == null) return '';
    var d = document.createElement('div');
    d.textContent = String(s);
    return d.innerHTML;
  }

  function iconFor(tone) {
    if (tone === 'danger') return 'fa-circle-exclamation';
    if (tone === 'success') return 'fa-circle-check';
    if (tone === 'warning') return 'fa-triangle-exclamation';
    return 'fa-bell';
  }

  function dismiss(t) {
    if (t.dead) return;
    t.dead = true;
    clearTimeout(t.timer);
    t.el.classList.add('leaving');
    t.el.addEventListener('transitionend', function () {
      if (t.el.parentNode) t.el.parentNode.removeChild(t.el);
    }, { once: true });
    setTimeout(function () { if (t.el.parentNode) t.el.parentNode.removeChild(t.el); }, 400);
    items = items.filter(function (x) { return x !== t; });
  }

  function arm(t, ms) {
    clearTimeout(t.timer);
    t.endsAt = Date.now() + ms;
    t.remaining = ms;
    if (t.bar) {
      t.bar.style.transition = 'none';
      t.bar.style.transform = 'scaleX(1)';
      void t.bar.offsetWidth;
      t.bar.style.transition = 'transform ' + ms + 'ms linear';
      t.bar.style.transform = 'scaleX(0)';
    }
    t.timer = setTimeout(function () { dismiss(t); }, ms);
  }

  function push(opts) {
    opts = opts || {};
    if (typeof opts === 'string') opts = { title: opts };

    var tone = opts.tone || 'accent';
    var ms = opts.duration != null ? opts.duration : DEFAULT_MS;
    var h = ensureHost();

    var el = document.createElement('div');
    el.className = 'toast toast-' + tone + (opts.href ? ' toast-link' : '');
    el.innerHTML =
      '<span class="toast-ic"><i class="fa-solid ' + iconFor(tone) + '"></i></span>' +
      '<div class="toast-body">' +
        '<div class="toast-title">' + esc(opts.title) + '</div>' +
        (opts.body ? '<div class="toast-desc">' + esc(opts.body) + '</div>' : '') +
      '</div>' +
      '<button class="toast-x" type="button" aria-label="Fermer"><i class="fa-solid fa-xmark"></i></button>' +
      '<span class="toast-bar"></span>';

    var t = { el: el, dead: false, timer: null, bar: el.querySelector('.toast-bar') };

    el.querySelector('.toast-x').addEventListener('click', function (e) {
      e.stopPropagation();
      dismiss(t);
    });

    if (opts.href) {
      el.addEventListener('click', function () {
        if (window.MDT_ROUTER && window.MDT_ROUTER.go && opts.href.indexOf('/') === 0) {
          window.MDT_ROUTER.go(opts.href);
        } else {
          window.location.href = opts.href;
        }
        dismiss(t);
      });
    }

    el.addEventListener('mouseenter', function () {
      clearTimeout(t.timer);
      t.remaining = Math.max(400, t.endsAt - Date.now());
      if (t.bar) {
        var w = t.bar.getBoundingClientRect().width;
        var full = t.bar.parentNode.getBoundingClientRect().width || 1;
        t.bar.style.transition = 'none';
        t.bar.style.transform = 'scaleX(' + (w / full) + ')';
      }
    });
    el.addEventListener('mouseleave', function () {
      if (!t.dead && ms > 0) arm(t, t.remaining || ms);
    });

    h.appendChild(el);
    void el.offsetWidth;
    el.classList.add('in');

    items.push(t);
    while (items.length > MAX) dismiss(items[0]);

    if (ms > 0) arm(t, ms);
    return t;
  }

  window.MDT_TOAST = {
    push: push,
    ok:   function (m, b) { return push({ title: m, body: b, tone: 'success' }); },
    err:  function (m, b) { return push({ title: m, body: b, tone: 'danger', duration: 8000 }); },
    warn: function (m, b) { return push({ title: m, body: b, tone: 'warning' }); },
    info: function (m, b) { return push({ title: m, body: b, tone: 'accent' }); },
    clear: function () { items.slice().forEach(dismiss); }
  };
})();
