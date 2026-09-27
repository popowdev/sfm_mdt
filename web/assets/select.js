(function () {
  if (window.MDT_SELECT) return;
  var styled = false;
  var CSS = '' +
    '.ssel{position:relative;width:100%;}' +
    '.ssel-btn{width:100%;display:flex;align-items:center;gap:8px;background:var(--surface-2,#0f172a);border:1px solid var(--border,#334155);border-radius:10px;padding:10px 12px;color:var(--text,#e2e8f0);font-family:inherit;font-size:.9em;cursor:pointer;text-align:left;}' +
    '.ssel-btn:hover{border-color:var(--accent,#3b82f6);}' +
    '.ssel-btn.open{border-color:var(--accent,#3b82f6);}' +
    '.ssel-btn .ssel-lbl{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}' +
    '.ssel-btn .ssel-ph{color:var(--muted-2,#64748b);}' +
    '.ssel-btn .ssel-ch{color:var(--muted-2,#64748b);font-size:.8em;transition:transform .15s;}' +
    '.ssel-btn.open .ssel-ch{transform:rotate(180deg);}' +
    '.ssel-dd{position:fixed;z-index:99998;background:var(--surface,#1e293b);border:1px solid var(--border,#334155);border-radius:11px;box-shadow:0 14px 34px rgba(0,0,0,.5);max-height:300px;overflow-y:auto;display:none;padding:5px;}' +
    '.ssel-dd.show{display:block;}' +
    '.ssel-srch{position:sticky;top:-5px;background:var(--surface,#1e293b);padding:3px 3px 6px;margin:-5px -5px 4px;border-bottom:1px solid var(--border-soft,#1e293b);}' +
    '.ssel-srch input{width:100%;background:var(--surface-2,#0f172a);border:1px solid var(--border,#334155);border-radius:8px;padding:7px 10px;color:var(--text,#e2e8f0);font-family:inherit;font-size:.85em;}' +
    '.ssel-opt{padding:9px 11px;border-radius:8px;cursor:pointer;font-size:.9em;color:var(--text,#e2e8f0);display:flex;align-items:center;gap:8px;}' +
    '.ssel-opt:hover,.ssel-opt.active{background:var(--surface-2,#334155);}' +
    '.ssel-opt.sel{color:var(--accent-2,#60a5fa);font-weight:600;}' +
    '.ssel-opt .ssel-tick{margin-left:auto;opacity:0;}' +
    '.ssel-opt.sel .ssel-tick{opacity:1;}' +
    '.ssel-empty{padding:12px;text-align:center;color:var(--muted-2,#64748b);font-size:.85em;}';
  function inject() { if (styled) return; styled = true; var s = document.createElement('style'); s.textContent = CSS; document.head.appendChild(s); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
  function norm(s) { return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); }

  function enhance(sel) {
    if (!sel || sel._ss || sel.multiple || sel.hasAttribute('data-native') || sel.getAttribute('type') === 'native') return;
    sel._ss = true;
    inject();
    sel.style.display = 'none';
    var wrap = document.createElement('div'); wrap.className = 'ssel';
    var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'ssel-btn';
    btn.innerHTML = '<span class="ssel-lbl"></span><i class="ssel-ch fa-solid fa-chevron-down" aria-hidden="true"></i>';
    if (sel.className) wrap.className += ' ' + sel.className;
    wrap.appendChild(btn);
    sel.parentNode.insertBefore(wrap, sel.nextSibling);
    var dd = document.createElement('div'); dd.className = 'ssel-dd'; document.body.appendChild(dd);
    var lbl = btn.querySelector('.ssel-lbl');
    var open = false, active = -1, opts = [], filtered = [];

    function readOptions() {
      opts = Array.prototype.map.call(sel.options, function (o) { return { value: o.value, label: o.textContent, disabled: o.disabled }; });
    }
    function syncLabel() {
      var cur = sel.options[sel.selectedIndex];
      if (cur && cur.value !== '') { lbl.textContent = cur.textContent; lbl.classList.remove('ssel-ph'); }
      else { lbl.textContent = cur ? cur.textContent : ''; lbl.classList.toggle('ssel-ph', !cur || cur.value === ''); }
    }
    function place() { var r = btn.getBoundingClientRect(); dd.style.left = r.left + 'px'; dd.style.top = (r.bottom + 4) + 'px'; dd.style.width = r.width + 'px'; }
    function renderList(q) {
      var withSearch = opts.length > 9;
      filtered = q ? opts.filter(function (o) { return norm(o.label).indexOf(norm(q)) >= 0; }) : opts;
      var body = filtered.length ? filtered.map(function (o) {
        var isSel = o.value === sel.value;
        return '<div class="ssel-opt' + (isSel ? ' sel' : '') + '" data-v="' + esc(o.value) + '">' + esc(o.label) + '<i class="ssel-tick fa-solid fa-check" aria-hidden="true"></i></div>';
      }).join('') : '<div class="ssel-empty">Aucun résultat</div>';
      dd.innerHTML = (withSearch ? '<div class="ssel-srch"><input type="text" placeholder="Rechercher…" autocomplete="off"></div>' : '') + body;
      if (withSearch) { var si = dd.querySelector('.ssel-srch input'); si.value = q || ''; si.addEventListener('input', function () { renderList(si.value); }); si.addEventListener('keydown', onKey); setTimeout(function () { si.focus(); }, 0); }
      Array.prototype.forEach.call(dd.querySelectorAll('.ssel-opt'), function (el) {
        el.addEventListener('mousedown', function (e) { e.preventDefault(); pick(el.getAttribute('data-v')); });
      });
    }
    function show() { readOptions(); place(); dd.classList.add('show'); btn.classList.add('open'); open = true; active = -1; renderList(''); document.addEventListener('mousedown', onDoc, true); window.addEventListener('scroll', onScroll, true); window.addEventListener('resize', onScroll); }
    function hide() { open = false; dd.classList.remove('show'); btn.classList.remove('open'); document.removeEventListener('mousedown', onDoc, true); window.removeEventListener('scroll', onScroll, true); window.removeEventListener('resize', onScroll); }
    function pick(v) { if (sel.value !== v) { sel.value = v; sel.dispatchEvent(new Event('change', { bubbles: true })); } syncLabel(); hide(); }
    function onDoc(e) { if (!wrap.contains(e.target) && !dd.contains(e.target)) hide(); }
    function onScroll() { if (open) { if (!sel.isConnected) { cleanup(); return; } place(); } }
    function onKey(e) {
      var els = dd.querySelectorAll('.ssel-opt');
      if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(els.length - 1, active + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(0, active - 1); }
      else if (e.key === 'Enter') { if (els[active]) { e.preventDefault(); pick(els[active].getAttribute('data-v')); } return; }
      else if (e.key === 'Escape') { hide(); return; }
      else return;
      Array.prototype.forEach.call(els, function (el, i) { el.classList.toggle('active', i === active); if (i === active && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' }); });
    }
    function cleanup() { hide(); if (dd.parentNode) dd.parentNode.removeChild(dd); }
    btn.addEventListener('click', function () { if (open) hide(); else show(); });
    btn.addEventListener('keydown', function (e) { if (open) onKey(e); else if (e.key === 'Enter' || e.key === 'ArrowDown' || e.key === ' ') { e.preventDefault(); show(); } });
    readOptions(); syncLabel();
    sel._ssApi = { refresh: function () { readOptions(); syncLabel(); }, cleanup: cleanup };
  }
  function scan(root) { try { Array.prototype.forEach.call((root || document).querySelectorAll('select'), enhance); } catch (e) {} }
  function init() {
    scan(document);
    try {
      var mo = new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
          var added = muts[i].addedNodes;
          for (var j = 0; j < added.length; j++) {
            var el = added[j];
            if (el.nodeType !== 1) continue;
            if (el.tagName === 'SELECT') enhance(el);
            else if (el.querySelectorAll) scan(el);
          }
        }
      });
      mo.observe(document.documentElement || document.body, { childList: true, subtree: true });
    } catch (e) {}
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
  window.MDT_SELECT = { enhance: enhance, scan: scan };
})();
