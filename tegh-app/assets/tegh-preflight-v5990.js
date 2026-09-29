(() => {
  'use strict';
  try {
    // Apply the last explicit shell choice before React and the portal mount.
    // This small global cache avoids a light-theme flash while the company-
    // scoped preference and server copy are still loading.
    try {
      const paintPreference=JSON.parse(localStorage.getItem('tegh-shell-paint-preference-v1')||'{}');
      const theme=['light','dark'].includes(paintPreference.theme)?paintPreference.theme:'auto';
      document.documentElement.dataset.teghTheme=theme;
      document.documentElement.dataset.teghNavigationLayout=paintPreference.navigationLayout==='top'?'top':'side';
      document.documentElement.style.colorScheme=theme==='dark'?'dark':theme==='light'?'light':'light dark';
    } catch {}
    const params = new URLSearchParams(location.search);
    const version = '5990';
    const assetRevision = '5990-r127-tegh';
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
    if(params.has('accountSetup')||params.has('invite'))document.documentElement.classList.add('sr-invite-only');
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
