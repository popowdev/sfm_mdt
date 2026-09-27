(function () {
  if (window.MDT_RECHERCHE) return;

  var API = '/recherche/rch_api.php';

  var ETATS = [
    { k: 'non',     lbl: 'Non fouillée', court: 'Non',      color: '#64748B', ic: 'fa-circle' },
    { k: 'encours', lbl: 'En cours',     court: 'En cours', color: '#F59E0B', ic: 'fa-person-walking' },
    { k: 'ok',      lbl: 'Fouillée',     court: 'Fouillée', color: '#22C55E', ic: 'fa-check' },
    { k: 'ras',     lbl: 'RAS',          court: 'RAS',      color: '#15803D', ic: 'fa-check-double' },
    { k: 'suspect', lbl: 'Suspect',      court: 'Suspect',  color: '#EF4444', ic: 'fa-triangle-exclamation' }
  ];
  var BY_K = {};
  ETATS.forEach(function (e) { BY_K[e.k] = e; });

  var CAT = null, OP = null, ZE = {}, CE = {}, REV = 0, ETAG = null, POLL = null;
  var MAP = null, HOST = null, CB = {}, notify = function () {};
  var showGrid = false, showZones = true, showSect = true, tab = 'zones';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }
  function headers(x) {
    var h = x || {};
    try { var t = localStorage.getItem('mdt_auth_token') || ''; if (t) h['Authorization'] = 'Bearer ' + t; } catch (e) {}
    return h;
  }
  function post(a, b) {
    return fetch(API + '?action=' + a, {
      method: 'POST', headers: headers({ 'Content-Type': 'application/json' }), body: JSON.stringify(b)
    }).then(function (r) { return r.json().catch(function () { return null; }); });
  }

  var CSS =
    '.rq-tabs{display:flex;gap:3px;padding:9px 11px 0;}' +
    '.rq-tabs button{flex:1;border:none;background:var(--surface-2);color:var(--muted);font-family:inherit;' +
    'font-weight:800;font-size:.76em;padding:8px;border-radius:9px 9px 0 0;cursor:pointer;}' +
    '.rq-tabs button.on{background:var(--accent);color:#3b1a02;}' +
    '.rq-body{flex:1 1 auto;min-height:0;overflow-y:auto;padding:10px 11px 18px;}' +
    '.rq-prog{padding:9px 11px;}' +
    '.rq-bar{height:8px;border-radius:5px;background:var(--surface-2);overflow:hidden;display:flex;}' +
    '.rq-bar i{display:block;height:100%;}' +
    '.rq-pct{font-size:.75em;color:var(--muted-2);margin-top:6px;}' +
    '.rq-grid{display:grid;gap:3px;}' +
    '.rq-cell{border:none;border-radius:5px;font-family:inherit;font-weight:800;font-size:.62em;color:#fff;' +
    'aspect-ratio:1;cursor:pointer;display:flex;align-items:center;justify-content:center;opacity:.92;}' +
    '.rq-cell:hover{outline:2px solid #fff;opacity:1;}' +
    '.rq-sec{margin-bottom:11px;}' +
    '.rq-sech{display:flex;align-items:center;gap:8px;padding:6px 9px;border-radius:8px;font-size:.72em;' +
    'font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#fff;}' +
    '.rq-sech span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}' +
    '.rq-zone{display:flex;align-items:center;gap:7px;padding:5px 4px 5px 9px;border-radius:7px;}' +
    '.rq-zone:hover{background:rgba(255,255,255,.04);}' +
    '.rq-zn{flex:1 1 auto;min-width:0;font-size:.81em;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer;}' +
    '.rq-zn small{display:block;font-size:.82em;color:var(--muted-2);}' +
    '.rq-pill{border:none;font-family:inherit;font-size:.66em;font-weight:800;padding:4px 8px;border-radius:999px;' +
    'cursor:pointer;color:#fff;white-space:nowrap;flex:0 0 auto;}' +
    '.rq-geo{border:none;background:none;color:var(--muted-2);cursor:pointer;padding:3px 4px;flex:0 0 auto;font-size:.78em;}' +
    '.rq-geo.on{color:var(--accent-2);}' +
    '.rq-menu{position:fixed;z-index:10050;display:none;flex-direction:column;gap:3px;background:var(--surface);' +
    'border:1px solid var(--border);border-radius:11px;padding:6px;box-shadow:0 16px 44px rgba(0,0,0,.5);min-width:170px;}' +
    '.rq-menu.on{display:flex;}' +
    '.rq-menu button{border:none;background:none;color:var(--text);font-family:inherit;font-size:.82em;' +
    'font-weight:700;padding:7px 12px;border-radius:7px;cursor:pointer;display:flex;align-items:center;gap:9px;text-align:left;}' +
    '.rq-menu button:hover{background:var(--surface-2);}' +
    '.rq-menu i{width:14px;text-align:center;}' +
    '.rq-empty{color:var(--muted-2);font-size:.83em;line-height:1.65;padding:22px 10px;text-align:center;}' +
    '.tac-ztip{background:var(--surface)!important;border:1px solid var(--border)!important;color:var(--text)!important;' +
    'font-weight:700!important;font-size:.75rem!important;box-shadow:0 6px 18px rgba(0,0,0,.4)!important;}' +
    '.tac-ztip::before{display:none!important;}';

  function injectCSS() {
    if (document.getElementById('rq-css')) return;
    var st = document.createElement('style');
    st.id = 'rq-css'; st.textContent = CSS;
    document.head.appendChild(st);
  }

  function colName(i) {
    var s = '';
    i += 1;
    while (i > 0) { var m = (i - 1) % 26; s = String.fromCharCode(65 + m) + s; i = Math.floor((i - 1) / 26); }
    return s;
  }

  function cellList() {
    if (!OP) return [];
    var out = [];
    for (var r = 0; r < OP.rows; r++) {
      for (var c = 0; c < OP.cols; c++) {
        out.push({ id: colName(c) + (r + 1), c: c, r: r });
      }
    }
    return out;
  }

  function cellPts(c) {
    var w = 1 / OP.cols, h = 1 / OP.rows;
    var x = c.c * w, y = c.r * h;
    return [[x, y], [x + w, y], [x + w, y + h], [x, y + h]];
  }

  function eOfCell(id) { return (CE[id] && CE[id].etat) || 'non'; }
  function eOfZone(id) { return (ZE[id] && ZE[id].etat) || 'non'; }

  function stats() {
    var c = {}; ETATS.forEach(function (e) { c[e.k] = 0; });
    if (tab === 'grid') cellList().forEach(function (x) { c[eOfCell(x.id)]++; });
    else (CAT ? CAT.zones : []).forEach(function (z) { c[eOfZone(z.id)]++; });
    return c;
  }

  function paint() {
    if (!MAP || !OP) { if (MAP) MAP.clearOverlay(); return; }
    var list = [];
    if (showGrid) {
      cellList().forEach(function (c) {
        var e = BY_K[eOfCell(c.id)];
        list.push({ id: 'C:' + c.id, pts: cellPts(c), color: e.color,
                    label: 'Case ' + c.id + ' — ' + e.lbl, dim: e.k === 'non' });
      });
    }
    if (showSect && CAT) {
      CAT.secteurs.forEach(function (sec) {
        if (!sec.pts) return;
        var zs = CAT.zones.filter(function (z) { return z.secteur_id === sec.id; });
        var reste = zs.filter(function (z) { return eOfZone(z.id) === 'non'; }).length;
        var fini = zs.length && reste === 0;
        var suspect = zs.some(function (z) { return eOfZone(z.id) === 'suspect'; });
        var col = suspect ? '#EF4444' : (fini ? '#15803D' : sec.couleur);
        list.push({ id: 'S:' + sec.id, pts: sec.pts, color: col,
                    label: sec.nom + ' — ' + (zs.length - reste) + '/' + zs.length + ' fouillé',
                    dim: true });
      });
    }
    if (showZones && CAT) {
      CAT.zones.forEach(function (z) {
        if (!z.pts) return;
        var e = BY_K[eOfZone(z.id)];
        list.push({ id: 'Z:' + z.id, pts: z.pts, color: e.color,
                    label: z.nom + ' — ' + e.lbl, dim: false });
      });
    }
    MAP.setOverlay(list, function (id) {
      if (id.indexOf('C:') === 0) menu(id.slice(2), null, 'cell');
      else if (id.indexOf('S:') === 0) { tab = 'zones'; render(); MAP.focusOverlay(id); }
      else menu(parseInt(id.slice(2), 10), null, 'zone');
    });
  }

  function menu(id, anchor, kind) {
    var m = document.getElementById('rqMenu');
    m.innerHTML = '';
    var nom = kind === 'cell' ? 'Case ' + id
      : ((CAT.zones.filter(function (z) { return z.id === id; })[0] || {}).nom || 'Zone');
    var t = document.createElement('div');
    t.style.cssText = 'padding:6px 12px 4px;font-size:.72em;font-weight:800;color:var(--muted-2);text-transform:uppercase';
    t.textContent = nom;
    m.appendChild(t);
    ETATS.forEach(function (e) {
      var b = document.createElement('button');
      b.type = 'button';
      b.innerHTML = '<i class="fa-solid ' + e.ic + '" style="color:' + e.color + '"></i>' + esc(e.lbl);
      b.onclick = function () {
        m.classList.remove('on');
        if (e.k === 'suspect') {
          window.MDT_PROMPT('Qu’as-tu relevé ?', '', { title: nom, icon: 'fa-triangle-exclamation' })
            .then(function (n) { if (n !== null) set(id, e.k, n, kind); });
        } else set(id, e.k, '', kind);
      };
      m.appendChild(b);
    });
    var r = anchor ? anchor.getBoundingClientRect()
                   : { left: window.innerWidth / 2 - 85, bottom: window.innerHeight / 2 - 60 };
    m.style.left = Math.min(window.innerWidth - 190, Math.max(6, r.left - 60)) + 'px';
    m.style.top = Math.min(window.innerHeight - 250, r.bottom + 6) + 'px';
    m.classList.add('on');
  }

  function set(id, etat, note, kind) {
    if (!OP) return;
    var store = kind === 'cell' ? CE : ZE;
    var prev = store[id] ? JSON.parse(JSON.stringify(store[id])) : null;
    store[id] = { etat: etat, note: note || '', by_mat: CAT.me.mat, by_name: CAT.me.nom };
    render();
    var body = kind === 'cell'
      ? { op: OP.id, cell: id, etat: etat, note: note || '' }
      : { op: OP.id, zone: id, etat: etat, note: note || '' };
    post(kind === 'cell' ? 'cell_set' : 'zone_set', body).then(function (d) {
      if (d && d.success) { REV = Math.max(REV, d.rev); return; }
      if (prev) store[id] = prev; else delete store[id];
      render();
      notify((d && d.error) || 'Changement refusé', false);
    }).catch(function () {
      if (prev) store[id] = prev; else delete store[id];
      render();
      notify('Réseau indisponible', false);
    });
  }

  function render() {
    if (!HOST) return;
    injectCSS();
    var c = stats();
    var total = (tab === 'grid') ? cellList().length : ((CAT && CAT.zones.length) || 0);

    if (CB.onLegend) {
      CB.onLegend(ETATS.map(function (e) {
        return '<span class="rc-lg" style="background:' + e.color + '22;color:' + e.color + '">'
             + '<i class="fa-solid ' + e.ic + '"></i>' + c[e.k] + ' ' + esc(e.court) + '</span>';
      }).join(''));
    }

    var h = '<div class="rq-tabs">'
      + '<button id="rqTabG" class="' + (tab === 'grid' ? 'on' : '') + '">Quadrillage</button>'
      + '<button id="rqTabZ" class="' + (tab === 'zones' ? 'on' : '') + '">Lieux à risque</button></div>';
    if (OP && total) {
      var fait = total - c.non;
      h += '<div class="rq-prog"><div class="rq-bar">';
      ETATS.forEach(function (e) {
        if (c[e.k]) h += '<i style="width:' + (c[e.k] / total * 100).toFixed(2) + '%;background:' + e.color + '"></i>';
      });
      h += '</div><div class="rq-pct">' + fait + ' / ' + total + ' — ' + Math.round(fait / total * 100) + ' % couvert</div></div>';
    }
    h += '<div class="rq-body" id="rqBody"></div>';
    HOST.innerHTML = '<div style="display:flex;flex-direction:column;height:100%;min-height:0">' + h + '</div>';

    document.getElementById('rqTabG').onclick = function () { tab = 'grid'; render(); };
    document.getElementById('rqTabZ').onclick = function () { tab = 'zones'; render(); };

    var body = document.getElementById('rqBody');
    if (!OP) {
      body.innerHTML = '<div class="rq-empty"><i class="fa-solid fa-bullhorn" style="font-size:1.8em;opacity:.4"></i>'
        + '<br><br>Aucune recherche en cours.<br>La Supervision en lance une avec <b>Lancer</b> :'
        + ' tous les agents reçoivent une notification avec le lien.</div>';
      paint();
      return;
    }

    if (tab === 'grid') {
      var g = document.createElement('div');
      g.className = 'rq-grid';
      g.style.gridTemplateColumns = 'repeat(' + OP.cols + ',1fr)';
      cellList().forEach(function (cell) {
        var e = BY_K[eOfCell(cell.id)];
        var b = document.createElement('button');
        b.className = 'rq-cell';
        b.style.background = e.color;
        b.textContent = cell.id;
        var st = CE[cell.id];
        b.title = cell.id + ' — ' + e.lbl + (st && st.by_mat ? ' (par ' + st.by_mat + ')' : '');
        b.onclick = function () { menu(cell.id, this, 'cell'); };
        g.appendChild(b);
      });
      body.appendChild(g);
      var hint = document.createElement('p');
      hint.className = 'rq-pct';
      hint.style.marginTop = '10px';
      hint.textContent = 'Clique une case pour changer son état. Les cases se colorent aussi sur la carte.';
      body.appendChild(hint);
      paint();
      return;
    }

    CAT.secteurs.forEach(function (s) {
      var zs = CAT.zones.filter(function (z) { return z.secteur_id === s.id; });
      if (!zs.length) return;
      var reste = zs.filter(function (z) { return eOfZone(z.id) === 'non'; }).length;
      var sec = document.createElement('div');
      sec.className = 'rq-sec';
      var head = document.createElement('div');
      head.className = 'rq-sech';
      head.style.background = s.couleur;
      head.innerHTML = '<span>' + esc(s.nom) + '</span><em style="font-style:normal;opacity:.85">'
        + (zs.length - reste) + '/' + zs.length + '</em>';
      sec.appendChild(head);
      zs.forEach(function (z) {
        var e = BY_K[eOfZone(z.id)], st = ZE[z.id] || {};
        var row = document.createElement('div');
        row.className = 'rq-zone';
        var sub = st.by_mat ? st.by_mat + (st.note ? ' — ' + st.note : '') : '';
        row.innerHTML = '<span class="rq-zn">' + esc(z.nom) + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</span>'
          + '<button class="rq-geo' + (z.pts ? ' on' : '') + '" title="'
          + (z.pts ? 'Centrer sur la carte (clic droit : retracer)' : 'Tracer le contour') + '">'
          + '<i class="fa-solid fa-draw-polygon"></i></button>'
          + '<button class="rq-pill" style="background:' + e.color + '">' + esc(e.court) + '</button>';
        row.querySelector('.rq-pill').onclick = function () { menu(z.id, this, 'zone'); };
        row.querySelector('.rq-zn').onclick = function () { if (z.pts) MAP.focusOverlay('Z:' + z.id); else tracer(z); };
        var g2 = row.querySelector('.rq-geo');
        g2.onclick = function () { if (z.pts) MAP.focusOverlay('Z:' + z.id); else tracer(z); };
        g2.oncontextmenu = function (ev) { ev.preventDefault(); tracer(z); };
        sec.appendChild(row);
      });
      body.appendChild(sec);
    });
    paint();
  }

  function tracer(z) {
    if (!MAP) return;
    MAP.canWrite = true;
    notify('Trace le contour de « ' + z.nom + ' » sur la carte', true);
    MAP.captureZone(function (pts) {
      MAP.canWrite = false;
      MAP.setTool('pan');
      post('zone_geom', { zone: z.id, pts: pts }).then(function (d) {
        if (d && d.success) { z.pts = pts; notify('Contour enregistré', true); render(); }
        else notify((d && d.error) || 'Refusé', false);
      });
    });
  }

  function nouvelle() {
    if (!CAT || !CAT.is_sup) { notify('Seule la Supervision peut lancer une recherche', false); return; }
    window.MDT_PROMPT('Motif de la recherche (visible par tous)', 'Agent porté disparu',
      { title: 'Lancer une recherche', icon: 'fa-bullhorn' }).then(function (t) {
      if (t === null || !String(t).trim()) return;
      window.MDT_PROMPT('Finesse du quadrillage (4 à 16 cases de côté)', '8',
        { title: 'Quadrillage', icon: 'fa-table-cells' }).then(function (n) {
        if (n === null) return;
        var k = Math.max(4, Math.min(16, parseInt(n, 10) || 8));
        post('op_create', { titre: String(t).trim(), cols: k, rows: k }).then(function (d) {
          if (d && d.success) {
            notify('Recherche lancée — ' + d.notifies + ' agent(s) notifié(s)', true);
            ouvrir(d.op);
          } else notify((d && d.error) || 'Lancement refusé', false);
        });
      });
    });
  }

  function cloturer() {
    if (!OP) return;
    window.MDT_CONFIRM('Clôturer « ' + OP.titre + ' » ? Les états sont conservés dans l’historique.',
      { title: 'Clôturer', icon: 'fa-flag-checkered', okLabel: 'Clôturer' }).then(function (y) {
      if (!y) return;
      post('op_close', { op: OP.id }).then(function (d) {
        if (d && d.success) { notify('Recherche clôturée', true); ouvrir(OP.id); }
        else notify((d && d.error) || 'Refusé', false);
      });
    });
  }

  function choisir(anchor) {
    fetch(API + '?action=bootstrap', { headers: headers({}) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var m = document.getElementById('rqMenu');
        m.innerHTML = '';
        var ops = (d && d.ops) || [];
        if (!ops.length) {
          var e = document.createElement('div');
          e.style.cssText = 'padding:9px 12px;font-size:.82em;color:var(--muted-2)';
          e.textContent = 'Aucune recherche enregistrée';
          m.appendChild(e);
        }
        ops.slice(0, 14).forEach(function (o) {
          var b = document.createElement('button');
          b.type = 'button';
          b.innerHTML = '<i class="fa-solid ' + (o.statut === 'active' ? 'fa-circle-dot' : 'fa-lock')
            + '" style="color:' + (o.statut === 'active' ? '#22C55E' : '#64748B') + '"></i>' + esc(o.titre);
          b.onclick = function () { m.classList.remove('on'); ouvrir(o.id); };
          m.appendChild(b);
        });
        var r2 = anchor ? anchor.getBoundingClientRect() : { left: 200, bottom: 60 };
        m.style.left = Math.max(6, r2.left - 200) + 'px';
        m.style.top = (r2.bottom + 6) + 'px';
        m.classList.add('on');
      });
  }

  function ouvrir(id) {
    REV = 0; ETAG = null; ZE = {}; CE = {};
    fetch(API + '?action=state&op=' + id, { headers: headers({}) })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || d.error) { notify('Recherche inaccessible', false); return; }
        OP = d.op; REV = d.rev;
        (d.etats || []).forEach(function (e) { ZE[e.zone_id] = e; });
        (d.cells || []).forEach(function (e) { CE[e.cell] = e; });
        try { localStorage.setItem('mdt_rch_op', String(id)); } catch (e) {}
        if (CB.onOp) CB.onOp(OP);
        render();
        startPoll();
      });
  }

  function sync() {
    if (!OP || document.hidden) return;
    var h = headers({});
    if (REV > 0 && ETAG) h['If-None-Match'] = ETAG;
    fetch(API + '?action=state&op=' + OP.id + '&since=' + REV, { headers: h })
      .then(function (r) {
        ETAG = r.headers.get('ETag') || ETAG;
        if (r.status === 304) return null;
        return r.ok ? r.json() : null;
      })
      .then(function (d) {
        if (!d) return;
        var chg = false;
        (d.etats || []).forEach(function (e) { ZE[e.zone_id] = e; chg = true; });
        (d.cells || []).forEach(function (e) { CE[e.cell] = e; chg = true; });
        REV = Math.max(REV, d.rev);
        if (d.op) { OP = d.op; if (CB.onOp) CB.onOp(OP); }
        if (chg) render();
      })
      .catch(function () {});
  }

  function startPoll() { stopPoll(); POLL = setInterval(sync, 4000); }
  function stopPoll() { if (POLL) { clearInterval(POLL); POLL = null; } }

  var EDIT = false, MODE = null, SECT_COURANT = null;

  function ouvrirMenu(html, choix) {
    var m = document.getElementById('rqMenu');
    m.innerHTML = '';
    var t = document.createElement('div');
    t.style.cssText = 'padding:6px 12px 4px;font-size:.72em;font-weight:800;color:var(--muted-2);text-transform:uppercase';
    t.textContent = html;
    m.appendChild(t);
    choix.forEach(function (c) {
      var b = document.createElement('button');
      b.type = 'button';
      b.innerHTML = c.html;
      b.onclick = function (e) { e.stopPropagation(); m.classList.remove('on'); c.go(); };
      m.appendChild(b);
    });
    m.style.left = Math.max(6, window.innerWidth / 2 - 110) + 'px';
    m.style.top = '92px';
    setTimeout(function () { m.classList.add('on'); }, 30);
  }

  function decoupage() {
    if (!CAT || !CAT.is_sup) { notify('Le découpage est réservé à la Supervision', false); return; }
    if (EDIT) { stopEdit(); return; }
    ouvrirMenu('Que veux-tu tracer ?', [
      { html: '<i class="fa-solid fa-layer-group"></i>Un SECTEUR (grande zone)', go: function () { startEdit('secteur'); } },
      { html: '<i class="fa-solid fa-location-dot"></i>Une ZONE dans un secteur', go: function () { choisirSecteurPuisTracer(); } }
    ]);
  }

  function choisirSecteurPuisTracer() {
    var opts = CAT.secteurs.map(function (sec) {
      return { html: '<i class="fa-solid fa-square" style="color:' + sec.couleur + '"></i>' + esc(sec.nom),
               go: function () { SECT_COURANT = sec; startEdit('zone'); } };
    });
    ouvrirMenu('Dans quel secteur ?', opts);
  }

  function startEdit(mode) {
    EDIT = true; MODE = mode;
    if (CB.onEdit) CB.onEdit(true, mode === 'zone' && SECT_COURANT ? SECT_COURANT.nom : 'secteurs');
    notify(mode === 'secteur' ? 'Trace un secteur sur la carte'
                              : 'Trace une zone dans « ' + SECT_COURANT.nom + ' »', true);
    armer();
  }

  function stopEdit() {
    EDIT = false; MODE = null; SECT_COURANT = null;
    MAP.canWrite = false; MAP.setTool('pan');
    if (CB.onEdit) CB.onEdit(false);
    notify('Découpage terminé', true);
  }

  function armer() {
    if (!EDIT) return;
    MAP.canWrite = true;
    MAP.captureZone(function (pts) {
      MAP.canWrite = false;
      var titre = MODE === 'secteur' ? 'Nouveau secteur' : 'Nouvelle zone dans ' + SECT_COURANT.nom;
      window.MDT_PROMPT('Nom', '', { title: titre, icon: 'fa-draw-polygon' }).then(function (nom) {
        nom = (nom === null) ? null : String(nom).trim();
        if (!nom) { armer(); return; }
        if (MODE === 'secteur') enregistrerSecteur(nom, pts);
        else enregistrerZone(nom, pts);
      });
    });
  }

  function enregistrerSecteur(nom, pts) {
    var coul = '#' + ('00000' + Math.floor(Math.random() * 12582912 + 3355443).toString(16)).slice(-6);
    post('secteur_add', { nom: nom, couleur: coul }).then(function (d) {
      if (!d || !d.success) { notify((d && d.error) || 'Refusé', false); armer(); return; }
      fetch(API + '?action=bootstrap', { headers: headers({}) })
        .then(function (r) { return r.json(); })
        .then(function (b) {
          CAT = b;
          var sec = b.secteurs.filter(function (x) { return x.nom === nom; })[0];
          if (!sec) { notify('Secteur introuvable après création', false); armer(); return; }
          post('secteur_geom', { secteur: sec.id, pts: pts }).then(function (g) {
            if (g && g.success) { sec.pts = pts; notify('Secteur « ' + nom + ' » enregistré', true); render(); }
            else notify((g && g.error) || 'Contour refusé', false);
            armer();
          });
        });
    });
  }

  function enregistrerZone(nom, pts) {
    post('zone_add', { secteur: SECT_COURANT.id, nom: nom }).then(function (d) {
      if (!d || !d.success) { notify((d && d.error) || 'Refusé', false); armer(); return; }
      post('zone_geom', { zone: d.zone, pts: pts }).then(function (g) {
        if (g && g.success) {
          CAT.zones.push({ id: d.zone, secteur_id: SECT_COURANT.id, nom: nom, pts: pts, ordre: 999 });
          notify('« ' + nom + ' » enregistrée', true);
          render();
        } else notify((g && g.error) || 'Contour refusé', false);
        armer();
      });
    });
  }

  function effacerContoursSecteurs() {
    window.MDT_CONFIRM('Effacer tous les contours de secteurs ? Les noms et les zones sont conservés.',
      { title: 'Effacer les contours', icon: 'fa-eraser', danger: true, okLabel: 'Effacer' })
      .then(function (y) {
        if (!y) return;
        var n = 0;
        CAT.secteurs.forEach(function (sec) {
          if (!sec.pts) return;
          n++;
          post('secteur_geom', { secteur: sec.id, pts: [] }).then(function () { sec.pts = null; render(); });
        });
        notify(n ? n + ' contour(s) effacé(s)' : 'Aucun contour à effacer', true);
      });
  }

  function setLayers(g, z, se) {
    showGrid = g; showZones = z;
    if (typeof se === 'boolean') showSect = se;
    paint();
  }

  function mount(host, map, notifyFn, cbs) {
    HOST = host; MAP = map; CB = cbs || {};
    if (notifyFn) notify = notifyFn;
    injectCSS();
    if (!document.getElementById('rqMenu')) {
      var m = document.createElement('div');
      m.className = 'rq-menu'; m.id = 'rqMenu';
      document.body.appendChild(m);
      document.addEventListener('click', function (e) {
        if (EDIT) return;
        if (!e.target.closest || !e.target.closest('#rqMenu,.rq-pill,.rq-cell,#rcOps')) m.classList.remove('on');
      });
    }
    render();
    fetch(API + '?action=bootstrap', { headers: headers({}) })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || d.error) { HOST.innerHTML = '<div class="rq-empty">Module inaccessible.</div>'; return; }
        CAT = d;
        if (!/[?&]op=/.test(location.search)) {
          var last = null;
          try { last = localStorage.getItem('mdt_rch_op'); } catch (e) {}
          var act = (d.ops || []).filter(function (o) { return o.statut === 'active'; });
          var pick = act.filter(function (o) { return String(o.id) === String(last); })[0] || act[0];
          if (pick) { ouvrir(pick.id); return; }
        }
        render();
      });
  }

  function unmount() { stopPoll(); if (MAP) MAP.clearOverlay(); HOST = null; }

  window.MDT_RECHERCHE = {
    mount: mount, unmount: unmount, refresh: sync,
    nouvelle: nouvelle, choisir: choisir, cloturer: cloturer,
    ouvrir: ouvrir, setLayers: setLayers,
    decoupage: decoupage, effacerContours: effacerContoursSecteurs,
    enEdition: function () { return EDIT; }
  };
})();
