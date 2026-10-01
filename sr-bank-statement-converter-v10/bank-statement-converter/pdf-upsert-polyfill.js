// SR AccounTax converter: pdf.js 6 calls Map/WeakMap.prototype.getOrInsertComputed, a 2026 JavaScript addition that
// many browsers do not have yet; without it, rendering a scanned page for OCR fails. This adds the standard behaviour
// only where the browser lacks it. Loaded on the page and, through pdf-worker-entry.mjs, inside the PDF worker.
(function () {
  for (const C of [globalThis.Map, globalThis.WeakMap]) {
    if (!C) continue;
    if (typeof C.prototype.getOrInsert !== 'function') Object.defineProperty(C.prototype, 'getOrInsert', {configurable: true, writable: true, value(key, value) { if (this.has(key)) return this.get(key); this.set(key, value); return value; }});
    if (typeof C.prototype.getOrInsertComputed !== 'function') Object.defineProperty(C.prototype, 'getOrInsertComputed', {configurable: true, writable: true, value(key, callback) { if (this.has(key)) return this.get(key); const value = callback(key); this.set(key, value); return value; }});
  }
})();
