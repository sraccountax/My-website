(() => {
  'use strict';

  const BUILD = '5700';
  const VERSION = '5.7.0';
  const $ = (selector, root=document) => root.querySelector(selector);
  const $$ = (selector, root=document) => Array.from(root.querySelectorAll(selector));
  const norm = value => String(value || '').replace(/&/g,'and').replace(/\s+/g,' ').trim().toLowerCase();

  const moduleWorkspaces = {
    Receivables: {
      Activity: [
        ['Create Customer', () => portal()?.invokeMenuAction?.('Receivables','Create Customer'), ['customers','core-customer']],
        ['Products & Services', () => portal()?.invokeMenuAction?.('Receivables','Products and Services'), ['products']],
        ['Customer Invoice', () => portal()?.invokeMenuAction?.('Receivables','Customer Invoices'), ['customer-invoice','core-invoice']],
        ['Customer Payments', () => portal()?.invokeMenuAction?.('Receivables','Customer Payments'), ['customer-payments']],
      ],
      Reports: [
        ['Customers', () => portal()?.invokeMenuAction?.('Receivables','Customers'), ['customers']],
        ['Customer Invoice Register', () => portal()?.invokeMenuAction?.('Receivables','Customer Invoice Register'), ['invoices']],
        ['Customer Ledgers', () => portal()?.invokeMenuAction?.('Receivables','Customer Ledgers'), ['ledger-customer']],
        ['Receivable Ageing', () => portal()?.invokeMenuAction?.('Receivables','Receivable Ageing'), ['aging-receivable']],
        ['Period Trial Balance', () => portal()?.invokeMenuAction?.('Receivables','Period Trial Balance'), ['trial-balance-receivables']],
      ],
    },
    Payables: {
      Activity: [
        ['Create Vendor', () => portal()?.invokeMenuAction?.('Payables','Create Vendor'), ['vendors']],
        ['Products & Services', () => portal()?.invokeMenuAction?.('Payables','Products and Services'), ['products']],
        ['Vendor Invoice', () => portal()?.invokeMenuAction?.('Payables','Vendor Invoices'), ['bills']],
        ['Vendor Payments', () => portal()?.invokeMenuAction?.('Payables','Vendor Payments'), ['vendor-payments']],
      ],
      Reports: [
        ['Vendors', () => portal()?.invokeMenuAction?.('Payables','Vendors'), ['vendors']],
        ['Vendor Invoice Register', () => portal()?.invokeMenuAction?.('Payables','Vendor Invoice Register'), ['report-bill-register']],
        ['Vendor Ledgers', () => portal()?.invokeMenuAction?.('Payables','Vendor Ledgers'), ['ledger-vendor']],
        ['Payable Ageing', () => portal()?.invokeMenuAction?.('Payables','Payable Ageing'), ['aging-payable']],
        ['Period Trial Balance', () => portal()?.invokeMenuAction?.('Payables','Period Trial Balance'), ['trial-balance-payables']],
      ],
    },
    'General ledger': {
      Activity: [
        ['Journal Entries', () => portal()?.invokeMenuAction?.('General ledger','Journal Entries'), ['gl-journal-entry']],
        ['Expense Vouchers', () => portal()?.invokeMenuAction?.('General ledger','Expense Vouchers'), ['expense-vouchers','expense-register','core-expenses']],
      ],
      Reports: [
        ['Day Book', () => portal()?.invokeMenuAction?.('General ledger','Day Book'), ['day-book']],
        ['GL Account Ledger', () => portal()?.invokeMenuAction?.('General ledger','GL Account Ledger'), ['report-gl-account-ledger']],
        ['Period Trial Balance', () => portal()?.invokeMenuAction?.('General ledger','Period Trial Balance'), ['trial-balance-general-ledger']],
        ['GIFI Report', () => portal()?.invokeMenuAction?.('General ledger','GIFI Report'), ['gifi-report']],
      ],
    },
    Payroll: {
      Activity: [
        ['Payroll & Tax Center', () => portal()?.openPayrollTaxCenter?.(), ['payroll-tax-center']],
        ['Quick Calculation', () => portal()?.invokeMenuAction?.('Payroll','Quick Calculation'), ['payroll-quick-calculator']],
        ['Employees', () => portal()?.invokeMenuAction?.('Payroll','Employees'), ['payroll-employees','core-payroll']],
        ['Pay Run Register', () => portal()?.invokeMenuAction?.('Payroll','Pay Run Register'), ['payroll-runs']],
        ['Payroll Verification', () => portal()?.invokeMenuAction?.('Payroll','Payroll Verification'), ['payroll-verification']],
        ['CRA Remittance', () => portal()?.invokeMenuAction?.('Payroll','CRA Remittance'), ['payroll-remittance']],
      ],
      Reports: [
        ['Payroll History', () => portal()?.invokeMenuAction?.('Payroll','Payroll History'), ['payroll-history']],
      ],
    },
  };

  const reportGroups = {
    'Financial Statements': [
      ['Profit and Loss', () => portal()?.openFinancialReport?.('profit-loss'), ['financial-profit-loss']],
      ['Balance Sheet', () => portal()?.openFinancialReport?.('balance-sheet'), ['financial-balance-sheet']],
      ['Trial Balance', () => portal()?.openPeriodTrialBalance?.('general-ledger','Reports'), ['financial-trial-balance','trial-balance-general-ledger']],
      ['Cash Flow', () => openReport('Cash Flow'), ['cash-flow']],
    ],
    'General Ledger and Audit': [
      ['GL Account Ledger', () => portal()?.openGlAccountLedger?.('', 'Reports'), ['report-gl-account-ledger']],
      ['General Ledger Detail', () => openReport('General Ledger Detail'), ['report-general-ledger']],
      ['Day Book', () => openReport('Day Book'), ['day-book']],
      ['Audit History', () => openReport('Audit History'), ['audit-history']],
      ['GIFI Report', () => portal()?.invokeMenuAction?.('General ledger','GIFI Report'), ['gifi-report']],
    ],
    Receivables: [
      ['Receivable Ageing', () => openReport('Receivable Ageing'), ['aging-receivable']],
      ['Customer Balances', () => openReport('Customer Balances'), ['report-customer-balances']],
      ['Customer Invoice Register', () => openReport('Customer Invoice Register'), ['report-invoice-register','invoices']],
    ],
    'Payables and Expenses': [
      ['Payable Ageing', () => openReport('Payable Ageing'), ['aging-payable']],
      ['Vendor Balances', () => openReport('Vendor Balances'), ['report-vendor-balances']],
      ['Vendor Invoice Register', () => openReport('Vendor Invoice Register'), ['report-bill-register']],
      ['Expense Register', () => openReport('Expense Register'), ['report-expense-register','expense-vouchers']],
    ],
    'Accounting': [
      ['Inventory Report', () => portal()?.openInventoryReport?.(), ['report-inventory']],
    ],
    Banking: [
      ['Bank Reconciliation Summary', () => openReport('Bank Reconciliation Summary'), ['report-bank-reconciliation']],
      ['Bank Transaction Report', () => openReport('Bank Transaction Report'), ['report-bank-transactions']],
    ],
    Tax: [
      ['GST/HST Summary', () => openReport('GST/HST Summary'), ['report-tax-summary']],
    ],
    Payroll: [
      ['Pay Run Register', () => openReport('Pay Run Register'), ['payroll-runs']],
    ],
    Management: [
      ['Financial Analyst', () => portal()?.openFinancialAnalyst?.(), ['financial-analyst']],
      ['Currency Exposure', () => openReport('Currency Exposure'), ['report-currency-exposure']],
      ['Budget versus Actual', () => openReport('Budget versus Actual'), ['advanced-budgets']],
      ['Fixed Asset Register', () => openReport('Fixed Asset Register'), ['advanced-assets']],
    ],
  };

  const preferredGroups = Object.create(null);
  const portal = () => window.SRBooksPortal;
  const setPreferredGroup = (module,group) => { if(module && group) preferredGroups[module]=group; };
  const getPreferredGroup = module => preferredGroups[module] || '';
  window.SRBooksWorkflowNavigation = Object.assign(window.SRBooksWorkflowNavigation||{},{
    setPreferredGroup,
    getPreferredGroup,
    build:BUILD,
  });

  function visible(node) {
    if (!node || !node.isConnected || node.hidden) return false;
    if (node.closest('[hidden]')) return false;
    const style = getComputedStyle(node);
    return style.display !== 'none' && style.visibility !== 'hidden';
  }

  function activeRoot() {
    const custom = $$('.srp-page').reverse().find(visible);
    if (custom) return custom;
    const core = $('.stage > .content, .stage main.content');
    return visible(core) ? core : null;
  }

  function routeOf(root) {
    if (!root) return '';
    if (root.classList.contains('srp-page')) return root.dataset.teghRoute || root.dataset.srpPage || '';
    const page = String(root.dataset.page || '').toLowerCase();
    return page ? `core-${page}` : '';
  }

  function moduleOf(root) {
    if (!root) return '';
    if (root.classList.contains('srp-page')) return root.dataset.srpModule || '';
    const page = String(root.dataset.page || '').toLowerCase();
    return ({sales:'Receivables',customer:'Receivables',invoice:'Receivables',payables:'Payables',expenses:'General ledger',accounting:'General ledger',payroll:'Payroll',reports:'Reports'})[page] || '';
  }

  function pageTitle(root=activeRoot()) {
    return ($('.srp-page-head h1',root) || $('h1',root) || $('h2',root))?.textContent?.replace(/\s+/g,' ').trim() || 'this record';
  }

  function isRecordForm(form) {
    if (!form || !visible(form)) return false;
    if (form.matches('.srp-register-filter,.srp-report-period,.srp-filter,[data-workspace-filter],[data-financial-filter],[data-payrun-filter],[data-invoice-filter]')) return false;
    if (form.closest('.srp-sites-catalogue-search,.srp-report-head')) return false;
    const signature = `${form.id || ''} ${form.className || ''} ${Object.keys(form.dataset || {}).join(' ')}`.toLowerCase();
    if (/customer|vendor|invoice|payment|product|employee|journal|expense|opening|company|account|payroll|run|bill/.test(signature)) return true;
    const submit = $$('button[type="submit"],input[type="submit"]',form).find(visible);
    const text = norm(submit?.textContent || submit?.value || '');
    return /save|create|record|post|add|update|complete|submit|recalculate/.test(text);
  }

  function dirtyForms(root=activeRoot()) {
    if (!root) return [];
    return $$('form',root).filter(form => visible(form) && isRecordForm(form) && (form.dataset.srpDirty === '1' || form.dataset.teghWorkflowDirty === '1'));
  }

  function hasUnsaved(root=activeRoot()) {
    return dirtyForms(root).length > 0;
  }

  function clearDirty(root=activeRoot()) {
    if (!root) return;
    $$('form',root).forEach(form => { form.dataset.teghWorkflowDirty='0'; if (form.dataset.srpDirty === '1') form.dataset.srpDirty='0'; });
  }

  function confirmNavigate(targetLabel) {
    const root = activeRoot();
    if (!hasUnsaved(root)) return true;
    const current = pageTitle(root);
    const ok = window.confirm(`You have unsaved changes on ${current}. If you open ${targetLabel}, those changes will be lost. Continue without saving?`);
    if (ok) clearDirty(root);
    return ok;
  }

  function guarded(label, action) {
    if (!confirmNavigate(label)) return;
    try { action?.(); } catch (error) { console.error('Tegh workflow navigation failed', error); }
  }

  function waitFor(check, timeout=3700) {
    const started=Date.now();
    return new Promise(resolve => {
      const tick=()=>{
        let result=null;
        try { result=check(); } catch (_) {}
        if (result || Date.now()-started>=timeout) return resolve(result || null);
        setTimeout(tick,50);
      };
      tick();
    });
  }

  async function openModuleItem(module, label) {
    const p=portal(); if (!p) return;
    p.openModule?.(module);
    const button=await waitFor(() => {
      const root=activeRoot();
      return $$('[data-module-action-id],[data-module-toolbar-action],button',root).find(node => visible(node) && norm(node.textContent).startsWith(norm(label)));
    });
    button?.click();
  }

  async function openReport(label) {
    const p=portal(); if (!p) return;
    p.openReports?.();
    const button=await waitFor(() => $$('.srp-page [data-report]').find(node => visible(node) && norm(node.textContent).includes(norm(label))));
    button?.click();
  }

  function customersIsActivity(root) {
    const form=$('[data-customer-form]',root);
    return !!form && /new customer/i.test(form.textContent || '');
  }

  function vendorsIsActivity(root) {
    const form=$('[data-vendor-form]',root);
    return !!form && /new vendor/i.test(form.textContent || '');
  }

  function billsIsActivity(root) {
    return !!$('form',root) && /new vendor invoice|record vendor invoice|save vendor invoice/i.test(root.textContent || '');
  }

  function currentModuleGroup(module, route, root) {
    if (module === 'Receivables') {
      if (route === 'customers') { if (customersIsActivity(root)) { setPreferredGroup(module,'Activity'); return 'Activity'; } return getPreferredGroup(module) || 'Reports'; }
      if (['products','customer-invoice','customer-payments','core-invoice','core-customer','core-sales'].includes(route)) { setPreferredGroup(module,'Activity'); return 'Activity'; }
      setPreferredGroup(module,'Reports'); return 'Reports';
    }
    if (module === 'Payables') {
      if (route === 'vendors') { if (vendorsIsActivity(root)) { setPreferredGroup(module,'Activity'); return 'Activity'; } return getPreferredGroup(module) || 'Reports'; }
      if (route === 'bills') { if (billsIsActivity(root)) { setPreferredGroup(module,'Activity'); return 'Activity'; } return getPreferredGroup(module) || 'Reports'; }
      if (['products','vendor-payments','core-payables'].includes(route)) { setPreferredGroup(module,'Activity'); return 'Activity'; }
      setPreferredGroup(module,'Reports'); return 'Reports';
    }
    if (module === 'General ledger') {
      if (['gl-journal-entry','expense-vouchers','expense-register','core-accounting','core-expenses'].includes(route)) { setPreferredGroup(module,'Activity'); return 'Activity'; }
      setPreferredGroup(module,'Reports'); return 'Reports';
    }
    if (module === 'Payroll') {
      const group=route === 'payroll-history' ? 'Reports' : 'Activity';setPreferredGroup(module,group);return group;
    }
    return '';
  }

  function activeItem(items, route, root, group) {
    if (route === 'customers' && group === 'Activity' && customersIsActivity(root)) return 'Create Customer';
    if (route === 'vendors' && group === 'Activity' && vendorsIsActivity(root)) return 'Create Vendor';
    if (route === 'bills' && group === 'Activity' && billsIsActivity(root)) return 'Vendor Invoice';
    const found=items.find(([, , routes]) => routes.includes(route));
    return found?.[0] || '';
  }

  function reportGroupFor(route) {
    for (const [group,items] of Object.entries(reportGroups)) {
      if (items.some(([, , routes]) => routes.includes(route))) return group;
    }
    return '';
  }

  function makeButton(label, active, action, step='') {
    const button=document.createElement('button');
    button.type='button'; button.className='tegh-context-tab'; button.classList.toggle('active',!!active);
    if (step) button.dataset.step=step;
    button.innerHTML=`${step?`<span>${step}</span>`:''}<b>${label}</b>`;
    button.addEventListener('click', () => { if (!active) guarded(label,action); });
    return button;
  }

  function renderModuleBar(root,module,route) {
    const workspace=moduleWorkspaces[module]; if (!workspace) return null;
    const group=currentModuleGroup(module,route,root); if (!group) return null;
    const items=workspace[group] || [], active=activeItem(items,route,root,group);
    const bar=document.createElement('section');bar.className='tegh-context-bar';bar.dataset.teghContextBuild=BUILD;
    const top=document.createElement('div');top.className='tegh-context-top';
    const title=document.createElement('div');title.innerHTML=`<small>${module}</small><strong>${group === 'Activity' ? 'Workflow' : 'Reports'}</strong>`;top.append(title);
    const switcher=document.createElement('nav');switcher.className='tegh-context-switch';switcher.setAttribute('aria-label',`${module} section`);
    ['Activity','Reports'].forEach(name=>{
      const button=document.createElement('button');button.type='button';button.textContent=name;button.classList.toggle('active',name===group);
      button.addEventListener('click',()=>{if(name===group)return;const first=workspace[name]?.[0];if(first)guarded(name,()=>{setPreferredGroup(module,name);first[1]()})});switcher.append(button);
    });
    top.append(switcher);
    if(group==='Activity'&&active){
      const activeIndex=items.findIndex(item=>item[0]===active),steps=document.createElement('div');steps.className='srp-workflow-step-actions';
      if(activeIndex>0){const previous=document.createElement('button');previous.type='button';previous.className='tegh-context-all';previous.textContent='← Previous Step';previous.addEventListener('click',()=>guarded('Previous Step',()=>{setPreferredGroup(module,group);items[activeIndex-1][1]()}));steps.append(previous)}
      if(activeIndex>=0&&activeIndex<items.length-1){const next=document.createElement('button');next.type='button';next.className='tegh-context-all';next.textContent='Next Step →';next.addEventListener('click',()=>guarded('Next Step',()=>{setPreferredGroup(module,group);items[activeIndex+1][1]()}));steps.append(next)}
      if(steps.children.length)top.append(steps);
    }
    bar.append(top);
    const tabs=document.createElement('nav');tabs.className=`tegh-context-tabs ${group==='Activity'?'workflow':''}`;tabs.setAttribute('aria-label',`${module} ${group}`);
    items.forEach((item,index)=>tabs.append(makeButton(item[0],item[0]===active,()=>{setPreferredGroup(module,group);item[1]()},group==='Activity'?String(index+1):'')));
    bar.append(tabs);return bar;
  }

  function renderReportBar(root,route) {
    const group=reportGroupFor(route); if (!group) return null;
    const items=reportGroups[group],active=activeItem(items,route,root,'Reports');
    const bar=document.createElement('section');bar.className='tegh-context-bar tegh-report-context-bar';bar.dataset.teghContextBuild=BUILD;
    const top=document.createElement('div');top.className='tegh-context-top';
    const title=document.createElement('div');title.innerHTML=`<small>Reports</small><strong>${group}</strong>`;top.append(title);
    const all=document.createElement('button');all.type='button';all.className='tegh-context-all';all.textContent='All Reports';all.addEventListener('click',()=>guarded('All Reports',()=>portal()?.openReports?.()));top.append(all);bar.append(top);
    const tabs=document.createElement('nav');tabs.className='tegh-context-tabs';tabs.setAttribute('aria-label',group);
    items.forEach(item=>tabs.append(makeButton(item[0],item[0]===active,item[1])));bar.append(tabs);return bar;
  }

  function suppressCurrentStepCtas(root,module,bar) {
    const activeLabel=norm($('.tegh-context-tab.active b',bar)?.textContent||'');
    if(!activeLabel)return;
    const aliases={
      'create customer':['new customer','add customer','create customer'],
      'products and services':['new product','add product','add product or service','new service'],
      'customer invoice':['new invoice','create invoice','new customer invoice'],
      'customer payments':['record payment','new payment','record customer payment'],
      'create vendor':['new vendor','add vendor','create vendor'],
      'vendor invoice':['new vendor invoice','create vendor invoice','record vendor invoice','new bill'],
      'vendor payments':['record vendor payment','new vendor payment'],
      'quick calculation':['quick calculation','new quick calculation'],
      'employees':['new employee','add employee'],
      'pay run register':['new pay run','create pay run'],
      'cra remittance':['record remittance','new remittance']
    };
    const workflowOpen=bar.querySelector('.tegh-context-tabs.workflow');
    const wanted=new Set(workflowOpen?Object.values(aliases).flat():(aliases[activeLabel]||[]));
    if(!wanted.size)return;
    $$('button,a[role="button"]',root).forEach(button=>{
      if(button.closest('.tegh-context-bar')||button.closest('form')||button.closest('.sidebar'))return;
      const text=norm(button.textContent);if(wanted.has(text))button.classList.add('tegh-current-step-cta');
    });
  }

  function suppressDuplicates(root,module,bar) {
    const labels=[];
    bar.querySelectorAll('.tegh-context-tab b').forEach(node=>labels.push(norm(node.textContent)));
    if (!labels.length) return;
    $$('nav,.srp-segmented,[class*="tabs"],[class*="segmented"]',root).forEach(node=>{
      if (node===bar || node.closest('.tegh-context-bar') || node.closest('.sidebar')) return;
      const buttonLabels=$$('button',node).map(button=>norm(button.textContent)).filter(Boolean);
      const overlap=buttonLabels.filter(text=>labels.some(label=>text===label || text.includes(label) || label.includes(text))).length;
      if (overlap>=2) node.classList.add('tegh-workflow-legacy-duplicate');
    });
  }

  function installBar() {
    const root=activeRoot(); if(!root)return;
    if(root.dataset.r22Shell==='1'){ $$('.tegh-context-bar',root).forEach(n=>n.remove());return; }
    const route=routeOf(root),module=moduleOf(root);
    if (!route || route.startsWith('module-') || route==='reports-centre' || route==='dashboard') return;
    let bar=null;
    if (module==='Reports') bar=renderReportBar(root,route);
    if (!bar && moduleWorkspaces[module]) bar=renderModuleBar(root,module,route);
    if (!bar) return;
    const existing=$(':scope > .tegh-context-bar',root) || $('.tegh-context-bar',root);
    const signature=`${module}|${route}|${bar.querySelector('.tegh-context-top strong')?.textContent||''}`;
    if (existing?.dataset.signature===signature) { suppressDuplicates(root,module,existing); suppressCurrentStepCtas(root,module,existing); return; }
    existing?.remove(); bar.dataset.signature=signature;
    if (root.classList.contains('srp-page')) {
      const header=$(':scope > .srp-page-head',root);header?.insertAdjacentElement('afterend',bar) || root.prepend(bar);
    } else root.prepend(bar);
    suppressDuplicates(root,module,bar);
    suppressCurrentStepCtas(root,module,bar);
  }

  function bindDirtyTracking() {
    if (document.documentElement.dataset.teghWorkflowDirtyBound) return;
    document.documentElement.dataset.teghWorkflowDirtyBound=BUILD;
    const mark=event=>{
      const form=event.target?.closest?.('form');
      if (!form || !isRecordForm(form)) return;
      form.dataset.teghWorkflowDirty='1';
    };
    document.addEventListener('input',mark,true);document.addEventListener('change',mark,true);
    document.addEventListener('click',event=>{const button=event.target?.closest?.('[data-module-section-card] button');if(!button)return;const card=button.closest('[data-module-section-card]'),page=button.closest('.srp-page'),module=page?.dataset.srpModule,group=card?.dataset.moduleSectionCard;if(module&&group)setPreferredGroup(module,group)},true);
    document.addEventListener('reset',event=>{const form=event.target;if(form?.tagName==='FORM')form.dataset.teghWorkflowDirty='0'},true);
    window.addEventListener('beforeunload',event=>{if(!hasUnsaved())return;event.preventDefault();event.returnValue='';});
  }

  function init() {
    document.documentElement.dataset.teghWorkflowBuild=BUILD;
    bindDirtyTracking();
    let queued=false;
    const queue=()=>{if(queued)return;queued=true;requestAnimationFrame(()=>{queued=false;installBar()})};
    window.addEventListener('tegh:page-rendered',queue);window.addEventListener('tegh:enhance-subtree',queue);window.addEventListener('popstate',queue);queue();
    window.TeghWorkflowNavigation={version:VERSION,build:BUILD,refresh:queue,hasUnsaved};
  }

  if (document.readyState==='loading') document.addEventListener('DOMContentLoaded',init,{once:true}); else init();
})();
