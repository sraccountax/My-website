/* R156: fills the operator details on the Terms and Privacy pages from the server's configuration, and says plainly
 * when they are incomplete. The text is set with textContent only. */
(function(){
  'use strict';
  const fields=[...document.querySelectorAll('[data-operator]')];if(!fields.length)return;
  const show=(details,missing)=>{
    fields.forEach(el=>{const v=details?String(details[el.dataset.operator]??'').trim():'';el.textContent=v||'(not yet provided)';el.classList.toggle('legal-missing',!v);
      if(el.dataset.operatorLink==='mailto'&&v){const a=document.createElement('a');a.href='mailto:'+v;a.textContent=v;el.replaceChildren(a)}});
    const box=document.querySelector('[data-operator-status]');if(!box)return;
    if(missing&&missing.length){box.hidden=false;box.textContent='This notice is incomplete: the operator of this Tegh service has not yet provided '+missing.join(', ')+'. Do not create an account until these details are shown.'}
    else box.hidden=true;
  };
  fetch('/api/index.php?route=public/operator',{credentials:'same-origin',cache:'no-store'}).then(r=>r.ok?r.json():Promise.reject(r.status))
    .then(d=>show(d.operator||{},d.missing||[])).catch(()=>show(null,['the operator details (they could not be loaded)']));
})();
