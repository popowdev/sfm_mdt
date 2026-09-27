(function () {
  if (window.MDT_AVERTO) return;
  window.MDT_AVERTO = true;

  var API = '/dispatch/dispatch_api.php';
  var affiche = false, joue = false;

  function headers() {
    var h = {};
    try { var t = localStorage.getItem('mdt_auth_token') || ''; if (t) h.Authorization = 'Bearer ' + t; } catch (e) {}
    return h;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  var CSS =
    '.avz{position:fixed;inset:0;z-index:10070;display:none;align-items:center;justify-content:center;' +
    'background:rgba(60,4,4,.90);backdrop-filter:blur(3px);font-family:var(--font,"Inter",sans-serif);}' +
    '.avz.on{display:flex;animation:avzIn .25s ease;}' +
    '@keyframes avzIn{from{opacity:0}to{opacity:1}}' +
    '.avz-box{width:min(560px,92vw);background:#1a0808;border:2px solid #ef4444;border-radius:18px;' +
    'box-shadow:0 0 0 6px rgba(239,68,68,.18),0 30px 80px rgba(0,0,0,.7);overflow:hidden;}' +
    '.avz-hd{background:#ef4444;color:#fff;padding:16px 22px;display:flex;align-items:center;gap:13px;' +
    'font-weight:800;font-size:1.05rem;letter-spacing:.01em;}' +
    '.avz-hd i{font-size:1.5rem;animation:avzP 1.1s ease-in-out infinite;}' +
    '@keyframes avzP{0%,100%{transform:scale(1)}50%{transform:scale(1.16)}}' +
    '.avz-bd{padding:22px;color:#fecaca;font-size:.95rem;line-height:1.65;}' +
    '.avz-bd b{color:#fff;}' +
    '.avz-rang{display:inline-flex;align-items:center;gap:8px;margin:14px 0 4px;padding:8px 15px;' +
    'border-radius:999px;background:rgba(239,68,68,.2);border:1px solid #ef4444;color:#fff;font-weight:800;}' +
    '.avz-sanction{margin-top:15px;padding:13px 16px;border-radius:11px;background:#7f1d1d;' +
    'border-left:4px solid #fff;color:#fff;font-weight:700;font-size:.9rem;line-height:1.55;}' +
    '.avz-meta{margin-top:15px;font-size:.8rem;color:#f87171;}' +
    '.avz-ft{padding:15px 22px;border-top:1px solid rgba(239,68,68,.35);display:flex;justify-content:flex-end;}' +
    '.avz-ok{border:none;background:#ef4444;color:#fff;font-family:inherit;font-weight:800;font-size:.92rem;' +
    'padding:12px 26px;border-radius:11px;cursor:pointer;}' +
    '.avz-ok:hover{background:#dc2626;}';

  function css() {
    if (document.getElementById('avz-css')) return;
    var st = document.createElement('style');
    st.id = 'avz-css'; st.textContent = CSS;
    document.head.appendChild(st);
  }

  function bip() {
    if (joue) return;
    joue = true;
    try {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return;
      var ac = new AC();
      [0, 0.34].forEach(function (t0) {
        var o = ac.createOscillator(), g = ac.createGain();
        o.type = 'square';
        o.frequency.setValueAtTime(880, ac.currentTime + t0);
        o.frequency.setValueAtTime(660, ac.currentTime + t0 + 0.14);
        g.gain.setValueAtTime(0.0001, ac.currentTime + t0);
        g.gain.exponentialRampToValueAtTime(0.35, ac.currentTime + t0 + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, ac.currentTime + t0 + 0.28);
        o.connect(g); g.connect(ac.destination);
        o.start(ac.currentTime + t0); o.stop(ac.currentTime + t0 + 0.3);
      });
      setTimeout(function () { try { ac.close(); } catch (e) {} }, 1500);
    } catch (e) {}
  }

  function montrer(a) {
    if (affiche) return;
    affiche = true;
    css();
    var ov = document.createElement('div');
    ov.className = 'avz on';
    ov.innerHTML =
      '<div class="avz-box">' +
        '<div class="avz-hd"><i class="fa-solid fa-triangle-exclamation"></i>Retrait de service forcé</div>' +
        '<div class="avz-bd">' +
          'Tu <b>n’as pas retiré ton service</b>. La Supervision l’a retiré à ta place.' +
          '<div class="avz-rang"><i class="fa-solid fa-gavel"></i> Avertissement ' + esc(a.rang) + ' / 3 cette semaine</div>' +
          '<div>Motif : <b>' + esc(a.motif) + '</b></div>' +
          (parseInt(a.sanction, 10)
            ? '<div class="avz-sanction"><i class="fa-solid fa-ban"></i> 3ᵉ avertissement : tes heures de la semaine sont annulées.</div>'
            : '<div class="avz-meta">Au 3ᵉ avertissement de la semaine, tes heures de la semaine sont annulées.</div>') +
          '<div class="avz-meta">Posé par ' + esc((a.by_mat ? a.by_mat + ' — ' : '') + (a.by_name || 'la Supervision')) +
          ' · ' + esc(a.created_at || '') + '</div>' +
        '</div>' +
        '<div class="avz-ft"><button class="avz-ok" type="button">J’ai compris</button></div>' +
      '</div>';
    document.body.appendChild(ov);
    bip();
    ov.querySelector('.avz-ok').onclick = function () {
      fetch(API + '?action=averto_vu', {
        method: 'POST',
        headers: (function () { var h = headers(); h['Content-Type'] = 'application/json'; return h; })(),
        body: JSON.stringify({ id: a.id })
      }).catch(function () {});
      ov.remove();
      affiche = false;
      joue = false;
      setTimeout(verifier, 900);
    };
  }

  function verifier() {
    if (affiche || document.hidden) return;
    var t = '';
    try { t = localStorage.getItem('mdt_auth_token') || ''; } catch (e) {}
    if (!t) return;
    fetch(API + '?action=mes_avertos', { headers: headers() })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (d && d.avertos && d.avertos.length) montrer(d.avertos[0]);
      })
      .catch(function () {});
  }

  setTimeout(verifier, 2500);
  setInterval(verifier, 45000);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) setTimeout(verifier, 600);
  });
})();
