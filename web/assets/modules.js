(function () {
  var M = [
    { key:'documents', label:'Documentation', desc:'Divisions, catégories, documents',        icon:'fa-book',            color:'#10b981', href:'/documents',          gate:'documents',         dock:true,  status:'v2' },
    { key:'cid',       label:'CID · Dossiers', desc:"Dossiers d'enquête, hiérarchie, pins",   icon:'fa-user-secret',     color:'#6366f1', href:'/cid',                gate:'',         dock:true,  status:'v2' },
    { key:'annonces',  label:'Annonces',      desc:'Diffusion descendante, accusés de réception', icon:'fa-bullhorn',    color:'#22d3ee', href:'/annonces/',          gate:'',         dock:true,  status:'v2' },
    { key:'messagerie', label:'Messagerie',   desc:'Canaux d\'équipe & messages privés',           icon:'fa-comments',   color:'#6366f1', href:'/messagerie/',        gate:'',         dock:true,  status:'v2' },
    { key:'plainte',   label:'Plaintes',      desc:'Dépôt et consultation des plaintes',      icon:'fa-file-signature',  color:'#ec4899', href:'/plainte/',           gate:'plainte',  dock:true,  status:'v2' },
    { key:'saisies',   label:'Saisies',       desc:'Registre des saisies',                    icon:'fa-box-archive',     color:'#f97316', href:'/saisies/',           gate:'saisies',  dock:true,  status:'v2' },
    { key:'dispatch',  label:'Dispatch',      desc:'Board live des patrouilles',              icon:'fa-tower-broadcast', color:'#06b6d4', href:'/dispatch/dispatch',  gate:'dispatch', dock:true,  status:'v2', kind:'app' },
    { key:'carte',     label:'Carte tactique',desc:'Plans partagés, périmètres et briefings', icon:'fa-map-location-dot', color:'#22d3ee', href:'/carte/',            gate:'dispatch', dock:true,  status:'v2', kind:'app' },
    { key:'recherche', label:'Recherche',      desc:'Quadrillage et suivi de fouille',         icon:'fa-magnifying-glass-location', color:'#f97316', href:'/recherche/', gate:'dispatch', dock:true,  status:'v2', kind:'app' },
    { key:'td',        label:'Training Division', desc:'Recrutement, fiches, First Lincoln',  icon:'fa-graduation-cap',  color:'#10b981', href:'/td/',                gate:'td',       dock:true,  status:'v2' },
    { key:'suivi',     label:'Suivi Cadet',   desc:'Commentaires & progression cadets',       icon:'fa-user-graduate', color:'#8b5cf6', href:'/td/suivi',           gate:'suivi',         dock:false, status:'v2' },
    { key:'mdt',       label:'Aide MDT',     desc:'MDT, règlement, codes',                   icon:'fa-shield-halved',   color:'#3b82f6', href:'/mdt/mdt',           gate:'mdt',      dock:true,  status:'v2' },
    { key:'sd',        label:'Laboratoire',   desc:'Division scientifique, analyses',         icon:'fa-flask',           color:'#8b5cf6', href:'/sd/',                gate:'sd',       dock:true,  status:'v2' },
    { key:'admin',     label:'Administration', desc:'Roster, permissions, maintenance',       icon:'fa-sliders',         color:'#ef4444', href:'/admin',              gate:'admin',    dock:true,  status:'v2' },
    { key:'penal',     label:'Code Pénal',    desc:'Simulateur d\'amende & code pénal',        icon:'fa-scale-balanced',  color:'#a855f7', href:'/penal/',             gate:'',         dock:true,  status:'v2' },
    { key:'heures',    label:'Heures & Salaires', desc:'Suivi des heures de service',         icon:'fa-clock',           color:'#f59e0b', href:'/heures',             gate:'',         dock:false, status:'v2' },
    { key:'upload',    label:'Hébergement',   desc:'Galerie de médias',                       icon:'fa-cloud-arrow-up',  color:'#f59e0b', href:'/upload',             gate:'upload'      ,   dock:false, status:'v2' }
  ];

  var h = window.location.hostname;
  var ENV = (h.indexOf('dev.') === 0 || h === 'localhost' || h === '127.0.0.1') ? 'dev' : 'prod';

  window.MDT_ENV = ENV;
  window.MDT_MODULES = M;
  window.MDT_MODULE = function (key) {
    for (var i = 0; i < M.length; i++) if (M[i].key === key) return M[i];
    return null;
  };
  window.MDT_MODULE_READY = function (m) {
    return m.status === 'v2' || ENV === 'prod';
  };
  window.MDT_MODULES_LIVE = function () {
    return M.filter(function (m) { return m.status === 'v2'; });
  };
  window.MDT_MODULES_DOCK = function () {
    return M.filter(function (m) { return m.dock !== false; });
  };

  var ORDER_KEY = 'mdt_module_order';
  window.MDT_MODULE_ORDER_GET = function () {
    try { var o = JSON.parse(localStorage.getItem(ORDER_KEY) || '[]'); return Array.isArray(o) ? o : []; } catch (e) { return []; }
  };
  window.MDT_MODULE_ORDER_SET = function (keys) {
    try { localStorage.setItem(ORDER_KEY, JSON.stringify(keys)); } catch (e) {}
    try { window.dispatchEvent(new CustomEvent('mdt_module_order_changed')); } catch (e) {}
  };
  window.MDT_MODULES_SORTED = function (list) {
    var order = window.MDT_MODULE_ORDER_GET();
    if (!order.length) return list.slice();
    var pos = {};
    order.forEach(function (k, i) { pos[k] = i; });
    return list.slice().sort(function (a, b) {
      var pa = (a.key in pos) ? pos[a.key] : 9999;
      var pb = (b.key in pos) ? pos[b.key] : 9999;
      return pa - pb;
    });
  };

  var HIDE_MODS_KEY = 'mdt_module_hidden';
  window.MDT_MODULE_HIDDEN_GET = function () {
    try { var o = JSON.parse(localStorage.getItem(HIDE_MODS_KEY) || '[]'); return Array.isArray(o) ? o : []; } catch (e) { return []; }
  };
  window.MDT_MODULE_HIDDEN_SET = function (keys) {
    try { localStorage.setItem(HIDE_MODS_KEY, JSON.stringify(keys)); } catch (e) {}
    try { window.dispatchEvent(new CustomEvent('mdt_module_order_changed')); } catch (e) {}
  };

  window.MDT_MODULES_ALLOWED = function (cb) {
    var apply = function (perms) {
      cb(M.filter(function (m) {
        if (!m.gate) return true;
        if (!perms) return true;
        return perms[m.gate] === true;
      }), perms);
    };
    var cached = null;
    try {
      sessionStorage.removeItem('mdt_all_perms');
      var box = JSON.parse(sessionStorage.getItem('mdt_all_perms_v2') || 'null');
      if (box && typeof box === 'object' && box.p && typeof box.p === 'object'
          && (Math.floor(Date.now() / 1000) - (parseInt(box.t, 10) || 0)) < 300) {
        cached = box.p;
      }
    } catch (e) {}
    if (cached && typeof cached === 'object' && !cached.error) { apply(cached); return; }
    var tok = '';
    try { tok = localStorage.getItem('mdt_auth_token') || ''; } catch (e) {}
    if (!tok) { apply(null); return; }
    fetch('/login/permissions_api.php?action=bulk_check', { headers: { Authorization: 'Bearer ' + tok } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        var perms = (d && typeof d === 'object' && !d.error) ? d : null;
        if (perms) {
          var anyTrue = false;
          for (var k in perms) { if (perms[k] === true) { anyTrue = true; break; } }
          if (!anyTrue) perms = null;
        }
        if (perms) { try { sessionStorage.setItem('mdt_all_perms_v2', JSON.stringify({ t: Math.floor(Date.now() / 1000), p: perms })); } catch (e) {} }
        apply(perms);
      })
      .catch(function () { apply(null); });
  };
})();
