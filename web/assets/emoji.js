(function () {
  if (window.MDT_EMOJI) return;
  var CATS = [
    { key: 'recent', icon: 'fa-clock-rotate-left', name: 'Récents', list: [] },
    { key: 'smileys', icon: 'fa-face-smile', name: 'Smileys', list: ['😀','😃','😄','😁','😆','😅','🤣','😂','🙂','🙃','😉','😊','😇','🥰','😍','🤩','😘','😗','😚','😙','😋','😛','😜','🤪','😝','🤗','🤭','🤫','🤔','😐','😑','😶','😏','😒','🙄','😬','😌','😔','😪','🤤','😴','😷','🤒','🤕','🤢','🤮','🤧','🥵','🥶','🥴','😵','🤯','🤠','🥳','😎','🤓','🧐','😕','😟','🙁','☹️','😮','😯','😲','😳','🥺','😦','😧','😨','😰','😥','😢','😭','😱','😖','😣','😞','😓','😩','😫','🥱','😤','😡','😠','🤬','😈','👿','💀','💩','🤡','👻','👽','🤖'] },
    { key: 'gestes', icon: 'fa-hand', name: 'Gestes', list: ['👍','👎','👊','✊','🤛','🤜','🤞','✌️','🤟','🤘','👌','🤏','👈','👉','👆','👇','☝️','✋','🤚','🖐️','🖖','👋','🤙','💪','🙏','🤝','👏','🙌','👐','🤲','🫡','🫢','🫣','🫰','🤌','✍️','💅','🤳','💯','🔥'] },
    { key: 'coeurs', icon: 'fa-heart', name: 'Cœurs', list: ['❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣️','💕','💞','💓','💗','💖','💘','💝','💟','♥️','💋','💢','💥','💫','💦','💨'] },
    { key: 'animaux', icon: 'fa-paw', name: 'Animaux', list: ['🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐨','🐯','🦁','🐮','🐷','🐸','🐵','🐔','🐧','🐦','🐤','🦆','🦅','🦉','🐺','🐗','🐴','🦄','🐝','🐛','🦋','🐌','🐞','🐢','🐍','🐙','🦑','🦀','🐟','🐬','🐳','🐋','🦈','🐊','🐘','🦏','🐫','🦒','🐄','🐎','🐖','🐑','🐐','🦌','🐕','🐈','🐓','🦃','🐇','🌸','🌹','🌺','🌻','🌼','🌷','🌱','🌲','🌳','🌴','🌵','🍀','🍁','🍂'] },
    { key: 'food', icon: 'fa-burger', name: 'Nourriture', list: ['🍏','🍎','🍐','🍊','🍋','🍌','🍉','🍇','🍓','🫐','🍒','🍑','🥭','🍍','🥥','🥝','🍅','🥑','🥦','🥕','🌽','🥔','🥐','🍞','🥖','🧀','🥚','🍳','🥞','🥓','🥩','🍗','🍖','🌭','🍔','🍟','🍕','🥪','🌮','🌯','🥗','🍝','🍜','🍲','🍛','🍣','🍱','🍤','🍙','🍚','🍥','🍢','🍡','🍧','🍨','🍦','🥧','🍰','🎂','🍮','🍭','🍬','🍫','🍿','🍩','🍪','🥛','☕','🍵','🥤','🍺','🍻','🥂','🍷','🥃','🍸','🍹','🍾','🧉'] },
    { key: 'activites', icon: 'fa-futbol', name: 'Activités', list: ['⚽','🏀','🏈','⚾','🥎','🎾','🏐','🏉','🥏','🎱','🏓','🏸','🥅','🏒','🏑','🏏','⛳','🏹','🎣','🥊','🥋','⛸️','🎿','⛷️','🏂','🏋️','🤼','🤸','⛹️','🤾','🏌️','🏇','🧘','🏄','🏊','🚣','🧗','🚴','🚵','🎯','🎮','🕹️','🎲','🎰','🎳','🏆','🥇','🥈','🥉','🏅','🎖️','🎫','🎪','🎭','🎨','🎬','🎤','🎧','🎼','🎹','🥁','🎷','🎺','🎸','🎻'] },
    { key: 'voyage', icon: 'fa-car', name: 'Voyage', list: ['🚗','🚕','🚙','🚌','🚎','🏎️','🚓','🚑','🚒','🚐','🚚','🚛','🚜','🛴','🚲','🛵','🏍️','🚨','🚔','🚘','🚖','🚀','🛸','🚁','✈️','🛫','🛬','🚢','⛵','🚤','🛥️','⚓','🗺️','🗽','🗼','🏰','🏯','🎡','🎢','🎠','⛲','🏖️','🏝️','🏔️','⛰️','🌋','🏕️','⛺','🏠','🏡','🏢','🏬','🏥','🏦','🏨','🏪','🏫','⛪','🕌','🌍','🌎','🌏','🌐','🗾','🧭','🌙','⭐','🌟','✨','⚡','☀️','🌤️','⛅','🌧️','⛈️','🌨️','❄️','☃️','🌈','🔥','💧','🌊'] },
    { key: 'objets', icon: 'fa-lightbulb', name: 'Objets', list: ['⌚','📱','💻','⌨️','🖥️','🖨️','🖱️','💽','💾','💿','📷','📸','📹','🎥','📞','☎️','📟','📠','📺','📻','⏰','⌛','⏳','🔋','🔌','💡','🔦','🕯️','🧯','💸','💵','💰','💳','💎','⚖️','🔧','🔨','🛠️','🔩','⚙️','🧰','🧲','🔫','💣','🧨','🔪','🗡️','⚔️','🛡️','🔒','🔓','🔑','🗝️','🚪','🛋️','🛏️','🖼️','🛍️','🎁','🎈','🎏','🎀','🎊','🎉','🏮','✉️','📩','📧','📦','📮','📝','📄','📊','📈','📉','📅','📆','📋','📁','📂','📰','📓','📔','📒','📕','📗','📘','📙','📚','📖','🔖','🔗','📎','📐','📏','📌','📍','✂️','🖊️','✒️','🖌️','✏️','🔍','🔎'] },
    { key: 'symboles', icon: 'fa-heart', name: 'Symboles', list: ['✅','❌','❎','➕','➖','➗','✖️','‼️','⁉️','❓','❔','❕','❗','〰️','🔥','⭐','🌟','✨','⚡','💥','💫','☮️','✝️','☪️','🕉️','☸️','✡️','☯️','🛐','⛎','♈','♉','♊','♋','♌','♍','♎','♏','♐','♑','♒','♓','🆔','☢️','☣️','⚠️','🚸','🔱','⚜️','🔰','♻️','✅','🚫','🔞','📵','🚭','🅿️','♿','🈳','🚹','🚺','🚻','🎦','📶','🔣','ℹ️','🆗','🆙','🆒','🆕','🆓','0️⃣','1️⃣','2️⃣','3️⃣','4️⃣','5️⃣','6️⃣','7️⃣','8️⃣','9️⃣','🔟','▶️','⏸️','⏹️','⏭️','⏮️','⏩','⏪','◀️','🔼','🔽','➡️','⬅️','⬆️','⬇️','🔀','🔁','🔂','🔄','🎵','🎶','✔️','☑️','🔘','⚪','⚫','🔴','🔵','🟢','🟡','🟠','🟣','🟤','🔺','🔻','🔶','🔷','🟥','🟧','🟨','🟩','🟦','🟪','⬛','⬜','🔔','🔕','📣','📢'] }
  ];
  var CSS = '' +
    '.emj-pop{position:fixed;z-index:200;width:328px;max-width:94vw;background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:0 16px 44px rgba(0,0,0,.5);overflow:hidden;display:flex;flex-direction:column;font-family:var(--font);}' +
    '.emj-tabs{display:flex;gap:2px;padding:7px 8px;border-bottom:1px solid var(--border-soft);overflow-x:auto;scrollbar-width:none;}' +
    '.emj-tabs::-webkit-scrollbar{display:none;}' +
    '.emj-tab{flex:0 0 auto;width:30px;height:30px;border:none;background:none;color:var(--muted-2);border-radius:8px;cursor:pointer;font-size:.92em;}' +
    '.emj-tab:hover{background:var(--surface-hover);color:var(--text);}' +
    '.emj-tab.on{background:rgba(var(--accent-rgb),.16);color:var(--accent-2,#818cf8);}' +
    '.emj-grid{padding:8px;overflow-y:auto;max-height:230px;display:grid;grid-template-columns:repeat(8,1fr);gap:2px;}' +
    '.emj-cell{border:none;background:none;cursor:pointer;font-size:1.32em;line-height:1;padding:5px 0;border-radius:8px;}' +
    '.emj-cell:hover{background:var(--surface-hover);transform:scale(1.15);}' +
    '.emj-sec{grid-column:1/-1;font-size:.68em;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--muted-2);padding:6px 4px 2px;}' +
    '.emj-empty{grid-column:1/-1;text-align:center;color:var(--muted-2);font-size:.82em;padding:20px;}';
  var styled = false;
  function injectCSS() { if (styled) return; styled = true; var st = document.createElement('style'); st.textContent = CSS; document.head.appendChild(st); }
  function recents() { try { return (localStorage.getItem('mdtEmojiRecent') || '').split('|').filter(Boolean); } catch (e) { return []; } }
  function pushRecent(em) { try { var r = recents().filter(function (x) { return x !== em; }); r.unshift(em); localStorage.setItem('mdtEmojiRecent', r.slice(0, 24).join('|')); } catch (e) {} }

  var pop = null, onPickCb = null, curCat = 'smileys';
  function close() { if (pop) { pop.remove(); pop = null; document.removeEventListener('mousedown', onDoc, true); document.removeEventListener('keydown', onKey, true); } }
  function onDoc(e) { if (pop && !pop.contains(e.target)) close(); }
  function onKey(e) { if (e.key === 'Escape') close(); }
  function esc(s){ return String(s); }

  function renderGrid() {
    var grid = pop.querySelector('.emj-grid');
    var cat = null; for (var i = 0; i < CATS.length; i++) if (CATS[i].key === curCat) cat = CATS[i];
    if (!cat) return;
    var list = cat.key === 'recent' ? recents() : cat.list;
    if (!list.length) { grid.innerHTML = '<div class="emj-empty">Aucun émoji récent</div>'; return; }
    grid.innerHTML = list.map(function (em) { return '<button type="button" class="emj-cell" data-e="' + esc(em) + '">' + em + '</button>'; }).join('');
    Array.prototype.forEach.call(grid.querySelectorAll('.emj-cell'), function (b) {
      b.onclick = function () { var em = b.getAttribute('data-e'); pushRecent(em); if (onPickCb) onPickCb(em); };
    });
    grid.scrollTop = 0;
  }
  function open(anchor, onPick) {
    injectCSS(); close();
    onPickCb = onPick;
    pop = document.createElement('div'); pop.className = 'emj-pop';
    pop.innerHTML = '<div class="emj-tabs">' + CATS.map(function (c) { return '<button type="button" class="emj-tab' + (c.key === curCat ? ' on' : '') + '" data-c="' + c.key + '" title="' + c.name + '"><i class="fa-solid ' + c.icon + '"></i></button>'; }).join('') + '</div><div class="emj-grid"></div>';
    document.body.appendChild(pop);
    Array.prototype.forEach.call(pop.querySelectorAll('.emj-tab'), function (t) {
      t.onclick = function () { curCat = t.getAttribute('data-c'); Array.prototype.forEach.call(pop.querySelectorAll('.emj-tab'), function (x) { x.classList.toggle('on', x === t); }); renderGrid(); };
    });
    if (curCat === 'recent' && !recents().length) { curCat = 'smileys'; }
    renderGrid();
    var r = anchor.getBoundingClientRect(), pw = 328, ph = pop.offsetHeight || 300;
    var left = Math.min(Math.max(8, r.right - pw), window.innerWidth - pw - 8);
    var top = r.top - ph - 8; if (top < 8) top = Math.min(r.bottom + 8, window.innerHeight - ph - 8);
    pop.style.left = left + 'px'; pop.style.top = top + 'px';
    setTimeout(function () { document.addEventListener('mousedown', onDoc, true); document.addEventListener('keydown', onKey, true); }, 0);
  }
  function insertAtCaret(el, text) {
    if (!el) return;
    el.focus();
    var start = el.selectionStart, end = el.selectionEnd;
    if (start == null) { el.value += text; }
    else { el.value = el.value.slice(0, start) + text + el.value.slice(end); var p = start + text.length; el.selectionStart = el.selectionEnd = p; }
    try { el.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
  }
  function attach(btn, inputOrGetter) {
    if (!btn) return;
    btn.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      if (pop) { close(); return; }
      var input = typeof inputOrGetter === 'function' ? inputOrGetter() : inputOrGetter;
      open(btn, function (em) { insertAtCaret(input, em); });
    });
  }
  window.MDT_EMOJI = { open: open, attach: attach, insertAtCaret: insertAtCaret, close: close };
})();
