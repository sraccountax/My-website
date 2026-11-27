/* R18 premium SaaS workspace: fixed viewport, internal data scrolling and one export control. */
(() => {
  'use strict';

  const $=(selector,root=document)=>root.querySelector(selector);
  const $$=(selector,root=document)=>[...root.querySelectorAll(selector)];
  const esc=value=>String(value??'').replace(/[&<>'"]/g,character=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[character]));
  const exportIcon='<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v12m-4-4 4 4 4-4M5 15v5h14v-5"/></svg>';
  const tableSelector='.srp-table-wrap,.sra-table-wrap,.srctl-table-wrap,.tegh-responsive-report,.tegh-recon-table-scroll';
  const exportSelector='[data-export],[data-csv],[data-print],[data-export-csv],[data-export-xls],[data-export-pdf],[data-export-visible],[data-export-all],[data-export-side],[data-bank-export],[data-gl-ledger-csv],[data-gl-ledger-print],[data-inventory-csv],[data-inventory-print],[data-quick-pdf],[data-forecast-csv],[data-fa-forecast-csv],[data-forecast-export]';
  const filterSelector='.srp-register-filter,.srp-report-period,.srp-filter,.tegh-fa-toolbar,.tegh-recon-side-filters,[data-opening-filter],[data-coa-filter],[data-bank-ledger-filter]';
  let page=null,observer=null,frame=0;

  document.documentElement.dataset.teghLayoutRevision='r18';

  function visible(node){
    if(!node||node.hidden||node.closest('[hidden]'))return false;
    const style=getComputedStyle(node);return style.display!=='none'&&style.visibility!=='hidden';
  }

  function exportLabel(button){
    if(button.dataset.exportSide)return `${button.dataset.exportSide==='bank'?'Bank statement':'Books'} · ${button.dataset.visible?'visible filtered':'all filtered'} entries · CSV`;
    if(button.hasAttribute('data-export-visible'))return 'Visible columns · all filtered rows · CSV';
    if(button.hasAttribute('data-export-all'))return 'All authorised columns · all filtered rows · CSV';
    if(button.hasAttribute('data-export-pdf')||button.hasAttribute('data-quick-pdf'))return 'All filtered rows · PDF';
    if(button.hasAttribute('data-export-xls'))return 'All filtered rows · Excel';
    if(button.hasAttribute('data-print')||button.hasAttribute('data-gl-ledger-print')||button.hasAttribute('data-inventory-print'))return 'Current view · Print';
    if(button.hasAttribute('data-csv')||button.hasAttribute('data-export-csv')||button.hasAttribute('data-gl-ledger-csv')||button.hasAttribute('data-inventory-csv'))return 'All filtered rows · CSV';
    if(button.hasAttribute('data-fa-forecast-csv')||button.hasAttribute('data-forecast-csv')||button.hasAttribute('data-forecast-export'))return 'Current forecast · CSV';
    return String(button.textContent||'Export').replace(/\s+/g,' ').trim();
  }

  function exportKey(button){
    const data=[...button.attributes].filter(attribute=>attribute.name.startsWith('data-')).map(attribute=>`${attribute.name}=${attribute.value}`).sort().join('|');
    return `${data}|${button.closest('[data-report-column-key]')?.dataset.reportColumnKey||''}`;
  }

  function createExportMenu(){
    const control=document.createElement('details');control.className='r17-menu r17-export-menu';control.dataset.r18ViewExport='1';
    control.innerHTML=`<summary class="srp-btn secondary">${exportIcon}<span>Export</span><span aria-hidden="true">▾</span></summary><div class="r17-menu-panel r17-export-options" role="group" aria-label="Export formats"></div>`;
    return control;
  }

  function consolidateExports(current){
    const official=$('[data-tegh-output-toolbar]',current);
    if(official){
      $$(exportSelector,current).forEach(button=>{if(!button.closest('[data-tegh-output-toolbar],.tegh-invoice-output-actions,.tegh-document-output-actions,[role="dialog"],.srp-modal-scrim'))button.remove()});
      $$('[data-r17-view-export],[data-r18-view-export]',current).forEach(control=>control.remove());
      return;
    }
    const candidates=$$(exportSelector,current).filter(button=>visible(button)&&!button.closest('.r17-export-menu,.tegh-invoice-output-actions,.tegh-document-output-actions,[role="dialog"],.srp-modal-scrim'));
    const textCandidates=$$('.srp-actions button,.srp-report-toolbar button,.srp-page-head button',current).filter(button=>visible(button)&&!button.closest('.r17-export-menu,.tegh-invoice-output-actions,.tegh-document-output-actions')&&/^(?:print(?:\s*\/\s*pdf)?|download\s+(?:pdf|excel|csv)|export(?:\s+(?:pdf|excel|csv))?)$/i.test(String(button.textContent||'').trim()));
    const unique=[...new Set([...candidates,...textCandidates])];if(!unique.length)return;
    const actions=$('.tegh-page-head-actions',current);if(!actions)return;
    let control=$('[data-r17-view-export],[data-r18-view-export]',actions);
    if(!control){control=createExportMenu();actions.prepend(control)}
    const options=$('.r17-export-options',control);if(!options)return;
    unique.forEach(button=>{
      const key=exportKey(button);$$('[data-r18-export-key]',options).filter(item=>item.dataset.r18ExportKey===key).forEach(item=>item.remove());
      button.dataset.r18ExportKey=key;button.type='button';button.textContent=exportLabel(button);options.append(button);
    });
    $$('.srp-export-group,.srp-column-export-actions,.r15-export-menu,.r16-export-menu',current).forEach(group=>{if(!$('button',group))group.remove()});
  }

  function toneStatuses(current){
    $$('.srp-status,.status,.confidence,[data-status-badge]',current).forEach(node=>{
      const value=String(node.textContent||'').replace(/\s+/g,' ').trim();
      node.dataset.r18Tone=/\b(exact|matched|posted|paid|active|issued|sent|approved|reconciled|complete|available|verified)\b/i.test(value)?'success':/\b(overdue|failed|error|void|cancelled|rejected|blocked)\b/i.test(value)?'danger':/\b(unmatched|unposted|pending|draft|review|unavailable|attention|difference)\b/i.test(value)?'warning':'neutral';
    });
  }

  function markFilters(current){$$(filterSelector,current).forEach(filter=>filter.classList.add('r17-filter-row','r18-compact-filter'))}

  function tableScore(wrap){return $$('tbody>tr',wrap).filter(row=>!row.hidden).length*100+Number(wrap.scrollHeight||0)}

  function markScrollOwnership(current){
    const body=$('.srp-page-body',current);if(!body)return;
    body.classList.remove('r18-table-page','r18-workspace-scroll');
    $$('.r18-primary-card,.r18-flex-path',body).forEach(node=>node.classList.remove('r18-primary-card','r18-flex-path'));
    const wraps=$$(tableSelector,body).filter(wrap=>visible(wrap)&&$('table',wrap));
    wraps.forEach(wrap=>{wrap.classList.add('r18-table-scroll');wrap.setAttribute('role','region');if(!wrap.hasAttribute('tabindex'))wrap.tabIndex=0;if(!wrap.getAttribute('aria-label'))wrap.setAttribute('aria-label','Scrollable accounting records')});
    if(body.classList.contains('r17-dashboard')){body.classList.add('r18-dashboard-fixed');return}
    if(body.classList.contains('r15-recon')){body.classList.add('r18-table-page');return}
    const opening=$('.r18-opening-card',body);if(opening){body.classList.add('r18-table-page');opening.classList.add('r18-primary-card');return}
    if(!wraps.length){body.classList.add('r18-workspace-scroll');return}
    const cards=[...new Set(wraps.map(wrap=>wrap.closest('.srp-card')).filter(Boolean))];
    if(cards.length!==1){body.classList.add('r18-workspace-scroll');return}
    const primary=wraps.sort((a,b)=>tableScore(b)-tableScore(a))[0],card=primary.closest('.srp-card');
    if(!card){body.classList.add('r18-workspace-scroll');return}
    body.classList.add('r18-table-page');card.classList.add('r18-primary-card');
    let node=card.parentElement;while(node&&node!==body){node.classList.add('r18-flex-path');node=node.parentElement}
  }

  function polishDashboard(current){
    const dashboard=$('.r17-dashboard',current);if(!dashboard)return;
    const actions=$('.r17-dashboard-actions',dashboard);if(!actions)return;
    const title=$('h2',actions),eyebrow=$('header small',actions);if(title)title.textContent='Quick Actions';if(eyebrow)eyebrow.textContent='Up to 10 shortcuts';
  }

  function apply(current){
    if(!current?.isConnected)return;page=current;if(current.dataset.r22Shell==='1')return;
    markFilters(current);markScrollOwnership(current);consolidateExports(current);toneStatuses(current);polishDashboard(current);
    current.dataset.r18Ready='1';
  }

  function refresh(current=page){
    if(current)page=current;if(frame)return;
    frame=requestAnimationFrame(()=>{frame=0;observer?.disconnect();apply(page);if(page?.isConnected&&page.dataset.r22Shell!=='1')observer?.observe(page,{childList:true,subtree:true})});
  }

  function connect(event){
    const current=event?.detail?.page||$('.srp-page');if(!current)return;
    observer?.disconnect();page=current;observer=new MutationObserver(()=>refresh(current));refresh(current);
  }

  window.addEventListener('tegh:page-rendered',connect);
  window.addEventListener('resize',()=>refresh(),{passive:true});
  document.addEventListener('tegh:report-model-ready',()=>refresh());
  connect();
  window.TeghWorkspaceR18=Object.freeze({refresh:()=>refresh(),revision:'r18'});
})();
