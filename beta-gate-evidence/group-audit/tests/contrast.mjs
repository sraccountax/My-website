import {PALETTES, mapHex, hexToOklch} from './recolor.mjs';
const lum = h => { const [r,g,b] = [1,3,5].map(i => parseInt(h.slice(i,i+2),16)/255).map(c => c <= 0.03928 ? c/12.92 : ((c+0.055)/1.055)**2.4); return 0.2126*r+0.7152*g+0.0722*b; };
const cr = (a,b) => { const [x,y] = [lum(a),lum(b)].sort((p,q)=>q-p); return ((x+0.05)/(y+0.05)).toFixed(2); };
const de = (a,b) => { const p = hexToOklch(a), q = hexToOklch(b); const ax = p.C*Math.cos(p.H*Math.PI/180), ay = p.C*Math.sin(p.H*Math.PI/180), bx = q.C*Math.cos(q.H*Math.PI/180), by = q.C*Math.sin(q.H*Math.PI/180); return (100*Math.hypot(p.L-q.L, ax-bx, ay-by)).toFixed(1); };
const pairs = [['Button text on primary', '#ffffff', '#0f766e'], ['Primary (link) on canvas', '#0f766e', '#f3f6f5'], ['Strong primary on white', '#0b5d56', '#ffffff'], ['Nav text on sidebar', '#c7d8d2', '#0b2b24'], ['Nav accent on sidebar', '#d4b56a', '#0b2b24'], ['Muted text on canvas', '#5d706a', '#f3f6f5'], ['Ink on tint', '#0b1714', '#e6f4f0']];
const rows = {};
for (const [k, P] of Object.entries({current: null, ...PALETTES})) {
  const m = h => P ? mapHex(h, P) : h;
  rows[k] = pairs.map(([n, f, bg]) => `${n}: ${m(f)} on ${m(bg)} = ${cr(m(f), m(bg))}:1`);
  rows[k].push(`Brand vs success #117b56 ΔE ${de(m('#0f766e'), '#117b56')}, vs danger #b42318 ΔE ${de(m('#0f766e'), '#b42318')}, vs warning #b54708 ΔE ${de(m('#0f766e'), '#b54708')}`);
  rows[k].push('Key colours: primary ' + m('#0f766e') + ', strong ' + m('#0b5d56') + ', sidebar ' + m('#0b2b24') + ', accent ' + m('#d4b56a') + ', tint ' + m('#e6f4f0') + ', canvas ' + m('#f3f6f5') + ', dark canvas ' + m('#091411'));
}
console.log(JSON.stringify(rows, null, 1));
