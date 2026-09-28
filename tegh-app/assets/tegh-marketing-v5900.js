// Tegh 5.9.0 / Build 5900 — marketing interaction repair.
(() => {
  'use strict';
  document.querySelectorAll('link[data-tegh-deferred-css]').forEach(link=>{
    const enable=()=>{link.media='all'};
    if(link.sheet)enable();else link.addEventListener('load',enable,{once:true});
  });
  const $=(s,c=document)=>c.querySelector(s), $$=(s,c=document)=>[...c.querySelectorAll(s)];
  const header=$('.site-header'), mobile=$('.mobile-nav'), toggle=$('.mobile-toggle');
  let mobileReturnFocus=null;
  const backgroundTargets=()=>[$('main.page'),$('.site-footer')].filter(Boolean);
  const focusables=()=>[toggle,...$$('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])',mobile)].filter((el,i,a)=>el&&a.indexOf(el)===i&&el.offsetParent!==null);
  const closeMenus=()=>{$$('.nav-item.open').forEach(n=>{n.classList.remove('open');n.querySelector(':scope > .nav-trigger')?.setAttribute('aria-expanded','false')});header?.classList.remove('menu-active')};
  $$('.nav-trigger').forEach((btn,index)=>{
    const item=btn.closest('.nav-item'); const menu=$('.mega-menu',item); if(menu&&!menu.id)menu.id=`mega-menu-${index+1}`; if(menu)btn.setAttribute('aria-controls',menu.id);
    btn.addEventListener('click',e=>{e.stopPropagation();const open=!item.classList.contains('open');closeMenus();if(open){item.classList.add('open');header?.classList.add('menu-active');btn.setAttribute('aria-expanded','true')}});
    btn.addEventListener('keydown',e=>{if(e.key==='Escape'){closeMenus();btn.focus()}});
  });
  document.addEventListener('click',e=>{if(!e.target.closest('.nav-item'))closeMenus()});
  const setMobile=(open,{restore=true}={})=>{
    if(!mobile||!toggle)return;
    if(open)mobileReturnFocus=document.activeElement instanceof HTMLElement?document.activeElement:toggle;
    document.body.classList.toggle('nav-open',open); mobile.classList.toggle('open',open); mobile.setAttribute('aria-hidden',String(!open)); toggle.setAttribute('aria-expanded',String(open)); toggle.setAttribute('aria-label',open?'Close menu':'Open menu');
    backgroundTargets().forEach(el=>{if(open)el.setAttribute('inert','');else el.removeAttribute('inert')});
    if(open){requestAnimationFrame(()=>mobile.querySelector('a[href],button:not([disabled])')?.focus())}else if(restore){(mobileReturnFocus||toggle).focus();mobileReturnFocus=null}
  };
  toggle?.setAttribute('aria-controls','site-mobile-nav'); if(mobile){mobile.id='site-mobile-nav';mobile.setAttribute('aria-hidden',document.body.classList.contains('nav-open')?'false':'true')}
  toggle?.addEventListener('click',()=>setMobile(!document.body.classList.contains('nav-open')));
  $$('.mobile-nav a').forEach(a=>a.addEventListener('click',()=>setMobile(false,{restore:false})));
  document.addEventListener('keydown',e=>{
    if(e.key==='Escape'){if(document.body.classList.contains('nav-open')){e.preventDefault();setMobile(false);return}closeMenus();return}
    if(e.key==='Tab'&&document.body.classList.contains('nav-open')){const list=focusables();if(!list.length)return;const first=list[0],last=list[list.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus()}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus()}}
  });
  addEventListener('resize',()=>{if(innerWidth>1040&&document.body.classList.contains('nav-open'))setMobile(false,{restore:false})},{passive:true});
  const onScroll=()=>header?.classList.toggle('scrolled',window.scrollY>12);onScroll();addEventListener('scroll',onScroll,{passive:true});
  window.TeghMotion?.reveal?.(document);
  $$('.solution-tab').forEach(btn=>btn.addEventListener('click',()=>{const root=btn.closest('[data-solution-tabs]');if(!root)return;$$('.solution-tab',root).forEach(x=>x.classList.toggle('active',x===btn));$$('.solution-panel',root).forEach(p=>p.hidden=p.dataset.panel!==btn.dataset.tab)}));
  $$('.report-tab').forEach(btn=>btn.addEventListener('click',()=>{$$('.report-tab').forEach(x=>x.classList.toggle('active',x===btn));const title=$('[data-report-title]'),period=$('[data-report-period]');if(!title)return;const name=btn.dataset.report||'Profit & Loss';title.textContent=name;if(period)period.textContent=name==='Balance Sheet'?'As of August 31, 2026':'August 1–31, 2026'}));

  const allowedEvents=new Set(['join_beta_click','signup_started','request_demo_view','request_demo_started','request_demo_success','ask_tegh_demo_click','pricing_view','migration_view']);
  const track=(event)=>{if(!allowedEvents.has(event))return;try{fetch('/api/index.php?route=marketing/event',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',keepalive:true,body:JSON.stringify({event})}).catch(()=>{})}catch(_){}};
  window.TeghMarketing={track};
  $$('a[href*="register=1"]').forEach(a=>a.addEventListener('click',()=>{track('join_beta_click');track('signup_started')}));
  $$('a[href="/ask-tegh.html"],a[href="#ask"]').forEach(a=>a.addEventListener('click',()=>track('ask_tegh_demo_click')));
  const path=location.pathname.replace(/\/+$/,'')||'/'; if(path==='/subscriptions.html')track('pricing_view'); if(path==='/migration.html')track('migration_view'); if(path==='/contact.html')track('request_demo_view');

  const form=$('[data-marketing-contact]');
  if(form){
    const status=$('[data-form-status]',form), submit=$('button[type="submit"]',form), retry=$('[data-form-token-retry]',form); let token=null, tokenPromise=null, started=false;
    const setStatus=(msg,type='')=>{if(!status)return;status.textContent=msg;status.className='form-status'+(type?' '+type:'');status.hidden=false;status.setAttribute('role',type==='error'?'alert':'status');status.setAttribute('aria-live',type==='error'?'assertive':'polite')};
    const loadToken=async({announceFailure=true}={})=>{token=null;if(retry)retry.hidden=true;try{const r=await fetch('/api/index.php?route=marketing/contact-token',{credentials:'same-origin',cache:'no-store'});if(!r.ok)throw new Error();token=await r.json();if(status&&!status.hidden&&status.dataset.tokenError==='1'){status.hidden=true;delete status.dataset.tokenError}return true}catch(_){if(announceFailure){setStatus('The secure form could not initialize. Check your connection and try again.','error');if(status)status.dataset.tokenError='1';if(retry)retry.hidden=false}return false}};
    const ensureToken=(options={})=>{if(token)return Promise.resolve(true);if(!tokenPromise)tokenPromise=loadToken(options).finally(()=>{tokenPromise=null});return tokenPromise};
    const primeToken=()=>ensureToken({announceFailure:false});
    form.addEventListener('focusin',primeToken,{once:true});
    if('IntersectionObserver'in window){const observer=new IntersectionObserver(entries=>{if(entries.some(entry=>entry.isIntersecting)){observer.disconnect();primeToken()}},{rootMargin:'180px'});observer.observe(form)}else{setTimeout(primeToken,1200)}
    retry?.addEventListener('click',()=>ensureToken());
    form.addEventListener('input',()=>{if(!started){started=true;track('request_demo_started')}},{once:true});
    form.addEventListener('submit',async e=>{e.preventDefault();if(!form.reportValidity())return;if(!token&&!(await ensureToken())){setStatus('The secure form is not ready yet. Use Retry and then submit again.','error');if(retry)retry.hidden=false;return}submit.disabled=true;setStatus('Sending your request…');const fd=new FormData(form),body=Object.fromEntries(fd.entries());Object.assign(body,token);try{const r=await fetch('/api/index.php?route=marketing/contact',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify(body)}),data=await r.json().catch(()=>({}));if(!r.ok)throw new Error(data.error||'The request could not be sent.');form.reset();track('request_demo_success');setStatus('Thanks — we received your request. We’ll use the contact details you provided to respond.','success');await loadToken({announceFailure:false})}catch(err){setStatus(err.message||'The request could not be sent. Please try again.','error');await loadToken({announceFailure:false})}finally{submit.disabled=false}});
  }
})();

(() => {
  'use strict';
  const hero=document.querySelector('[data-signature-hero]');
  const field=document.querySelector('[data-financial-field]');
  const core=document.querySelector('[data-financial-core]');
  if(!hero||!field||!core)return;
  const reduce=matchMedia('(prefers-reduced-motion: reduce)').matches;
  const fine=matchMedia('(hover:hover) and (pointer:fine)').matches;
  const clamp=(n,min=0,max=1)=>Math.max(min,Math.min(max,n));
  let demoFlow='';

  requestAnimationFrame(()=>requestAnimationFrame(()=>document.body.classList.add('hero-loaded')));

  const setFlow=(flow='')=>{field.dataset.activeFlow=flow};
  field.querySelectorAll('[data-flow]').forEach(el=>{
    const flow=el.dataset.flow||'';
    el.addEventListener('mouseenter',()=>setFlow(flow));
    el.addEventListener('mouseleave',()=>setFlow(demoFlow));
    el.addEventListener('focus',()=>setFlow(flow));
    el.addEventListener('blur',()=>setFlow(demoFlow));
  });

  if(fine&&!reduce){
    let pointerFrame=0,clientX=0,clientY=0;
    const updatePointer=()=>{
      pointerFrame=0;
      const r=field.getBoundingClientRect();
      const x=clamp((clientX-r.left)/r.width,-1,2)-.5;
      const y=clamp((clientY-r.top)/r.height,-1,2)-.5;
      hero.style.setProperty('--hero-rotate-y',`${(x*3.6).toFixed(2)}deg`);
      hero.style.setProperty('--hero-rotate-x',`${(-y*2.7).toFixed(2)}deg`);
    };
    field.addEventListener('pointermove',e=>{
      clientX=e.clientX;clientY=e.clientY;
      if(!pointerFrame)pointerFrame=requestAnimationFrame(updatePointer);
    });
    field.addEventListener('pointerleave',()=>{
      if(pointerFrame){cancelAnimationFrame(pointerFrame);pointerFrame=0}
      hero.style.setProperty('--hero-rotate-y','0deg');
      hero.style.setProperty('--hero-rotate-x','0deg');
    });
  }

  const money=new Intl.NumberFormat('en-CA',{style:'currency',currency:'CAD',maximumFractionDigits:0});
  const animateMoney=(el,from,to,duration=650)=>{
    if(window.TeghMotion?.number){window.TeghMotion.number(el,from,to,{duration:Math.min(duration,320),format:v=>money.format(Math.round(v))});return}
    if(!el)return;el.textContent=money.format(to);
  };

  const runDepositDemo=()=>{
    demoFlow='deposit';setFlow('deposit');
    field.classList.add('deposit-resolved');
    window.TeghMotion?.heroLedger?.(field);
    animateMoney(field.querySelector('[data-cash-value]'),126840,129290,720);
    animateMoney(field.querySelector('[data-ar-value]'),48620,46170,720);
  };
  if(reduce)runDepositDemo(); else setTimeout(runDepositDemo,2850);

  let ticking=false;
  const updateScroll=()=>{
    ticking=false;
    if(innerWidth<=1040||reduce){
      hero.classList.remove('hero-scrolling');
      hero.style.setProperty('--hero-back-x','54px');hero.style.setProperty('--hero-back-y','-44px');hero.style.setProperty('--hero-back-z','-92px');
      hero.style.setProperty('--hero-mid-x','27px');hero.style.setProperty('--hero-mid-y','-20px');hero.style.setProperty('--hero-mid-z','-42px');
      hero.style.setProperty('--hero-scale','1');hero.style.setProperty('--hero-lift','0px');
      return;
    }
    const rect=hero.getBoundingClientRect();
    const start=78;
    const travel=Math.max(180,hero.offsetHeight-innerHeight+start);
    const p=clamp((start-rect.top)/travel);
    hero.classList.toggle('hero-scrolling',p>.07&&p<.98);
    hero.style.setProperty('--hero-back-x',`${(54*(1-p)).toFixed(1)}px`);
    hero.style.setProperty('--hero-back-y',`${(-44*(1-p)).toFixed(1)}px`);
    hero.style.setProperty('--hero-back-z',`${(-92*(1-p)).toFixed(1)}px`);
    hero.style.setProperty('--hero-mid-x',`${(27*(1-p)).toFixed(1)}px`);
    hero.style.setProperty('--hero-mid-y',`${(-20*(1-p)).toFixed(1)}px`);
    hero.style.setProperty('--hero-mid-z',`${(-42*(1-p)).toFixed(1)}px`);
    hero.style.setProperty('--hero-scale',(1-p*.035).toFixed(4));
    hero.style.setProperty('--hero-lift',`${(-18*p).toFixed(1)}px`);
  };
  const queueScroll=()=>{if(!ticking){ticking=true;requestAnimationFrame(updateScroll)}};
  addEventListener('scroll',queueScroll,{passive:true});
  addEventListener('resize',queueScroll,{passive:true});
  updateScroll();
})();

// Tegh 4.1.4 agency-built operations stage: one product canvas, three financial contexts.
(() => {
  'use strict';
  document.querySelectorAll('[data-ops-stage]').forEach(stage => {
    const tabs=[...stage.querySelectorAll('[data-ops-tab]')];
    const panels=[...stage.querySelectorAll('[data-ops-panel]')];
    const activate=(name,focus=false)=>{
      tabs.forEach(btn=>{const on=btn.dataset.opsTab===name;btn.classList.toggle('active',on);btn.setAttribute('aria-selected',String(on));if(on&&focus)btn.focus()});
      panels.forEach(panel=>panel.hidden=panel.dataset.opsPanel!==name);
    };
    tabs.forEach((btn,index)=>{
      btn.addEventListener('click',()=>activate(btn.dataset.opsTab));
      btn.addEventListener('keydown',e=>{
        if(!['ArrowLeft','ArrowRight'].includes(e.key))return;
        e.preventDefault();
        const delta=e.key==='ArrowRight'?1:-1;
        const next=(index+delta+tabs.length)%tabs.length;
        activate(tabs[next].dataset.opsTab,true);
      });
    });
  });
})();

// Keep disclosure semantics synchronized with the shared desktop navigation.
(() => {
  'use strict';
  const sync=()=>document.querySelectorAll('.nav-item').forEach(item=>{
    const btn=item.querySelector(':scope > .nav-trigger');
    if(btn)btn.setAttribute('aria-expanded',String(item.classList.contains('open')));
  });
  document.querySelectorAll('.nav-trigger').forEach(btn=>btn.addEventListener('click',()=>queueMicrotask(sync)));
  document.addEventListener('keydown',e=>{if(e.key==='Escape')queueMicrotask(sync)});
  document.addEventListener('click',()=>queueMicrotask(sync));
  sync();
})();
