/* Tegh R22 • whole-application ActivityShell.
 * Presentation and navigation only. No financial requests, success inference,
 * authorization bypass, value rewriting, row cloning, or hidden required inputs.
 */
(() => {
  'use strict';
  if (window.TeghActivityShell) return;
  const $ = (s, root = document) => root?.querySelector(s);
  const $$ = (s, root = document) => [...(root?.querySelectorAll(s) || [])];
  const esc = x => String(x ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const root = document.documentElement;
  root.dataset.teghActivityShell = 'r22';
  root.dataset.teghLayoutRevision = 'r22';
  const states = new WeakMap(), tableStates = new WeakMap(), overlays = new WeakMap(), scrollBindings = new WeakMap(), dragScrollbarBindings = new Set();
  let active = null, frame = 0, sequence = 0, scrollOwnerSequence = 0;

  /*
   * R28 shared overlay manager. Menus must not remain inside a transformed,
   * filtered or overflow-restricted shell: those ancestors change the
   * containing block for position:fixed and clip otherwise valid coordinates.
   * The manager moves the real menu node (and therefore its real handlers) to
   * one top-level host, positions it against the viewport, and restores it to
   * its exact source location when it closes.
   */
  const overlayManager = window.TeghOverlayManager || (() => {
    let current = null, moving = 0;
    const beginMove = () => { moving++; root.dataset.teghOverlayMoving='1'; };
    const endMove = () => setTimeout(() => { moving=Math.max(0,moving-1);if(!moving)delete root.dataset.teghOverlayMoving; },0);
    const focusableSelector = 'a[href],button:not(:disabled),summary,[tabindex]:not([tabindex="-1"]),input:not(:disabled),select:not(:disabled),textarea:not(:disabled)';
    const visible = node => !!node?.isConnected && !!node.getClientRects().length && getComputedStyle(node).visibility !== 'hidden';
    const host = () => {
      let node = document.getElementById('tegh-overlay-root');
      if (!node) {
        node = document.createElement('div');
        node.id = 'tegh-overlay-root';
        node.setAttribute('aria-live', 'off');
        document.body.append(node);
      }
      return node;
    };
    const documentFocusables = () => $$(focusableSelector).filter(node => visible(node) && !node.closest('#tegh-overlay-root'));
    const moveFromTrigger = (trigger, direction) => {
      const nodes = documentFocusables(), at = nodes.indexOf(trigger);
      const next = nodes[at + direction];
      if (next) next.focus({preventScroll:true});
      else trigger?.focus({preventScroll:true});
    };
    function position(source = current?.source) {
      const item = current;
      if (!item || (source && item.source !== source)) return;
      const {trigger,panel,options} = item;
      if (!trigger?.isConnected || !panel?.isConnected) { close(source, false); return; }
      const gutter = Math.max(8, Number(options.gutter) || 10);
      const viewportWidth = document.documentElement.clientWidth || innerWidth;
      const viewportHeight = document.documentElement.clientHeight || innerHeight;
      const anchor = trigger.getBoundingClientRect();
      panel.style.maxWidth = `${Math.max(1, viewportWidth - gutter * 2)}px`;
      const naturalWidth = Math.ceil(Math.max(panel.scrollWidth, panel.getBoundingClientRect().width));
      const width = Math.min(
        Math.max(Number(options.minWidth) || 220, Math.min(Number(options.preferredWidth) || naturalWidth, naturalWidth)),
        Math.max(1, viewportWidth - gutter * 2)
      );
      panel.style.width = `${width}px`;
      const below = Math.max(0, viewportHeight - anchor.bottom - gutter - 6);
      const above = Math.max(0, anchor.top - gutter - 6);
      const wantedHeight = Math.ceil(Math.max(panel.scrollHeight, panel.getBoundingClientRect().height));
      const openAbove = wantedHeight > below && above > below;
      const availableHeight = Math.max(48, openAbove ? above : below);
      const height = Math.min(wantedHeight, availableHeight, Math.max(48, viewportHeight - gutter * 2));
      const preferredLeft = options.align === 'start' ? anchor.left : anchor.right - width;
      const left = Math.max(gutter, Math.min(preferredLeft, viewportWidth - width - gutter));
      const preferredTop = openAbove ? anchor.top - height - 6 : anchor.bottom + 6;
      const top = Math.max(gutter, Math.min(preferredTop, viewportHeight - height - gutter));
      panel.style.left = `${Math.round(left)}px`;
      panel.style.top = `${Math.round(top)}px`;
      panel.style.maxHeight = `${Math.floor(availableHeight)}px`;
      panel.dataset.teghOverlaySide = openAbove ? 'above' : 'below';
    }
    function close(source = current?.source, restoreFocus = true) {
      const item = current;
      if (!item || (source && item.source !== source)) return;
      current = null;
      const {source:owner,trigger,panel,placeholder,layer,resizeObserver,onClose,formControls} = item;
      resizeObserver?.disconnect();
      window.removeEventListener('resize', item.reposition);
      window.removeEventListener('scroll', item.reposition, true);
      try { if (layer.matches(':popover-open')) layer.hidePopover(); } catch (_) {}
      panel.classList.remove('tegh-overlay-surface');
      panel.removeAttribute('data-tegh-overlay-side');
      for (const property of ['left','top','right','bottom','width','maxWidth','maxHeight']) panel.style[property] = '';
      if (placeholder?.parentNode) placeholder.replaceWith(panel);
      else if (owner?.isConnected) owner.append(panel);
      formControls?.forEach(({control,hadForm,formId}) => {
        if (hadForm) control.setAttribute('form',formId);
        else control.removeAttribute('form');
      });
      delete owner.__teghOverlayPanel;
      layer.remove();
      trigger?.setAttribute('aria-expanded','false');
      if (owner?.tagName === 'DETAILS' && owner.open) owner.open = false;
      try { onClose?.(); } catch (_) {}
      if (restoreFocus && visible(trigger)) trigger.focus({preventScroll:true});
      endMove();
    }
    function open({source,trigger,panel,align='end',gutter=10,minWidth=220,preferredWidth,onClose,closeOnSelect=true} = {}) {
      if (!source || !trigger || !panel) return null;
      if (current?.source === source) { position(source); return panel; }
      close(undefined, false);
      beginMove();
      const placeholder = document.createComment('Tegh overlay source position');
      panel.before(placeholder);
      // A portaled invoice message or filter is still part of its original form.
      // Keep native validation, FormData and form.elements working while open.
      const formControls = $$('input,select,textarea,button',panel).filter(control => control.form).map(control => {
        const form = control.form;
        if (!form.id) form.id = `tegh-overlay-form-${++sequence}`;
        const hadForm = control.hasAttribute('form'),formId = control.getAttribute('form');
        control.setAttribute('form',form.id);
        return {control,hadForm,formId};
      });
      const layer = document.createElement('div');
      layer.className = 'tegh-overlay-layer';
      const backstop = document.createElement('div');
      backstop.className = 'tegh-overlay-backstop';
      layer.append(backstop, panel);
      host().append(layer);
      panel.classList.add('tegh-overlay-surface');
      source.__teghOverlayPanel=panel;
      trigger.setAttribute('aria-expanded','true');
      const options = {align,gutter,minWidth,preferredWidth,closeOnSelect};
      const reposition = event => {
        // Scrolling inside a dropdown does not move its anchor.
        if (event?.type === 'scroll' && (event.target === panel || panel.contains(event.target))) return;
        requestAnimationFrame(() => position(source));
      };
      const resizeObserver = typeof ResizeObserver === 'function' ? new ResizeObserver(reposition) : null;
      current = {source,trigger,panel,placeholder,layer,backstop,options,reposition,resizeObserver,onClose,formControls};
      if (typeof layer.showPopover === 'function') {
        layer.setAttribute('popover','manual');
        try { layer.showPopover(); } catch (_) {}
      }
      backstop.addEventListener('pointerdown', event => {
        event.preventDefault();
        event.stopPropagation();
        close(source, true);
      });
      trigger.addEventListener('keydown', event => {
        if (current?.source !== source) return;
        if (event.key === 'Tab' && !event.shiftKey) {
          const first = $$(focusableSelector,panel).find(visible);
          if (first) { event.preventDefault(); first.focus({preventScroll:true}); }
        } else if (event.key === 'Tab' && event.shiftKey) {
          event.preventDefault(); close(source, false); moveFromTrigger(trigger,-1);
        }
      }, {once:true});
      panel.addEventListener('keydown', event => {
        if (current?.source !== source) return;
        const nodes = $$(focusableSelector,panel).filter(visible), at = nodes.indexOf(document.activeElement);
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(source,true); return; }
        if (['ArrowDown','ArrowUp','Home','End'].includes(event.key) && nodes.length) {
          event.preventDefault();
          const index = event.key === 'Home' ? 0 : event.key === 'End' ? nodes.length-1 : (at + (event.key === 'ArrowDown' ? 1 : -1) + nodes.length) % nodes.length;
          nodes[index]?.focus({preventScroll:true});
        } else if (event.key === 'Tab' && ((!event.shiftKey && at === nodes.length-1) || (event.shiftKey && at <= 0))) {
          event.preventDefault();
          const direction = event.shiftKey ? -1 : 1;
          close(source,false); moveFromTrigger(trigger,direction);
        }
      });
      panel.addEventListener('click', event => {
        const action = event.target.closest('[role="menuitem"],a[href],button:not([aria-haspopup="menu"])');
        if (options.closeOnSelect && action && !action.disabled) queueMicrotask(() => { if (current?.source === source) close(source,false); });
      });
      window.addEventListener('resize', reposition, {passive:true});
      window.addEventListener('scroll', reposition, {passive:true,capture:true});
      // Positioning writes the panel's width and height. Observing that same
      // panel retriggers positioning on hover/scroll and makes menus flicker.
      resizeObserver?.observe(trigger);
      position(source);
      endMove();
      return panel;
    }
    const api = Object.freeze({open,close,position,get active(){return current?.source || null;}});
    for (const eventName of ['hashchange','popstate','tegh:page-rendered','tegh:invalidate-scope']) {
      window.addEventListener(eventName, () => close(undefined,false));
    }
    window.TeghOverlayManager = api;
    return api;
  })();
  const tableSelector = 'table.srp-table,table.sra-table,table.srctl-table,table.tegh-authoritative-table,table.r20-profit-table,table.r19-invoice-table,.tegh-review-preview-table table';
  const contracts = [
    {match:/^dashboard$/, kind:'dashboard'},
    {match:/^guided-bookkeeping$/, kind:'guided'},
    {match:/^bank-review$/,kind:'bank-review',split:'.tegh-review-split',panes:'.tegh-review-queue-scroll,.tegh-review-detail-scroll'},
    {match:/^bank-reconciliation$/,kind:'reconciliation',split:'.tegh-recon-workspace',panes:'.tegh-recon-table-scroll',toolbar:'[data-recon-filter]'},
    {match:/^opening-balances$/,kind:'opening',primary:'.r18-opening-table-scroll',toolbar:'[data-opening-filter]'},
    {match:/^customer-invoice$/,kind:'invoice-form',primary:'.srp-ci-lines'},
    {match:/^invoice-detail$/,kind:'invoice-detail'},
    {match:/^payroll-run-new$/,kind:'payrun-form',primary:'[data-eligible-list]'},
    {match:/^payroll-run-edit$/,kind:'payrun-form',primary:'[data-payrun-eligible]'},
    {match:/^gl-journal-entry$/,kind:'journal-form',primary:'[data-lines]'},
    {match:/^customer-payments$|^vendor-payments$|^payments-customer$|^payments-vendor$/,kind:'payment-form',primary:'[data-payment-form] .srp-table-wrap'},
    {match:/^invoices$/,kind:'register',primary:'[data-r23-table-host] .r23-scroll,.r19-register-table,.tegh-responsive-report,.srp-table-wrap',toolbar:'[data-invoice-filter]'},
    {match:/^payroll-runs$|^payroll-history$/,kind:'register',primary:'.srp-table-wrap',toolbar:'[data-payrun-filter]'},
    {match:/^payroll-employees$/,kind:'directory',primary:'.srp-table-wrap'},
    {match:/^bank-transfers$/,kind:'transfer',primary:'.srp-table-wrap'},
    {match:/^financial-accounts$/,kind:'bank-accounts',primary:'.r28-bank-account-list,.srp-table-wrap',toolbar:'[data-bank-directory-controls]'},
    {match:/^report-bank-general-ledger$/,kind:'bank-ledger',primary:'[data-r23-table-host] .r23-scroll,.tegh-responsive-report,.srp-table-wrap',toolbar:'.srp-report-period'},
    {match:/^financial-analyst$/,kind:'analysis'},
    {match:/^financial-|^report-|^trial-balance-|^aging-|^day-book$|^gifi-report$|^cash-flow$/,kind:'report',primary:'[data-r23-table-host] .r23-scroll,[data-r20-profit-grid],.tegh-responsive-report,.srp-table-wrap,.sra-table-wrap',toolbar:'[data-r20-profit-form],.srp-report-period,.srp-register-filter,.srp-filter,.tegh-fa-toolbar'},
    {match:/^ledger-|^customer-ledger$|^vendor-ledger$|^gl-account-ledger$/,kind:'report',primary:'[data-r23-table-host] .r23-scroll,.tegh-responsive-report,.srp-table-wrap',toolbar:'.srp-faq-search,[data-r23-source-filter]'},
    {match:/^customers$|^vendors$|^products$|^chart-of-accounts$/,kind:'directory',primary:'.tegh-responsive-report,.srp-table-wrap',toolbar:'[data-coa-filter]'},
    {match:/^bills$/,kind:'bills',primary:'.tegh-responsive-report,.srp-table-wrap',toolbar:'[data-bill-filter]'},
    {match:/^bank-imports$|^data-import$|^bank-statement-converter$/,kind:'import'},
    {match:/^document-intake$|^collection-drafts$/,kind:'review',split:'.r22-review-split',panes:'.r22-review-queue,.r22-review-detail'},
    {match:/^advanced-/,kind:'advanced',primary:'.sra-table-wrap'},
    {match:/^payroll-remittance$/,kind:'remittance'},
    {match:/^payroll-quick-calculator$/,kind:'calculator'},
    {match:/^payroll-verification$/,kind:'verification',primary:'.srp-table-wrap'},
    {match:/^reports-centre$/,kind:'catalogue'},
    {match:/./,kind:'activity'}
  ];
  const shown = n => !!n?.isConnected && !!n.getClientRects().length && !n.closest('[hidden]') && getComputedStyle(n).display !== 'none' && getComputedStyle(n).visibility !== 'hidden';
  const appendOnce = (parent, child) => { if (child && child.parentElement !== parent) parent.append(child); };
  const guidance = s => {let n=$(':scope > .r22-guidance',s.work);if(!n){n=document.createElement('aside');n.className='r22-guidance';n.setAttribute('aria-label','Page guidance');s.work.prepend(n);}return n;};
  const norm = text => String(text || '').replace(/[^a-z0-9]+/gi,' ').trim().toLowerCase();
  function contract(page) {
    const id=page.dataset.srpPage||page.dataset.teghRoute||'activity';
    return contracts.find(c=>c.match.test(id));
  }
  function details(label, cls='') {
    const n=document.createElement('details'); n.className='r22-menu '+cls;
    n.innerHTML=`<summary>${esc(label)}</summary><div class="r22-menu-panel"></div>`;
    return n;
  }
  const menuPanel = control => control?.__teghOverlayPanel || $('.r22-menu-panel',control);
  function focusSafe(n) { if(n?.isConnected && shown(n)) n.focus({preventScroll:true}); }
  function bindMenu(control) {
    if(control.dataset.r22MenuBound)return;control.dataset.r22MenuBound='1';
    control.addEventListener('toggle',()=>{
      const trigger=$(':scope > summary',control);trigger?.setAttribute('aria-expanded',String(control.open));
      if(control.open){$$('details.r22-menu[open]').forEach(n=>{if(n!==control&&!n.contains(control)&&!control.contains(n))n.open=false;});const panel=$(':scope > .r22-menu-panel',control);overlayManager.open({source:control,trigger,panel,minWidth:control.classList.contains('r22-row-actions')?220:260,preferredWidth:control.classList.contains('r22-row-actions')?260:440});schedule();}
      else overlayManager.close(control,false);
    });
    control.addEventListener('keydown',e=>{
      if(e.key==='Escape'){e.preventDefault();e.stopPropagation();control.open=false;focusSafe($(':scope > summary',control));}
    });
  }
  function positionMenu(control) {
    if(control?.open) overlayManager.position(control);
  }
  // R122: page headings are written by more than one layer. Writing only
  // when the text actually changes stops a MutationObserver ping-pong that
  // made titles flicker (Edit Product alternated with "Edit item" ~60x/s).
  const setHeading=head=>{const h=$('h1',head);return {set textContent(value){if(h&&h.textContent!==String(value))h.textContent=String(value)}}};
  function titleAndHelp(page,s) {
    const head=s.head;
    if(!head.dataset.r22Authored){
      const title=$('h1',head),intro=$(':scope > div:first-child > p',head),actions=$('.tegh-page-head-actions',head);
      s.title=title?.textContent||page.dataset.srpTitle||page.dataset.srpPage||'Workspace';s.description=intro?.textContent||'';
      const titleBox=document.createElement('div');titleBox.className='r22-title';
      if(title)titleBox.append(title);else titleBox.innerHTML=`<h1>${esc(s.title)}</h1>`;
      const controls=document.createElement('div');controls.className='r22-toolbar-controls';
      const tools=actions||document.createElement('div');tools.classList.add('tegh-page-head-actions');
      const back=$('.srp-back',tools);if(back){
        back.title=back.textContent.trim()||'Back';back.setAttribute('aria-label',back.title);
        back.innerHTML='<svg aria-hidden="true" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>';
        back.classList.add('r22-return');
      }
      const primary=document.createElement('div');primary.className='r22-toolbar-primary';
      if(back)primary.append(back);
      primary.append(titleBox,tools);s.baseTools=new Set([...tools.children]);
      head.replaceChildren(primary,controls);head.classList.add('r22-toolbar');head.dataset.r22Authored='1';
    }
    if(s.head.parentElement!==s.body){
      // A route-owned rerender may replace the body. Retain the real back/export
      // handlers, but discard detached old filter forms, never copy their values.
      if(s.everMounted){
        $('.r22-toolbar-controls',head)?.replaceChildren();
        const tools=$('.tegh-page-head-actions',head);[...(tools?.children||[])].filter(n=>!s.baseTools?.has(n)).forEach(n=>n.remove());
        $$('.r22-record-scope,.r22-applied-scope',head).forEach(n=>n.remove());
      }
      s.body.prepend(head);
    }
  }
  function attachWorkspace(page,s) {
    titleAndHelp(page,s);
    let work=$(':scope > .r22-workspace',s.body);
    if(!work){work=document.createElement('section');work.className='r22-workspace';work.setAttribute('aria-label',s.title+' workspace');
      [...s.body.childNodes].filter(n=>n!==s.head).forEach(n=>work.append(n));s.body.append(work);
    }
    [...s.body.childNodes].filter(n=>n!==s.head&&n!==work).forEach(n=>work.append(n));
    s.work=work;s.everMounted=true;
    // Banners added alongside the body stay visible inside the workspace.
    $$(':scope > .srp-consolidated-banner',page).forEach(n=>work.prepend(n));
  }
  function simplifyHeadings(s) {
    const {work,head}=s;
    const explicit='.srp-register-head,.srp-section-title,.srp-opening-head,.r19-detail-heading';
    $$(explicit,work).forEach(block=>{
      if(block.dataset.r22Heading)return;
      const heading=$('h1,h2',block);if(!heading)return;
      // Only exact duplicate titles, or explicitly authored register headers, move.
      const duplicate=norm(heading.textContent)===norm(s.title)||block.matches('.srp-register-head,.srp-opening-head');
      if(!duplicate)return;
      block.dataset.r22Heading='1';
      const tools=$('.srp-register-actions,.primary-actions',block);
      const keepWithRows=['report-bank-general-ledger'].includes(s.page.dataset.srpPage);
      if(tools&&!keepWithRows){tools.classList.add('r22-inline-actions');appendOnce($('.tegh-page-head-actions',head),tools);}
      const ownTitle=heading.closest('div');
      if(ownTitle&&ownTitle!==block&&!$('input,select,textarea,button,a',ownTitle))ownTitle.remove();
      else heading.remove();
      if(!block.textContent.trim()&&!$('input,select,textarea,button,a',block))block.remove();
    });
    // Preserve route-owned context/chips. Presentation CSS may restyle verified
    // duplicates, but the shell must not destroy interactive route controls.
    $$('.r17-breadcrumb,.r17-filter-chips,.tegh-context-bar',work).forEach(n=>n.classList.add('r22-secondary-context'));
    // Screen-only report identities are replaced by one concise scope control.
    $$('.r17-report-identity-block,.r20-report-identity,.srp-report-period-caption',work).forEach(n=>{
      if(n.dataset.r22Identity)return;n.dataset.r22Identity='1';n.classList.add('r22-document-identity');
      const help=guidance(s);
      if(help&&!['report-bank-general-ledger','report-bank-transactions'].includes(s.page.dataset.srpPage)){const copy=document.createElement('p');copy.className='r22-recorded-scope';copy.textContent=n.textContent.replace(/\s+/g,' ').trim();
        const prior=$('.r22-recorded-scope',help);prior?.remove();help.append(copy);}
    });
  }
  function toolbar(s,c) {
    const controls=$('.r22-toolbar-controls',s.head);
    if(c.toolbar){const form=$$(c.toolbar,s.work).find(shown);if(form&&!form.dataset.r22EditorBackground){form.classList.add('r22-filter-controls');appendOnce(controls,form);}}
    // Nested R19 invoice filters remain the same form and handler.
    $$('.r19-filter-details',controls).forEach(d=>{const items=$('.r19-filter-items',d);if(items){[...items.children].forEach(n=>d.before(n));d.remove();}});
    // Preserve the real Apply control and its existing handler/form ownership.
    $$('.r19-search-apply',controls).forEach(n=>n.classList.add('r22-filter-apply'));
    $$('.r20-form-state',controls).forEach(n=>{n.classList.add('r22-status-inline');n.setAttribute('aria-live','polite');});
    // Native overflow disclosure: never move a required record input into it.
    const form=$('form.r22-filter-controls',controls);
    if(form){
      if(!form.dataset.r22ChangeBound){form.dataset.r22ChangeBound='1';form.addEventListener('change',()=>schedule());}
      if(!form.dataset.r22InvalidBound){form.dataset.r22InvalidBound='1';form.addEventListener('invalid',e=>{const menu=e.target.closest('details.r22-menu')||(overlayManager.active?.matches?.('details.r22-menu')?overlayManager.active:null);if(menu){menu.open=true;positionMenu(menu);}},true);}
      let more=$(':scope > .r22-more-filters',form);
      const labels=[...form.children].filter(n=>n.tagName==='LABEL'&&!n.hidden);
      const secondary=labels.filter(n=>{
        const field=$('input,select,textarea',n);if(!field)return false;
        // Dates remain visible on both invoice registers; narrow layouts reflow.
        return /^(comparisonStart|comparisonEnd|basis|c1|c2|c3|bucket1|bucket2|bucket3|bucket4|amount|recon|source|type|status)$/.test(field.name);
      });
      if(secondary.length){if(!more){more=details('More Filters','r22-more-filters');form.append(more);bindMenu(more);}secondary.forEach(n=>menuPanel(more).append(n));}
      $$('.r22-invoice-date-scope',form).forEach(n=>n.remove());
      if(more){const activeInputs=$$('input,select',more).filter(n=>!n.disabled&&n.value&&!['all','any'].includes(n.value));$('summary',more).textContent='More Filters'+(activeInputs.length?' ('+activeInputs.length+')':'');}
    }
    // Source output, not pending inputs, determines this concise material scope.
    const model=s.page.teghSealedReport,hideAppliedScope=['report-bank-general-ledger','report-bank-transactions','invoices','report-invoice-register'].includes(s.page.dataset.srpPage);
    if(hideAppliedScope)$('.r22-applied-scope',s.head)?.remove();
    if(model&&!hideAppliedScope){
      let scope=$('.r22-applied-scope',s.head);if(!scope){scope=document.createElement('span');scope.className='r22-applied-scope';$('.r22-title',s.head).append(scope);}
      scope.textContent=[model.currency,model.accountingBasis].filter(Boolean).join(' · ');
      scope.title=[model.company?.legalName||model.company?.name,model.period?.start||model.parameters?.start,model.period?.end||model.parameters?.end].filter(Boolean).join(' · ');
    }
  }

  function fitToolbar(s) {
    // Bank reports own their specialised filter layout.
    if ($('.tegh-bank-report-filters', s.head)) return;

    // R23 registers own their own responsive register toolbar.
    if (s.page.dataset.r23Register === '1') return;

    const head = s.head;
    const form = $('form.r22-filter-controls', head);

    // Use actual application/workspace width rather than browser width because
    // an expanded sidebar can significantly reduce usable toolbar space.
    const availableWidth = s.body?.clientWidth || head?.clientWidth || innerWidth;
    if (availableWidth < 900) return;

    const overflow = () =>
      head.scrollWidth > head.clientWidth + 2 ||
      (form && form.scrollWidth > form.clientWidth + 2);

    if (form && overflow()) {
      // Keep essential search/date controls visible. Only lower-priority
      // filters are eligible for More Filters.
      const secondary = [...form.children]
        .filter(node =>
          node.tagName === 'LABEL' &&
          !node.hidden &&
          $('input,select,textarea', node)
        )
        .filter(node => {
          const field = $('input,select,textarea', node);
          return field && /^(module|status|basis|comparisonStart|comparisonEnd|c1|c2|c3|bucket1|bucket2|bucket3|bucket4|amount|recon|source|type)$/i.test(field.name);
        });

      if (secondary.length) {
        let menu = $(':scope > .r22-more-filters', form);
        if (!menu) {
          menu = details('More Filters', 'r22-more-filters');
          form.append(menu);
          bindMenu(menu);
        }
        const panel = menuPanel(menu);
        for (const label of secondary) {
          if (!overflow()) break;
          panel.append(label);
        }
        const fields = $$('input,select,textarea', menu).filter(node => !node.disabled && node.value);
        const summary = $('summary', menu);
        summary.textContent = 'More Filters' + (fields.length ? ` (${fields.length})` : '');
        summary.title = fields.map(node => {
          const label = node.closest('label')?.childNodes[0]?.textContent?.trim() || node.name;
          return `${label}: ${node.value}`;
        }).join('; ');
      }
    }

    if (form && overflow()) {
      const actions = $$('button[type=button].secondary', form).filter(node => !node.closest('details'));
      if (actions.length) {
        let menu = $(':scope > .r22-more-filters', form);
        if (!menu) {
          menu = details('More Filters', 'r22-more-filters');
          form.append(menu);
          bindMenu(menu);
        }
        const panel = menuPanel(menu);
        for (const action of actions) {
          if (!overflow()) break;
          panel.append(action);
        }
      }
    }

    // Restore room for the task's primary control by grouping only secondary
    // page actions. The original nodes, permissions and handlers survive.
    if (overflow()) {
      const tools = $('.tegh-page-head-actions', head);
      const extra = [...(tools?.children || [])].filter(node =>
        node.tagName === 'BUTTON' &&
        node.matches('.secondary') &&
        !node.matches('[data-new-run],[data-add-employee],[data-post-openings]') &&
        !node.closest('.r22-menu')
      );
      if (extra.length) {
        const menu = toolbarMenu(s, 'r22-overflow-actions', 'More');
        const panel = menuPanel(menu);
        for (const button of extra) {
          if (!overflow()) break;
          panel.append(button);
        }
      }
    }

    head.classList.toggle('r22-toolbar-overflowing', overflow());
  }
  function finalChrome(s,c) {
    const {work,head}=s, help=guidance(s);
    const report=c.kind==='report'&&!$('[data-r20-profit-form]',head);
    if(report){
      $$('.srp-report-toolbar',work).forEach(n=>{if(!$('button,input,select,a',n))n.remove();});
      $$('.srp-section-title',work).filter(n=>!n.closest('details')).forEach(n=>{
        const actions=$('.srp-actions',n);if(actions){const menu=toolbarMenu(s,'r22-source-actions','Actions');appendOnce(menuPanel(menu),actions);}
        if(!$('input,select,button,a',n)) {if(n.textContent.trim())help.append(n);else n.remove();}
      });
      const host=$('.tegh-authoritative-report',work);
      if(host){
        const card=host.parentElement;
        $$(':scope > .srp-note,:scope > .srp-portal-banner,:scope > .srp-kpis,:scope > .srp-gifi-summary',card).forEach(n=>{
          let footer=$(':scope > .r22-source-context',card);if(!footer){footer=document.createElement('footer');footer.className='r22-source-context';card.append(footer);}appendOnce(footer,n);
        });
      }
      $$('.srp-gifi-summary',work).filter(n=>!n.closest('details')).forEach(n=>{const menu=toolbarMenu(s,'r22-gifi-summary','Mapping totals');appendOnce(menuPanel(menu),n);});
    }
    if(s.page.dataset.srpPage==='day-book'){
      // The filter toolbar and R23 table caption already provide the page,
      // period and record identity. Remove two duplicated visual bands so the
      // ledger starts directly below the compact filter row.
      work.classList.add('r27-daybook-workspace');
      $$('.r22-document-identity',work).forEach(n=>n.classList.add('r27-daybook-document-identity'));
      help.remove();
    }
    if((c.kind==='import'&&s.page.dataset.srpPage!=='bank-imports')||s.page.dataset.srpPage==='company-details'){
      $$('.srp-section-title',work).filter(n=>!n.closest('details')).forEach(n=>{
        const actions=$('.srp-actions',n);if(actions){const menu=toolbarMenu(s,'r22-related-actions','Related actions');appendOnce(menuPanel(menu),actions);}
        if(!$('input,select,button,a',n))help.append(n);
      });
      $$('.srp-register-head',work).filter(n=>!n.textContent.trim()&&!$('input,select,button,a',n)).forEach(n=>n.remove());
    }
    // Authored register actions use the existing toolbar, not a third band.
    const tools=$('.tegh-page-head-actions',head);
    if(/^payroll-(runs|employees)$/.test(s.page.dataset.srpPage)){
      $$('[data-new-run],[data-add-employee]',work).forEach(n=>{appendOnce(tools,n);if(n.hasAttribute('data-new-run'))n.textContent='New Pay Run';});
      $$('.srp-section-title,.srp-register-head',work).filter(n=>!n.textContent.trim()&&!$('input,button,select,a',n)).forEach(n=>n.remove());
    }
    if(s.page.dataset.srpPage==='opening-balances'){
      const period=$('.srp-opening-period',work),footer=$('.srp-opening-footer',work)||$('[data-opening-post]',work)?.closest('.srp-actions')?.parentElement;
      if(period&&footer){period.classList.add('r22-opening-date-summary');appendOnce(footer,period);}
    }
    if(s.page.dataset.srpPage==='payroll-history'){
      const banner=$('.srp-portal-banner',work),table=$('.srp-table-wrap',work);if(banner&&table&&banner.parentElement===table.parentElement){banner.classList.add('r22-trailing-warning');appendOnce(table.parentElement,banner);}
    }
    const visibleForm=$$('form',work).find(n=>shown(n)&&!n.closest('details'));
    if(visibleForm&&['expense-vouchers','payroll-remittance','payroll-employees'].includes(s.page.dataset.srpPage)){
      const card=visibleForm.closest('.srp-card'),intro=card&&$(':scope>.srp-section-title,:scope>.srp-register-head',card);
      if(intro&&!$('button,input,select,a',intro)){
        const h=$('h2',intro);if(h){setHeading(head).textContent=h.textContent;h.remove();}
        const text=intro.textContent.trim();if(text){intro.classList.add('r22-trailing-warning');appendOnce(card,intro);}else intro.remove();
      }
    }
    if(s.page.dataset.srpPage==='financial-analyst'){
      const run=$('[data-fa-run]',work)||$('[data-fa-run]',tools);if(run){run.textContent='Run analyst';run.setAttribute('aria-label','Run Financial Analyst');}
      const controls=$('.r22-toolbar-controls',head);
      s.analysisToolbars=(s.analysisToolbars||[]).filter(x=>x.node.isConnected&&x.anchor.isConnected);
      $$('[data-fa-panel] > .tegh-fa-toolbar',work).forEach(node=>{if(s.analysisToolbars.some(x=>x.node===node))return;const anchor=document.createComment('R22 original panel control position');node.before(anchor);s.analysisToolbars.push({node,anchor,panel:node.parentElement});});
      for(const item of s.analysisToolbars){
        if(!item.panel.hidden){item.node.classList.add('r22-analysis-controls');appendOnce(controls,item.node);
          const buttons=$$('button',item.node);if(buttons.length){const menu=toolbarMenu(s,'r22-analysis-context-actions','Planning actions');buttons.forEach(n=>menuPanel(menu).append(n));}
        }else if(item.node.parentElement!==item.panel)item.anchor.after(item.node);
      }

      const actions=$('.tegh-fa-hero-actions',work);if(actions){const run=$('[data-fa-run]',actions);if(run)appendOnce(tools,run);const menu=toolbarMenu(s,'r22-analyst-options','Analyst options');appendOnce(menuPanel(menu),actions);}
      $$('.r15-financial-actions',work).filter(n=>!n.textContent.trim()).forEach(n=>n.remove());
      const tabs=$('[role=tablist]',work);if(tabs){const menu=toolbarMenu(s,'r22-analysis-view','View');appendOnce(menuPanel(menu),tabs);const active=$('[aria-selected=true]',tabs);$('summary',menu).textContent='View: '+(active?.textContent?.trim()||'Analysis');if(!tabs.dataset.r22ViewBound){tabs.dataset.r22ViewBound='1';tabs.addEventListener('click',e=>{if(e.target.closest('[role=tab]')){if(e.detail!==0)menu.open=false;schedule(s.page);}});}}
    }
    if(s.page.dataset.srpPage==='expense-vouchers'){
      const form=$('[data-expense-form]',work),card=form?.closest('.srp-card'),grid=card?.parentElement;
      if(form&&grid){grid.classList.add('r22-expense-editor');[...grid.children].filter(n=>n!==card).forEach(n=>{const menu=toolbarMenu(s,'r22-expense-history','Expense history');appendOnce(menuPanel(menu),n);});
        const title=$(':scope > h2',card);if(title){setHeading(head).textContent=title.textContent;title.remove();}
        form.classList.add('r22-expense-form');
      }
    }
  }
  function asMenu(node,label) {
    if(!node)return null;

    // Existing <details> can be enhanced directly.
    if(node.tagName==='DETAILS'){
      node.dataset.r22Menu='1';
      node.classList.add('r22-menu');
      let sum=$(':scope > summary',node);
      if(!sum){sum=document.createElement('summary');sum.textContent=label||'Details';node.prepend(sum);}
      else if(label)sum.textContent=label;
      let panel=menuPanel(node);
      if(!panel){panel=document.createElement('div');panel.className='r22-menu-panel';[...node.childNodes].filter(n=>n!==sum).forEach(n=>panel.append(n));node.append(panel);}
      bindMenu(node);
      return node;
    }

    // If already wrapped by this shell, reuse the real disclosure.
    const parentDetails=node.parentElement?.closest('details.r22-menu[data-r22-wrapper="1"]');
    if(parentDetails&&menuPanel(parentDetails)?.contains(node)){
      if(label){const sum=$(':scope > summary',parentDetails);if(sum)sum.textContent=label;}
      bindMenu(parentDetails);
      return parentDetails;
    }

    // Ordinary elements must be wrapped in a genuine <details>; a <summary>
    // placed directly inside a div has no native disclosure behaviour.
    const wrapper=details(label||'Details','r22-wrapped-menu');
    wrapper.dataset.r22Wrapper='1';
    node.before(wrapper);
    menuPanel(wrapper).append(node);
    bindMenu(wrapper);
    return wrapper;
  }
  function authoredChrome(s,c) {
    const work=s.work,tools=$('.tegh-page-head-actions',s.head),controls=$('.r22-toolbar-controls',s.head);
    if(c.kind==='report'){
      const host=$('.tegh-authoritative-report',work);
      $$('.r20-report-controls',work).filter(n=>!$('form',n)).forEach(n=>n.remove());
      if(host?.classList.contains('r20-profit')){
        let footer=$(':scope > .r22-report-summary',host);
        if(!footer){footer=document.createElement('footer');footer.className='r22-report-summary';host.append(footer);}
        const kpis=$('.r20-report-kpis',host);if(kpis)appendOnce(footer,kpis);
        const months=$('.r20-month-toolbar',host);if(months){appendOnce(footer,months);const prev=$('[data-r20-month-prev]',months),next=$('[data-r20-month-next]',months);if(prev){prev.textContent='‹';prev.setAttribute('aria-label','Previous months');}if(next){next.textContent='›';next.setAttribute('aria-label','Next months');}}
        const chart=$('.r20-chart-disclosure',host);
        if(chart){
          const chartMenu=asMenu(chart,'Report details');
          if(chartMenu){
            appendOnce(footer,chartMenu);
            const panel=menuPanel(chartMenu);
            const note=$(':scope > .r20-comparison-note',host),foot=$(':scope > .r20-report-foot',host);
            if(note)panel.prepend(note);
            if(foot)panel.append(foot);
          }
        }
        const caption=$('table caption',host);if(caption)caption.classList.add('r22-sr-only');
      }else if(host){
        let footer=$(':scope > .r22-report-summary',host);
        const totals=$(':scope > .tegh-report-totals',host),detail=$(':scope > .tegh-report-context,:scope > details',host);
        if(totals||detail){
          if(!footer){footer=document.createElement('footer');footer.className='r22-report-summary';host.append(footer);}
          if(totals)appendOnce(footer,totals);
          if(detail){const detailMenu=asMenu(detail,'Report details');if(detailMenu)appendOnce(footer,detailMenu);}
        }
        $$('table caption',host).forEach(n=>n.classList.add('r22-sr-only'));
      }
    }
    if(c.kind==='invoice-detail'){
      const heading=$('.r19-detail-heading',work),tabs=$('.r19-detail-tabs',work);
      if(heading){
        const invTitle=$('h2',heading),actions=$('.r19-detail-actions',heading),back=$('[data-r19-back]',heading);
        if(invTitle){const title=$('h1',s.head);title.replaceChildren(...invTitle.childNodes);invTitle.remove();}
        if(actions)appendOnce(tools,actions);
        if(back){$('.r22-return',tools)?.remove();back.textContent='←';back.setAttribute('aria-label','Back to invoices with previous filters');back.classList.add('r22-return');tools.prepend(back);}
        heading.remove();
      }
      // Genuine invoice sections live in the existing toolbar, not a third band.
      if(tabs){let menu=$('.r22-invoice-sections',controls);if(!menu){menu=details('View: Overview','r22-invoice-sections');controls.append(menu);bindMenu(menu);}
        menuPanel(menu).append(tabs);
        if(!tabs.dataset.r22Bound){tabs.dataset.r22Bound='1';tabs.addEventListener('click',e=>{const tab=e.target.closest('[role=tab]');if(tab){$('summary',menu).textContent='View: '+tab.textContent.trim();if(e.detail!==0){menu.open=false;$('summary',menu).focus({preventScroll:true});}}});}
      }
    }
    if(c.kind==='dashboard'){
      const views=$('.r17-dashboard-controls',work);if(views)appendOnce(controls,views);
    }
  }


  function toolbarMenu(s,cls,label) {
    let menu=$('.'+cls,s.head);if(!menu){menu=details(label,cls);$('.tegh-page-head-actions',s.head).append(menu);bindMenu(menu);}return menu;
  }
  function operatorChrome(s,c) {
    const {work,head}=s,tools=$('.tegh-page-head-actions',head),controls=$('.r22-toolbar-controls',head);
    if(c.kind==='invoice-form'){
      const form=$('[data-ci-form]',work);if(form&&!form.dataset.r29Reference){let foot=$('.r22-document-footer',form);if(!foot){foot=document.createElement('footer');foot.className='r22-document-footer full';form.append(foot);}
        const totals=$('.srp-ci-totals',form),actions=$('.srp-actions',form),notes=$('.r22-invoice-notes',form);
        if(notes){const notesMenu=asMenu(notes,'Message & details');if(notesMenu)appendOnce(foot,notesMenu);}if(totals)appendOnce(foot,totals);if(actions)appendOnce(foot,actions);
      }
    }
    if(c.kind==='journal-form'){
      const nav=$('.srp-segmented',work);if(nav)appendOnce(controls,nav);
      const form=$('[data-journal-form]',work),card=form?.closest('.srp-card');
      if(form){
        const intro=$(':scope > .srp-section-title',card);if(intro){const help=guidance(s);while(intro.firstChild)help.append(intro.firstChild);intro.remove();}
        const other=$$(':scope > .srp-card',work).filter(n=>n!==card);
        if(other.length){const menu=toolbarMenu(s,'r22-journal-records','Records');other.forEach(n=>menuPanel(menu).append(n));}
      }
    }
    if(c.kind==='directory'||c.kind==='bills'){
      const form=$('form[data-customer-form],form[data-vendor-form],form[data-employee-form],form[data-bill-form]',work);
      s.page.dataset.r22Editor=form?'1':'0';
      if(form){
        const filter=$('form.r22-filter-controls',head);
        if(filter){filter.dataset.r22EditorBackground='1';const menu=toolbarMenu(s,'r22-background-register','Directory / history');appendOnce(menuPanel(menu),filter);}
        const heading=$(':scope > h3',form);if(heading){const title=$('h1',head);title.textContent=heading.textContent;heading.remove();}
        const background=$$('.srp-table-wrap,.tegh-authoritative-report',work).filter(n=>!form.contains(n)&&!n.contains(form)&&!n.closest('.r22-menu'));
        if(background.length){const menu=toolbarMenu(s,'r22-background-register','Directory / history');background.forEach(n=>menuPanel(menu).append(n));}
      }
    }
    if(c.kind==='payment-form'){
      const form=$('[data-payment-form]',work);
      if(form){const card=form.closest('.srp-card'),grid=card?.parentElement;
        const intro=$(':scope>.srp-section-title',card);if(intro){const heading=$('h2,h3',intro);if(heading)setHeading(head).textContent=heading.textContent;const help=guidance(s);while(intro.firstChild)help.append(intro.firstChild);intro.remove();}
        if(grid?.classList.contains('srp-grid')){
        grid.classList.add('r22-payment-grid');const others=[...grid.children].filter(n=>n!==card);if(others.length){const menu=toolbarMenu(s,'r22-payment-guide','Recording help');others.forEach(n=>menuPanel(menu).append(n));}
        const history=$$(':scope > .srp-card',work).filter(n=>!n.contains(form));if(history.length){const menu=toolbarMenu(s,'r22-payment-history','History');history.forEach(n=>{const card=n.closest('.srp-card');menuPanel(menu).append(card&&!card.contains(form)?card:n);});}
      }}
    }
    if(c.kind==='register'||c.kind==='directory'){
      const policy=$('.srp-payroll-mode',work);
      if(policy){const title=$('h2',policy)?.textContent?.trim()||'Payroll policy';const menu=toolbarMenu(s,'r22-payroll-policy','Posting: '+title);menuPanel(menu).append(policy);}
    }
    if(c.kind==='bank-review'){
      const pagebar=$('.tegh-review-pagebar',work),account=$('.tegh-review-account-control',work);
      if(pagebar){$(':scope > div:first-child',pagebar)?.remove();appendOnce(tools,pagebar);}
      if(account){const label=$(':scope > label',account);if(label)appendOnce(controls,label);
        // Keep balances and their unavailable-state evidence inside the workspace.
        account.classList.add('r22-bank-context');account.style.order='10';
      }
    }
    if(c.kind==='reconciliation'){
      const top=$('.r15-recon-top',work),nav=$('.r15-recon-status nav',top);
      if(top){const action=$('.r15-recon-toolbar>.srp-actions',top);if(action)appendOnce(tools,action);if(nav){const menu=toolbarMenu(s,'r22-recon-view','Status');menuPanel(menu).append(nav);}
        const selection=$('[data-recon-selection-label]',top);if(selection){let footer=$(':scope > .r22-recon-summary',work);if(!footer){footer=document.createElement('footer');footer.className='r22-recon-summary';work.append(footer);}appendOnce(footer,selection);}
        const legend=$('.r15-exact-legend',top);if(legend)appendOnce(guidance(s),legend);
        if(!top.querySelector('input,select,button,[role=status]'))top.remove();
      }
      s.page.dataset.r22NarrowSplit=s.body.clientWidth<1050?'1':'0';
    }

    if(c.kind==='reconciliation'){
      $$('.tegh-recon-side',work).forEach(pane=>{
        const header=$(':scope > header',pane),actions=$(':scope > .tegh-recon-side-actions',pane),filters=$(':scope > .tegh-recon-side-filters',pane);
        if(actions){appendOnce(header,actions);$$('details',actions).forEach(n=>asMenu(n,'Export'));}
        if(filters){const which=pane.id.includes('-bank-')?'Bank':'Books';let menu=$('.r22-pane-filter',header);if(!menu){menu=details(which+' filters','r22-pane-filter');header.append(menu);bindMenu(menu);}appendOnce(menuPanel(menu),filters);}
      });
    }
    if(c.kind==='bank-accounts'){
      const picker=$('[data-bank-account-picker]',work);if(picker)appendOnce(controls,picker.closest('label'));
      const search=$('[data-bank-directory-controls]',work);if(search)appendOnce(controls,search);
      // Account-row actions stay with their account. They must never be rebound
      // to a global selection or moved into the page heading.
    }
    if(c.kind==='import'){
      const chooser=$('.srp-import-shell',work);
      if(chooser){const menu=toolbarMenu(s,'r22-import-type-menu','Import type');appendOnce(menuPanel(menu),chooser);
        const steps=$('.srp-import-steps',chooser);if(steps){const current=$('.current',steps);let step=$('.r22-import-stage',head);if(!step){step=document.createElement('span');step.className='r22-import-stage';controls.prepend(step);}step.textContent=current?.textContent.trim()||'Choose file';steps.remove();}
      }
      const bench=$('[data-import-workbench]:not([hidden]),.srp-import-review',work);
      if(bench){const title=$('.srp-register-head h2',bench);if(title){setHeading(head).textContent=title.textContent;title.remove();}const links=$('.srp-import-template-links',bench);if(links){const menu=toolbarMenu(s,'r22-import-templates','Templates');menuPanel(menu).append(links);}}
      const legacyNav=$('.srp-segmented',work);if(legacyNav)appendOnce(controls,legacyNav);
    }
    if(c.kind==='catalogue'){
      const intro=$('.srp-sites-catalogue-head',work);if(intro){const search=$('.srp-sites-catalogue-search',intro);if(search)appendOnce(controls,search);intro.remove();}
    }
    if(c.kind==='directory'&&s.page.dataset.srpPage==='products'){
      const editor=$('[data-r22-product-editor]',work),catalog=$('#srp-product-form',work)?.closest('.srp-grid');
      if(editor&&catalog){s.page.dataset.r22Editor=editor.hidden?'0':'1';catalog.classList.add('r22-product-grid');const sibling=[...catalog.children].find(n=>n!==editor);if(sibling)sibling.hidden=!editor.hidden;
        setHeading(head).textContent=editor.hidden?'Products & services':($('#srp-product-form [name=id]',editor)?.value?'Edit Product or Service':'New Product or Service');
      }
    }
    if(c.kind==='calculator'){
      const card=$(':scope > .srp-card',work),notice=card&&$(':scope > .srp-section-title',card);
      if(notice){const small=$('small',notice);if(small){small.classList.add('r22-calculator-limit');appendOnce(controls,small);}const help=guidance(s);while(notice.firstChild)help.append(notice.firstChild);notice.remove();}
    }
    if(c.kind==='remittance'){
      const form=$('form',work);if(form){const card=form.closest('.srp-card'),grid=card?.parentElement;
        if(grid?.classList.contains('srp-grid')){grid.classList.add('r22-remittance-grid');const siblings=[...grid.children].filter(n=>n!==card);if(siblings.length){const menu=toolbarMenu(s,'r22-remittance-context','Remittance details');siblings.forEach(n=>menuPanel(menu).append(n));}}
        const history=$$('.srp-table-wrap',work).filter(n=>!form.contains(n)&&!n.closest('.r22-menu'));
        if(history.length){const menu=toolbarMenu(s,'r22-remittance-history','History');history.forEach(n=>{const card=n.closest('.srp-card');menuPanel(menu).append(card&&!card.contains(form)?card:n);});}
      }
    }
    if(c.kind==='transfer'){
      const card=$(':scope > .srp-card',work);if(card){const heading=$(':scope > h2',card);if(heading)heading.remove();const long=$(':scope > p:not(.srp-note)',card);if(long)appendOnce(guidance(s),long);}
    }

    if(c.kind==='review'){
      const intake=s.page.dataset.srpPage==='document-intake', list=$(intake?'[data-native-document-list]':'[data-collection-list]',work);
      const boundary=$('.tegh-native-boundary',work);
      if(boundary){const help=guidance(s);appendOnce(help,boundary);let note=$('.r22-review-boundary',work);if(!note){note=document.createElement('p');note.className='r22-review-boundary';note.textContent=intake?'Private source · Extraction stays local · Human review is required before creating a draft.':'Preparing a collection draft does not send it. Review and explicit approval remain required.';work.append(note);}}
      const upload=$(intake?'[data-native-document-upload]':'[data-collection-prepare]',work);
      if(upload){const card=upload.closest('.srp-card');const menu=toolbarMenu(s,'r22-review-create',intake?'Upload document':'Prepare draft');appendOnce(menuPanel(menu),card||upload);}
      const tabs=$('[role=tablist],.tegh-collection-tabs',work);if(tabs){const menu=toolbarMenu(s,'r22-review-states','Queue status');appendOnce(menuPanel(menu),tabs);}
      const learning=$('.tegh-vendor-learning-owner',work);if(learning){const menu=toolbarMenu(s,'r22-review-learning','Recognition');appendOnce(menuPanel(menu),learning);}
      if(list){
        const selector=intake?'[data-native-document]':'[data-collection-draft]';
        const incoming=$$(selector,list).filter(n=>n.matches('article'));
        if(incoming.length){
          let split=$(':scope>.r22-review-split',list),queue,detail;
          if(!split){split=document.createElement('div');split.className='r22-review-split';queue=document.createElement('nav');queue.className='r22-review-queue';queue.setAttribute('aria-label',intake?'Private documents':'Collection drafts');detail=document.createElement('section');detail.className='r22-review-detail';split.append(queue,detail);list.append(split);}else{queue=$('.r22-review-queue',split);detail=$('.r22-review-detail',split);}
          incoming.filter(card=>card.parentElement!==detail).forEach(card=>detail.append(card));
          const cards=$$(selector,detail),keys=cards.map(card=>card.getAttribute(intake?'data-native-document':'data-collection-draft'));
          if(keys.join('|')!==queue.dataset.r22Keys){
            queue.replaceChildren();queue.dataset.r22Keys=keys.join('|');
            cards.forEach((card,i)=>{const button=document.createElement('button');button.type='button';button.className='r22-review-record';button.textContent=$('h2',card)?.textContent||((intake?'Document ':'Draft ')+(i+1));button.dataset.r22ReviewKey=keys[i];queue.append(button);button.onclick=()=>{s.reviewSelected=keys[i];cards.forEach((n,j)=>{n.hidden=j!==i});$$('button',queue).forEach((b,j)=>b.setAttribute('aria-pressed',String(j===i)));detail.scrollTop=0;};});
          }
          if(!keys.includes(s.reviewSelected))s.reviewSelected=keys[0];
          cards.forEach((n,i)=>n.hidden=keys[i]!==s.reviewSelected);$$('button',queue).forEach(n=>n.setAttribute('aria-pressed',String(n.dataset.r22ReviewKey===s.reviewSelected)));
        }
      }
    }
    if(c.kind==='advanced'){
      // R121: the Budgets module re-renders its whole view (after opening the
      // editor, saving or cancelling). Nodes hoisted from the previous render
      // are tagged and removed first, so the toolbar, search and overview are
      // never duplicated ("Create Budget" appeared three times).
      const hero=$('.sra-budget-hero',work),freshSearch=$('.sra-budget-search:not([data-r22-hoisted])',work),freshHead=$('.sra-budget-list>header:not([data-r22-hoisted])',work);
      if(hero||freshSearch||freshHead){const help=$(':scope > .r22-guidance',work);[tools,controls,help].forEach(scope=>scope&&$$('[data-r22-hoisted="advanced"]',scope).forEach(n=>{if(!hero&&n.matches('[data-budget-create]'))return;if(!freshSearch&&n.matches('.sra-budget-search'))return;n.remove();}));}
      const tag=n=>{if(n?.nodeType===1)n.dataset.r22Hoisted='advanced';return n;};
      if(hero){const create=$('[data-budget-create]',hero);if(create)appendOnce(tools,tag(create));const help=guidance(s);[...hero.children].forEach(n=>help.append(tag(n)));hero.remove();}
      const search=freshSearch;if(search)appendOnce(controls,tag(search));
      const budgetHead=freshHead;if(budgetHead){const help=guidance(s);appendOnce(help,tag(budgetHead));}
      const budgetKpis=$('.sra-budget-kpis',work);if(budgetKpis){budgetKpis.classList.add('r22-advanced-summary');budgetKpis.style.order='10';}
      const assetForm=$('#sra-asset-form',work),analyticForm=$('#sra-analytic-form',work);
      if(assetForm||analyticForm){const form=assetForm||analyticForm,card=form.closest('.sra-card'),grid=card?.parentElement;
        if(grid){grid.classList.add('r22-advanced-directory');const otherForms=$$('form',grid).filter(n=>n!==form);otherForms.forEach(n=>{const other=n.closest('.sra-card');if(other&&other!==card){const menu=toolbarMenu(s,'r22-advanced-options','Additional actions');appendOnce(menuPanel(menu),other);}});
          if(!card.dataset.r22Editor){card.dataset.r22Editor='1';card.hidden=true;const open=document.createElement('button');open.type='button';open.className='sra-btn';open.textContent=assetForm?'Add asset schedule':'New analytic account';open.dataset.r22AdvancedCreate='1';tools.append(open);const cancel=document.createElement('button');cancel.type='button';cancel.className='sra-btn secondary';cancel.textContent='Cancel';$('.sra-actions',form).append(cancel);
            const apply=show=>{card.hidden=!show;[...grid.children].filter(n=>n!==card).forEach(n=>n.hidden=show);setHeading(head).textContent=show?open.textContent:s.title;open.hidden=show;s.page.dataset.r22AdvancedEditing=show?'1':'0';schedule();};
            const markDirty=event=>{const field=event.target;if(field?.matches?.('input,select,textarea'))field.dataset.r22Dirty='1';};
            open.onclick=()=>apply(true);
            cancel.onclick=()=>{
              if(form.querySelector('[data-r22-dirty="1"]')&&!confirm('Discard your unsaved changes?'))return;
              $$('[data-r22-dirty]',form).forEach(field=>delete field.dataset.r22Dirty);
              apply(false);
            };
            form.addEventListener('input',markDirty);
            form.addEventListener('change',markDirty);
          }
        }
      }
    }

    // Authored register toolbar children: search is a filter, not a page action.
    $$('.r22-inline-actions > label',tools).forEach(label=>appendOnce(controls,label));
    const secondarySelectors=['[data-import-customers]','[data-import-vendors]'];
    const primary=s.page.dataset.srpPage==='customers'?'data-new-customer':s.page.dataset.srpPage==='vendors'?'data-new-vendor':s.page.dataset.srpPage==='invoices'?'data-new-invoice':'';
    const extra=$$(secondarySelectors.join(','),tools).filter(n=>!n.hasAttribute(primary||'data-r22-never')&&!n.closest('.r22-menu'));
    if(extra.length){const menu=toolbarMenu(s,'r22-secondary-actions','More');extra.forEach(n=>menuPanel(menu).append(n));}
    // Nested page-header instances are legacy wrapper duplication, not sections.
    $$('.srp-page-head',work).forEach(n=>{
      if(n.dataset.r22Nested)return;n.dataset.r22Nested='1';const heading=$('h2',n),search=$('[data-ledger-search]',n);
      if(search){search.setAttribute('aria-label','Search parties');appendOnce(controls,search);}
      $$('form[data-r23-source-filter]',n).forEach(form=>{form.classList.add('r22-filter-controls');appendOnce(controls,form);});
      const buttons=$$('button,a',n);buttons.forEach(b=>appendOnce(tools,b));
      if(heading&&norm(heading.textContent)!==norm(s.title)){let scope=$('.r22-record-scope',head);if(!scope){scope=document.createElement('span');scope.className='r22-record-scope';$('.r22-title',head).append(scope);}scope.textContent=heading.textContent;}
      const help=guidance(s);while(n.firstChild)help.append(n.firstChild);n.remove();
    });
  }

  function dragScrollbarLayer(){
    let layer=document.getElementById('tegh-drag-scrollbar-layer');
    if(!layer){
      layer=document.createElement('div');layer.id='tegh-drag-scrollbar-layer';layer.setAttribute('aria-live','off');document.body.append(layer);
    }
    return layer;
  }
  function pruneDragScrollbars(){
    for(const binding of [...dragScrollbarBindings])if(!binding.node.isConnected)binding.destroy();
  }
  function createDragScrollbar(node,label){
    pruneDragScrollbars();
    const rail=document.createElement('div'),thumb=document.createElement('span');
    rail.className='r22-drag-scrollbar';rail.tabIndex=0;rail.setAttribute('role','scrollbar');rail.setAttribute('aria-orientation','vertical');
    rail.setAttribute('aria-valuemin','0');rail.setAttribute('aria-label',`${label} vertical scrollbar`);
    thumb.className='r22-drag-scrollbar-thumb';thumb.setAttribute('aria-hidden','true');rail.append(thumb);dragScrollbarLayer().append(rail);
    const generatedId=!node.id;if(generatedId){node.id=`r22-scroll-owner-${++scrollOwnerSequence}`;node.dataset.r22AddedScrollId='1';}
    rail.setAttribute('aria-controls',node.id);
    let raf=0,pointerId=null,dragOffset=0,lastMetrics=null;
    const readMetrics=()=>{
      if(!node.isConnected||!shown(node))return null;
      const rect=node.getBoundingClientRect(),clientHeight=node.clientHeight,max=Math.max(0,node.scrollHeight-clientHeight);
      if(max<=2||clientHeight<48||rect.width<24||rect.bottom<=0||rect.top>=innerHeight)return null;
      const top=Math.max(0,rect.top+(node.clientTop||0)),bottom=Math.min(innerHeight,rect.top+(node.clientTop||0)+clientHeight),height=Math.max(0,bottom-top);
      if(height<48)return null;
      const trackHeight=height,thumbHeight=Math.max(36,Math.min(trackHeight,trackHeight*(clientHeight/node.scrollHeight))),travel=Math.max(0,trackHeight-thumbHeight);
      return {top,left:Math.max(0,Math.min(innerWidth-20,rect.right-20)),height:trackHeight,thumbHeight,travel,max};
    };
    const refresh=()=>{
      raf=0;const metrics=readMetrics();lastMetrics=metrics;
      if(!metrics){rail.hidden=true;if(node.dataset.r161RailSpace){node.style.paddingRight=node.dataset.r161RailSpace==='-'?'':node.dataset.r161RailSpace;delete node.dataset.r161RailSpace;}return;}
      // R161: the rail sits over the owner's last 20 px; keep that strip free so amounts at the right edge are not covered.
      if(!node.dataset.r161RailSpace&&parseFloat(getComputedStyle(node).paddingRight)<22){node.dataset.r161RailSpace=node.style.paddingRight||'-';node.style.paddingRight='22px';}
      rail.hidden=false;rail.style.top=`${Math.round(metrics.top)}px`;rail.style.left=`${Math.round(metrics.left)}px`;rail.style.height=`${Math.round(metrics.height)}px`;
      thumb.style.height=`${Math.round(metrics.thumbHeight)}px`;thumb.style.transform=`translateY(${Math.round(metrics.max?node.scrollTop/metrics.max*metrics.travel:0)}px)`;
      rail.setAttribute('aria-valuemax',String(Math.round(metrics.max)));rail.setAttribute('aria-valuenow',String(Math.round(node.scrollTop)));
    };
    const scheduleRefresh=()=>{if(!raf)raf=requestAnimationFrame(refresh);};
    const setFromPointer=clientY=>{
      const metrics=lastMetrics||readMetrics();if(!metrics)return;
      const thumbTop=Math.max(0,Math.min(metrics.travel,clientY-metrics.top-dragOffset));
      node.scrollTop=metrics.travel?thumbTop/metrics.travel*metrics.max:0;refresh();
    };
    const finish=event=>{
      if(pointerId===null||(event?.pointerId!==undefined&&event.pointerId!==pointerId))return;
      try{if(rail.hasPointerCapture?.(pointerId))rail.releasePointerCapture(pointerId);}catch(_){}
      pointerId=null;rail.classList.remove('is-dragging');
    };
    const pointerdown=event=>{
      if(event.button!==undefined&&event.button!==0)return;
      refresh();if(!lastMetrics)return;
      event.preventDefault();event.stopPropagation();pointerId=event.pointerId;const thumbRect=thumb.getBoundingClientRect();
      dragOffset=event.target===thumb?event.clientY-thumbRect.top:lastMetrics.thumbHeight/2;
      rail.classList.add('is-dragging');try{rail.setPointerCapture?.(pointerId);}catch(_){}setFromPointer(event.clientY);rail.focus({preventScroll:true});
    };
    const pointermove=event=>{if(pointerId===null||event.pointerId!==pointerId)return;event.preventDefault();setFromPointer(event.clientY);};
    const wheel=event=>{
      if(event.ctrlKey)return;event.preventDefault();let delta=event.deltaY;
      if(event.deltaMode===WheelEvent.DOM_DELTA_LINE)delta*=40;else if(event.deltaMode===WheelEvent.DOM_DELTA_PAGE)delta*=node.clientHeight;
      node.scrollTop=Math.max(0,Math.min(node.scrollHeight-node.clientHeight,node.scrollTop+delta));refresh();
    };
    const keydown=event=>{
      if(event.altKey||event.ctrlKey||event.metaKey)return;const max=Math.max(0,node.scrollHeight-node.clientHeight),page=Math.max(40,Math.floor(node.clientHeight*.9));let next=null;
      if(event.key==='PageDown')next=node.scrollTop+page;else if(event.key==='PageUp')next=node.scrollTop-page;else if(event.key==='End')next=max;else if(event.key==='Home')next=0;else if(event.key==='ArrowDown')next=node.scrollTop+40;else if(event.key==='ArrowUp')next=node.scrollTop-40;
      if(next===null)return;event.preventDefault();event.stopPropagation();node.scrollTop=Math.max(0,Math.min(max,next));refresh();
    };
    rail.addEventListener('pointerdown',pointerdown);rail.addEventListener('wheel',wheel,{passive:false});rail.addEventListener('keydown',keydown);
    window.addEventListener('pointermove',pointermove,{capture:true,passive:false});window.addEventListener('pointerup',finish,true);window.addEventListener('pointercancel',finish,true);
    window.addEventListener('resize',scheduleRefresh,{passive:true});window.addEventListener('scroll',scheduleRefresh,{passive:true,capture:true});node.addEventListener('scroll',scheduleRefresh,{passive:true});
    const resizeObserver=typeof ResizeObserver==='function'?new ResizeObserver(scheduleRefresh):null;resizeObserver?.observe(node);scheduleRefresh();
    let binding;
    binding={
      node,
      refresh:scheduleRefresh,
      setLabel(next){rail.setAttribute('aria-label',`${next} vertical scrollbar`);},
      destroy(){
        if(raf)cancelAnimationFrame(raf);finish();resizeObserver?.disconnect();node.removeEventListener('scroll',scheduleRefresh);window.removeEventListener('resize',scheduleRefresh);window.removeEventListener('scroll',scheduleRefresh,true);
        window.removeEventListener('pointermove',pointermove,true);window.removeEventListener('pointerup',finish,true);window.removeEventListener('pointercancel',finish,true);rail.remove();
        if(generatedId&&node.dataset.r22AddedScrollId==='1'){node.removeAttribute('id');delete node.dataset.r22AddedScrollId;}dragScrollbarBindings.delete(binding);
      }
    };
    dragScrollbarBindings.add(binding);return binding;
  }
  function bindScrollInput(node,label){
    const existing=scrollBindings.get(node);if(existing){existing.dragScrollbar?.setLabel(label);existing.dragScrollbar?.refresh();return;}
    // Native wheel/trackpad input normally moves this owner. Chromium can keep
    // a stale async-scroll target for one routed frame after replacing a page,
    // though, so apply the delta only when the browser did not move either
    // axis. The listener stays passive and never competes with thumb dragging.
    const wheel=event=>{
      if(event.defaultPrevented||event.ctrlKey)return;
      const path=event.composedPath?.()||[];
      for(const item of path){
        if(item===node)break;
        if(!(item instanceof Element))continue;
        const style=getComputedStyle(item),vertical=/auto|scroll/.test(style.overflowY)&&item.scrollHeight>item.clientHeight+1,horizontal=/auto|scroll/.test(style.overflowX)&&item.scrollWidth>item.clientWidth+1;
        const canVertical=vertical&&((event.deltaY>0&&item.scrollTop<item.scrollHeight-item.clientHeight-1)||(event.deltaY<0&&item.scrollTop>1));
        const canHorizontal=horizontal&&((event.deltaX>0&&item.scrollLeft<item.scrollWidth-item.clientWidth-1)||(event.deltaX<0&&item.scrollLeft>1));
        if(canVertical||canHorizontal)return;
      }
      const beforeTop=node.scrollTop,beforeLeft=node.scrollLeft,mode=event.deltaMode;
      let dy=event.deltaY,dx=event.deltaX;
      if(mode===WheelEvent.DOM_DELTA_LINE){dy*=40;dx*=40;}
      else if(mode===WheelEvent.DOM_DELTA_PAGE){dy*=node.clientHeight;dx*=node.clientWidth;}
      if(event.shiftKey&&!dx){dx=dy;dy=0;}
      requestAnimationFrame(()=>{
        if(!node.isConnected||node.scrollTop!==beforeTop||node.scrollLeft!==beforeLeft)return;
        const maxTop=Math.max(0,node.scrollHeight-node.clientHeight),maxLeft=Math.max(0,node.scrollWidth-node.clientWidth);
        if(dy)node.scrollTop=Math.max(0,Math.min(maxTop,beforeTop+dy));
        if(dx)node.scrollLeft=Math.max(0,Math.min(maxLeft,beforeLeft+dx));
      });
    };
    const keydown=event=>{
      if(event.target!==node||event.defaultPrevented||event.altKey||event.ctrlKey||event.metaKey)return;
      const max=Math.max(0,node.scrollHeight-node.clientHeight);if(max<=1)return;
      const before=node.scrollTop,page=Math.max(40,Math.floor(node.clientHeight*.9));let next=null;
      if(event.key==='PageDown')next=before+page;else if(event.key==='PageUp')next=before-page;
      else if(event.key==='End')next=max;else if(event.key==='Home')next=0;
      else if(event.key==='ArrowDown')next=before+40;else if(event.key==='ArrowUp')next=before-40;
      if(next===null)return;node.scrollTop=Math.max(0,Math.min(max,next));
      if(node.scrollTop!==before||event.key==='Home'||event.key==='End')event.preventDefault();
    };
    node.addEventListener('wheel',wheel,{passive:true});
    node.addEventListener('keydown',keydown);
    const dragScrollbar=createDragScrollbar(node,label);
    scrollBindings.set(node,{wheel,keydown,dragScrollbar});node.dataset.r22ScrollInput='1';
  }
  function unbindScrollInput(node){
    const binding=scrollBindings.get(node);if(!binding)return;
    node.removeEventListener('wheel',binding.wheel);
    node.removeEventListener('keydown',binding.keydown);binding.dragScrollbar?.destroy();
    scrollBindings.delete(node);delete node.dataset.r22ScrollInput;
  }
  function removeHorizontalRail(node){
    const rail=node?.previousElementSibling;
    if(rail?.classList.contains('r22-horizontal-rail'))rail.remove();
    node.classList.remove('r22-axis-split-scroll');delete node.dataset.r22HorizontalRail;
  }
  function scrollRegion(node,label) {
    if(!node)return;node.classList.add('r22-scroll-region');node.dataset.r22Scroller='1';
    if(!['INPUT','TEXTAREA','SELECT'].includes(node.tagName)){
      if(!node.hasAttribute('tabindex')){node.tabIndex=0;node.dataset.r22AddedTabindex='1';}
      if(!node.hasAttribute('role')){node.setAttribute('role','region');node.dataset.r22AddedRole='1';}
      if(!node.getAttribute('aria-label')){node.setAttribute('aria-label',label);node.dataset.r22AddedAriaLabel='1';}
      bindScrollInput(node,label);
    }
    // One browser-owned element controls both axes. A synthetic sibling rail
    // made the native vertical scrollbar non-interactive on long reports and
    // duplicated scroll ownership. Horizontal overflow now remains on the
    // table region and appears only when its columns genuinely need it.
    removeHorizontalRail(node);
  }
  function clearScrollRegion(node){
    if(!node)return;unbindScrollInput(node);removeHorizontalRail(node);
    node.classList.remove('r22-scroll-region');delete node.dataset.r22Scroller;
    if(node.dataset.r22AddedTabindex==='1'){node.removeAttribute('tabindex');delete node.dataset.r22AddedTabindex;}
    if(node.dataset.r22AddedRole==='1'){node.removeAttribute('role');delete node.dataset.r22AddedRole;}
    if(node.dataset.r22AddedAriaLabel==='1'){node.removeAttribute('aria-label');delete node.dataset.r22AddedAriaLabel;}
  }
  function reconcileScrollPlan(s,owners,fillNodes=[]) {
    const next=new Set(owners.map(item=>item.node).filter(Boolean));
    for(const node of s.scrollOwners||[])if(!next.has(node))clearScrollRegion(node);
    for(const {node,label} of owners)if(node)scrollRegion(node,label);
    const paths=new Set(fillNodes.filter(Boolean));
    for(const node of s.fillNodes||[])if(!paths.has(node))node.classList.remove('r22-fill-path');
    for(const node of paths)node.classList.add('r22-fill-path');
    const nextPrimary=owners.find(item=>item.primary)?.node||null;
    // Registers and the report catalogue already own their native overflow,
    // height and flex rules. Promoting either one to the generic shell class
    // replaces those rules and leaves a visible scrollbar whose pointer/wheel
    // input cannot advance. Keep ActivityShell accessibility on the region,
    // but reserve primary promotion for shell-owned workspaces and panes.
    const promotedPrimary=nextPrimary&&!nextPrimary.matches('.r23-scroll,.r27-report-groups')?nextPrimary:null;
    if(s.primaryScroll&&s.primaryScroll!==promotedPrimary)s.primaryScroll.classList.remove('r22-primary-scroll');
    if(nextPrimary&&nextPrimary!==promotedPrimary)nextPrimary.classList.remove('r22-primary-scroll');
    if(promotedPrimary)promotedPrimary.classList.add('r22-primary-scroll');
    s.scrollOwners=next;s.fillNodes=paths;s.primaryScroll=promotedPrimary;
  }
  function pathNodes(node,stop) {
    const nodes=[];let n=node?.parentElement;while(n&&n!==stop){nodes.push(n);n=n.parentElement;}return nodes;
  }
  function arrange(s,c) {
    const {page,work}=s;
    page.dataset.r22Kind=c.kind;
    work.classList.remove('r22-task-flow','r22-task-grid','r22-task-split','r22-dashboard-flow','r22-guided-flow');

    // Base responsive decisions on actual application-body width, not browser
    // width, so expanded/collapsed navigation does not produce false fit.
    const availableWidth=s.body?.clientWidth||innerWidth;
    work.classList.toggle('r22-reflow',availableWidth<900||innerHeight<580);

    if(c.kind==='guided'){
      work.classList.add('r22-guided-flow');
      reconcileScrollPlan(s,[{node:work,label:'Guided bookkeeping workspace',primary:true}],[]);
      return;
    }
    if(c.kind==='dashboard'){
      work.classList.add('r22-dashboard-flow');
      const owners=[{node:work,label:'Dashboard overview',primary:true}],quick=$('.r17-quick-list',work);
      if(quick)owners.push({node:quick,label:'Quick Actions'});
      reconcileScrollPlan(s,owners,[]);
      return;
    }
    if(c.kind==='catalogue'){
      const groups=$('.r27-report-groups',work);
      work.classList.add('r22-task-grid');
      reconcileScrollPlan(s,groups?[{node:groups,label:'Report categories',primary:true}]:[{node:work,label:'Reports catalogue',primary:true}],groups?pathNodes(groups,work):[]);
      return;
    }
    const split=c.split&&$(c.split,work);
    if(split){
      work.classList.add('r22-task-split');split.classList.add('r22-split');
      const panes=$$(c.panes,split).filter(shown);
      const owners=panes.length
        ? panes.map((pane,i)=>({node:pane,label:i?'Selected accounting details':'Accounting records',primary:i===0}))
        : [{node:split,label:s.title+' workspace',primary:true}];
      reconcileScrollPlan(s,owners,pathNodes(split,work));
      return;
    }
    let candidates=c.primary?$$(c.primary,work).filter(n=>shown(n)):[];if(page.dataset.r22AdvancedEditing==='1')candidates=[];
    // A creation/editor view takes priority over a background register.
    if(['bills','directory'].includes(c.kind)&&($('form[data-bill-form],form[data-customer-form],form[data-vendor-form],form[data-employee-form]',work)||$('[data-r22-product-editor]:not([hidden])',work)))candidates=[];
    const primary=candidates.find(n=>!n.closest('[hidden]'));
    if(primary){
      work.classList.add('r22-task-grid');
      reconcileScrollPlan(s,[{node:primary,label:s.title+' records',primary:true}],pathNodes(primary,work));
    }else{
      work.classList.add('r22-task-flow');
      reconcileScrollPlan(s,[{node:work,label:s.title+' content',primary:true}],[]);
    }
  }
  function plainStates(s) {
    // Preserve unavailable amounts. Only translate a known internal reason label.
    $$('[data-review-balance-strip] [title]',s.work).forEach(n=>{if(n.title==='history_gap')n.title='The imported statement history is incomplete; this balance is not verified.';});
  }
  function tableHeaderLabels(table) {
    const rows=$$('thead > tr',table);
    if(!rows.length)return [];
    const matrix=[];
    rows.forEach((row,rowIndex)=>{
      matrix[rowIndex]=matrix[rowIndex]||[];
      let column=0;
      [...row.cells].forEach(cell=>{
        while(matrix[rowIndex][column])column++;
        const text=cell.textContent.replace(/\s+/g,' ').trim();
        const rowSpan=Math.max(1,Number(cell.rowSpan)||1),colSpan=Math.max(1,Number(cell.colSpan)||1);
        for(let r=rowIndex;r<rowIndex+rowSpan;r++){
          matrix[r]=matrix[r]||[];
          for(let c=column;c<column+colSpan;c++)if(!matrix[r][c])matrix[r][c]=text;
        }
        column+=colSpan;
      });
    });
    const width=Math.max(0,...matrix.map(row=>row.length));
    return Array.from({length:width},(_,column)=>{
      const parts=[];
      matrix.forEach(row=>{const text=row[column];if(text&&!parts.includes(text))parts.push(text);});
      return parts.join(' · ');
    });
  }
  function rowActionMenus(table,headers) {
    const actionIndex=headers.findIndex(label=>/\bactions?\b/i.test(label.trim()));
    if(actionIndex<0)return;
    $$('tbody > tr',table).forEach(row=>{
      // R161: rows held back by "Show more" (r161-more) are done when they are shown.
      if(row.classList.contains('r17-detail-row')||row.classList.contains('r161-more'))return;
      const cell=[...row.cells].find(item=>Number(item.dataset.r22Column)===actionIndex)||row.cells[actionIndex];
      if(!cell||cell.dataset.r22RowActions==='1'||$('details,[role="menu"]',cell))return;
      const actions=$$('button,a[href],[role="button"]',cell).filter(node=>!node.closest('table table'));
      if(!actions.length)return;
      const menu=details('Actions','r22-row-actions'),summary=$(':scope > summary',menu),panel=$(':scope > .r22-menu-panel',menu);
      const identity=[...row.cells].find(item=>item!==cell&&item.textContent.trim())?.textContent.replace(/\s+/g,' ').trim().slice(0,80)||'record';
      summary.setAttribute('aria-label','Actions for '+identity);summary.setAttribute('aria-haspopup','menu');panel.setAttribute('role','menu');
      [...cell.childNodes].forEach(node=>panel.append(node));
      actions.forEach(action=>action.setAttribute('role','menuitem'));
      cell.append(menu);cell.dataset.r22RowActions='1';bindMenu(menu);
      menu.addEventListener('click',event=>{if(event.target.closest('button,a[href],[role="menuitem"]'))queueMicrotask(()=>{if(menu.isConnected)menu.open=false;});});
    });
  }
  function annotateTable(table) {
    if(table.hasAttribute('data-r23-table')||table.hasAttribute('data-r22-native-table'))return;
    if(!shown(table)||table.closest('.r20-profit'))return;
    const headers=tableHeaderLabels(table);
    if(!headers.length)return;
    table.dataset.r22Table='1';table.setAttribute('role','table');
    const caption=$('caption',table);if(caption)caption.classList.add('r22-sr-only');
    let occupied=[];
    $$('tbody > tr,tfoot > tr',table).forEach(row=>{
      if(row.classList.contains('r17-detail-row')||row.classList.contains('r161-more'))return;
      row.setAttribute('role','row');let column=0,next=occupied.map(n=>Math.max(0,n-1));
      [...row.cells].forEach(cell=>{
        while(occupied[column]>0)column++;
        const index=Number(cell.dataset.r22Column??column);
        cell.dataset.r22Column=String(index);
        cell.setAttribute('role',cell.tagName==='TH'?'rowheader':'cell');
        if(cell.colSpan>1)cell.dataset.r22Span='1';
        else {
          if(!cell.dataset.label)cell.dataset.label=headers[index]||'Select / actions';
          if(/amount|debit|credit|balance|total|tax|net|gross|price|variance/i.test(headers[index]||'')||cell.matches('.srp-money,.num'))cell.dataset.r22Numeric='1';
        }
        for(let c=0;c<cell.colSpan;c++)next[index+c]=Math.max(next[index+c]||0,cell.rowSpan-1);
        column=index+cell.colSpan;
      });occupied=next;
    });
    rowActionMenus(table,headers);
    // Keep every actual cell and control in the DOM. Stacking never clones a
    // field or makes a secondary amount unavailable on mobile/expanded sidebar.
    const region=table.parentElement,available=region?.clientWidth||table.clientWidth;
    const state=tableStates.get(table)||{width:0,stacked:false};
    if(Math.abs(available-state.width)>3){table.classList.remove('r22-stacked-table');state.stacked=false;}
    const columns=headers.length,minimum=columns>8?columns*110+100:columns*92;
    // R121: cards only on phone-width containers. On a desktop (including a
    // zoomed-in one) a wide table keeps its columns and scrolls sideways; its
    // container is overflow-x:auto. Stacking a 1,180px desktop table into
    // cards clipped values at the right edge (Bank Reconciliation Report).
    const needsStack=available<640&&(available<Math.max(480,minimum)||(!state.stacked&&table.scrollWidth>available+2));
    if(needsStack){table.classList.add('r22-stacked-table');state.stacked=true;}
    const thead=$('thead',table);if(thead){thead.classList.toggle('r22-select-head',!!$('input,button,select',thead));$$('th,td',thead).forEach(cell=>(cell.classList.toggle('r22-header-control',!!$('input,button,select',cell)),cell.dataset.r22SelectLabel=table.closest('.tegh-import-verification')?'Select filtered records':'Select this page'));}
    state.width=available;tableStates.set(table,state);
  }
  function enhanceOverlay(panel) {
    if(!panel?.isConnected)return;
    const previous=overlays.get(panel);if(previous){previous.refresh();return;}
    panel.classList.add('r22-overlay');
    let scrollOwners=new Set();
    const refresh=()=>{if(!panel.isConnected)return;observer.disconnect();
      const planned=[];
      const importRegion=$('.tegh-import-review-body',panel)||$('.tegh-import-verification-table',panel);if(importRegion)planned.push({node:importRegion,label:'Import preview records and validation'});
      const quickBody=$('.tegh-qa-v4-body',panel),selected=$('[data-qa-v4-selected-list]',panel),results=$('[data-qa-v4-results-list]',panel);
      if(quickBody)planned.push({node:quickBody,label:'Quick Actions lists'});if(selected)planned.push({node:selected,label:'Selected Quick Actions'});if(results)planned.push({node:results,label:'Available Quick Actions'});
      const next=new Set(planned.map(item=>item.node));for(const node of scrollOwners)if(!next.has(node))clearScrollRegion(node);for(const item of planned)scrollRegion(item.node,item.label);scrollOwners=next;
      $$(tableSelector,panel).forEach(annotateTable);observer.observe(panel,{childList:true,subtree:true});};
    const observer=new MutationObserver(refresh),resize=new ResizeObserver(refresh);
    overlays.set(panel,{refresh,observer,resize,get scrollOwners(){return scrollOwners}});resize.observe(panel);refresh();
  }
  function detachOverlay(panel) {
    const o=overlays.get(panel);if(o){o.observer.disconnect();o.resize.disconnect();for(const node of o.scrollOwners||[])clearScrollRegion(node);overlays.delete(panel);}
  }
  function formLayout(s) {
    $$('.sra-field',s.work).forEach(field=>{const label=$('label',field),input=$('input,select,textarea',field);if(label&&input&&!label.htmlFor){if(!input.id)input.id='r22-field-'+(++sequence);label.htmlFor=input.id;}});
    // R26: compact scope labels are consistent across modules. Keep the words
    // From / To and Bank / Credit account beside their field rather than
    // consuming a second line. This only changes geometry; labels remain real
    // label elements and still wrap safely at narrow widths.
    $$('label',s.page).forEach(label=>{const control=$(':scope > input[type="date"],:scope > select',label);if(!control)return;const direct=[...label.childNodes].filter(n=>n.nodeType===Node.TEXT_NODE).map(n=>n.textContent).join(' ').replace(/\s+/g,' ').trim();if(/^(?:from(?: date)?|to(?: date)?|bank account|bank \/ credit account|current bank \/ credit account)$/i.test(direct))label.classList.add('r26-inline-field');});
    // Required fields are kept visible; "full" was a legacy span, not a domain rule.
    $$('form.srp-form,form.sra-form,.r20-fields',s.work).forEach(form=>form.classList.add('r22-form-grid'));
    $$('.srp-actions',s.work).filter(n=>n.parentElement?.matches('form')).forEach(n=>n.classList.add('r22-form-actions'));
    $$('input[type=date]',s.work).forEach(input=>{if(!input.getAttribute('aria-label')&&!input.closest('label'))input.setAttribute('aria-label',input.name||'Date');});
  }
  function namedSections(s) {
    // Only these explicitly non-blocking explanations are disclosures.
    const allowed=['.srp-ci-section-title > p','.srp-ci-summary'];
    allowed.forEach(selector=>$$(selector,s.work).forEach(n=>{
      if(n.closest('details'))return;
      const d=document.createElement('details');d.className='r22-inline-help';d.innerHTML='<summary>Details</summary>';n.before(d);d.append(n);
    }));
  }
  function forecastMarkup(forecast) {
    const points=Array.isArray(forecast?.points)?forecast.points:[];
    if(!points.length)return '<p class="srp-empty">No forecast points are available for this horizon.</p>';
    if(points.some(p=>!Number.isSafeInteger(p.closingCents)))return '<p class="srp-note" role="status">Forecast values are incomplete. Refresh the forecast; unavailable balances have not been treated as zero.</p>';
    const fmt=n=>{try{return new Intl.NumberFormat('en-CA',{style:'currency',currency:forecast.currency||'CAD'}).format(n/100)}catch{return String(forecast.currency||'')+' '+(n/100).toFixed(2)}};
    const values=points.map(p=>p.closingCents),low=Math.min(0,...values),high=Math.max(0,...values),data={points:points.map(p=>({label:String(p.label||p.periodEnd||p.date||''),value:p.closingCents,text:fmt(p.closingCents)})),low,high};
    return `<figure class="tegh-fa-chart r22-forecast-chart" data-r22-forecast="${esc(JSON.stringify(data))}"><figcaption><b>Projected ${esc(forecast.bucketUnit||'month')}-end cash</b><span>${esc(forecast.horizonStart||'')} to ${esc(forecast.horizonEnd||'')} · Planning estimate</span></figcaption><div class="r22-forecast-scale"><span>Scale minimum <b>${esc(fmt(low))}</b></span><span>Scale maximum <b>${esc(fmt(high))}</b></span></div><svg role="img" aria-label="Forecast cash trend. Focus a point for its exact period and closing amount."></svg><div class="r22-forecast-dates"><span>${esc(data.points[0].label)}</span><span>${esc(data.points.at(-1).label)}</span></div><output class="r22-forecast-value" aria-live="polite">${esc(data.points[0].label)}: ${esc(data.points[0].text)}</output></figure>`;
  }
  function fitForecastCharts(s) {
    $$('[data-r22-forecast]',s.work).forEach(figure=>{
      if(!shown(figure))return;
      const width=Math.floor(figure.clientWidth);if(width<100)return;
      const raw=figure.dataset.r22Forecast;
      if(figure._r22ChartWidth===width&&figure._r22ChartData===raw)return;
      let data;try{data=JSON.parse(raw)}catch{return}
      const points=Array.isArray(data.points)?data.points:[];if(!points.length)return;
      const svg=$('svg',figure);if(!svg)return;
      const height=180,pad=10,range=Number(data.high)-Number(data.low);
      const x=i=>pad+(points.length===1?(width-2*pad)/2:i*(width-2*pad)/(points.length-1));
      const y=v=>range===0?height/2:pad+(data.high-v)*(height-2*pad)/range;
      svg.setAttribute('viewBox',`0 0 ${width} ${height}`);svg.style.height=height+'px';
      svg.innerHTML=`<line class="tegh-fa-zero" x1="${pad}" x2="${width-pad}" y1="${y(0)}" y2="${y(0)}"/><polyline points="${points.map((p,i)=>x(i).toFixed(1)+','+y(p.value).toFixed(1)).join(' ')}"/>${points.map((p,i)=>`<circle tabindex="0" role="img" data-r22-point="${i}" aria-label="${esc(p.label+': '+p.text)}" class="${p.value<0?'is-negative':''}" cx="${x(i)}" cy="${y(p.value)}" r="5"><title>${esc(p.label+': '+p.text)}</title></circle>`).join('')}`;
      if(!figure._r22PointBound){
        const show=e=>{
          const point=e.target.closest('[data-r22-point]');if(!point)return;
          let current;try{current=JSON.parse(figure.dataset.r22Forecast)}catch{return}
          const p=current.points?.[Number(point.dataset.r22Point)];if(!p)return;
          const output=$('output',figure);if(output)output.textContent=p.label+': '+p.text;
        };
        figure.addEventListener('focusin',show);figure.addEventListener('pointerover',show);figure.addEventListener('click',show);figure._r22PointBound=true;
      }
      figure._r22ChartWidth=width;figure._r22ChartData=raw;
    });
  }
  function density() {
    const pref=root.dataset.teghTableDensity||root.dataset.teghNavDensity||'comfortable';
    root.dataset.r22Density=/compact/.test(pref)?'compact':'comfortable';
  }
  function resolveLiveShell(page) {
    const current=states.get(page);
    return {
      body:$(':scope > .srp-page-body',page)||$('.srp-page-body',page),
      // The portal may replace the body's contents while refreshing a tab.
      // Our authored toolbar lives inside that body, so restore its actual
      // controls and handlers if the refresh detached it.
      head:$(':scope > .srp-page-head',page)||$('.srp-page-head',page)||current?.head
    };
  }
  function mount(page) {
    if(!page?.isConnected)return;
    const live=resolveLiveShell(page);if(!live.body||!live.head)return;
    let s=states.get(page);
    if(!s){
      s={page,body:live.body,head:live.head,observer:null,resize:null,everMounted:false,title:'',work:null,scrollOwners:new Set(),fillNodes:new Set(),primaryScroll:null,readyEmitted:false,baseTools:null};
      states.set(page,s);
      page.dataset.r22Shell='1';s.body.classList.add('r22-render-root');
      s.observer=new MutationObserver(()=>{if(root.dataset.teghOverlayMoving!=='1')schedule(page);});
      s.resize=new ResizeObserver(()=>schedule(page));
      s.resize.observe(s.body);
    }else{
      const bodyChanged=s.body!==live.body,headChanged=s.head!==live.head;
      if(bodyChanged||headChanged){
        s.observer?.disconnect();
        if(s.body){try{s.resize?.unobserve(s.body);}catch{/* body may already be detached */}}
        for(const node of s.scrollOwners||[])clearScrollRegion(node);
        for(const node of s.fillNodes||[])node.classList.remove('r22-fill-path');
        if(s.primaryScroll)s.primaryScroll.classList.remove('r22-primary-scroll');
        s.body=live.body;s.head=live.head;s.work=null;s.scrollOwners=new Set();s.fillNodes=new Set();s.primaryScroll=null;s.readyEmitted=false;s.analysisToolbars=[];s.reviewSelected=null;
        s.body.classList.add('r22-render-root');
        if(headChanged){s.everMounted=false;s.baseTools=null;s.title='';delete s.head.dataset.r22Authored;}
      }
    }
    s.observer.disconnect();s.resize.observe(s.body);const focus=document.activeElement;
    attachWorkspace(page,s);const c=contract(page);
    simplifyHeadings(s);toolbar(s,c);authoredChrome(s,c);operatorChrome(s,c);finalChrome(s,c);formLayout(s);namedSections(s);arrange(s,c);plainStates(s);fitToolbar(s);
    $$(tableSelector,s.work).forEach(annotateTable);fitForecastCharts(s);
    $$('.r22-menu',page).forEach(bindMenu);density();
    if(focus?.isConnected&&document.activeElement!==focus)focusSafe(focus);
    s.observer.observe(s.body,{childList:true,subtree:true});
    page.dataset.r22Ready='1';active=page;
    root.dataset.teghLayoutRevision='r22';
    if(!s.readyEmitted){
      s.readyEmitted=true;
      window.dispatchEvent(new CustomEvent('tegh:activity-ready',{detail:{page,route:page.dataset.srpPage,kind:c.kind,revision:'r22'}}));
    }
  }
  function schedule(page=active) {
    if(page)active=page;if(frame)return;
    frame=requestAnimationFrame(()=>{frame=0;if(active?.isConnected)mount(active);});
  }
  function detach(page) {
    const s=states.get(page);if(!s)return;
    s.observer?.disconnect();s.resize?.disconnect();
    for(const node of s.scrollOwners||[])clearScrollRegion(node);
    for(const node of s.fillNodes||[])node.classList.remove('r22-fill-path');
    if(s.primaryScroll)s.primaryScroll.classList.remove('r22-primary-scroll');
    states.delete(page);delete page.dataset.r22Ready;
    if(active===page)active=null;
  }
  function navigation(layout,shouldSchedule=true) {
    const app=$('.app'),bar=$('.topbar');if(!app||!bar)return;
    // Never interpret an unknown/uninitialised preference as side navigation.
    if(layout!=='top'&&layout!=='side')return;
    const top=layout==='top';root.dataset.teghNavigationLayout=top?'top':'side';
    document.body.classList.toggle('tegh-top-navigation-mode',top);
    // The portal owns the permission-aware module menus and utility rail.
    // Remove only obsolete ActivityShell-owned navigation/mode controls.
    $$('.r22-modules,[data-r22-mode]',app).forEach(node=>node.remove());
    if(shouldSchedule)schedule();
  }
  document.addEventListener('click',e=>{$$('details.r22-menu[open]').forEach(n=>{const panel=n.__teghOverlayPanel;if(!n.contains(e.target)&&!panel?.contains(e.target))n.open=false;});});
  window.addEventListener('resize',()=>{$$('details.r22-menu[open]').forEach(positionMenu);schedule();},{passive:true});
  window.addEventListener('tegh:page-rendered',event=>{
    const page=event.detail?.page;if(!page)return;
    if(active&&active!==page)detach(active);
    // Refresh permission-aware navigation before applying the resolved layout.
    window.TeghPortal?.refreshNavigation?.();
    navigation(root.dataset.teghNavigationLayout,false);
    const existing=states.get(page);if(existing)existing.readyEmitted=false;
    mount(page);
  });
  document.addEventListener('tegh:report-model-ready',()=>schedule());
  window.TeghActivityShell=Object.freeze({revision:'r22',forecastMarkup,mount,refresh:schedule,detach,navigation,enhanceOverlay,detachOverlay,contracts:contracts.map(c=>({routePattern:c.match.source,kind:c.kind})),
    metrics(page=active){const s=states.get(page);if(!s)return null;const rect=n=>n?.getBoundingClientRect().toJSON();return {revision:'r22',route:page.dataset.srpPage,kind:page.dataset.r22Kind,viewport:{width:innerWidth,height:innerHeight,dpr:devicePixelRatio},toolbar:rect(s.head),workspace:rect(s.work),scrollOwners:$$('[data-r22-scroller]',s.body).map(n=>({tag:n.tagName,className:n.className,clientHeight:n.clientHeight,scrollHeight:n.scrollHeight,clientWidth:n.clientWidth,scrollWidth:n.scrollWidth})),bodyScroll:document.documentElement.scrollHeight>innerHeight+2};}});
})();
