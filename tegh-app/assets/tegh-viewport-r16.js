/* Viewport ownership and shared view controls. Accounting handlers retain their original nodes. */
(() => {
  'use strict';
  const $=(s,r=document)=>r.querySelector(s),$$=(s,r=document)=>[...r.querySelectorAll(s)];
  document.documentElement.dataset.teghViewport='r16';
  const icon='<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v12m-4-4 4 4 4-4M5 15v5h14v-5"/></svg>';
  const tableSelector='.srp-table-wrap,.sra-table-wrap,.srctl-table-wrap,.tegh-responsive-report';
  const exportSelector='[data-export-csv],[data-export-xls],[data-export-pdf],[data-export-visible],[data-export-all],[data-fa-forecast-csv],[data-forecast-export],[data-bank-export],[data-export-side]';
  let observer,frame=0,currentPage;
  const visible=node=>!node.hidden&&!node.closest('[hidden]')&&getComputedStyle(node).display!=='none';
  function menu(label='Export') {
    const el=document.createElement('details');el.className='r16-export-menu';
    el.innerHTML=`<summary class="srp-btn secondary">${icon}<span>${label}</span><span aria-hidden="true">▾</span></summary><div class="r16-export-options" role="group" aria-label="${label} formats"></div>`;return el;
  }
  function exports(page) {
    // Authoritative reports own one format menu; secondary table exporters are removed by that owner.
    const official=$('[data-tegh-output-toolbar]',page);
    if(official)return;
    const candidates=$$(exportSelector,page).filter(b=>!b.closest('.r16-export-menu,[role="dialog"],.srp-modal-scrim'));
    if(!candidates.length)return;
    const actions=$('.tegh-page-head-actions',page);if(!actions)return;
    let control=$('[data-r16-view-export]',actions);if(!control){control=menu();control.dataset.r16ViewExport='1';actions.prepend(control);}
    const options=$('.r16-export-options',control);
    candidates.forEach(button=>{
      // Preserve the exact handler and data attributes, including authorized visible/all column export.
      if(button.dataset.exportSide)button.textContent=`${button.dataset.exportSide==='bank'?'Bank Statement':'Books'} · ${button.dataset.visible?'visible':'all'} entries (CSV)`;
      else if(button.hasAttribute('data-export-visible'))button.textContent='CSV · visible columns';
      else if(button.hasAttribute('data-export-all'))button.textContent='CSV · all columns';
      else if(button.hasAttribute('data-fa-forecast-csv'))button.textContent='Forecast (CSV)';
      const columnScope=button.closest('[data-report-column-key]')?.dataset.reportColumnKey||'';
      const key=[...button.attributes].filter(a=>a.name.startsWith('data-')).map(a=>a.name+'='+a.value).sort().join('|')+'|'+columnScope;
      // Rerendered filters produce fresh handlers. Retire the previous scope's node instead of retaining stale exports.
      [...options.children].filter(node=>node.dataset.r16ExportKey===key).forEach(node=>node.remove());
      button.dataset.r16ExportKey=key;button.type='button';options.append(button);
    });
    $$('.srp-export-group,.srp-column-export-actions,.r15-export-menu',page).forEach(group=>{if(!$('button',group))group.remove()});
  }
  function markTable(wrap,body) {
    if(!visible(wrap))return;
    wrap.classList.add('r16-data-region');wrap.tabIndex=0;wrap.setAttribute('role','region');
    if(!wrap.getAttribute('aria-label'))wrap.setAttribute('aria-label','Scrollable accounting records');
    for(let parent=wrap.parentElement;parent&&parent!==body;parent=parent.parentElement){
      if(parent.matches('details,[role="dialog"],.srp-modal,.srp-modal-scrim,.tegh-recon-side,.tegh-recon-workspace,[data-review-split]'))break;
      if(parent.matches('.srp-grid,.srp-sites-module-sections')){parent.classList.add('r16-card-list');break;}
      parent.classList.add('r16-table-path');
    }
  }
  function sectionTabs(body) {
    // Longer workspaces expose their sections as tabs; no section is clipped or discarded.
    if(body.matches('.r16-dashboard,.r15-recon')||$('[data-fa-panel],[data-review-split]',body))return;
    const sections=[...body.children].filter(n=>n.matches('.srp-card,.srp-grid,.r15-access-people,.r15-access-history')&&!n.matches('[data-route-loading]'));
    if(sections.length<2)return;
    let nav=$(':scope > .r16-section-tabs',body);
    if(!nav){nav=document.createElement('nav');nav.className='r16-section-tabs';nav.setAttribute('aria-label','Workspace sections');body.prepend(nav);}
    const prior=nav.dataset.selected||body.closest('.srp-page')?.dataset.r16Section||'0';
    const signature=sections.map(n=>n.tagName+':'+($('h2,h3',n)?.textContent||'Section')).join('|');
    if(nav.dataset.signature===signature&&nav.children.length===sections.length)return;
    nav.dataset.signature=signature;nav.replaceChildren();
    sections.forEach((section,i)=>{
      section.classList.add('r16-workspace-section');const button=document.createElement('button');button.type='button';button.className='srp-btn secondary';
      button.textContent=$('h2,h3',section)?.textContent.trim()||`Section ${i+1}`;
      button.onclick=()=>{nav.dataset.selected=String(i);body.closest('.srp-page').dataset.r16Section=String(i);sections.forEach((s,j)=>{s.classList.toggle('r16-section-inactive',j!==i);nav.children[j].setAttribute('aria-pressed',String(j===i))});refresh(currentPage)};
      nav.append(button);
    });nav.children[Math.min(Number(prior),sections.length-1)].click();
  }
  function internalLists(body) {
    // Menu grids, settings options and form fields are internal lists, with view headings outside.
    $$('.srp-settings-menu-grid,.tegh-settings-category-grid,[data-settings-search-results],.srp-settings-grid,.tegh-settings-grid,.srp-module-grid,.srp-module-list,.tegh5300-dashboard-grid,.srp-sites-module-sections,.srp-sites-module-menu-grid,.srp-sites-workspace-options,.srp-sites-module-grid,.srp-sites-menu-grid,.tegh-fa-scenario-index>div:last-child',body).forEach(n=>n.classList.add('r16-list-region'));
    $$(':scope > .srp-grid',body).forEach(n=>n.classList.add('r16-card-list'));
    $$('.srp-sites-report-groups,.srp-option-grid,.tegh-guided-report-grid,[data-more-reports-panel],.tegh-guided-cash-list',body).forEach(n=>n.classList.add('r16-list-region'));
    const forms=$$('form.srp-form',body).filter(f=>!f.closest('[role="dialog"],.srp-modal-scrim'));
    forms.forEach(form=>{
      if(form.dataset.r16FieldList)return;
      const fields=[...form.children].filter(n=>!n.matches('.srp-actions,footer,[data-form-actions]')&&!n.querySelector('button[type="submit"]')&&!n.matches('[role="alert"],[role="status"]'));
      if(fields.length<5)return;
      form.closest('.srp-card')?.classList.add('r16-form-card');
      const list=document.createElement('div');list.className='r16-field-list';list.setAttribute('role','group');list.setAttribute('aria-label','Entry fields');form.prepend(list);fields.forEach(n=>list.append(n));form.dataset.r16FieldList='1';
    });
  }
  function apply(page) {
    if(!page?.isConnected)return;
    const body=$('.srp-page-body',page);if(!body)return;
    exports(page);sectionTabs(body);internalLists(body);
    $$(tableSelector,body).forEach(w=>{if(!w.closest('.r16-section-inactive,[role="dialog"],.srp-modal-scrim'))markTable(w,body)});
    // Fixed-height shells use only data/list scrollers; nested chrome has no scroll owner.
    body.dataset.r16Ready='1';
  }
  function refresh(page=currentPage) {if(page)currentPage=page;if(frame)return;frame=requestAnimationFrame(()=>{frame=0;observer?.disconnect();apply(currentPage);if(currentPage?.isConnected)observer?.observe(currentPage,{childList:true,subtree:true});});}
  function connect(event) {
    const page=event?.detail?.page||$('.srp-page');if(!page)return;observer?.disconnect();currentPage=page;
    observer=new MutationObserver(()=>refresh(page));refresh(page);
  }
  // Fixed-position popups escape clipped table/panel ancestors without moving their accounting buttons.
  function position(control) {
    const panel=$('.r16-export-options',control);if(!panel)return;
    const r=$('summary',control).getBoundingClientRect(),height=Math.min(panel.scrollHeight,innerHeight-20),width=Math.min(280,innerWidth-20);
    panel.style.width=`${width}px`;panel.style.left=`${Math.max(10,Math.min(r.right-width,innerWidth-width-10))}px`;
    panel.style.top=`${Math.max(10,Math.min(r.bottom+4,innerHeight-height-10))}px`;
  }
  document.addEventListener('toggle',e=>{const control=e.target;if(!control.matches?.('.r16-export-menu'))return;$('summary',control)?.setAttribute('aria-expanded',String(control.open));if(control.open){$$('.r16-export-menu[open]').forEach(other=>{if(other!==control)other.open=false});position(control)}},true);
  document.addEventListener('click',e=>{
    $$('details.r16-export-menu[open]').forEach(control=>{if(!control.contains(e.target)||e.target.closest('.r16-export-options button'))control.open=false});
  });
  document.addEventListener('keydown',e=>{
    const control=e.target.closest?.('.r16-export-menu');if(!control)return;
    if(e.key==='Escape'){control.open=false;$('summary',control).focus();e.preventDefault();}
    else if(['ArrowDown','ArrowUp','Home','End'].includes(e.key)){
      control.open=true;const buttons=$$('button:not(:disabled)',control),at=buttons.indexOf(document.activeElement),index=e.key==='Home'?0:e.key==='End'?buttons.length-1:(at+(e.key==='ArrowDown'?1:-1)+buttons.length)%buttons.length;
      buttons[index]?.focus();e.preventDefault();
    }
  });
  window.addEventListener('resize',()=>{refresh();$$('.r16-export-menu[open]').forEach(position)});
  window.addEventListener('tegh:page-rendered',connect);document.addEventListener('tegh:report-model-ready',()=>refresh());
  window.TeghViewport={refresh};connect();
})();
