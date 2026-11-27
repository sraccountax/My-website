/* R134: charts for the client viewing dashboard. The set of charts follows the
   reports the accountant shared: each shared report that has a natural picture
   adds one chart card. Plain SVG, no library. Every chart has a legend (2+
   series), a hover/focus tooltip and a table view; motion is skipped when the
   reader prefers reduced motion. */
(()=>{
  'use strict';
  const NS='http://www.w3.org/2000/svg';
  const reduced=()=>window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  const el=(tag,attrs={},parent)=>{const n=document.createElementNS(NS,tag);for(const [k,v] of Object.entries(attrs))n.setAttribute(k,String(v));if(parent)parent.append(n);return n};
  const h=(tag,cls,text)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=text;return n};
  const monthLabel=ym=>{const [y,m]=ym.split('-').map(Number);return new Intl.DateTimeFormat('en-CA',{month:'short',timeZone:'UTC'}).format(new Date(Date.UTC(y,m-1,1)))};
  const monthsBetween=(start,end)=>{const out=[];let [y,m]=start.slice(0,7).split('-').map(Number);const [ey,em]=end.slice(0,7).split('-').map(Number);while(y<ey||(y===ey&&m<=em)){out.push(`${y}-${String(m).padStart(2,'0')}`);m++;if(m>12){m=1;y++}}return out};

  /* ---------- scales ---------- */
  function niceTicks(min,max,count=4){
    if(min===max){max=min+1}
    const span=max-min,raw=span/count,mag=10**Math.floor(Math.log10(raw)),step=[1,2,2.5,5,10].map(s=>s*mag).find(s=>s>=raw)||10*mag;
    const lo=Math.floor(min/step)*step,hi=Math.ceil(max/step)*step,ticks=[];for(let v=lo;v<=hi+step/2;v+=step)ticks.push(Math.round(v));return ticks;
  }
  // Rounded data-end (4px), square at the baseline.
  function barPath(x,y0,y1,w,r=4){
    const up=y1<y0,hgt=Math.abs(y1-y0);r=Math.min(r,w/2,hgt);if(hgt<0.5)return '';
    if(up)return `M${x},${y0}V${y1+r}Q${x},${y1} ${x+r},${y1}H${x+w-r}Q${x+w},${y1} ${x+w},${y1+r}V${y0}Z`;
    return `M${x},${y0}V${y1-r}Q${x},${y1} ${x+r},${y1}H${x+w-r}Q${x+w},${y1} ${x+w},${y1-r}V${y0}Z`;
  }
  function hbarPath(x0,x1,y,hgt,r=4){
    const right=x1>x0,len=Math.abs(x1-x0);r=Math.min(r,hgt/2,len);if(len<0.5)return '';
    if(right)return `M${x0},${y}H${x1-r}Q${x1},${y} ${x1},${y+r}V${y+hgt-r}Q${x1},${y+hgt} ${x1-r},${y+hgt}H${x0}Z`;
    return `M${x0},${y}H${x1+r}Q${x1},${y} ${x1},${y+r}V${y+hgt-r}Q${x1},${y+hgt} ${x1+r},${y+hgt}H${x0}Z`;
  }

  /* ---------- tooltip ---------- */
  function tooltip(card){
    const tip=h('div','cvc-tip');tip.setAttribute('role','status');tip.hidden=true;card.append(tip);
    return {show(x,y,title,rows){tip.replaceChildren();tip.append(h('b','',title));for(const r of rows){const row=h('div','cvc-tip-row');const key=h('i','cvc-key');key.style.background=r.color;if(r.kind==='line')key.classList.add('is-line');const v=h('strong','',r.value);const l=h('span','',r.label);row.append(key,v,l);tip.append(row)}tip.hidden=false;const cw=card.clientWidth,tw=tip.offsetWidth;tip.style.left=Math.max(8,Math.min(cw-tw-8,x-tw/2))+'px';tip.style.top=Math.max(8,y-tip.offsetHeight-12)+'px'},hide(){tip.hidden=true}};
  }

  /* ---------- column chart (grouped columns + optional line, one axis) ---------- */
  function columnChart(box,spec,ctx,animate){
    const W=Math.max(280,box.clientWidth),H=W<480?220:260,m={t:14,r:12,b:30,l:W<480?52:62},iw=W-m.l-m.r,ih=H-m.t-m.b;
    const cols=spec.series,values=[...cols.flatMap(s=>s.values),...(spec.line?spec.line.values:[]),0];
    const ticks=niceTicks(Math.min(...values),Math.max(...values)),lo=ticks[0],hi=ticks[ticks.length-1],y=v=>m.t+ih-(v-lo)/(hi-lo)*ih,y0=y(0);
    const svg=el('svg',{viewBox:`0 0 ${W} ${H}`,width:W,height:H,role:'img','aria-label':spec.aria});
    for(const t of ticks){el('line',{x1:m.l,x2:W-m.r,y1:y(t),y2:y(t),class:t===0?'cvc-base':'cvc-grid'},svg);const tx=el('text',{x:m.l-8,y:y(t)+4,'text-anchor':'end',class:'cvc-axis'},svg);tx.textContent=ctx.compact(t)}
    const n=spec.categories.length,band=iw/n,group=Math.min(band*0.72,cols.length*24+(cols.length-1)*2),bw=(group-(cols.length-1)*2)/cols.length,every=W<480&&n>6?2:1;
    spec.categories.forEach((c,i)=>{if(i%every)return;const tx=el('text',{x:m.l+band*i+band/2,y:H-10,'text-anchor':'middle',class:'cvc-axis'},svg);tx.textContent=c});
    const marks=el('g',{class:animate?'cvc-anim':''},svg);
    cols.forEach((s,si)=>s.values.forEach((v,i)=>{const x=m.l+band*i+(band-group)/2+si*(bw+2),d=barPath(x,y0,y(v),bw);if(!d)return;const color=s.colorFor?s.colorFor(v):s.color;const p=el('path',{d,fill:color,class:'cvc-col'+(v<0?' is-neg':'')},marks);p.style.animationDelay=(animate?i*45:0)+'ms'}));
    if(spec.line){const pts=spec.line.values.map((v,i)=>[m.l+band*i+band/2,y(v)]);const path=el('path',{d:'M'+pts.map(p=>p.join(',')).join('L'),fill:'none',stroke:spec.line.color,'stroke-width':2,'stroke-linejoin':'round','stroke-linecap':'round',pathLength:1,class:'cvc-line'},marks);path.style.animationDelay=(animate?n*45:0)+'ms';pts.forEach(([px,py],i)=>{const dot=el('circle',{cx:px,cy:py,r:4,fill:spec.line.color,stroke:'var(--cv-surface)','stroke-width':2,class:'cvc-dot'},marks);dot.style.animationDelay=(animate?n*45+300+i*30:0)+'ms'})}
    // Hover/focus: one hit band per category, readout lists every series.
    const tip=tooltip(box.closest('.cvc-card')),hits=el('g',{},svg),hl=el('rect',{x:0,y:m.t,width:band,height:ih,class:'cvc-hl'},hits);hl.style.opacity=0;
    spec.categories.forEach((c,i)=>{const r=el('rect',{x:m.l+band*i,y:m.t,width:band,height:ih,fill:'transparent',tabindex:0,role:'button','aria-label':`${spec.fullCategories?.[i]||c}: ${cols.map(s=>`${s.name} ${ctx.money(s.values[i])}`).concat(spec.line?[`${spec.line.name} ${ctx.money(spec.line.values[i])}`]:[]).join(', ')}`},hits);
      const show=()=>{hl.setAttribute('x',m.l+band*i);hl.style.opacity=1;const card=box.closest('.cvc-card'),rb=r.getBoundingClientRect(),cb=card.getBoundingClientRect();tip.show(rb.left-cb.left+rb.width/2,rb.top-cb.top+12,spec.fullCategories?.[i]||c,[...cols.map(s=>({label:s.name,value:ctx.money(s.values[i]),color:s.colorFor?s.colorFor(s.values[i]):s.color})),...(spec.line?[{label:spec.line.name,value:ctx.money(spec.line.values[i]),color:spec.line.color,kind:'line'}]:[])])},hide=()=>{hl.style.opacity=0;tip.hide()};
      r.addEventListener('pointerenter',show);r.addEventListener('pointerleave',hide);r.addEventListener('focus',show);r.addEventListener('blur',hide)});
    box.replaceChildren(svg);
  }

  /* ---------- horizontal bar chart (one series, value at the tip) ---------- */
  function barChart(box,spec,ctx,animate){
    const W=Math.max(280,box.clientWidth),rowH=34,labelW=Math.min(170,Math.round(W*0.36)),m={t:6,r:86,b:6,l:labelW},H=m.t+m.b+rowH*spec.categories.length,iw=W-m.l-m.r;
    const vals=spec.values,lo=Math.min(0,...vals),hi=Math.max(0,...vals),x=v=>m.l+(v-lo)/((hi-lo)||1)*iw,x0=x(0);
    const svg=el('svg',{viewBox:`0 0 ${W} ${H}`,width:W,height:H,role:'img','aria-label':spec.aria}),marks=el('g',{class:animate?'cvc-anim':''},svg);
    el('line',{x1:x0,x2:x0,y1:m.t,y2:H-m.b,class:'cvc-base'},svg);
    const tip=tooltip(box.closest('.cvc-card'));
    spec.categories.forEach((c,i)=>{const yy=m.t+i*rowH,bh=Math.min(20,rowH-12),by=yy+(rowH-bh)/2;const lab=el('text',{x:m.l-10,y:yy+rowH/2+4,'text-anchor':'end',class:'cvc-label'},svg);lab.textContent=c.length>24?c.slice(0,23)+'…':c;
      const v=vals[i],d=hbarPath(x0,x(v),by,bh);if(d){const p=el('path',{d,fill:spec.colorFor?spec.colorFor(v):spec.color,class:'cvc-bar'},marks);p.style.animationDelay=(animate?i*70:0)+'ms'}
      const val=el('text',{x:(v<0?x0:x(v))+8,y:yy+rowH/2+4,class:'cvc-value'},svg);val.textContent=ctx.money(v);
      const hit=el('rect',{x:0,y:yy,width:W,height:rowH,fill:'transparent',tabindex:0,role:'button','aria-label':`${c}: ${ctx.money(v)}`},svg);const show=()=>{const card=box.closest('.cvc-card'),rb=hit.getBoundingClientRect(),cb=card.getBoundingClientRect();tip.show(rb.left-cb.left+Math.min(rb.width/2,x(Math.max(v,0))),rb.top-cb.top+6,c,[{label:spec.valueLabel||'Amount',value:ctx.money(v),color:spec.colorFor?spec.colorFor(v):spec.color}])},hide=()=>tip.hide();hit.addEventListener('pointerenter',show);hit.addEventListener('pointerleave',hide);hit.addEventListener('focus',show);hit.addEventListener('blur',hide)});
    box.replaceChildren(svg);
  }

  /* ---------- chart specs from report models ---------- */
  const S1='var(--cvc-s1)',S2='var(--cvc-s2)',S3='var(--cvc-s3)',NEG='var(--cvc-neg)';
  const builders={
    'profit-loss':(m,ctx)=>{const months=(m.months||[]).filter(x=>x.month>=ctx.period.start.slice(0,7)&&x.month<=ctx.period.end.slice(0,7));if(!months.length)return null;const gt=m.groupTotals||{},inc=months.map(x=>Number(gt.income?.[x.key]||0)),exp=months.map(x=>Number(gt.expense?.[x.key]||0)),net=inc.map((v,i)=>v-exp[i]),total=Number(m.totals?.netIncomeCents||0);
      return {key:'profit-loss',title:'Money in vs money out',headline:`${total<0?'Loss':'Profit'} so far this year: ${ctx.money(Math.abs(total))}`,type:'columns',categories:months.map(x=>monthLabel(x.month)),fullCategories:months.map(x=>x.label),series:[{name:'Money in',color:S1,values:inc},{name:'Money out',color:S2,values:exp}],line:{name:'Profit',color:S3,values:net},aria:`Income and expenses by month. ${total<0?'Loss':'Profit'} ${ctx.money(Math.abs(total))}.`,table:{head:['Month','Money in','Money out','Profit'],rows:months.map((x,i)=>[x.label,ctx.money(inc[i]),ctx.money(exp[i]),ctx.money(net[i])])}}},
    'cash-flow':(m,ctx)=>{const months=monthsBetween(ctx.period.start,ctx.period.end),by=Object.fromEntries(months.map(k=>[k,0]));for(const r of m.rows||[]){const k=String(r.date||'').slice(0,7);if(k in by)by[k]+=Number(r.amountCents||0)}const vals=months.map(k=>by[k]),closing=Number(m.totals?.closingCashCents||0);
      return {key:'cash-flow',title:'Cash in and out each month',headline:`Cash at the end: ${ctx.money(closing)}`,type:'columns',categories:months.map(monthLabel),series:[{name:'Net cash',colorFor:v=>v<0?NEG:S1,values:vals}],legend:[{name:'More came in',color:S1},{name:'More went out',color:NEG}],aria:`Net cash movement by month; closing cash ${ctx.money(closing)}.`,table:{head:['Month','Net cash'],rows:months.map((k,i)=>[monthLabel(k)+' '+k.slice(0,4),ctx.money(vals[i])])}}},
    'balance-sheet':(m,ctx)=>{const t=m.totals||{},cats=['What it owns','What it owes','Owner’s share'],vals=[Number(t.assetsCents||0),Number(t.liabilitiesCents||0),Number(t.equityCents||0)];
      return {key:'balance-sheet',title:'What the business owns and owes',headline:`Owner’s share: ${ctx.money(vals[2])}`,type:'bars',categories:cats,values:vals,color:S1,colorFor:v=>v<0?NEG:S1,valueLabel:'Amount',aria:`Assets ${ctx.money(vals[0])}, liabilities ${ctx.money(vals[1])}, equity ${ctx.money(vals[2])}.`,table:{head:['','Amount'],rows:cats.map((c,i)=>[c,ctx.money(vals[i])])}}},
    'ar-ageing':(m,ctx)=>ageing(m,ctx,'ar-ageing','Who owes you, by how late','Customers owe'),
    'ap-ageing':(m,ctx)=>ageing(m,ctx,'ap-ageing','What you owe suppliers, by how late','You owe'),
    'invoice-register':(m,ctx)=>perMonth(m,ctx,'invoice-register','Invoiced each month','Invoiced'),
    'bill-register':(m,ctx)=>perMonth(m,ctx,'bill-register','Supplier bills each month','Billed'),
    'expense-register':(m,ctx)=>{const by=new Map();for(const r of m.rows||[]){const k=r.accountName||r.vendor||'Other';by.set(k,(by.get(k)||0)+Number(r.totalCents||0))}if(!by.size)return null;let list=[...by].sort((a,b)=>b[1]-a[1]);if(list.length>6){const rest=list.slice(5).reduce((s,x)=>s+x[1],0);list=[...list.slice(0,5),['Other',rest]]}const total=list.reduce((s,x)=>s+x[1],0);
      return {key:'expense-register',title:'Spending by category',headline:`Spent this year: ${ctx.money(total)}`,type:'bars',categories:list.map(x=>x[0]),values:list.map(x=>x[1]),color:S1,valueLabel:'Spent',aria:`Spending by category, total ${ctx.money(total)}.`,table:{head:['Category','Spent'],rows:list.map(x=>[x[0],ctx.money(x[1])])}}}
  };
  function ageing(m,ctx,key,title,verb){const order=m.groupOrder||[],gt=m.groupTotals||{},vals=order.map(k=>Number(typeof gt[k]==='object'?gt[k]?.balanceCents:gt[k]||0)),total=Number(m.totals?.balanceCents||0);if(!order.length)return null;const nice=k=>/^current/i.test(k)?'Not yet due':k.replace(/^(\d+)\+$/,'Over $1 days').replace(/^(\d+)–(\d+)$/,'$1–$2 days late');const overdue=vals.slice(1).reduce((s,v)=>s+v,0);
    return {key,title,headline:`${verb} ${ctx.money(total)}${overdue>0?(overdue>=total?' · all overdue':` · ${ctx.money(overdue)} overdue`):''}`,type:'columns',categories:order.map(k=>/^current/i.test(k)?'Not due':k.replace('+','+ d').replace(/–(\d+)$/,'–$1 d')),fullCategories:order.map(nice),series:[{name:'Amount',color:S1,values:vals}],aria:`${title}: total ${ctx.money(total)}.`,table:{head:['How late','Amount'],rows:order.map((k,i)=>[nice(k),ctx.money(vals[i])])}}}
  function perMonth(m,ctx,key,title,verb){const months=monthsBetween(ctx.period.start,ctx.period.end),by=Object.fromEntries(months.map(k=>[k,0]));for(const r of m.rows||[]){const k=String(r.date||'').slice(0,7);if(k in by)by[k]+=Number(r.totalCents||0)}const vals=months.map(k=>by[k]),total=vals.reduce((s,v)=>s+v,0);
    return {key,title,headline:`${verb} this year: ${ctx.money(total)}`,type:'columns',categories:months.map(monthLabel),series:[{name:verb,color:S1,values:vals}],aria:`${title}; total ${ctx.money(total)}.`,table:{head:['Month',verb],rows:months.map((k,i)=>[monthLabel(k)+' '+k.slice(0,4),ctx.money(vals[i])])}}}
  const ORDER=['profit-loss','cash-flow','ar-ageing','ap-ageing','invoice-register','bill-register','expense-register','balance-sheet'];

  /* ---------- cards ---------- */
  function card(spec,ctx,index){
    const c=h('article','cvc-card');c.style.setProperty('--cvc-i',index);
    const head=h('header','cvc-head'),t=h('div');t.append(h('h3','',spec.title),h('p','cvc-headline',spec.headline));
    const actions=h('div','cvc-actions'),tbl=h('button','cvc-ghost','Table');tbl.type='button';tbl.setAttribute('aria-pressed','false');const open=h('button','cvc-ghost','Open report →');open.type='button';open.onclick=()=>ctx.onOpen(spec.key);actions.append(tbl,open);head.append(t,actions);c.append(head);
    const legendItems=spec.legend||(spec.series&&(spec.series.length+(spec.line?1:0))>=2?[...spec.series.map(s=>({name:s.name,color:s.color})),...(spec.line?[{name:spec.line.name,color:spec.line.color,kind:'line'}]:[])]:null);
    if(legendItems){const lg=h('ul','cvc-legend');for(const it of legendItems){const li=h('li');const k=h('i','cvc-key'+(it.kind==='line'?' is-line':''));k.style.background=it.color;li.append(k,document.createTextNode(it.name));lg.append(li)}c.append(lg)}
    const box=h('div','cvc-plot');c.append(box);
    const table=h('div','cvc-table');table.hidden=true;const tb=h('table');const thead=h('thead'),trh=h('tr');spec.table.head.forEach((x,i)=>{const th=h('th',i?'num':'',x);trh.append(th)});thead.append(trh);const tbody=h('tbody');for(const r of spec.table.rows){const tr=h('tr');r.forEach((x,i)=>tr.append(h('td',i?'num':'',x)));tbody.append(tr)}tb.append(thead,tbody);table.append(tb);c.append(table);
    tbl.onclick=()=>{const on=table.hidden;table.hidden=!on;box.hidden=on;tbl.setAttribute('aria-pressed',String(on));tbl.textContent=on?'Chart':'Table';if(!on)draw(false)};
    const draw=animate=>{if(box.hidden)return;(spec.type==='bars'?barChart:columnChart)(box,spec,ctx,animate)};
    c.__draw=draw;return c;
  }

  async function mount(host,ctx){
    const keys=ORDER.filter(k=>ctx.reports.includes(k)&&builders[k]);
    host.replaceChildren();if(!keys.length)return;
    const grid=h('div','cvc-grid');host.append(grid);
    const placeholders=keys.map((k,i)=>{const p=h('article','cvc-card cvc-loading');p.style.setProperty('--cvc-i',i);p.append(h('h3','',ctx.titles[k]||''),h('p','cvc-headline','Drawing chart…'));grid.append(p);return p});
    const cards=[];
    await Promise.all(keys.map(async(k,i)=>{
      try{const model=await ctx.fetch(k);const spec=builders[k](model,ctx);if(!spec){placeholders[i].remove();return}const c=card(spec,ctx,i);placeholders[i].replaceWith(c);cards.push(c);
        // Draw once it scrolls into view so the animation is seen; draw at once when motion is reduced.
        if(reduced()||!('IntersectionObserver' in window)){c.__draw(false);c.__drawn=true;return}
        const io=new IntersectionObserver(es=>{if(es.some(e=>e.isIntersecting)){io.disconnect();c.__drawn=true;c.__draw(true)}},{threshold:0.15});io.observe(c)}
      catch(e){placeholders[i].querySelector('.cvc-headline').textContent='This chart could not be drawn right now.'}
    }));
    let raf=0,lastW=host.clientWidth;const ro=new ResizeObserver(()=>{if(Math.abs(host.clientWidth-lastW)<4)return;lastW=host.clientWidth;cancelAnimationFrame(raf);raf=requestAnimationFrame(()=>cards.forEach(c=>{if(c.__drawn)c.__draw(false)}))});ro.observe(host);
    host.__cvcCleanup=()=>ro.disconnect();
  }

  /* Count a number up to its value (skipped for reduced motion). */
  function countUp(node,target,format,duration=900){
    if(reduced()||!Number.isFinite(target)){node.textContent=format(target);return}
    const start=performance.now();const step=now=>{const t=Math.min(1,(now-start)/duration),e=1-Math.pow(1-t,3);node.textContent=format(Math.round(target*e));if(t<1)requestAnimationFrame(step)};requestAnimationFrame(step);
  }

  window.TeghClientCharts={mount,countUp};
})();
