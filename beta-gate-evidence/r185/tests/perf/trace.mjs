// Traces one navigation and reports where style/layout time goes. Usage: node trace.mjs <module> <action> [company]
import {open} from '../e2e/base.mjs';
import fs from 'fs';
const [m,a,co]=process.argv.slice(2);
const {b,p,errs}=await open(co);
const cdp=await p.context().newCDPSession(p);
await p.waitForTimeout(1500);
// count CSS rules and DOM size
const info=await p.evaluate(()=>{let rules=0,has=0,attr=0,univ=0;const sheets=[];for(const s of document.styleSheets){let n=0;try{for(const r of s.cssRules){n++;const t=r.cssText||'';if(t.includes(':has('))has++;if(/\[[^\]]*\*=|\[[^\]]*\^=/.test(t))attr++;if(/(^|[\s>+~,])\*(?![=])/.test(t.split('{')[0]))univ++}}catch{};rules+=n;sheets.push([(s.href||'inline').replace(/.*\//,'').slice(0,40),n])}return {rules,has,attr,univ,sheets:sheets.sort((x,y)=>y[1]-x[1]).slice(0,25)}});
console.log(JSON.stringify(info));
await cdp.send('Tracing.start',{categories:'devtools.timeline,disabled-by-default-devtools.timeline,blink.user_timing',transferMode:'ReturnAsStream'});
const t=Date.now();await p.evaluate(([m,a])=>TeghPortal.invokeMenuAction(m,a),[m,a]);await p.waitForTimeout(4000);
const done=new Promise(r=>cdp.once('Tracing.tracingComplete',r));await cdp.send('Tracing.end');const {stream}=await done;
let data='';for(;;){const c=await cdp.send('IO.read',{handle:stream});data+=c.data;if(c.eof)break}
const ev=JSON.parse(data).traceEvents||JSON.parse(data);
const style=ev.filter(e=>e.name==='UpdateLayoutTree'||e.name==='RecalculateStyles');
let tot=0,cnt=0,big=[];for(const e of style){if(e.dur){tot+=e.dur;cnt++;big.push([Math.round(e.dur/1000),e.args?.elementCount||e.args?.beginData?.elementCount||'',e.args?.beginData?.stackTrace?.[0]?.functionName||''])}}
console.log('style recalcs',cnt,'total ms',Math.round(tot/1000));console.log(big.sort((x,y)=>y[0]-x[0]).slice(0,15));
// What triggers them: look for forced (synchronous) recalcs with stack
const forced=ev.filter(e=>(e.name==='UpdateLayoutTree')&&e.args?.beginData?.stackTrace).map(e=>[Math.round((e.dur||0)/1000),e.args.beginData.stackTrace.slice(0,3).map(s=>s.functionName+':'+s.lineNumber+' '+(s.url||'').replace(/.*\//,'').replace(/\?.*/,'')).join(' < ')]);
const agg={};for(const [d,s] of forced){agg[s]=agg[s]||[0,0];agg[s][0]+=d;agg[s][1]++}
console.log(Object.entries(agg).sort((x,y)=>y[1][0]-x[1][0]).slice(0,12));
const fn=ev.filter(e=>e.name==='FunctionCall'&&e.dur>20000).map(e=>[Math.round(e.dur/1000),e.args?.data?.functionName,e.args?.data?.url?.replace(/.*\//,'').replace(/\?.*/,''),e.args?.data?.lineNumber]);console.log('long calls',fn.slice(0,15));
const mut=ev.filter(e=>e.name==='V8.Execute'||e.name==='EventDispatch').length;
await b.close();
