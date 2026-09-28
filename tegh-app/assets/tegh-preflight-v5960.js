(() => {
  'use strict';
  try {
    const params = new URLSearchParams(location.search);
    const version = '5960';
    if (localStorage.getItem('srbooks-ui-version') !== version) {
      [
        'srbooks-shell-cache',
        'srbooks-navigation-cache',
        'srbooks-report-cache',
        'srbooks-startup-error',
        'srbooks-shell-version',
        'srbooks-navigation-version',
        'srbooks-last-route',
        'srbooks-ui-density'
      ].forEach(key => localStorage.removeItem(key));
      localStorage.setItem('srbooks-ui-version', version);
      if('caches' in window)caches.keys().then(keys=>Promise.all(keys.filter(key=>/sr.?books/i.test(key)).map(key=>caches.delete(key)))).catch(()=>{});
      if('serviceWorker' in navigator)navigator.serviceWorker.getRegistrations().then(rows=>Promise.all(rows.map(row=>row.unregister()))).catch(()=>{});
    }
    if(params.has('accountSetup')||params.has('invite'))document.documentElement.classList.add('sr-invite-only');
    if(params.has('passwordReset'))document.documentElement.classList.add('sr-reset-only');
    if (!params.has('forceSignIn') && !params.has('accountSetup') && !params.has('invite') && !params.has('passwordReset')) {
      document.documentElement.classList.add('sr-gate-session-pending');
      // If the active gate asset itself is missing or blocked, the neutral
      // loader must not cover the page forever. Retry the no-store HTML once
      // with a unique URL, then reveal the gate with an actionable error.
      window.setTimeout(() => {
        if (!document.documentElement.classList.contains('sr-gate-session-pending')
            || document.documentElement.dataset.srGateRunning === version) return;
        const retryKey = 'tegh-startup-retry-v5960';
        let alreadyRetried = false;
        try { alreadyRetried = sessionStorage.getItem(retryKey) === '1'; } catch {}
        if (!alreadyRetried) {
          try { sessionStorage.setItem(retryKey, '1'); } catch {}
          const retryUrl = new URL(location.href);
          retryUrl.searchParams.set('v', version);
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
