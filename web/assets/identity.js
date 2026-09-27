(function () {
  if (window.MDT_IDENTITY) return;
  var CACHE = [];

  function authHeaders() {
    var h = {};
    try { var t = localStorage.getItem('mdt_auth_token'); if (t) h.Authorization = 'Bearer ' + t; } catch (e) {}
    return h;
  }
  function enhanceFields(root) {
    if (!window.MDT_AUTOCOMPLETE) return;
    var els = (root || document).querySelectorAll('input[list="mdt-suspects"]');
    Array.prototype.forEach.call(els, function (inp) {
      if (inp._identDone) return;
      inp._identDone = true;
      inp.removeAttribute('list');
      MDT_AUTOCOMPLETE.attach(inp, { source: function () { return CACHE; }, min: 1, empty: 'Aucune identité trouvée' });
    });
  }
  function load(cb) {
    fetch('/suspects_api.php?action=list', { headers: authHeaders() })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { CACHE = (d && d.suspects) || []; enhanceFields(); if (cb) cb(CACHE); })
      .catch(function () { if (cb) cb([]); });
  }
  function initObserver() {
    enhanceFields();
    try {
      var mo = new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
          var added = muts[i].addedNodes;
          for (var j = 0; j < added.length; j++) {
            var el = added[j];
            if (el.nodeType !== 1) continue;
            if (el.tagName === 'INPUT' && el.getAttribute('list') === 'mdt-suspects') enhanceFields(el.parentNode || document);
            else if (el.querySelectorAll) enhanceFields(el);
          }
        }
      });
      mo.observe(document.documentElement || document.body, { childList: true, subtree: true });
    } catch (e) {}
  }

  window.MDT_IDENTITY = { load: load, list: function () { return CACHE; }, refresh: load, enhance: enhanceFields };

  if (document.readyState !== 'loading') { load(); initObserver(); }
  else document.addEventListener('DOMContentLoaded', function () { load(); initObserver(); });
})();
