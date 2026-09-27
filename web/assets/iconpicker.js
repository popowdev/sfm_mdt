(function () {
  if (window.MDT_ICONPICKER) return;
  var ICONS = ('fa-house fa-house-chimney fa-building fa-building-shield fa-building-columns fa-city fa-shop fa-warehouse fa-industry fa-hotel fa-school ' +
    'fa-gear fa-gears fa-sliders fa-wrench fa-screwdriver-wrench fa-toolbox fa-hammer fa-plug fa-power-off fa-bolt fa-microchip fa-server fa-database fa-hard-drive fa-network-wired ' +
    'fa-bars fa-grip fa-grip-vertical fa-table fa-table-cells fa-list fa-list-ul fa-list-ol fa-list-check fa-filter fa-magnifying-glass fa-magnifying-glass-location fa-magnifying-glass-plus ' +
    'fa-plus fa-minus fa-xmark fa-check fa-check-double fa-pen fa-pen-to-square fa-pencil fa-eraser fa-trash fa-trash-can fa-copy fa-paste fa-scissors fa-clone ' +
    'fa-rotate fa-rotate-right fa-arrows-rotate fa-download fa-upload fa-share fa-share-nodes fa-link fa-link-slash fa-paperclip fa-thumbtack fa-map-pin fa-location-dot fa-location-crosshairs ' +
    'fa-star fa-star-half-stroke fa-heart fa-bookmark fa-flag fa-flag-checkered fa-bell fa-bell-slash fa-envelope fa-envelope-open fa-inbox fa-paper-plane ' +
    'fa-comment fa-comments fa-comment-dots fa-message fa-phone fa-phone-volume fa-mobile fa-fax fa-at fa-hashtag fa-signal fa-wifi fa-tower-broadcast fa-tower-cell fa-satellite-dish fa-rss ' +
    'fa-key fa-lock fa-lock-open fa-unlock fa-eye fa-eye-slash fa-fingerprint fa-shield fa-shield-halved fa-user-shield fa-user-secret fa-user-lock fa-id-card fa-id-badge fa-passport ' +
    'fa-user fa-users fa-user-group fa-user-plus fa-user-minus fa-user-check fa-user-xmark fa-user-tie fa-user-gear fa-user-pen fa-people-group fa-person fa-child fa-address-card fa-address-book fa-contact-card ' +
    'fa-calendar fa-calendar-day fa-calendar-check fa-calendar-xmark fa-clock fa-hourglass fa-hourglass-half fa-stopwatch fa-gauge fa-gauge-high ' +
    'fa-chart-simple fa-chart-line fa-chart-pie fa-chart-column fa-chart-area fa-arrow-trend-up fa-arrow-trend-down fa-ranking-star ' +
    'fa-circle-info fa-circle-question fa-circle-exclamation fa-triangle-exclamation fa-circle-check fa-circle-xmark fa-ban fa-circle-notch fa-spinner fa-circle-half-stroke ' +
    'fa-folder fa-folder-open fa-folder-plus fa-folder-tree fa-file fa-file-lines fa-file-pdf fa-file-image fa-file-video fa-file-audio fa-file-zipper fa-file-code fa-file-signature fa-file-contract fa-file-invoice fa-file-shield fa-file-circle-check ' +
    'fa-box fa-box-open fa-box-archive fa-boxes-stacked fa-briefcase fa-suitcase fa-folder-closed fa-inbox ' +
    'fa-gavel fa-scale-balanced fa-scale-unbalanced fa-book fa-book-open fa-book-bookmark fa-books? fa-clipboard fa-clipboard-list fa-clipboard-check fa-clipboard-question fa-note-sticky fa-newspaper fa-bullhorn fa-scroll fa-stamp fa-signature ' +
    'fa-tag fa-tags fa-ticket fa-receipt fa-barcode fa-qrcode fa-money-bill fa-money-bill-wave fa-sack-dollar fa-coins fa-credit-card fa-wallet fa-vault fa-piggy-bank fa-hand-holding-dollar fa-cash-register ' +
    'fa-gun fa-hand-fist fa-handcuffs fa-bomb fa-explosion fa-burst fa-fire fa-fire-flame-curved fa-skull fa-skull-crossbones fa-radiation fa-biohazard fa-virus fa-shield-virus fa-crosshairs fa-bullseye fa-circle-dot ' +
    'fa-syringe fa-pills fa-capsules fa-prescription-bottle fa-prescription-bottle-medical fa-cannabis fa-wine-bottle fa-bottle-droplet fa-flask fa-flask-vial fa-vial fa-vials fa-microscope fa-dna fa-droplet fa-magnet fa-atom fa-radiation ' +
    'fa-car fa-car-side fa-car-on fa-truck fa-truck-fast fa-truck-medical fa-van-shuttle fa-taxi fa-motorcycle fa-bicycle fa-helicopter fa-plane fa-jet-fighter fa-ship fa-anchor fa-road fa-traffic-light fa-gas-pump fa-tower-observation fa-tower-broadcast ' +
    'fa-wrench fa-toolbox fa-gears fa-laptop fa-desktop fa-mobile-screen fa-tablet fa-keyboard fa-computer-mouse fa-print fa-camera fa-video fa-image fa-images fa-film fa-music fa-headphones fa-microphone fa-microphone-slash fa-volume-high fa-volume-xmark fa-play fa-pause fa-stop fa-forward fa-backward ' +
    'fa-compass fa-map fa-map-location-dot fa-globe fa-earth-americas fa-route fa-signs-post fa-diamond-turn-right fa-person-walking fa-person-running ' +
    'fa-circle fa-square fa-square-check fa-diamond fa-heart-pulse fa-face-smile fa-face-frown fa-face-meh fa-thumbs-up fa-thumbs-down fa-hand fa-handshake fa-hands-holding fa-crown fa-medal fa-trophy fa-award fa-certificate fa-graduation-cap fa-gem ' +
    'fa-bolt-lightning fa-cloud fa-cloud-rain fa-sun fa-moon fa-snowflake fa-tree fa-leaf fa-seedling fa-paw fa-dog fa-cat fa-crow fa-dove fa-fish fa-mountain fa-water fa-wind fa-temperature-half fa-umbrella fa-tornado fa-volcano ' +
    'fa-arrow-up fa-arrow-down fa-arrow-left fa-arrow-right fa-arrows-up-down fa-arrows-left-right fa-up-down-left-right fa-chevron-up fa-chevron-down fa-chevron-left fa-chevron-right fa-angles-up fa-caret-up fa-caret-down fa-right-left fa-expand fa-compress fa-maximize fa-minimize ' +
    'fa-heart-crack fa-face-angry fa-hand-holding-heart fa-life-ring fa-first-aid? fa-kit-medical fa-hospital fa-house-medical fa-stethoscope fa-heart-circle-check fa-bed fa-wheelchair fa-crutch ' +
    'fa-utensils fa-mug-hot fa-bowl-food fa-pizza-slice fa-burger fa-carrot fa-apple-whole fa-fish-fins fa-drumstick-bite fa-cookie fa-ice-cream fa-champagne-glasses fa-martini-glass fa-beer-mug-empty ' +
    'fa-lightbulb fa-brain fa-eye-low-vision fa-glasses fa-hat-wizard fa-wand-magic-sparkles fa-puzzle-piece fa-dice fa-chess fa-gamepad fa-ghost fa-robot fa-spider fa-dragon fa-hand-spock')
    .split(/\s+/).filter(function (s) { return s && s.indexOf('?') === -1; });
  (function () { var seen = {}, out = []; ICONS.forEach(function (i) { if (!seen[i]) { seen[i] = 1; out.push(i); } }); ICONS = out; })();

  var styled = false;
  var CSS = '' +
    '.ip-box{position:relative;display:block;max-width:340px}' +
    '.ip-trigger{display:inline-flex;align-items:center;gap:9px;width:100%;background:var(--surface-2);border:1px solid var(--border);border-radius:9px;padding:10px 13px;color:var(--text);font-family:inherit;font-size:.92em;cursor:pointer}' +
    '.ip-trigger:hover{border-color:var(--accent)}' +
    '.ip-trigger>i:first-child{color:var(--accent-2);font-size:1.15em;width:22px;text-align:center}' +
    '.ip-cur{flex:1;text-align:left;font-family:ui-monospace,monospace;font-size:.84em;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
    '.ip-caret{color:var(--muted-2);font-size:.8em}' +
    '.ip-pop{display:none;position:absolute;left:0;top:100%;margin-top:6px;width:330px;max-width:90vw;background:var(--surface);border:1px solid var(--border);border-radius:12px;box-shadow:0 16px 40px rgba(0,0,0,.42);z-index:70;overflow:hidden}' +
    '.ip-pop.open{display:block}' +
    '.ip-srch{position:relative;border-bottom:1px solid var(--border)}' +
    '.ip-srch>i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--muted-2);font-size:.85em}' +
    '.ip-input{width:100%;border:none;background:transparent;padding:11px 13px 11px 34px;color:var(--text);font-family:inherit;font-size:.9em;outline:none}' +
    '.ip-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:3px;padding:8px;max-height:250px;overflow-y:auto}' +
    '.ip-ic{aspect-ratio:1;display:flex;align-items:center;justify-content:center;border:1px solid transparent;border-radius:8px;background:transparent;color:var(--muted);cursor:pointer;font-size:1em}' +
    '.ip-ic:hover{background:var(--surface-2);color:var(--text)}' +
    '.ip-ic.on{border-color:var(--accent);color:var(--accent-2);background:rgba(var(--accent-rgb),.12)}' +
    '.ip-empty{grid-column:1/-1;padding:22px;text-align:center;color:var(--muted-2);font-size:.85em}';
  function injectCSS() { if (styled) return; styled = true; var s = document.createElement('style'); s.textContent = CSS; document.head.appendChild(s); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
  function norm(v) { v = String(v || '').trim(); if (!v) return ''; if (v.indexOf('fa-') !== 0) v = 'fa-' + v; return v; }

  function mount(container, opts) {
    injectCSS();
    opts = opts || {};
    var value = norm(opts.value) || 'fa-tag', onChange = opts.onChange || function () {}, open = false, q = '';
    function render() {
      var h = '<div class="ip-box"><button type="button" class="ip-trigger"><i class="fa-solid ' + esc(value) + '"></i> <span class="ip-cur">' + esc(value) + '</span> <i class="fa-solid fa-caret-down ip-caret"></i></button>';
      h += '<div class="ip-pop' + (open ? ' open' : '') + '"><div class="ip-srch"><i class="fa-solid fa-magnifying-glass"></i><input type="text" class="ip-input" placeholder="Rechercher une icône…" value="' + esc(q) + '"></div><div class="ip-grid"></div></div></div>';
      container.innerHTML = h;
      container.querySelector('.ip-trigger').onclick = function () { open = !open; render(); if (open) { var inp = container.querySelector('.ip-input'); if (inp) inp.focus(); grid(q); } };
      var inp = container.querySelector('.ip-input'); if (inp) inp.oninput = function () { q = inp.value; grid(q); };
      if (open) grid(q);
    }
    function grid(query) {
      var g = container.querySelector('.ip-grid'); if (!g) return;
      var ql = String(query || '').toLowerCase().replace(/^fa-/, '');
      var list = ICONS.filter(function (ic) { return ic.indexOf(ql) !== -1; }).slice(0, 210);
      g.innerHTML = list.length ? list.map(function (ic) { return '<button type="button" class="ip-ic' + (ic === value ? ' on' : '') + '" data-ic="' + esc(ic) + '" title="' + esc(ic) + '"><i class="fa-solid ' + esc(ic) + '"></i></button>'; }).join('') : '<div class="ip-empty">Aucune icône</div>';
      g.querySelectorAll('.ip-ic').forEach(function (b) { b.onclick = function () { value = b.getAttribute('data-ic'); open = false; onChange(value); render(); }; });
    }
    render();
    return { get: function () { return value; }, set: function (v) { value = norm(v) || 'fa-tag'; render(); } };
  }
  window.MDT_ICONPICKER = { mount: mount, icons: function () { return ICONS.slice(); } };
})();
