(() => {
  'use strict';

  const VERSION = '5.1.0';
  const BUILD = '5100';
  const ACTIONS = [
    {
      id: 'native_agent.finding_snooze',
      label: 'Snooze low-risk findings',
      description: 'Hide a current informational or warning finding for an owner-bounded number of days. Evidence changes can surface it again.',
    },
    {
      id: 'native_agent.finding_assign',
      label: 'Assign low-risk findings',
      description: 'Assign a current informational or warning finding to one owner-approved active company member.',
    },
    {
      id: 'native_agent.finding_task',
      label: 'Prepare review tasks',
      description: 'Prepare the existing review workflow for a current finding. Nothing is posted, filed, paid, sent, or finalized.',
    },
    {
      id: 'native_agent.scan_refresh',
      label: 'Refresh specialist evidence',
      description: 'Rerun the relevant deterministic specialist after its evidence and policy are revalidated.',
    },
    {
      id: 'native_agent.finding_resolved_dismiss',
      label: 'Dismiss resolved notices',
      description: 'Dismiss only the notification attached to a deterministically resolved informational finding.',
    },
  ];

  const actionById = new Map(ACTIONS.map(action => [action.id, action]));

  function policyStatus(policy) {
    if (policy.integrityValid === false) return ['Integrity blocked', 'warning'];
    if (policy.suspendedAt) return ['Suspended', 'warning'];
    if (policy.enabled) return ['Enabled', 'active'];
    return ['Disabled', 'draft'];
  }

  function runStatusClass(status) {
    if (status === 'completed') return 'active';
    if (status === 'needs_review') return 'warning';
    return 'draft';
  }

  function findingMarkup(finding, index, helpers) {
    const {esc, titleCasePhrase, formatDateTime} = helpers;
    const evidence = finding.evidence || {};
    const action = finding.proposedAction || {};
    const critical = finding.severity === 'critical';
    return `<article class="srp-card tegh-pt-finding is-${esc(finding.severity || 'info')}">
      <header><div><span class="srp-status ${critical ? 'warning' : 'draft'}">${esc(titleCasePhrase(finding.severity || 'info'))}</span><span class="tegh-pt-agent-label">Payroll/Tax Agent</span></div><time>${esc(formatDateTime(finding.evidenceTimestamp || finding.updatedAt))}</time></header>
      <h2>${esc(finding.title || 'Payroll or tax review')}</h2>
      <p>${esc(finding.explanation || 'Review the current deterministic evidence.')}</p>
      <dl><div><dt>Affected evidence</dt><dd>${Number(evidence.affectedCount || 0)}</dd></div><div><dt>Provider requests</dt><dd>${Number(evidence.providerAttempts || 0)}</dd></div><div><dt>Accounting writes</dt><dd>${Number(evidence.accountingWrites || 0)}</dd></div><div><dt>Autonomy</dt><dd>${critical ? 'Human only' : 'Policy gated'}</dd></div></dl>
      <footer class="srp-actions">${action.available ? `<button type="button" class="srp-btn secondary" data-pt-open-source="${index}">${esc(action.label || 'Open review workflow')}</button>` : ''}<button type="button" class="srp-btn secondary" data-pt-agent-center>Review in Agent Center</button></footer>
    </article>`;
  }

  function policyMarkup(policy, members, helpers) {
    const {esc} = helpers;
    const action = actionById.get(policy.actionId) || {label: policy.actionId, description: 'Bounded workflow metadata action.'};
    const [status, tone] = policyStatus(policy);
    const constraints = policy.constraints || {};
    const memberOptions = members.map(member => `<option value="${esc(member.id)}" ${constraints.assignedUserId === member.id ? 'selected' : ''}>${esc(member.displayName || member.email || 'Company member')}</option>`).join('');
    const assignment = policy.actionId === 'native_agent.finding_assign' ? `<label>Approved assignee<select name="assignedUserId" ${policy.enabled ? 'required' : ''}><option value="">Choose an active member</option>${memberOptions}</select></label>` : '';
    const snooze = policy.actionId === 'native_agent.finding_snooze' ? `<label>Snooze days<input type="number" name="snoozeDays" min="1" max="${Number(policy.maxSnoozeDays || 7)}" step="1" value="${Number(constraints.snoozeDays || 1)}" required></label>` : '';
    return `<form class="srp-card tegh-pt-policy" data-pt-policy="${esc(policy.actionId)}" data-policy-revision="${Number(policy.revision || 0)}">
      <header><div><small>Owner-approved allowlist action</small><h3>${esc(action.label)}</h3></div><span class="srp-status ${tone}">${esc(status)}</span></header>
      <p>${esc(action.description)}</p>
      ${policy.integrityValid === false ? '<p class="tegh-pt-policy-alert" role="alert"><b>Policy integrity check failed.</b> Execution is blocked. Review and save a new owner-signed revision.</p>' : ''}
      <div class="tegh-pt-policy-grid">
        <label class="srp-check"><input type="checkbox" name="enabled" ${policy.enabled ? 'checked' : ''}> Enable this exact action</label>
        <label>Maximum severity<select name="maxSeverity"><option value="info" ${policy.maxSeverity === 'info' ? 'selected' : ''}>Information only</option><option value="warning" ${policy.maxSeverity === 'warning' ? 'selected' : ''}>Information and warning</option></select></label>
        <label>Daily cap<input type="number" name="dailyLimit" min="0" max="100" step="1" value="${Number(policy.dailyLimit || 0)}" required></label>
        <label>Cooling-off minutes<input type="number" name="cooldownMinutes" min="1" max="10080" step="1" value="${Number(policy.cooldownMinutes || 60)}" required></label>
        <label>Maximum snooze days<input type="number" name="maxSnoozeDays" min="1" max="30" step="1" value="${Number(policy.maxSnoozeDays || 7)}" required></label>
        ${assignment}${snooze}
        <label class="srp-check"><input type="checkbox" name="suspended" ${policy.suspendedAt ? 'checked' : ''}> Suspend this policy</label>
        <label class="grow">Suspension reason<input type="text" name="suspensionReason" maxlength="500" value="${esc(policy.suspensionReason || '')}" placeholder="Owner suspension"></label>
      </div>
      <footer><span>Revision ${Number(policy.revision || 0)} · critical findings are permanently excluded</span><button type="submit" class="srp-btn">Save Policy</button></footer>
    </form>`;
  }

  async function openPayrollTaxCenter(adapter, options = {}) {
    const {
      $, $$, api, esc, toast, userFacingError, showPage, moduleDashboard,
      auth, companyAccess, titleCasePhrase, formatDateTime, nativeAgentRun,
      openAgentCenter, openNativeAgentSettings, navigateRegisteredAction,
    } = adapter;
    return showPage('payroll-tax-center', 'Payroll & Tax Center', 'Deterministic payroll and tax readiness with owner-approved, deny-by-default workflow-metadata automation.', async body => {
      const [overview, session] = await Promise.all([api('payroll-tax-agent/overview'), auth()]);
      const access = companyAccess(session) || {};
      const isOwner = access.role === 'owner';
      let members = [];
      if (isOwner) {
        try {
          const response = await api('platform/members');
          members = (response.members || []).filter(member => member.status === 'active');
        } catch (_) {
          const current = session.user || {};
          if (current.id) members = [{id: current.id, displayName: current.displayName || current.email || 'Company Owner'}];
        }
      }
      const findings = Array.isArray(overview.findings) ? overview.findings : [];
      const policies = Array.isArray(overview.autonomyPolicies) ? overview.autonomyPolicies : [];
      const runs = Array.isArray(overview.recentAutonomyRuns) ? overview.recentAutonomyRuns : [];
      const critical = findings.filter(finding => finding.severity === 'critical').length;
      const warning = findings.filter(finding => finding.severity === 'warning').length;
      const enabledPolicies = policies.filter(policy => policy.enabled && !policy.suspendedAt && policy.integrityValid !== false).length;
      body.innerHTML = `<section class="tegh-pt-hero">
        <div><span class="srp-sites-eyebrow">Payroll/Tax Agent · Build ${BUILD}</span><h2>Surface obligations. Never invent authority.</h2><p>Tegh reads current-company payroll, liability, rate, levy, sales-tax and period-lock evidence. It does not infer statutory filing frequency or due dates from incomplete records.</p><div class="tegh-pt-boundaries"><span>Provider requests: 0</span><span>Accounting writes: 0</span><span>Payments and filings: 0</span><span>Rate changes: 0</span></div></div>
        <div class="tegh-pt-hero-actions"><button type="button" class="srp-btn" data-pt-run ${overview.agentEnabled ? '' : 'disabled title="The Payroll/Tax Agent is disabled in company policy."'}>Run Payroll/Tax Agent</button><button type="button" class="srp-btn secondary" data-pt-agent-center>Agent Center</button>${isOwner ? '<button type="button" class="srp-btn secondary" data-pt-agent-settings>Agent Settings</button>' : ''}</div>
      </section>
      <section class="tegh-pt-kpis"><article><small>Current findings</small><b>${findings.length}</b></article><article class="${critical ? 'is-critical' : ''}"><small>Critical · human only</small><b>${critical}</b></article><article class="${warning ? 'is-warning' : ''}"><small>Warnings</small><b>${warning}</b></article><article><small>Enabled metadata policies</small><b>${enabledPolicies} / ${ACTIONS.length}</b></article></section>
      <p class="tegh-pt-review-note" role="note"><b>Official verification remains required.</b> Company review thresholds are workflow prompts, not statutory deadlines. Confirm current requirements with the applicable authority before filing or paying.</p>
      <nav class="tegh-pt-tabs" role="tablist" aria-label="Payroll and tax center sections"><button type="button" role="tab" data-pt-tab="findings" aria-selected="true">Readiness Findings <b>${findings.length}</b></button><button type="button" role="tab" data-pt-tab="autonomy" aria-selected="false">Bounded Autonomy <b>${enabledPolicies}</b></button><button type="button" role="tab" data-pt-tab="history" aria-selected="false">Action History <b>${runs.length}</b></button></nav>
      <section data-pt-panel="findings" class="tegh-pt-findings">${findings.map((finding, index) => findingMarkup(finding, index, {esc, titleCasePhrase, formatDateTime})).join('') || '<section class="srp-card tegh-pt-empty"><span aria-hidden="true">✓</span><h2>No current Payroll/Tax findings</h2><p>Run the specialist to refresh deterministic evidence. This does not certify external compliance or unrecorded evidence.</p></section>'}</section>
      <section data-pt-panel="autonomy" hidden><section class="srp-card tegh-pt-autonomy-boundary"><div><small>Permanent boundary</small><h2>Only five reversible metadata actions</h2><p>Every action requires an owner-enabled exact allowlist policy, fresh finding evidence, live registry permission, severity limits, a daily cap, a cooling-off period and an idempotent execution record.</p></div><ul><li>No critical finding can execute automatically.</li><li>No journal, payroll, tax return, filing, payment, message or rate can be changed.</li><li>Any ambiguous outcome becomes <b>Needs Review</b> and is never auto-retried.</li></ul></section>${isOwner ? `<div class="tegh-pt-policy-list">${policies.map(policy => policyMarkup(policy, members, {esc})).join('')}</div>` : `<section class="srp-card tegh-pt-empty"><h2>Company Owner controls</h2><p>You can review which policies are active, but only the Company Owner can enable, change or suspend bounded autonomy.</p><div class="tegh-pt-readonly-policies">${policies.map(policy => { const meta = actionById.get(policy.actionId); const [status, tone] = policyStatus(policy); return `<article><span class="srp-status ${tone}">${esc(status)}</span><b>${esc(meta?.label || policy.actionId)}</b><small>Revision ${Number(policy.revision || 0)}</small></article>`; }).join('')}</div></section>`}</section>
      <section data-pt-panel="history" hidden><section class="srp-card"><div class="srp-section-title"><div><small>Current-company audit trail</small><h2>Bounded action runs</h2><p>Only attempts that passed policy gates far enough to begin execution appear here.</p></div></div><div class="srp-table-wrap"><table class="srp-table"><thead><tr><th>Started</th><th>Action</th><th>Status</th><th>Policy</th><th>Verified zero-write result</th></tr></thead><tbody>${runs.map(run => `<tr><td>${esc(formatDateTime(run.startedAt))}</td><td>${esc(actionById.get(run.actionId)?.label || run.actionId)}</td><td><span class="srp-status ${runStatusClass(run.status)}">${esc(titleCasePhrase(run.status || 'unknown'))}</span></td><td>Revision ${Number(run.policyRevision || 0)}</td><td>${run.status === 'completed' ? `Accounting ${Number(run.result?.accountingWrites || 0)} · filings ${Number(run.result?.filingsSubmitted || 0)} · payments ${Number(run.result?.paymentsInitiated || 0)} · messages ${Number(run.result?.messagesSent || 0)} · rates ${Number(run.result?.rateChanges || 0)}` : 'Human review required; no retry scheduled'}</td></tr>`).join('') || '<tr><td colspan="5" class="srp-empty">No bounded action has begun for this company.</td></tr>'}</tbody></table></div></section></section>`;

      const tabs = $$('[data-pt-tab]', body);
      const panels = $$('[data-pt-panel]', body);
      const activate = name => {
        tabs.forEach(button => {
          const active = button.dataset.ptTab === name;
          button.setAttribute('aria-selected', String(active));
          button.classList.toggle('active', active);
          button.tabIndex = active ? 0 : -1;
        });
        panels.forEach(panel => { panel.hidden = panel.dataset.ptPanel !== name; });
      };
      tabs.forEach((button, index) => {
        button.onclick = () => activate(button.dataset.ptTab);
        button.onkeydown = event => {
          if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
          event.preventDefault();
          let next = index;
          if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
          if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
          if (event.key === 'Home') next = 0;
          if (event.key === 'End') next = tabs.length - 1;
          tabs[next].focus();
          tabs[next].click();
        };
      });
      $$('[data-pt-agent-center]', body).forEach(button => { button.onclick = () => openAgentCenter({agent: 'payroll_tax'}); });
      $('[data-pt-agent-settings]', body)?.addEventListener('click', openNativeAgentSettings);
      $$('[data-pt-open-source]', body).forEach(button => {
        button.onclick = () => {
          const finding = findings[Number(button.dataset.ptOpenSource)];
          if (finding?.proposedAction?.actionId) navigateRegisteredAction(finding.proposedAction.actionId, finding.proposedInputs || {});
        };
      });
      $('[data-pt-run]', body)?.addEventListener('click', async event => {
        try {
          await nativeAgentRun(['payroll_tax'], '', event.currentTarget);
          toast('Payroll/Tax evidence refreshed', 'Current-company checks and approved metadata policies were revalidated. No accounting record, filing, payment, message, payroll value, or rate was changed.', 'success');
          openPayrollTaxCenter(adapter, {tab: 'findings'});
        } catch (error) {
          toast('Payroll/Tax Agent unavailable', userFacingError(error), 'error');
        }
      });
      $$('[data-pt-policy]', body).forEach(form => {
        const sync = () => {
          const enabled = form.enabled.checked;
          if (enabled && Number(form.dailyLimit.value || 0) < 1) form.dailyLimit.value = '1';
          if (form.assignedUserId) form.assignedUserId.required = enabled;
        };
        form.enabled.addEventListener('change', sync);
        sync();
        form.onsubmit = async event => {
          event.preventDefault();
          if (!form.reportValidity()) return;
          const values = Object.fromEntries(new FormData(form));
          const actionId = form.dataset.ptPolicy;
          const constraints = {};
          if (actionId === 'native_agent.finding_assign') constraints.assignedUserId = values.assignedUserId;
          if (actionId === 'native_agent.finding_snooze') constraints.snoozeDays = Number(values.snoozeDays || 1);
          const submit = $('button[type="submit"]', form);
          submit.disabled = true;
          try {
            const response = await api('payroll-tax-agent/policy', {method: 'PUT', json: {
              actionId,
              expectedRevision: Number(form.dataset.policyRevision || 0),
              enabled: form.enabled.checked,
              maxSeverity: values.maxSeverity,
              dailyLimit: Number(values.dailyLimit || 0),
              cooldownMinutes: Number(values.cooldownMinutes || 60),
              maxSnoozeDays: Number(values.maxSnoozeDays || 7),
              constraints,
              suspended: form.suspended.checked,
              suspensionReason: values.suspensionReason || 'Owner suspension',
            }});
            const saved = (response.policies || []).find(policy => policy.actionId === actionId);
            toast('Bounded policy saved', `Owner-signed revision ${Number(saved?.revision || 0)} is active. This changed only autonomy policy metadata.`, 'success');
            openPayrollTaxCenter(adapter, {tab: 'autonomy'});
          } catch (error) {
            submit.disabled = false;
            toast('Policy not saved', userFacingError(error), 'error');
          }
        };
      });
      activate(options.tab || 'findings');
      if (options.autoRun) {
        try {
          await nativeAgentRun(['payroll_tax'], '', $('[data-pt-run]', body));
          openPayrollTaxCenter(adapter, {tab: 'findings', autoRun: false});
        } catch (error) {
          toast('Payroll/Tax Agent unavailable', userFacingError(error), 'error');
        }
      }
    }, {module: 'Payroll', back: () => moduleDashboard('Payroll'), route: 'payroll-tax-center'});
  }

  window.TeghPayrollTax = Object.freeze({VERSION, BUILD, ACTIONS, openPayrollTaxCenter});
})();
