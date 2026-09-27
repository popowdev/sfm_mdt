(function () {
  if (window.MDT_AUTOCOMPLETE) return;
  var styled = false;
  var CSS = '' +
    '.sac-dd{position:fixed;z-index:99999;background:var(--surface,#1e293b);border:1px solid var(--border,#334155);border-radius:11px;box-shadow:0 14px 34px rgba(0,0,0,.5);max-height:280px;overflow-y:auto;display:none;padding:5px;}' +
    '.sac-dd.show{display:block;}' +
    '.sac-item{padding:9px 11px;border-radius:8px;cursor:pointer;font-size:.9em;display:flex;flex-direction:column;gap:2px;line-height:1.25;}' +
    '.sac-item:hover,.sac-item.active{background:var(--surface-2,#334155);}' +
    '.sac-item .sac-sub{font-size:.8em;color:var(--muted-2,#94a3b8);}' +
    '.sac-empty{padding:12px;text-align:center;color:var(--muted-2,#94a3b8);font-size:.85em;}';
  function inject() { if (styled) return; styled = true; var s = document.createElement('style'); s.textContent = CSS; document.head.appendChild(s); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
  function norm(s) { return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); }
  function normItem(x) { if (x == null) return null; if (typeof x === 'string') return { value: x, label: x, sub: '' }; return { value: x.value != null ? x.value : (x.label || ''), label: x.label != null ? x.label : (x.value || ''), sub: x.sub || '' }; }

  function attach(input, opts) {
    if (!input) return null;
    if (input._sac) return input._sac;
    inject();
    opts = opts || {};
    var min = opts.min || 0, max = opts.max || 60, emptyLabel = opts.empty || 'Aucun résultat';
    var getData = typeof opts.source === 'function' ? opts.source : function () { return opts.source || []; };
    var onSelect = opts.onSelect || function (it) { input.value = it.value; input.dispatchEvent(new Event('input', { bubbles: true })); };
    input.setAttribute('autocomplete', 'off');
    input.setAttribute('data-lpignore', 'true');
    input.setAttribute('data-1p-ignore', 'true');
    input.setAttribute('data-form-type', 'other');
    var dd = document.createElement('div'); dd.className = 'sac-dd'; document.body.appendChild(dd);
    var items = [], active = -1, open = false;

    function place() { var r = input.getBoundingClientRect(); dd.style.left = r.left + 'px'; dd.style.top = (r.bottom + 4) + 'px'; dd.style.minWidth = r.width + 'px'; dd.style.maxWidth = Math.max(r.width, 340) + 'px'; }
    function hide() { open = false; dd.classList.remove('show'); active = -1; }
    function paint() { Array.prototype.forEach.call(dd.querySelectorAll('.sac-item'), function (el, i) { el.classList.toggle('active', i === active); if (i === active && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' }); }); }
    function render() {
      if (!input.isConnected) { api.destroy(); return; }
      var val = input.value || '';
      if (val.length < min) { hide(); return; }
      var f = norm(val);
      var data = (getData() || []).map(normItem).filter(Boolean);
      var res = f ? data.filter(function (x) { return norm(x.label).indexOf(f) >= 0 || norm(x.value).indexOf(f) >= 0; }) : data;
      items = res.slice(0, max);
      if (!items.length) { dd.innerHTML = '<div class="sac-empty">' + esc(emptyLabel) + '</div>'; place(); dd.classList.add('show'); open = true; active = -1; return; }
      dd.innerHTML = items.map(function (it, i) { return '<div class="sac-item" data-i="' + i + '">' + esc(it.label) + (it.sub ? '<span class="sac-sub">' + esc(it.sub) + '</span>' : '') + '</div>'; }).join('');
      Array.prototype.forEach.call(dd.querySelectorAll('.sac-item'), function (el) {
        el.addEventListener('mousedown', function (e) { e.preventDefault(); choose(parseInt(el.getAttribute('data-i'), 10)); });
      });
      place(); dd.classList.add('show'); open = true; active = -1;
    }
    function choose(i) { var it = items[i]; if (!it) return; onSelect(it); hide(); }

    function onKey(e) {
      if (!open) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(items.length - 1, active + 1); paint(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(0, active - 1); paint(); }
      else if (e.key === 'Enter') { if (active >= 0) { e.preventDefault(); choose(active); } }
      else if (e.key === 'Escape') { hide(); }
    }
    function isValidVal(v){ if (!v) return true; var nv = norm(v); return (getData()||[]).map(normItem).filter(Boolean).some(function(x){ return norm(x.value)===nv || norm(x.label)===nv; }); }
    function onBlur() { setTimeout(function(){ hide(); if (opts.force && input.isConnected && !isValidVal(input.value)) { input.value=''; input.dispatchEvent(new Event('input', { bubbles: true })); if (window.MDT_TOAST && MDT_TOAST.err) MDT_TOAST.err('Choisis une valeur dans la liste'); } }, 140); }
    function onScroll() { if (open) { if (!input.isConnected) { api.destroy(); return; } place(); } }

    input.addEventListener('input', render);
    input.addEventListener('focus', render);
    input.addEventListener('keydown', onKey);
    input.addEventListener('blur', onBlur);
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', onScroll);

    var api = {
      refresh: render,
      hide: hide,
      destroy: function () {
        input.removeEventListener('input', render); input.removeEventListener('focus', render);
        input.removeEventListener('keydown', onKey); input.removeEventListener('blur', onBlur);
        window.removeEventListener('scroll', onScroll, true); window.removeEventListener('resize', onScroll);
        if (dd.parentNode) dd.parentNode.removeChild(dd);
        try { delete input._sac; } catch (e) { input._sac = null; }
      }
    };
    input._sac = api;
    return api;
  }

  window.MDT_AUTOCOMPLETE = { attach: attach };
})();
