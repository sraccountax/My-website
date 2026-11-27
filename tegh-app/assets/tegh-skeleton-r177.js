/* R177: shared with design/tegh-dashboard/assets/tegh-skeleton.js (the design prototype). */
/*
 * Tegh Skeleton: shows a layout-matched placeholder while a panel's data loads, then cross-fades to the content.
 *
 *   TeghSkeleton.load(region, () => fetch('/api/...').then(r => r.json()), {
 *     render: (data, region) => { ...fill region.querySelector('.sk-content')... },
 *     renderError: (error, region, retry) => { ... }
 *   });
 *
 * States on the region (data-state): idle → loading → ready | error.
 *  - delay:   wait this long before showing the placeholder (default 150 ms), so fast answers never flash.
 *  - minShow: once shown, keep the placeholder at least this long (default 450 ms), so it never flickers.
 * Needs tegh-skeleton.css.
 */
(function (global) {
  'use strict';

  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const resolve = t => {
    const n = typeof t === 'string' ? document.querySelector(t) : t;
    if (!n) throw new Error('TeghSkeleton: region not found: ' + t);
    return n;
  };

  // Number the .sk-in pieces so they rise in one after another.
  function stagger(region, step) {
    region.querySelectorAll('.sk-content .sk-in').forEach((el, i) => el.style.setProperty('--i', String(Math.min(i, step || 12))));
  }

  function start(region, o) {
    clearTimeout(region._skTimer);
    region.dataset.state = 'idle';
    region.setAttribute('aria-busy', 'true');
    region._skShownAt = 0;
    region._skTimer = setTimeout(() => {
      region.dataset.state = 'loading';
      region._skShownAt = performance.now();
    }, o.delay);
  }

  async function settle(region, o) {
    clearTimeout(region._skTimer);
    if (region._skShownAt) {
      const wait = o.minShow - (performance.now() - region._skShownAt);
      if (wait > 0) await sleep(wait);
    }
  }

  function load(target, task, options) {
    const region = resolve(target);
    const o = Object.assign({ delay: 150, minShow: 450, render: null, renderError: null }, options);
    const run = ++region._skRun || (region._skRun = 1);
    start(region, o);
    let promise;
    try { promise = Promise.resolve(typeof task === 'function' ? task() : task); }
    catch (e) { promise = Promise.reject(e); }
    return promise.then(async data => {
      await settle(region, o);
      if (region._skRun !== run) return data;           // a newer load replaced this one
      if (o.render) o.render(data, region);
      stagger(region);
      region.dataset.state = 'ready';
      region.setAttribute('aria-busy', 'false');
      return data;
    }, async error => {
      await settle(region, o);
      if (region._skRun !== run) return undefined;
      region.dataset.state = 'error';
      region.setAttribute('aria-busy', 'false');
      if (o.renderError) o.renderError(error, region, () => load(region, task, options));
      return undefined;
    });
  }

  // Load several regions at once; each one appears as soon as its own data is ready.
  function loadAll(jobs, onProgress) {
    let done = 0;
    return Promise.all(jobs.map(job => load(job.region, job.task, job).then(v => {
      done += 1;
      if (onProgress) onProgress(done, jobs.length);
      return v;
    })));
  }

  global.TeghSkeleton = { load, loadAll, stagger };
})(window);
