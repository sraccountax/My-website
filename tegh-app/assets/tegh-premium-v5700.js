(() => {
  'use strict';

  const BUILD = '5700';
  const RAIL_COLLAPSED_CLASS = 'tegh-utility-rail-collapsed';
  const SEARCH_OPEN_CLASS = 'tegh-utility-search-open';
  const interactedRailKeys = new Set();
  if (window.__teghPremium5700Installed) return;
  window.__teghPremium5700Installed = true;
  document.documentElement.dataset.teghPremiumBuild = BUILD;

  const cardSelector = [
    '.srp-dashboard-tile',
    '.srp-import-type-card',
    '.srp-option-grid > button',
    '.srp-sites-module-section-card',
    '.srp-settings-group',
    '.srp-kpi',
    '.stat',
    '.mini-stats > span'
  ].join(',');

  let scheduledFrame = 0, scheduledScope = null;

  const icons = {
    collapse: '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"></path></svg>',
    company: '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21h16"></path><path d="M6 21V5l6-3 6 3v16"></path><path d="M9 9h1"></path><path d="M14 9h1"></path><path d="M9 13h1"></path><path d="M14 13h1"></path><path d="M10 21v-4h4v4"></path></svg>',
    search: '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg>',
    mode: '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="2"></rect><path d="M8 7h8"></path><path d="M8 11h2"></path><path d="M14 11h2"></path><path d="M8 15h2"></path><path d="M14 15h2"></path></svg>',
    notifications: '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>',
    power: '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v10"></path><path d="M18.4 6.6a9 9 0 1 1-12.8 0"></path></svg>'
  };

  function isVisible(node) {
    if (!node?.isConnected) return false;
    const style = window.getComputedStyle(node);
    return style.display !== 'none' && style.visibility !== 'hidden' && node.getClientRects().length > 0;
  }

  function railIdentity(rail) {
    const company = rail?.querySelector('[data-utility-company]')?.value
      || window.localStorage.getItem('sr-accountax-company') || 'global';
    const user = rail?.querySelector('.tegh-utility-profile small')?.textContent?.trim()
      || rail?.querySelector('.tegh-utility-profile span')?.textContent?.trim() || 'user';
    return `${encodeURIComponent(user)}:${encodeURIComponent(company)}`;
  }

  function railStorageKey(rail) {
    return `tegh-utility-rail-collapsed-v1:${railIdentity(rail)}`;
  }

  function readLocalRailPreference(rail) {
    try { return window.localStorage.getItem(railStorageKey(rail)) === '1'; }
    catch (_) { return false; }
  }

  function writeLocalRailPreference(rail, collapsed) {
    try { window.localStorage.setItem(railStorageKey(rail), collapsed ? '1' : '0'); }
    catch (_) {}
  }

  function updateRailToggle(rail, collapsed) {
    const label = collapsed ? 'Expand sidebar' : 'Minimize sidebar';
    const toggles = [rail?.querySelector('[data-utility-rail-toggle]'), document.querySelector('[data-top-rail-toggle]')].filter(Boolean);
    toggles.forEach((toggle) => {
      toggle.setAttribute('aria-expanded', String(!collapsed));
      toggle.setAttribute('aria-label', label);
      toggle.title = label;
      toggle.dataset.tooltip = label;
      const copy = toggle.querySelector('[data-rail-toggle-label]');
      if (copy) copy.textContent = label;
    });
  }

  function setRailCollapsed(rail, collapsed, { persist = false } = {}) {
    if (!rail) return;
    const next = Boolean(collapsed);
    document.body.classList.toggle(RAIL_COLLAPSED_CLASS, next);
    rail.dataset.collapsed = String(next);
    updateRailToggle(rail, next);
    writeLocalRailPreference(rail, next);
    if (!next) closeCompactSearch(rail);
    if (persist) {
      const identity = railIdentity(rail);
      interactedRailKeys.add(identity);
      void persistRailPreference(rail, next);
    }
  }

  function closeCompactSearch(rail = document.querySelector('.tegh-utility-rail')) {
    document.body.classList.remove(SEARCH_OPEN_CLASS);
    const trigger = rail?.querySelector('[data-utility-icon="search"]');
    trigger?.setAttribute('aria-expanded', 'false');
    const results = rail?.querySelector('[data-utility-search-results]');
    if (results) results.hidden = true;
  }

  function openCompactSearch(rail) {
    if (!rail) return;
    document.body.classList.add(SEARCH_OPEN_CLASS);
    rail.querySelector('[data-utility-icon="search"]')?.setAttribute('aria-expanded', 'true');
    const input = rail.querySelector('[data-utility-search]');
    input?.focus();
    input?.select();
    window.requestAnimationFrame(() => {
      if (document.activeElement !== input) input?.focus();
    });
  }

  async function persistRailPreference(rail, collapsed) {
    try {
      const companyId = rail.querySelector('[data-utility-company]')?.value
        || window.localStorage.getItem('sr-accountax-company') || '';
      const authResponse = await window.fetch('/api/index.php?route=auth/me', {
        credentials: 'same-origin', cache: 'no-store'
      });
      if (!authResponse.ok) return;
      const auth = await authResponse.json();
      if (!auth?.csrfToken || !companyId) return;
      await window.fetch('/api/index.php?route=agent%2Finterface-preferences', {
        method: 'PUT',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': String(auth.csrfToken),
          'X-Company-Id': String(companyId)
        },
        body: JSON.stringify({ utilityRailCollapsed: Boolean(collapsed) })
      });
    } catch (_) {
      // The local preference remains authoritative for this device when sync
      // is temporarily unavailable.
    }
  }

  async function syncRailPreference(rail) {
    const identity = railIdentity(rail);
    if (rail.dataset.teghRailPreferenceSync === identity) return;
    rail.dataset.teghRailPreferenceSync = identity;
    try {
      const companyId = rail.querySelector('[data-utility-company]')?.value
        || window.localStorage.getItem('sr-accountax-company') || '';
      const response = await window.fetch('/api/index.php?route=agent%2Finterface-preferences', {
        credentials: 'same-origin', cache: 'no-store',
        headers: companyId ? { 'X-Company-Id': String(companyId) } : {}
      });
      if (!response.ok) return;
      const state = await response.json();
      if (railIdentity(rail) !== identity || interactedRailKeys.has(identity) || rail.dataset.teghRailUserInteracted === BUILD) return;
      if (typeof state?.preference?.utilityRailCollapsed === 'boolean') {
        setRailCollapsed(rail, state.preference.utilityRailCollapsed);
      }
    } catch (_) {}
  }

  function buttonMarkup(name, icon, extra = '') {
    return `<button type="button" class="tegh-utility-icon-button" data-utility-icon="${name}" ${extra}>${icon}</button>`;
  }

  function updateIconLabels(rail) {
    const select = rail.querySelector('[data-utility-company]');
    const company = select?.selectedOptions?.[0]?.textContent?.trim() || 'Active company';
    const mode = rail.querySelector('[data-utility-mode] b')?.textContent?.trim() || 'Accounting mode';
    const profile = rail.querySelector('.tegh-utility-profile span')?.textContent?.trim() || 'My profile';
    const email = rail.querySelector('.tegh-utility-profile small')?.textContent?.trim() || '';
    const initials = profile.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase() || 'P';
    const labels = {
      company: `Company: ${company}. Expand to change company.`,
      search: 'Search Tegh (Ctrl+K)',
      mode: `Accounting Mode: ${mode}. Expand to change mode.`,
      notifications: 'Open Notification Center',
      profile: email ? `${profile}, ${email}. Open profile.` : `${profile}. Open profile.`,
      signout: 'Sign Out'
    };
    Object.entries(labels).forEach(([name, label]) => {
      const button = rail.querySelector(`[data-utility-icon="${name}"]`);
      if (!button) return;
      button.setAttribute('aria-label', label);
      button.title = label;
      button.dataset.tooltip = label;
    });
    const avatar = rail.querySelector('.tegh-utility-icon-avatar');
    if (avatar) avatar.textContent = initials;
    const sourceBell = document.querySelector('.top-actions .bell');
    let unread = 0;
    try { unread = JSON.parse(sourceBell?.dataset?.srpNotices || '[]').filter((item) => !item?.read).length; }
    catch (_) { unread = Number(sourceBell?.querySelector('.srp-notice-badge')?.textContent || 0); }
    const badge = rail.querySelector('.tegh-utility-icon-badge');
    if (badge) {
      badge.textContent = unread > 99 ? '99+' : String(unread || '');
      if (badge.hidden === Boolean(unread)) badge.hidden = !unread;
    }
  }

  function enhanceUtilityRail(scope = document) {
    const rail = scope===document?document.querySelector('.tegh-utility-rail'):(scope?.matches?.('.tegh-utility-rail')?scope:scope?.closest?.('.tegh-utility-rail')||scope?.querySelector?.('.tegh-utility-rail'));
    if (!rail) return;
    // The required portal owns its complete compact utilities and preference
    // state. Legacy enhancement must not remove or rewire those controls.
    if (rail.querySelector('[data-utility-icon-stack="native"]')) return;
    if (!rail.id) rail.id = 'tegh-top-utility-rail';
    let toggle = rail.querySelector('[data-utility-rail-toggle]');
    const existingTopToggle = document.querySelector('[data-top-rail-toggle]');
    if (!toggle && !existingTopToggle) {
      toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'tegh-utility-rail-toggle';
      toggle.dataset.utilityRailToggle = '1';
      toggle.setAttribute('aria-controls', rail.id);
      toggle.innerHTML = `${icons.collapse}<span data-rail-toggle-label>Minimize sidebar</span>`;
      rail.querySelector('.tegh-utility-brand')?.insertAdjacentElement('afterend', toggle);
      toggle.addEventListener('click', () => {
        setRailCollapsed(rail, !document.body.classList.contains(RAIL_COLLAPSED_CLASS), { persist: true });
        toggle.focus();
      });
    }
    const primary = document.querySelector('.tegh-top-primary');
    let topToggle = primary?.querySelector('[data-top-rail-toggle]');
    if (primary && !topToggle) {
      topToggle = document.createElement('button');
      topToggle.type = 'button';
      topToggle.className = 'tegh-top-rail-toggle';
      topToggle.dataset.topRailToggle = '1';
      topToggle.setAttribute('aria-controls', rail.id);
      topToggle.innerHTML = `${icons.collapse}<span data-rail-toggle-label>Minimize sidebar</span>`;
      primary.prepend(topToggle);
    }
    if (topToggle && !topToggle.dataset.teghRailToggleWired) {
      topToggle.dataset.teghRailToggleWired = '1';
      topToggle.addEventListener('click', () => {
        setRailCollapsed(rail, !document.body.classList.contains(RAIL_COLLAPSED_CLASS), { persist: true });
        topToggle.focus();
      });
    }
    if (topToggle && toggle) {
      toggle.remove();
      toggle = null;
    }
    let stack = rail.querySelector('[data-utility-icon-stack]');
    if (!stack) {
      stack = document.createElement('div');
      stack.className = 'tegh-utility-icon-stack';
      stack.dataset.utilityIconStack = '1';
      stack.setAttribute('aria-label', 'Minimized workspace utilities');
      stack.innerHTML = [
        buttonMarkup('company', icons.company),
        buttonMarkup('search', icons.search, 'aria-haspopup="dialog" aria-expanded="false"'),
        buttonMarkup('mode', icons.mode)
      ].join('');
      rail.append(stack);
      stack.querySelector('[data-utility-icon="company"]').addEventListener('click', () => {
        setRailCollapsed(rail, false, { persist: true });
        window.requestAnimationFrame(() => rail.querySelector('[data-utility-company]')?.focus());
      });
      stack.querySelector('[data-utility-icon="search"]').addEventListener('click', () => openCompactSearch(rail));
      stack.querySelector('[data-utility-icon="mode"]').addEventListener('click', () => {
        setRailCollapsed(rail, false, { persist: true });
        window.requestAnimationFrame(() => rail.querySelector('[data-utility-mode]')?.click());
      });
      rail.querySelector('[data-utility-search-results]')?.addEventListener('click', () => closeCompactSearch(rail));
    }
    stack.querySelectorAll('[data-utility-icon="notifications"],[data-utility-icon="profile"],[data-utility-icon="signout"],.tegh-utility-icon-spacer').forEach((node) => node.remove());
    updateIconLabels(rail);
    if (rail.dataset.teghRailStateApplied !== BUILD) {
      rail.dataset.teghRailStateApplied = BUILD;
      setRailCollapsed(rail, readLocalRailPreference(rail));
    } else {
      setRailCollapsed(rail, document.body.classList.contains(RAIL_COLLAPSED_CLASS));
    }
    void syncRailPreference(rail);
  }

  function installGlobalShortcut() {
    if (window.__teghPremium5700ShortcutInstalled) return;
    window.__teghPremium5700ShortcutInstalled = true;
    // Build 5700 delegates Ctrl/Cmd+K and listbox focus to the required portal
    // controller so the optional premium layer cannot replace keyboard behavior.
  }

  function enhanceCards(scope) {
    scope.querySelectorAll(cardSelector).forEach((card, index) => {
      card.classList.add('tegh-premium-card');
      if (card.matches('button, a') || card.querySelector('button, a, [role="button"]')) {
        card.dataset.teghInteractive = 'true';
      }
      if (!card.dataset.teghEnterReady) {
        card.dataset.teghEnterReady = 'true';
        card.style.setProperty('--tegh-enter-order', String(Math.min(index, 8)));
        card.classList.add('tegh-premium-enter');
      }
    });
  }

  function enhanceSteppers(scope) {
    scope.querySelectorAll('.srp-import-steps').forEach((stepper) => {
      stepper.dataset.teghStepper = 'premium';
      stepper.setAttribute('role', 'list');
      const steps = Array.from(stepper.children);
      let current = steps.findIndex((step) => step.classList.contains('current'));
      if (current < 0) current = steps.findIndex((step) => step.classList.contains('active'));

      steps.forEach((step, index) => {
        step.setAttribute('role', 'listitem');
        const isCurrent = step.classList.contains('current') || step.classList.contains('active');
        const state = isCurrent
          ? 'current'
          : (step.classList.contains('done') || (current >= 0 && index < current) ? 'complete' : 'upcoming');
        step.dataset.state = state;
        if (state === 'current') step.setAttribute('aria-current', 'step');
        else step.removeAttribute('aria-current');
      });
    });
  }

  const QUICK_ACTION_LIMIT = 10;
  const compactModuleLabels = new Map([
    ['Dashboard', 'DB'], ['Banking', 'BK'], ['Receivables', 'AR'], ['Payables', 'AP'],
    ['General ledger', 'GL'], ['General Ledger', 'GL'], ['Payroll', 'PY'], ['Reports', 'RP'],
    ['Advanced accounting', 'AA'], ['Advanced Accounting', 'AA'], ['Agent Center', 'AC'],
    ['My account', 'ST'], ['Settings', 'ST']
  ]);
  let shellAuthPromise = null;
  let quickActionSyncIdentity = '';
  let navigationMode = false;
  let navigationEscapeArmed = false;
  let altCandidate = false;
  let backToTopScroller = null;

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[character]);

  function currentCompanyId() {
    return document.querySelector('[data-utility-company]')?.value
      || window.localStorage.getItem('sr-accountax-company') || '';
  }

  async function shellAuth(force = false) {
    if (force) shellAuthPromise = null;
    shellAuthPromise ||= window.fetch('/api/index.php?route=auth%2Fme&v=5300', {
      credentials: 'same-origin', cache: 'no-store'
    }).then(async (response) => {
      const body = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(body.error || 'Sign in is required.');
      return body;
    }).catch((error) => { shellAuthPromise = null; throw error; });
    return shellAuthPromise;
  }

  async function shellApi(route, options = {}) {
    const companyId = options.companyId || currentCompanyId();
    const auth = await shellAuth();
    if (companyId !== currentCompanyId()) throw Object.assign(new Error('The company changed. Refresh this view.'), {code: 'stale_workspace_response'});
    const url = new URL('/api/index.php', window.location.origin);
    url.searchParams.set('route', route);
    url.searchParams.set('v', BUILD);
    Object.entries(options.params || {}).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, String(value));
    });
    const method = String(options.method || 'GET').toUpperCase();
    const headers = {'X-Company-Id': companyId};
    if (!['GET', 'HEAD'].includes(method)) headers['X-CSRF-Token'] = String(auth.csrfToken || '');
    let body;
    if (options.json !== undefined) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(options.json);
    }
    const response = await window.fetch(url.pathname + url.search, {
      method, credentials: 'same-origin', cache: 'no-store', headers, body
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
      const error = new Error(payload.error || `Tegh could not complete this request (${response.status}).`);
      error.code = payload.code || 'request_failed';
      error.status = response.status;
      throw error;
    }
    return payload;
  }

  function showShellNotice(title, message, type = 'success') {
    let region = document.querySelector('[data-tegh-shell-notices]');
    if (!region) {
      region = document.createElement('section');
      region.className = 'tegh-shell-notices';
      region.dataset.teghShellNotices = '1';
      region.setAttribute('aria-live', 'polite');
      region.setAttribute('aria-atomic', 'true');
      document.body.append(region);
    }
    const notice = document.createElement('article');
    notice.className = `tegh-shell-notice is-${type}`;
    notice.innerHTML = `<b>${escapeHtml(title)}</b><p>${escapeHtml(message)}</p><button type="button" aria-label="Dismiss notification">×</button>`;
    notice.querySelector('button').onclick = () => notice.remove();
    region.append(notice);
    window.setTimeout(() => notice.remove(), 7000);
  }

  function visibleSidebarModules() {
    return Array.from(document.querySelectorAll('.sidebar nav > button,.sidebar nav > .srp-standalone-nav,.sidebar .side-foot > button'))
      .filter(isVisible);
  }

  function sidebarModuleName(button) {
    const declared = button.dataset.srpLabel || button.dataset.srpMenu || button.getAttribute('aria-label') || '';
    const visible = Array.from(button.childNodes).filter((node) => !node.classList?.contains?.('tegh-compact-label'))
      .map((node) => node.textContent || '').join(' ').replace(/\s+/g, ' ').trim();
    const raw = String(declared || visible).replace(/[›⌄⌃]/g, '').replace(/\s+/g, ' ').trim();
    if (/settings|my account/i.test(raw)) return 'Settings';
    if (/general ledger/i.test(raw)) return 'General Ledger';
    if (/advanced accounting/i.test(raw)) return 'Advanced Accounting';
    return raw;
  }

  function positionCompactFlyout(button) {
    const submenu = button?.nextElementSibling;
    if (!submenu?.classList.contains('srp-subnav')) return;
    const bounds = button.getBoundingClientRect();
    submenu.style.setProperty('--tegh-flyout-top', `${Math.max(10, Math.min(window.innerHeight - 110, bounds.top))}px`);
    submenu.setAttribute('aria-label', `${sidebarModuleName(button)} options`);
  }

  function enhanceSideNavigation(scope = document) {
    const sidebar = scope===document?document.querySelector('.sidebar'):(scope?.matches?.('.sidebar')?scope:scope?.closest?.('.sidebar')||scope?.querySelector?.('.sidebar'));
    if (!sidebar) return;
    const toggle = sidebar.querySelector('.sr-sidebar-toggle[data-tegh-required-sidebar],.sr-sidebar-toggle');
    if (toggle) {
      const minimized = document.body.classList.contains('sr-sidebar-collapsed');
      toggle.setAttribute('aria-label', minimized ? 'Expand Main Menu' : 'Minimize Main Menu');
      toggle.title = minimized ? 'Expand Main Menu' : 'Minimize Main Menu';
    }
    visibleSidebarModules().forEach((button) => {
      const name = sidebarModuleName(button);
      const compact = compactModuleLabels.get(name);
      if (!compact) return;
      let label = button.querySelector(':scope > .tegh-compact-label');
      if (!label) {
        label = document.createElement('span');
        label.className = 'tegh-compact-label';
        label.setAttribute('aria-hidden', 'true');
        button.append(label);
      }
      if (label.textContent !== compact) label.textContent = compact;
      button.dataset.teghFullLabel = name;
      button.setAttribute('aria-label', name);
      button.title = name;
      if (!button.dataset.teghCompactWired) {
        button.dataset.teghCompactWired = '1';
        button.addEventListener('click', () => window.requestAnimationFrame(() => positionCompactFlyout(button)));
        button.addEventListener('focus', () => positionCompactFlyout(button));
      }
      positionCompactFlyout(button);
    });
  }

  function waitForElement(selector, timeout = 5000) {
    return new Promise((resolve) => {
      const immediate = document.querySelector(selector);
      if (immediate) { resolve(immediate); return; }
      const observer = new MutationObserver(() => {
        const found = document.querySelector(selector);
        if (found) { observer.disconnect(); resolve(found); }
      });
      observer.observe(document.documentElement, {childList: true, subtree: true});
      window.setTimeout(() => { observer.disconnect(); resolve(document.querySelector(selector)); }, timeout);
    });
  }

  async function openCollectionTemplateSettings() {
    window.TeghPortal?.openCollectionDrafts?.();
    const button = await waitForElement('[data-collection-templates]');
    button?.click();
  }

  function settingsActionButton(label, action, marker) {
    const button = document.createElement('button');
    button.type = 'button';
    button.setAttribute('role', 'menuitem');
    button.tabIndex = -1;
    button.dataset.teghSettingsAction = marker;
    button.textContent = label;
    button.addEventListener('click', () => {
      document.querySelectorAll('.tegh-top-module-menu,.tegh-top-more-menu').forEach((menu) => { menu.hidden = true; });
      action();
    });
    return button;
  }

  function ensureSettingsGroup(menu, name) {
    let group = Array.from(menu.querySelectorAll(':scope > [role="group"]')).find((node) => node.getAttribute('aria-label') === name);
    if (!group) {
      group = document.createElement('div');
      group.setAttribute('role', 'group');
      group.setAttribute('aria-label', name);
      group.innerHTML = `<small>${escapeHtml(name)}</small>`;
      menu.append(group);
    }
    return group;
  }

  function enhanceSettingsNavigation() {
    document.querySelectorAll('[data-top-module="My account"]').forEach((trigger) => {
      if (trigger.childNodes.length === 1 && trigger.firstChild?.nodeType === Node.TEXT_NODE && trigger.textContent !== 'Settings') trigger.textContent = 'Settings';
      const menu = trigger.parentElement?.querySelector(':scope > .tegh-top-module-menu');
      if (!menu) return;
      menu.classList.add('tegh-settings-mega-menu');
      const sales = ensureSettingsGroup(menu, 'Sales & Purchases');
      if (!sales.querySelector('[data-tegh-settings-action="collection-templates"]')) {
        sales.append(settingsActionButton('Collection Message Templates', openCollectionTemplateSettings, 'collection-templates'));
      }
      const tegh = ensureSettingsGroup(menu, 'Tegh');
      Array.from(tegh.querySelectorAll('button')).forEach((button) => {
        if (/month-end close/i.test(button.textContent || '')) button.textContent = 'Month-End Review & Lock';
      });
      if (!tegh.querySelector('[data-tegh-settings-action="email-delivery"]')) {
        tegh.append(settingsActionButton('Email Delivery Health', openEmailDeliveryHealth, 'email-delivery'));
      }
    });
    const settingsParent = visibleSidebarModules().find((button) => compactModuleLabels.get(sidebarModuleName(button)) === 'ST');
    const submenu = settingsParent?.nextElementSibling;
    if (submenu?.classList.contains('srp-subnav')) {
      Array.from(submenu.querySelectorAll('button')).forEach((button) => {
        if (/month-end close/i.test(button.textContent || '')) button.textContent = 'Month-End Review & Lock';
      });
      if (!submenu.querySelector('[data-tegh-settings-action="collection-templates"]')) {
        const templates = settingsActionButton('Collection Message Templates', openCollectionTemplateSettings, 'collection-templates');
        templates.removeAttribute('role'); templates.tabIndex = 0; submenu.append(templates);
      }
      if (!submenu.querySelector('[data-tegh-settings-action="email-delivery"]')) {
        const delivery = settingsActionButton('Email Delivery Health', openEmailDeliveryHealth, 'email-delivery');
        delivery.removeAttribute('role'); delivery.tabIndex = 0; submenu.append(delivery);
      }
    }
  }

  function mailStatusMarkup(mail) {
    const delivery = mail.deliverability || {};
    const state = (value) => `<span class="tegh-health-state is-${escapeHtml(value || 'unknown')}">${escapeHtml(String(value || 'unknown').replace(/_/g, ' '))}</span>`;
    const value = (candidate, fallback = 'Not available') => escapeHtml(candidate === '' || candidate === null || candidate === undefined ? fallback : candidate);
    return `<section class="tegh-health-summary">
      <article><small>Sent · last 30 days</small><b>${Number(mail.sentLast30Days || 0)}</b></article>
      <article><small>Failed · last 30 days</small><b>${Number(mail.failedLast30Days || 0)}</b></article>
      <article><small>Manual Review</small><b>${Number(mail.manualReviewLast30Days || 0)}</b></article>
      <article><small>Pending / Deferred</small><b>${Number(mail.pendingLast30Days || 0)}</b></article>
    </section>
    <section class="srp-card tegh-mail-card"><div class="srp-section-title"><div><small>Sanitized private configuration</small><h2>SMTP Connection</h2><p>No password, authorization value, or protocol transcript is displayed.</p></div>${state(mail.configured ? 'configured' : 'not_configured')}</div>
      <dl class="tegh-health-grid"><div><dt>Host</dt><dd>${value(mail.host)}</dd></div><div><dt>Port</dt><dd>${value(mail.port)}</dd></div><div><dt>Encryption</dt><dd>${value(mail.encryption)}</dd></div><div><dt>Authentication</dt><dd>${mail.authenticationConfigured ? 'Configured' : 'Not configured'}</dd></div><div><dt>Username</dt><dd>${value(mail.maskedUsername)}</dd></div><div><dt>From</dt><dd>${value(mail.fromEmail)}</dd></div><div><dt>Reply-To</dt><dd>${value(mail.replyTo)}</dd></div><div><dt>Last successful test</dt><dd>${value(mail.lastSuccessfulTest)}</dd></div></dl>
    </section>
    <section class="srp-card tegh-mail-card"><div class="srp-section-title"><div><small>Published DNS evidence</small><h2>Sender Alignment</h2><p>${value(mail.limitations)}</p></div><time>${value(mail.checkedAt)}</time></div>
      <dl class="tegh-health-grid"><div><dt>Authenticated SMTP</dt><dd>${state(delivery.authenticatedSmtp ? 'pass' : 'missing')}</dd></div><div><dt>From / site alignment</dt><dd>${state(delivery.senderAligned ? 'pass' : 'review')}</dd></div><div><dt>SMTP / sender alignment</dt><dd>${state(delivery.smtpSenderAligned ? 'pass' : 'review')}</dd></div><div><dt>SPF</dt><dd>${state(delivery.spf)}</dd></div><div><dt>DKIM</dt><dd>${state(delivery.dkim)}</dd></div><div><dt>DMARC</dt><dd>${state(delivery.dmarc)}</dd></div></dl>
      ${mail.lastError ? `<p class="tegh-health-error"><b>Last sanitized error · ${escapeHtml(String(mail.lastErrorStage || 'unknown stage').replace(/_/g, ' '))}</b>${escapeHtml(mail.lastError)}</p>` : '<p class="tegh-health-ok"><b>No recent sanitized delivery failure is recorded.</b></p>'}
    </section>`;
  }

  async function openEmailDeliveryHealth() {
    const portal = window.TeghPortal;
    portal?.openAccount?.();
    const page = await waitForElement('.srp-page[data-srp-page="account-access"]');
    if (!page) { showShellNotice('Email Delivery Health unavailable', 'The Settings workspace did not open.', 'error'); return; }
    page.dataset.srpPage = 'email-delivery-health';
    page.dataset.teghRoute = 'email-delivery-health';
    const heading = page.querySelector('.srp-page-head h1');
    const description = page.querySelector('.srp-page-head p');
    if (heading) heading.textContent = 'Email Delivery Health';
    if (description) description.textContent = 'Review sanitized SMTP readiness, structured outcomes, and local OCR runtime integrity.';
    const back = page.querySelector('.srp-back');
    if (back) { back.textContent = 'Back to Settings'; back.onclick = () => portal?.openModule?.('My account'); }
    const body = page.querySelector('.srp-page-body');
    body.innerHTML = '<section class="srp-card srp-empty" aria-busy="true">Checking private delivery configuration…</section>';
    try {
      const [result, auth] = await Promise.all([shellApi('platform/mail-status'), shellAuth()]);
      body.innerHTML = `<section class="tegh-email-hero"><div><span>Platform Owner diagnostic</span><h2>Deliver with evidence, recover without duplicates.</h2><p>Tegh distinguishes sender rejection, recipient rejection, final acceptance, and ambiguous post-submission outcomes.</p></div><div class="srp-actions"><button type="button" class="srp-btn" data-mail-test>Send Test Email</button><button type="button" class="srp-btn secondary" data-ocr-diagnostic>Verify Local OCR Runtime</button></div></section>
        <div data-mail-health>${mailStatusMarkup(result.mail || {})}</div>
        <section class="srp-card tegh-runtime-diagnostic"><div class="srp-section-title"><div><small>Same-origin browser runtime</small><h2>Local OCR Diagnostic</h2><p>Verifies PDF and Tesseract runtime assets only. Legal files remain package-audited and protected from browser access.</p></div></div><div data-ocr-result role="status">Not run in this session.</div></section>`;
      const testButton = body.querySelector('[data-mail-test]');
      testButton.onclick = async () => {
        if (!window.confirm(`Send one rate-limited test email to ${auth.user?.email || 'the signed-in Platform Owner address'}?`)) return;
        testButton.disabled = true; testButton.textContent = 'Sending test…';
        try {
          const response = await shellApi('platform/mail-status', {method: 'POST', json: {recipient: auth.user?.email || ''}});
          showShellNotice(response.delivery?.sent ? 'Test email accepted' : 'Test email needs review', response.delivery?.message || 'Review the structured outcome below.', response.delivery?.sent ? 'success' : 'error');
          const refreshed = await shellApi('platform/mail-status');
          body.querySelector('[data-mail-health]').innerHTML = mailStatusMarkup(refreshed.mail || {});
        } catch (error) { showShellNotice('Test email not sent', error.message, 'error'); }
        finally { testButton.disabled = false; testButton.textContent = 'Send Test Email'; }
      };
      const ocrButton = body.querySelector('[data-ocr-diagnostic]');
      ocrButton.onclick = async () => {
        const host = body.querySelector('[data-ocr-result]');
        ocrButton.disabled = true; host.textContent = 'Verifying PDF and OCR runtime groups…';
        try {
          const diagnostic = await window.TeghNativeOCR.verifyRuntimeGroups();
          host.innerHTML = `<p class="tegh-health-ok"><b>All local runtime groups passed.</b> ${escapeHtml(diagnostic.groups.join(', '))}. Provider requests: 0.</p>`;
        } catch (error) { host.innerHTML = `<p class="tegh-health-error"><b>Local runtime verification failed.</b>${escapeHtml(error.code || 'ocr_runtime_unavailable')}. No accounting entry was created.</p>`; }
        finally { ocrButton.disabled = false; }
      };
    } catch (error) {
      body.innerHTML = `<section class="srp-card tegh-health-error" role="alert"><h2>Email Delivery Health could not open</h2><p>${escapeHtml(error.message)}</p><button type="button" class="srp-btn secondary" data-mail-retry>Retry</button></section>`;
      body.querySelector('[data-mail-retry]').onclick = openEmailDeliveryHealth;
    }
    window.dispatchEvent(new CustomEvent('tegh:page-rendered', {detail: {id: 'email-delivery-health', page}}));
  }

  async function replaceLocalQuickActions(ids) {
    const service = window.TeghPortal?.quickActions;
    if (!service) return;
    const unique = [...new Set(ids)].slice(0, QUICK_ACTION_LIMIT);
    if (typeof service.setIds === 'function') service.setIds(unique, 'Quick Action order updated.');
    else {
      service.getIds().forEach((id) => service.remove(id));
      for (const id of unique) await service.add(id);
    }
  }

  async function saveQuickActions(ids, mode, context = {}) {
    const unique = [...new Set(ids)].slice(0, QUICK_ACTION_LIMIT);
    await shellApi('agent/interface-preferences', {method: 'PUT', companyId: context.companyId, json: {quickActionMode: mode, actionIds: unique}});
    if (context.current && !context.current()) return unique;
    await replaceLocalQuickActions(unique);
    return unique;
  }

  async function syncQuickActions() {
    const service = window.TeghPortal?.quickActions;
    const company = currentCompanyId();
    if (!service || !company) return;
    const initialScope = service.getScope?.() || '';
    const catalogue = await service.getCatalogue().catch(() => null);
    const scope = service.getScope?.() || company;
    const current = () => currentCompanyId() === company && (service.getScope?.() || company) === scope;
    if (!current() || (initialScope && initialScope !== scope) || !Array.isArray(catalogue)) return;
    const mode = service.getMode?.() === 'guided' ? 'guided' : 'full';
    const identity = `${scope}:${mode}`;
    if (quickActionSyncIdentity === identity) return;
    quickActionSyncIdentity = identity;
    // Default and legacy shortcuts can include write destinations a viewer
    // cannot use. Only migrate IDs from the authenticated permitted catalogue.
    const allowed = new Set(catalogue.map(item => item.action_id));
    const permitted = ids => [...new Set((Array.isArray(ids) ? ids : []).filter(id => allowed.has(id)))].slice(0, QUICK_ACTION_LIMIT);
    try {
      const response = await shellApi('agent/interface-preferences', {companyId: company});
      if (!current()) return;
      const remote = response.quickActions?.[mode] || {};
      const local = service.getIds();
      if (remote.source === 'user') {
        const visible = permitted(remote.actionIds);
        if (JSON.stringify(local.slice(0, QUICK_ACTION_LIMIT)) !== JSON.stringify(visible)) await replaceLocalQuickActions(visible);
      } else {
        const migrated = permitted(local);
        await saveQuickActions(migrated, mode, {companyId: company, current});
        if (!current()) return;
        if (local.length > QUICK_ACTION_LIMIT && !window.sessionStorage.getItem('tegh-quick-action-limit-notice-v5700')) {
          window.sessionStorage.setItem('tegh-quick-action-limit-notice-v5700', '1');
          showShellNotice('Quick Actions updated', 'Up to ten permitted saved actions were kept in their existing order.');
        }
      }
    } catch (_) { if (quickActionSyncIdentity === identity) quickActionSyncIdentity = ''; }
  }

  function openQuickActionCustomizer() {
    const existing = document.querySelector('[data-tegh-quick-customizer]');
    if (existing) { existing.querySelector('[data-qa-v3-search]')?.focus(); return; }
    const service = window.TeghPortal?.quickActions;
    if (!service) return;
    const previousFocus = document.activeElement;
    let selected = service.getIds().slice(0, QUICK_ACTION_LIMIT);
    let catalogue = (service.peekCatalogue?.() || []).slice();
    let mode = service.getMode?.() === 'guided' ? 'guided' : 'full';
    let byId = new Map();
    let visibleLimit = 36;
    let loading = catalogue.length === 0;
    let loadError = '';
    let dragId = '';
    let searchTimer = 0;
    const prepareCatalogue = (items) => (items || []).map((item) => ({
      ...item,
      teghSearchText: `${item.label || ''} ${item.module || ''} ${item.description || ''} ${(item.keywords || []).join(' ')}`.toLowerCase()
    }));
    catalogue = prepareCatalogue(catalogue);
    byId = new Map(catalogue.map((item) => [item.action_id, item]));

    const scrim = document.createElement('div');
    scrim.className = 'srp-modal-scrim tegh-qa-scrim tegh-qa-v3-scrim tegh-qa-v4-scrim';
    scrim.dataset.teghQuickCustomizer = '1';
    scrim.dataset.teghDialogReady = '1';
    scrim.setAttribute('role', 'dialog');
    scrim.setAttribute('aria-modal', 'true');
    scrim.setAttribute('aria-labelledby', 'tegh-qa-v4-title');
    scrim.setAttribute('aria-describedby', 'tegh-qa-v4-description');
    scrim.innerHTML = `<section class="srp-modal tegh-qa-dialog tegh-qa-v3-dialog tegh-qa-v4-dialog">
      <header class="tegh-qa-head tegh-qa-v4-head"><div><small>Ten customizable slots</small><h2 id="tegh-qa-v4-title">Customize Quick Actions</h2><p id="tegh-qa-v4-description">Choose, reorder, and save the shortcuts you use most. Slot order also sets Ctrl+Alt+1–9 and Ctrl+Alt+0 for slot 10.</p></div><button type="button" class="tegh-qa-close" data-qa-v3-cancel aria-label="Cancel Quick Action changes">×</button></header>
      <div class="tegh-qa-v4-search"><label for="tegh-qa-v4-search"><span>Search available actions</span><input id="tegh-qa-v4-search" type="search" data-qa-v3-search placeholder="Search workflows, reports, or modules" autocomplete="off"></label><small>Only actions permitted for this company and your role are shown.</small></div>
      <div class="tegh-qa-v3-body tegh-qa-v4-body">
        <section class="tegh-qa-v4-selected" data-qa-v3-selected aria-label="Selected Quick Actions"><header><span><small>Your shortcuts</small><h3>Your Quick Actions</h3></span><p>Drag a card or use its arrow buttons.</p></header><div data-qa-v4-selected-list></div></section>
        <section class="tegh-qa-v4-results" data-qa-v3-results aria-label="Available Quick Actions"><header><span><small>Permitted catalogue</small><h3>Available Actions</h3></span><b data-qa-v4-result-count></b></header><div data-qa-v4-results-list></div></section>
      </div>
      <footer class="tegh-qa-v3-footer tegh-qa-v4-footer"><span data-qa-v3-count aria-live="polite"></span><div><button type="button" class="srp-btn secondary" data-qa-v3-defaults>Restore Defaults</button><button type="button" class="srp-btn secondary" data-qa-v3-cancel>Cancel</button><button type="button" class="srp-btn" data-qa-v3-save>Save Quick Actions</button></div></footer>
      <div class="sr-only" role="status" aria-live="polite" data-qa-v4-live></div>
    </section>`;
    document.body.append(scrim);
    document.body.classList.add('srp-modal-open');
    const dialog = scrim.querySelector('.tegh-qa-v4-dialog');
    window.TeghActivityShell?.enhanceOverlay(dialog);
    try { performance.mark('tegh-quick-actions-dialog-ready'); } catch (_) {}

    const selectedList = scrim.querySelector('[data-qa-v4-selected-list]');
    const resultList = scrim.querySelector('[data-qa-v4-results-list]');
    const resultCount = scrim.querySelector('[data-qa-v4-result-count]');
    const countHost = scrim.querySelector('[data-qa-v3-count]');
    const input = scrim.querySelector('[data-qa-v3-search]');
    const saveButton = scrim.querySelector('[data-qa-v3-save]');
    const live = scrim.querySelector('[data-qa-v4-live]');
    const announce = (message) => { live.textContent = ''; window.requestAnimationFrame(() => { live.textContent = message; }); };
    const close = () => {
      window.clearTimeout(searchTimer);
      window.TeghActivityShell?.detachOverlay(dialog);
      scrim.remove();
      if (!document.querySelector('[role="dialog"][aria-modal="true"]')) document.body.classList.remove('srp-modal-open');
      previousFocus?.focus?.();
    };
    const renderSelected = () => {
      countHost.textContent = `${selected.length} of ${QUICK_ACTION_LIMIT} selected`;
      selectedList.innerHTML = selected.length ? selected.map((id, index) => {
        const item = byId.get(id);
        const label = item?.label || (loading ? 'Loading saved action…' : 'Unavailable action');
        const shortcut = `+${(index + 1) % 10}`;
        return `<article draggable="true" data-qa-v3-row="${escapeHtml(id)}"><span class="tegh-qa-v4-slot"><kbd>${escapeHtml(shortcut)}</kbd><small>Slot ${index + 1}</small></span><span class="tegh-qa-v4-copy"><b>${escapeHtml(label)}</b><small>${escapeHtml(item?.module || (loading ? 'Checking access…' : 'No longer available for this workspace'))}</small></span><nav aria-label="Reorder ${escapeHtml(label)}"><button type="button" data-qa-v3-up="${escapeHtml(id)}" ${index === 0 ? 'disabled' : ''} aria-label="Move ${escapeHtml(label)} up" title="Move up">↑</button><button type="button" data-qa-v3-down="${escapeHtml(id)}" ${index === selected.length - 1 ? 'disabled' : ''} aria-label="Move ${escapeHtml(label)} down" title="Move down">↓</button><button type="button" class="tegh-qa-v4-remove" data-qa-v3-remove="${escapeHtml(id)}" aria-label="Remove ${escapeHtml(label)}">Remove</button></nav></article>`;
      }).join('') : '<div class="tegh-qa-v4-empty"><b>No shortcuts selected</b><p>Add an available action to create your first shortcut.</p></div>';
    };
    const renderResults = () => {
      const query = input.value.trim().toLowerCase();
      const available = catalogue.filter((item) => !selected.includes(item.action_id) && (!query || item.teghSearchText.includes(query)));
      const visible = available.slice(0, visibleLimit);
      resultCount.textContent = loading && !catalogue.length ? 'Loading…' : `${available.length} available`;
      scrim.setAttribute('aria-busy', String(loading && !catalogue.length));
      saveButton.disabled = loading && !catalogue.length;
      if (loading && !catalogue.length) {
        resultList.innerHTML = '<div class="tegh-qa-v4-loading" role="status"><span aria-hidden="true"></span><b>Loading permitted actions…</b><p>You can already review your saved shortcut order.</p></div>';
        return;
      }
      if (loadError && !catalogue.length) {
        resultList.innerHTML = `<div class="tegh-qa-v4-empty" role="alert"><b>Available actions could not load</b><p>${escapeHtml(loadError)}</p><button type="button" class="srp-btn secondary" data-qa-v4-retry>Retry</button></div>`;
        return;
      }
      if (!available.length) {
        resultList.innerHTML = `<div class="tegh-qa-v4-empty"><b>${query ? 'No actions match this search' : 'No more actions are available'}</b><p>${query ? 'Try a module, report, or shorter workflow name.' : 'Remove a shortcut to choose a different action.'}</p></div>`;
        return;
      }
      resultList.innerHTML = `${visible.map((item) => `<button type="button" data-qa-v3-add="${escapeHtml(item.action_id)}" ${selected.length >= QUICK_ACTION_LIMIT ? 'disabled' : ''}><span class="tegh-qa-v4-module">${escapeHtml(item.module || 'Workspace')}</span><span class="tegh-qa-v4-copy"><b>${escapeHtml(item.label)}</b><small>${escapeHtml(item.description || 'Open destination')}</small></span><em>${selected.length >= QUICK_ACTION_LIMIT ? 'Full' : 'Add'}</em></button>`).join('')}${visible.length < available.length ? `<button type="button" class="tegh-qa-v4-more" data-qa-v4-more>Show ${Math.min(36, available.length - visible.length)} more actions</button>` : ''}`;
    };
    const render = () => { renderSelected(); renderResults(); };
    const refreshCatalogue = async ({force = false} = {}) => {
      if (!catalogue.length) { loading = true; loadError = ''; renderResults(); }
      try {
        const fresh = await service.getCatalogue({force});
        if (!scrim.isConnected) return;
        catalogue = prepareCatalogue(fresh);
        byId = new Map(catalogue.map((item) => [item.action_id, item]));
        mode = service.getMode?.() === 'guided' ? 'guided' : 'full';
        loadError = '';
      } catch (error) {
        if (!scrim.isConnected) return;
        loadError = error?.message || 'Tegh could not refresh the permitted action catalogue.';
      } finally {
        if (scrim.isConnected) { loading = false; render(); announce(loadError ? 'Available actions could not be refreshed.' : 'Available actions are ready.'); }
      }
    };

    input.addEventListener('input', () => {
      visibleLimit = 36;
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(renderResults, 90);
    });
    input.addEventListener('keydown', (event) => {
      if (event.key !== 'ArrowDown') return;
      const first = resultList.querySelector('[data-qa-v3-add]:not(:disabled)');
      if (first) { event.preventDefault(); first.focus(); }
    });
    scrim.addEventListener('click', async (event) => {
      if (event.target === scrim) { close(); return; }
      const button = event.target.closest('button');
      if (!button || !scrim.contains(button)) return;
      if (button.matches('[data-qa-v3-cancel]')) { close(); return; }
      if (button.matches('[data-qa-v4-retry]')) { void refreshCatalogue(); return; }
      if (button.matches('[data-qa-v4-more]')) { visibleLimit += 36; renderResults(); return; }
      if (button.matches('[data-qa-v3-defaults]')) {
        selected = (service.getDefaultIds?.() || []).filter((id) => byId.has(id)).slice(0, QUICK_ACTION_LIMIT);
        render(); announce('Default Quick Actions restored in this draft.'); return;
      }
      if (button.matches('[data-qa-v3-up],[data-qa-v3-down]')) {
        const id = button.dataset.qaV3Up || button.dataset.qaV3Down;
        const from = selected.indexOf(id);
        const to = from + (button.dataset.qaV3Up !== undefined ? -1 : 1);
        if (from >= 0 && to >= 0 && to < selected.length) { [selected[from], selected[to]] = [selected[to], selected[from]]; renderSelected(); announce('Quick Action order updated.'); }
        return;
      }
      if (button.matches('[data-qa-v3-remove]')) {
        const item = byId.get(button.dataset.qaV3Remove);
        selected = selected.filter((id) => id !== button.dataset.qaV3Remove);
        render(); announce(`${item?.label || 'Quick Action'} removed.`); return;
      }
      if (button.matches('[data-qa-v3-add]')) {
        if (selected.length >= QUICK_ACTION_LIMIT || !byId.has(button.dataset.qaV3Add)) return;
        const item = byId.get(button.dataset.qaV3Add);
        selected.push(button.dataset.qaV3Add);
        render(); announce(`${item.label} added to slot ${selected.length}.`); return;
      }
      if (button.matches('[data-qa-v3-save]')) {
        button.disabled = true; button.textContent = 'Saving…';
        try {
          await saveQuickActions(selected, mode);
          close();
          showShellNotice('Quick Actions saved', 'Your Quick Actions and keyboard shortcuts are ready.');
          if (document.querySelector('[data-srp-page="dashboard"]')) window.TeghPortal?.openDashboard?.();
        } catch (error) {
          button.disabled = false; button.textContent = 'Save Quick Actions';
          showShellNotice('Quick Actions not saved', error.message, 'error');
        }
      }
    });
    scrim.addEventListener('dragstart', (event) => {
      const row = event.target.closest('[data-qa-v3-row]');
      if (!row) return;
      dragId = row.dataset.qaV3Row;
      row.classList.add('is-dragging');
      event.dataTransfer?.setData('text/plain', dragId);
    });
    scrim.addEventListener('dragend', (event) => { event.target.closest('[data-qa-v3-row]')?.classList.remove('is-dragging'); dragId = ''; });
    scrim.addEventListener('dragover', (event) => { if (event.target.closest('[data-qa-v3-row]')) event.preventDefault(); });
    scrim.addEventListener('drop', (event) => {
      const row = event.target.closest('[data-qa-v3-row]');
      if (!row || !dragId || row.dataset.qaV3Row === dragId) return;
      event.preventDefault();
      const next = selected.filter((id) => id !== dragId);
      next.splice(next.indexOf(row.dataset.qaV3Row), 0, dragId);
      selected = next; dragId = ''; renderSelected(); announce('Quick Action order updated.');
    });
    scrim.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') { event.preventDefault(); close(); return; }
      if (event.key !== 'Tab') return;
      const focusable = Array.from(scrim.querySelectorAll('button:not(:disabled),input:not(:disabled)')).filter((node) => node.getClientRects().length && !node.closest('[hidden]'));
      const first = focusable[0], last = focusable.at(-1);
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    });

    render();
    window.requestAnimationFrame(() => input.focus());
    void refreshCatalogue({force: catalogue.length > 0});
  }

  function enhanceQuickActionDashboard(scope = document) {
    const buttons = Array.from(scope.querySelectorAll?.('[data-sites-quick]') || []);
    buttons.slice(QUICK_ACTION_LIMIT).forEach((button) => button.remove());
    buttons.slice(0, QUICK_ACTION_LIMIT).forEach((button, index) => {
      let chip = button.querySelector('.tegh-quick-slot');
      if (!chip) { chip = document.createElement('kbd'); chip.className = 'tegh-quick-slot'; button.append(chip); }
      const shortcut = `+${(index + 1) % 10}`;
      if (chip.textContent !== shortcut) chip.textContent = shortcut;
    });
    scope.querySelectorAll?.('[data-edit-quick-actions]').forEach((button) => { if (button.textContent !== 'Edit') button.textContent = 'Edit'; });
  }

  function keyboardContextBlocked(event) {
    if (event.isComposing || event.repeat) return true;
    const target = event.target;
    if (target?.closest?.('input,textarea,select,[contenteditable="true"],.sr-ai-composer,.sra-composer,[role="dialog"]')) return true;
    return Boolean(document.querySelector('[role="dialog"][aria-modal="true"]'));
  }

  function announceNavigation(message) {
    let live = document.querySelector('[data-tegh-navigation-live]');
    if (!live) { live = document.createElement('div'); live.className = 'sr-only'; live.dataset.teghNavigationLive = '1'; live.setAttribute('role', 'status'); live.setAttribute('aria-live', 'polite'); document.body.append(live); }
    live.textContent = ''; window.requestAnimationFrame(() => { live.textContent = message; });
  }

  function navigationItems() {
    if (document.body.classList.contains('tegh-top-navigation-mode')) return Array.from(document.querySelectorAll('.tegh-top-menubar > [data-top-module],.tegh-top-menubar > .tegh-top-module > [data-top-module],.tegh-top-menubar > .tegh-top-more > [data-top-more]')).filter(isVisible);
    return visibleSidebarModules();
  }

  function enterNavigationMode() {
    const items = navigationItems(); if (!items.length) return;
    navigationMode = true; navigationEscapeArmed = false; document.body.classList.add('tegh-navigation-active');
    const active = items.find((item) => item.classList.contains('active') || item.getAttribute('aria-current') === 'page') || items[0];
    items.forEach((item) => { item.tabIndex = item === active ? 0 : -1; }); active.focus();
    announceNavigation('Navigation active. Use arrow keys, Enter or Escape.');
  }

  function exitNavigationMode() {
    navigationMode = false; navigationEscapeArmed = false; document.body.classList.remove('tegh-navigation-active'); announceNavigation('Navigation closed.');
  }

  function installNavigationMode() {
    if (window.__teghNavigation5700Installed) return; window.__teghNavigation5700Installed = true;
    // The required portal renderer owns ordinary top/side Arrow, Enter and
    // Escape behavior. Keeping this former Alt-mode listener active creates a
    // second capture-phase owner and can swallow the same keystroke.
  }

  function installSuccessorShortcuts() {
    if (window.__teghShortcuts5700Installed) return; window.__teghShortcuts5700Installed = true;
    document.addEventListener('click', (event) => {
      if (!event.target.closest?.('[data-edit-quick-actions]')) return;
      event.preventDefault(); event.stopImmediatePropagation(); void openQuickActionCustomizer();
    }, true);
    document.addEventListener('keydown', (event) => {
      if (!event.ctrlKey || !event.altKey || event.metaKey || event.shiftKey || keyboardContextBlocked(event)) return;
      if (event.key.toLowerCase() === 'q') { event.preventDefault(); event.stopImmediatePropagation(); void openQuickActionCustomizer(); return; }
      if (window.TeghRegistersR23?.ownsQuickKeys || !/^[0-9]$/.test(event.key)) return;
      event.preventDefault(); event.stopImmediatePropagation();
      const index = event.key === '0' ? 9 : Number(event.key) - 1, service = window.TeghPortal?.quickActions, actionId = service?.getIds?.()[index];
      if (actionId) void service.open(actionId, {source: `keyboard-slot-${index + 1}`});
    }, true);
  }

  function activeScroller() {
    const candidates = [document.querySelector('.stage'), document.querySelector('.srp-page'), document.querySelector('.stage > .content'), document.scrollingElement].filter(Boolean);
    return candidates.find((node) => { const style = window.getComputedStyle(node); return node.scrollHeight > node.clientHeight + 8 && /(auto|scroll)/.test(style.overflowY); }) || document.scrollingElement;
  }

  function installBackToTop() {
    if (window.__teghBackToTop5300Installed) return; window.__teghBackToTop5300Installed = true;
    const button = document.createElement('button'); button.type = 'button'; button.className = 'tegh-back-to-top'; button.dataset.teghBackToTop = '1'; button.hidden = true; button.setAttribute('aria-label', 'Back to Top'); button.title = 'Back to Top'; button.innerHTML = '<span aria-hidden="true">↑</span>';
    document.body.append(button);
    let observedTop = -1, observedViewport = -1, observedModal = false;
    const update = () => { const scroller = activeScroller(); if (scroller !== backToTopScroller) { backToTopScroller?.removeEventListener?.('scroll', requestUpdate); backToTopScroller = scroller; backToTopScroller?.addEventListener?.('scroll', requestUpdate, {passive: true}); }
      const top = scroller === document.scrollingElement ? window.scrollY : scroller.scrollTop; const viewport = scroller === document.scrollingElement ? window.innerHeight : scroller.clientHeight;
      const modal = Boolean(document.querySelector('[role="dialog"][aria-modal="true"]')); observedTop = top; observedViewport = viewport; observedModal = modal;
      const shouldHide = top < viewport || !document.querySelector('.app') || modal; if (button.hidden !== shouldHide) button.hidden = shouldHide;
    };
    // Scroll events can fire faster than the main thread can paint large
    // accounting tables. Coalesce geometry reads to one animation frame and
    // react to dialog/route mutations instead of polling layout continuously.
    let updateQueued = false;
    const requestUpdate = () => {
      if (updateQueued) return;
      updateQueued = true;
      window.requestAnimationFrame(() => { updateQueued = false; update(); });
    };
    const stateObserver = new MutationObserver(requestUpdate);
    stateObserver.observe(document.body, {childList: true, subtree: true, attributes: true, attributeFilter: ['open', 'aria-modal', 'hidden']});
    button.onclick = () => { const scroller = activeScroller(), reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches; const heading = Array.from(document.querySelectorAll('.srp-page h1,.content h1,main h1')).find((node) => !node.closest('[hidden],[aria-hidden="true"]') && isVisible(node)); const focusHeading = () => { if (!heading?.isConnected) return; heading.setAttribute('tabindex', '-1'); heading.focus(); }; focusHeading(); if (scroller === document.scrollingElement) window.scrollTo({top: 0, behavior: reduced ? 'auto' : 'smooth'}); else scroller.scrollTo({top: 0, behavior: reduced ? 'auto' : 'smooth'}); window.requestAnimationFrame(focusHeading); window.setTimeout(() => { focusHeading(); heading?.addEventListener('blur', () => heading.removeAttribute('tabindex'), {once: true}); update(); }, reduced ? 0 : 420); };
    document.addEventListener('scroll', requestUpdate, {capture: true, passive: true}); window.addEventListener('resize', requestUpdate, {passive: true}); window.addEventListener('pageshow', requestUpdate); window.addEventListener('tegh:page-rendered', requestUpdate); update();
  }

  function humanMoney(cents, currency = 'CAD') { try { return new Intl.NumberFormat(undefined, {style: 'currency', currency}).format(Number(cents || 0) / 100); } catch (_) { return `${currency} ${(Number(cents || 0) / 100).toFixed(2)}`; } }
  const monthStatusLabels = {'Passed':'Complete','Needs Attention':'Action Needed','Needs attention':'Action Needed','Waiting for User':'Your Confirmation Needed','Waiting for user':'Your Confirmation Needed','Unable to Verify':'Check Manually','Unable to verify':'Check Manually','Not Applicable':'Not Needed','Not applicable':'Not Needed'};

  function humanMonthFacts(key, facts) {
    if (!facts || typeof facts !== 'object') return 'Tegh did not retain a displayable fact summary for this check.';
    if (key === 'tax_payroll_liabilities' || key === 'payroll') {
      const accounts = Array.isArray(facts.accounts) ? facts.accounts : []; const checked = Number(facts.checkedAccountCount || accounts.length || 0);
      if (accounts.length) { const account = accounts[0]; return `${checked} liability account${checked === 1 ? '' : 's'} were checked. Account ${escapeHtml(account.code || '')} has a credit balance of ${escapeHtml(humanMoney(account.creditBalanceCents))}. Confirm whether it remains payable, has already been remitted, or requires reconciliation.`; }
      return `${checked} liability account${checked === 1 ? '' : 's'} were checked against posted balances.`;
    }
    if (key === 'period_lock') return facts.locked ? `The selected month ending ${escapeHtml(facts.periodEnd || '')} is locked.` : `${escapeHtml(String(facts.periodEnd || 'The selected month'))} is not locked. Lock it only after every review item is complete.`;
    const summaries = [];
    Object.entries(facts).forEach(([name, value]) => {
      if (/id|hash|revision|service|policy|formalclose/i.test(name)) return;
      const label = name.replace(/([A-Z])/g, ' $1').replace(/_/g, ' ').replace(/^./, (letter) => letter.toUpperCase());
      if (/cents$/i.test(name) && Number.isFinite(Number(value))) summaries.push(`${label.replace(/ cents$/i, '')}: ${humanMoney(value)}`);
      else if (typeof value === 'boolean') summaries.push(`${label}: ${value ? 'Yes' : 'No'}`);
      else if (typeof value === 'string' && value && !/^[a-z]+_[a-f0-9-]{12,}$/i.test(value)) summaries.push(`${label}: ${escapeHtml(value)}`);
      else if (typeof value === 'number') summaries.push(`${label}: ${value.toLocaleString()}`);
      else if (Array.isArray(value)) summaries.push(`${label}: ${value.length} item${value.length === 1 ? '' : 's'}`);
    });
    return summaries.slice(0, 4).join('. ') || 'Tegh compared the current company records required for this check.';
  }

  async function refreshMonthAttestation(page) {
    const period = page.querySelector('[data-close-period] input[type="month"]')?.value; const host = page.querySelector('[data-month-attestation]'); if (!period || !host) return;
    host.setAttribute('aria-busy', 'true');
    try {
      const response = await shellApi('native-agent/month-end-attestations', {params: {period}}); const current = response.currentAttestation;
      host.innerHTML = `<div class="srp-section-title"><div><small>Stage 4 · External records</small><h2>Confirm External Records</h2><p>This confirmation is append-only and becomes stale if the month’s evidence changes.</p></div>${current ? '<span class="srp-status active">Current confirmation recorded</span>' : '<span class="srp-status warning">Confirmation needed</span>'}</div>
        <form data-month-attest-form><label class="tegh-attestation-check"><input type="checkbox" name="confirmed" required><span>${escapeHtml(response.statement)}</span></label><label>Optional review note<textarea name="note" rows="3" maxlength="500"></textarea></label><button type="submit" class="srp-btn" ${current ? 'disabled' : ''}>${current ? 'Already Confirmed for Current Evidence' : 'Confirm Reviewed'}</button></form>
        ${(response.attestations || []).length ? `<details class="tegh-audit-details"><summary>Confirmation history</summary><ul>${response.attestations.map((item) => `<li><b>${item.current ? 'Current' : 'Stale'}</b> · ${escapeHtml(item.attestedAt)}${item.note ? ` · ${escapeHtml(item.note)}` : ''}</li>`).join('')}</ul></details>` : ''}`;
      const form = host.querySelector('[data-month-attest-form]'); if (form && !current) form.onsubmit = async (event) => { event.preventDefault(); if (!form.reportValidity()) return; const submit = form.querySelector('button'); submit.disabled = true; try { await shellApi('native-agent/month-end-attestations', {method: 'POST', json: {period, acknowledgement: response.statement, note: form.note.value}}); showShellNotice('External records confirmed', 'The signed confirmation was retained. No entry was posted and the month was not locked.'); await refreshMonthAttestation(page); } catch (error) { submit.disabled = false; showShellNotice('Confirmation not recorded', error.message, 'error'); } };
      page.dataset.teghEvidenceHash = response.currentEvidenceHash || '';
      page.dataset.teghEvidenceRevision = response.currentSourceRevisionHash || '';
    } catch (error) { host.innerHTML = `<p class="tegh-health-error"><b>Confirmation status unavailable.</b>${escapeHtml(error.message)}</p>`; }
    finally { host.setAttribute('aria-busy', 'false'); }
  }

  function enhanceMonthEnd(page) {
    if (!page?.matches?.('[data-srp-page="month-end-close"]')) return;
    const title = page.querySelector('.srp-page-head h1'), description = page.querySelector('.srp-page-head p');
    if (title && title.textContent !== 'Month-End Review & Lock') title.textContent = 'Month-End Review & Lock';
    const monthDescription = 'Tegh checks whether the selected month appears complete and ready for review. It does not post adjustments or lock the month automatically.';
    if (description && description.textContent !== monthDescription) description.textContent = monthDescription;
    const body = page.querySelector('.srp-page-body'), hero = body?.querySelector('.tegh-close-hero'); if (!body || !hero) return;
    if (!body.querySelector('[data-month-stages]')) hero.insertAdjacentHTML('beforebegin', `<ol class="tegh-month-stages" data-month-stages aria-label="Month-end review stages"><li class="is-current"><b>1</b><span>Choose Month</span></li><li><b>2</b><span>Run Readiness Review</span></li><li><b>3</b><span>Complete Required Actions</span></li><li><b>4</b><span>Confirm External Records</span></li><li><b>5</b><span>Review Reports and Lock Period</span></li></ol>`);
    const heroTitle = hero.querySelector('h2'), heroCopy = hero.querySelector('p'); if (heroTitle && heroTitle.textContent !== 'Review the month in five clear stages.') heroTitle.textContent = 'Review the month in five clear stages.'; if (heroCopy && heroCopy.textContent !== 'Every result comes from current-company records. Items Tegh cannot verify stay clearly marked for your review.') heroCopy.textContent = 'Every result comes from current-company records. Items Tegh cannot verify stay clearly marked for your review.';
    if (!body.querySelector('[data-month-attestation]')) { const section = document.createElement('section'); section.className = 'srp-card tegh-month-attestation'; section.dataset.monthAttestation = '1'; section.innerHTML = '<p>Loading external-record confirmation…</p>'; const results = body.querySelector('[data-close-results]'); results?.insertAdjacentElement('beforebegin', section); void refreshMonthAttestation(page); const form = body.querySelector('[data-close-period]'); form?.addEventListener('submit', () => window.setTimeout(() => refreshMonthAttestation(page), 80)); }
    body.querySelectorAll('.tegh-close-checklist > article').forEach((article) => {
      if (article.dataset.teghHumanized === BUILD) return; article.dataset.teghHumanized = BUILD;
      const status = article.querySelector('.tegh-close-status'); if (status) status.textContent = monthStatusLabels[status.textContent.trim()] || status.textContent;
      const oldDetails = article.querySelector('details'); let facts = {}; try { facts = JSON.parse(oldDetails?.querySelector('pre')?.textContent || '{}'); } catch (_) {}
      const key = article.querySelector('[data-close-item]')?.dataset.closeItem || '';
      if (oldDetails) oldDetails.outerHTML = `<p class="tegh-month-facts"><b>What Tegh found</b>${humanMonthFacts(key, facts)}</p><p class="tegh-month-why"><b>Why it matters</b>Completing this review helps keep the month’s reports and balances supportable.</p><details class="tegh-audit-details"><summary>Audit Details</summary><dl><div><dt>Evidence hash</dt><dd>${escapeHtml(String(page.dataset.teghEvidenceHash || 'Refresh confirmation status').slice(0, 18))}</dd></div><div><dt>Evidence revision</dt><dd>${escapeHtml(String(page.dataset.teghEvidenceRevision || 'Current review run').slice(0, 28))}</dd></div></dl></details>`;
      const action = article.querySelector('[data-close-item]'); if (action && key === 'period_lock') action.textContent = 'Review and Lock Period'; else if (action) action.textContent = 'Review Source';
    });
  }

  function enhanceClippedLabels(scope = document) {
    scope.querySelectorAll?.('.sidebar button,.tegh-top-module-menu button,.tegh-top-more-menu button,.srp-tabs button').forEach((node) => {
      if (node.scrollWidth <= node.clientWidth + 1) { node.removeAttribute('data-tegh-clipped-label'); return; }
      node.dataset.teghClippedLabel = node.textContent.replace(/\s+/g, ' ').trim(); node.setAttribute('aria-description', `Full label: ${node.dataset.teghClippedLabel}`);
    });
  }

  function enhance(scope = document) {
    if (!scope?.querySelectorAll) return;
    enhanceCards(scope);
    enhanceSteppers(scope);
    enhanceUtilityRail(scope);
    enhanceSideNavigation(scope);
    if(scope===document||scope.matches?.('.tegh-top-primary,.sidebar')||scope.closest?.('.tegh-top-primary,.sidebar')||scope.querySelector?.('.tegh-top-primary,.sidebar'))enhanceSettingsNavigation();
    enhanceQuickActionDashboard(scope);
    const monthEnd=scope===document?document.querySelector('.srp-page[data-srp-page="month-end-close"]'):(scope.matches?.('.srp-page[data-srp-page="month-end-close"]')?scope:scope.closest?.('.srp-page[data-srp-page="month-end-close"]')||scope.querySelector?.('.srp-page[data-srp-page="month-end-close"]'));enhanceMonthEnd(monthEnd);
    enhanceClippedLabels(scope);
    scope.querySelectorAll('.srp-global-search, .search, .tegh-utility-search')
      .forEach((item) => { item.dataset.teghPolishedSearch = 'true'; });
    scope.querySelectorAll('.profile-menu, .srp-company-menu, .srp-columns-panel, .srp-notice-menu')
      .forEach((item) => { item.dataset.teghPolishedPopover = 'true'; });
  }

  function scheduleEnhancement(scope = document) {
    const candidate=scope instanceof Element||scope instanceof Document?scope:document;
    if(!scheduledScope)scheduledScope=candidate;else if(scheduledScope!==document){if(candidate===document||candidate.contains?.(scheduledScope))scheduledScope=candidate;else if(!scheduledScope.contains?.(candidate))scheduledScope=document}
    if(scheduledFrame)return;
    scheduledFrame = window.requestAnimationFrame(() => {const next=scheduledScope||document;scheduledScope=null;scheduledFrame=0;enhance(next)});
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      installGlobalShortcut();
      installNavigationMode();
      installSuccessorShortcuts();
      installBackToTop();
      void syncQuickActions();
      scheduleEnhancement();
    }, { once: true });
  } else {
    installGlobalShortcut();
    installNavigationMode();
    installSuccessorShortcuts();
    installBackToTop();
    void syncQuickActions();
    scheduleEnhancement();
  }

  window.addEventListener('tegh:enhance-subtree',event=>scheduleEnhancement(event.detail?.root||document));
  window.addEventListener('tegh:page-rendered',event=>scheduleEnhancement(event.detail?.page||document));
  window.addEventListener('tegh:navigation-layout-changed',()=>scheduleEnhancement(document.querySelector('.app')||document));
  window.addEventListener('tegh:quick-actions-changed',()=>void syncQuickActions());
  window.addEventListener('pageshow',()=>scheduleEnhancement(document));
})();
