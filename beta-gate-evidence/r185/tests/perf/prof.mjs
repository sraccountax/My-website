// CPU profile of one navigation (or of startup with "startup"). Prints self and total time by function.
import {open} from '../e2e/base.mjs';
const [m,a,co]=process.argv.slice(2);
const {b,p}=await open(co);
const cdp=await p.context().newCDPSession(p);await p.waitForTimeout(1500);
await cdp.send('Profiler.enable');await cdp.send('Profiler.setSamplingInterval',{interval:200});await cdp.send('Profiler.start');
if(m==='startup'){await p.reload();await p.waitForFunction(()=>window.TeghPortal&&document.querySelector('.sidebar,.topbar'));await p.waitForTimeout(3000)}
else{await p.evaluate(([m,a])=>TeghPortal.invokeMenuAction(m,a),[m,a]);await p.waitForTimeout(4000);}
const {profile}=await cdp.send('Profiler.stop');
const nodes=new Map(profile.nodes.map(n=>[n.id,n]));const self=new Map();
const dt=profile.timeDeltas;const counts=new Map();profile.samples.forEach((s,i)=>counts.set(s,(counts.get(s)||0)+(dt[i]||0)));
const key=n=>{const c=n.callFrame;return `${c.functionName||'(anon)'} ${c.url.replace(/.*\//,'').replace(/\?.*/,'')}:${c.lineNumber+1}`};
const parent=new Map();for(const n of profile.nodes)for(const c of n.children||[])parent.set(c,n.id);
const tot=new Map();
for(const [id,us] of counts){const n=nodes.get(id);const k=key(n);self.set(k,(self.get(k)||0)+us);
 const seen=new Set();let cur=id;while(cur){const kk=key(nodes.get(cur));if(!seen.has(kk)){seen.add(kk);tot.set(kk,(tot.get(kk)||0)+us)}cur=parent.get(cur)}}
const fmt=mp=>[...mp].sort((x,y)=>y[1]-x[1]).slice(0,30).map(([k,v])=>String(Math.round(v/1000)).padStart(6)+' ms  '+k).join('\n');
console.log('--- self\n'+fmt(self));console.log('--- total\n'+fmt(tot));await b.close();
