/* R17 task-first workspace: responsive records, one scroll owner, and shared header utilities. */
(() => {
  'use strict';

  const $=(selector,root=document)=>root.querySelector(selector);
  const $$=(selector,root=document)=>[...root.querySelectorAll(selector)];
  const esc=value=>String(value??'').replace(/[&<>'"]/g,character=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[character]));
  const exportIcon='<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v12m-4-4 4 4 4-4M5 15v5h14v-5"/></svg>';
  const createIcon='<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 5v14M5 12h14"/></svg>';
  const userIcon='<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg>';
  const tableSelector='.srp-table-wrap,.sra-table-wrap,.srctl-table-wrap,.tegh-responsive-report';
  const exportSelector='[data-export-csv],[data-export-xls],[data-export-pdf],[data-export-visible],[data-export-all],[data-fa-forecast-csv],[data-forecast-export],[data-bank-export],[data-export-side]';
  const moneyPattern=/\b(amount|debit|credit|balance|total|tax|net|gross|price|opening|closing|outstanding|cad|usd|eur|gbp)\b/i;
  let observer,currentPage,frame=0,headerGeneration=0;

  document.documentElement.dataset.teghViewport='r17';

  function visible(node){return !!node&&!node.hidden&&!node.closest('[hidden]')&&getComputedStyle(node).display!=='none'}

  function exportMenu(label='Export'){
    const control=document.createElement('details');control.className='r17-menu r17-export-menu';
    control.innerHTML=`<summary class="srp-btn secondary">${exportIcon}<span>${esc(label)}</span><span aria-hidden="true">▾</span></summary><div class="r17-menu-panel r17-export-options" role="group" aria-label="${esc(label)} formats"></div>`;
    return control;
  }

  function exportLabel(button){
    if(button.dataset.exportSide)return `${button.dataset.exportSide==='bank'?'Bank statement':'Books'} · ${button.dataset.visible?'visible filtered':'all filtered'} entries · CSV`;
    if(button.hasAttribute('data-export-visible'))return 'Visible columns · all filtered rows · CSV';
    if(button.hasAttribute('data-export-all'))return 'All authorised columns · all filtered rows · CSV';
    if(button.hasAttribute('data-fa-forecast-csv')||button.hasAttribute('data-forecast-export'))return 'Current forecast · CSV';
    if(button.hasAttribute('data-export-pdf'))return 'All filtered rows · PDF';
    if(button.hasAttribute('data-export-xls'))return 'All filtered rows · Excel';
    if(button.hasAttribute('data-export-csv'))return 'All filtered rows · CSV';
    return String(button.textContent||'Export').replace(/\s+/g,' ').trim();
  }

  function consolidateExports(page){
    if($('[data-tegh-output-toolbar]',page))return;
    const candidates=$$(exportSelector,page).filter(button=>!button.closest('.r17-export-menu,[role="dialog"],.srp-modal-scrim'));
    if(!candidates.length)return;
    const actions=$('.tegh-page-head-actions',page);if(!actions)return;
    let control=$('[data-r17-view-export]',actions);
    if(!control){control=exportMenu();control.dataset.r17ViewExport='1';actions.prepend(control)}
    const options=$('.r17-export-options',control);
    candidates.forEach(button=>{
      const columnScope=button.closest('[data-report-column-key]')?.dataset.reportColumnKey||'';
      const key=[...button.attributes].filter(attribute=>attribute.name.startsWith('data-')).map(attribute=>attribute.name+'='+attribute.value).sort().join('|')+'|'+columnScope;
      $$("[data-r17-export-key]",options).filter(node=>node.dataset.r17ExportKey===key).forEach(node=>node.remove());
      button.dataset.r17ExportKey=key;button.type='button';button.textContent=exportLabel(button);options.append(button);
    });
    $$('.srp-export-group,.srp-column-export-actions,.r15-export-menu,.r16-export-menu',page).forEach(group=>{if(!$('button',group))group.remove()});
  }

  function choosePrimaryColumns(headers,cells){
    if(headers.length<=5)return new Set(headers.map((_,index)=>index));
    const firstContent=Math.max(0,cells.findIndex(cell=>!$('input[type="checkbox"],input[type="radio"]',cell)));
    const find=pattern=>headers.findIndex(label=>pattern.test(label));
    const findLast=pattern=>{for(let index=headers.length-1;index>=0;index--)if(pattern.test(headers[index]))return index;return -1};
    const ranked=[0,firstContent,find(/description|invoice|customer|vendor|transaction|account|name|reference/i),find(/date|period|due/i),findLast(moneyPattern),find(/status|state|match/i),findLast(/action|open|manage/i)];
    const chosen=new Set(ranked.filter(index=>index>=0));
    for(let index=0;chosen.size<5&&index<headers.length;index++)chosen.add(index);
    while(chosen.size>5){const removable=[...chosen].find(index=>![0,firstContent,findLast(moneyPattern),find(/status|state|match/i),findLast(/action|open|manage/i)].includes(index));if(removable===undefined)break;chosen.delete(removable)}
    return chosen;
  }

  function rowDetails(row,headers,primary){
    const cells=[...row.children].filter(cell=>cell.matches('td,th'));
    if(cells.length!==headers.length||headers.length<=5||row.dataset.r17ResponsiveRow)return;
    row.dataset.r17ResponsiveRow='1';
    const secondary=headers.map((label,index)=>({label,index,value:String(cells[index]?.textContent||'').replace(/\s+/g,' ').trim()})).filter(item=>!primary.has(item.index)&&item.value);
    if(!secondary.length)return;
    const identity=String(cells.find(cell=>!$('input',cell))?.textContent||'record').replace(/\s+/g,' ').trim().slice(0,80);
    const id=`r17-row-${Math.random().toString(36).slice(2,10)}`;
    const toggle=document.createElement('button');toggle.type='button';toggle.className='r17-detail-toggle';toggle.setAttribute('aria-expanded','false');toggle.setAttribute('aria-controls',id);toggle.textContent='Details';toggle.setAttribute('aria-label',`Show more details for ${identity}`);
    (cells.find(cell=>!$('input',cell))||cells[0]).append(toggle);
    const details=document.createElement('tr');details.className='r17-detail-row';details.id=id;details.hidden=true;
    details.innerHTML=`<td colspan="${headers.length}"><dl>${secondary.map(item=>`<div><dt>${esc(item.label||`Column ${item.index+1}`)}</dt><dd>${esc(item.value)}</dd></div>`).join('')}</dl></td>`;
    row.after(details);
    toggle.onclick=()=>{const open=details.hidden;details.hidden=!open;toggle.setAttribute('aria-expanded',String(open));toggle.textContent=open?'Hide details':'Details'};
  }

  function enhanceTable(wrap){
    if(!visible(wrap))return;
    const table=$('table',wrap);if(!table||table.hasAttribute('data-r19-authored-table'))return;
    wrap.classList.add('r17-data-region');wrap.tabIndex=0;wrap.setAttribute('role','region');
    if(!wrap.getAttribute('aria-label'))wrap.setAttribute('aria-label','Accounting records');
    if(table.closest('.tegh-recon-side')){wrap.classList.add('r17-recon-region');return}
    const headerCells=$$('thead tr:first-child > th,thead tr:first-child > td',table),headers=headerCells.map(cell=>String(cell.textContent||'').replace(/\s+/g,' ').trim());
    if(!headers.length)return;
    wrap.dataset.r17Columns=String(headers.length);
    const sample=[...table.querySelectorAll('tbody > tr')].find(row=>!row.classList.contains('r17-detail-row'));
    const primary=choosePrimaryColumns(headers,sample?[...sample.children]:[]);
    headerCells.forEach((cell,index)=>{cell.dataset.r17Priority=primary.has(index)?'primary':'secondary';if(moneyPattern.test(headers[index])||cell.matches('.srp-money,.num'))cell.classList.add('r17-money')});
    $$('tbody > tr',table).filter(row=>!row.classList.contains('r17-detail-row')).forEach(row=>{
      const cells=[...row.children].filter(cell=>cell.matches('td,th'));
      cells.forEach((cell,index)=>{
        const label=headers[index]||`Column ${index+1}`;cell.dataset.label=label;cell.dataset.r17Priority=primary.has(index)?'primary':'secondary';
        if(moneyPattern.test(label)||cell.matches('.srp-money,.num'))cell.classList.add('r17-money');
        if(/status|state|match/i.test(label))cell.classList.add('r17-status-cell');
      });
      rowDetails(row,headers,primary);
    });
    $$('tfoot td,tfoot th',table).forEach((cell,index)=>{if(!cell.dataset.label)cell.dataset.label=headers[index]||'Total';if(cell.matches('.srp-money,.num')||moneyPattern.test(cell.dataset.label))cell.classList.add('r17-money')});
  }

  function filterValue(control){
    if(control.disabled||control.type==='hidden'||['submit','button','reset','checkbox','radio'].includes(control.type))return '';
    const value=String(control.value||'').trim();if(!value)return '';
    if(control.tagName==='SELECT'&&control.selectedIndex===0)return '';
    return control.tagName==='SELECT'?String(control.selectedOptions?.[0]?.textContent||value).trim():value;
  }

  function refreshFilterChips(form){
    let chips=form.nextElementSibling?.matches('.r17-filter-chips')?form.nextElementSibling:null;
    const entries=$$('input,select',form).map(control=>({control,value:filterValue(control)})).filter(item=>item.value).map(item=>{
      const label=String(item.control.closest('label')?.childNodes?.[0]?.textContent||item.control.name||'Filter').replace(/\s+/g,' ').trim();return {...item,label};
    });
    if(!entries.length){chips?.remove();return}
    if(!chips){chips=document.createElement('div');chips.className='r17-filter-chips';chips.setAttribute('aria-label','Applied filters');form.after(chips)}
    chips.replaceChildren(...entries.map(({control,label,value})=>{
      const chip=document.createElement(control.required?'span':'button');if(!control.required)chip.type='button';chip.className='r17-filter-chip';chip.innerHTML=`<b>${esc(label)}</b><span>${esc(value)}</span>${control.required?'':'<i aria-hidden="true">×</i>'}`;
      if(!control.required){chip.setAttribute('aria-label',`Remove ${label} filter`);chip.onclick=()=>{if(control.tagName==='SELECT')control.selectedIndex=0;else control.value='';control.dispatchEvent(new Event('change',{bubbles:true}));if(form.requestSubmit)form.requestSubmit();else form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}))}}
      return chip;
    }));
  }

  function enhanceFilters(body){
    $$('.srp-register-filter,.srp-report-period,.tegh-fa-toolbar',body).forEach(form=>{
      form.classList.add('r17-filter-row');if(form.dataset.r17FilterBound||form.tagName!=='FORM')return;form.dataset.r17FilterBound='1';
      const update=()=>requestAnimationFrame(()=>refreshFilterChips(form));form.addEventListener('change',update);form.addEventListener('submit',update);refreshFilterChips(form);
    });
  }

  function enhanceBreadcrumb(page){
    const head=$('.srp-page-head',page),host=head?.firstElementChild,title=$('h1',host)?.textContent.trim(),module=page.dataset.srpModule||'';if(!host||!title||$('.r17-breadcrumb',host)||title==='Dashboard')return;
    const nav=document.createElement('nav');nav.className='r17-breadcrumb';nav.setAttribute('aria-label','Breadcrumb');
    nav.innerHTML=`<span>${esc(module||'Workspace')}</span><b aria-hidden="true">/</b><span aria-current="page">${esc(title)}</span>`;host.prepend(nav);
  }

  function enhanceReport(page){
    $$('.srp-report-period-caption',page).forEach(node=>{node.classList.add('r17-report-identity');node.setAttribute('aria-label','Report identity and period')});
    $$('tbody > tr',page).forEach(row=>{const text=String(row.cells?.[0]?.textContent||'').trim();if(/^total\b|^net (income|profit|loss)\b|^closing balance\b/i.test(text))row.classList.add('r17-report-total');else if(/^(assets|liabilities|equity|income|expenses|operating|investing|financing)\b/i.test(text)&&row.cells?.length<=3)row.classList.add('r17-report-group')});
  }

  function enhanceTabs(page){
    $$('.tegh-fa-tabs,.tegh-recon-mobile-tabs',page).forEach((tablist,listIndex)=>{
      tablist.setAttribute('role','tablist');
      $$(':scope > button',tablist).forEach((button,index)=>{
        const key=button.dataset.faTab||button.dataset.reconMobileSide||String(index),panel=button.dataset.faTab?$(`[data-fa-panel="${CSS.escape(key)}"]`,page):$(`#tegh-recon-${CSS.escape(key)}-panel`,page);
        button.setAttribute('role','tab');button.setAttribute('aria-selected',button.getAttribute('aria-pressed')||String(index===0));button.tabIndex=button.getAttribute('aria-selected')==='true'?0:-1;
        if(panel){if(!panel.id)panel.id=`r17-panel-${listIndex}-${index}`;button.setAttribute('aria-controls',panel.id);panel.setAttribute('role','tabpanel');panel.setAttribute('aria-labelledby',button.id||(button.id=`r17-tab-${listIndex}-${index}`))}
        if(!button.dataset.r17TabBound){button.dataset.r17TabBound='1';button.addEventListener('click',()=>requestAnimationFrame(()=>{$$(':scope > button',tablist).forEach((tab,tabIndex)=>{const selected=tab.getAttribute('aria-pressed')==='true'||(!tab.hasAttribute('aria-pressed')&&tabIndex===index);tab.setAttribute('aria-selected',String(selected));tab.tabIndex=selected?0:-1})}))}
      });
      if(!tablist.dataset.r17Keys){tablist.dataset.r17Keys='1';tablist.addEventListener('keydown',event=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;const tabs=$$(':scope > button:not(:disabled)',tablist),at=tabs.indexOf(document.activeElement),next=event.key==='Home'?0:event.key==='End'?tabs.length-1:(at+(event.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length;tabs[next]?.focus();tabs[next]?.click();event.preventDefault()})}
    });
  }

  function enhancePayrollSections(page){
    $$('[data-run-new],[data-run-edit]',page).forEach(form=>{
      const picker=$('[data-payrun-eligible]',form);if(picker){picker.classList.add('r17-authored-section');const legend=$('legend',picker);if(legend)legend.textContent=legend.textContent.includes('Run')?'Employees and earnings':'Employees and earnings'}
      $('.r17-form-review',form)?.setAttribute('aria-label','Review before saving');
    });
  }

  async function installCreateMenu(){
    const host=$('.topbar .top-actions');if(!host)return;
    let control=$('[data-r17-create]');if(!control){control=document.createElement('details');control.className='r17-menu r17-create-menu';control.dataset.r17Create='1';control.innerHTML=`<summary>${createIcon}<span>Create</span><span aria-hidden="true">▾</span></summary><div class="r17-menu-panel" role="menu"><p role="status">Loading available actions…</p></div>`;const anchor=$('[data-shell-assist]',host);host.insertBefore(control,anchor||host.firstChild)}
    const generation=++headerGeneration,panel=$('.r17-menu-panel',control),service=window.TeghQuickActions;if(!service?.getCatalogue)return;
    try{
      const catalogue=await service.getCatalogue();if(generation!==headerGeneration||!control.isConnected)return;
      const preferred=['Customer Invoice','Vendor Invoice','Expense','Customer','Vendor','Bank Statement','Manual Journal','Pay Run'];
      const creation=(catalogue||[]).filter(item=>/\b(create|new|add|record|import|upload)\b/i.test(item.label||'')).sort((a,b)=>{const rank=item=>{const at=preferred.findIndex(label=>String(item.label||'').includes(label));return at<0?99:at};return rank(a)-rank(b)}).slice(0,10);
      panel.innerHTML=creation.length?creation.map(item=>`<button type="button" role="menuitem" data-r17-create-action="${esc(item.action_id)}"><span><b>${esc(item.label)}</b><small>${esc(item.module||'Workspace')}</small></span></button>`).join(''):'<p>No creation actions are available for this role.</p>';
      $$('[data-r17-create-action]',panel).forEach(button=>button.onclick=()=>{control.open=false;service.open(button.dataset.r17CreateAction,{source:'global-create'})});
    }catch{if(generation===headerGeneration&&control.isConnected)panel.innerHTML='<p>Creation actions are temporarily unavailable.</p>'}
  }

  function installProfileMenu(){
    const host=$('.topbar .top-actions');if(!host)return;
    const oldProfile=$('[data-shell-profile]',host),oldSignout=$('[data-shell-signout]',host);let control=$('[data-r17-profile]',host);
    if(!control){if(!oldProfile&&!oldSignout)return;control=document.createElement('details');control.className='r17-menu r17-profile-menu';control.dataset.r17Profile='1';
      control.innerHTML=`<summary aria-label="Profile" title="Profile">${userIcon}</summary><div class="r17-menu-panel" role="menu"><button type="button" role="menuitem" data-r17-account><b>My profile</b><small>Account and access</small></button><button type="button" role="menuitem" data-r17-appearance><b>More display settings</b><small>Text size, density and alerts</small></button><button type="button" role="menuitem" data-r17-signout><b>Sign out</b></button></div>`;
      host.insertBefore(control,oldProfile||oldSignout);
    }
    oldProfile?.remove();oldSignout?.remove();
  }

  function installHeader(){const modules=$('.topbar .menu,.topbar [data-menu-toggle],.topbar [data-mobile-menu]');if(modules){modules.setAttribute('aria-label','Modules');modules.title='Modules';if(!$('.r17-modules-label',modules)){const label=document.createElement('span');label.className='r17-modules-label';label.textContent='Modules';modules.append(label)}}installProfileMenu();void installCreateMenu();window.TeghPortal?.refreshNavigation?.()}

  function apply(page){
    if(!page?.isConnected)return;currentPage=page;if(page.dataset.r22Shell==='1'){installHeader();window.TeghActivityShell?.refresh?.(page);return;}const body=$('.srp-page-body',page);if(!body)return;
    consolidateExports(page);enhanceBreadcrumb(page);enhanceFilters(body);$$(tableSelector,body).forEach(enhanceTable);enhanceReport(page);enhanceTabs(page);enhancePayrollSections(page);installHeader();page.dataset.r17Ready='1';
  }

  function refresh(page=currentPage){if(page)currentPage=page;if(frame)return;frame=requestAnimationFrame(()=>{frame=0;observer?.disconnect();apply(currentPage);if(currentPage?.isConnected&&currentPage.dataset.r22Shell!=='1')observer?.observe(currentPage,{childList:true,subtree:true})})}
  function connect(event){const page=event?.detail?.page||$('.srp-page');if(!page)return;observer?.disconnect();currentPage=page;observer=new MutationObserver(()=>refresh(page));refresh(page)}

  function positionMenu(control){if(window.TeghOverlayManager?.active===control){window.TeghOverlayManager.position(control);return}const panel=$('.r17-menu-panel',control);if(!panel)return;const trigger=$('summary',control),rect=trigger.getBoundingClientRect(),width=Math.min(Math.max(240,panel.scrollWidth),innerWidth-20),height=Math.min(panel.scrollHeight,innerHeight-20);panel.style.width=`${width}px`;panel.style.left=`${Math.max(10,Math.min(rect.right-width,innerWidth-width-10))}px`;panel.style.top=`${Math.max(10,Math.min(rect.bottom+6,innerHeight-height-10))}px`}
  document.addEventListener('toggle',event=>{const control=event.target;if(!control.matches?.('details.r17-menu'))return;const trigger=$('summary',control);trigger?.setAttribute('aria-expanded',String(control.open));if(control.open){$$('details.r17-menu[open]').forEach(other=>{if(other!==control)other.open=false});const panel=$(':scope > .r17-menu-panel',control);control.__r17Panel=panel;if(window.TeghOverlayManager)window.TeghOverlayManager.open({source:control,trigger,panel,minWidth:240,preferredWidth:control.classList.contains('r17-profile-menu')?400:control.classList.contains('r17-create-menu')?360:280});else positionMenu(control)}else window.TeghOverlayManager?.close(control,false)},true);
  document.addEventListener('click',event=>{if(window.TeghOverlayManager)return;$$('details.r17-menu[open]').forEach(control=>{if(!control.contains(event.target)||event.target.closest('.r17-menu-panel button'))control.open=false})});
  document.addEventListener('keydown',event=>{const control=event.target.closest?.('details.r17-menu')||(window.TeghOverlayManager?.active?.matches?.('details.r17-menu')?window.TeghOverlayManager.active:null);if(!control)return;if(event.key==='Escape'){control.open=false;window.TeghOverlayManager?.close(control,true);$('summary',control)?.focus();event.preventDefault()}else if(['ArrowDown','ArrowUp','Home','End'].includes(event.key)&&event.target=== $('summary',control)){control.open=true;requestAnimationFrame(()=>{const panel=control.__r17Panel||$('.r17-menu-panel',control),buttons=$$('button:not(:disabled),a[href]',panel),index=event.key==='End'||event.key==='ArrowUp'?buttons.length-1:0;buttons[index]?.focus({preventScroll:true})});event.preventDefault()}});
  window.addEventListener('resize',()=>{if(window.TeghOverlayManager?.active)window.TeghOverlayManager.position();else $$('details.r17-menu[open]').forEach(positionMenu);refresh()},{passive:true});
  window.addEventListener('tegh:page-rendered',connect);window.addEventListener('tegh:quick-actions-changed',installCreateMenu);window.addEventListener('tegh:bookkeeping-mode-changed',installCreateMenu);window.addEventListener('tegh:company-changed',installCreateMenu);document.addEventListener('tegh:report-model-ready',()=>refresh());
  window.TeghViewport={refresh};installHeader();connect();
})();
