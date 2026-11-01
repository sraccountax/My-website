/*
 * Tegh Charts: small, dependency-free, animated SVG charts for accounting data.
 *
 *   TeghCharts.bar(target, {labels, series})            grouped bars (money in / money out by month)
 *   TeghCharts.line(target, {labels, series, forecastFrom, markMin})   balances and forecasts
 *   TeghCharts.waterfall(target, {steps})                profit bridge: revenue to net profit
 *   TeghCharts.hbar(target, {items})                     ranked lists: expenses, ageing buckets
 *   TeghCharts.kpi(target, {label, value, previous, spark})            headline number that counts up
 *
 * Every chart returns an object with update(data), replay() and destroy().
 * Needs tegh-charts.css. Respects prefers-reduced-motion. Keyboard: focus a chart, then use the arrow keys.
 */
(function (global) {
  'use strict';

  const NS = 'http://www.w3.org/2000/svg';
  const reducedMotion = () => !!(global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches);
  const easeOut = t => 1 - Math.pow(1 - t, 3);
  const clamp01 = t => (t < 0 ? 0 : t > 1 ? 1 : t);

  // ---- Formatting (Canadian dollars by default) ----
  const cad = new Intl.NumberFormat('en-CA', { style: 'currency', currency: 'CAD', maximumFractionDigits: 0 });
  const money = v => (v < 0 ? '−' : '') + cad.format(Math.abs(v)).replace('CA', '').replace('-', '');
  const moneyShort = v => {
    const a = Math.abs(v), s = v < 0 ? '−' : '';
    if (a >= 1e6) return s + '$' + (a / 1e6).toFixed(a >= 1e7 ? 0 : 1).replace(/\.0$/, '') + 'M';
    if (a >= 1e3) return s + '$' + (a / 1e3).toFixed(a >= 1e4 ? 0 : 1).replace(/\.0$/, '') + 'k';
    return s + '$' + Math.round(a);
  };
  const percent = v => Math.round(v) + '%';

  // ---- DOM helpers ----
  const svgEl = (tag, attrs, parent) => {
    const n = document.createElementNS(NS, tag);
    if (attrs) for (const k in attrs) n.setAttribute(k, attrs[k]);
    if (parent) parent.appendChild(n);
    return n;
  };
  const htmlEl = (tag, cls, parent, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    if (parent) parent.appendChild(n);
    return n;
  };
  const setAttrs = (n, attrs) => { for (const k in attrs) n.setAttribute(k, attrs[k]); };
  const resolve = target => {
    const node = typeof target === 'string' ? document.querySelector(target) : target;
    if (!node) throw new Error('TeghCharts: container not found: ' + target);
    return node;
  };

  // ---- Scales ----
  function niceNum(x, round) {
    const e = Math.floor(Math.log10(x)), f = x / Math.pow(10, e);
    const nf = round ? (f < 1.5 ? 1 : f < 3 ? 2 : f < 7 ? 5 : 10) : (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10);
    return nf * Math.pow(10, e);
  }
  function niceScale(lo, hi, count) {
    if (!(hi > lo)) hi = lo + 1;
    const step = niceNum(niceNum(hi - lo, false) / Math.max(1, count - 1), true);
    const min = Math.floor(lo / step) * step, max = Math.ceil(hi / step) * step, ticks = [];
    for (let v = min; v <= max + step / 2; v += step) ticks.push(Math.round(v / step) * step);
    return { min, max, ticks };
  }

  // Bar path with rounded corners on the data end only, anchored flat on the baseline.
  function barPath(x, w, y0, y1) {
    if (Math.abs(y1 - y0) < 0.01 || w <= 0) return '';
    const up = y1 < y0, r = Math.min(4, w / 2, Math.abs(y1 - y0));
    const e = up ? y1 + r : y1 - r, c = y1;
    return 'M' + x + ',' + y0 + 'V' + e + 'Q' + x + ',' + c + ' ' + (x + r) + ',' + c + 'H' + (x + w - r) +
      'Q' + (x + w) + ',' + c + ' ' + (x + w) + ',' + e + 'V' + y0 + 'Z';
  }

  // ---- Base chart: legend, tooltip, table view, resize, keyboard and the animation loop ----
  class Chart {
    constructor(target, options, defaults) {
      this.root = resolve(target);
      this.o = Object.assign({
        height: 280, format: moneyShort, tipFormat: money, animate: true, duration: 750, stagger: 45,
        table: true, ariaLabel: '', colors: ['var(--tc-1)', 'var(--tc-2)', 'var(--tc-total)']
      }, defaults, options);
      this.root.classList.add('tc');
      this.root.textContent = '';
      this.head = htmlEl('div', 'tc-head', this.root);
      this.legend = htmlEl('div', 'tc-legend', this.head);
      if (this.o.table) {
        this.tableBtn = htmlEl('button', 'tc-btn', this.head, 'Table');
        this.tableBtn.type = 'button';
        this.tableBtn.setAttribute('aria-pressed', 'false');
        this.tableBtn.addEventListener('click', () => this.showTable(this.tableBox.hidden));
      }
      this.stage = htmlEl('div', 'tc-stage', this.root);
      this.stage.tabIndex = 0;
      this.stage.setAttribute('role', 'group');
      this.stage.setAttribute('aria-roledescription', 'chart');
      this.tip = htmlEl('div', 'tc-tip', this.stage);
      this.tip.setAttribute('role', 'status');
      this.tip.setAttribute('aria-live', 'polite');
      this.tip.hidden = true;
      this.tableBox = htmlEl('div', 'tc-table', this.root);
      this.tableBox.hidden = true;
      this.active = -1;
      this.cur = null;

      this._onMove = e => {
        const r = this.stage.getBoundingClientRect();
        this.setActive(this.hitIndex(e.clientX - r.left, e.clientY - r.top));
      };
      this._onLeave = () => this.setActive(-1);
      this._onKey = e => {
        const n = this.count();
        if (!n) return;
        let i = this.active;
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') i = i < 0 ? 0 : Math.min(n - 1, i + 1);
        else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') i = i < 0 ? n - 1 : Math.max(0, i - 1);
        else if (e.key === 'Home') i = 0;
        else if (e.key === 'End') i = n - 1;
        else if (e.key === 'Escape') i = -1;
        else return;
        e.preventDefault();
        this.setActive(i);
      };
      this.stage.addEventListener('pointermove', this._onMove);
      this.stage.addEventListener('pointerdown', this._onMove);
      this.stage.addEventListener('pointerleave', this._onLeave);
      this.stage.addEventListener('keydown', this._onKey);
      this.stage.addEventListener('blur', this._onLeave);
      if (global.ResizeObserver) {
        this._ro = new ResizeObserver(() => {
          const w = Math.round(this.stage.clientWidth);
          if (w && w !== this.w && this.data) { this.w = w; this.build(); this.paint(this.cur || this.target(), 1); }
        });
        this._ro.observe(this.stage);
      }
    }

    // Public API
    update(data) {
      this.data = this.normalize(data);
      this.w = Math.round(this.stage.clientWidth) || 600;
      this.setActive(-1);
      this.renderLegend();
      this.renderTable();
      this.stage.setAttribute('aria-label', this.o.ariaLabel || this.summary());
      const fresh = !this.cur || this.cur.length !== this.target().length;
      this.build();
      this.run(fresh ? this.start() : this.cur, this.target(), fresh);
      return this;
    }
    replay() { this.cur = null; return this.update(this.data); }
    showTable(on) {
      this.tableBox.hidden = !on;
      this.stage.hidden = !!on;
      if (this.tableBtn) { this.tableBtn.setAttribute('aria-pressed', on ? 'true' : 'false'); this.tableBtn.textContent = on ? 'Chart' : 'Table'; }
      if (!on && this.data) { this.w = Math.round(this.stage.clientWidth) || this.w; this.build(); this.paint(this.cur || this.target(), 1); }
      return this;
    }
    destroy() {
      cancelAnimationFrame(this._raf);
      if (this._ro) this._ro.disconnect();
      this.stage.removeEventListener('pointermove', this._onMove);
      this.stage.removeEventListener('pointerdown', this._onMove);
      this.stage.removeEventListener('pointerleave', this._onLeave);
      this.stage.removeEventListener('keydown', this._onKey);
      this.root.textContent = '';
      this.root.classList.remove('tc');
    }

    // Animation: each value moves from `from` to `to`; groups start a little after each other (stagger).
    run(from, to, fresh) {
      cancelAnimationFrame(this._raf);
      this.fresh = fresh;
      if (!this.o.animate || reducedMotion()) { this.cur = to.slice(); this.paint(this.cur, 1); return; }
      const o = this.o, groups = this.groupCount(), total = o.duration + o.stagger * Math.max(0, groups - 1);
      const t0 = performance.now();
      const frame = now => {
        const el = now - t0;
        this.cur = to.map((v, k) => {
          const p = easeOut(clamp01((el - this.groupOf(k) * o.stagger) / o.duration));
          return from[k] + (v - from[k]) * p;
        });
        this.paint(this.cur, clamp01(el / total));
        if (el < total) this._raf = requestAnimationFrame(frame);
        else { this.cur = to.slice(); this.paint(this.cur, 1); }
      };
      this._raf = requestAnimationFrame(frame);
    }

    setActive(i) {
      if (i === this.active) return;
      this.active = i;
      this.highlight(i);
      if (i < 0) { this.tip.hidden = true; return; }
      const t = this.tipFor(i);
      this.tip.textContent = '';
      htmlEl('b', '', this.tip, t.title);
      for (const row of t.rows) {
        const r = htmlEl('div', 'tc-tip-row' + (row.total ? ' is-total' : ''), this.tip);
        const name = htmlEl('span', '', r);
        if (row.color) htmlEl('i', 'tc-swatch', name).style.background = row.color;
        name.appendChild(document.createTextNode(row.name));
        htmlEl('span', '', r, row.value);
      }
      this.tip.hidden = false;
      const tw = this.tip.offsetWidth, sw = this.stage.clientWidth;
      let x = t.x + 14;
      if (x + tw > sw) x = t.x - tw - 14;
      this.tip.style.transform = 'translate(' + Math.max(0, Math.round(x)) + 'px,' + Math.max(0, Math.round((t.y || 0) - 8)) + 'px)';
    }

    renderLegend() {
      this.legend.textContent = '';
      for (const k of this.legendItems()) {
        const key = htmlEl('span', 'tc-key', this.legend);
        const sw = htmlEl('i', 'tc-swatch' + (k.kind ? ' is-' + k.kind : ''), key);
        if (k.kind === 'dash') sw.style.color = k.color; else sw.style.background = k.color;
        key.appendChild(document.createTextNode(k.name));
      }
    }
    renderTable() {
      const t = this.tableData();
      this.tableBox.textContent = '';
      const table = htmlEl('table', '', this.tableBox), thead = htmlEl('thead', '', table), tr = htmlEl('tr', '', thead);
      t.head.forEach(hd => { const th = htmlEl('th', '', tr, hd); th.scope = 'col'; });
      const tbody = htmlEl('tbody', '', table);
      t.rows.forEach(row => {
        const r = htmlEl('tr', '', tbody);
        row.forEach((cell, j) => { const c = htmlEl(j ? 'td' : 'th', '', r, cell); if (!j) c.scope = 'row'; });
      });
    }

    // Shared SVG frame: gridlines and value axis labels.
    frame(scale, pad) {
      this.svg = this.svg || svgEl('svg', { class: 'tc-svg', 'aria-hidden': 'true' }, this.stage);
      this.svg.textContent = '';
      const W = this.w, H = this.o.height;
      setAttrs(this.svg, { viewBox: '0 0 ' + W + ' ' + H, height: H });
      this.svg.style.height = H + 'px';   // host pages may size bare SVGs as icons
      const y = v => pad.t + (1 - (v - scale.min) / (scale.max - scale.min)) * (H - pad.t - pad.b);
      const grid = svgEl('g', null, this.svg);
      for (const v of scale.ticks) {
        svgEl('line', { x1: pad.l, x2: W - pad.r, y1: y(v), y2: y(v), stroke: v === 0 ? 'var(--tc-axis)' : 'var(--tc-grid)', 'stroke-width': 1 }, grid);
        svgEl('text', { x: pad.l - 8, y: y(v) + 4, 'text-anchor': 'end' }, grid).textContent = this.o.format(v);
      }
      return y;
    }
    xLabels(labels, xOf, y, minGap) {
      const g = svgEl('g', null, this.svg);
      const every = Math.max(1, Math.ceil(minGap / Math.max(1, (xOf(1) - xOf(0)) || minGap)));
      labels.forEach((lab, i) => {
        if (i % every) return;
        svgEl('text', { x: xOf(i), y, 'text-anchor': 'middle' }, g).textContent = lab;
      });
    }

    // Defaults subclasses override
    normalize(d) { return d; }
    start() { return this.target().map(() => 0); }
    groupCount() { return 1; }
    groupOf() { return 0; }
    count() { return 0; }
    highlight(i) { if (this.svg) this.svg.classList.toggle('tc-dim', i >= 0); }
    legendItems() { return []; }
    summary() { return 'Chart'; }
  }

  // ---- Grouped bar chart ----
  class BarChart extends Chart {
    constructor(target, opts) { super(target, opts, { height: 280 }); this.update(opts.data || opts); }
    normalize(d) {
      const series = d.series.map((s, i) => ({ name: s.name, values: s.values.map(Number), color: s.color || this.o.colors[i % this.o.colors.length] }));
      return { labels: d.labels.slice(), series };
    }
    target() { return [].concat(...this.data.series.map(s => s.values)); }
    count() { return this.data.labels.length; }
    groupCount() { return this.count(); }
    groupOf(k) { return k % this.count(); }
    legendItems() { return this.data.series.map(s => ({ name: s.name, color: s.color })); }
    summary() {
      return 'Bar chart. ' + this.data.series.map(s => s.name + ' totals ' + this.o.tipFormat(s.values.reduce((a, b) => a + b, 0))).join('; ') +
        ', over ' + this.count() + ' periods. Use the Table button for every value.';
    }
    tableData() {
      const d = this.data, net = d.series.length === 2;
      return {
        head: ['Period'].concat(d.series.map(s => s.name), net ? ['Net'] : []),
        rows: d.labels.map((l, i) => [l].concat(d.series.map(s => this.o.tipFormat(s.values[i])), net ? [this.o.tipFormat(d.series[0].values[i] - d.series[1].values[i])] : []))
      };
    }
    build() {
      const d = this.data, n = this.count(), m = d.series.length, H = this.o.height;
      const all = this.target(), lo = Math.min(0, ...all), hi = Math.max(0, ...all);
      this.pad = { l: 48, r: 8, t: 10, b: 28 };
      this.scale = niceScale(lo, hi, 5);
      this.y = this.frame(this.scale, this.pad);
      const plot = this.w - this.pad.l - this.pad.r;
      this.gw = plot / n;
      this.bw = Math.max(3, Math.min(28, (this.gw * 0.72 - 2 * (m - 1)) / m));
      this.cx = i => this.pad.l + this.gw * (i + 0.5);
      this.marks = [];
      const g = svgEl('g', null, this.svg);
      for (let s = 0; s < m; s++) for (let i = 0; i < n; i++) {
        const p = svgEl('path', { class: 'tc-mark', fill: d.series[s].color }, g);
        p.dataset.group = i;
        this.marks.push(p);
      }
      this.xLabels(d.labels, this.cx, H - 8, 34);
      this.last = svgEl('text', { class: 'tc-strong', 'text-anchor': 'middle' }, this.svg);
    }
    paint(vals) {
      const n = this.count(), m = this.data.series.length, y0 = this.y(0);
      const span = m * this.bw + 2 * (m - 1);
      vals.forEach((v, k) => {
        const s = Math.floor(k / n), i = k % n, x = this.cx(i) - span / 2 + s * (this.bw + 2);
        this.marks[k].setAttribute('d', barPath(+x.toFixed(2), +this.bw.toFixed(2), y0, +this.y(v).toFixed(2)));
      });
      // Direct label on the latest value of the first series.
      const k = n - 1, v = vals[k];
      setAttrs(this.last, { x: this.cx(k) - span / 2 + this.bw / 2, y: this.y(Math.max(0, v)) - 6 });
      this.last.textContent = this.o.format(v);
    }
    hitIndex(x) {
      const i = Math.floor((x - this.pad.l) / this.gw);
      return i >= 0 && i < this.count() ? i : -1;
    }
    highlight(i) {
      super.highlight(i);
      if (this.marks) this.marks.forEach(p => p.classList.toggle('is-on', +p.dataset.group === i));
    }
    tipFor(i) {
      const d = this.data, rows = d.series.map(s => ({ name: s.name, color: s.color, value: this.o.tipFormat(s.values[i]) }));
      if (d.series.length === 2) {
        const net = d.series[0].values[i] - d.series[1].values[i];
        rows.push({ name: 'Net', value: (net > 0 ? '+' : '') + this.o.tipFormat(net), total: true });
      }
      const top = Math.min(...d.series.map(s => this.y(Math.max(0, s.values[i]))));
      return { title: d.labels[i], rows, x: this.cx(i), y: Math.max(0, top - 40) };
    }
  }

  // ---- Line chart (balances, forecasts) ----
  class LineChart extends Chart {
    constructor(target, opts) { super(target, opts, { height: 260, zero: false }); this.update(opts.data || opts); }
    normalize(d) {
      const series = d.series.map((s, i) => ({ name: s.name, values: s.values.map(Number), color: s.color || this.o.colors[i % this.o.colors.length] }));
      return { labels: d.labels.slice(), series, forecastFrom: d.forecastFrom == null ? -1 : d.forecastFrom, markMin: !!d.markMin, minLabel: d.minLabel || 'Lowest' };
    }
    target() { return [].concat(...this.data.series.map(s => s.values)); }
    start() {
      // First draw: the line grows from its own values left to right (see paint), so start at the target.
      return this.target();
    }
    count() { return this.data.labels.length; }
    legendItems() {
      const items = this.data.series.map(s => ({ name: s.name, color: s.color, kind: 'line' }));
      if (this.data.forecastFrom >= 0) items.push({ name: 'Forecast', color: 'var(--tc-ink-3)', kind: 'dash' });
      return items;
    }
    summary() {
      const s = this.data.series[0], v = s.values;
      return 'Line chart of ' + s.name + ' from ' + this.o.tipFormat(v[0]) + ' to ' + this.o.tipFormat(v[v.length - 1]) +
        ', lowest ' + this.o.tipFormat(Math.min(...v)) + '. Use the Table button for every value.';
    }
    tableData() {
      const d = this.data;
      return {
        head: ['Period'].concat(d.series.map(s => s.name)),
        rows: d.labels.map((l, i) => [l + (d.forecastFrom >= 0 && i > d.forecastFrom ? ' (forecast)' : '')].concat(d.series.map(s => this.o.tipFormat(s.values[i]))))
      };
    }
    build() {
      const d = this.data, H = this.o.height, all = this.target();
      const lo = this.o.zero ? Math.min(0, ...all) : Math.min(...all), hi = Math.max(...all);
      const padV = (hi - lo) * 0.08;
      this.pad = { l: 48, r: 14, t: 18, b: 28 };
      this.scale = niceScale(lo - padV, hi + padV, 5);
      this.y = this.frame(this.scale, this.pad);
      const n = this.count(), plot = this.w - this.pad.l - this.pad.r;
      this.x = i => this.pad.l + (n > 1 ? plot * i / (n - 1) : plot / 2);
      const f = d.forecastFrom;
      if (f >= 0) {
        svgEl('rect', { x: this.x(f), y: this.pad.t, width: this.w - this.pad.r - this.x(f), height: H - this.pad.t - this.pad.b, fill: 'var(--tc-track)', opacity: 0.6 }, this.svg);
        svgEl('text', { x: this.x(f) + 6, y: this.pad.t + 12, class: 'tc-strong' }, this.svg).textContent = 'Forecast';
      }
      const id = 'tc-clip-' + Math.random().toString(36).slice(2);
      const clip = svgEl('clipPath', { id }, svgEl('defs', null, this.svg));
      this.clipRect = svgEl('rect', { x: 0, y: 0, width: this.w, height: H }, clip);
      const g = svgEl('g', { 'clip-path': 'url(#' + id + ')' }, this.svg);
      this.lines = d.series.map((s, si) => ({
        area: si === 0 ? svgEl('path', { fill: s.color, opacity: 0.1 }, g) : null,
        solid: svgEl('path', { fill: 'none', stroke: s.color, 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }, g),
        dash: svgEl('path', { fill: 'none', stroke: s.color, 'stroke-width': 2, 'stroke-dasharray': '5 5', 'stroke-linecap': 'round' }, g)
      }));
      this.xLabels(d.labels, this.x, H - 8, 56);
      this.minDot = d.markMin ? svgEl('circle', { r: 5, fill: 'var(--tc-2)', stroke: 'var(--tc-surface)', 'stroke-width': 2 }, this.svg) : null;
      this.minText = d.markMin ? svgEl('text', { class: 'tc-strong', 'text-anchor': 'middle' }, this.svg) : null;
      this.cross = svgEl('line', { y1: this.pad.t, y2: H - this.pad.b, stroke: 'var(--tc-axis)', 'stroke-width': 1, visibility: 'hidden' }, this.svg);
      this.dots = d.series.map(s => svgEl('circle', { r: 5, fill: s.color, stroke: 'var(--tc-surface)', 'stroke-width': 2, visibility: 'hidden' }, this.svg));
    }
    paint(vals, progress) {
      const n = this.count(), f = this.data.forecastFrom, H = this.o.height;
      if (this.fresh) this.clipRect.setAttribute('width', this.pad.l + (this.w - this.pad.l) * easeOut(progress));
      else this.clipRect.setAttribute('width', this.w);
      this.data.series.forEach((s, si) => {
        const pts = [];
        for (let i = 0; i < n; i++) pts.push([+this.x(i).toFixed(2), +this.y(vals[si * n + i]).toFixed(2)]);
        const path = list => list.map((p, j) => (j ? 'L' : 'M') + p[0] + ',' + p[1]).join('');
        const solidPts = f >= 0 ? pts.slice(0, f + 1) : pts, dashPts = f >= 0 ? pts.slice(f) : [];
        const L = this.lines[si];
        L.solid.setAttribute('d', path(solidPts));
        L.dash.setAttribute('d', dashPts.length ? path(dashPts) : '');
        if (L.area) {
          const base = H - this.pad.b;
          L.area.setAttribute('d', path(pts) + 'L' + pts[n - 1][0] + ',' + base + 'L' + pts[0][0] + ',' + base + 'Z');
        }
      });
      if (this.minDot) {
        // With a forecast, mark the lowest forecast point: that is the one to plan for.
        const v = vals.slice(0, n), from = Math.max(0, f), mi = from + v.slice(from).indexOf(Math.min(...v.slice(from)));
        const show = !this.fresh || progress >= 1;
        setAttrs(this.minDot, { cx: this.x(mi), cy: this.y(v[mi]), visibility: show ? 'visible' : 'hidden' });
        const below = this.y(v[mi]) + 22 < H - this.pad.b;
        setAttrs(this.minText, { x: Math.min(this.w - 50, Math.max(this.pad.l + 40, this.x(mi))), y: below ? this.y(v[mi]) + 22 : this.y(v[mi]) - 12, visibility: show ? 'visible' : 'hidden' });
        this.minText.textContent = this.data.minLabel + ' ' + this.o.format(v[mi]);
      }
      if (this.active >= 0) this.placeCross(this.active);
    }
    placeCross(i) {
      const n = this.count(), on = i >= 0;
      this.cross.setAttribute('visibility', on ? 'visible' : 'hidden');
      this.dots.forEach(d => d.setAttribute('visibility', on ? 'visible' : 'hidden'));
      if (!on) return;
      setAttrs(this.cross, { x1: this.x(i), x2: this.x(i) });
      this.dots.forEach((d, si) => setAttrs(d, { cx: this.x(i), cy: this.y(this.cur[si * n + i]) }));
    }
    highlight(i) { if (this.cross) this.placeCross(i); }
    hitIndex(x) {
      const n = this.count(), plot = this.w - this.pad.l - this.pad.r;
      if (x < this.pad.l - 20 || x > this.w) return -1;
      return Math.max(0, Math.min(n - 1, Math.round((x - this.pad.l) / plot * (n - 1))));
    }
    tipFor(i) {
      const d = this.data, forecast = d.forecastFrom >= 0 && i > d.forecastFrom;
      return {
        title: d.labels[i] + (forecast ? ' · forecast' : ''),
        rows: d.series.map(s => ({ name: s.name, color: s.color, value: this.o.tipFormat(s.values[i]) })),
        x: this.x(i), y: Math.max(0, this.y(d.series[0].values[i]) - 60)
      };
    }
  }

  // ---- Waterfall: how revenue turns into net profit ----
  class WaterfallChart extends Chart {
    constructor(target, opts) { super(target, opts, { height: 300, stagger: 110 }); this.update(opts.data || opts); }
    normalize(d) {
      let run = 0;
      const steps = d.steps.map(s => {
        const value = Number(s.value);
        const from = s.total ? 0 : run, to = s.total ? value : run + value;
        run = to;
        return { label: s.label, value, total: !!s.total, from, to };
      });
      return { steps };
    }
    target() { return this.data.steps.map(s => s.to); }
    start() { return this.data.steps.map(s => s.from); }
    count() { return this.data.steps.length; }
    groupCount() { return this.count(); }
    groupOf(k) { return k; }
    colorOf(s) { return s.total ? 'var(--tc-total)' : s.value >= 0 ? 'var(--tc-1)' : 'var(--tc-2)'; }
    legendItems() { return [{ name: 'Adds', color: 'var(--tc-1)' }, { name: 'Takes away', color: 'var(--tc-2)' }, { name: 'Total', color: 'var(--tc-total)' }]; }
    summary() {
      const s = this.data.steps;
      return 'Waterfall from ' + s[0].label + ' ' + this.o.tipFormat(s[0].to) + ' to ' + s[s.length - 1].label + ' ' + this.o.tipFormat(s[s.length - 1].to) + '. Use the Table button for every step.';
    }
    tableData() {
      return { head: ['Step', 'Amount', 'Running total'], rows: this.data.steps.map(s => [s.label, s.total ? '' : (s.value > 0 ? '+' : '') + this.o.tipFormat(s.value), this.o.tipFormat(s.to)]) };
    }
    build() {
      const st = this.data.steps, H = this.o.height, all = st.map(s => s.to).concat(st.map(s => s.from));
      this.pad = { l: 52, r: 8, t: 22, b: 30 };
      this.scale = niceScale(Math.min(0, ...all), Math.max(0, ...all), 5);
      this.y = this.frame(this.scale, this.pad);
      const n = this.count();
      this.gw = (this.w - this.pad.l - this.pad.r) / n;
      this.bw = Math.max(8, Math.min(56, this.gw * 0.62));
      this.cx = i => this.pad.l + this.gw * (i + 0.5);
      this.links = st.slice(0, -1).map(() => svgEl('line', { stroke: 'var(--tc-axis)', 'stroke-width': 1, 'stroke-dasharray': '3 3' }, this.svg));
      const g = svgEl('g', null, this.svg);
      this.marks = st.map((s, i) => { const p = svgEl('path', { class: 'tc-mark', fill: this.colorOf(s) }, g); p.dataset.group = i; return p; });
      this.labels = st.map(() => svgEl('text', { class: 'tc-strong', 'text-anchor': 'middle' }, this.svg));
      // Long step names: wrap to two lines on narrow screens.
      const lg = svgEl('g', null, this.svg);
      st.forEach((s, i) => {
        const t = svgEl('text', { x: this.cx(i), y: H - 16, 'text-anchor': 'middle' }, lg);
        const words = s.label.split(' ');
        if (this.gw < 60 && !s.total) return;   // narrow screens: name the totals only; tap a bar for the rest
        if (this.gw < 90 && words.length > 1) {
          const half = Math.ceil(words.length / 2);
          svgEl('tspan', { x: this.cx(i), dy: 0 }, t).textContent = words.slice(0, half).join(' ');
          svgEl('tspan', { x: this.cx(i), dy: 13 }, t).textContent = words.slice(half).join(' ');
        } else { t.setAttribute('y', H - 10); t.textContent = s.label; }
      });
    }
    paint(vals) {
      const st = this.data.steps;
      st.forEach((s, i) => {
        const x = +(this.cx(i) - this.bw / 2).toFixed(2), y0 = this.y(s.from), y1 = this.y(vals[i]);
        const done = Math.abs(vals[i] - s.to) < Math.abs(s.to - s.from) * 0.002 + 1e-6;
        this.marks[i].setAttribute('d', s.total ? barPath(x, this.bw, this.y(0), y1) : barPath(x, this.bw, y0, y1));
        const top = Math.min(y0, y1, this.y(0));
        setAttrs(this.labels[i], { x: this.cx(i), y: (s.value < 0 && !s.total ? Math.max(y0, y1) + 15 : top - 6), visibility: done && (s.total || this.gw >= 60) ? 'visible' : 'hidden' });
        this.labels[i].textContent = s.total ? this.o.format(s.to) : (s.value > 0 ? '+' : '') + this.o.format(s.value);
        if (i < this.links.length) {
          setAttrs(this.links[i], { x1: this.cx(i) + this.bw / 2, x2: this.cx(i + 1) - this.bw / 2, y1: this.y(s.to), y2: this.y(s.to), visibility: done ? 'visible' : 'hidden' });
        }
      });
    }
    hitIndex(x) { const i = Math.floor((x - this.pad.l) / this.gw); return i >= 0 && i < this.count() ? i : -1; }
    highlight(i) { super.highlight(i); if (this.marks) this.marks.forEach(p => p.classList.toggle('is-on', +p.dataset.group === i)); }
    tipFor(i) {
      const s = this.data.steps[i], first = this.data.steps[0].to;
      const rows = s.total
        ? [{ name: 'Total', color: this.colorOf(s), value: this.o.tipFormat(s.to) }]
        : [{ name: s.value >= 0 ? 'Adds' : 'Takes away', color: this.colorOf(s), value: this.o.tipFormat(Math.abs(s.value)) }, { name: 'Running total', value: this.o.tipFormat(s.to), total: true }];
      if (first && i > 0) rows.push({ name: 'Share of ' + this.data.steps[0].label.toLowerCase(), value: percent(Math.abs(s.total ? s.to : s.value) / first * 100) });
      return { title: s.label, rows, x: this.cx(i), y: Math.max(0, Math.min(this.y(s.from), this.y(s.to)) - 50) };
    }
  }

  // ---- Horizontal bar list (HTML, so long labels wrap and stay crisp) ----
  class HBarChart extends Chart {
    constructor(target, opts) { super(target, opts, { stagger: 60, sort: true, color: 'var(--tc-2)', showShare: true }); this.update(opts.data || opts); }
    normalize(d) {
      const items = d.items.map(it => ({ label: it.label, value: Number(it.value), flag: it.flag || '', color: it.color || (it.muted ? 'var(--tc-total)' : this.o.color), muted: !!it.muted }));
      if (this.o.sort) items.sort((a, b) => (a.muted - b.muted) || (b.value - a.value));
      return { items, total: items.reduce((a, b) => a + b.value, 0) };
    }
    target() { return this.data.items.map(i => i.value); }
    count() { return this.data.items.length; }
    groupCount() { return this.count(); }
    groupOf(k) { return k; }
    summary() { return 'Ranked list. ' + this.data.items.map(i => i.label + ' ' + this.o.tipFormat(i.value)).join('; ') + '.'; }
    tableData() { return { head: ['Item', 'Amount', 'Share'], rows: this.data.items.map(i => [i.label, this.o.tipFormat(i.value), percent(i.value / (this.data.total || 1) * 100)]) }; }
    build() {
      if (this.list) this.list.remove();
      this.list = htmlEl('ul', 'tc-rows', this.stage);
      this.max = Math.max(...this.target(), 1);
      this.rows = this.data.items.map(it => {
        const li = htmlEl('li', 'tc-row', this.list), top = htmlEl('div', 'tc-row-top', li);
        const lab = htmlEl('span', 'tc-row-label', top, it.label);
        if (it.flag) htmlEl('span', 'tc-flag', lab, '! ' + it.flag);
        const val = htmlEl('span', 'tc-row-value', top, this.o.tipFormat(it.value));
        if (this.o.showShare) htmlEl('span', 'tc-row-share', val, percent(it.value / (this.data.total || 1) * 100));
        const fill = htmlEl('div', 'tc-fill', htmlEl('div', 'tc-track', li));
        fill.style.background = it.color;
        return { li, fill };
      });
    }
    paint(vals) { vals.forEach((v, i) => { this.rows[i].fill.style.width = (Math.max(0, v) / this.max * 100).toFixed(2) + '%'; }); }
    hitIndex(x, y) {
      if (!this.rows) return -1;
      const top = this.stage.getBoundingClientRect().top;
      return this.rows.findIndex(r => { const b = r.li.getBoundingClientRect(); return y >= b.top - top && y <= b.bottom - top; });
    }
    highlight(i) { if (this.rows) this.rows.forEach((r, j) => r.li.classList.toggle('is-on', j === i)); }
    // Values are printed on every row, so hover and arrow keys highlight the row without a tooltip.
    setActive(i) { if (i === this.active) return; this.active = i; this.highlight(i); }
  }

  // ---- KPI tile: number counts up, sparkline draws in ----
  class Kpi {
    constructor(target, opts) {
      this.root = resolve(target);
      this.o = Object.assign({ format: money, duration: 900, goodWhen: 'up', compareLabel: 'vs last period' }, opts);
      this.root.classList.add('tc', 'tc-kpi');
      this.root.textContent = '';
      htmlEl('div', 'tc-kpi-label', this.root, this.o.label);
      this.num = htmlEl('div', 'tc-kpi-value', this.root);
      this.delta = htmlEl('div', 'tc-kpi-delta', this.root);
      this.spark = svgEl('svg', { class: 'tc-spark', viewBox: '0 0 200 40', preserveAspectRatio: 'none', 'aria-hidden': 'true' }, this.root);
      this.spark.style.height = '40px';
      this.area = svgEl('path', { fill: 'var(--tc-1)', opacity: 0.1 }, this.spark);
      this.line = svgEl('path', { fill: 'none', stroke: 'var(--tc-1)', 'stroke-width': 2, 'vector-effect': 'non-scaling-stroke', 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }, this.spark);
      this.end = svgEl('circle', { r: 3, fill: 'var(--tc-1)' }, this.spark);
      this.value = 0;
      this.update(opts);
    }
    update(d) {
      Object.assign(this.o, d);
      const o = this.o, from = this.value, to = Number(o.value);
      this.root.setAttribute('aria-label', o.label + ': ' + o.format(to));
      if (o.previous != null) {
        const ch = to - o.previous, up = ch >= 0, good = (o.goodWhen === 'up') === up;
        this.delta.className = 'tc-kpi-delta ' + (good ? 'is-good' : 'is-bad');
        this.delta.textContent = (up ? '▲ Up ' : '▼ Down ') + o.format(Math.abs(ch)) + ' ';
        htmlEl('span', '', this.delta, o.compareLabel);
      } else this.delta.textContent = '';
      const s = o.spark || [];
      this.spark.style.display = s.length > 1 ? '' : 'none';
      if (s.length > 1) {
        const mn = Math.min(...s), mx = Math.max(...s), pts = s.map((v, i) => [i * 200 / (s.length - 1), 36 - (v - mn) / ((mx - mn) || 1) * 32]);
        const d = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ',' + p[1].toFixed(1)).join('');
        this.line.setAttribute('d', d);
        this.area.setAttribute('d', d + 'L200,40L0,40Z');
        setAttrs(this.end, { cx: 200, cy: pts[pts.length - 1][1] });
        const len = this.line.getTotalLength ? this.line.getTotalLength() : 400;
        this.line.style.strokeDasharray = len;
        this.line.style.strokeDashoffset = reducedMotion() ? 0 : len;
        this.end.style.opacity = reducedMotion() ? 1 : 0;
      }
      cancelAnimationFrame(this._raf);
      if (reducedMotion()) { this.value = to; this.num.textContent = o.format(to); return this; }
      const t0 = performance.now();
      const frame = now => {
        const p = easeOut(clamp01((now - t0) / o.duration));
        this.value = from + (to - from) * p;
        this.num.textContent = o.format(this.value);
        if (s.length > 1) { this.line.style.strokeDashoffset = (1 - p) * parseFloat(this.line.style.strokeDasharray); this.end.style.opacity = p > 0.95 ? 1 : 0; }
        if (p < 1) this._raf = requestAnimationFrame(frame); else this.value = to;
      };
      this._raf = requestAnimationFrame(frame);
      return this;
    }
    replay() { this.value = 0; return this.update({}); }
    destroy() { cancelAnimationFrame(this._raf); this.root.textContent = ''; }
  }

  const make = Cls => (target, opts) => new Cls(target, opts || {});
  global.TeghCharts = {
    bar: make(BarChart), line: make(LineChart), waterfall: make(WaterfallChart), hbar: make(HBarChart), kpi: make(Kpi),
    format: { money, moneyShort, percent }
  };
})(window);
