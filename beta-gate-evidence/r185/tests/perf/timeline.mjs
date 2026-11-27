// Timeline of one warm reload: when each request starts and ends, and when the interface becomes usable.
// Usage: node timeline.mjs <company> [throttle:1|0]
import {open} from '../e2e/base.mjs';
const [co, thr = '1'] = process.argv.slice(2);
const {b, p} = await open(co);
await p.waitForTimeout(2500);
const cdp = await p.context().newCDPSession(p);
await cdp.send('Network.enable');
if (thr === '1') await cdp.send('Network.emulateNetworkConditions', {offline: false, latency: 60, downloadThroughput: 20e6 / 8, uploadThroughput: 5e6 / 8});
const req = new Map(); let t0 = 0;
cdp.on('Network.requestWillBeSent', e => { if (!t0) t0 = e.timestamp; req.set(e.requestId, {url: e.request.url, start: e.timestamp, method: e.request.method}); });
cdp.on('Network.responseReceived', e => { const r = req.get(e.requestId); if (r) { r.cache = e.response.fromDiskCache || e.response.fromMemoryCache || e.response.status === 304 ? (e.response.status === 304 ? '304' : 'cache') : ''; r.status = e.response.status; } });
cdp.on('Network.loadingFinished', e => { const r = req.get(e.requestId); if (r) { r.end = e.timestamp; r.bytes = e.encodedDataLength; } });
const marks = {};
const T = Date.now();
await p.reload({waitUntil: 'commit'});
const w = async (name, fn) => { await p.waitForFunction(fn, null, {timeout: 60000}).catch(() => {}); marks[name] = Date.now() - T; };
await w('gate script', () => !!window.TeghGate || !!document.querySelector('#sr-gate'));
await w('core app', () => !!document.querySelector('.sidebar,.topbar'));
await w('portal', () => !!window.TeghPortal);
await w('startup ready', () => document.documentElement.dataset.srStartup === 'ready' || document.documentElement.classList.contains('sr-startup-ready') || !document.documentElement.classList.contains('sr-gate-session-pending'));
await p.waitForTimeout(2500);
const rows = [...req.values()].filter(r => r.end).map(r => ({...r, s: Math.round((r.start - t0) * 1000), e: Math.round((r.end - t0) * 1000)})).sort((a, b) => a.s - b.s);
for (const r of rows) console.log(String(r.s).padStart(5), String(r.e).padStart(5), (r.cache || '').padEnd(5), String(r.bytes).padStart(7), r.method.padEnd(4), r.url.replace('https://gate.test', '').replace(/\?v=5990-r[0-9a-z-]+/, '').replace('/api/index.php?route=', 'API ').slice(0, 80));
console.log('marks (ms from reload):', JSON.stringify(marks));
await b.close();
