/* Tegh 5.7.0 — scoped interface enhancements */
(() => {
  'use strict';

  const STORAGE = {
    sidebar: 'srbooks.ui.sidebarCollapsed',
    density: 'srbooks.ui.compactRows'
  };
  const enhancedTables = new WeakSet();
  let sidebarFilter = null;
  let testModeRefreshAt = 0;
  let testModeLoading = false;

  const safeGet = (key) => {
    try { return localStorage.getItem(key); } catch (_) { return null; }
  };
  const safeSet = (key, value) => {
    try { localStorage.setItem(key, value); } catch (_) {}
  };
  const svg = (path, viewBox = '0 0 24 24') => {
    const el = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    el.setAttribute('viewBox', viewBox);
    el.setAttribute('fill', 'none');
    el.setAttribute('stroke', 'currentColor');
    el.setAttribute('stroke-width', '2');
    el.setAttribute('stroke-linecap', 'round');
    el.setAttribute('stroke-linejoin', 'round');
    el.innerHTML = path;
    return el;
  };
  const toast = (message) => {
    const prior = document.querySelector('.sr-ui-toast');
    if (prior) prior.remove();
    const el = document.createElement('div');
    el.className = 'sr-ui-toast';
    el.setAttribute('role', 'status');
    el.textContent = message;
    document.body.appendChild(el);
    window.setTimeout(() => el.remove(), 1800);
  };


  async function srApi(route, options = {}) {
    const authResponse = await fetch('/api/index.php?route=auth/me', { credentials: 'same-origin', cache: 'no-store' });
    const auth = await authResponse.json().catch(() => ({}));
    if (!authResponse.ok) throw new Error(auth.error || 'Sign in is required.');
    const headers = new Headers(options.headers || {});
    if (options.json !== undefined) {
      headers.set('Content-Type', 'application/json');
      options.body = JSON.stringify(options.json);
    }
    if (String(options.method || 'GET').toUpperCase() !== 'GET') headers.set('X-CSRF-Token', auth.csrfToken || '');
    const response = await fetch(`/api/index.php?route=${encodeURIComponent(route)}`, { ...options, headers, credentials: 'same-origin', cache: 'no-store' });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(body.error || 'The request could not be completed.');
    return { body, auth };
  }

  function closeTestModal() { document.querySelector('.sr-test-modal-scrim')?.remove(); }

  async function openTestModeDialog() {
    closeTestModal();
    let auth;
    try { ({ auth } = await srApi('auth/me')); } catch (error) { toast(error.message); return; }
    const activeId = localStorage.getItem('sr-accountax-company') || auth.companies?.[0]?.id || '';
    const active = auth.companies?.find((c) => c.id === activeId) || auth.companies?.[0] || null;
    const regular = (auth.companies || []).filter((c) => !c.isTestMode);
    const scrim = document.createElement('div');
    scrim.className = 'sr-test-modal-scrim';
    const expiry = active?.isTestMode && active.testExpiresAt ? new Date(active.testExpiresAt) : null;
    const expiryCopy = expiry && !Number.isNaN(expiry.getTime()) ? expiry.toLocaleString('en-CA', { dateStyle: 'medium', timeStyle: 'short' }) : '';
    scrim.innerHTML = `<section class="sr-test-modal" role="dialog" aria-modal="true" aria-labelledby="sr-test-title">
      <button class="sr-test-close" type="button" aria-label="Close">×</button>
      <span class="sr-test-icon">T</span>
      <h2 id="sr-test-title">Test Mode</h2>
      ${active?.isTestMode ? `<p>You are working in a temporary test company. It will be permanently removed after <b>${expiryCopy || 'four days'}</b>.</p>
        <div class="sr-test-note">Use this company to try imports, invoices, payroll, reports and other features. Do not enter real confidential information.</div>
        <button class="sr-test-primary" data-test-centre>Open Intensive Test Centre</button>
        ${regular.length ? `<label class="sr-test-field"><span>Return to a regular company</span><select data-test-switch>${regular.map(c => `<option value="${String(c.id).replace(/"/g,'&quot;')}">${String(c.name).replace(/</g,'&lt;')}</option>`).join('')}</select></label><button class="sr-test-primary" data-test-return>Switch company</button>` : ''}` : `<p>Create a temporary company for safely trying Tegh. The company and its data are automatically removed after four days.</p>
        <label class="sr-test-field"><span>Test company name</span><input data-test-name maxlength="160" value="Tegh Test ${new Date().toLocaleDateString('en-CA')}"></label>
        <label class="sr-test-field"><span>Modules to test</span><select data-test-module><option value="both">Accounting and payroll</option><option value="accounting">Accounting only</option><option value="payroll">Payroll only</option></select></label>
        <div class="sr-test-note">The test company is isolated from normal company files and clearly marked throughout the website.</div>
        <button class="sr-test-primary" data-test-create>Create and open Test Mode</button>`}
    </section>`;
    document.body.appendChild(scrim);
    scrim.addEventListener('click', (event) => { if (event.target === scrim) closeTestModal(); });
    scrim.querySelector('.sr-test-close')?.addEventListener('click', closeTestModal);
    scrim.querySelector('[data-test-centre]')?.addEventListener('click', () => {
      closeTestModal();
      window.SRBooksTestCentre?.open?.();
    });
    scrim.querySelector('[data-test-return]')?.addEventListener('click', () => {
      const id = scrim.querySelector('[data-test-switch]')?.value;
      if (id) { localStorage.setItem('sr-accountax-company', id); location.reload(); }
    });
    scrim.querySelector('[data-test-create]')?.addEventListener('click', async (event) => {
      const button = event.currentTarget;
      const name = scrim.querySelector('[data-test-name]')?.value?.trim();
      const moduleMode = scrim.querySelector('[data-test-module]')?.value || 'both';
      if (!name) { toast('Enter a test company name.'); return; }
      button.disabled = true; button.textContent = 'Creating Test Mode…';
      try {
        const source = active || {};
        const { body } = await srApi('companies', { method: 'POST', json: {
          name, legalName: name, businessType: source.businessType || 'corporation', province: source.province || 'ON',
          currency: source.currency || 'CAD', accountingBasis: source.accountingBasis || 'accrual', moduleMode,
          payrollPostingMode: moduleMode === 'accounting' ? 'none' : 'draft',
          taxReportingProfile: source.taxReportingProfile || (source.businessType === 'sole_proprietor' ? 't2125' : 'gifi'),
          fiscalYearEnd: source.fiscalYearEnd || '12-31', taxRegistered: false, testMode: true
        }});
        if (body.company?.id) localStorage.setItem('sr-accountax-company', body.company.id);
        sessionStorage.setItem('sr-company-delete-notice', 'Test Mode created. This temporary company will be removed automatically after four days.');
        location.reload();
      } catch (error) { button.disabled = false; button.textContent = 'Create and open Test Mode'; toast(error.message); }
    });
  }

  async function enhanceTestMode() {
    if (testModeLoading || Date.now() < testModeRefreshAt) return;
    testModeLoading = true;
    testModeRefreshAt = Date.now() + 15000;
    const actions = document.querySelector('.top-actions');
    const context = document.querySelector('.book-context');
    if (!actions || !context) { testModeLoading = false; return; }
    let auth;
    try {
      const response = await fetch('/api/index.php?route=auth/me', { credentials: 'same-origin', cache: 'no-store' });
      if (!response.ok) { testModeLoading = false; return; }
      auth = await response.json();
    } catch (_) { testModeLoading = false; return; }
    const activeId = localStorage.getItem('sr-accountax-company') || auth.companies?.[0]?.id || '';
    const active = auth.companies?.find((c) => c.id === activeId);
    let button = actions.querySelector('.sr-test-mode-button');
    if (auth.user?.platformRole !== 'platform_owner') {
      button?.remove();
      button = null;
    } else if (!button) {
      button = document.createElement('button'); button.type = 'button'; button.className = 'sr-test-mode-button';
      button.addEventListener('click', openTestModeDialog); actions.prepend(button);
    }
    if (button) {
      button.classList.toggle('active', Boolean(active?.isTestMode));
      button.innerHTML = active?.isTestMode ? '<b>TEST</b><span>Test Mode</span>' : '<b>T</b><span>Test Mode</span>';
      button.title = active?.isTestMode ? `Temporary company expires ${active.testExpiresAt || 'after four days'}` : 'Create a temporary test company';
    }
    let tag = context.querySelector('.sr-test-company-tag');
    if (active?.isTestMode) {
      if (!tag) { tag = document.createElement('span'); tag.className = 'sr-test-company-tag'; context.appendChild(tag); }
      const expiry = active.testExpiresAt ? new Date(active.testExpiresAt) : null;
      const hours = expiry && !Number.isNaN(expiry.getTime()) ? Math.max(0, Math.ceil((expiry.getTime() - Date.now()) / 3600000)) : null;
      tag.textContent = hours === null ? 'TEST' : `TEST · ${Math.ceil(hours / 24)}d`;
    } else tag?.remove();
    testModeLoading = false;
  }

  function showPostReloadNotice() {
    let message = '';
    try {
      message = sessionStorage.getItem('sr-company-delete-notice') || '';
      if (message) sessionStorage.removeItem('sr-company-delete-notice');
    } catch (_) {}
    if (message) window.setTimeout(() => toast(message), 350);
  }

  function addSkipLink() {
    if (document.querySelector('.sr-skip-link')) return;
    const link = document.createElement('a');
    link.className = 'sr-skip-link';
    link.href = '#root';
    link.textContent = 'Skip to main content';
    link.addEventListener('click', () => {
      const content = document.querySelector('.content') || document.querySelector('main') || document.querySelector('#root');
      if (content) {
        content.setAttribute('tabindex', '-1');
        content.focus({ preventScroll: true });
        content.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
    document.body.prepend(link);
  }

  function enhanceSidebar() {
    const sidebar = document.querySelector('.sidebar');
    if (!sidebar) return;
    const nav = sidebar.querySelector('nav');
    const head = sidebar.querySelector('.side-head');
    const company = sidebar.querySelector('.company');
    if (!nav || !head) return;

    if (!sidebar.dataset.srModern) {
      sidebar.dataset.srModern = '1';
      if (safeGet(STORAGE.sidebar) === '1' && window.matchMedia('(min-width:821px)').matches) {
        document.body.classList.add('sr-sidebar-collapsed');
      }
    }

    if (!head.querySelector('.sr-sidebar-toggle')) {
      const toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'sr-sidebar-toggle';
      toggle.title = 'Collapse or expand the menu';
      toggle.setAttribute('aria-label', 'Collapse or expand the menu');
      toggle.appendChild(svg('<path d="m15 18-6-6 6-6"/>'));
      toggle.addEventListener('click', () => {
        const collapsed = document.body.classList.toggle('sr-sidebar-collapsed');
        safeSet(STORAGE.sidebar, collapsed ? '1' : '0');
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      });
      head.appendChild(toggle);
    }

    /* Build 5700: the required portal synchronously owns the visible registry
       search. This optional visual layer only discovers that canonical input. */
    sidebarFilter = sidebar.querySelector('[data-srp-sidebar-search]');

    nav.querySelectorAll('button').forEach((button) => {
      if (!button.title) button.title = button.textContent.trim().replace(/\s+/g, ' ');
    });
    const active = nav.querySelector('button.active');
    if (active && active !== nav._srLastActive) {
      nav._srLastActive = active;
      window.requestAnimationFrame(() => active.scrollIntoView({ block: 'nearest', behavior: 'smooth' }));
    }
  }

  // The table row-spacing toggle was withdrawn from the header in 2.2.5.
  // Table spacing now follows the single comfortable default. Any stored
  // preference from an earlier build is cleared so a cached "compact" setting
  // cannot leave the tables looking different from the current design.
  function enhanceTopbar() {
    document.body.classList.remove('sr-compact-rows');
    document.querySelectorAll('.sr-density-toggle').forEach((node) => node.remove());
    try { window.localStorage.removeItem(STORAGE.density); } catch (_) {}
  }

  function enhanceScrollableContainer(scroller) {
    if (!scroller || scroller.dataset.srScrollEnhanced) return;
    scroller.dataset.srScrollEnhanced = '1';
    const update = () => {
      const remaining = scroller.scrollWidth - scroller.clientWidth - scroller.scrollLeft;
      scroller.classList.toggle('sr-can-scroll-right', remaining > 3);
    };
    scroller.addEventListener('scroll', update, { passive: true });
    new ResizeObserver(update).observe(scroller);
    update();
  }

  function enhanceTable(table) {
    if (enhancedTables.has(table)) return;
    enhancedTables.add(table);
    table.classList.add('sr-table-enhanced');
    const scroll = table.closest('.table-scroll');
    enhanceScrollableContainer(scroll);

    const getRows = () => Array.from(table.querySelectorAll('tbody tr')).filter((row) => row.querySelector('input[type="checkbox"]'));
    const rows = getRows();
    if (!rows.length) return;

    const host = table.closest('.data-panel,.panel') || scroll || table.parentElement;
    if (!host) return;
    let bar = host.querySelector(':scope > .sr-selection-bar');
    if (!bar) {
      bar = document.createElement('div');
      bar.className = 'sr-selection-bar';
      bar.setAttribute('role', 'status');
      bar.innerHTML = '<div class="sr-selection-copy"><strong>0 rows selected</strong><small>Use the available bulk actions to update selected records.</small></div>';
      const clear = document.createElement('button');
      clear.type = 'button';
      clear.textContent = 'Clear selection';
      clear.addEventListener('click', () => {
        getRows().forEach((row) => {
          const check = row.querySelector('input[type="checkbox"]');
          if (check && check.checked) {
            check.click();
          } else {
            row.classList.remove('sr-row-selected');
          }
        });
      });
      bar.appendChild(clear);
      host.appendChild(bar);
    }

    let tools = host.querySelector('.sr-table-select-tools');
    if (!tools) {
      const toolbar = host.querySelector('.toolbar,.table-toolbar,.simple-toolbar');
      if (toolbar) {
        tools = document.createElement('div');
        tools.className = 'sr-table-select-tools';
        const selectAll = document.createElement('button');
        selectAll.type = 'button';
        selectAll.textContent = 'Select visible';
        selectAll.addEventListener('click', () => {
          getRows().filter((row) => row.offsetParent !== null).forEach((row) => {
            const check = row.querySelector('input[type="checkbox"]');
            if (check && !check.checked && !check.disabled) check.click();
          });
        });
        const clearAll = document.createElement('button');
        clearAll.type = 'button';
        clearAll.textContent = 'Clear';
        clearAll.addEventListener('click', () => {
          getRows().forEach((row) => {
            const check = row.querySelector('input[type="checkbox"]');
            if (check && check.checked && !check.disabled) check.click();
          });
        });
        tools.append(selectAll, clearAll);
        toolbar.appendChild(tools);
      }
    }

    const update = () => {
      const currentRows = getRows();
      let count = 0;
      currentRows.forEach((row) => {
        const checked = Boolean(row.querySelector('input[type="checkbox"]:checked'));
        row.classList.toggle('sr-row-selected', checked);
        if (checked) count += 1;
      });
      const strong = bar.querySelector('strong');
      if (strong) strong.textContent = `${count} row${count === 1 ? '' : 's'} selected`;
      bar.classList.toggle('sr-visible', count > 0);
    };

    table.addEventListener('change', (event) => {
      if (event.target instanceof HTMLInputElement && event.target.type === 'checkbox') update();
    });
    table.addEventListener('click', (event) => {
      const target = event.target;
      if (!(target instanceof Element)) return;
      if (target.closest('button,a,input,select,textarea,label')) return;
      const row = target.closest('tbody tr');
      if (!row) return;
      const check = row.querySelector('input[type="checkbox"]');
      if (check && !check.disabled) check.click();
    });
    update();
  }

  function improveIconButtons(scope = document) {
    const buttons = [];
    if (scope.matches?.('button')) buttons.push(scope);
    scope.querySelectorAll?.('button').forEach(button => buttons.push(button));
    buttons.forEach((button) => {
      if (button.dataset.srLabelled) return;
      button.dataset.srLabelled = '1';
      const text = button.textContent.trim();
      if (!text && button.querySelector('svg') && !button.getAttribute('aria-label')) {
        button.setAttribute('aria-label', button.title || 'Action');
      }
    });
  }

  function enhanceAll(scope = document) {
    addSkipLink();
    enhanceSidebar();
    enhanceTopbar();
    enhanceTestMode();
    const root = scope instanceof Element || scope instanceof Document ? scope : document;
    if (root.matches?.('table')) enhanceTable(root);
    root.querySelectorAll?.('table').forEach(enhanceTable);
    if (root.matches?.('.table-scroll')) enhanceScrollableContainer(root);
    root.querySelectorAll?.('.table-scroll').forEach(enhanceScrollableContainer);
    improveIconButtons(root);
  }

  document.addEventListener('keydown', (event) => {
    const active = document.activeElement;
    const editing = active && ['INPUT','TEXTAREA','SELECT'].includes(active.tagName);
    if (event.key === '/' && !editing && sidebarFilter && !document.body.classList.contains('sr-sidebar-collapsed')) {
      event.preventDefault();
      sidebarFilter.focus();
    }
  });

  const start = () => {
    let enhancementScheduled = false, pendingScope = document;
    const scheduleEnhancement = (scope = document) => {
      pendingScope = scope instanceof Element ? scope : document;
      if (enhancementScheduled) return;
      enhancementScheduled = true;
      window.requestAnimationFrame(() => {
        enhancementScheduled = false;
        const next = pendingScope; pendingScope = document; enhanceAll(next);
      });
    };

    enhanceAll(document);
    showPostReloadNotice();
    window.addEventListener('tegh:enhance-subtree', event => scheduleEnhancement(event.detail?.root || document));
    window.addEventListener('tegh:page-rendered', event => scheduleEnhancement(event.detail?.root || document));
    window.addEventListener('resize', () => {
      if (window.innerWidth <= 820) document.body.classList.remove('sr-sidebar-collapsed');
      scheduleEnhancement(document.querySelector('.sidebar') || document.body);
    }, { passive: true });
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
