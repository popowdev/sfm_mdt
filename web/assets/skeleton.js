(function () {
  if (window.MDT_SKEL) return;

  function bar(w, h, extra) {
    return '<span class="sk" style="width:' + w + ';height:' + h + 'px' + (extra || '') + '"></span>';
  }
  function pseudo(i, min, max) {
    var v = ((i * 2654435761) % 100 + 100) % 100;
    return Math.round(min + (v / 100) * (max - min));
  }

  function build(shape, rows, cols) {
    var out = '', i, c;
    if (shape === 'table-rows') {
      for (i = 0; i < rows; i++) {
        var tds = '';
        for (c = 0; c < cols; c++) {
          var w = c === 0 ? pseudo(i * 7 + 1, 55, 80) : pseudo(i * 7 + c, 30, 70);
          tds += '<td class="sk-cell">' + bar(w + '%', 12) + '</td>';
        }
        out += '<tr class="sk-item">' + tds + '</tr>';
      }
      return out;
    }
    if (shape === 'cards') {
      for (i = 0; i < rows; i++) {
        out += '<div class="sk-item sk-card">' + bar(pseudo(i + 1, 45, 70) + '%', 14) +
          bar('92%', 10) + bar(pseudo(i + 3, 60, 88) + '%', 10) + '</div>';
      }
      return out;
    }
    if (shape === 'detail-panel') {
      out = '<div class="sk-item" style="display:flex;flex-direction:column;gap:13px;padding:6px 2px">' +
        bar('42%', 18) + bar('100%', 11) + bar('100%', 11) + bar('78%', 11) +
        bar('60%', 11, ';margin-top:10px') + bar('100%', 11) + bar('88%', 11) + '</div>';
      return out;
    }
    if (shape === 'other') {
      out = '<div class="sk-item" style="display:flex;flex-direction:column;gap:12px;padding:6px 2px">';
      for (i = 0; i < rows; i++) out += bar(pseudo(i + 1, 55, 95) + '%', 12);
      return out + '</div>';
    }
    for (i = 0; i < rows; i++) {
      out += '<div class="sk-item sk-row">' + bar('34px', 34, ';border-radius:50%;flex:0 0 auto') +
        '<div class="sk-lines">' + bar(pseudo(i + 1, 40, 65) + '%', 12) +
        bar(pseudo(i + 4, 25, 45) + '%', 10) + '</div>' +
        bar('64px', 20, ';flex:0 0 auto;border-radius:999px') + '</div>';
    }
    return out;
  }

  function hasReal(el) {
    for (var i = 0; i < el.children.length; i++) {
      var ch = el.children[i];
      if (!(ch.classList && ch.classList.contains('sk-item'))) return true;
    }
    return false;
  }

  window.MDT_SKEL = {
    show: function (target, shape, opts) {
      var el = typeof target === 'string' ? document.querySelector(target) : target;
      if (!el) return;
      opts = opts || {};
      var rows = opts.rows || 6;
      var cols = opts.cols || 4;
      shape = shape || 'list-rows';

      if (el._skObs) { try { el._skObs.disconnect(); } catch (e) {} el._skObs = null; }
      if (el._skTo) { clearTimeout(el._skTo); el._skTo = null; }

      el.classList.add('sk-host');
      el.classList.remove('sk-reveal-fade', 'sk-reveal-slide');
      el.innerHTML = build(shape, rows, cols);

      if (!window.MutationObserver) return;
      var mode = (shape === 'detail-panel' || shape === 'other') ? 'fade' : 'slide';
      var done = function () {
        if (el._skObs) { try { el._skObs.disconnect(); } catch (e) {} el._skObs = null; }
        if (el._skTo) { clearTimeout(el._skTo); el._skTo = null; }
        el.classList.remove('sk-host');
      };
      var obs = new MutationObserver(function () {
        if (!hasReal(el)) return;
        done();
        el.classList.add('sk-reveal-' + mode);
        setTimeout(function () { el.classList.remove('sk-reveal-' + mode); }, 800);
      });
      obs.observe(el, { childList: true });
      el._skObs = obs;
      el._skTo = setTimeout(done, 12000);
    },
    clear: function (target) {
      var el = typeof target === 'string' ? document.querySelector(target) : target;
      if (!el) return;
      if (el._skObs) { try { el._skObs.disconnect(); } catch (e) {} el._skObs = null; }
      if (el._skTo) { clearTimeout(el._skTo); el._skTo = null; }
      el.classList.remove('sk-host');
    }
  };
})();
