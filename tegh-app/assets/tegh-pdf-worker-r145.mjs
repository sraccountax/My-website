// R145: PDF worker entry. Installs the Map/WeakMap upsert methods first (module imports run in order), then starts the bundled pdf.js worker.
import './tegh-upsert-polyfill-r145.js?v=5990-r145-tegh';
import './tegh-ocr/v5220/pdfjs/pdf.worker.min.mjs?v=5220';
