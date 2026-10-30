(() => {
  'use strict';
  try {
    // R168: each browser tab keeps its own company. The selected company (and the multi-company selection) are read
    // from this tab's sessionStorage first; localStorage keeps the last choice as the default for new tabs. A tab
    // opened from a Tegh link carries the company in the address (#...&co=), so it opens in the same company.
    // Every request still sends the company and the server checks the person's access to it.
    try {
      const KEYS = new Set(['sr-accountax-company','sr-accountax-companies']), TAB = 'tegh-tab:';
      const proto = Storage.prototype, get = proto.getItem, set = proto.setItem, remove = proto.removeItem;
      const local = window.localStorage, session = window.sessionStorage;
      if (local && session && !proto.__teghTabCompany) {
        proto.__teghTabCompany = true;
        proto.getItem = function (key) { if (this === local && KEYS.has(key)) { const tab = get.call(session, TAB + key); if (tab !== null) return tab; } return get.call(this, key); };
        proto.setItem = function (key, value) { if (this === local && KEYS.has(key)) set.call(session, TAB + key, String(value)); return set.call(this, key, value); };
        proto.removeItem = function (key) { if (this === local && KEYS.has(key)) remove.call(session, TAB + key); return remove.call(this, key); };
        // Only a tab's first load takes the company from the address (or, without one, the last company used in any
        // tab); after that the tab's own choice wins, so refreshing never undoes a company switch made in this tab.
        if (get.call(session, TAB + 'sr-accountax-company') === null) {
          const co = new URLSearchParams((location.hash || '').replace(/^#/, '')).get('co');
          const ids = String(co || '').split(',').map(id => id.trim()).filter(id => /^company_[A-Za-z0-9]+$/.test(id)).slice(0, 50);
          if (ids.length) { set.call(session, TAB + 'sr-accountax-company', ids[0]); set.call(session, TAB + 'sr-accountax-companies', JSON.stringify(ids)); }
          else for (const key of KEYS) { const value = get.call(local, key); if (value !== null) set.call(session, TAB + key, value); }
        }
      }
    } catch {}
    // Apply the last explicit shell choice before React and the portal mount.
    // This small global cache avoids a light-theme flash while the company-
    // scoped preference and server copy are still loading.
    try {
      const paintPreference=JSON.parse(localStorage.getItem('tegh-shell-paint-preference-v1')||'{}');
      const choice=['light','dark'].includes(paintPreference.theme)?paintPreference.theme:'auto';
      // R153: "System" follows the device; it is resolved to light or dark so every dark-mode style applies.
      const theme=choice==='auto'?(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'):choice;
      document.documentElement.dataset.teghTheme=theme;document.documentElement.dataset.teghThemeChoice=choice;
      document.documentElement.dataset.teghNavigationLayout=paintPreference.navigationLayout==='top'?'top':'side';
      document.documentElement.style.colorScheme=theme==='dark'?'dark':'light';
    } catch {}
    const params = new URLSearchParams(location.search);
    // R141: invitation and password-reset tokens arrive after '#'.
    new URLSearchParams((location.hash || '').replace(/^#/, '')).forEach((value, key) => { if (['accountSetup','passwordReset'].includes(key) && !params.has(key)) params.set(key, value); });
    const version = '5990';
    const assetRevision = '5990-r174-site';
    const signedOut = params.get('signed-out') === '1';
    if (signedOut) {
      document.documentElement.dataset.signedOut = '1';
      if (window.history?.replaceState) {
        const clean = new URL(location.href);clean.searchParams.delete('signed-out');
        history.replaceState(history.state,'',clean.pathname+clean.search+clean.hash);
      }
    }
    // One-time move of browser settings saved under the product's former
    // name (report periods, sidebar state, tutorial progress) to tegh keys.
    const legacyPrefix = 'srbooks';
    try {
      for (const key of Object.keys(localStorage)) {
        if (!key.startsWith(legacyPrefix)) continue;
        const next = 'tegh' + key.slice(legacyPrefix.length);
        if (localStorage.getItem(next) === null) localStorage.setItem(next, localStorage.getItem(key));
        localStorage.removeItem(key);
      }
    } catch (_) {}
    if (localStorage.getItem('tegh-ui-version') !== assetRevision) {
      [
        'tegh-shell-cache',
        'tegh-navigation-cache',
        'tegh-report-cache',
        'tegh-startup-error',
        'tegh-shell-version',
        'tegh-navigation-version',
        'tegh-last-route',
        'tegh-ui-density'
      ].forEach(key => localStorage.removeItem(key));
      localStorage.setItem('tegh-ui-version', assetRevision);
      if('caches' in window)caches.keys().then(keys=>Promise.all(keys.filter(key=>key.toLowerCase().includes(legacyPrefix)||/^tegh-/i.test(key)).map(key=>caches.delete(key)))).catch(()=>{});
      if('serviceWorker' in navigator)navigator.serviceWorker.getRegistrations().then(rows=>Promise.all(rows.map(row=>row.unregister()))).catch(()=>{});
    }
    // R148: invitation links open the normal sign-in screen on its Create account tab, so no single-form invite mode.
    if(params.has('passwordReset'))document.documentElement.classList.add('sr-reset-only');
    if (!signedOut && !params.has('forceSignIn') && !params.has('accountSetup') && !params.has('invite') && !params.has('passwordReset')) {
      document.documentElement.classList.add('sr-gate-session-pending');
      // If the active gate asset itself is missing or blocked, the neutral
      // loader must not cover the page forever. Retry the no-store HTML once
      // with a unique URL, then reveal the gate with an actionable error.
      window.setTimeout(() => {
        if (!document.documentElement.classList.contains('sr-gate-session-pending')
            || document.documentElement.dataset.srGateRunning === version) return;
        const retryKey = 'tegh-startup-retry-v5990';
        let alreadyRetried = false;
        try { alreadyRetried = sessionStorage.getItem(retryKey) === '1'; } catch {}
        if (!alreadyRetried) {
          try { sessionStorage.setItem(retryKey, '1'); } catch {}
          const retryUrl = new URL(location.href);
          retryUrl.searchParams.set('v', assetRevision);
          retryUrl.searchParams.set('startupRetry', String(Date.now()));
          location.replace(retryUrl.pathname + retryUrl.search + retryUrl.hash);
          return;
        }
        document.documentElement.classList.remove('sr-gate-session-pending');
        const status = document.getElementById('sr-gate-status');
        const detail = document.getElementById('sr-gate-substatus');
        const error = document.getElementById('sr-gate-error');
        const title = document.getElementById('sr-gate-error-title');
        const message = document.getElementById('sr-gate-error-message');
        if (status) status.textContent = 'Tegh startup needs another try.';
        if (detail) detail.textContent = 'The application file did not load.';
        if (title) title.textContent = 'The Tegh interface did not start.';
        if (message) message.textContent = 'Reload this page. Your accounting data and any completed import are unchanged.';
        if (error) error.hidden = false;
      }, 12000);
    }
  } catch {}
})();
