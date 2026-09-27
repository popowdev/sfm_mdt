(function () {
  if (window.MDT_SHARE) return;

  var API = '/messagerie/messagerie_api.php';
  var ov = null, convs = [], cur = null, filter = '';

  var CSS =
    '.shr-ov{position:fixed;inset:0;background:rgba(2,6,23,.72);z-index:10055;display:none;' +
    'align-items:center;justify-content:center;padding:24px;}' +
    '.shr-ov.on{display:flex;}' +
    '.shr-box{background:var(--surface,#1e293b);border:1px solid var(--border,#334155);border-radius:16px;' +
    'width:min(440px,100%);max-height:82vh;display:flex;flex-direction:column;' +
    'box-shadow:0 24px 70px rgba(0,0,0,.55);font-family:var(--font,"Inter",sans-serif);color:var(--text,#e2e8f0);}' +
    '.shr-box h3{padding:16px 18px 10px;font-size:1em;display:flex;align-items:center;gap:9px;}' +
    '.shr-what{margin:0 18px 10px;padding:9px 12px;border-radius:10px;background:var(--surface-2,#0f172a);' +
    'border-left:3px solid var(--accent,#22d3ee);font-size:.85em;font-weight:700;}' +
    '.shr-what small{display:block;font-weight:400;color:var(--muted-2,#94a3b8);font-size:.85em;margin-top:2px;}' +
    '.shr-srch{margin:0 18px 8px;position:relative;}' +
    '.shr-srch input{width:100%;box-sizing:border-box;background:var(--surface-2,#0f172a);' +
    'border:1px solid var(--border,#334155);border-radius:10px;padding:9px 12px;color:inherit;' +
    'font-family:inherit;font-size:.88em;}' +
    '.shr-srch input:focus{outline:none;border-color:var(--accent,#22d3ee);}' +
    '.shr-list{padding:0 12px 8px;overflow-y:auto;display:flex;flex-direction:column;gap:4px;min-height:80px;}' +
    '.shr-row{display:flex;align-items:center;gap:10px;width:100%;text-align:left;border:1px solid transparent;' +
    'background:var(--surface-2,#0f172a);color:inherit;font-family:inherit;font-size:.88em;font-weight:600;' +
    'padding:9px 12px;border-radius:10px;cursor:pointer;}' +
    '.shr-row:hover{border-color:var(--accent,#22d3ee);}' +
    '.shr-row.on{border-color:var(--accent,#22d3ee);background:rgba(34,211,238,.12);}' +
    '.shr-row i{color:var(--accent,#22d3ee);width:16px;text-align:center;flex:0 0 auto;}' +
    '.shr-row span{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}' +
    '.shr-note{margin:0 18px 10px;}' +
    '.shr-note input{width:100%;box-sizing:border-box;background:var(--surface-2,#0f172a);' +
    'border:1px solid var(--border,#334155);border-radius:10px;padding:9px 12px;color:inherit;' +
    'font-family:inherit;font-size:.88em;}' +
    '.shr-note input:focus{outline:none;border-color:var(--accent,#22d3ee);}' +
    '.shr-foot{padding:12px 16px;border-top:1px solid var(--border,#334155);display:flex;' +
    'justify-content:flex-end;gap:8px;}' +
    '.shr-btn{border:none;background:var(--surface-2,#0f172a);color:inherit;font-family:inherit;font-weight:700;' +
    'font-size:.85em;padding:9px 15px;border-radius:9px;cursor:pointer;}' +
    '.shr-btn.primary{background:var(--accent,#22d3ee);color:#062a30;}' +
    '.shr-btn[disabled]{opacity:.4;cursor:not-allowed;}' +
    '.shr-empty{color:var(--muted-2,#94a3b8);font-size:.84em;padding:16px 6px;text-align:center;}';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function headers(extra) {
    var h = extra || {};
    try { var t = localStorage.getItem('mdt_auth_token') || ''; if (t) h['Authorization'] = 'Bearer ' + t; } catch (e) {}
    return h;
  }

  function notify(msg, ok) {
    if (typeof window.toast === 'function') window.toast(msg, !!ok);
  }

  function build() {
    var st = document.createElement('style');
    st.textContent = CSS;
    document.head.appendChild(st);
    ov = document.createElement('div');
    ov.className = 'shr-ov';
    ov.innerHTML =
      '<div class="shr-box">' +
        '<h3><i class="fa-solid fa-share-nodes"></i> Partager dans la messagerie</h3>' +
        '<div class="shr-what" id="shrWhat"></div>' +
        '<div class="shr-srch"><input type="text" id="shrSrch" placeholder="Rechercher une conversation…"></div>' +
        '<div class="shr-list" id="shrList"></div>' +
        '<div class="shr-note"><input type="text" id="shrNote" maxlength="300" placeholder="Ajouter un mot (facultatif)"></div>' +
        '<div class="shr-foot">' +
          '<button class="shr-btn" id="shrCancel">Annuler</button>' +
          '<button class="shr-btn primary" id="shrSend" disabled><i class="fa-solid fa-paper-plane"></i> Envoyer</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(ov);
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    ov.querySelector('#shrCancel').onclick = close;
    ov.querySelector('#shrSrch').oninput = function () { filter = this.value.toLowerCase(); renderList(); };
  }

  function close() { if (ov) ov.classList.remove('on'); }

  function renderList() {
    var box = ov.querySelector('#shrList');
    var list = convs.filter(function (c) {
      return !filter || String(c.name || '').toLowerCase().indexOf(filter) !== -1;
    });
    if (!list.length) {
      box.innerHTML = '<div class="shr-empty">Aucune conversation trouvée.</div>';
      return;
    }
    box.innerHTML = '';
    list.slice(0, 60).forEach(function (c) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'shr-row' + (cur === c.id ? ' on' : '');
      var ic = c.type === 'channel' ? 'fa-hashtag' : (c.type === 'group' ? 'fa-user-group' : 'fa-user');
      b.innerHTML = '<i class="fa-solid ' + ic + '"></i><span>' + esc(c.name || 'Conversation') + '</span>';
      b.onclick = function () {
        cur = c.id;
        ov.querySelector('#shrSend').disabled = false;
        renderList();
      };
      box.appendChild(b);
    });
  }

  function open(ref) {
    if (!ov) build();
    cur = null;
    filter = '';
    ov.querySelector('#shrSrch').value = '';
    ov.querySelector('#shrNote').value = '';
    ov.querySelector('#shrSend').disabled = true;
    ov.querySelector('#shrWhat').innerHTML = esc(ref.label || 'Ressource')
      + (ref.sub ? '<small>' + esc(ref.sub) + '</small>' : '');
    ov.querySelector('#shrList').innerHTML = '<div class="shr-empty">Chargement…</div>';
    ov.classList.add('on');

    ov.querySelector('#shrSend').onclick = function () {
      if (!cur) return;
      var btn = this;
      btn.disabled = true;
      fetch(API + '?action=send', {
        method: 'POST', headers: headers({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({
          conv: cur,
          body: ov.querySelector('#shrNote').value || '',
          embed: { t: ref.type, id: String(ref.id) }
        })
      }).then(function (r) { return r.json().catch(function () { return null; }); })
        .then(function (d) {
          btn.disabled = false;
          if (d && !d.error) { notify('Partagé', true); close(); }
          else notify((d && d.error) || 'Partage refusé', false);
        })
        .catch(function () { btn.disabled = false; notify('Réseau indisponible', false); });
    };

    fetch(API + '?action=list_convs', { headers: headers({}) })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        var raw = [].concat((d && d.channels) || [], (d && d.dms) || []);
        convs = raw.filter(function (c) { return c && c.id && !c.archived; })
          .map(function (c) { return { id: c.id, name: c.name || c.title, type: c.type }; });
        renderList();
      })
      .catch(function () {
        ov.querySelector('#shrList').innerHTML = '<div class="shr-empty">Impossible de charger tes conversations.</div>';
      });
  }

  window.MDT_SHARE = open;
})();
