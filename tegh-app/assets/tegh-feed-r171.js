/* R171 Company feed: what is happening in the selected company, as a short stream of posts.
 *
 * Desktop: a small window docked at the bottom right of the Dashboard (and Guided Home); it can be shrunk to a pill.
 * Phone: a "Feed" pill that opens the stream as a full-height sheet.
 * Posts come from GET activity-feed (the company's audit trail, filtered to business events by the server). Every
 * value is escaped before it is shown; record names and memos are data, never markup. New posts are fetched every
 * 45 seconds while the window is on screen and the tab is visible.
 */
(() => {
  'use strict';
  if (window.TeghCompanyFeed) return;
  const POLL_MS = 45000;
  const PREF_KEY = 'tegh-feed-r171';
  const FILTERS = [['all', 'All'], ['sales', 'Sales'], ['purchases', 'Purchases'], ['banking', 'Banking'], ['ledger', 'Ledger'], ['notes', 'Notes'], ['payroll', 'Payroll'], ['company', 'Company']];
  const CATEGORY_LABEL = Object.fromEntries(FILTERS);
  const ICONS = {
    sales: '<path d="M4 4h12l4 4v12H4z"/><path d="M8 12h8M8 16h5"/>',
    purchases: '<path d="M3 6h18l-2 10H5z"/><circle cx="8" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/>',
    notes: '<path d="M6 3h9l3 3v15H6z"/><path d="M9 11h6M9 15h4"/>',
    banking: '<path d="M3 10 12 4l9 6"/><path d="M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 20h18"/>',
    ledger: '<path d="M5 4h14v16H5z"/><path d="M9 4v16M12 9h4M12 13h4"/>',
    payroll: '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c1.2-3.6 3.8-5 7-5s5.8 1.4 7 5"/>',
    company: '<path d="M4 20V6l8-3 8 3v14"/><path d="M9 20v-5h6v5"/>',
  };
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[ch]));
  const pref = () => { try { return JSON.parse(localStorage.getItem(PREF_KEY) || '{}') || {}; } catch (_) { return {}; } };
  const savePref = patch => { try { localStorage.setItem(PREF_KEY, JSON.stringify({...pref(), ...patch})); } catch (_) {} };
  const isPhone = () => window.matchMedia('(max-width: 899px)').matches;
  // Shown on the Dashboard and Guided Home: the page on screen decides (the address can lag behind it).
  const onHome = () => {
    const page = [...document.querySelectorAll('.srp-page')].pop()?.dataset.srpPage;
    return page ? ['dashboard', 'guided-bookkeeping'].includes(page) : /^#tegh=(dashboard|guided-bookkeeping)(?:&|$)/.test(location.hash);
  };
  // The open window keeps a strip on the right of the Dashboard free when there is room for the page beside it
  // (page at least 1000px wide), so it never covers a button. Where there is no room it starts as the pill, and if it
  // is opened there it floats over the page until it is minimised.
  const GUTTER = 380, MIN_PAGE = 1000;
  const homePage = () => [...document.querySelectorAll('.srp-page')].pop();
  const fits = () => { const page = homePage(); const left = page ? page.getBoundingClientRect().left : 0; return window.innerWidth - Math.max(0, left) - GUTTER >= MIN_PAGE; };
  const companyFromHash = () => (/[#&]co=([^&]+)/.exec(location.hash) || [])[1] || '';

  let ctx = null, root = null, pill = null, timer = 0, loading = false;
  const state = {company: '', items: [], next: null, latest: null, filter: 'all', others: false, error: '', highlight: new Set()};

  const money = amount => {
    if (!amount || typeof amount.cents !== 'number') return '';
    try { return new Intl.NumberFormat('en-CA', {style: 'currency', currency: amount.currency || 'CAD'}).format(Math.abs(amount.cents) / 100); } catch (_) { return (Math.abs(amount.cents) / 100).toFixed(2); }
  };
  const when = iso => {
    const at = new Date(iso), diff = (Date.now() - at.getTime()) / 1000;
    if (!Number.isFinite(diff)) return '';
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
    if (diff < 6 * 3600) return Math.floor(diff / 3600) + ' h ago';
    return at.toLocaleTimeString('en-CA', {hour: 'numeric', minute: '2-digit'});
  };
  const dayLabel = iso => {
    const at = new Date(iso), today = new Date(), y = new Date(); y.setDate(today.getDate() - 1);
    if (at.toDateString() === today.toDateString()) return 'Today';
    if (at.toDateString() === y.toDateString()) return 'Yesterday';
    return at.toLocaleDateString('en-CA', {weekday: 'short', month: 'short', day: 'numeric', year: at.getFullYear() === today.getFullYear() ? undefined : 'numeric'});
  };
  const initials = name => String(name || '?').split(/[\s._-]+/).filter(Boolean).slice(0, 2).map(p => p[0]).join('').toUpperCase() || '?';
  const tone = name => { let h = 0; for (const ch of String(name)) h = (h * 31 + ch.charCodeAt(0)) >>> 0; return h % 6; };

  // Posts of the same kind by the same person within 15 minutes become one post ("posted 12 bank transactions").
  const groupNames = {
    'bank_transaction.posted': ['posted a bank transaction', 'posted %n bank transactions'],
    'customer.payment_posted': ['recorded a customer payment', 'recorded %n customer payments'],
    'vendor.payment_posted': ['paid a supplier', 'recorded %n supplier payments'],
    'customer.created': ['added a customer', 'added %n customers'],
    'vendor.created': ['added a supplier', 'added %n suppliers'],
    'invoice_intel.uploaded': ['uploaded a supplier invoice', 'uploaded %n supplier invoices for Invoice Intelligence to read'],
    'native_document.uploaded': ['uploaded a document', 'uploaded %n documents to Document Intake'],
  };
  const grouped = items => {
    const out = [];
    for (const item of items) {
      const prev = out[out.length - 1];
      if (item.group && prev && prev.group === item.group && prev.actor.name === item.actor.name && prev.actor.you === item.actor.you
        && Math.abs(new Date(prev.members[prev.members.length - 1].at) - new Date(item.at)) <= 15 * 60000 && dayLabel(prev.at) === dayLabel(item.at)) {
        prev.members.push(item);
      } else out.push({...item, members: [item]});
    }
    return out;
  };

  const lastSeenKey = () => 'seen:' + state.company;
  const lastSeen = () => pref()[lastSeenKey()] || '';
  const markSeen = () => { if (state.latest && root && root.dataset.open === '1' && document.visibilityState === 'visible') savePref({[lastSeenKey()]: state.latest}); };
  const unseen = item => { const seen = lastSeen(); return !item.actor.you && seen !== '' && item.cursor > seen; };
  // Posts that were new when the window was opened stay marked while it is open.
  const isNew = item => state.highlight.has(item.id) || unseen(item);

  function postMarkup(post, index, total) {
    const many = post.members.length > 1;
    const names = groupNames[post.group];
    const text = many && names ? names[1].replace('%n', String(post.members.length)) : post.text;
    const fresh = post.members.some(isNew);
    const link = !many && post.link ? ` data-feed-open="${esc(post.link.type)}" data-feed-id="${esc(post.link.id)}"` : '';
    const amount = !many && post.amount ? `<span class="tf-amount${post.amount.cents < 0 ? ' is-out' : ''}">${post.amount.cents < 0 ? '−' : ''}${esc(money(post.amount))}</span>` : '';
    const list = many ? `<details class="tf-more"><summary>Show all ${post.members.length}</summary><ul>${post.members.map(m => `<li>${m.link ? `<button type="button" class="tf-sub" data-feed-open="${esc(m.link.type)}" data-feed-id="${esc(m.link.id)}">` : '<span class="tf-sub">'}${esc(m.text.replace(/^posted a bank transaction: /, ''))}${m.amount ? ` <b>${m.amount.cents < 0 ? '−' : ''}${esc(money(m.amount))}</b>` : ''}${m.link ? '</button>' : '</span>'}</li>`).join('')}</ul></details>` : '';
    return `<article class="tf-post${fresh ? ' is-new' : ''}${link ? ' is-link' : ''}" aria-posinset="${index + 1}" aria-setsize="${total}" data-feed-post="${esc(post.id)}"${link} ${link ? 'tabindex="0" role="button"' : 'tabindex="-1"'}>
      <span class="tf-avatar tf-tone-${tone(post.actor.name)}" aria-hidden="true">${esc(initials(post.actor.name))}</span>
      <div class="tf-body">
        <p class="tf-line"><b>${post.actor.you ? 'You' : esc(post.actor.name)}</b> ${esc(text)}${fresh ? '<span class="tf-new">New</span>' : ''}</p>
        ${post.detail && !many ? `<p class="tf-detail">${esc(post.detail)}</p>` : ''}
        <p class="tf-meta"><svg viewBox="0 0 24 24" aria-hidden="true">${ICONS[post.category] || ''}</svg><span>${esc(CATEGORY_LABEL[post.category] || '')}</span><span aria-hidden="true">·</span><time datetime="${esc(post.at)}">${esc(when(post.at))}</time></p>
        ${list}
      </div>
      ${amount}
    </article>`;
  }

  function listMarkup() {
    if (state.error && !state.items.length) return `<p class="tf-empty" role="alert">${esc(state.error)} <button type="button" class="tf-link" data-feed-retry>Try again</button></p>`;
    if (!state.items.length) return loading ? '<p class="tf-empty">Loading…</p>' : `<p class="tf-empty">${state.filter === 'all' && !state.others ? 'Nothing has happened in this company yet. Invoices, payments, bank work and closing will appear here as they happen.' : 'Nothing matches this filter yet.'}</p>`;
    const posts = grouped(state.items);
    let day = '', html = '';
    posts.forEach((post, i) => {
      const label = dayLabel(post.at);
      if (label !== day) { day = label; html += `<h4 class="tf-day">${esc(label)}</h4>`; }
      html += postMarkup(post, i, posts.length);
    });
    return html + (state.next ? `<button type="button" class="tf-older" data-feed-older${loading ? ' disabled' : ''}>${loading ? 'Loading…' : 'Show older activity'}</button>` : '<p class="tf-end">That’s everything.</p>');
  }

  // The pages draw their heading after the feed starts, and redraw it; put the pill back each time.
  function placePill() {
    const head = homePage()?.querySelector('header.srp-page-head, .tegh-guided-home-head');
    if (head && pill && pill.parentElement !== head) head.appendChild(pill);
    return head;
  }

  function render() {
    if (!root) return;
    const phone = isPhone();
    // Open or not is decided once the page is on screen: open where it can sit beside the page (unless the person
    // minimised it), otherwise the Company feed button. Opened by hand on a narrow screen, it floats until minimised.
    if (!phone && onHome() && homePage() && root.dataset.userOpened !== '1') root.dataset.open = fits() && pref().desktopOpen !== false ? '1' : '0';
    if (phone && root.dataset.userOpened !== '1') root.dataset.open = '0';
    const open = root.dataset.open === '1';
    root.classList.toggle('is-phone', phone);
    root.hidden = !onHome();
    const docked = !root.hidden && open && !phone && fits();
    root.classList.toggle('is-docked', docked);
    root.classList.toggle('is-floating', !root.hidden && open && !phone && !docked);
    document.documentElement.classList.toggle('tf-docked', docked);

    const fresh = state.items.filter(isNew).length;
    // The pill is an ordinary button in the page heading, so it never sits over the page.
    const head = placePill();
    pill.hidden = root.hidden || !head || (open && !phone && docked);
    pill.querySelector('[data-feed-pill-count]').textContent = fresh ? String(fresh > 99 ? '99+' : fresh) : '';
    pill.setAttribute('aria-label', fresh ? `Company feed, ${fresh} new` : 'Company feed');
    pill.setAttribute('aria-expanded', String(open));
    root.querySelector('[data-feed-panel]').hidden = !open;
    root.querySelector('[data-feed-company]').textContent = ctx?.companyName?.() || '';
    root.querySelectorAll('[data-feed-filter]').forEach(b => { const on = b.dataset.feedFilter === state.filter; b.classList.toggle('is-active', on); b.setAttribute('aria-pressed', String(on)); });
    root.querySelector('[data-feed-others]').checked = state.others;
    const list = root.querySelector('[data-feed-list]');
    list.setAttribute('aria-busy', String(loading));
    list.innerHTML = listMarkup();
  }

  async function load(mode = 'reset') {
    if (!ctx || loading && mode !== 'reset') return;
    const company = companyFromHash() || ctx.companyId?.() || '';
    if (!company) return;
    if (company !== state.company) { state.company = company; mode = 'reset'; }
    const params = {limit: 30};
    if (state.filter !== 'all') params.category = state.filter;
    if (state.others) params.who = 'others';
    if (mode === 'older' && state.next) params.before = state.next;
    if (mode === 'newer') { if (!state.latest) return load('reset'); params.after = state.latest; }
    const token = state.company + '|' + state.filter + '|' + state.others;
    if (mode === 'reset') { state.items = []; state.next = null; state.latest = null; state.highlight = new Set(); }
    loading = true; state.error = ''; if (mode !== 'newer') render();
    try {
      const data = await ctx.api('activity-feed', {params, noCache: true});
      if (token !== state.company + '|' + state.filter + '|' + state.others) return;
      const items = (data.items || []).map(item => ({...item, cursor: item.at.replace('T', ' ').replace('Z', '') + '|' + item.id}));
      if (mode === 'newer') {
        const known = new Set(state.items.map(i => i.id));
        const added = items.filter(i => !known.has(i.id));
        if (added.length) {
          state.items = added.concat(state.items);
          added.filter(unseen).forEach(i => state.highlight.add(i.id));
          const live = root?.querySelector('[data-feed-live]');
          const others = added.filter(i => !i.actor.you).length;
          if (live && others) live.textContent = `${others} new post${others === 1 ? '' : 's'} in the company feed.`;
        }
        if (data.latest) state.latest = data.latest;
      } else {
        state.items = mode === 'older' ? state.items.concat(items) : items;
        state.next = data.next || null;
        if (mode === 'reset') state.latest = data.latest || null;
        if (mode === 'reset' && !lastSeen() && state.latest) savePref({[lastSeenKey()]: state.latest});
      }
    } catch (error) {
      state.error = error?.message || 'The feed could not be loaded.';
    } finally {
      loading = false; render(); markSeen();
    }
  }

  function setOpen(open, focus = true) {
    if (!root) return;
    if (!open) state.highlight = new Set();
    // Opened by hand where it cannot sit beside the page: it floats until minimised. Where it fits, the saved choice decides.
    root.dataset.userOpened = open && !isPhone() && !fits() ? '1' : open && isPhone() ? '1' : '';
    root.dataset.open = open ? '1' : '0';
    if (!isPhone()) savePref({desktopOpen: open});
    document.documentElement.classList.toggle('tf-sheet-open', open && isPhone());
    render();
    if (open) { state.items.filter(unseen).forEach(i => state.highlight.add(i.id)); markSeen(); if (focus) root.querySelector(isPhone() ? '[data-feed-close]' : '[data-feed-filter].is-active')?.focus(); }
    else if (focus && pill && !pill.hidden) pill.focus();
    if (open) render();
  }

  function schedule() {
    clearInterval(timer);
    timer = setInterval(() => { if (root && !root.hidden && document.visibilityState === 'visible') load('newer'); }, POLL_MS);
  }

  function sync() {
    if (!root) return;
    const show = onHome();
    const company = companyFromHash() || ctx?.companyId?.() || '';
    root.hidden = !show;
    if (!show) { root.dataset.userOpened = ''; document.documentElement.classList.remove('tf-sheet-open', 'tf-docked'); if (pill) pill.hidden = true; return; }
    if (company && (company !== state.company || !state.items.length && !loading && !state.error)) load('reset'); else render();
  }

  function build() {
    root = document.createElement('aside');
    root.className = 'tf-root';
    root.setAttribute('aria-label', 'Company feed');
    root.dataset.companyFeed = '';
    root.dataset.open = '0';
    root.innerHTML = `
      <section class="tf-panel" id="tf-panel" data-feed-panel role="dialog" aria-modal="false" aria-labelledby="tf-title" hidden>
        <header class="tf-head">
          <div><h3 id="tf-title"><span class="tf-dot" aria-hidden="true"></span>Company feed</h3><p data-feed-company></p></div>
          <div class="tf-head-actions">
            <button type="button" class="tf-icon" data-feed-refresh aria-label="Check for new activity" title="Check for new activity"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 4v7h-7"/></svg></button>
            <button type="button" class="tf-icon" data-feed-close aria-label="Minimize the feed" title="Minimize"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 12h12"/></svg></button>
          </div>
        </header>
        <div class="tf-tools">
          <div class="tf-chips" role="group" aria-label="Show">${FILTERS.filter(([key]) => key !== 'payroll' || ctx?.canSeePayroll?.()).map(([key, label]) => `<button type="button" class="tf-chip" data-feed-filter="${key}" aria-pressed="false">${label}</button>`).join('')}</div>
          <label class="tf-toggle"><input type="checkbox" data-feed-others> Only other people</label>
        </div>
        <div class="tf-list" role="feed" aria-label="Recent activity" data-feed-list></div>
        <p class="tf-foot">From the company’s audit history. Shows what was done, not the full record.</p>
      </section>
      <p class="tf-sr" aria-live="polite" data-feed-live></p>`;
    document.body.appendChild(root);
    pill = document.createElement('button');
    pill.type = 'button'; pill.className = 'tf-pill'; pill.dataset.feedPill = ''; pill.setAttribute('aria-controls', 'tf-panel');
    pill.innerHTML = '<span class="tf-dot" aria-hidden="true"></span>Company feed<span class="tf-count" data-feed-pill-count></span>';
    pill.addEventListener('click', () => setOpen(root.dataset.open !== '1'));
    root.addEventListener('click', event => {
      const t = event.target.closest('button,[data-feed-open]');
      if (!t || !root.contains(t)) return;
      if (t.matches('[data-feed-close]')) { setOpen(false); return; }
      if (t.matches('[data-feed-refresh],[data-feed-retry]')) { load(state.items.length ? 'newer' : 'reset'); return; }
      if (t.matches('[data-feed-older]')) { load('older'); return; }
      if (t.matches('[data-feed-filter]')) { state.filter = t.dataset.feedFilter; load('reset'); return; }
      if (t.matches('[data-feed-open]')) { event.preventDefault(); if (isPhone()) setOpen(false, false); ctx.openRecord(t.dataset.feedOpen, t.dataset.feedId); }
    });
    root.addEventListener('keydown', event => {
      if (event.key === 'Escape' && root.dataset.open === '1') { event.stopPropagation(); setOpen(false); }
      if ((event.key === 'Enter' || event.key === ' ') && event.target.matches('article[data-feed-open]')) { event.preventDefault(); event.target.click(); }
    });
    root.querySelector('[data-feed-others]').addEventListener('change', e => { state.others = e.target.checked; load('reset'); });
    window.addEventListener('hashchange', sync);
    window.addEventListener('resize', () => { root.classList.toggle('is-phone', isPhone()); render(); });
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && root && !root.hidden) load('newer'); });
    // The portal redraws pages without always changing the address; watch the page stack as well.
    const stage = document.querySelector('.stage') || document.body;
    new MutationObserver(() => {
      const hidden = !onHome();
      if (hidden !== root.hidden) { sync(); return; }
      if (!hidden && pill && !pill.isConnected) render();
    }).observe(stage, {childList: true, subtree: true});
    setInterval(() => { root.querySelectorAll('time[datetime]').forEach(t => { t.textContent = when(t.getAttribute('datetime')); }); }, 60000);
  }

  window.TeghCompanyFeed = {
    // ctx: {api, openRecord(type,id), companyId(), companyName(), canSeePayroll()}
    mount(context) {
      ctx = context;
      if (!root) { build(); schedule(); }
      sync();
    },
    refresh() { return load('newer'); },
    state: () => ({...state, items: state.items.length}),
  };
})();
