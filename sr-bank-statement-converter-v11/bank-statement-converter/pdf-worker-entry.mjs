// PDF worker entry: install the Map/WeakMap upsert methods first (module imports run in order), then start pdf.js's worker.
import './pdf-upsert-polyfill.js?v=20261001-10';
import './vendor/pdfjs/pdf.worker.min.mjs';
