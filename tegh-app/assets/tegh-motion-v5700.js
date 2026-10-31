/* Tegh 5.7.0 / Build 5700 — Quiet Momentum runtime. */
(()=>{
  'use strict';
  if(window.TeghMotion?.build==='5700')return;
  const d=document,root=d.documentElement;
  const reduce=matchMedia('(prefers-reduced-motion: reduce)');
  const fine=matchMedia('(hover:hover) and (pointer:fine)');
  const lowPower=(navigator.hardwareConcurrency&&navigator.hardwareConcurrency<=2)||(navigator.deviceMemory&&navigator.deviceMemory<=2);
  root.classList.add('tegh-motion-js');
  root.dataset.teghMotion='5700';
  root.dataset.teghMotionTier=(reduce.matches||lowPower)?'essential':'full';

  const timers=new WeakMap();
  const after=(node,ms,fn)=>{const prev=timers.get(node);if(prev)clearTimeout(prev);const id=setTimeout(()=>{timers.delete(node);fn?.()},ms);timers.set(node,id);return id};
  const visible=node=>node&&node.nodeType===1&&node.getClientRects().length>0;

  const pulse=(node,duration=720)=>{
    if(!node||reduce.matches)return;
    node.classList.remove('tegh-ledger-pulse');
    void node.offsetWidth;
    node.classList.add('tegh-ledger-pulse');
    after(node,duration,()=>node.classList.remove('tegh-ledger-pulse'));
  };
  const number=(el,from,to,{duration=300,format}={})=>{
    if(!el)return;
    const render=typeof format==='function'?format:(v=>String(Math.round(v)));
    if(reduce.matches){el.textContent=render(to);return}
    const start=performance.now();
    const tick=now=>{
      const p=Math.min(1,(now-start)/duration),e=1-Math.pow(1-p,3);
      el.textContent=render(from+(to-from)*e);
      if(p<1)requestAnimationFrame(tick);else{el.classList.remove('tegh-number-change');void el.offsetWidth;el.classList.add('tegh-number-change');after(el,320,()=>el.classList.remove('tegh-number-change'))}
    };
    requestAnimationFrame(tick);
  };
  const heroLedger=(field)=>{
    if(!field||reduce.matches)return;
    field.classList.remove('tegh-hero-ledger-pulse');void field.offsetWidth;field.classList.add('tegh-hero-ledger-pulse');after(field,780,()=>field.classList.remove('tegh-hero-ledger-pulse'));
  };

  let revealObserver=null;
  const installReveal=scope=>{
    const nodes=[...(scope?.matches?.('.reveal:not(.visible)')?[scope]:[]),...(scope?.querySelectorAll?.('.reveal:not(.visible)')||[])];
    if(!nodes.length)return;
    if(reduce.matches||!('IntersectionObserver'in window)){nodes.forEach(n=>n.classList.add('visible'));return}
    if(!revealObserver)revealObserver=new IntersectionObserver(entries=>{
      entries.forEach(entry=>{if(entry.isIntersecting){entry.target.classList.add('visible');revealObserver.unobserve(entry.target)}})
    },{threshold:.16,rootMargin:'0px 0px -8% 0px'});
    nodes.forEach(n=>revealObserver.observe(n));
  };

  const markLoading=scope=>{
    const nodes=[...(scope?.matches?.('.srp-empty')?[scope]:[]),...(scope?.querySelectorAll?.('.srp-empty')||[])];
    nodes.forEach(n=>{if(/^loading(?:…|\.\.\.)?/i.test((n.textContent||'').trim()))n.classList.add('tegh-motion-loading')});
  };
  const settlePage=node=>{
    if(!node||node.dataset.teghMotionPage==='1')return;
    // ActivityShell owns long-lived, nested report scroll regions. Animating
    // their routed page ancestor with transform leaves Chromium's compositor
    // pointing wheel, track and thumb input at the page that was just removed.
    // Keyboard/programmatic scrolling still works in that state, which makes
    // the failure easy to mistake for a sizing problem. Keep routed
    // ActivityShell pages in the normal hit-test tree from their first frame;
    // component and status motion remains available elsewhere in the app.
    if(root.dataset.teghActivityShell==='r22'){
      node.dataset.teghMotionPage='1';
      node.classList.remove('tegh-motion-page-in');
      node.classList.add('tegh-motion-settled');
      return;
    }
    node.dataset.teghMotionPage='1';node.classList.add('tegh-motion-page-in');
    after(node,360,()=>{node.classList.remove('tegh-motion-page-in');node.classList.add('tegh-motion-settled')});
  };
  const enhance=scope=>{
    if(!scope||scope.nodeType!==1)return;
    installReveal(scope);markLoading(scope);
    if(scope.matches?.('.srp-page'))settlePage(scope);
    scope.querySelectorAll?.('.srp-page:not([data-tegh-motion-page])').forEach(settlePage);
    const modals=[...(scope.matches?.('.srp-modal')?[scope]:[]),...(scope.querySelectorAll?.('.srp-modal')||[])];
    modals.forEach(m=>{m.classList.add('tegh-motion-modal-in');after(m,320,()=>m.classList.remove('tegh-motion-modal-in'))});
    const panels=[...(scope.matches?.('.srt-panel,.sr-tegh-panel,.tegh-ai-panel')?[scope]:[]),...(scope.querySelectorAll?.('.srt-panel,.sr-tegh-panel,.tegh-ai-panel')||[])];
    panels.forEach(p=>{if(p.dataset.teghMotionPanel)return;p.dataset.teghMotionPanel='1';p.classList.add('tegh-motion-panel-in');after(p,300,()=>p.classList.remove('tegh-motion-panel-in'))});
    const toasts=[...(scope.matches?.('.srp-toast')?[scope]:[]),...(scope.querySelectorAll?.('.srp-toast')||[])];
    toasts.forEach(t=>{if(t.dataset.teghMotionToast)return;t.dataset.teghMotionToast='1'});
  };

  d.addEventListener('pointerdown',e=>{const b=e.target.closest?.('button,.button,[role="button"]');if(b&&!b.disabled)b.classList.add('tegh-pressed')},{passive:true});
  const release=e=>{const b=e.target.closest?.('button,.button,[role="button"]');if(b){after(b,70,()=>b.classList.remove('tegh-pressed'))}};
  d.addEventListener('pointerup',release,{passive:true});d.addEventListener('pointercancel',release,{passive:true});
  d.addEventListener('keydown',e=>{if((e.key==='Enter'||e.key===' ')&&e.target.matches?.('button,.button,[role="button"]'))e.target.classList.add('tegh-pressed')});
  d.addEventListener('keyup',e=>{if(e.target.classList?.contains('tegh-pressed'))e.target.classList.remove('tegh-pressed')});
  let lastCommitPulse=0;
  addEventListener('tegh:accounting-commit',()=>{
    const now=performance.now();if(now-lastCommitPulse<500)return;lastCommitPulse=now;
    const page=[...d.querySelectorAll('.srp-page')].reverse().find(visible),target=page?.querySelector('.srp-page-head')||page||d.querySelector('.stage');
    pulse(target,720);
  });

  const init=()=>{
    installReveal(d);markLoading(d);
    d.querySelectorAll('.srp-page').forEach(settlePage);
    window.addEventListener('tegh:enhance-subtree',event=>enhance(event.detail?.root||d.body));
    window.addEventListener('tegh:page-rendered',event=>enhance(event.detail?.page||d.body));
    requestAnimationFrame(()=>requestAnimationFrame(()=>d.body.classList.add('tegh-motion-ready')));
  };
  reduce.addEventListener?.('change',()=>{root.dataset.teghMotionTier=(reduce.matches||lowPower)?'essential':'full';if(reduce.matches)d.querySelectorAll('.reveal').forEach(n=>n.classList.add('visible'))});
  if(d.readyState==='loading')d.addEventListener('DOMContentLoaded',init,{once:true});else init();

  window.TeghMotion={build:'5700',reduceMotion:()=>reduce.matches,finePointer:()=>fine.matches,pulse,number,heroLedger,reveal:installReveal,enhance};
})();
