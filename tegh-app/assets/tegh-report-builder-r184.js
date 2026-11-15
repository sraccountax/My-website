// R184: Report Builder page (Full Accounting › Reports › Report Builder).
// The browser only describes the report: data area, rows, columns side by side, values, filters, sort and period.
// The server checks every choice against its catalogue and the user's role, runs the report read-only, and returns a
// sealed result; this page draws it, drills into the lines behind a figure, and exports through Tegh's exporters.
const STYLE = new URL('./tegh-report-builder-r184.css', import.meta.url); STYLE.search = new URL(import.meta.url).search;
if (!document.querySelector('link[data-rb-style]')) { const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = STYLE.href; l.dataset.rbStyle = ''; document.head.append(l); }

const OPS = {
  text: [['eq', 'is'], ['ne', 'is not'], ['in', 'is one of'], ['contains', 'contains'], ['blank', 'is blank'], ['notblank', 'is not blank']],
  date: [['eq', 'is on'], ['before', 'is before'], ['after', 'is after'], ['between', 'is between']],
  number: [['eq', 'equals'], ['ne', 'does not equal'], ['gt', 'is over'], ['gte', 'is at least'], ['lt', 'is under'], ['lte', 'is at most'], ['between', 'is between']],
};
const opsFor = type => type === 'text' ? OPS.text : type === 'date' ? OPS.date : OPS.number;
const scale = type => type === 'money' ? 100 : type === 'quantity' ? 1000 : 1;
const newKey = () => (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2)).replace(/[^A-Za-z0-9]/g, '').slice(0, 40).padEnd(20, '0');
const iso = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

function periodPresets(today, fyEnd) {
  const t = new Date(today + 'T12:00:00'), y = t.getFullYear(), m = t.getMonth();
  const mStart = (yy, mm) => iso(new Date(yy, mm, 1)), mEnd = (yy, mm) => iso(new Date(yy, mm + 1, 0));
  const q = Math.floor(m / 3);
  const [fm, fd] = (fyEnd || '12-31').split('-').map(Number);
  let fyEndYear = y; if (m + 1 > fm || (m + 1 === fm && t.getDate() > fd)) fyEndYear = y + 1;
  const fyRange = endYear => { const end = new Date(endYear, fm - 1, fd), start = new Date(endYear - 1, fm - 1, fd + 1); return [iso(start), iso(end)]; };
  return [
    ['this-fy', 'This financial year', ...fyRange(fyEndYear)], ['last-fy', 'Last financial year', ...fyRange(fyEndYear - 1)],
    ['this-month', 'This month', mStart(y, m), mEnd(y, m)], ['last-month', 'Last month', mStart(y, m - 1), mEnd(y, m - 1)],
    ['this-quarter', 'This quarter', mStart(y, q * 3), mEnd(y, q * 3 + 2)], ['last-quarter', 'Last quarter', mStart(y, q * 3 - 3), mEnd(y, q * 3 - 1)],
    ['this-year', 'This calendar year', `${y}-01-01`, `${y}-12-31`], ['last-year', 'Last calendar year', `${y - 1}-01-01`, `${y - 1}-12-31`],
  ];
}

export function mount(body, ctx) {
  const {api, esc, money, toast, userFacingError} = ctx;
  const S = {catalogue: null, saved: [], current: null, spec: null, output: null, busy: false};
  const today = ctx.today ? ctx.today() : iso(new Date());
  const presets = periodPresets(today, ctx.fiscalYearEnd ? ctx.fiscalYearEnd() : '12-31');
  const $ = (s, r = body) => r.querySelector(s), $$ = (s, r = body) => [...r.querySelectorAll(s)];

  body.innerHTML = `<div class="rb" data-rb>
    <header class="rb-bar">
      <label class="rb-open">Saved reports <select data-rb-saved aria-label="Open a saved report"><option value="">New report</option></select></label>
      <span class="rb-name" data-rb-name data-preserve-case>New report</span>
      <span class="rb-spacer"></span>
      <button type="button" class="srp-btn secondary" data-rb-new>New</button>
      <button type="button" class="srp-btn secondary" data-rb-save>Save</button>
      <button type="button" class="srp-btn secondary" data-rb-saveas>Save as new</button>
      <button type="button" class="srp-btn secondary" data-rb-delete hidden>Delete</button>
    </header>
    <div class="rb-grid">
      <form class="rb-form" data-rb-form novalidate><p class="rb-loading" role="status">Loading what you can report on…</p></form>
      <section class="rb-result" data-rb-result data-preserve-case aria-live="polite"><div class="rb-empty"><h3>Build your report on the left</h3><p>Choose what the report is about, how to group the rows, what to put side by side and which amounts to add up. Then choose <b>Run report</b>.</p></div></section>
    </div></div>`;

  const sourceOf = key => S.catalogue.sources.find(s => s.key === key);
  const fieldOf = (src, key) => key === 'count' ? {key: 'count', label: 'Number of lines', type: 'integer', kind: 'measure'} : src.fields.find(f => f.key === key);

  function defaultSpec(sourceKey) {
    const src = sourceOf(sourceKey) || S.catalogue.sources[0];
    const dims = src.fields.filter(f => f.kind === 'dim' && f.type === 'text'), meas = src.fields.filter(f => f.kind === 'measure');
    const p = presets[0];
    const start = src.start || {rows: dims.length ? [dims[0].key] : [], values: meas.length ? [meas[0].key] : ['count']};
    return {source: src.key, mode: 'summary', rows: [...start.rows], columns: src.hasDate ? 'period:month' : null, values: [...start.values], fields: [...src.listDefault], filters: [], period: src.hasDate ? {start: p[2], end: p[3]} : null, sort: null, compare: 'none', subtotals: true};
  }

  // ---------- Form ----------
  function renderForm() {
    const sp = S.spec, src = sourceOf(sp.source), dims = src.fields.filter(f => f.kind === 'dim'), meas = [...src.fields.filter(f => f.kind === 'measure'), fieldOf(src, 'count')];
    const opt = (v, l, sel) => `<option value="${esc(v)}" ${sel ? 'selected' : ''}>${esc(l)}</option>`;
    const presetKey = sp.period ? (presets.find(p => p[2] === sp.period.start && p[3] === sp.period.end)?.[0] || 'custom') : '';
    const rowSelects = [0, 1, 2].map(i => `<label class="rb-inline"><span class="sr-only">Row field ${i + 1}</span><select data-rb-row="${i}">${i ? opt('', i === 1 ? '+ Then by…' : '+ Then by…', !sp.rows[i]) : opt('', 'No row grouping', !sp.rows[0])}${dims.map(f => opt(f.key, f.label, sp.rows[i] === f.key)).join('')}</select></label>`).slice(0, Math.min(3, sp.rows.length + 1)).join('');
    const colOptions = `${opt('', 'Nothing side by side', !sp.columns)}${src.hasDate ? `<optgroup label="By period">${S.catalogue.periodColumns.map(p => opt(p.key, p.label, sp.columns === p.key)).join('')}</optgroup>` : ''}<optgroup label="By a field">${dims.filter(f => !sp.rows.includes(f.key)).map(f => opt(f.key, f.label, sp.columns === f.key)).join('')}</optgroup>`;
    const sortKeys = sp.mode === 'list' ? sp.fields.map(k => fieldOf(src, k)).filter(Boolean) : sp.rows.map(k => fieldOf(src, k));
    $('[data-rb-form]').innerHTML = `
      <section class="rb-step"><h3><span>1</span> What is the report about?</h3>
        <select name="source" aria-label="Data">${S.catalogue.sources.map(s => opt(s.key, s.label, s.key === sp.source)).join('')}</select>
        <p class="rb-hint">${esc(src.description)}</p></section>
      ${src.hasDate ? `<section class="rb-step"><h3><span>2</span> Period</h3>
        <select name="preset" aria-label="Period">${presets.map(p => opt(p[0], p[1], p[0] === presetKey)).join('')}${opt('custom', 'Choose dates', presetKey === 'custom')}</select>
        <div class="rb-dates"><label>From <input type="date" name="start" value="${esc(sp.period?.start || '')}" required></label><label>To <input type="date" name="end" value="${esc(sp.period?.end || '')}" required></label></div></section>` : `<section class="rb-step"><h3><span>2</span> Period</h3><p class="rb-hint">This list has no date; it shows the current records.</p></section>`}
      <section class="rb-step"><h3><span>3</span> Layout</h3>
        <div class="rb-seg" role="radiogroup" aria-label="Layout"><label><input type="radio" name="mode" value="summary" ${sp.mode === 'summary' ? 'checked' : ''}> <span>Summary with totals</span></label><label><input type="radio" name="mode" value="list" ${sp.mode === 'list' ? 'checked' : ''}> <span>List of lines</span></label></div></section>
      ${sp.mode === 'summary' ? `
      <section class="rb-step"><h3><span>4</span> Rows</h3><p class="rb-hint">Group the rows by up to three fields. With two or more, each group gets a subtotal.</p>${rowSelects}
        ${sp.rows.length > 1 ? `<label class="rb-check"><input type="checkbox" name="subtotals" ${sp.subtotals ? 'checked' : ''}> Show subtotals</label>` : ''}</section>
      <section class="rb-step"><h3><span>5</span> Columns side by side</h3><select name="columns" aria-label="Columns side by side">${colOptions}</select>
        ${String(sp.columns || '').startsWith('period:') ? `<label class="rb-inline">Compare periods <select name="compare">${opt('none', 'No comparison', sp.compare === 'none')}${opt('change', 'Change from the previous period', sp.compare === 'change')}${opt('percent', '% change from the previous period', sp.compare === 'percent')}</select></label>` : ''}</section>
      <section class="rb-step"><h3><span>6</span> Values to add up</h3><p class="rb-hint">Up to ${S.catalogue.limits.values}.</p><div class="rb-checks">${meas.map(f => `<label class="rb-check"><input type="checkbox" name="values" value="${esc(f.key)}" ${sp.values.includes(f.key) ? 'checked' : ''}> ${esc(f.label)}</label>`).join('')}</div></section>`
      : `<section class="rb-step"><h3><span>4</span> Fields in the list</h3><p class="rb-hint">Up to ${S.catalogue.limits.listFields}, in the order chosen.</p><div class="rb-checks">${src.fields.map(f => `<label class="rb-check"><input type="checkbox" name="fields" value="${esc(f.key)}" ${sp.fields.includes(f.key) ? 'checked' : ''}> ${esc(f.label)}</label>`).join('')}</div></section>`}
      <section class="rb-step"><h3><span>${sp.mode === 'summary' ? 7 : 5}</span> Filters</h3><div data-rb-filters>${sp.filters.map((f, i) => filterRow(src, f, i)).join('')}</div>
        <button type="button" class="srp-btn secondary rb-small" data-rb-add-filter ${sp.filters.length >= S.catalogue.limits.filters ? 'disabled' : ''}>+ Add filter</button></section>
      <section class="rb-step"><h3><span>${sp.mode === 'summary' ? 8 : 6}</span> Sort</h3><div class="rb-row2">
        <select name="sortKey" aria-label="Sort by">${opt('', sp.mode === 'list' ? (src.hasDate ? 'Date order' : 'Default order') : 'Row names A–Z', !sp.sort)}${sortKeys.map(f => opt(f.key, f.label, sp.sort?.key === f.key)).join('')}${sp.mode === 'summary' ? opt('__total', 'Total of the first value', sp.sort?.key === '__total') : ''}</select>
        <select name="sortDir" aria-label="Direction">${opt('asc', 'Ascending', sp.sort?.dir !== 'desc')}${opt('desc', 'Descending', sp.sort?.dir === 'desc')}</select></div></section>
      <div class="rb-run"><button class="srp-btn" type="submit" data-rb-run>Run report</button></div>`;
  }

  function filterRow(src, f, i) {
    const field = fieldOf(src, f.field) || src.fields[0], type = field.type === 'text' ? 'text' : field.type === 'date' ? 'date' : 'number';
    const opt = (v, l, sel) => `<option value="${esc(v)}" ${sel ? 'selected' : ''}>${esc(l)}</option>`;
    const shown = v => v === undefined || v === null ? '' : type === 'number' ? String(Number(v) / scale(field.type)) : String(v);
    let value = '';
    if (['blank', 'notblank'].includes(f.op)) value = '';
    else if (f.op === 'between') value = `<input name="v1" type="${type === 'date' ? 'date' : 'number'}" step="any" value="${esc(shown(f.value?.[0]))}" aria-label="From"><input name="v2" type="${type === 'date' ? 'date' : 'number'}" step="any" value="${esc(shown(f.value?.[1]))}" aria-label="To">`;
    else if (f.op === 'in') value = `<button type="button" class="srp-btn secondary rb-small" data-rb-pick>${Array.isArray(f.value) && f.value.length ? esc(`${f.value.length} chosen`) : 'Choose values…'}</button>`;
    else value = `<input name="v1" type="${type === 'date' ? 'date' : type === 'number' ? 'number' : 'text'}" step="any" value="${esc(shown(f.value))}" aria-label="Value" ${type === 'text' ? `list="rb-dl-${i}"` : ''}>${type === 'text' ? `<datalist id="rb-dl-${i}"></datalist>` : ''}`;
    return `<div class="rb-filter" data-rb-filter="${i}"><select name="field" aria-label="Field">${src.fields.map(x => opt(x.key, x.label, x.key === field.key)).join('')}</select><select name="op" aria-label="Condition">${opsFor(field.type === 'text' ? 'text' : field.type === 'date' ? 'date' : 'number').map(([k, l]) => opt(k, l, k === f.op)).join('')}</select><span class="rb-fv">${value}</span><button type="button" class="rb-x" data-rb-remove-filter aria-label="Remove filter">×</button></div>`;
  }

  // Read the form back into the spec (values are converted to cents / thousandths here).
  function readForm() {
    const form = $('[data-rb-form]'), sp = S.spec, src = sourceOf(sp.source);
    if (form.mode) sp.mode = form.mode.value;
    if (src.hasDate) sp.period = {start: form.start.value, end: form.end.value};
    if (sp.mode === 'summary') {
      sp.rows = $$('[data-rb-row]').map(s => s.value).filter(Boolean).filter((v, i, a) => a.indexOf(v) === i);
      if (form.columns) sp.columns = form.columns.value || null;
      if (sp.columns && sp.rows.includes(sp.columns)) sp.columns = null;
      sp.compare = form.compare && String(sp.columns || '').startsWith('period:') ? form.compare.value : 'none';
      sp.values = $$('input[name=values]:checked').map(x => x.value);
      if (form.subtotals) sp.subtotals = form.subtotals.checked;
    } else {
      const picked = $$('input[name=fields]:checked').map(x => x.value);
      sp.fields = [...sp.fields.filter(k => picked.includes(k)), ...picked.filter(k => !sp.fields.includes(k))];
    }
    sp.filters = $$('[data-rb-filter]').map(row => {
      const i = Number(row.dataset.rbFilter), field = fieldOf(src, $('[name=field]', row).value), op = $('[name=op]', row).value, prev = sp.filters[i] || {};
      const conv = v => field.type === 'text' || field.type === 'date' ? v : (v === '' ? '' : Math.round(Number(v) * scale(field.type)));
      let value = null;
      if (op === 'between') value = [conv($('[name=v1]', row)?.value ?? ''), conv($('[name=v2]', row)?.value ?? '')];
      else if (op === 'in') value = prev.op === 'in' && prev.field === field.key ? prev.value : [];
      else if (!['blank', 'notblank'].includes(op)) value = conv($('[name=v1]', row)?.value ?? '');
      return {field: field.key, op, value};
    });
    const sk = form.sortKey?.value; sp.sort = sk ? {key: sk, dir: form.sortDir.value} : null;
  }

  function payloadSpec() {
    const sp = S.spec, src = sourceOf(sp.source);
    const out = {source: sp.source, mode: sp.mode, filters: sp.filters, sort: sp.sort};
    if (src.hasDate) out.period = sp.period;
    if (sp.mode === 'summary') Object.assign(out, {rows: sp.rows, columns: sp.columns, values: sp.values, compare: sp.compare, subtotals: sp.subtotals});
    else out.fields = sp.fields;
    return out;
  }

  // ---------- Results ----------
  const fmt = (col, v) => {
    if (v === null || v === undefined || v === '') return col.type === 'number' && /change/i.test(col.label) ? '—' : '';
    if (col.type === 'money') return money(Number(v));
    if (col.type === 'number') return /% change/.test(col.label) ? `${Number(v).toLocaleString('en-CA', {maximumFractionDigits: 1})}%` : Number(v).toLocaleString('en-CA', {maximumFractionDigits: 3});
    if (col.type === 'integer') return Number(v).toLocaleString('en-CA');
    return String(v);
  };
  const numeric = c => ['money', 'number', 'integer'].includes(c.type);

  function renderResult(out) {
    S.output = out;
    const layout = out.reportBuilder?.layout || {}, isSummary = layout.mode === 'summary';
    const p = out.parameters || {}, period = out.period?.mode === 'range' ? `${out.period.start} to ${out.period.end}` : 'Current records';
    const rows = out.rows.slice(0, 2000), more = out.rows.length - rows.length;
    const totalRow = isSummary ? layout.grandTotal : out.totals;
    const head = out.columns.map(c => `<th scope="col" class="${numeric(c) ? 'num' : ''}">${esc(c.label)}</th>`).join('');
    const body = rows.map((r, i) => `<tr class="${r.__rowType === 'subtotal' ? 'rb-sub' : ''}" data-rb-i="${i}">${out.columns.map((c, j) => {
      const v = fmt(c, r[c.key]), drill = isSummary && numeric(c) && r[c.key] !== null && r[c.key] !== 0 && !/change/i.test(c.label);
      return j === 0 ? `<th scope="row">${esc(v)}</th>` : `<td class="${numeric(c) ? 'num' : ''}">${drill ? `<button type="button" class="rb-cell" data-rb-drill="${esc(c.key)}" title="Show the lines behind this figure">${esc(v)}</button>` : esc(v)}</td>`;
    }).join('')}</tr>`).join('');
    const foot = out.columns.some(c => numeric(c) && totalRow && totalRow[c.key] !== undefined) ? `<tr>${out.columns.map((c, j) => j === 0 ? `<th scope="row">Total</th>` : `<td class="${numeric(c) ? 'num' : ''}">${numeric(c) && totalRow?.[c.key] !== undefined ? (isSummary && totalRow[c.key] !== 0 && !/change/i.test(c.label) ? `<button type="button" class="rb-cell" data-rb-drill="${esc(c.key)}" data-rb-total>${esc(fmt(c, totalRow[c.key]))}</button>` : esc(fmt(c, totalRow[c.key]))) : ''}</td>`).join('')}</tr>` : '';
    $('[data-rb-result]').innerHTML = `<header class="rb-res-head"><div><h2>${esc(out.title)}</h2>
        <p class="rb-meta">${esc(out.company?.name || '')} · ${esc(period)}${p.rows ? ` · Rows: ${esc(p.rows)}` : ''}${p.columnsSideBySide && p.columnsSideBySide !== 'None' ? ` · Side by side: ${esc(p.columnsSideBySide)}` : ''}</p>
        <p class="rb-meta">Filters: ${esc(p.filtersApplied || 'None')} · ${esc(out.postingScope || '')}</p></div>
        ${ctx.canExport() ? `<details class="rb-dl" data-rb-download><summary class="srp-btn secondary">Download ▾</summary><div role="menu"><button type="button" role="menuitem" data-rb-export="xlsx">Excel (.xlsx)</button><button type="button" role="menuitem" data-rb-export="csv">CSV (.csv)</button><button type="button" role="menuitem" data-rb-export="pdf">PDF</button><button type="button" role="menuitem" data-rb-export="print">Print</button></div></details>` : ''}</header>
      <div class="rb-table-wrap" tabindex="0" role="region" aria-label="${esc(out.title)}"><table class="rb-table" data-rb-table><thead><tr>${head}</tr></thead><tbody>${body || `<tr><td colspan="${out.columns.length}" class="rb-none">No lines match this report.</td></tr>`}</tbody>${foot ? `<tfoot>${foot}</tfoot>` : ''}</table></div>
      ${more > 0 ? `<p class="rb-hint">The first 2,000 of ${out.rows.length.toLocaleString('en-CA')} lines are shown. Download for all of them.</p>` : ''}
      <p class="rb-foot">${esc(out.presentationStatus || 'Management report — unaudited')} · Reference ${esc(out.verifiedOutput?.reference || '')} · ${esc(out.currencyQualifications || '')}${isSummary ? ' · Select a figure to see the lines behind it.' : ''}</p>`;
  }

  function showError(error) {
    $('[data-rb-result]').innerHTML = `<div class="rb-error" role="alert"><h3>The report could not be run</h3><p>${esc(userFacingError(error))}</p>${error?.requestId ? `<p class="rb-hint">Support reference: <code>${esc(error.requestId)}</code></p>` : ''}</div>`;
  }

  async function run() {
    readForm();
    if (S.busy) return; S.busy = true; const btn = $('[data-rb-run]'); if (btn) { btn.disabled = true; btn.textContent = 'Running…'; }
    $('[data-rb-result]').setAttribute('aria-busy', 'true');
    try {
      const def = payloadSpec(); if (S.current?.name) def.title = S.current.name;
      const r = await api('report-builder/run', {method: 'POST', json: {definition: def}});
      renderResult(r.output);
      if (window.innerWidth < 900) $('[data-rb-result]').scrollIntoView({block: 'start', behavior: 'smooth'});
    } catch (e) { showError(e); } finally { S.busy = false; $('[data-rb-result]').removeAttribute('aria-busy'); if (btn) { btn.disabled = false; btn.textContent = 'Run report'; } }
  }

  // Drill-down: the lines behind one figure, as a list with the report's filters plus the row and column of the figure.
  async function drill(colKey, rowIndex, isTotal) {
    const out = S.output, layout = out.reportBuilder.layout, spec = out.reportBuilder.spec, src = sourceOf(spec.source);
    const filters = [...spec.filters]; let period = spec.period;
    if (!isTotal) {
      const row = out.rows[rowIndex];
      let keys = layout.rowKeys[rowIndex];
      if (row.__rowType === 'subtotal') { for (let k = rowIndex - 1; k >= 0; k--) if (layout.rowKeys[k]) { keys = [layout.rowKeys[k][0]]; break; } }
      (keys || []).forEach((v, i) => filters.push({field: spec.rows[i], op: 'eq', value: v}));
    }
    const m = /^[cx](\d+)_m\d+$/.exec(colKey);
    if (m && spec.columns) { const b = layout.buckets[Number(m[1])]; if (String(spec.columns).startsWith('period:')) period = {start: b.start, end: b.end}; else filters.push({field: spec.columns, op: 'eq', value: b.key}); }
    const def = {source: spec.source, mode: 'list', fields: src.listDefault, filters: filters.slice(0, S.catalogue.limits.filters + 4), sort: null}; if (src.hasDate) def.period = period;
    const scrim = document.createElement('div'); scrim.className = 'srp-modal-scrim';
    scrim.innerHTML = `<section class="srp-modal rb-drill" role="dialog" aria-modal="true" aria-labelledby="rb-drill-title"><header class="rb-drill-head"><h2 id="rb-drill-title">Lines behind this figure</h2><button type="button" class="rb-x" data-rb-close aria-label="Close">×</button></header><div class="rb-drill-body" role="status">Loading the lines…</div></section>`;
    document.body.append(scrim); document.body.classList.add('srp-modal-open');
    const close = () => { scrim.remove(); if (!document.querySelector('.srp-modal-scrim')) document.body.classList.remove('srp-modal-open'); };
    scrim.addEventListener('click', e => { if (e.target === scrim || e.target.closest('[data-rb-close]')) close(); });
    scrim.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
    $('[data-rb-close]', scrim).focus();
    try {
      if (def.filters.length > S.catalogue.limits.filters) throw Object.assign(Error('This figure has too many conditions to list. Open it as a list and remove a filter.'), {});
      const r = (await api('report-builder/run', {method: 'POST', json: {definition: def}})).output;
      const shown = r.rows.slice(0, 500);
      $('.rb-drill-body', scrim).innerHTML = `<p class="rb-meta">${esc(String(r.rowCount))} line${r.rowCount === 1 ? '' : 's'} · ${esc(r.parameters?.filtersApplied || '')}${r.period?.mode === 'range' ? ` · ${esc(r.period.start)} to ${esc(r.period.end)}` : ''}</p>
        <div class="rb-table-wrap"><table class="rb-table"><thead><tr>${r.columns.map(c => `<th class="${numeric(c) ? 'num' : ''}">${esc(c.label)}</th>`).join('')}</tr></thead><tbody>${shown.map(x => `<tr>${r.columns.map(c => `<td class="${numeric(c) ? 'num' : ''}">${esc(fmt(c, x[c.key]))}</td>`).join('')}</tr>`).join('')}</tbody>${Object.keys(r.totals || {}).length ? `<tfoot><tr>${r.columns.map((c, j) => `<td class="${numeric(c) ? 'num' : ''}">${j === 0 ? 'Total' : r.totals[c.key] !== undefined ? esc(fmt(c, r.totals[c.key])) : ''}</td>`).join('')}</tr></tfoot>` : ''}</table></div>
        ${r.rows.length > shown.length ? `<p class="rb-hint">The first 500 lines are shown. Download for all ${esc(String(r.rowCount))}.</p>` : ''}
        <footer class="rb-drill-foot">${ctx.canExport() ? `<button type="button" class="srp-btn secondary" data-rb-drill-csv>Download CSV</button>` : ''}<button type="button" class="srp-btn secondary" data-rb-drill-open>Open as a list report</button><button type="button" class="srp-btn" data-rb-close>Close</button></footer>`;
      $('[data-rb-drill-csv]', scrim)?.addEventListener('click', () => download(window.TeghRegistersR23.csv(r), 'Tegh-Report-lines.csv'));
      $('[data-rb-drill-open]', scrim).addEventListener('click', () => { S.spec = {...defaultSpec(def.source), ...def, filters: def.filters}; close(); renderForm(); run(); });
    } catch (e) { $('.rb-drill-body', scrim).innerHTML = `<div class="rb-error" role="alert"><p>${esc(userFacingError(e))}</p></div><footer class="rb-drill-foot"><button type="button" class="srp-btn" data-rb-close>Close</button></footer>`; }
  }

  function download(blob, name) { const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name; document.body.append(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1500); }

  async function exportAs(kind) {
    const out = S.output; if (!out) return;
    const P = window.TeghProfessionalOutput, name = 'Tegh-' + String(out.title || 'Report').replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '');
    const model = structuredClone(out); model.rows = model.rows.map(({__rowType, ...r}) => r);
    try {
      if (kind === 'xlsx') download(P.reportXlsx(model), name + '.xlsx');
      else if (kind === 'csv') download(window.TeghRegistersR23.csv(model), name + '.csv');
      // PDF and print use Tegh's page layout: up to 12 columns print as a table; a wider report prints each row's
      // figures as labelled details (Excel keeps every column side by side).
      const printable = window.TeghRegistersR23.printModel(structuredClone(model)), wide = printable.columns.length === 2 && printable.columns[0].key === 'field';
      if (kind === 'pdf') download(await P.pdfReport(printable), name + '.pdf');
      else if (kind === 'print') { const w = window.open('', '_blank'); if (!w) throw Error('Allow pop-ups to open the print view.'); w.document.open(); w.document.write(P.reportPrintHtml(printable)); w.document.close(); w.focus(); setTimeout(() => w.print(), 250); }
      toast('Download ready', `${out.title} · ${kind === 'xlsx' ? 'Excel' : kind.toUpperCase()}${wide && (kind === 'pdf' || kind === 'print') ? ' — this report is wider than a page, so each row is listed with its figures. Excel keeps the columns side by side.' : ''}`, 'success');
    } catch (e) { toast('Download failed', userFacingError(e), 'error'); }
  }

  // ---------- Saved reports ----------
  function renderSaved() {
    const sel = $('[data-rb-saved]'), mine = S.saved.filter(r => r.mine), shared = S.saved.filter(r => !r.mine);
    const o = r => `<option value="${r.id}" ${S.current?.id === r.id ? 'selected' : ''}>${ctx.esc(r.name)}${r.visibility === 'company' && r.mine ? ' (shared)' : ''}</option>`;
    sel.innerHTML = `<option value="">New report</option>${mine.length ? `<optgroup label="My reports">${mine.map(o).join('')}</optgroup>` : ''}${shared.length ? `<optgroup label="Shared with the company">${shared.map(r => o({...r, name: `${r.name} — ${r.owner}`})).join('')}</optgroup>` : ''}`;
    $('[data-rb-name]').textContent = S.current ? S.current.name + (S.current.visibility === 'company' ? ' · shared with the company' : ' · private') : 'New report';
    $('[data-rb-delete]').hidden = !S.current?.canDelete;
    $('[data-rb-save]').disabled = !!S.current && !S.current.canEdit;
    $('[data-rb-save]').title = S.current && !S.current.canEdit ? 'Only its creator can change this report. Use Save as new.' : '';
  }
  async function loadSaved() { try { S.saved = (await api('report-builder/reports', {noCache: true})).reports || []; } catch { S.saved = []; } renderSaved(); }

  function saveDialog(asNew) {
    readForm();
    const cur = asNew ? null : S.current, opKey = newKey();
    const scrim = document.createElement('div'); scrim.className = 'srp-modal-scrim';
    scrim.innerHTML = `<form class="srp-modal rb-save" role="dialog" aria-modal="true" aria-labelledby="rb-save-title"><h2 id="rb-save-title">${cur ? 'Save changes' : 'Save report'}</h2>
      <label>Name <input name="name" maxlength="120" required value="${esc(cur?.name || (S.current && asNew ? S.current.name + ' (copy)' : ''))}"></label>
      <label>Description <input name="description" maxlength="500" value="${esc(cur?.description || '')}"></label>
      <fieldset><legend>Who can open it</legend><label class="rb-check"><input type="radio" name="visibility" value="private" ${(cur?.visibility || 'private') === 'private' ? 'checked' : ''}> Only me</label><label class="rb-check"><input type="radio" name="visibility" value="company" ${cur?.visibility === 'company' ? 'checked' : ''}> Everyone in this company (each person sees only what their role allows)</label></fieldset>
      <p class="rb-error" data-rb-save-error hidden></p>
      <footer class="rb-drill-foot"><button type="button" class="srp-btn secondary" data-rb-cancel>Cancel</button><button class="srp-btn" type="submit">Save</button></footer></form>`;
    document.body.append(scrim); document.body.classList.add('srp-modal-open');
    const close = () => { scrim.remove(); if (!document.querySelector('.srp-modal-scrim')) document.body.classList.remove('srp-modal-open'); };
    const form = $('form', scrim); form.name.focus();
    $('[data-rb-cancel]', scrim).onclick = close;
    form.onsubmit = async e => {
      e.preventDefault(); const btn = $('button[type=submit]', form); btn.disabled = true;
      const body = {name: form.name.value.trim(), description: form.description.value.trim(), visibility: form.visibility.value, definition: payloadSpec()};
      try {
        const r = cur ? await api('report-builder/reports/' + cur.id, {method: 'PUT', json: {...body, revision: cur.revision}}) : await api('report-builder/reports', {method: 'POST', json: {...body, operationKey: opKey}});
        S.current = r.report; close(); await loadSaved(); toast('Report saved', r.report.name, 'success');
      } catch (err) { const p = $('[data-rb-save-error]', scrim); p.hidden = false; p.textContent = userFacingError(err); btn.disabled = false; }
    };
  }

  // ---------- Wiring ----------
  body.addEventListener('change', e => {
    const t = e.target, form = $('[data-rb-form]');
    if (!form.contains(t)) return;
    if (t.name === 'source') { S.spec = defaultSpec(t.value); renderForm(); return; }
    if (t.name === 'preset' && t.value !== 'custom') { const p = presets.find(x => x[0] === t.value); form.start.value = p[2]; form.end.value = p[3]; }
    if ((t.name === 'start' || t.name === 'end') && form.preset) form.preset.value = presets.find(p => p[2] === form.start.value && p[3] === form.end.value)?.[0] || 'custom';
    if (t.name === 'values') { const on = $$('input[name=values]:checked'); if (on.length > S.catalogue.limits.values) { t.checked = false; toast('Up to ' + S.catalogue.limits.values + ' values', 'Remove one before adding another.', 'warning'); } }
    if (t.name === 'fields') { const on = $$('input[name=fields]:checked'); if (on.length > S.catalogue.limits.listFields) { t.checked = false; toast('Up to ' + S.catalogue.limits.listFields + ' fields', 'Remove one before adding another.', 'warning'); } }
    if (['mode', 'columns', 'field', 'op'].includes(t.name) || t.matches('[data-rb-row]')) {
      if (t.name === 'field') { readForm(); const i = Number(t.closest('[data-rb-filter]').dataset.rbFilter), f = fieldOf(sourceOf(S.spec.source), t.value); S.spec.filters[i] = {field: t.value, op: opsFor(f.type === 'text' ? 'text' : f.type === 'date' ? 'date' : 'number')[0][0], value: null}; renderForm(); return; }
      readForm(); renderForm();
    }
  });
  body.addEventListener('focusin', async e => {
    const input = e.target; if (!input.matches?.('[data-rb-filter] input[list]')) return;
    const row = input.closest('[data-rb-filter]'), field = $('[name=field]', row).value, dl = $('datalist', row); if (dl.dataset.loaded) return; dl.dataset.loaded = '1';
    try { const r = await api(`report-builder/values?source=${encodeURIComponent(S.spec.source)}&field=${encodeURIComponent(field)}`); dl.innerHTML = r.values.map(v => `<option value="${esc(v.value)}">${esc(v.label)}</option>`).join(''); } catch {}
  });
  body.addEventListener('click', async e => {
    const t = e.target.closest('button'); if (!t || !body.contains(t)) return;
    if (t.matches('[data-rb-add-filter]')) { readForm(); const src = sourceOf(S.spec.source), f = src.fields.find(x => x.kind === 'dim' && x.type === 'text') || src.fields[0]; S.spec.filters.push({field: f.key, op: f.type === 'text' ? 'eq' : 'eq', value: null}); renderForm(); $$('[data-rb-filter]').at(-1)?.querySelector('input,select')?.focus(); }
    else if (t.matches('[data-rb-remove-filter]')) { readForm(); S.spec.filters.splice(Number(t.closest('[data-rb-filter]').dataset.rbFilter), 1); renderForm(); }
    else if (t.matches('[data-rb-pick]')) pickValues(Number(t.closest('[data-rb-filter]').dataset.rbFilter));
    else if (t.matches('[data-rb-drill]')) drill(t.dataset.rbDrill, Number(t.closest('tr').dataset.rbI), t.hasAttribute('data-rb-total'));
    else if (t.matches('[data-rb-export]')) { t.closest('details')?.removeAttribute('open'); exportAs(t.dataset.rbExport); }
    else if (t.matches('[data-rb-new]')) { S.current = null; S.spec = defaultSpec(S.spec.source); renderSaved(); renderForm(); }
    else if (t.matches('[data-rb-save]')) { if (S.current?.canEdit) saveDialog(false); else saveDialog(true); }
    else if (t.matches('[data-rb-saveas]')) saveDialog(true);
    else if (t.matches('[data-rb-delete]')) {
      if (!S.current) return;
      const msg = `Delete the report "${S.current.name}"? This removes the saved layout only; it does not change the books.`;
      if (!(window.TeghConfirm ? await window.TeghConfirm(msg) : confirm(msg))) return;
      try { await api('report-builder/reports/' + S.current.id, {method: 'DELETE'}); toast('Report deleted', S.current.name, 'success'); S.current = null; await loadSaved(); } catch (err) { toast('Could not delete', userFacingError(err), 'error'); }
    }
  });
  body.addEventListener('submit', e => { if (e.target.matches('[data-rb-form]')) { e.preventDefault(); run(); } });
  $('[data-rb-saved]').addEventListener('change', async e => {
    const id = e.target.value; if (!id) { S.current = null; renderSaved(); return; }
    const r = S.saved.find(x => x.id === id); if (!r) return;
    S.current = r; const d = r.definition; S.spec = {...defaultSpec(d.source), ...d, filters: d.filters || [], fields: d.fields?.length ? d.fields : defaultSpec(d.source).fields};
    renderSaved(); renderForm(); run();
  });

  async function pickValues(i) {
    readForm(); const f = S.spec.filters[i], chosen = new Set(Array.isArray(f.value) ? f.value : []);
    const scrim = document.createElement('div'); scrim.className = 'srp-modal-scrim';
    scrim.innerHTML = `<section class="srp-modal rb-pick" role="dialog" aria-modal="true" aria-labelledby="rb-pick-title"><h2 id="rb-pick-title">Choose values</h2><input type="search" placeholder="Search" aria-label="Search values" data-rb-q><div class="rb-pick-list" data-rb-list role="status">Loading…</div><footer class="rb-drill-foot"><button type="button" class="srp-btn secondary" data-rb-cancel>Cancel</button><button type="button" class="srp-btn" data-rb-ok>Use these</button></footer></section>`;
    document.body.append(scrim); document.body.classList.add('srp-modal-open');
    const close = () => { scrim.remove(); if (!document.querySelector('.srp-modal-scrim')) document.body.classList.remove('srp-modal-open'); };
    const load = async q => { try { const r = await api(`report-builder/values?source=${encodeURIComponent(S.spec.source)}&field=${encodeURIComponent(f.field)}${q ? '&q=' + encodeURIComponent(q) : ''}`); $('[data-rb-list]', scrim).innerHTML = r.values.map(v => `<label class="rb-check"><input type="checkbox" value="${esc(v.value)}" ${chosen.has(v.value) ? 'checked' : ''}> ${esc(v.label || '(blank)')}</label>`).join('') + (r.more ? '<p class="rb-hint">More values exist; search to narrow.</p>' : '') || '<p class="rb-hint">No values.</p>'; } catch (e) { $('[data-rb-list]', scrim).textContent = userFacingError(e); } };
    $('[data-rb-list]', scrim).addEventListener('change', e => { if (e.target.checked) chosen.add(e.target.value); else chosen.delete(e.target.value); });
    let timer; $('[data-rb-q]', scrim).addEventListener('input', e => { clearTimeout(timer); timer = setTimeout(() => load(e.target.value.trim()), 250); });
    $('[data-rb-cancel]', scrim).onclick = close;
    $('[data-rb-ok]', scrim).onclick = () => { S.spec.filters[i] = {...f, op: 'in', value: [...chosen].slice(0, 200)}; close(); renderForm(); };
    $('[data-rb-q]', scrim).focus(); load('');
  }

  // ---------- Start ----------
  (async () => {
    try { S.catalogue = await api('report-builder/catalogue', {noCache: true}); }
    catch (e) { $('[data-rb-form]').innerHTML = `<div class="rb-error" role="alert"><p>${esc(userFacingError(e))}</p></div>`; return; }
    if (!S.catalogue.sources.length) { $('[data-rb-form]').innerHTML = '<p class="rb-hint">Your company role does not allow any reports.</p>'; return; }
    S.spec = defaultSpec(S.catalogue.sources[0].key); renderForm(); await loadSaved();
  })();
  return {state: S, run};
}

window.TeghReportBuilder = {mount};
