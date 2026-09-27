(function () {
  if (window.TacMap) return;

  var TILE_SIZE = 6144, TILE_MZ = 5, EXTRA_ZOOM = 3;
  var HOVER_GRACE = 350, SIMPLIFY_PX = 1.2, MIN_DIST_PX = 2.2;

  function uid() {
    return 's' + Date.now().toString(36) + Math.floor(Math.random() * 1e6).toString(36);
  }

  function esc(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function rdp(pts, eps) {
    if (pts.length < 3) return pts.slice();
    var keep = new Array(pts.length);
    for (var i = 0; i < pts.length; i++) keep[i] = false;
    keep[0] = keep[pts.length - 1] = true;
    var stack = [[0, pts.length - 1]];
    while (stack.length) {
      var seg = stack.pop(), a = seg[0], b = seg[1];
      var ax = pts[a].x, ay = pts[a].y, bx = pts[b].x, by = pts[b].y;
      var dx = bx - ax, dy = by - ay, den = Math.sqrt(dx * dx + dy * dy), best = -1, bi = -1;
      for (var k = a + 1; k < b; k++) {
        var d;
        if (den === 0) {
          d = Math.sqrt(Math.pow(pts[k].x - ax, 2) + Math.pow(pts[k].y - ay, 2));
        } else {
          d = Math.abs(dy * pts[k].x - dx * pts[k].y + bx * ay - by * ax) / den;
        }
        if (d > best) { best = d; bi = k; }
      }
      if (best > eps && bi > 0) { keep[bi] = true; stack.push([a, bi]); stack.push([bi, b]); }
    }
    var out = [];
    for (var j = 0; j < pts.length; j++) if (keep[j]) out.push(pts[j]);
    return out;
  }

  function TacMap(el, opts) {
    opts = opts || {};
    this.el = el;
    this.api = opts.api || '/carte/tacmap_api.php';
    this.onStatus = opts.onStatus || function () {};
    this.onShapes = opts.onShapes || function () {};
    this.onImage = opts.onImage || function () {};
    this.onBoard = opts.onBoard || function () {};
    this.onAttach = opts.onAttach || function () {};
    this.onHistory = opts.onHistory || function () {};
    this._undo = [];
    this._redo = [];
    this.canWrite = true;
    this.scope = 'comm';
    this.boardId = 'tb_common';
    this.rev = 0;
    this.me = null;
    this.isSup = false;
    this.tool = 'ink';
    this.color = '#F87171';
    this.width = 5;
    this.alpha = 100;
    this.shapes = {};
    this.pending = [];
    this.spaceHeld = false;
    this.drawing = null;
    this.hoverCid = null;
    this.hoverTimer = null;
    this._build();
  }

  TacMap.prototype._build = function () {
    var self = this;
    this.map = L.map(this.el, {
      crs: L.CRS.Simple,
      minZoom: 0,
      maxZoom: TILE_MZ + EXTRA_ZOOM,
      zoomControl: true,
      attributionControl: false,
      preferCanvas: true,
      renderer: L.canvas({ tolerance: 8 }),
      doubleClickZoom: false,
      boxZoom: false,
      zoomSnap: 0.5,
      wheelPxPerZoomLevel: 90
    });
    this.layers = L.layerGroup().addTo(this.map);
    this.overlay = L.layerGroup().addTo(this.map);
    this.overlayItems = {};
    this.setBackground('tiles', '');

    this.badge = L.DomUtil.create('div', 'tac-badge', this.el.parentNode || this.el);
    this.badge.style.display = 'none';
    L.DomEvent.disableClickPropagation(this.badge);
    L.DomEvent.on(this.badge, 'click', function (e) {
      var btn = e.target.closest ? e.target.closest('[data-a]') : null;
      L.DomEvent.stop(e);
      if (!btn || !self.hoverCid) return;
      var cid = self.hoverCid;
      if (btn.getAttribute('data-a') === 'del') { self.remove([cid]); self._hideBadge(true); }
      else { self._hideBadge(true); self.onAttach(cid, self.shapes[cid] ? self.shapes[cid].data : null); }
    });
    L.DomEvent.on(this.badge, 'mouseenter', function () { clearTimeout(self.hoverTimer); });
    L.DomEvent.on(this.badge, 'mouseleave', function () { self._hideBadge(); });

    this.map.on('zoomend', function () { self._refreshArrows(); });

    this._bindDrawing();
    this._bindKeys();
  };

  TacMap.prototype.setBackground = function (kind, url) {
    var self = this;
    if (this.bgLayer) { this.map.removeLayer(this.bgLayer); this.bgLayer = null; }

    var apply = function (w, h, mz) {
      self.W = w; self.H = h; self.MZ = mz;
      var bounds = L.latLngBounds(self.map.unproject([0, h], mz), self.map.unproject([w, 0], mz));
      self.bounds = bounds;
      if (kind === 'image' && url) {
        self.bgLayer = L.imageOverlay(url, bounds, { interactive: false });
      } else {
        self.bgLayer = L.tileLayer('/tiles/gta/{z}/{x}/{y}.png', {
          tileSize: 256, minZoom: 0, maxNativeZoom: mz, maxZoom: mz + EXTRA_ZOOM,
          noWrap: true, bounds: bounds
        });
      }
      self.bgLayer.addTo(self.map);
      self.bgLayer.bringToBack();
      self.map.setMaxBounds(bounds.pad(0.25));
      self.map.fitBounds(bounds);
      self._reproject();
    };

    if (kind === 'image' && url) {
      var img = new Image();
      img.onload = function () {
        var w = img.naturalWidth || 2048, h = img.naturalHeight || 2048;
        apply(w, h, Math.max(1, Math.ceil(Math.log(Math.max(w, h) / 256) / Math.LN2)));
      };
      img.onerror = function () { apply(TILE_SIZE, TILE_SIZE, TILE_MZ); };
      img.src = url;
    } else {
      apply(TILE_SIZE, TILE_SIZE, TILE_MZ);
    }
  };

  TacMap.prototype.setOverlay = function (list, onClick) {
    this.overlay.clearLayers();
    this.overlayItems = {};
    if (!list || !list.length) return;
    var self = this;
    for (var i = 0; i < list.length; i++) {
      var it = list[i];
      if (!it.pts || it.pts.length < 3) continue;
      var lls = [];
      for (var k = 0; k < it.pts.length; k++) lls.push(this.n2ll(it.pts[k]));
      var poly = L.polygon(lls, {
        color: it.color, weight: 2, opacity: 0.95, fill: true,
        fillColor: it.color, fillOpacity: it.dim ? 0.16 : 0.42,
        interactive: true, className: 'tac-zone'
      });
      poly.bindTooltip(it.label || '', { direction: 'center', className: 'tac-ztip', permanent: false });
      (function (id) {
        poly.on('click', function (e) { L.DomEvent.stop(e); if (onClick) onClick(id); });
      })(it.id);
      this.overlay.addLayer(poly);
      this.overlayItems[it.id] = poly;
    }
  };

  TacMap.prototype.clearOverlay = function () {
    this.overlay.clearLayers();
    this.overlayItems = {};
  };

  TacMap.prototype.focusOverlay = function (id) {
    var p = this.overlayItems[id];
    if (!p) return false;
    try { this.map.fitBounds(p.getBounds().pad(0.6)); } catch (e) {}
    return true;
  };

  TacMap.prototype.captureZone = function (cb) {
    this._capture = cb;
    this.setTool('zone');
  };

  TacMap.prototype._reproject = function () {
    var cids = Object.keys(this.shapes);
    for (var i = 0; i < cids.length; i++) {
      var rec = this.shapes[cids[i]];
      this.layers.removeLayer(rec.layer);
      delete this.shapes[cids[i]];
      this._attach(cids[i], rec.data, this._makeLayer(rec.data));
    }
    if (this.drawing) {
      this.layers.removeLayer(this.drawing.layer);
      this.drawing.layer = this._makeLayer(this.drawing.s);
      this.layers.addLayer(this.drawing.layer);
    }
    if (this.badge) this._hideBadge(true);
  };

  TacMap.prototype._refreshArrows = function () {
    var cids = Object.keys(this.shapes), rebuild = [];
    for (var i = 0; i < cids.length; i++) {
      var rec = this.shapes[cids[i]];
      if (!rec) continue;
      var k = rec.data.kind;
      if (k === 'arrow' || k === 'text' || k === 'point') { rebuild.push(cids[i]); continue; }
      var hit = this._hit(rec.layer);
      if (hit && hit.setStyle) hit.setStyle({ weight: this._weightAt(rec.data.width) });
    }
    for (var j = 0; j < rebuild.length; j++) {
      var r = this.shapes[rebuild[j]];
      this.layers.removeLayer(r.layer);
      delete this.shapes[rebuild[j]];
      this._attach(rebuild[j], r.data, this._makeLayer(r.data));
    }
  };

  TacMap.prototype.n2ll = function (p) {
    return this.map.unproject([p[0] * this.W, p[1] * this.H], this.MZ);
  };

  TacMap.prototype.ll2n = function (ll) {
    var p = this.map.project(ll, this.MZ);
    return [p.x / this.W, p.y / this.H];
  };

  TacMap.prototype.invalidate = function () {
    if (this.map) this.map.invalidateSize();
  };

  TacMap.prototype.setTool = function (t) {
    this.tool = t;
    this.el.classList.toggle('tac-drawing', t !== 'pan' && t !== 'move');
    this.el.classList.toggle('tac-movetool', t === 'move');
  };

  TacMap.prototype._scale = function () {
    if (!this.map) return 1;
    var ref = (this.MZ || 5) - 1;
    return Math.pow(2, (this.map.getZoom() - ref) * 0.75);
  };

  TacMap.prototype._weightAt = function (w) {
    return Math.max(1, Math.min(64, (parseInt(w, 10) || 5) * this._scale()));
  };

  TacMap.prototype._styleOf = function (s) {
    var op = Math.max(0.05, Math.min(1, (s.alpha || 100) / 100));
    var base = { color: s.color, weight: this._weightAt(s.width), opacity: op, lineCap: 'round', lineJoin: 'round', interactive: true };
    if (s.kind === 'zone') { base.fill = true; base.fillColor = s.color; base.fillOpacity = op * 0.45; base.opacity = Math.min(1, op + 0.35); }
    else if (s.kind === 'circle') { base.fill = true; base.fillColor = s.color; base.fillOpacity = op * 0.25; base.opacity = Math.min(1, op + 0.35); }
    else base.fill = false;
    return base;
  };

  TacMap.prototype._arrowHead = function (pts, shape) {
    var n = pts.length;
    if (n < 2) return null;
    var tip = pts[n - 1], prev = pts[n - 2];
    var dx = tip[0] - prev[0], dy = tip[1] - prev[1];
    var len = Math.sqrt(dx * dx + dy * dy);
    if (len < 1e-9) return null;
    var total = 0;
    for (var i = 1; i < n; i++) total += Math.sqrt(Math.pow(pts[i][0] - pts[i - 1][0], 2) + Math.pow(pts[i][1] - pts[i - 1][1], 2));
    var W = this.W || 1, H = this.H || 1, D = Math.max(W, H);
    var mx = dx * W, my = dy * H, ml = Math.sqrt(mx * mx + my * my);
    if (ml < 1e-9) return null;
    var totalM = 0;
    for (var j = 1; j < n; j++) {
      var sx = (pts[j][0] - pts[j - 1][0]) * W, sy = (pts[j][1] - pts[j - 1][1]) * H;
      totalM += Math.sqrt(sx * sx + sy * sy);
    }
    var z = this.map ? this.map.getZoom() : this.MZ;
    var perPx = Math.pow(2, this.MZ - z);
    var wpx = this._weightAt(shape && shape.width);
    var size = Math.max(9, wpx * 3.4) * perPx;
    size = Math.min(size, totalM * 0.45);
    var ux = mx / ml, uy = my / ml, a = 0.42;
    var ca = Math.cos(a), sa = Math.sin(a);
    var l = [-(ux * ca - uy * sa), -(ux * sa + uy * ca)];
    var r = [-(ux * ca + uy * sa), -(-ux * sa + uy * ca)];
    return [tip,
      [tip[0] + l[0] * size / W, tip[1] + l[1] * size / H],
      [tip[0] + r[0] * size / W, tip[1] + r[1] * size / H]];
  };

  TacMap.prototype._circlePoly = function (pts) {
    var c = pts[0], e = pts[1] || pts[0], W = this.W || 1, H = this.H || 1;
    var dx = (e[0] - c[0]) * W, dy = (e[1] - c[1]) * H;
    var r = Math.sqrt(dx * dx + dy * dy);
    var out = [];
    for (var i = 0; i <= 48; i++) {
      var t = (i / 48) * Math.PI * 2;
      out.push([c[0] + Math.cos(t) * r / W, c[1] + Math.sin(t) * r / H]);
    }
    return out;
  };

  TacMap.prototype._makeLayer = function (s) {
    var self = this, st = this._styleOf(s), pts = s.pts || [], lls = [];

    if (s.kind === 'text') {
      var ll = this.n2ll(pts[0] || [0.5, 0.5]);
      var fs = Math.max(8, Math.min(72, (9 + (parseInt(s.width, 10) || 5) * 1.6) * this._scale()));
      var top = Math.max(0.05, Math.min(1, (s.alpha || 100) / 100));
      var m = L.marker(ll, {
        interactive: true,
        icon: L.divIcon({
          className: 'tac-text-icon',
          html: '<span style="color:' + esc(s.color) + ';font-size:' + fs.toFixed(1)
              + 'px;opacity:' + top.toFixed(2) + '">' + esc(s.label || '') + '</span>',
          iconSize: null
        })
      });
      return m;
    }

    if (s.kind === 'point') {
      var p0 = this.n2ll(pts[0] || [0.5, 0.5]);
      var g = L.layerGroup();
      var cm = L.circleMarker(p0, {
        radius: Math.max(3, Math.min(40, (s.width + 4) * this._scale())), color: '#0f172a', weight: 2,
        fillColor: s.color, fillOpacity: 0.95, interactive: true
      });
      g.addLayer(cm);
      if (s.label) {
        g.addLayer(L.marker(p0, {
          interactive: false,
          icon: L.divIcon({ className: 'tac-point-label', html: '<span>' + esc(s.label) + '</span>', iconSize: null })
        }));
      }
      g.__hit = cm;
      return g;
    }

    var src = pts;
    if (s.kind === 'circle') src = this._circlePoly(pts);
    for (var i = 0; i < src.length; i++) lls.push(this.n2ll(src[i]));

    if (s.kind === 'zone' || s.kind === 'circle') return L.polygon(lls, st);

    if (s.kind === 'arrow') {
      var grp = L.layerGroup();
      var head = this._arrowHead(pts, s);
      var shaft = lls;
      if (head) {
        var tipN = pts[pts.length - 1], prevN = pts[pts.length - 2];
        var bx = (head[1][0] + head[2][0]) / 2, by = (head[1][1] + head[2][1]) / 2;
        var segX = tipN[0] - prevN[0], segY = tipN[1] - prevN[1];
        var backX = bx - tipN[0], backY = by - tipN[1];
        var segLen = Math.sqrt(segX * segX + segY * segY);
        var backLen = Math.sqrt(backX * backX + backY * backY);
        if (segLen > backLen) {
          shaft = lls.slice(0, lls.length - 1);
          shaft.push(this.n2ll([tipN[0] + backX * 0.9, tipN[1] + backY * 0.9]));
        }
      }
      var line = L.polyline(shaft, st);
      grp.addLayer(line);
      if (head) {
        var hl = [];
        for (var j = 0; j < head.length; j++) hl.push(this.n2ll(head[j]));
        grp.addLayer(L.polygon(hl, {
          color: s.color, weight: 1, opacity: st.opacity, fill: true,
          fillColor: s.color, fillOpacity: st.opacity,
          lineJoin: 'miter', interactive: false
        }));
      }
      grp.__hit = line;
      return grp;
    }

    return L.polyline(lls, st);
  };

  TacMap.prototype._hit = function (layer) { return layer.__hit || layer; };

  TacMap.prototype._attach = function (cid, s, layer) {
    var self = this;
    var hit = this._hit(layer);
    hit.on('mousedown', function (e) {
      if (self.tool !== 'move') return;
      L.DomEvent.stop(e);
      self._startMove(cid, e);
    });
    hit.on('mouseover', function (e) { self._hover(cid, e); });
    hit.on('mouseout', function () { self._hideBadge(); });
    hit.on('click', function (e) {
      L.DomEvent.stop(e);
      if (s.image) self.onImage(s);
    });
    hit.on('contextmenu', function (e) {
      L.DomEvent.stop(e);
      if (self._mayDelete(s)) self.remove([cid]);
    });
    this.layers.addLayer(layer);
    this.shapes[cid] = { data: s, layer: layer };
  };

  TacMap.prototype._startMove = function (cid, e) {
    var rec = this.shapes[cid];
    if (!rec || !this._mayDelete(rec.data)) {
      this.onStatus('err', 'Tracé d’un autre agent');
      return;
    }
    var self = this;
    this._hideBadge(true);
    this.map.dragging.disable();
    this.el.classList.add('tac-moving');

    var before = this._clone(rec.data);
    var start = this.ll2n(e.latlng);
    var orig = [];
    for (var i = 0; i < rec.data.pts.length; i++) orig.push(rec.data.pts[i].slice());

    var onMove = function (ev) {
      var cur = self.ll2n(ev.latlng);
      var dx = cur[0] - start[0], dy = cur[1] - start[1];
      var next = [];
      for (var k = 0; k < orig.length; k++) next.push([orig[k][0] + dx, orig[k][1] + dy]);
      rec.data.pts = next;
      self.layers.removeLayer(rec.layer);
      rec.layer = self._makeLayer(rec.data);
      self.layers.addLayer(rec.layer);
    };

    var onUp = function () {
      self.map.off('mousemove', onMove);
      self.map.off('mouseup', onUp);
      self.map.dragging.enable();
      self.el.classList.remove('tac-moving');
      self.layers.removeLayer(rec.layer);
      delete self.shapes[cid];
      self._attach(cid, rec.data, self._makeLayer(rec.data));
      self._push([rec.data], true);
      self._push2({ t: 'upd', before: before, after: self._clone(rec.data) });
    };

    this.map.on('mousemove', onMove);
    this.map.on('mouseup', onUp);
  };

  TacMap.prototype._mayDelete = function (s) {
    if (this.scope === 'priv') return true;
    if (this.isSup) return true;
    return !s.by_did || String(s.by_did) === String(this.me);
  };

  TacMap.prototype._hover = function (cid, e) {
    if (this.drawing) return;
    var rec = this.shapes[cid];
    if (!rec) return;
    clearTimeout(this.hoverTimer);
    if (this.hoverCid && this.hoverCid !== cid) this._unhighlight(this.hoverCid);
    this.hoverCid = cid;
    var hit = this._hit(rec.layer);
    if (hit.setStyle && rec.data.kind !== 'point') {
      hit.setStyle({ weight: (rec.data.width || 4) + 4, opacity: 1 });
    }
    var pt = this.map.latLngToContainerPoint(e.latlng);
    var bw = 108, bh = 36;
    this.badge.style.left = Math.max(2, Math.min(this.el.clientWidth - bw, pt.x + 12)) + 'px';
    this.badge.style.top = Math.max(2, Math.min(this.el.clientHeight - bh, pt.y - 12)) + 'px';
    var mine = this._mayDelete(rec.data);
    var who = rec.data.mat ? String(rec.data.mat) : '';
    var whoTitle = (rec.data.by ? String(rec.data.by) : 'Auteur inconnu') + (who ? ' — matricule ' + who : '');
    var html = who
      ? '<span class="tac-badge-who" title="' + esc(whoTitle) + '">' + esc(who) + '</span>'
      : '';
    if (mine) {
      html += '<button type="button" data-a="img" title="' + (rec.data.image ? 'Changer l’image' : 'Attacher une image') + '">'
           +  '<i class="fa-solid fa-' + (rec.data.image ? 'image' : 'camera') + '"></i></button>';
      html += '<button type="button" data-a="del" title="Supprimer ce tracé"><i class="fa-solid fa-trash"></i></button>';
    } else {
      html += '<span class="tac-badge-lock" title="' + esc(whoTitle) + '">'
           +  '<i class="fa-solid fa-lock"></i></span>';
    }
    this.badge.className = 'tac-badge';
    this.badge.innerHTML = html;
    this.badge.style.display = 'flex';
  };

  TacMap.prototype._unhighlight = function (cid) {
    var rec = this.shapes[cid];
    if (!rec) return;
    var hit = this._hit(rec.layer);
    if (hit.setStyle && rec.data.kind !== 'point') hit.setStyle(this._styleOf(rec.data));
  };

  TacMap.prototype._hideBadge = function (now) {
    var self = this;
    clearTimeout(this.hoverTimer);
    var kill = function () {
      if (self.hoverCid) self._unhighlight(self.hoverCid);
      self.hoverCid = null;
      self.badge.style.display = 'none';
    };
    if (now) kill(); else this.hoverTimer = setTimeout(kill, HOVER_GRACE);
  };

  TacMap.prototype._bindKeys = function () {
    var self = this;
    this._onKeyDown = function (e) {
      if (e.code === 'Space' && !self.spaceHeld) {
        var t = e.target && e.target.tagName;
        if (t === 'INPUT' || t === 'TEXTAREA') return;
        self.spaceHeld = true;
        self.el.classList.add('tac-panning');
        e.preventDefault();
      }
      if (e.key === 'Escape' && self.drawing) self._abort();
      if ((e.ctrlKey || e.metaKey) && !e.altKey) {
        var t2 = e.target && e.target.tagName;
        if (t2 === 'INPUT' || t2 === 'TEXTAREA') return;
        if (e.key === 'z' || e.key === 'Z') { e.preventDefault(); if (e.shiftKey) self.redo(); else self.undo(); }
        else if (e.key === 'y' || e.key === 'Y') { e.preventDefault(); self.redo(); }
      }
    };
    this._onKeyUp = function (e) {
      if (e.code === 'Space') { self.spaceHeld = false; self.el.classList.remove('tac-panning'); }
    };
    this._onBlur = function () {
      if (self.spaceHeld) { self.spaceHeld = false; self.el.classList.remove('tac-panning'); }
    };
    document.addEventListener('keydown', this._onKeyDown);
    document.addEventListener('keyup', this._onKeyUp);
    window.addEventListener('blur', this._onBlur);
  };

  TacMap.prototype._bindDrawing = function () {
    var self = this, cont = this.map.getContainer();

    cont.addEventListener('contextmenu', function (e) { e.preventDefault(); });

    cont.addEventListener('pointerdown', function (e) {
      if (e.button !== 0) return;
      if (self.drawing) return;
      if (self.tool === 'pan' || self.tool === 'move' || self.spaceHeld || !self.canWrite) return;
      if (e.target.closest && e.target.closest('.leaflet-control, .tac-badge')) return;

      var ll = self.map.mouseEventToLatLng(e);
      var n = self.ll2n(ll);

      if (self.tool === 'text' || self.tool === 'point') {
        self._placeMarker(n);
        return;
      }

      self.map.dragging.disable();
      try { cont.setPointerCapture(e.pointerId); } catch (err) {}
      self._hideBadge(true);

      var s = {
        cid: uid(), kind: self.tool, color: self.color,
        width: self.width, alpha: self.alpha, pts: [n], label: '', image: '',
        by: null, by_did: self.me
      };
      var layer = self._makeLayer(s);
      self.layers.addLayer(layer);
      self.drawing = { s: s, layer: layer, zoom: self.map.getZoom(), last: n, raw: [n] };
      e.preventDefault();
    });

    cont.addEventListener('pointermove', function (e) {
      var d = self.drawing;
      if (!d) return;
      var evs = (typeof e.getCoalescedEvents === 'function') ? e.getCoalescedEvents() : [e];
      if (!evs || !evs.length) evs = [e];
      var moved = false;
      for (var i = 0; i < evs.length; i++) {
        var n = self.ll2n(self.map.mouseEventToLatLng(evs[i]));
        if (d.s.kind === 'line' || d.s.kind === 'arrow' || d.s.kind === 'circle') {
          d.s.pts[1] = n; moved = true;
        } else {
          var pa = self.map.latLngToContainerPoint(self.n2ll(d.last));
          var pb = self.map.latLngToContainerPoint(self.n2ll(n));
          if (Math.abs(pa.x - pb.x) + Math.abs(pa.y - pb.y) < MIN_DIST_PX) continue;
          d.s.pts.push(n); d.last = n; moved = true;
        }
      }
      if (moved) self._repaintDraft();
    });

    var finish = function (e) {
      if (!self.drawing) return;
      try { cont.releasePointerCapture(e.pointerId); } catch (err) {}
      self._commit();
    };
    cont.addEventListener('pointerup', finish);
    cont.addEventListener('pointercancel', finish);
  };

  TacMap.prototype._repaintDraft = function () {
    var d = this.drawing;
    if (!d) return;
    if (d.raf) return;
    var self = this;
    d.raf = requestAnimationFrame(function () {
      d.raf = null;
      if (!self.drawing) return;
      self.layers.removeLayer(d.layer);
      d.layer = self._makeLayer(d.s);
      self.layers.addLayer(d.layer);
    });
  };

  TacMap.prototype._abort = function () {
    var d = this.drawing;
    if (!d) return;
    if (d.raf) cancelAnimationFrame(d.raf);
    this.layers.removeLayer(d.layer);
    this.drawing = null;
    this.map.dragging.enable();
  };

  TacMap.prototype._commit = function () {
    var d = this.drawing;
    if (!d) return;
    if (d.raf) cancelAnimationFrame(d.raf);
    this.drawing = null;
    this.map.dragging.enable();

    var s = d.s;
    if (s.kind === 'ink' || s.kind === 'zone') {
      if (s.pts.length > 2) {
        var self = this, proj = [];
        for (var i = 0; i < s.pts.length; i++) {
          proj.push({ x: s.pts[i][0] * this.W, y: s.pts[i][1] * this.H, i: i });
        }
        var eps = SIMPLIFY_PX * Math.pow(2, this.MZ - d.zoom);
        var kept = rdp(proj, eps), out = [];
        for (var k = 0; k < kept.length; k++) out.push(s.pts[kept[k].i]);
        s.pts = out;
      }
    }

    var minPts = (s.kind === 'ink') ? 2 : (s.kind === 'zone' ? 3 : 2);
    if (s.pts.length < minPts) {
      this.layers.removeLayer(d.layer);
      return;
    }

    this.layers.removeLayer(d.layer);

    if (this._capture) {
      var cb = this._capture;
      this._capture = null;
      cb(s.pts);
      return;
    }

    var layer = this._makeLayer(s);
    this._attach(s.cid, s, layer);
    this._push([s]);
    this._push2({ t: 'add', after: this._clone(s) });
    this.onShapes(this.count());
  };

  TacMap.prototype._placeMarker = function (n) {
    var self = this;
    var mk = function (label, image) {
      var s = {
        cid: uid(), kind: self.tool, color: self.color, width: self.width,
        alpha: 100, pts: [n], label: label || '', image: image || '', by: null, by_did: self.me
      };
      var layer = self._makeLayer(s);
      self._attach(s.cid, s, layer);
      self._push([s]);
      self._push2({ t: 'add', after: self._clone(s) });
      self.onShapes(self.count());
    };
    if (this.tool === 'text') {
      if (typeof window.MDT_PROMPT !== 'function') return;
      window.MDT_PROMPT('Texte à écrire sur la carte', '', { title: 'Texte', icon: 'fa-font' })
        .then(function (v) { if (v !== null && String(v).trim()) mk(String(v).trim(), ''); });
      return;
    }
    this.onBoard('point', n, mk);
  };

  TacMap.prototype.setImage = function (cid, url) {
    var rec = this.shapes[cid];
    if (!rec) return;
    var beforeImg = this._clone(rec.data);
    rec.data.image = url || '';
    this.layers.removeLayer(rec.layer);
    delete this.shapes[cid];
    var layer = this._makeLayer(rec.data);
    this._attach(cid, rec.data, layer);
    this._push([rec.data], true);
    this._push2({ t: 'upd', before: beforeImg, after: this._clone(rec.data) });
  };

  TacMap.prototype._push2 = function (entry) {
    this._undo.push(entry);
    if (this._undo.length > 60) this._undo.shift();
    this._redo = [];
    this.onHistory(this._undo.length, 0);
  };

  TacMap.prototype._clone = function (d) { return JSON.parse(JSON.stringify(d)); };

  TacMap.prototype._recreate = function (data) {
    var d = this._clone(data);
    this._attach(d.cid, d, this._makeLayer(d));
    this._push([d]);
    this.onShapes(this.count());
  };

  TacMap.prototype._erase = function (cid) {
    var rec = this.shapes[cid];
    if (!rec) return null;
    var data = this._clone(rec.data);
    this._drop(cid);
    this.onShapes(this.count());
    fetch(this.api + '?action=del', {
      method: 'POST', headers: this._headers({ 'Content-Type': 'application/json' }),
      body: JSON.stringify({ scope: this.scope, board: this.boardId, cids: [cid] })
    }).then(function (r) { return r.json().catch(function () { return null; }); }).catch(function () {});
    return data;
  };

  TacMap.prototype.undo = function () {
    var e = this._undo.pop();
    if (!e) return;
    if (e.t === 'add') { this._erase(e.after.cid); this._redo.push(e); }
    else if (e.t === 'del') { for (var i = 0; i < e.before.length; i++) this._recreate(e.before[i]); this._redo.push(e); }
    else if (e.t === 'upd') { this._replace(e.before); this._redo.push(e); }
    this.onHistory(this._undo.length, this._redo.length);
  };

  TacMap.prototype.redo = function () {
    var e = this._redo.pop();
    if (!e) return;
    if (e.t === 'add') { this._recreate(e.after); }
    else if (e.t === 'del') { for (var i = 0; i < e.before.length; i++) this._erase(e.before[i].cid); }
    else if (e.t === 'upd') { this._replace(e.after); }
    this._undo.push(e);
    this.onHistory(this._undo.length, this._redo.length);
  };

  TacMap.prototype._replace = function (data) {
    var rec = this.shapes[data.cid];
    if (rec) { this.layers.removeLayer(rec.layer); delete this.shapes[data.cid]; }
    var d = this._clone(data);
    this._attach(d.cid, d, this._makeLayer(d));
    this._push([d], true);
  };

  TacMap.prototype.count = function () {
    var n = 0;
    for (var k in this.shapes) if (this.shapes.hasOwnProperty(k)) n++;
    return n;
  };

  TacMap.prototype._headers = function (extra) {
    var h = extra || {};
    try { var t = localStorage.getItem('mdt_auth_token') || ''; if (t) h['Authorization'] = 'Bearer ' + t; } catch (e) {}
    return h;
  };

  TacMap.prototype._push = function (shapes, isUpdate) {
    var self = this;
    var body = { scope: this.scope, board: this.boardId, shapes: shapes };
    fetch(this.api + '?action=add', {
      method: 'POST', headers: this._headers({ 'Content-Type': 'application/json' }),
      body: JSON.stringify(body)
    }).then(function (r) { return r.json().catch(function () { return null; }); })
      .then(function (d) {
        if (d && d.success) { self.rev = Math.max(self.rev, d.rev); self.onStatus('ok'); }
        else {
          self.onStatus('err', (d && d.error) || 'Enregistrement refusé');
          self._rollback(shapes, isUpdate);
        }
      })
      .catch(function () {
        self.onStatus('err', 'Réseau indisponible : tracé non enregistré');
        self._rollback(shapes, isUpdate);
      });
  };

  TacMap.prototype._rollback = function (shapes, isUpdate) {
    if (isUpdate) { this.reload(); return; }
    for (var i = 0; i < shapes.length; i++) this._drop(shapes[i].cid);
    this.onShapes(this.count());
  };

  TacMap.prototype._drop = function (cid) {
    var rec = this.shapes[cid];
    if (!rec) return;
    this.layers.removeLayer(rec.layer);
    delete this.shapes[cid];
    if (this.hoverCid === cid) this._hideBadge(true);
  };

  TacMap.prototype.remove = function (cids) {
    var self = this, keep = {};
    for (var i = 0; i < cids.length; i++) {
      var rec = this.shapes[cids[i]];
      if (!rec) continue;
      if (!this._mayDelete(rec.data)) { self.onStatus('err', 'Tracé d’un autre agent'); continue; }
      keep[cids[i]] = rec;
      this._drop(cids[i]);
    }
    var list = Object.keys(keep);
    if (!list.length) return;
    var snap = [];
    for (var c0 in keep) if (keep.hasOwnProperty(c0)) snap.push(this._clone(keep[c0].data));
    this._push2({ t: 'del', before: snap });
    this.onShapes(this.count());
    fetch(this.api + '?action=del', {
      method: 'POST', headers: this._headers({ 'Content-Type': 'application/json' }),
      body: JSON.stringify({ scope: this.scope, board: this.boardId, cids: list })
    }).then(function (r) { return r.json().catch(function () { return null; }); })
      .then(function (d) {
        if (d && d.success) { self.rev = Math.max(self.rev, d.rev); return; }
        self.onStatus('err', (d && d.error) || 'Suppression refusée');
        for (var c in keep) if (keep.hasOwnProperty(c)) self._attach(c, keep[c].data, self._makeLayer(keep[c].data));
        self.onShapes(self.count());
      })
      .catch(function () {
        self.onStatus('err', 'Réseau indisponible');
        for (var c in keep) if (keep.hasOwnProperty(c)) self._attach(c, keep[c].data, self._makeLayer(keep[c].data));
        self.onShapes(self.count());
      });
  };

  TacMap.prototype.clearAll = function (mineOnly, cb) {
    var self = this;
    fetch(this.api + '?action=clear', {
      method: 'POST', headers: this._headers({ 'Content-Type': 'application/json' }),
      body: JSON.stringify({ scope: this.scope, board: this.boardId, mine_only: !!mineOnly })
    }).then(function (r) { return r.json().catch(function () { return null; }); })
      .then(function (d) {
        if (d && d.success) {
          self.rev = Math.max(self.rev, d.rev);
          self.reload();
          if (cb) cb(true, d.deleted);
        } else { self.onStatus('err', (d && d.error) || 'Refusé'); if (cb) cb(false); }
      })
      .catch(function () { self.onStatus('err', 'Réseau indisponible'); if (cb) cb(false); });
  };

  TacMap.prototype.wipe = function () {
    for (var k in this.shapes) if (this.shapes.hasOwnProperty(k)) this.layers.removeLayer(this.shapes[k].layer);
    this.shapes = {};
    this._hideBadge(true);
  };

  TacMap.prototype.reload = function () {
    this.wipe();
    this._undo = [];
    this._redo = [];
    this.onHistory(0, 0);
    this.rev = 0;
    this._etag = null;
    this._busy = false;
    this._epoch = (this._epoch || 0) + 1;
    this.sync();
  };

  TacMap.prototype.setScope = function (scope, boardId) {
    this.scope = (scope === 'priv') ? 'priv' : 'comm';
    if (boardId) this.boardId = boardId;
    this.reload();
  };

  TacMap.prototype.sync = function () {
    var self = this;
    if (this._busy) return;
    this._busy = true;
    var epoch = this._epoch || 0;
    var url = this.api + '?action=state&scope=' + this.scope + '&board=' + encodeURIComponent(this.boardId) + '&since=' + this.rev;
    var h = this._headers({});
    if (this.rev > 0 && this._etag) h['If-None-Match'] = this._etag;
    fetch(url, { headers: h })
      .then(function (r) {
        if (epoch !== (self._epoch || 0)) return null;
        self._etag = r.headers.get('ETag') || self._etag;
        if (r.status === 304) return null;
        if (!r.ok) return r.json().then(function (d) { throw new Error((d && d.error) || 'Erreur ' + r.status); });
        return r.json();
      })
      .then(function (d) {
        self._busy = false;
        if (!d || epoch !== (self._epoch || 0)) return;
        self.me = d.me;
        self.isSup = !!d.is_sup;
        if (d.full) self.wipe();
        self.onBoard('board', d.board, d.full);
        for (var i = 0; i < d.shapes.length; i++) {
          var s = d.shapes[i];
          if (s.del) { self._drop(s.cid); continue; }
          if (self.shapes[s.cid]) {
            if (self.shapes[s.cid].data.rev === s.rev) continue;
            self._drop(s.cid);
          }
          self._attach(s.cid, s, self._makeLayer(s));
        }
        self.rev = Math.max(self.rev, d.rev);
        self.onShapes(self.count());
        self.onStatus('ok');
      })
      .catch(function (e) {
        self._busy = false;
        self.onStatus('err', e && e.message ? e.message : 'Synchronisation impossible');
      });
  };

  TacMap.prototype.startPolling = function (ms) {
    var self = this;
    this.stopPolling();
    this._poll = setInterval(function () {
      if (document.hidden) return;
      if (self.drawing) return;
      self.sync();
    }, ms || 3000);
  };

  TacMap.prototype.stopPolling = function () {
    if (this._poll) { clearInterval(this._poll); this._poll = null; }
  };

  TacMap.prototype.destroy = function () {
    this.stopPolling();
    document.removeEventListener('keydown', this._onKeyDown);
    document.removeEventListener('keyup', this._onKeyUp);
    window.removeEventListener('blur', this._onBlur);
    if (this.map) this.map.remove();
  };

  window.TacMap = TacMap;
})();
