(function () {
  if (window.MDT_ROLESELECT) return;
  var styled = false;
  var CSS = '' +
    '.rs-box{position:relative}' +
    '.rs-chips{display:flex;flex-wrap:wrap;gap:7px;align-items:center}' +
    '.rs-none{color:var(--muted-2);font-size:.85em;font-style:italic}' +
    '.rs-chip{display:inline-flex;align-items:center;gap:6px;background:var(--surface-2);border:1px solid var(--border);border-radius:999px;padding:4px 6px 4px 11px;font-size:.84em;font-weight:600}' +
    '.rs-dot{width:10px;height:10px;border-radius:50%;flex:0 0 auto;box-shadow:0 0 0 1px rgba(0,0,0,.15) inset}' +
    '.rs-nm{max-width:190px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
    '.rs-x{border:none;background:transparent;color:var(--muted-2);cursor:pointer;width:18px;height:18px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.72em;opacity:0;transition:.12s}' +
    '.rs-chip:hover .rs-x{opacity:1}' +
    '.rs-x:hover{background:var(--danger);color:#fff}' +
    '.rs-add{display:inline-flex;align-items:center;gap:6px;background:transparent;border:1px dashed var(--border);color:var(--accent-2,#8b98a5);border-radius:999px;padding:5px 12px;font-size:.83em;cursor:pointer;font-family:inherit}' +
    '.rs-add:hover{border-color:var(--accent)}' +
    '.rs-dd{display:none;position:absolute;left:0;right:0;margin-top:6px;background:var(--surface);border:1px solid var(--border);border-radius:11px;box-shadow:0 14px 34px rgba(0,0,0,.4);z-index:60;overflow:hidden}' +
    '.rs-dd.open{display:block}' +
    '.rs-srch{position:relative;border-bottom:1px solid var(--border)}' +
    '.rs-srch>i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--muted-2);font-size:.85em}' +
    '.rs-input{width:100%;border:none;background:transparent;padding:11px 13px 11px 34px;color:var(--text);font-family:inherit;font-size:.9em;outline:none}' +
    '.rs-list{max-height:240px;overflow-y:auto;padding:5px}' +
    '.rs-opt{display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:8px;cursor:pointer;font-size:.88em}' +
    '.rs-opt:hover{background:var(--surface-2)}' +
    '.rs-empty{padding:14px;text-align:center;color:var(--muted-2);font-size:.85em}';
  function injectCSS() { if (styled) return; styled = true; var s = document.createElement('style'); s.textContent = CSS; document.head.appendChild(s); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
  function colorHex(c) { if (typeof c === 'string') return /^#[0-9a-fA-F]{3,8}$/.test(c) ? c : '#8b98a5'; c = (c || 0) >>> 0; if (!c) return '#8b98a5'; return '#' + ('000000' + c.toString(16)).slice(-6); }

  function mount(container, opts) {
    injectCSS();
    opts = opts || {};
    var roles = opts.roles || [], selected = (opts.selected || []).map(String), onChange = opts.onChange || function () {};
    var byId = {}; roles.forEach(function (r) { byId[String(r.id)] = r; });
    var ddOpen = false;
    function emit() { onChange(selected.slice()); }
    function render() {
      var h = '<div class="rs-box"><div class="rs-chips">';
      if (!selected.length) h += '<span class="rs-none">Visible par tous les connectés</span>';
      selected.forEach(function (id) {
        var r = byId[id] || { name: id, color: 0 };
        h += '<span class="rs-chip"><span class="rs-dot" style="background:' + colorHex(r.color) + '"></span><span class="rs-nm">' + esc(r.name) + '</span><button type="button" class="rs-x" data-rm="' + esc(id) + '" title="Retirer"><i class="fa-solid fa-xmark"></i></button></span>';
      });
      h += '<button type="button" class="rs-add"><i class="fa-solid fa-plus"></i> Ajouter un rôle</button></div>';
      h += '<div class="rs-dd' + (ddOpen ? ' open' : '') + '"><div class="rs-srch"><i class="fa-solid fa-magnifying-glass"></i><input type="text" class="rs-input" placeholder="Rechercher un rôle…"></div><div class="rs-list"></div></div></div>';
      container.innerHTML = h;
      container.querySelectorAll('.rs-x').forEach(function (b) { b.onclick = function (e) { e.stopPropagation(); var id = b.getAttribute('data-rm'); selected = selected.filter(function (x) { return x !== id; }); emit(); render(); }; });
      container.querySelector('.rs-add').onclick = function () { ddOpen = !ddOpen; render(); if (ddOpen) { var inp = container.querySelector('.rs-input'); if (inp) inp.focus(); } };
      var inp = container.querySelector('.rs-input'); if (inp) inp.oninput = function () { renderList(inp.value); };
      if (ddOpen) renderList('');
    }
    function renderList(q) {
      var list = container.querySelector('.rs-list'); if (!list) return;
      q = (q || '').toLowerCase();
      var avail = roles.filter(function (r) { return selected.indexOf(String(r.id)) === -1 && String(r.name || '').toLowerCase().indexOf(q) !== -1; });
      if (!avail.length) { list.innerHTML = '<div class="rs-empty">Aucun rôle</div>'; return; }
      list.innerHTML = avail.slice(0, 100).map(function (r) { return '<div class="rs-opt" data-add="' + esc(r.id) + '"><span class="rs-dot" style="background:' + colorHex(r.color) + '"></span>' + esc(r.name) + '</div>'; }).join('');
      list.querySelectorAll('.rs-opt').forEach(function (o) { o.onclick = function () { var id = o.getAttribute('data-add'); if (selected.indexOf(id) === -1) selected.push(id); emit(); ddOpen = false; render(); }; });
    }
    container.__rsClose = function () { if (ddOpen) { ddOpen = false; render(); } };
    render();
    return { get: function () { return selected.slice(); }, set: function (ids) { selected = (ids || []).map(String); render(); } };
  }
  if (!window.__RS_GLOBAL) {
    window.__RS_GLOBAL = true;
    document.addEventListener('mousedown', function (e) {
      var dds = document.querySelectorAll('.rs-dd.open');
      for (var i = 0; i < dds.length; i++) {
        var box = dds[i].closest ? dds[i].closest('.rs-box') : null;
        if (box && !box.contains(e.target)) { var host = box.parentNode; if (host && host.__rsClose) host.__rsClose(); }
      }
    }, true);
  }
  window.MDT_ROLESELECT = { mount: mount };
})();
