(() => {
  'use strict';

  const VERSION = '5.9.5';
  const BUILD = '5950';
  const ORDER = ['Dashboard','Banking','Receivables','Payables','General ledger','Payroll','Reports','Advanced accounting'];
  const DISPLAY = {
    Dashboard: 'Overview',
    'General ledger': 'General ledger',
    'Advanced accounting': 'Advanced accounting',
    'My account': 'Settings',
  };
  const GLYPHS = {
    Dashboard: '⌂',
    Receivables: 'AR',
    Payables: 'AP',
    Payroll: 'PL',
    Banking: '▤',
    'General ledger': 'GL',
    'Advanced accounting': 'AA',
    Reports: '⌁',
    'My account': '⚙',
  };
  const ALIASES = {
    Dashboard: ['overview', 'dashboard', 'book pulse', 'all modules'],
    Receivables: ['receivables'],
    Payables: ['payables'],
    Payroll: ['payroll'],
    Banking: ['banking'],
    'General ledger': ['general ledger'],
    'Advanced accounting': ['advanced accounting', 'advanced'],
    Reports: ['reports'],
    'My account': ['settings', 'my account'],
    Expenses: ['expenses'],
  };

  const text = (node) => String(node?.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();

  function detectLabel(button) {
    const assigned = String(button?.dataset?.srpLabel || '');
    if (assigned && (ORDER.includes(assigned) || assigned === 'Expenses')) return assigned;
    const value = text(button);
    if (!value) return '';
    if (/\bexpense vouchers?\b/.test(value)) return '';
    for (const [label, aliases] of Object.entries(ALIASES)) {
      if (aliases.some((alias) => value === alias || value.includes(alias))) return label;
    }
    return '';
  }

  function removeLegacyExpenses(root = document) {
    let removed = 0;
    root.querySelectorAll('.sidebar nav > button, .sidebar .side-foot > button').forEach((button) => {
      if (detectLabel(button) !== 'Expenses') return;
      const submenu = button.nextElementSibling;
      if (submenu?.classList.contains('srp-subnav')) submenu.remove();
      button.remove();
      removed += 1;
    });
    if (removed || !root.querySelector('.sidebar nav > button[data-srp-label="Expenses"]')) {
      document.documentElement.dataset.srExpensesMenu = 'removed';
    }
    return removed;
  }

  function decorateButton(button, label) {
    if (!button || !label || label === 'Expenses') return;
    button.dataset.srpLabel = label;
    button.dataset.srpSitesNav = '1';
    button.removeAttribute('aria-controls');
    button.removeAttribute('aria-expanded');
    button.querySelector('.srp-chevron')?.remove();
    let code = button.querySelector('.nav-code');
    if (!code) {
      code = document.createElement('span');
      code.className = 'nav-code';
      button.prepend(code);
    }
    const glyph = GLYPHS[label] || '•';
    if (code.textContent !== glyph) code.textContent = glyph;
    let copy = button.querySelector('.nav-copy');
    if (!copy) {
      copy = document.createElement('span');
      copy.className = 'nav-copy';
      button.append(copy);
    }
    let strong = copy.querySelector('b');
    if (!strong) {
      strong = document.createElement('b');
      copy.prepend(strong);
    }
    const visible = DISPLAY[label] || label;
    if (strong.textContent !== visible) strong.textContent = visible;
    copy.querySelectorAll('small').forEach((node) => node.remove());
    button.title = visible;
    button.setAttribute('aria-label', visible);
  }

  function normalizeBrand(sidebar) {
    let brand = sidebar.querySelector(':scope > .brand') || sidebar.querySelector('.brand');
    if (!brand) {
      brand = document.createElement('div');
      brand.className = 'brand';
      sidebar.prepend(brand);
    }
    if (brand.dataset.srpSitesBrand !== BUILD) {
      brand.dataset.srpSitesBrand = BUILD;
      brand.className = 'brand srp-restored-brand srp-sites-brand';
      brand.title = 'TEGH - The Efficient General Ledger Hub';
      brand.innerHTML = '<img class="brand-mark tegh-brand-image" src="/assets/tegh-mark-v4600.svg?v=4600" alt=""><span class="brand-word"><strong>TEGH</strong><small>The Efficient General Ledger Hub</small></span>';
    }
  }

  function normalizeCompanySwitcher() {
    // The portal owns the authenticated company allow-list and selected-company
    // state. This shell only adds compact visual identity; it does not read or
    // reinterpret company IDs from browser storage.
    document.querySelectorAll('.srp-company-trigger').forEach((button) => {
      const label = button.querySelector('b');
      const name = label?.textContent?.trim() || 'Company';
      const initials = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase() || 'T';
      let avatar = button.querySelector('.srp-sites-company-avatar');
      if (!avatar) {
        avatar = document.createElement('i');
        avatar.className = 'srp-sites-company-avatar';
        button.prepend(avatar);
      }
      if (avatar.textContent !== initials) avatar.textContent = initials;
      const small = button.querySelector('small');
      if (small) { small.textContent = ''; small.hidden = true; }
    });
  }

  function normalizeTopbarActions() {
    // Build 4700 ownership: the portal is the sole notification-bell renderer,
    // data source and click-handler owner. Sites shell only normalizes profile chrome.
    const profile = document.querySelector('.profile > button');
    if (profile) {
      profile.classList.add('srp-sites-profile');
      profile.setAttribute('aria-label', profile.textContent?.trim() || 'Signed-in account');
    }
  }

  function normalizePageHead() {
    document.querySelectorAll('.srp-page-head > div').forEach((heading) => {
      if (heading.querySelector('.srp-sites-eyebrow')) return;
      const page = heading.closest('.srp-page');
      const raw = String(page?.dataset?.srpModule || page?.dataset?.srpPage || 'Workspace');
      const label = raw === 'My account' ? 'Settings' : raw === 'Reports' ? 'Reporting' : raw.replace(/^module-/, '').replace(/-/g, ' ');
      const eyebrow = document.createElement('span');
      eyebrow.className = 'srp-sites-eyebrow';
      eyebrow.textContent = label || 'Workspace';
      heading.prepend(eyebrow);
    });
  }

  function normalize() {
    // 3.0.0: the portal renderer is the single authority for mode-dependent
    // navigation. The Sites shell now normalizes only shared chrome/branding.
    document.documentElement.dataset.teghShell = 'sites-v12';
    document.documentElement.dataset.teghUiBuild = BUILD;
    const sidebar = document.querySelector('.sidebar');
    if (!sidebar) return;
    normalizeBrand(sidebar);
    removeLegacyExpenses(document);
    const sideFoot = sidebar.querySelector('.side-foot') || sidebar.appendChild(Object.assign(document.createElement('div'), {className: 'side-foot'}));
    let release = sideFoot.querySelector('.srp-sites-release');
    if (!release) {
      release = document.createElement('div');
      release.className = 'srp-sites-release';
      sideFoot.append(release);
    }
    if (release.dataset.srpSitesRelease !== BUILD) {
      release.dataset.srpSitesRelease = BUILD;
      release.innerHTML = `<span></span><small>Version ${VERSION}</small>`;
    }
    normalizeCompanySwitcher();
    normalizeTopbarActions();
    normalizePageHead();
  }

  let queued = false;
  function schedule() {
    if (queued) return;
    queued = true;
    requestAnimationFrame(() => {
      queued = false;
      normalize();
    });
  }

  window.SRBooksSitesShell = {
    enabled: false,
    version: VERSION,
    build: BUILD,
    order: [...ORDER],
    glyphs: {...GLYPHS},
    detectLabel,
    decorateButton,
    removeLegacyExpenses,
    normalize,
    schedule,
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', schedule, {once: true});
  else schedule();
  window.addEventListener('tegh:page-rendered', schedule);
  window.addEventListener('tegh:navigation-layout-changed', schedule);
  window.addEventListener('tegh:company-changed', schedule);
})();
