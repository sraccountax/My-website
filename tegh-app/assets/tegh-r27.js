/* Tegh R27 presentation stability layer.
 * Accounting calculations, permissions and posting remain with their existing
 * authoritative owners. This module only enhances rendered interface nodes.
 */
(() => {
  'use strict';
  if (window.TeghR27) return;
  const $=(selector,root=document)=>root.querySelector(selector),$$=(selector,root=document)=>[...root.querySelectorAll(selector)];
  const reportRoutes=new Set(['day-book','financial-profit-loss','financial-balance-sheet','financial-trial-balance','cash-flow','cash-forecast','reports-centre']);
  const routineForms='form[data-customer-form],form[data-vendor-form],form[data-bill-form],form[data-employee-form],form[data-invoice-form],form[data-customer-invoice-form],form[data-expense-form],form[data-payment-form],form[data-bank-account-form],form#srp-product-form,form[data-product-form]';

  function accessibleSearch(root){
    $$('label',root).forEach(label=>{const input=$('input[type="search"]',label);if(!input)return;if(!input.getAttribute('aria-label'))input.setAttribute('aria-label',input.placeholder||'Search');for(const node of [...label.childNodes]){if(node===input||node.nodeType!==Node.TEXT_NODE)continue;const text=node.textContent.trim();if(/^(search|find|find a report|find a setting|description)$/i.test(text)){const span=document.createElement('span');span.className='r27-sr-only';span.textContent=text;node.replaceWith(span);}}$$(':scope > span',label).forEach(span=>{if(/^(search|find|find a report|find a setting|description)$/i.test(span.textContent.trim()))span.classList.add('r27-sr-only')});});
  }

  function formState(label,index){
    const control=$('input:not([type="hidden"]),select,textarea',label);if(!control)return;
    label.classList.add('r27-field');
    let number=$(':scope > .r27-field-number',label);if(!number){number=document.createElement('span');number.className='r27-field-number';number.setAttribute('aria-hidden','true');label.prepend(number)}number.textContent=String(index).padStart(2,'0');
    const required=control.required||control.getAttribute('aria-required')==='true';if(required&&!$(':scope > .r27-required',label)){const marker=document.createElement('span');marker.className='r27-required';marker.textContent='Required';label.insertBefore(marker,control)}
    let state=$(':scope > .r27-field-state',label);if(required&&!state){state=document.createElement('span');state.className='r27-field-state';state.setAttribute('aria-live','polite');label.insertBefore(state,control)}
    const update=()=>{if(!state)return;const complete=control.type==='checkbox'||control.type==='radio'?control.checked:String(control.value||'').trim()!=='';state.textContent=complete?'✓ Complete':'Pending';state.dataset.state=complete?'complete':'pending'};
    if(!control.dataset.r27State){control.dataset.r27State='1';control.addEventListener('input',update);control.addEventListener('change',update)}update();
  }
  function compactForms(root){$$(routineForms,root).forEach(form=>{form.classList.add('r27-task-form');if(form.dataset.teghFormLayout!=='compact'){let index=0;$$('label',form).forEach(label=>{if(label.closest('fieldset')&&label.querySelector('input[type="radio"],input[type="checkbox"]'))return;const control=$('input:not([type="hidden"]),select,textarea',label);if(control)formState(label,++index)})}else{$$('.r27-field-number,.r27-required,.r27-field-state',form).forEach(node=>node.remove());$$('.r27-field',form).forEach(label=>label.classList.remove('r27-field'))}const actions=$('.srp-actions,.form-actions,[data-form-actions]',form);if(actions)actions.classList.add('r27-form-actions')});}

  function simplifyReports(page){
    const route=page.dataset.srpPage||'';if(!reportRoutes.has(route)&&!route.startsWith('report-')&&!route.startsWith('financial-')&&!$('.srp-report-period-caption,[data-tegh-authoritative-report]',page))return;
    $$('.srp-report-details,[data-report-provenance]',page).forEach(node=>node.remove());
    $$('.srp-note',page).forEach(node=>{const text=node.textContent.trim();if(/^(prepared from|date basis:|opening balance (means|is)|historical .* selected|each actual journal line|posted journal entries only)/i.test(text))node.remove()});
    $$('.srp-kpis',page).forEach(group=>{if(group.closest('form')||group.classList.contains('r27-totals-line'))return;group.classList.add('r27-totals-line');group.classList.remove('srp-kpis');$$('.srp-kpi',group).forEach(item=>{item.classList.add('r27-total');item.classList.remove('srp-kpi')});const card=group.closest('.srp-card');const table=$('.srp-table-wrap',card||page);if(table&&(group.compareDocumentPosition(table)&Node.DOCUMENT_POSITION_FOLLOWING))table.after(group)});
    $$('.srp-actions,.srp-report-toolbar,.tegh-page-head-actions',page).forEach(toolbar=>{if(toolbar.dataset.r27Export)return;const actions=$$('button',toolbar).filter(button=>/^(export csv|export excel|download excel|download pdf|print)$/i.test(button.textContent.trim()));if(actions.length<2)return;const menu=document.createElement('details');menu.className='r27-export-menu';menu.innerHTML='<summary aria-label="Export report">⇩ Export</summary><div role="menu"></div>';const panel=$('div',menu);actions.forEach(action=>{action.setAttribute('role','menuitem');panel.append(action)});toolbar.append(menu);toolbar.dataset.r27Export='1'});
  }

  function stableScroll(root){
    $$('.srp-table-wrap,.r23-scroll,.tegh-recon-table-scroll,.sra-table-wrap,[role="dialog"] .srp-modal-body',root).forEach(node=>{node.classList.add('r27-native-scroll');if(!node.hasAttribute('tabindex'))node.tabIndex=0});
    $$('table thead',root).forEach(head=>head.classList.add('r27-sticky-head'));
  }

  function enhance(root=document){const page=root.matches?.('.srp-page')?root:root.closest?.('.srp-page')||$('.srp-page',root)||root;accessibleSearch(page);compactForms(page);simplifyReports(page);stableScroll(page);}
  window.addEventListener('tegh:page-rendered',event=>enhance(event.detail?.page||document));
  window.addEventListener('tegh:interface-preferences-ready',()=>enhance(document));
  document.addEventListener('DOMContentLoaded',()=>enhance(document),{once:true});
  window.TeghR27=Object.freeze({enhance});
})();
