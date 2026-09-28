(()=>{
  'use strict';
  const scope=()=>localStorage.getItem('sr-accountax-company')||'';
  const endpoint=(id='')=>'/api/index.php?route=requested-downloads'+(id?'&id='+encodeURIComponent(id):'');
  const cleanName=value=>String(value||'Tegh-report').replace(/[- ]?TVO-[A-F0-9]+/gi,'').replace(/[^a-z0-9._ -]/gi,'-').slice(0,180);
  const notices=new Set();
  function clear(){notices.forEach(item=>item.close());notices.clear()}
  ['tegh:invalidate-scope','tegh:scope-invalidated','tegh:company-scope-invalidated'].forEach(name=>window.addEventListener(name,clear));
  window.addEventListener('storage',e=>{if(e.key===null||['sr-accountax-company','sr-accountax-companies'].includes(e.key))clear()});
  function offer(blob,filename){
    const url=URL.createObjectURL(blob),item=document.createElement('div');item.className='tegh-output-notice success tegh-download-notice';item.setAttribute('role','status');
    let region=document.querySelector('#tegh-output-live');if(!region){region=document.createElement('div');region.id='tegh-output-live';region.className='tegh-output-live';document.body.append(region)}
    const title=document.createElement('b');title.textContent='Download ready';const caption=document.createElement('span');caption.textContent=filename;
    const link=document.createElement('a');link.href=url;link.download=filename;link.textContent='Save file';link.className='tegh-download-link';
    const button=document.createElement('button');button.type='button';button.textContent='×';button.className='r15-download-close';button.setAttribute('aria-label','Dismiss download notification');
    const status=document.createElement('small');item.append(title,caption,link,button,status);region.append(item);
    // R119: notices used to stay two minutes each and stack over the report's
    // Actions menu, so the next export could not be started (worst on phones).
    // Keep at most two, and close each after 20s; the saved copy stays in
    // Settings → Requested Downloads.
    const record={close:()=>{item.remove();URL.revokeObjectURL(url);notices.delete(record)}};notices.add(record);button.onclick=record.close;link.click();
    [...notices].slice(0,-2).forEach(old=>old.close());setTimeout(record.close,20000);return status;
  }
  async function save(blob,name){
    if(!(blob instanceof Blob)||!blob.size)throw Error('The generated file is empty.');
    const filename=cleanName(name),company=scope(),status=offer(blob,filename);
    status.textContent='Saving to Requested Downloads…';
    try{
      const authResponse=await fetch('/api/index.php?route=auth/me',{credentials:'same-origin',cache:'no-store'});const session=await authResponse.json();if(!authResponse.ok)throw Error('Sign in again to save this download.');
      if(scope()!==company){clear();return}
      const form=new FormData();form.append('file',blob,filename);
      const response=await fetch(endpoint(),{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'X-CSRF-Token':session.csrfToken||'','X-Company-Id':company},body:form});
      const result=await response.json();if(!response.ok)throw Error(result.error||'The saved copy is unavailable.');
      if(scope()===company)status.textContent='Saved in Settings → Requested Downloads';
    }catch(error){if(scope()===company)status.textContent='Save this file now. It was not archived: '+error.message;}
    return {filename,size:blob.size};
  }
  async function retrieve(id){
    const company=scope();try{const response=await fetch(endpoint(id),{credentials:'same-origin',cache:'no-store',headers:{'X-Company-Id':company}});if(!response.ok){const result=await response.json();throw Error(result.error||'Download unavailable.')}const blob=await response.blob();if(scope()!==company)return;const name=response.headers.get('Content-Disposition')?.match(/filename="([^"]+)"/)?.[1]||'Tegh-report';offer(blob,cleanName(name));}catch(error){alert(error.message)}
  }
  window.TeghDownloads={save,retrieve,cleanName};
})();
