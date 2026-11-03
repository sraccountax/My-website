/* Tegh 5.7.0 signature experience layer — visual semantics only; motion is owned by TeghMotion 5700. */
(()=>{
  'use strict';
  const d=document,root=d.documentElement;
  root.dataset.teghDesign='living-ledger';
  root.dataset.teghExperienceBuild='5700';
  const enhance=(scope=d)=>{
    scope.querySelectorAll?.('.srp-page:not([data-tegh-premium]),.srp-modal:not([data-tegh-premium])').forEach(node=>{node.dataset.teghPremium='1'});
    scope.querySelectorAll?.('.srp-money,td[class*="money"],.srp-kpi b,.srp-sites-stat-card strong').forEach(node=>{node.style.fontVariantNumeric='tabular-nums'});
    scope.querySelectorAll?.('.srp-table:not([data-tegh-table])').forEach(table=>{table.dataset.teghTable='1';table.setAttribute('role','table');const wrap=table.closest('.srp-table-wrap');if(wrap&&!wrap.hasAttribute('tabindex'))wrap.tabIndex=0});
    window.TeghMotion?.enhance?.(scope);
  };
  const start=()=>{enhance();d.body.classList.add('tegh-premium-ready');window.addEventListener('tegh:enhance-subtree',event=>enhance(event.detail?.root||d));window.addEventListener('tegh:page-rendered',event=>enhance(event.detail?.page||d))};
  if(d.readyState==='loading')d.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
