(() => {
  'use strict';

  const VERSION = '5.9.9';
  const BUILD = '5990';
  const ASSET_REVISION = '5990-r130-tegh';
  const AUTH_CACHE_MS = 60000;
  const POST_COMMIT_SESSION_GRACE_MS = 45000;
  const RECOVERY_MARKER_KEY = 'tegh-session-recovery-v5990';
  const STARTUP_RETRY_KEY = 'tegh-startup-retry-v5990';
  const BASE_MODULE = '/assets/index-BsxPiq85-v2817.js';
  const REQUIRED_INTERFACE_MODULES = [
    '/assets/tegh-advanced-v5800.js',
    '/assets/tegh-sites-shell-v5990.js',
    '/assets/tegh-downloads-r15.js',
    '/assets/tegh-activity-r22.js',
    '/assets/tegh-registers-r23.js',
    '/assets/tegh-invoice-reports-r20.js',
  '/assets/tegh-portal-v5990.js',
    '/assets/tegh-professional-output-v5990.js',
    '/assets/tegh-workflow-v5700.js',
    '/assets/tegh-workspace-r17.js',
    '/assets/tegh-workspace-r18.js',
    '/assets/tegh-r27.js',
    '/assets/tegh-reference-r29.js',
  ];
  // Only shared enhancements belong in the idle queue. Feature workspaces load
  // on demand; workflow remains required because it guards unsaved forms.
  const OPTIONAL_INTERFACE_MODULES = [
    '/assets/tegh-modern-v5700.js',
    '/assets/tegh-premium-v5700.js',
  ];
  const FEATURE_MODULES = Object.freeze({
    'native-ap-ar':'/assets/tegh-native-ap-ar-v5600.js',
    'payroll-tax':'/assets/tegh-payroll-tax-v5100.js',
    ocr:'/assets/tegh-native-ocr-v5220.js',
    forecast:'/assets/tegh-forecast-v5300.js',
  });
  // One promise per allowlisted feature prevents concurrent requests from
  // installing multiple observers or handlers. Failed loads remain retryable.
  function createFeatureLoader(importer) {
    const pending = new Map();
    return function loadFeature(name) {
      if (!Object.prototype.hasOwnProperty.call(FEATURE_MODULES, name)) {
        return Promise.reject(new Error('This Tegh feature is not registered.'));
      }
      if (pending.has(name)) return pending.get(name);
      const promise = Promise.resolve().then(() => importer(FEATURE_MODULES[name])).then(ok => {
        if (!ok) throw new Error('This Tegh feature could not load. Please try again.');
        return true;
      }).catch(error => { pending.delete(name); throw error; });
      pending.set(name, promise);
      return promise;
    };
  }
  window.TeghLoadFeature = createFeatureLoader(moduleUrl => loadExtension(moduleUrl, true));
  function installDeferredOCR() {
    if (window.TeghNativeOCR) return;
    const call = method => async (...args) => {
      await window.TeghLoadFeature('ocr');
      const runtime = window.TeghNativeOCR;
      if (!runtime || runtime.deferred || typeof runtime[method] !== 'function') {
        throw new Error('The local document extraction runtime did not initialize.');
      }
      return runtime[method](...args);
    };
    window.TeghNativeOCR = Object.freeze({
      deferred:true, localOnly:true,
      extract:call('extract'), loadManifest:call('loadManifest'),
      verifyAssets:call('verifyAssets'), verifyRuntimeGroups:call('verifyRuntimeGroups'),
    });
  }
  installDeferredOCR();
  const escHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
  const rawFetch = window.fetch.bind(window);
  const trackConversion = (event) => {
    try {
      rawFetch('/api/index.php?route=marketing/event', {method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',keepalive:true,body:JSON.stringify({event})}).catch(()=>{});
    } catch (_) {}
  };
  const params = new URL(window.location.href).searchParams;
  const forceSignIn = params.get('forceSignIn') === '1' || params.get('signin') === '1' || document.documentElement.dataset.signedOut === '1';
  const forceRegister = params.get('register') === '1';
  // `forceSignIn=1` is only a one-load UI instruction from public Sign In links.
  // Keep the behavior, but remove the implementation flag from the visible URL.
  if (forceSignIn && window.history?.replaceState) {
    try {
      const cleanUrl = new URL(window.location.href);
      cleanUrl.searchParams.delete('forceSignIn');
      cleanUrl.searchParams.delete('signin');
      window.history.replaceState(window.history.state, '', cleanUrl.pathname + cleanUrl.search + cleanUrl.hash);
    } catch (_) {}
  }
  const safeMode = params.get('safeMode') === '1';
  const inviteToken = params.get('accountSetup') || params.get('invite') || '';
  const passwordResetToken = params.get('passwordReset') || '';
  let inviteDetails = null;
  let sessionController = null;
  let opening = false;
  let appOpened = false;
  let returningToSignIn = false;
  let lastAuth = null;
  let lastWorkspace = null;
  window.addEventListener('tegh:invalidate-scope',()=>{sessionController?.abort();lastAuth=null;lastWorkspace=null;lastCompanyId='';cacheExpiresAt=0;authCacheExpiresAt=0;lastVerifiedSessionAt=0;});
  window.addEventListener('pageshow',event=>{if(event.persisted){lastAuth=null;lastWorkspace=null;cacheExpiresAt=0;authCacheExpiresAt=0;location.replace('/app.html')}});
  let lastCompanyId = '';
  let cacheExpiresAt = 0;
  let authCacheExpiresAt = 0;
  let lastVerifiedSessionAt = 0;
  let lastImportCommitAt = 0;
  let progressStartedAt = 0;
  let progressTicker = 0;
  let firstCompanyReloadScheduled = false;
  let reportingClientIncident = false;
  let sessionProbePromise = null;
  let coreSessionRecoveryStarted = false;
  let coreSessionRepairStarted = false;
  let coreSessionRepairTimer = 0;
  let workspaceClosingStartedAt = 0;
  let authSecurity = {enabled:false,configured:false,provider:'turnstile',siteKey:'',registrationRequired:false,loginMode:'off',loginFailureThreshold:2,loginChallengeNow:false};
  let authSecurityPromise = null;
  let turnstileScriptPromise = null;
  let loginFailuresThisPage = 0;
  const captchaState = {
    login:{widgetId:null,token:''},
    register:{widgetId:null,token:''},
  };

  document.documentElement.dataset.srGateRunning = BUILD;

  const $ = (selector) => document.querySelector(selector);
  const gate = $('#sr-gate');
  const loginForm = $('#sr-login-form');
  const setupForm = $('#sr-setup-form');
  const registerForm = $('#sr-register-form');

  const inviteForm = $('#sr-invite-form');
  const inviteSummary = $('#sr-invite-summary');
  const forgotPasswordLink = $('#sr-forgot-password');
  ensureTwoChoiceGate();
  const showRegisterButton=$('#sr-choose-register');
  const choiceButtons=document.querySelectorAll('.sr-gate-choice button');
  const showLoginButtons=document.querySelectorAll('[data-show-login]');
  const progress = $('#sr-gate-progress');
  const statusText = $('#sr-gate-status');
  const substatusText = $('#sr-gate-substatus');
  const errorBox = $('#sr-gate-error');
  const errorTitle = $('#sr-gate-error-title');
  const errorMessage = $('#sr-gate-error-message');
  const retryButton = $('#sr-gate-retry');
  const sessionLoader = $('#sr-session-loader');
  const sessionLoaderTitle = sessionLoader?.querySelector('h1');
  const sessionLoaderDetail = sessionLoader?.querySelector('p');

  function beginWorkspaceClosing() {
    workspaceClosingStartedAt = Date.now();
    document.documentElement.dataset.srWorkspaceTransition = 'closing';
    if (sessionLoader) sessionLoader.setAttribute('aria-label', 'Closing Your Workspace');
    if (sessionLoaderTitle) sessionLoaderTitle.textContent = 'Closing Your Workspace';
    if (sessionLoaderDetail) sessionLoaderDetail.textContent = 'Securing your work and ending this session…';
    document.documentElement.classList.add('sr-gate-session-pending');
  }

  function restoreOpeningTransition() {
    workspaceClosingStartedAt = 0;
    delete document.documentElement.dataset.srWorkspaceTransition;
    if (sessionLoader) sessionLoader.setAttribute('aria-label', 'Opening Your Workspace');
    if (sessionLoaderTitle) sessionLoaderTitle.textContent = 'Opening Your Workspace';
    if (sessionLoaderDetail) sessionLoaderDetail.textContent = 'Restoring your secure workspace…';
    if (!opening && !coreSessionRecoveryStarted) document.documentElement.classList.remove('sr-gate-session-pending');
  }

  async function holdWorkspaceClosing(minimumMs = 450) {
    const remaining = Math.max(0, minimumMs - (Date.now() - workspaceClosingStartedAt));
    if (remaining) await new Promise(resolve => window.setTimeout(resolve, remaining));
  }

  function recentSessionEvidence(now = Date.now()) {
    return !!lastAuth && now - Math.max(lastVerifiedSessionAt, lastImportCommitAt) < POST_COMMIT_SESSION_GRACE_MS;
  }

  function recentImportCommit(now = Date.now()) {
    return !!lastAuth && lastImportCommitAt > 0 && now - lastImportCommitAt < POST_COMMIT_SESSION_GRACE_MS;
  }

  // A committed import may be followed by a short-lived auth transport
  // mismatch on shared hosting. The legacy core can briefly select its own
  // sign-in component even though the gate still has fresh authenticated
  // evidence. Re-run the core auth hook against the gate's valid cache in the
  // current document. This preserves the Bank Import/Review screen and avoids
  // the full-page Opening Tegh recovery used by earlier builds.
  function restoreCoreSessionInPlace(trigger = 'post_commit_core_repair') {
    if (!recentImportCommit() || returningToSignIn) return false;
    const restore = window.TeghCoreReauthenticate;
    if (typeof restore !== 'function') return false;
    if (coreSessionRepairStarted) return true;
    coreSessionRepairStarted = true;
    coreSessionRecoveryStarted = false;
    window.clearTimeout(coreSessionRepairTimer);
    document.documentElement.classList.remove('sr-gate-session-pending');
    document.documentElement.classList.add('sr-post-commit-session-repair');
    try {
      Promise.resolve(restore()).catch((error) => {
        reportClientIncident(error instanceof Error ? error : new Error('In-place session repair failed.'), 'auth/me', String(trigger || 'post_commit_core_repair'));
      });
    } catch (error) {
      reportClientIncident(error instanceof Error ? error : new Error('In-place session repair failed.'), 'auth/me', String(trigger || 'post_commit_core_repair'));
    }
    const started = Date.now();
    const finish = () => {
      if (!coreSignInVisible() || Date.now() - started >= 2600) {
        document.documentElement.classList.remove('sr-post-commit-session-repair');
        coreSessionRepairStarted = false;
        coreSessionRepairTimer = 0;
        return;
      }
      coreSessionRepairTimer = window.setTimeout(finish, 50);
    };
    coreSessionRepairTimer = window.setTimeout(finish, 0);
    return true;
  }

  function readRecoveryMarker() {
    try {
      const marker = JSON.parse(window.sessionStorage.getItem(RECOVERY_MARKER_KEY) || 'null');
      if (!marker || Number(marker.at || 0) < Date.now() - 30000) return {count:0, at:0};
      return {count:Math.max(0, Number(marker.count || 0)), at:Number(marker.at || 0)};
    } catch (_) {
      return {count:0, at:0};
    }
  }

  function markStartupReady() {
    document.documentElement.classList.remove('sr-gate-session-pending');
    document.documentElement.dataset.srStartup = 'ready';
    try { window.sessionStorage.removeItem(STARTUP_RETRY_KEY); } catch (_) {}
    window.setTimeout(() => {
      if (!coreSignInVisible()) {
        try { window.sessionStorage.removeItem(RECOVERY_MARKER_KEY); } catch (_) {}
      }
    }, 8000);
  }

  // Signing out, or losing a session, must always land on THIS sign-in screen.
  // The core application bundle carries its own sign-in view, which offers no
  // way to create an account and leaves the assistant mounted on the page.
  // Whenever the session ends we tear that state down and reload the gate.
  function returnToSignIn(reason = 'confirmed_session_end') {
    if (returningToSignIn) return;
    returningToSignIn = true;
    document.documentElement.dataset.srSignInNavigation = 'pending';
    window.clearTimeout(coreSessionRepairTimer);
    coreSessionRepairStarted = false;
    document.documentElement.classList.remove('sr-post-commit-session-repair');
    try {
      window.sessionStorage.setItem('tegh-last-signin-reason', String(reason || 'confirmed_session_end').slice(0, 120));
      window.localStorage.removeItem('tegh-session-hint');
      window.localStorage.removeItem('sr-accountax-company');
      window.sessionStorage.removeItem(RECOVERY_MARKER_KEY);
    } catch (_) {}
    document.querySelectorAll('.srt-launcher,.srt-panel,.sra-overlay,.srp-notice-menu').forEach((node) => node.remove());
    const target = '/app.html';
    if (window.location.pathname + window.location.search === target) window.location.reload();
    else window.location.replace(target + (window.location.hash || ''));
    window.setTimeout(() => {
      returningToSignIn = false;
      delete document.documentElement.dataset.srSignInNavigation;
      document.documentElement.classList.remove('sr-gate-session-pending');
    }, 8000);
  }

  function sessionProbeUrl() {
    const url = new URL('/api/index.php', window.location.origin);
    url.searchParams.set('route', 'auth/me');
    url.searchParams.set('v', BUILD);
    url.searchParams.set('probe', String(Date.now()));
    return url.pathname + url.search;
  }

  async function verifyServerSession(trigger = 'session_check') {
    if (sessionProbePromise) return sessionProbePromise;
    sessionProbePromise = (async () => {
      try {
        const response = await rawFetch(sessionProbeUrl(), {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: {'X-SR-Session-Probe': String(trigger || 'session_check').slice(0, 80)},
        });
        const authState = String(response.headers.get('X-Tegh-Auth-State') || '').toLowerCase();
        const contentType = String(response.headers.get('content-type') || '').toLowerCase();
        let body = {};
        if (contentType.includes('application/json')) {
          try { body = await response.clone().json(); } catch (_) {}
        }
        if (response.ok && body && typeof body === 'object' && body.csrfToken) {
          lastAuth = body;
          lastVerifiedSessionAt = Date.now();
          authCacheExpiresAt = lastVerifiedSessionAt + AUTH_CACHE_MS;
          const company = selectedCompany(body);
          if (company) {
            lastCompanyId = company;
            try { window.localStorage.setItem('sr-accountax-company', company); } catch (_) {}
          }
          try { window.localStorage.setItem('tegh-session-hint', '1'); } catch (_) {}
          return {state:'valid', status:response.status, body};
        }
        const code = String(body?.code || '');
        if (response.status === 401 && (authState === 'required' || authState === 'expired'
            || code === 'authentication_required' || code === 'session_expired')) {
          return {state:'invalid', status:response.status, code, authState};
        }
        return {state:'unknown', status:response.status, code, authState};
      } catch (error) {
        return {state:'unknown', status:0, code:'session_probe_failed', error};
      }
    })();
    try { return await sessionProbePromise; }
    finally { sessionProbePromise = null; }
  }

  function recoverVerifiedSession(trigger = 'core_signin_false_positive') {
    if (coreSessionRecoveryStarted || returningToSignIn) return;
    const previous = readRecoveryMarker();
    // Never leave the browser in a same-document recovery loop. One normal
    // restart and one core-only restart are the maximum automatic attempts.
    if (previous.count >= 2) {
      coreSessionRecoveryStarted = true;
      document.documentElement.classList.remove('sr-gate-session-pending');
      return;
    }
    coreSessionRecoveryStarted = true;
    // Keep the recovery loader above the gate while the document is replaced.
    // A verified session must never expose the sign-in form, even for the
    // short transition needed to rehydrate the legacy core bundle.
    document.documentElement.classList.add('sr-gate-session-pending');
    const count = previous.count + 1;
    try {
      window.sessionStorage.setItem(RECOVERY_MARKER_KEY, JSON.stringify({
        count,
        at:Date.now(),
        reason:String(trigger || 'core_signin_false_positive').slice(0, 120),
      }));
    } catch (_) {}
    const hash = window.location.hash || '#tegh=dashboard';
    const fallback = count > 1 ? '&safeMode=1' : '';
    window.location.replace(`/app.html?sessionRecovery=${Math.min(count, 2)}${fallback}` + hash);
    // Navigation should replace this document. If a browser extension or an
    // unload guard cancels it, release the overlay instead of staying stuck.
    window.setTimeout(() => {
      coreSessionRecoveryStarted = false;
      document.documentElement.classList.remove('sr-gate-session-pending');
    }, 8000);
  }

  async function confirmSessionEnd(trigger = 'session_end_candidate') {
    if (returningToSignIn) return true;
    const result = await verifyServerSession(trigger);
    if (result.state === 'invalid') {
      // A successful authenticated import is stronger evidence than a
      // transient follow-up 401. Keep the committed data and rebuild the UI;
      // never turn that short hosting inconsistency into a logout.
      if (recentImportCommit() && restoreCoreSessionInPlace(`${trigger}:recent_commit`)) {
        return false;
      }
      returnToSignIn(trigger);
      return true;
    }
    if (result.state === 'valid' && coreSignInVisible()) {
      if (!restoreCoreSessionInPlace(trigger)) recoverVerifiedSession(trigger);
    }
    return false;
  }

  // The core bundle uses .auth-shell for both its sign-in form AND the valid
  // first-company wizard. Only .auth-shell containing .auth-form means the
  // session has gone. Treating the generic shell itself as sign-in caused the
  // new-company form to flash and immediately redirect back to this gate.
  function coreSignInVisible() {
    const shell = document.querySelector('.auth-shell');
    return !!shell && !!shell.querySelector('.auth-form') && !shell.querySelector('.company-create');
  }

  function watchForCoreSignIn() {
    let verifying = false;
    const check = async () => {
      if (!appOpened || returningToSignIn || coreSessionRecoveryStarted || verifying || !coreSignInVisible()) return;
      // A successful import has already proved the session. Repair the legacy
      // core in place before paint; do not show the startup loader or navigate.
      if (recentImportCommit() && restoreCoreSessionInPlace('post_commit_core_signin')) return;
      verifying = true;
      // Cover the legacy form before the asynchronous server check so a
      // transient auth render cannot flash on screen.
      document.documentElement.classList.add('sr-gate-session-pending');
      try {
        // Never infer logout from DOM alone. A failed multipart request can make
        // the legacy core briefly render its internal sign-in form even while
        // the server-side Tegh session remains valid. Verify auth/me first.
        await confirmSessionEnd('core_signin_rendered');
      } finally {
        verifying = false;
        if (!returningToSignIn && !coreSessionRecoveryStarted) {
          document.documentElement.classList.remove('sr-gate-session-pending');
        }
      }
    };
    try { new MutationObserver((records) => {
      const signInAdded = records.some((record) => [...record.addedNodes].some((node) => node instanceof Element && (node.matches?.('.auth-shell,.auth-form') || node.querySelector?.('.auth-shell .auth-form'))));
      if (signInAdded) void check();
    }).observe(document.getElementById('root') || document.body, {childList: true, subtree: true}); } catch (_) {}
    void check();
  }

  function routeFor(input) {
    try {
      const requestUrl = typeof input === 'string' || input instanceof URL ? String(input) : input.url;
      const url = new URL(requestUrl, window.location.origin);
      if (url.origin !== window.location.origin || !url.pathname.startsWith('/api/')) return '';
      return url.searchParams.get('route') || url.pathname.replace(/^\/api\/?/, '').replace(/^index\.php\/?/, '');
    } catch (_) {
      return '';
    }
  }

  function methodFor(input, init) {
    return String(init?.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
  }

  function headersFor(input, init) {
    const headers = new Headers(input instanceof Request ? input.headers : undefined);
    new Headers(init?.headers || {}).forEach((value, key) => headers.set(key, value));
    return headers;
  }

  function jsonResponse(data, requestId) {
    return new Response(JSON.stringify(data), {
      status: 200,
      headers: {
        'Content-Type': 'application/json; charset=utf-8',
        'Cache-Control': 'no-store',
        'X-SR-Request-ID': requestId,
      },
    });
  }

  function reportClientIncident(error, fallbackRoute = 'frontend', kind = 'client_error') {
    if (!lastAuth?.csrfToken || reportingClientIncident || !error) return;
    reportingClientIncident = true;
    const url = new URL('/api/index.php', window.location.origin);
    url.searchParams.set('route', 'auth/client-incident');
    url.searchParams.set('v', BUILD);
    const payload = {
      kind,
      route: error.route || fallbackRoute || 'frontend',
      status: Number(error.status || 0),
      code: error.code || kind,
      message: error.internalMessage || error.message || 'A browser-side error occurred.',
      requestId: error.requestId || '',
      stack: error.stack || '',
      pagePath: window.location.pathname,
      version: VERSION,
    };
    rawFetch(url.pathname + url.search, {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token':lastAuth.csrfToken},
      body: JSON.stringify(payload),
      credentials: 'same-origin',
      cache: 'no-store',
      keepalive: true,
    }).catch(() => {}).finally(() => { reportingClientIncident = false; });
  }

  function base64FromBytes(bytes) {
    let binary = '';
    const step = 0x8000;
    for (let offset = 0; offset < bytes.length; offset += step) {
      binary += String.fromCharCode(...bytes.subarray(offset, Math.min(offset + step, bytes.length)));
    }
    return window.btoa(binary);
  }

  async function importJsonRequest(route, payload, requestHeaders, signal) {
    const url = new URL('/api/index.php', window.location.origin);
    url.searchParams.set('route', route);
    url.searchParams.set('v', BUILD);
    const headers = new Headers(requestHeaders || {});
    headers.set('Content-Type', 'application/json');
    headers.set('Accept', 'application/json');
    const response = await rawFetch(url.pathname + url.search, {
      method: 'POST',
      headers,
      body: JSON.stringify(payload),
      credentials: 'same-origin',
      cache: 'no-store',
      signal,
    });
    return response;
  }

  // Shared-hosting security layers can reject authenticated multipart/form-data
  // before PHP receives the request. Bank statement sources therefore travel as
  // small authenticated JSON chunks and are reassembled byte-for-byte in private
  // storage. The accounting import still finalizes in one server-side transaction.
  async function uploadStatementWithoutMultipart(form, requestHeaders, signal) {
    const file = form.get('file');
    if (!(file instanceof Blob)) {
      return new Response(JSON.stringify({error:'Choose a statement file to upload.',code:'upload_missing'}), {
        status: 400,
        headers: {'Content-Type':'application/json; charset=utf-8','Cache-Control':'no-store'},
      });
    }
    const filename = typeof file.name === 'string' && file.name ? file.name : 'statement';
    const bankAccountId = String(form.get('bankAccountId') || '');
    const exchangeRateMicros = String(form.get('exchangeRateMicros') || '');
    const clientExtraction = String(form.get('clientExtraction') || '');
    const mode = String(form.get('mode') || '');
    const openingBalanceCents = String(form.get('openingBalanceCents') || '');
    const closingBalanceCents = String(form.get('closingBalanceCents') || '');

    let response = await importJsonRequest('imports/upload-start', {
      filename,
      size: Number(file.size || 0),
    }, requestHeaders, signal);
    if (!response.ok) return response;
    let start = {};
    try { start = await response.clone().json(); } catch (_) {}
    const uploadId = String(start.uploadId || '');
    const chunkSize = Math.max(128 * 1024, Math.min(512 * 1024, Number(start.chunkSize || 384 * 1024)));
    if (!uploadId) {
      return new Response(JSON.stringify({error:'Tegh could not start the secure statement upload.',code:'upload_start_invalid'}), {
        status: 502,
        headers: {'Content-Type':'application/json; charset=utf-8','Cache-Control':'no-store'},
      });
    }

    let offset = 0;
    while (offset < file.size) {
      const end = Math.min(file.size, offset + chunkSize);
      const bytes = new Uint8Array(await file.slice(offset, end).arrayBuffer());
      response = await importJsonRequest('imports/upload-chunk', {
        uploadId,
        offset,
        data: base64FromBytes(bytes),
      }, requestHeaders, signal);
      if (!response.ok) return response;
      offset = end;
    }

    return await importJsonRequest('imports/upload-finish', {
      uploadId,
      bankAccountId,
      exchangeRateMicros,
      clientExtraction,
      mode,
      openingBalanceCents,
      closingBalanceCents,
    }, requestHeaders, signal);
  }

  function timeoutFor(route) {
    if (route === 'auth/me') return 9000;
    if (route === 'auth/login' || route === 'auth/setup') return 16000;
    if (route === 'workspace') return 38000;
    if (route === 'imports') return 120000;
    if (route === 'startup/prepare') return 88000;
    if (route === 'health') return 7000;
    if (route.startsWith('backup/')) return 120000;
    if (route === 'platform/maintenance' || route === 'platform/rate-check') return 90000;
    return route ? 45000 : 0;
  }

  // The first company is a special onboarding boundary. The core bundle owns
  // the lightweight creation form; the full portal/Tegh AI observer stack is not
  // mounted until a company exists. After a successful create we reload once
  // into the normal verified workspace so all extensions start from a stable DOM.
  function scheduleFirstCompanyReload(response) {
    if (firstCompanyReloadScheduled) return;
    firstCompanyReloadScheduled = true;
    document.documentElement.dataset.srFirstCompany = 'created';
    Promise.resolve()
      .then(() => response.clone().json())
      .then((body) => {
        const companyId = body?.company?.id || body?.companyId || '';
        if (companyId) {
          // Carry the onboarding presentation choice from the pre-company key
          // to the newly created company. This changes UI preference only.
          let pendingMode = '';
          try { pendingMode = normalizeFirstCompanyExperienceMode(window.localStorage.getItem(firstCompanyExperienceKey('new-company')) || ''); } catch (_) {}
          lastCompanyId = companyId;
          try {
            window.localStorage.setItem('sr-accountax-company', companyId);
            if (pendingMode) window.localStorage.setItem(firstCompanyExperienceKey(companyId), pendingMode);
          } catch (_) {}
        }
      })
      .catch(() => {})
      .finally(() => window.setTimeout(() => window.location.replace('/app.html'), 180));
  }

  // This remains active after the application opens. It supplies the verified
  // startup responses to the React app and prevents any API request from
  // leaving the interface behind an endless spinner.
  window.fetch = async (input, init = {}) => {
    const route = routeFor(input);
    const method = methodFor(input, init);
    const headers = headersFor(input, init);
    const now = Date.now();
    const logoutRequest = route === 'auth/logout' && method === 'POST';
    if (logoutRequest) beginWorkspaceClosing();

    if (method === 'GET' && headers.get('X-SR-Test-Run') !== '1') {
      if (route === 'auth/me' && lastAuth && now < authCacheExpiresAt) {
        return jsonResponse(lastAuth, 'startup-auth-cache');
      }
      if (now < cacheExpiresAt && route === 'workspace' && lastWorkspace) {
        const requestedCompany = headers.get('X-Company-Id') || '';
        if (!requestedCompany || requestedCompany === lastCompanyId) {
          return jsonResponse(lastWorkspace, 'startup-workspace-cache');
        }
      }
    }

    const applyMutationEffects = async (response) => {
      if (response.ok && route && route !== 'health') {
        lastVerifiedSessionAt = Date.now();
        authCacheExpiresAt = Math.max(authCacheExpiresAt, lastVerifiedSessionAt + AUTH_CACHE_MS);
      }
      if (response.ok && !['GET','HEAD','OPTIONS'].includes(method)) {
        lastWorkspace = null;
        cacheExpiresAt = 0;
      }
      if (route === 'imports' && method === 'POST' && response.ok) {
        let importBody = {};
        try { importBody = await response.clone().json(); } catch (_) {}
        if (importBody?.preview && !importBody?.batch) return response;
        // The final 201 proves that the authenticated server committed the
        // statement. Keep auth/me stable while React refreshes the larger
        // workspace response; workspace data itself remains uncached.
        lastImportCommitAt = Date.now();
        lastVerifiedSessionAt = lastImportCommitAt;
        authCacheExpiresAt = Math.max(authCacheExpiresAt, lastImportCommitAt + AUTH_CACHE_MS);
        try { window.sessionStorage.setItem('tegh-last-import-commit-v2824', String(lastImportCommitAt)); } catch (_) {}
      }
      if (route === 'auth/logout' && method === 'POST' && response.ok) {
        await holdWorkspaceClosing();
        returnToSignIn('explicit_logout');
      } else if (logoutRequest) {
        restoreOpeningTransition();
      }
      if (route === 'companies' && method === 'POST' && response.ok && !lastCompanyId) {
        scheduleFirstCompanyReload(response);
      }
      // Only Tegh's own authenticated API may decide that the session ended.
      // Hosting/WAF layers can return their own 401 for multipart uploads; those
      // responses must stay on the current screen and surface as request errors
      // instead of being misclassified as a logout.
      if (response.status === 401 && appOpened && !returningToSignIn
          && route && (route === 'auth/me' || !route.startsWith('auth/'))) {
        const authState = String(response.headers.get('X-Tegh-Auth-State') || '').toLowerCase();
        let authCode = '';
        const contentType = response.headers.get('content-type') || '';
        if (contentType.includes('application/json')) {
          try {
            const body = await response.clone().json();
            authCode = String(body?.code || '');
          } catch (_) {}
        }
        const saysAuthEnded = authState === 'required' || authState === 'expired'
          || authCode === 'authentication_required' || authCode === 'session_expired';
        // The legacy core treats any failed auth/me response as a logout and
        // immediately renders its own sign-in form. Re-probe through the raw
        // same-origin channel first so a transient hosting/WAF 401 cannot
        // clear a valid React session and trigger the sign-in flash.
        if (route === 'auth/me') {
          const probe = await verifyServerSession('core_auth_me_401');
          if (probe.state === 'invalid' && !recentSessionEvidence()) {
            returnToSignIn('confirmed_auth_me_401');
            return response;
          }
          if (probe.state === 'valid' && probe.body?.csrfToken) return jsonResponse(probe.body, 'session-probe-recovered');
          return new Response(JSON.stringify({error:'The session check was inconclusive, but Tegh kept you signed in. Please retry.',code:'auth_probe_unconfirmed'}), {
            status: 503,
            headers: {'Content-Type':'application/json; charset=utf-8','Cache-Control':'no-store','X-Tegh-Session-Preserved':'1'},
          });
        }
        if (saysAuthEnded) {
          // A multipart POST can arrive without the normal cookie through some
          // hosting/security layers even though the browser session is still
          // alive. Confirm with a separate same-origin auth/me GET before any
          // forced sign-in redirect.
          const probe = await verifyServerSession(`api_401:${route}`);
          if (probe.state === 'invalid' && !recentSessionEvidence()) {
            returnToSignIn(`confirmed_api_401:${route}`);
            return response;
          }
          const message = route === 'imports'
            ? 'The statement upload request was not accepted, but your Tegh session is still active. Tegh kept you signed in. Retry the upload; if it repeats, the hosting layer may be blocking the multipart request.'
            : 'The request was not accepted, but your Tegh session is still active. Please retry.';
          const preserved = new Error(message);
          preserved.status = response.status;
          preserved.code = probe.state === 'valid' ? 'request_auth_mismatch' : 'request_auth_unconfirmed';
          preserved.route = route;
          reportClientIncident(preserved, route, preserved.code);
          return new Response(JSON.stringify({
            error: message,
            code: preserved.code,
          }), {
            status: 502,
            headers: {'Content-Type':'application/json; charset=utf-8','Cache-Control':'no-store','X-Tegh-Session-Preserved':'1'},
          });
        } else {
          const unexpected = new Error('A server or hosting layer returned HTTP 401 without ending the Tegh session.');
          unexpected.status = 401;
          unexpected.code = 'unexpected_non_auth_401';
          unexpected.route = route;
          reportClientIncident(unexpected, route, 'unexpected_non_auth_401');
          return new Response(JSON.stringify({
            error: route === 'imports'
              ? 'The hosting server rejected the statement upload, but Tegh kept you signed in. Please retry; if it repeats, review the hosting upload/security limits.'
              : 'The hosting server rejected the request, but Tegh kept you signed in.',
            code: 'unexpected_non_auth_401',
          }), {
            status: 502,
            headers: {'Content-Type':'application/json; charset=utf-8','Cache-Control':'no-store','X-Tegh-Session-Preserved':'1'},
          });
        }
      }
      return response;
    };
    if (route === 'imports' && method === 'POST' && init?.body instanceof FormData) {
      const uploadController = init.signal ? null : new AbortController();
      const uploadTimer = uploadController ? window.setTimeout(() => uploadController.abort(), timeoutFor('imports')) : 0;
      try {
        return await applyMutationEffects(await uploadStatementWithoutMultipart(init.body, headers, init.signal || uploadController.signal));
      } catch (error) {
        if (error?.name === 'AbortError' && uploadController?.signal.aborted) {
          const timedOut = new Error('The statement import did not finish within 120 seconds. The server may still have completed it; refresh Bank Review before retrying.');
          timedOut.code = 'statement_import_timeout';
          timedOut.route = 'imports';
          reportClientIncident(timedOut, 'imports', timedOut.code);
          throw timedOut;
        }
        if (error?.name === 'AbortError') throw error;
        const failed = new Error(error?.message || 'The statement upload could not be completed.');
        failed.status = Number(error?.status || 0);
        failed.code = error?.code || 'chunked_statement_upload_failed';
        failed.route = 'imports';
        reportClientIncident(failed, 'imports', failed.code);
        throw error;
      } finally {
        if (uploadTimer) window.clearTimeout(uploadTimer);
      }
    }
    const timeoutMs = timeoutFor(route);
    if (!timeoutMs || init.signal) {
      try { return await applyMutationEffects(await rawFetch(input, init)); }
      catch (error) { if (logoutRequest) restoreOpeningTransition(); throw error; }
    }
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), timeoutMs);
    try {
      const response = await rawFetch(input, {...init, signal: controller.signal, cache: init.cache || 'no-store'});
      return await applyMutationEffects(response);
    } catch (error) {
      if (logoutRequest) restoreOpeningTransition();
      if (controller.signal.aborted) {
        const failure = new Error('The server did not answer in time. Please retry.');
        failure.internalMessage = `The ${route || 'server'} request did not answer within ${Math.ceil(timeoutMs / 1000)} seconds.`;
        failure.code = 'startup_timeout';
        failure.route = route;
        throw failure;
      }
      throw error;
    } finally {
      window.clearTimeout(timer);
    }
  };

  function setProgress(title, detail, busy = false, state = 'ready') {
    if (!progress) return;
    progress.dataset.busy = busy ? '1' : '0';
    progress.dataset.state = state;
    statusText.textContent = title;
    substatusText.dataset.baseText = detail;
    substatusText.textContent = detail;
    window.clearInterval(progressTicker);
    progressTicker = 0;
    if (busy) {
      progressStartedAt = Date.now();
      progressTicker = window.setInterval(() => {
        const seconds = Math.max(1, Math.floor((Date.now() - progressStartedAt) / 1000));
        substatusText.textContent = `${substatusText.dataset.baseText} (${seconds}s)`;
      }, 1000);
    }
  }

  function clearError() {
    if (!errorBox) return;
    errorBox.hidden = true;
    retryButton.hidden = true;
  }

  function friendlyError(error, fallbackRoute = '') {
    let message = error?.message || 'The request could not be completed.';
    const route = error?.route || fallbackRoute || 'startup';
    const status = error?.status || 0;
    const code = error?.code || '';
    if (route === 'frontend') message = 'The Tegh interface did not start. Refresh the page and try again.';
    else if (code === 'upstream_unavailable') message = 'Tegh cannot reach the accounting service right now. Please retry when the connection returns.';
    else if (!status) message = 'Tegh could not reach the server. Check your connection and retry.';
    else if (route === 'startup/migrate' || code.startsWith('schema_upgrade_')) message = error?.message || 'Database upgrade could not be completed. No accounting entries were changed. Structural preparation may be partial; the protected upgrader can resume after the reported issue is corrected.';
    else if (status >= 500) message = 'Tegh could not complete the request. No accounting entries were changed.';
    const titleByCode = {
      upstream_unavailable: 'Accounting service unavailable.',
      account_exists: 'This email already has an account.',
      invalid_credentials: 'Sign-in was not accepted.',
      captcha_required: 'Security check required.',
      captcha_failed: 'Security check was not accepted.',
      captcha_configuration_required: 'Turnstile needs configuration.',
      captcha_provider_unavailable: 'Security check is temporarily unavailable.',
      login_rate_limited: 'Sign-in is temporarily locked.',
      session_expired: 'Your secure session expired.',
      registration_closed: 'Account registration is unavailable.',
    };
    return {
      title: titleByCode[code] || (route === 'workspace'
        ? 'The company workspace did not open.'
        : route === 'startup/prepare'
          ? 'Tegh could not prepare the database.'
          : route === 'frontend'
            ? 'The Tegh interface did not start.'
            : 'Tegh could not complete that request.'),
      message,
      retryable: !status || status >= 500 || code === 'startup_timeout',
    };
  }

  function showError(error, route = '') {
    const info = friendlyError(error, route);
    errorTitle.textContent = info.title;
    errorMessage.textContent = info.message;
    errorBox.hidden = false;
    retryButton.hidden = !info.retryable;
    setProgress(error?.code === 'upstream_unavailable' ? 'Accounting service unavailable.' : 'Sign-in remains available.', 'Review the message below and continue.', false, 'error');
    if (!error?.status || error?.route === 'frontend' || error?.code === 'startup_timeout') {
      reportClientIncident(error, route, error?.route === 'frontend' ? 'frontend_module_failed' : 'client_request_failed');
    }
  }

  async function request(route, options = {}, timeoutMs = timeoutFor(route), extraParams = null) {
    const url = new URL('/api/index.php', window.location.origin);
    url.searchParams.set('route', route);
    url.searchParams.set('v',BUILD);
    if(extraParams) for(const [key,value] of extraParams) url.searchParams.set(key,value);
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), timeoutMs);
    const headers = new Headers(options.headers || {});
    headers.set('X-SR-Startup', VERSION);
    try {
      const response = await rawFetch(url.pathname + url.search, {
        ...options,
        headers,
        signal: controller.signal,
        credentials: 'same-origin',
        cache: 'no-store',
      });
      const text = await response.text();
      let body = {};
      if (text) {
        try { body = JSON.parse(text); }
        catch (_) {
          const error = new Error('The server returned an unreadable response. Please retry.');
          error.internalMessage = `The server returned an unreadable response: ${text.slice(0, 180).replace(/\s+/g, ' ')}`;
          error.status = response.status;
          error.code = 'invalid_server_response';
          error.route = route;
          error.requestId = response.headers.get('X-SR-Request-ID') || '';
          throw error;
        }
      }
      if (!response.ok) {
        const error = new Error(body.error || `The server returned HTTP ${response.status}.`);
        error.status = response.status;
        error.code = body.code || 'request_error';
        error.route = route;
        error.requestId = body.requestId || response.headers.get('X-SR-Request-ID') || '';
        error.body = body;
        throw error;
      }
      return body;
    } catch (error) {
      if (controller.signal.aborted) {
        const timeoutError = new Error('The server did not answer in time. Please retry.');
        timeoutError.internalMessage = `The server did not answer ${route} within ${Math.ceil(timeoutMs / 1000)} seconds.`;
        timeoutError.code = 'startup_timeout';
        timeoutError.route = route;
        throw timeoutError;
      }
      throw error;
    } finally {
      window.clearTimeout(timer);
    }
  }

  function schemaUpgradePending(preparation) {
    const current = Number(preparation?.schemaVersion ?? 0);
    const expected = Number(preparation?.expectedSchemaVersion ?? current);
    return preparation?.degraded === true
      || preparation?.deliveryReviewReady === false
      || preparation?.warningCode === 'schema_upgrade_pending'
      || (expected > 0 && current < expected);
  }

  function platformOwner(auth) {
    return String(auth?.user?.platformRole || '') === 'platform_owner';
  }

  function verifiedSchemaUpgrade(result, expectedSchema) {
    if (result?.preflight) {
      return !!result.ok
        && result.preflight.ready === true
        && Number(result.preflight.markerVersion || result.schemaVersion || 0) === expectedSchema
        && Number(result.preflight.structuralVersion || 0) >= expectedSchema;
    }
    return !!result?.ok
      && Number(result.schemaVersion || 0) >= expectedSchema
      && Number(result.expectedSchemaVersion || expectedSchema) === expectedSchema
      && result.coreReady === true
      && result.accountingNotesReady === true;
  }

  async function resolveSchemaUpgrade(auth, preparation) {
    if (!schemaUpgradePending(preparation) || !platformOwner(auth)) return preparation;

    let preflight = null;
    try {
      const response = await request('startup/migration-preflight', {method:'GET'}, 30000);
      preflight = response?.preflight || null;
    } catch (error) {
      reportClientIncident(error, 'startup/migration-preflight', error?.code || 'schema_preflight_failed');
    }
    const currentSchema = Number(preflight?.markerVersion ?? preparation?.schemaVersion ?? 0);
    const expectedSchema = Number(preparation?.expectedSchemaVersion || preflight?.expectedSchemaVersion || 0);
    // Other optional startup features can be degraded after the protected
    // database migration is complete. The preflight owns this decision.
    if (expectedSchema > 0 && preflight?.ready === true
      && preflight?.contract?.ready !== false
      && currentSchema >= expectedSchema
      && Number(preflight.structuralVersion || 0) >= expectedSchema) {
      try {
        const refreshed = await request('startup/status', {method:'GET'}, 15000);
        if (refreshed?.ok && refreshed.coreReady === true
          && Number(refreshed.schemaVersion || 0) >= expectedSchema) return refreshed;
      } catch (error) {
        reportClientIncident(error, 'startup/status', error?.code || 'schema_status_refresh_failed');
      }
      return preparation;
    }
    const retrySafe = preflight ? preflight.retrySafe === true : false;
    const repairMode = ['marker_41_incomplete','partial_schema_41','schema_41_objects_stale_marker','marker_40_incomplete','partial_schema_40','schema_40_objects_stale_marker','marker_39_incomplete','partial_schema_39','schema_39_objects_stale_marker','supported_objects_stale_marker'].includes(String(preflight?.state || ''));
    let latestReference = String(preflight?.requestReference || '');
    document.documentElement.classList.remove('sr-gate-session-pending');
    const choice = gate?.querySelector('.sr-gate-choice');
    if (choice) choice.hidden = true;
    [loginForm, setupForm, registerForm, inviteForm].forEach((form) => { if (form) form.hidden = true; });
    clearError();

    let panel = document.getElementById('sr-schema-upgrade');
    if (panel) panel.remove();
    panel = document.createElement('section');
    panel.id = 'sr-schema-upgrade';
    panel.className = 'sr-schema-upgrade';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute('aria-labelledby', 'sr-schema-upgrade-title');
    panel.tabIndex = -1;
    panel.innerHTML = `
      <span class="sr-schema-upgrade-kicker">Platform Owner maintenance</span>
      <h2 id="sr-schema-upgrade-title">${repairMode ? 'Database recovery required' : 'Database upgrade required'}</h2>
      <p>Tegh ${VERSION} inspected the retained database before offering any change. ${escHtml(preflight?.stateLabel || 'The protected preflight could not be completed.')}.</p>
      <dl class="sr-schema-upgrade-identity">
        <div><dt>Stored marker</dt><dd>Schema ${currentSchema}</dd></div>
        <div><dt>Highest verified schema</dt><dd>Schema ${Number(preflight?.structuralVersion || 0)}</dd></div>
        <div><dt>Required target</dt><dd>Schema ${expectedSchema}</dd></div>
      </dl>
      <div class="sr-schema-upgrade-safety">
        <b>What this upgrade does</b>
        <p>It runs cumulative, additive and resumable migration steps under Tegh's global database-upgrade lock. It never posts accounting entries. Because MySQL/MariaDB DDL can auto-commit, structural preparation may remain partial if a request is interrupted; the protected upgrader can safely classify and resume it.</p>
      </div>
      <label class="sr-schema-upgrade-confirm">
        <input type="checkbox" data-schema-backup-confirm>
        <span><b>I completed all required backups</b><small>I backed up the website files, the complete MySQL/MariaDB database, and private document/OCR storage without replacing private configuration.</small></span>
      </label>
      <p class="sr-schema-upgrade-status" id="sr-schema-upgrade-status" data-schema-upgrade-status role="status" aria-live="polite">${escHtml(preflight?.stateLabel || 'Preflight evidence is unavailable. No database change has been started.')}</p>
      <div class="sr-schema-upgrade-actions">
        <button type="button" class="sr-gate-primary" data-schema-upgrade-run aria-describedby="sr-schema-upgrade-status" disabled>${repairMode ? 'Retry Protected Recovery' : `Upgrade to Schema ${expectedSchema}`}</button>
        <button type="button" data-schema-upgrade-skip>Open Accounting Only</button>
        <button type="button" data-schema-copy-reference ${latestReference?'':'disabled'}>Copy Reference</button>
        <button type="button" data-schema-diagnostic>View / Download Diagnostic</button>
      </div>
      <small class="sr-schema-upgrade-note">${retrySafe?'The preflight classified this state as safe to resume after backup confirmation.':'Mutation is blocked because preflight did not establish a supported, safe state and required database capabilities.'} Core accounting may be opened only when the server has separately verified it safe.</small>`;
    progress?.before(panel);

    const confirm = panel.querySelector('[data-schema-backup-confirm]');
    const run = panel.querySelector('[data-schema-upgrade-run]');
    const skip = panel.querySelector('[data-schema-upgrade-skip]');
    const copyReference = panel.querySelector('[data-schema-copy-reference]');
    const diagnostic = panel.querySelector('[data-schema-diagnostic]');
    const status = panel.querySelector('[data-schema-upgrade-status]');
    confirm.addEventListener('change', () => { run.disabled = !confirm.checked || !retrySafe; });
    copyReference.addEventListener('click', async () => {
      if (!latestReference) return;
      try { await navigator.clipboard.writeText(latestReference); status.textContent = `Request reference ${latestReference} copied.`; }
      catch (_) { status.textContent = `Copy this request reference manually: ${latestReference}`; }
    });
    diagnostic.addEventListener('click', async () => {
      diagnostic.disabled = true;
      try {
        const result = await request('startup/migration-diagnostic', {method:'GET'}, 30000);
        latestReference = String(result?.diagnostic?.requestReference || latestReference);
        copyReference.disabled = !latestReference;
        const blob = new Blob([JSON.stringify(result, null, 2)], {type:'application/json'});
        const link = document.createElement('a');link.href = URL.createObjectURL(blob);link.download = `Tegh-Schema-${currentSchema}-Diagnostic-${latestReference || BUILD}.json`;link.click();
        window.setTimeout(() => URL.revokeObjectURL(link.href), 1000);
        status.textContent = 'Protected diagnostic downloaded for Platform Owner review.';
      } catch (error) {
        const info = friendlyError(error, 'startup/migration-diagnostic');
        status.textContent = info.message;
      } finally { diagnostic.disabled = false; }
    });
    window.setTimeout(() => panel.focus(), 0);

    return await new Promise((resolve) => {
      skip.addEventListener('click', () => {
        setProgress('Accounting-only mode selected.', `Schema ${currentSchema} remains active; protected database maintenance is still pending.`, false, 'warning');
        document.documentElement.classList.add('sr-gate-session-pending');
        panel.remove();
        resolve(preparation);
      }, {once:true});

      run.addEventListener('click', async () => {
        if (!confirm.checked) return;
        run.disabled = true;
        skip.disabled = true;
        confirm.disabled = true;
        panel.setAttribute('aria-busy', 'true');
        status.removeAttribute('role');
        status.dataset.state = 'busy';
        status.textContent = `${repairMode?'Recovering':'Upgrading'} Schema ${currentSchema} to ${expectedSchema} under the global database-upgrade lock. Do not close this page…`;
        setProgress('Database upgrade is running.', 'Applying the next verified cumulative migration checkpoint…', true);
        try {
          const migrated = await request('startup/migrate', {
            method: 'POST',
            headers: {'X-CSRF-Token': auth.csrfToken || '', 'Content-Type':'application/json'},
            body: JSON.stringify({backupConfirmed:true}),
          }, 90000);
          if (!verifiedSchemaUpgrade(migrated, expectedSchema)) {
            const incomplete = new Error(`Tegh did not confirm that every required Schema ${expectedSchema} structure is ready.`);
            incomplete.status = 503;
            incomplete.code = 'schema_upgrade_incomplete';
            incomplete.route = 'startup/migrate';
            throw incomplete;
          }
          const verified = await request('startup/status', {method:'GET'}, 15000);
          if (!verifiedSchemaUpgrade(verified, expectedSchema)) {
            const unverified = new Error('The read-only verification did not confirm the completed database upgrade.');
            unverified.status = 503;
            unverified.code = 'schema_upgrade_verification_failed';
            unverified.route = 'startup/status';
            throw unverified;
          }
          status.dataset.state = 'success';
          status.setAttribute('role', 'status');
          const completed = Array.isArray(migrated.steps) ? migrated.steps.map(step=>step.id).join(', ') : '';
          status.textContent = `${migrated.alreadyUpToDate?'Database is already up to date.':'Upgrade successful.'} Previous Schema ${migrated.previousSchema}; resulting Schema ${expectedSchema}.${completed?` Completed: ${completed}.`:''}`;
          setProgress('Database upgrade verified.', `Schema ${expectedSchema} is ready. Opening your company workspace…`, false, 'success');
          window.setTimeout(() => {
            document.documentElement.classList.add('sr-gate-session-pending');
            panel.remove();
            resolve(verified);
          }, 450);
        } catch (error) {
          const info = friendlyError(error, error?.route || 'startup/migrate');
          reportClientIncident(error, error?.route || 'startup/migrate', error?.code || 'schema_upgrade_failed');
          latestReference = String(error?.requestId || latestReference);
          copyReference.disabled = !latestReference;
          status.dataset.state = 'error';
          status.setAttribute('role', 'alert');
          status.textContent = `${info.message}${latestReference && !info.message.includes(latestReference) ? ` Reference: ${latestReference.slice(0, 40)}.` : ''}`;
          panel.removeAttribute('aria-busy');
          run.textContent = 'Retry Database Upgrade';
          run.disabled = false;
          skip.disabled = false;
          confirm.disabled = false;
          setProgress('Database upgrade needs attention.', 'Review the diagnostic and retry, or open accounting only if the server verified core accounting as safe.', false, 'error');
        }
      });
    });
  }

  async function loadAuthSecurity(force = false) {
    if (authSecurityPromise && !force) return authSecurityPromise;
    authSecurityPromise = request('auth/security', {method:'GET'}, 8000).then((profile) => {
      if(showRegisterButton)showRegisterButton.hidden=!profile.publicSignupEnabled;
      if(!profile.publicSignupEnabled&&!inviteToken&&registerForm&&!registerForm.hidden)showLogin();
      authSecurity = {...authSecurity, ...(profile || {})};
      authSecurity.loginFailureThreshold = Math.max(1, Number(authSecurity.loginFailureThreshold || 2));
      return authSecurity;
    }).catch((error) => {
      // CAPTCHA configuration discovery must never hide the sign-in form. The
      // server remains authoritative and will require a challenge when needed.
      console.warn('Tegh bot-protection profile could not be loaded.', error?.code || error?.message || error);
      return authSecurity;
    });
    return authSecurityPromise;
  }

  function captchaElements(kind) {
    return {
      wrap: document.querySelector(`[data-captcha-wrap="${kind}"]`),
      slot: document.getElementById(kind === 'login' ? 'sr-login-captcha' : 'sr-register-captcha'),
    };
  }

  function ensureTurnstileScript() {
    if (window.turnstile?.render) return Promise.resolve(window.turnstile);
    if (turnstileScriptPromise) return turnstileScriptPromise;
    turnstileScriptPromise = new Promise((resolve, reject) => {
      const existing = document.querySelector('script[data-tegh-turnstile]');
      if (existing) {
        const started = Date.now();
        const poll = () => {
          if (window.turnstile?.render) return resolve(window.turnstile);
          if (Date.now() - started > 8000) return reject(new Error('Security check did not load.'));
          window.setTimeout(poll, 80);
        };
        poll();
        return;
      }
      const script = document.createElement('script');
      script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
      script.async = true;
      script.defer = true;
      script.dataset.teghTurnstile = '1';
      script.onload = () => window.turnstile?.render ? resolve(window.turnstile) : reject(new Error('Security check did not initialize.'));
      script.onerror = () => reject(new Error('Security check could not be loaded.'));
      document.head.appendChild(script);
    });
    return turnstileScriptPromise;
  }

  async function renderCaptcha(kind) {
    const profile = await loadAuthSecurity();
    const {wrap,slot} = captchaElements(kind);
    if (!wrap || !slot || !profile.enabled) return true;
    const required = kind === 'register'
      ? !!profile.registrationRequired
      : profile.loginMode === 'always' || !!profile.loginChallengeNow || loginFailuresThisPage >= Number(profile.loginFailureThreshold || 2);
    if (!required) { wrap.hidden = true; return true; }
    wrap.hidden = false;
    if (!profile.configured || !profile.siteKey) {
      slot.textContent = 'Security verification is temporarily unavailable. Please contact the Tegh administrator.';
      return false;
    }
    if (captchaState[kind].widgetId !== null) return true;
    try {
      const turnstile = await ensureTurnstileScript();
      slot.textContent = '';
      captchaState[kind].widgetId = turnstile.render(slot, {
        sitekey: profile.siteKey,
        action: kind,
        theme: 'auto',
        size: 'flexible',
        appearance: 'always',
        callback: (token) => { captchaState[kind].token = String(token || ''); },
        'expired-callback': () => { captchaState[kind].token = ''; },
        'timeout-callback': () => { captchaState[kind].token = ''; },
        'error-callback': () => { captchaState[kind].token = ''; },
      });
      return true;
    } catch (error) {
      slot.textContent = 'Security verification could not load. Check your connection and try again.';
      return false;
    }
  }

  function resetCaptcha(kind) {
    captchaState[kind].token = '';
    try {
      if (captchaState[kind].widgetId !== null && window.turnstile?.reset) window.turnstile.reset(captchaState[kind].widgetId);
    } catch (_) {}
  }

  function captchaClientError(message = 'Complete the security check and try again.') {
    return Object.assign(new Error(message), {route:'auth/security', code:'captcha_required', status:403});
  }

  async function initializeCaptchaProtection() {
    const profile = await loadAuthSecurity();
    if (!profile.enabled) return;
    if (profile.loginMode === 'always' || profile.loginChallengeNow) await renderCaptcha('login');
    if (!registerForm?.hidden && profile.registrationRequired) await renderCaptcha('register');
  }

  function selectedCompany(auth) {
    const saved = window.localStorage.getItem('sr-accountax-company') || '';
    if (auth.companies?.some((company) => company.id === saved)) return saved;
    return auth.companies?.[0]?.id || '';
  }

  function waitForWorkspaceShell(timeoutMs = 12000) {
    return new Promise((resolve) => {
      const started = Date.now();
      const check = () => {
        if (document.querySelector('.app,.company-wizard,.auth-shell')) return resolve(true);
        if (Date.now() - started >= timeoutMs) return resolve(false);
        window.setTimeout(check, 80);
      };
      check();
    });
  }

  function importWithTimeout(moduleUrl, timeoutMs = 18000) {
    let timer = 0;
    return Promise.race([
      import(moduleUrl),
      new Promise((_, reject) => {
        timer = window.setTimeout(() => reject(Object.assign(
          new Error(`The interface file did not load within ${Math.ceil(timeoutMs / 1000)} seconds.`),
          {code:'frontend_module_timeout', route:'frontend'}
        )), timeoutMs);
      }),
    ]).finally(() => window.clearTimeout(timer));
  }

  async function loadExtension(moduleUrl, required = false) {
    let lastError = null;
    for (let attempt = 1; attempt <= 2; attempt++) {
      try {
        const suffix = attempt === 1 ? `?v=${ASSET_REVISION}` : `?v=${ASSET_REVISION}&retry=${Date.now()}`;
        await importWithTimeout(moduleUrl + suffix);
        return true;
      } catch (error) {
        lastError = error;
        console.error(`Tegh ${required ? 'required' : 'optional'} interface extension failed (attempt ${attempt}):`, moduleUrl, error);
        // A timed-out dynamic import cannot be cancelled. Do not launch the
        // same module under a second URL while the first browser load may still
        // complete, because that could initialize observers twice.
        if (error?.code === 'frontend_module_timeout') break;
      } finally {
        await new Promise((resolve) => window.setTimeout(resolve, 80));
      }
    }
    window.__TEGH_INTERFACE_ERRORS__ = window.__TEGH_INTERFACE_ERRORS__ || [];
    window.__TEGH_INTERFACE_ERRORS__.push({moduleUrl, required, message:lastError?.message || 'Interface extension failed'});
    return false;
  }

  function coreWorkspaceState() {
    const content = document.querySelector('.stage > .content:not([hidden]),.stage > main.content:not([hidden])');
    if (!content) return {ready:false, legacyHome:false, page:''};
    const page = String(content.dataset.page || '').toLowerCase();
    const text = String(content.textContent || '');
    const legacyHome = page === 'home' || /your books,\s*at a glance/i.test(text);
    return {ready:!!page && !legacyHome, legacyHome, page};
  }

  function markCanonicalInterfaceReady(reason = 'portal') {
    document.documentElement.dataset.srpCanonicalReady = '1';
    document.documentElement.dataset.srpCanonicalReason = String(reason || 'portal').slice(0, 40);
  }

  function requestedTeghRoute() {
    const match = String(window.location.hash || '').match(/(?:^#|&)tegh=([^&]+)/);
    try { return match ? decodeURIComponent(match[1]) : ''; } catch (_) { return match?.[1] || ''; }
  }

  async function waitForCurrentDashboard(timeoutMs = 5200) {
    const started = Date.now();
    let dashboardRequested = false;
    while (Date.now() - started < timeoutMs) {
      const wizard = document.querySelector('.company-wizard,.company-create');
      if (wizard) { markCanonicalInterfaceReady('company-wizard'); return true; }
      const portalPage = document.querySelector('.srp-page[data-srp-page]');
      if (portalPage) { markCanonicalInterfaceReady(portalPage.dataset.srpPage || 'portal'); return true; }

      const requested = requestedTeghRoute();
      const core = coreWorkspaceState();
      if (requested && requested !== 'dashboard' && core.ready) {
        markCanonicalInterfaceReady(`core-${core.page || 'page'}`);
        return true;
      }

      // A normal login must never expose the retired React Home dashboard.
      // If there is no explicit deep link, ask the current portal to mount its
      // dashboard immediately and keep the neutral loader above the core app
      // until that mount is confirmed.
      if (!dashboardRequested && (!requested || requested === 'dashboard') && window.TeghPortal?.openDashboard) {
        dashboardRequested = true;
        try { firstCompanyExperienceMode()==='owner'&&window.TeghPortal?.openGuidedBookkeeping ? window.TeghPortal.openGuidedBookkeeping() : window.TeghPortal.openDashboard(); } catch (_) {}
      }
      await new Promise((resolve) => window.setTimeout(resolve, 50));
    }

    // One final deterministic recovery attempt. Do not treat the old Home
    // screen as "ready" merely because a timeout elapsed.
    try { firstCompanyExperienceMode()==='owner'&&window.TeghPortal?.openGuidedBookkeeping ? window.TeghPortal.openGuidedBookkeeping() : window.TeghPortal?.openDashboard?.(); } catch (_) {}
    const recoveryStarted = Date.now();
    while (Date.now() - recoveryStarted < 1500) {
      const page = document.querySelector('.srp-page[data-srp-page]');
      if (page) { markCanonicalInterfaceReady(page.dataset.srpPage || 'portal-recovery'); return true; }
      await new Promise((resolve) => window.setTimeout(resolve, 50));
    }
    return false;
  }

  // Bookkeeping experience is a presentation preference, not accounting data.
  // v2 scopes the preference by signed-in user + company. Legacy v1 values are
  // read once for backward compatibility; the retired `self` mode maps safely
  // to Full Accounting without touching any company or ledger record.
  function firstCompanyExperienceUser(){return lastAuth?.user?.id||lastAuth?.user?.email||'user'}
  function firstCompanyExperienceLegacyKey(){return `tegh-experience-mode-v1:${firstCompanyExperienceUser()}`}
  function firstCompanyExperienceKey(targetCompanyId=lastCompanyId||'new-company'){return `tegh-experience-mode-v2:${firstCompanyExperienceUser()}:${targetCompanyId||'new-company'}`}
  function normalizeFirstCompanyExperienceMode(mode){return mode==='self'?'accountant':(['owner','accountant'].includes(mode)?mode:'')}
  function firstCompanyExperienceMode(){
    try{
      const key=firstCompanyExperienceKey(),stored=localStorage.getItem(key)||'',normalized=normalizeFirstCompanyExperienceMode(stored);
      if(normalized){if(stored!==normalized)localStorage.setItem(key,normalized);return normalized}
      const legacy=normalizeFirstCompanyExperienceMode(localStorage.getItem(firstCompanyExperienceLegacyKey())||'');
      if(legacy){localStorage.setItem(key,legacy);return legacy}
    }catch{}
    return '';
  }
  function setFirstCompanyExperienceMode(mode){const normalized=normalizeFirstCompanyExperienceMode(mode);if(!normalized)return;try{localStorage.setItem(firstCompanyExperienceKey(),normalized)}catch{}}
  function ownerCompanySetupMarkup(){
    const now=new Date(),year=now.getFullYear(),start=`${year}-01-01`,end=`${year}-12-31`;
    return `<section class="sr-first-owner-setup" data-first-owner-setup><header><span>T</span><div><small>Guided</small><h2>Let’s set up your business.</h2><p>Answer these questions in normal business language. Tegh will create the bookkeeping structure underneath.</p></div><button type="button" data-change-first-mode>Change mode</button></header><form data-first-owner-company-form><div class="sr-first-owner-question"><b>1</b><label><strong>What is your business called?</strong><input name="name" required maxlength="160" autocomplete="organization"></label></div><div class="sr-first-owner-question"><b>2</b><label><strong>What is the legal name?</strong><input name="legalName" maxlength="200" placeholder="Leave blank if it is the same"></label></div><div class="sr-first-owner-question two"><b>3</b><label><strong>What type of business is it?</strong><select name="businessType"><option value="corporation">Corporation</option><option value="sole_proprietor">Sole proprietor</option><option value="partnership">Partnership</option><option value="non_profit">Non-profit</option></select></label><label><strong>Where is the business located?</strong><select name="province">${['AB','BC','MB','NB','NL','NS','NT','NU','ON','PE','QC','SK','YT'].map(code=>`<option ${code==='ON'?'selected':''}>${code}</option>`).join('')}</select></label></div><div class="sr-first-owner-question two"><b>4</b><label><strong>When should Tegh start your books?</strong><input name="booksStartDate" type="date" value="${start}" required></label><label><strong>What is your business year-end?</strong><input name="fiscalYearEndDate" type="date" value="${end}" required></label></div><div class="sr-first-owner-question two"><b>5</b><label class="check"><input name="taxRegistered" type="checkbox"><span><strong>Are you registered for GST/HST?</strong><small>Turn this on only if the business has a GST/HST number.</small></span></label><label data-first-tax-number hidden><strong>What is the GST/HST number?</strong><input name="taxNumber" maxlength="40"></label></div><div class="sr-first-owner-question"><b>6</b><label><strong>Will you use Tegh payroll for employees?</strong><select name="employees"><option value="no">No / not now</option><option value="yes">Yes</option></select></label></div><div class="sr-first-owner-actions"><button class="btn-primary md" type="submit">Create My Company →</button><small>After this is complete, Tegh will open Guided Bookkeeping automatically.</small></div><p class="sr-first-owner-error" data-first-owner-error hidden></p></form></section>`;
  }
  function renderFirstOwnerCompanySetup(wizard){
    wizard.hidden=true;document.querySelector('[data-first-experience-choice]')?.remove();document.querySelector('[data-first-owner-setup]')?.remove();
    const holder=document.createElement('div');holder.innerHTML=ownerCompanySetupMarkup();const setup=holder.firstElementChild;wizard.parentElement?.insertBefore(setup,wizard);const form=setup.querySelector('[data-first-owner-company-form]'),tax=form.taxRegistered,taxWrap=setup.querySelector('[data-first-tax-number]'),error=setup.querySelector('[data-first-owner-error]');tax.onchange=()=>{taxWrap.hidden=!tax.checked;form.taxNumber.required=tax.checked};setup.querySelector('[data-change-first-mode]').onclick=()=>{try{localStorage.removeItem(firstCompanyExperienceKey())}catch{}setup.remove();installFirstCompanyExperience(true)};
    form.onsubmit=async event=>{event.preventDefault();if(!form.reportValidity())return;const button=form.querySelector('button[type="submit"]');button.disabled=true;error.hidden=true;const data=Object.fromEntries(new FormData(form));try{const response=await window.fetch(`/api/index.php?route=companies&v=${BUILD}`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':lastAuth?.csrfToken||''},body:JSON.stringify({name:data.name,legalName:data.legalName||data.name,businessType:data.businessType,province:data.province,currency:'CAD',accountingBasis:'accrual',moduleMode:data.employees==='yes'?'both':'accounting',payrollPostingMode:'draft',fiscalYearEndDate:data.fiscalYearEndDate,booksStartDate:data.booksStartDate,taxRegistered:tax.checked,taxNumber:tax.checked?data.taxNumber:null,coaMode:'default'}),credentials:'same-origin',cache:'no-store'});const body=await response.json().catch(()=>({}));if(!response.ok)throw Error(body.error||'The company could not be created.');button.textContent='Company created · Opening Guided Bookkeeping…'}catch(err){error.textContent=friendlyError(err,'companies').message||'The company could not be created.';error.hidden=false;button.disabled=false}};
  }
  function installFirstCompanyExperience(forceChoice=false){
    const wizard=document.querySelector('.company-create,.company-wizard');if(!wizard)return;const mode=forceChoice?'':firstCompanyExperienceMode();if(mode==='owner'){renderFirstOwnerCompanySetup(wizard);return}if(mode==='accountant'){wizard.hidden=false;return}
    if(document.querySelector('[data-first-experience-choice]'))return;wizard.hidden=true;const choice=document.createElement('section');choice.className='sr-first-experience-choice';choice.dataset.firstExperienceChoice='1';choice.innerHTML=`<header><span>T</span><div><small>Welcome to Tegh</small><h2>How would you like to keep your books?</h2><p>Choose the experience that best matches how you work. You can change it later.</p></div></header><div class="sr-first-experience-options"><button type="button" data-mode="owner"><b>Guided</b><span>Simple bookkeeping with Tegh guidance. Best for business owners and users who do not want to manage accounting structure directly.</span><em>Start Guided →</em></button><button type="button" data-mode="accountant"><b>Full Accounting</b><span>Complete professional accounting workspace for bookkeepers, accountants and advanced users.</span><em>Open Full Accounting →</em></button></div>`;wizard.parentElement?.insertBefore(choice,wizard);choice.querySelectorAll('[data-mode]').forEach(button=>button.onclick=()=>{const selected=button.dataset.mode;setFirstCompanyExperienceMode(selected);choice.remove();if(selected==='owner')renderFirstOwnerCompanySetup(wizard);else wizard.hidden=false});
  }


  async function importApplication() {
    setProgress('Starting the Tegh interface…', 'The verified company data is ready.', true);
    cacheExpiresAt = Date.now() + 30000;
    authCacheExpiresAt = Math.max(authCacheExpiresAt, Date.now() + AUTH_CACHE_MS);
    document.documentElement.dataset.srStartup = 'verified';

    // Render the accounting application first so its menus are usable immediately.
    try {
      await importWithTimeout(`${BASE_MODULE}?v=${BUILD}`, 24000);
    } catch (error) {
      error.route = error.route || 'frontend';
      error.code = error.code || 'frontend_module_failed';
      throw error;
    }
    const rendered = await waitForWorkspaceShell();
    window.clearInterval(progressTicker);
    progressTicker = 0;
    if (!rendered) {
      markStartupReady();
      showError(Object.assign(new Error('The core application loaded, but the interface did not render.'), {
        code: 'interface_not_rendered', route: 'frontend',
      }));
      return;
    }
    // A brand-new account has no company workspace yet. Keep this screen
    // deliberately core-only: loading portal, Tegh AI and dashboard observers on
    // the onboarding DOM can make enhancement code react to its own mutations.
    // The successful companies POST above reloads once into the full workspace.
    const firstCompanyMode = !lastCompanyId && !!document.querySelector('.company-create,.company-wizard');
    if (firstCompanyMode) {
      document.documentElement.dataset.srFirstCompany = '1';
      installFirstCompanyExperience();
      lastWorkspace = null;
      cacheExpiresAt = 0;
      markCanonicalInterfaceReady('first-company');
      markStartupReady();
      appOpened = true;
      watchForCoreSignIn();
      console.info('Tegh first-company setup opened in isolated onboarding mode.');
      return;
    }

    // Safe mode deliberately skips interface add-ons. It is a permanent
    // recovery route for diagnosing browser or extension performance problems.
    if (safeMode) {
      markCanonicalInterfaceReady('safe-mode');
      markStartupReady();
      console.info('Tegh opened in safe mode.');
      appOpened = true;
      watchForCoreSignIn();
      return;
    }

    // Keep the neutral loader in place until the current dashboard and its
    // canonical router are installed. The retired core Home view is never
    // exposed between startup and the current Tegh interface.
    await loadExtension('/assets/tegh-reference-r19.js', true);
    await Promise.all(REQUIRED_INTERFACE_MODULES.map(moduleUrl => loadExtension(moduleUrl, true)));
    const canonicalReady = await waitForCurrentDashboard();
    if (!canonicalReady) {
      throw Object.assign(new Error('The current Tegh dashboard did not finish mounting.'), {
        code:'canonical_interface_not_ready', route:'frontend'
      });
    }
    // Startup data is only a bootstrap snapshot. From this point every new
    // customer, vendor, invoice, bill or other mutation must be visible
    // immediately to subsequent workspace requests.
    lastWorkspace = null;
    cacheExpiresAt = 0;
    markStartupReady();

    // Keep enhancement work off the initial interaction. Feature routes and
    // document extraction never enter this queue.
    const startOptionalExtensions = async () => {
      for (const moduleUrl of OPTIONAL_INTERFACE_MODULES) await loadExtension(moduleUrl);
    };
    if ('requestIdleCallback' in window) {
      window.requestIdleCallback(() => void startOptionalExtensions(), {timeout: 5000});
    } else {
      window.setTimeout(() => void startOptionalExtensions(), 1500);
    }
    const loadPageFeature = event => {
      const page = event?.detail?.page || document.querySelector('.srp-page');
      if (['dashboard','financial-analyst'].includes(page?.dataset?.srpPage)) {
        void window.TeghLoadFeature('forecast').catch(error => console.error('Tegh forecast unavailable:', error.message));
      }
    };
    window.addEventListener('tegh:page-rendered', loadPageFeature);
    loadPageFeature();
    appOpened = true;
    watchForCoreSignIn();
  }

  async function openApplication(auth) {
    if (opening) return;
    opening = true;
    clearError();
    // A manual sign-in starts after the initial saved-session probe has already
    // revealed the gate. Re-arm the neutral startup cover before importing the
    // core React app, otherwise its retired Home dashboard can paint for a
    // frame before the current Tegh portal mounts.
    document.documentElement.classList.add('sr-gate-session-pending');
    delete document.documentElement.dataset.srpCanonicalReady;
    delete document.documentElement.dataset.srpCanonicalReason;
    lastAuth = auth;
    lastVerifiedSessionAt = Date.now();
    authCacheExpiresAt = lastVerifiedSessionAt + AUTH_CACHE_MS;
    window.localStorage.setItem('tegh-session-hint', '1');
    lastCompanyId = selectedCompany(auth);
    if (lastCompanyId) window.localStorage.setItem('sr-accountax-company', lastCompanyId);

    try {
      setProgress('Sign-in accepted.', 'Preparing the database safely. This screen will remain usable…', true);
      let preparation = null;
      let preparationError = null;
      try {
        preparation = await request('startup/prepare', {
          method: 'POST',
          headers: {'X-CSRF-Token': auth.csrfToken || ''},
        }, 88000);
      } catch (error) {
        preparationError = error;
        const status = Number(error?.status || 0);
        const code = String(error?.code || 'startup_prepare_failed');
        // Authentication/authorization failures remain hard failures. For a server-side
        // migration/maintenance failure, prove the established company workspace can
        // still be read before allowing a degraded sign-in.
        if (status && status < 500 && code !== 'schema_upgrade_busy' && code !== 'startup_timeout') throw error;
        if (!lastCompanyId) throw error;
        reportClientIncident(error, 'startup/prepare', 'startup_prepare_degraded_fallback');
        preparation = {
          ok: true,
          degraded: true,
          warningCode: 'startup_prepare_degraded_fallback',
          preparationErrorCode: code,
          preparationRequestId: String(error?.requestId || ''),
        };
      }
      preparation = await resolveSchemaUpgrade(auth, preparation);
      window.__TEGH_STARTUP_PREPARATION__ = preparation || {};
      if (preparation?.degraded) {
        document.documentElement.dataset.srSchemaDegraded = '1';
        console.warn('Tegh opened with a database-maintenance warning. Core accounting remains available; review the Platform Owner incident log before using affected AI features.', preparation.warningCode || 'schema_prepare_degraded');
      } else {
        delete document.documentElement.dataset.srSchemaDegraded;
      }
      if (lastCompanyId) {
        setProgress(preparation?.degraded ? 'Accounting workspace is being verified.' : 'Database is ready.', preparation?.degraded ? 'Database maintenance needs review; verifying that your existing company data is safely readable…' : 'Loading the selected company…', true);
        // This read is the final safety proof for degraded startup. If the established
        // accounting workspace cannot be read, Tegh still fails closed.
        lastWorkspace = await request('workspace', {
          method: 'GET',
          headers: {'X-Company-Id': lastCompanyId},
        }, 38000);
        if (preparation?.degraded) {
          setProgress('Sign-in accepted.', 'Your accounting workspace is available. Database maintenance has been recorded for review.', true);
        }
      } else {
        lastWorkspace = null;
        setProgress('Sign-in accepted.', 'Opening the new-company screen…', true);
      }
      await importApplication();
    } catch (error) {
      opening = false;
      document.documentElement.classList.remove('sr-gate-session-pending');
      loginForm.querySelector('button[type="submit"]').disabled = false;
      showError(error, error?.route || 'workspace');
    }
  }

  function showSetup() {
    showOnlyForm(setupForm);
    document.documentElement.classList.add('sr-setup-only');
    setProgress('Initial setup is required.', 'Create the first Tegh owner account.', false);
  }

  // The sign-in screen offers exactly two choices. If a cached or partially
  // updated index.html is served, the withdrawn controls are removed here and
  // the choice header is created if it is missing, so the screen cannot fall
  // back to the previous layout.
  function ensureTwoChoiceGate() {
    document.getElementById('sr-gate-session')?.remove();
    document.getElementById('sr-show-register')?.remove();
    document.querySelectorAll('#sr-register-form [data-show-login]').forEach(node => node.remove());

    const panel = document.querySelector('.sr-gate-panel');
    if (!panel) return;
    // Rebuild if either choice is missing, not merely if the container is
    // absent - a partly updated page can leave one button behind.
    const existing = panel.querySelector('.sr-gate-choice');
    if (existing && existing.querySelector('#sr-choose-login') && existing.querySelector('#sr-choose-register')) return;
    existing?.remove();
    const header = document.createElement('div');
    header.className = 'sr-gate-choice';
    header.setAttribute('role', 'tablist');
    header.setAttribute('aria-label', 'Sign in or create an account');
    header.innerHTML =
      '<button type="button" role="tab" id="sr-choose-login" data-show-login aria-selected="true" aria-controls="sr-login-form">Sign in</button>' +
      '<button type="button" role="tab" id="sr-choose-register" aria-selected="false" aria-controls="sr-register-form">Create account</button>';
    panel.prepend(header);
  }

  // One form at a time. The hidden attribute is the mechanism and the
  // stylesheet now honours it, but the gate states the intent explicitly so a
  // future styling change cannot quietly stack the forms again.
  function showOnlyForm(active) {
    [loginForm, registerForm, inviteForm, setupForm].forEach((form) => {
      if (!form) return;
      const isActive = form === active;
      form.hidden = !isActive;
      form.setAttribute('aria-hidden', isActive ? 'false' : 'true');
      form.setAttribute('role', 'tabpanel');
      if (form === loginForm) form.setAttribute('aria-labelledby', 'sr-choose-login');
      if (form === registerForm) form.setAttribute('aria-labelledby', 'sr-choose-register');
    });
  }

  function markChoice(which) {
    choiceButtons.forEach(button=>{
      const selected=button.id===(which==='register'?'sr-choose-register':'sr-choose-login');
      button.setAttribute('aria-selected',selected?'true':'false');
      button.classList.toggle('active',selected);
    });
  }

  function showLogin() {
    showOnlyForm(loginForm);
    markChoice('login');
  }

  function removePasswordResetPanel() {
    document.getElementById('sr-password-reset-panel')?.remove();
    document.documentElement.classList.remove('sr-reset-only');
  }

  function passwordResetPanel(innerHtml) {
    removePasswordResetPanel();
    document.documentElement.classList.add('sr-reset-only');
    showOnlyForm(null);
    const panel = document.createElement('form');
    panel.id = 'sr-password-reset-panel';
    panel.className = 'sr-gate-form';
    panel.noValidate = true;
    panel.innerHTML = innerHtml;
    const progressNode = document.getElementById('sr-gate-progress');
    (progressNode?.parentElement || document.querySelector('.sr-gate-panel'))?.insertBefore(panel, progressNode || null);
    return panel;
  }

  function restoreSignInAfterReset() {
    removePasswordResetPanel();
    history.replaceState({}, '', '/app.html');
    showLogin();
    clearError();
    setProgress('Sign-in is ready.', 'Enter your email and password.', false);
    loginForm?.email?.focus();
  }

  function showPasswordResetRequest() {
    clearError();
    const panel = passwordResetPanel(
      '<h2>Reset password</h2>' +
      '<p>Enter the email address for your Tegh account. If an active account exists, Tegh will send a single-use reset link.</p>' +
      '<label>Email address<input name="email" type="email" autocomplete="username" required></label>' +
      '<div class="sr-reset-actions"><button class="sr-gate-primary" type="submit">Send reset link</button><button class="sr-gate-link" type="button" data-reset-back>Back to sign in</button></div>'
    );
    panel.querySelector('[data-reset-back]')?.addEventListener('click', restoreSignInAfterReset);
    panel.addEventListener('submit', async (event) => {
      event.preventDefault();
      clearError();
      if (!panel.reportValidity()) return;
      const button = panel.querySelector('button[type="submit"]');
      if (button) button.disabled = true;
      setProgress('Sending reset link…', 'Checking the request securely.', true);
      try {
        const result = await request('auth/password-reset-request', {
          method: 'POST', headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({email: panel.email.value.trim()}),
        }, 20000);
        panel.innerHTML = '<h2>Check your email</h2><p>' + String(result.message || 'If an active Tegh account exists for that email, a password reset link has been sent.') + '</p><button class="sr-gate-primary" type="button" data-reset-back>Return to sign in</button>';
        panel.querySelector('[data-reset-back]')?.addEventListener('click', restoreSignInAfterReset);
        setProgress('Request received.', 'Reset links expire after 60 minutes and work once.', false);
      } catch (error) {
        if (button) button.disabled = false;
        showError(error, 'auth/password-reset-request');
      }
    });
    setProgress('Reset your password.', 'The reset response does not reveal whether an email is registered.', false);
    panel.email?.focus();
  }

  async function showPasswordResetComplete() {
    clearError();
    const panel = passwordResetPanel('<h2>Reset password</h2><p>Checking this secure reset link…</p>');
    setProgress('Checking reset link…', 'Verifying the single-use token.', true);
    try {
      await request('auth/password-reset-details', {method: 'GET'}, 15000, new URLSearchParams({token: passwordResetToken}));
      panel.innerHTML =
        '<h2>Choose a new password</h2>' +
        '<p>Use at least 12 characters with uppercase, lowercase and a number.</p>' +
        '<label>New password<input name="password" type="password" autocomplete="new-password" required></label>' +
        '<label>Confirm password<input name="confirm" type="password" autocomplete="new-password" required></label>' +
        '<div class="sr-reset-actions"><button class="sr-gate-primary" type="submit">Reset password</button><button class="sr-gate-link" type="button" data-reset-back>Back to sign in</button></div>';
      panel.querySelector('[data-reset-back]')?.addEventListener('click', restoreSignInAfterReset);
      panel.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearError();
        if (!panel.reportValidity()) return;
        if (panel.password.value !== panel.confirm.value) {
          showError(Object.assign(new Error('The two passwords do not match.'), {route:'auth/password-reset-complete',code:'password_mismatch'}));
          return;
        }
        const button = panel.querySelector('button[type="submit"]');
        if (button) button.disabled = true;
        setProgress('Resetting password…', 'Revoking existing sessions and saving the new password.', true);
        try {
          const result = await request('auth/password-reset-complete', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body:JSON.stringify({token:passwordResetToken,newPassword:panel.password.value}),
          }, 20000);
          panel.innerHTML = '<h2>Password reset</h2><p>' + String(result.message || 'Your password has been reset. Sign in again with your new password.') + '</p><button class="sr-gate-primary" type="button" data-reset-back>Sign in</button>';
          panel.querySelector('[data-reset-back]')?.addEventListener('click', restoreSignInAfterReset);
          setProgress('Password reset complete.', 'All previous sessions were revoked.', false);
        } catch (error) {
          if (button) button.disabled = false;
          showError(error, 'auth/password-reset-complete');
        }
      });
      setProgress('Reset link is valid.', 'Choose your new password.', false);
      panel.password?.focus();
    } catch (error) {
      panel.innerHTML = '<h2>Reset link unavailable</h2><p>This password reset link is invalid, expired or already used.</p><button class="sr-gate-primary" type="button" data-reset-back>Return to sign in</button>';
      panel.querySelector('[data-reset-back]')?.addEventListener('click', restoreSignInAfterReset);
      showError(error, 'auth/password-reset-details');
    }
  }

  async function checkSavedSession() {
    if (opening) return;
    sessionController?.abort();
    clearError();
    setProgress('Checking saved sign-in…', 'You can still enter your email and password below.', true);
    try {
      const auth = await request('auth/me', {method: 'GET'}, 9000);
      await openApplication(auth);
    } catch (error) {
      if (error.status === 401) {
        window.localStorage.removeItem('tegh-session-hint');
        document.documentElement.classList.remove('sr-gate-session-pending');
        showLogin();
        setProgress('Sign-in is ready.', 'Enter your email and password.', false);
        return;
      }
      if (error.status === 428 || error.code === 'setup_required') {
        document.documentElement.classList.remove('sr-gate-session-pending');
        showSetup();
        return;
      }
      document.documentElement.classList.remove('sr-gate-session-pending');
      showLogin();
      showError(error, 'auth/me');
    }
  }

  async function confirmFreshSession(auth) {
    let lastError = null;
    for (const delayMs of [0, 650, 1600]) {
      if (delayMs) await new Promise((resolve) => window.setTimeout(resolve, delayMs));
      try {
        const confirmed = await request('auth/me', {method:'GET'}, 9000);
        if (confirmed?.user) {
          return {
            ...(auth || {}),
            ...confirmed,
            csrfToken: confirmed.csrfToken || auth?.csrfToken || '',
          };
        }
      } catch (error) {
        lastError = error;
        if (Number(error?.status || 0) !== 401) throw error;
      }
    }
    throw lastError || Object.assign(new Error('The secure session was not established. Please sign in again.'), {
      status: 401,
      code: 'authentication_required',
      route: 'auth/me',
    });
  }

  loginForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (opening) return;
    clearError();
    const form = event.currentTarget;
    if (!form.reportValidity()) return;
    const profile = await loadAuthSecurity();
    const captchaNeeded = !!profile.enabled && profile.loginMode !== 'off' && (
      profile.loginMode === 'always' || profile.loginChallengeNow || loginFailuresThisPage >= Number(profile.loginFailureThreshold || 2)
    );
    if (captchaNeeded) {
      const ready = await renderCaptcha('login');
      if (!ready || !captchaState.login.token) {
        showError(captchaClientError(), 'auth/login');
        return;
      }
    }
    const button = form.querySelector('button[type="submit"]');
    if (!button) return;
    button.disabled = true;
    setProgress('Signing in…', 'Verifying your Tegh account.', true);
    try {
      const auth = await request('auth/login', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({email: form.email.value.trim(), password: form.password.value, captchaToken: captchaState.login.token || ''}),
      }, 16000);
      loginFailuresThisPage = 0;
      setProgress('Sign-in accepted.', 'Confirming your secure session…', true);
      await openApplication(await confirmFreshSession(auth));
    } catch (error) {
      button.disabled = false;
      if (error.code === 'invalid_credentials') loginFailuresThisPage += 1;
      if (error.code === 'captcha_required' || error.code === 'captcha_failed' || error.code === 'captcha_configuration_required') authSecurity.loginChallengeNow = true;
      if (error.code === 'invalid_credentials' && loginFailuresThisPage >= Number(authSecurity.loginFailureThreshold || 2)) {
        // The next attempt is challenge-gated. Make the Turnstile visibly
        // available immediately instead of waiting for another submit.
        await loadAuthSecurity(true);
        authSecurity.loginChallengeNow = true;
        await renderCaptcha('login');
        resetCaptcha('login');
      } else if (error.code === 'captcha_failed' || error.code === 'captcha_required' || error.code === 'captcha_configuration_required') {
        await loadAuthSecurity(true);
        authSecurity.loginChallengeNow = true;
        await renderCaptcha('login');
        resetCaptcha('login');
      }
      showError(error, 'auth/login');
    }
  });

  forgotPasswordLink?.addEventListener('click',(event)=>{event.preventDefault();showPasswordResetRequest();});
  showRegisterButton?.addEventListener('click',async()=>{const p=await loadAuthSecurity(true);if(!p.publicSignupEnabled){showLogin();return}clearError();showOnlyForm(registerForm);markChoice('register');setProgress('Create your Tegh account.','All released native modules are included; company permissions still apply.',false);await renderCaptcha('register')});
  showLoginButtons.forEach(button=>button.addEventListener('click',()=>{showLogin();setProgress('Sign-in is ready.','Enter your email and password.',false)}));

  registerForm?.addEventListener('submit',async(event)=>{
    event.preventDefault();if(opening)return;clearError();const form=event.currentTarget;if(!form.reportValidity())return;
    if(form.password.value!==form.confirm.value){showError(Object.assign(new Error('The two passwords do not match.'),{route:'auth/register',code:'password_mismatch'}));return}
    const profile=await loadAuthSecurity();
    if(profile.enabled&&profile.registrationRequired){const ready=await renderCaptcha('register');if(!ready||!captchaState.register.token){showError(captchaClientError(),'auth/register');return}}
    const button=form.querySelector('button[type="submit"]');if(!button)return;button.disabled=true;setProgress('Creating your account…','Preparing your secure workspace.',true);
    try{const auth=await request('auth/register',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({displayName:form.displayName.value.trim(),email:form.email.value.trim(),password:form.password.value,acceptTerms:form.acceptTerms.checked,website:form.website?.value||'',captchaToken:captchaState.register.token||''})},20000);trackConversion('signup_completed');await openApplication(await confirmFreshSession(auth))}catch(error){button.disabled=false;resetCaptcha('register');if(error.code==='account_exists'){loginForm.email.value=form.email.value.trim();showLogin();loginForm.password.focus()}showError(error,'auth/register')}
  });

  async function showInvitation(){
    clearError();showOnlyForm(inviteForm);
    document.documentElement.classList.add('sr-invite-only');
    setProgress('Checking account setup…','Confirming the secure link.',true);
    try{
      inviteDetails=await request('platform/invite-details',{method:'GET'},88000,new URLSearchParams({token:inviteToken}));
      const item=inviteDetails.invitation||{};
      inviteForm.invitedEmail.value=item.email||'';
      const invitedCompanies=document.getElementById('sr-invite-companies');if(invitedCompanies)invitedCompanies.textContent=(item.assignments||[]).map(x=>`${x.companyName} — ${x.roleLabel||x.role}`).join(' · ')||(item.scope==='workspace'?'Independent workspace':'');
      const confirm=inviteForm.querySelector('[data-invite-confirm]');if(confirm)confirm.hidden=!!item.existingUser;inviteForm.confirm.required=!item.existingUser;
      const action=inviteForm.querySelector('button[type="submit"]');if(action)action.textContent=item.existingUser?'Confirm Company Access':'Create Account';
      inviteSummary.textContent=item.existingUser?'Enter your current password to confirm this company access.':'Create a password for your Tegh account.';
      if(inviteForm?.password)inviteForm.password.autocomplete=item.existingUser?'current-password':'new-password';
      setProgress('Account setup is ready.','Enter your password to continue.',false);
    }catch(error){showError(error,'platform/invite-details');}
  }

  inviteForm?.addEventListener('submit',async(event)=>{
    event.preventDefault();if(opening)return;clearError();const form=event.currentTarget;if(!form.reportValidity())return;
    const button=form.querySelector('button[type="submit"]');if(!button)return;button.disabled=true;setProgress('Setting up your account…','Opening your secure workspace.',true);
    try{
      const result=await request('platform/invite-accept',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:inviteToken,password:form.password.value,confirmPassword:form.confirm.value,acceptTerms:form.acceptTerms.checked,termsVersion:inviteDetails?.invitation?.termsVersion,privacyVersion:inviteDetails?.invitation?.privacyVersion})},90000);
      if(!result.auth) throw Object.assign(new Error('The account was set up. Sign in using the email that received the setup link.'),{route:'platform/invite-accept',code:'account_setup_signin_required'});
      document.documentElement.classList.remove('sr-invite-only');history.replaceState({},'','/app.html');await openApplication(await confirmFreshSession(result.auth));
    }catch(error){button.disabled=false;showError(error,'platform/invite-accept')}
  });

  setupForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (opening) return;
    clearError();
    const form = event.currentTarget;
    if (!form.reportValidity()) return;
    if (form.password.value !== form.confirm.value) {
      showError(Object.assign(new Error('The two passwords do not match.'), {route: 'auth/setup', code: 'password_mismatch'}));
      return;
    }
    const button = form.querySelector('button[type="submit"]');
    if (!button) return;
    button.disabled = true;
    setProgress('Creating the owner…', 'Preparing the first Tegh account.', true);
    try {
      const auth = await request('auth/setup', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          setupKey: form.setupKey.value,
          displayName: form.displayName.value.trim(),
          email: form.email.value.trim(),
          password: form.password.value,
        }),
      }, 45000);
      await openApplication(await confirmFreshSession(auth));
    } catch (error) {
      button.disabled = false;
      showError(error, 'auth/setup');
    }
  });

  retryButton?.addEventListener('click', checkSavedSession);

  window.addEventListener('error', (event) => {
    if (!opening && !appOpened) return;
    const source = event.error instanceof Error ? event.error : new Error(event.message || 'Unhandled browser error.');
    const error = Object.assign(new Error(source.message || 'Unhandled browser error.'), {
      name: source.name || 'Error',
      stack: source.stack || '',
      route: source.route || 'frontend',
      code: source.code || 'window_error',
    });
    reportClientIncident(error, 'frontend', 'window_error');
  });
  window.addEventListener('unhandledrejection', (event) => {
    if (!opening && !appOpened) return;
    const reason = event.reason;
    const source = reason instanceof Error ? reason : new Error(typeof reason === 'string' ? reason : 'Unhandled browser promise rejection.');
    const error = Object.assign(new Error(source.message || 'Unhandled browser promise rejection.'), {
      name: source.name || 'Error',
      stack: source.stack || '',
      route: source.route || 'frontend',
      code: source.code || 'unhandled_rejection',
    });
    reportClientIncident(error, 'frontend', 'unhandled_rejection');
  });

  // Establish the starting state explicitly rather than trusting the markup.
  if (!inviteToken && !passwordResetToken) showOnlyForm(loginForm);

  window.__SR_STARTUP_GATE__ = {
    version: VERSION,
    checkSavedSession,
    getLastState: () => ({companyId: lastCompanyId, hasAuth: !!lastAuth, hasWorkspace: !!lastWorkspace, opening}),
  };

  initializeCaptchaProtection();

  if (passwordResetToken) {
    document.documentElement.classList.remove('sr-gate-session-pending');
    window.setTimeout(showPasswordResetComplete,100);
  } else if (inviteToken) {
    window.setTimeout(showInvitation,100);
  } else if (forceRegister) {
    document.documentElement.classList.remove('sr-gate-session-pending');
    clearError();
    showOnlyForm(registerForm);
    markChoice('register');
    setProgress('Create your Tegh account.', 'Saved-session opening was skipped by request.', false);
    window.setTimeout(()=>renderCaptcha('register'),0);
  } else if (forceSignIn) {
    document.documentElement.classList.remove('sr-gate-session-pending');
    showLogin();
    setProgress('Sign-in is ready.', 'Saved-session opening was skipped by request.', false);
  } else {
    window.setTimeout(checkSavedSession, 150);
  }
})();
