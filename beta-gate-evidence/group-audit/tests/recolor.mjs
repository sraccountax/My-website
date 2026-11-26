// Recolour engine for theme mock-ups: maps Tegh's teal/green/gold colours to a proposed palette in OKLCH,
// keeping each colour's lightness so contrast relationships stay close to the shipped design.
const toLin = c => (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);
const toSrgb = c => (c <= 0.0031308 ? 12.92 * c : 1.055 * c ** (1 / 2.4) - 0.055);
export function hexToOklch(hex) {
  let h = hex.replace('#', ''); if (h.length === 3) h = [...h].map(x => x + x).join('');
  const [r, g, b] = [0, 2, 4].map(i => toLin(parseInt(h.slice(i, i + 2), 16) / 255));
  const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b), m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b), s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
  const L = 0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s, A = 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s, B = 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s;
  return {L, C: Math.hypot(A, B), H: ((Math.atan2(B, A) * 180) / Math.PI + 360) % 360};
}
function oklchToRgb(L, C, H) {
  const a = C * Math.cos((H * Math.PI) / 180), b = C * Math.sin((H * Math.PI) / 180);
  const l = (L + 0.3963377774 * a + 0.2158037573 * b) ** 3, m = (L - 0.1055613458 * a - 0.0638541728 * b) ** 3, s = (L - 0.0894841775 * a - 1.291485548 * b) ** 3;
  return [4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s, -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s, -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s];
}
export function oklchToHex(L, C, H) {
  // Reduce chroma until the colour fits sRGB.
  let c = C; let rgb = oklchToRgb(L, c, H);
  while (c > 0 && rgb.some(v => v < -0.0005 || v > 1.0005)) { c -= 0.002; rgb = oklchToRgb(L, c, H); }
  return '#' + rgb.map(v => Math.round(Math.min(1, Math.max(0, toSrgb(Math.min(1, Math.max(0, v))))) * 255).toString(16).padStart(2, '0')).join('');
}
// Status colours stay as they are (success, warning, danger), so the mock-ups never repaint meaning.
const KEEP = new Set(['#117b56', '#15735d', '#16765a', '#1f715d', '#58d59e', '#b42318', '#a13d38', '#9d3d36', '#b43a3a', '#b5473d', '#ff8982', '#1d6b45', '#e6f4ec', '#7fd8a6']);
// A palette: dark (sidebar/heritage greens), brand (actions, links), tint (light brand washes), accent (gold), neutral (green-grey tints).
export const PALETTES = {
  aubergine: {name: 'Aubergine & Brass', dark: {H: 345, C: 0.055}, brand: {H: 350, Cx: 1.05}, tint: {H: 345, C: 0.025}, accent: {H: 78, Cx: 1.0}, neutral: {H: 50}},
  marigold: {name: 'Ink & Marigold', dark: {H: 268, C: 0.022}, brand: {H: 268, Cfix: 0.045, Lmax: 0.36}, tint: {H: 88, C: 0.06}, accent: {H: 82, Cfix: 0.165, Lmin: 0.8}, neutral: {H: 85}},
  terracotta: {name: 'Terracotta & Slate', dark: {H: 245, C: 0.035}, brand: {H: 42, Cx: 1.25}, tint: {H: 52, C: 0.03}, accent: {H: 240, Cfix: 0.07}, neutral: {H: 70}},
};
export function mapHex(hex, P) {
  const low = hex.toLowerCase(); if (KEEP.has(low)) return hex;
  const {L, C, H} = hexToOklch(low);
  const goldish = H >= 60 && H <= 100;
  // Dark brand surfaces: the green-black sidebar, the navy sign-in panel and ink.
  if (H >= 165 && H <= 255 && C > 0.015 && (L < 0.4 || (H >= 205 && L < 0.47))) return oklchToHex(L, P.dark.C, P.dark.H);
  const teal = H >= 168 && H <= 215; // brand teal; true greens below 168 stay (paid, positive, success)
  if (teal && C > 0.045) {
    if (L > 0.86) return oklchToHex(L, Math.min(C, P.tint.C), P.tint.H);
    const b = P.brand; return oklchToHex(b.Lmax ? Math.min(L, b.Lmax) : L, b.Cfix ?? C * (b.Cx || 1), b.H);
  }
  if (H >= 150 && H <= 215 && C > 0.003) return oklchToHex(L, Math.min(C, L > 0.86 ? P.tint.C : 0.03), L > 0.86 ? P.tint.H : P.neutral.H);
  if (goldish && C > 0.04) { const a = P.accent; return oklchToHex(a.Lmin ? Math.max(L, a.Lmin) : L, a.Cfix ?? C * (a.Cx || 1), a.H); }
  return hex;
}
const RGBA = /rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(,\s*[\d.]+\s*)?\)/g;
export function recolor(text, P) {
  return text.replace(/#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b(?![0-9a-fA-F])/g, m => mapHex(m, P))
    .replace(RGBA, (m, r, g, b, a) => { const h = mapHex('#' + [r, g, b].map(x => Number(x).toString(16).padStart(2, '0')).join(''), P); const [R, G, B] = [1, 3, 5].map(i => parseInt(h.slice(i, i + 2), 16)); return a ? `rgba(${R},${G},${B}${a})` : `rgb(${R},${G},${B})`; });
}
