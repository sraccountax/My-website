/*
 * R179: PaddleOCR in the browser, for Document Intake.
 *
 * Models: PaddlePaddle PP-OCRv5 mobile text detector and the Latin-script (English and French) text recogniser,
 * from the official PaddlePaddle releases (Apache License 2.0), converted to ONNX with paddle2onnx. They run here with
 * ONNX Runtime Web (MIT License), on WebAssembly, in this browser only: no image or text leaves the device.
 *
 * The pre- and post-processing below is a JavaScript port of PaddleOCR's own Python code (Apache License 2.0,
 * Copyright (c) PaddlePaddle Authors): DetResizeForTest + NormalizeImage, DBPostProcess (threshold, boxes from
 * connected regions, box score, unclip), sorted_boxes, get_rotate_crop_image, the recogniser's resize_norm_img and
 * CTCLabelDecode. OpenCV and pyclipper are replaced by small exact equivalents for upright text boxes.
 * Licence texts and the model provenance: /assets/tegh-ocr/paddle-r179/NOTICE.txt.
 *
 *   await TeghPaddleOCR.recognize(canvas, { onProgress }) → { text, lines: [{ text, score, box }] }
 */
(function (global) {
  'use strict';
  if (global.TeghPaddleOCR) return;

  const ROOT = '/assets/tegh-ocr/paddle-r179';
  const BUILD = 'r179';
  const MANIFEST_SHA256 = '61c971702bdb37881f513f4c39f68aa2bb01d08e36a594fe80f5a1027685ea3a';
  const DET = { thresh: 0.3, boxThresh: 0.6, unclipRatio: 1.5, minSize: 3, maxCandidates: 1000, minLong: 960, maxLong: 2560 };
  const REC = { height: 48, minWidth: 320, maxWidth: 3200, minScore: 0.5 };

  const runtimeError = (code, message) => Object.assign(new Error(message), { code });
  const sha256 = async bytes => {
    if (!global.crypto?.subtle) throw runtimeError('paddle_crypto_unavailable', 'This browser cannot verify the bundled OCR models.');
    const d = await global.crypto.subtle.digest('SHA-256', bytes);
    return Array.from(new Uint8Array(d), v => v.toString(16).padStart(2, '0')).join('');
  };
  const progress = (cb, status, value, detail) => { try { cb && cb({ status, progress: Math.max(0, Math.min(1, value)), detail: detail || '' }); } catch (_) { /* ignore */ } };

  // ---------- Verified loading ----------
  let ready = null;
  async function fetchVerified(record) {
    const url = new URL(record.path, global.location.origin);
    if (url.origin !== global.location.origin) throw runtimeError('paddle_origin_invalid', 'A bundled OCR model path is not same-origin.');
    url.searchParams.set('v', BUILD);
    const res = await fetch(url, { credentials: 'same-origin', cache: 'force-cache' });
    if (!res.ok) throw runtimeError('paddle_asset_unavailable', 'A bundled OCR model file is unavailable: ' + record.path);
    const bytes = await res.arrayBuffer();
    if (bytes.byteLength !== record.sizeBytes || await sha256(bytes) !== record.sha256) throw runtimeError('paddle_integrity_failed', 'A bundled OCR model file did not pass verification: ' + record.path);
    return bytes;
  }
  const loadScript = src => new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = src; s.async = true; s.dataset.teghPaddleOcr = '1';
    s.onload = () => resolve(); s.onerror = () => { s.remove(); reject(runtimeError('paddle_runtime_unavailable', 'The bundled ONNX runtime could not be loaded.')); };
    document.head.appendChild(s);
  });

  function load(onProgress) {
    ready ||= (async () => {
      progress(onProgress, 'loading_models', 0.02, 'Checking the local OCR models');
      const mres = await fetch(ROOT + '/manifest.json?v=' + BUILD, { credentials: 'same-origin', cache: 'force-cache' });
      if (!mres.ok) throw runtimeError('paddle_manifest_unavailable', 'The OCR model manifest is unavailable.');
      const mbytes = await mres.arrayBuffer();
      if (await sha256(mbytes) !== MANIFEST_SHA256) throw runtimeError('paddle_manifest_integrity_failed', 'The OCR model manifest did not pass verification.');
      const manifest = JSON.parse(new TextDecoder().decode(mbytes));
      if (manifest.release !== 'paddle-r179' || manifest.execution !== 'browser-only' || manifest.providerRequests !== 0) throw runtimeError('paddle_manifest_identity_failed', 'The OCR model manifest has an unexpected identity.');
      const rec = Object.fromEntries(manifest.runtimeAssets.map(a => [a.role, a]));
      for (const role of ['ortLoader', 'ortGlue', 'ortWasm', 'detModel', 'recModel', 'recDict']) {
        if (!rec[role] || !String(rec[role].path).startsWith(ROOT + '/') || !/^[a-f0-9]{64}$/.test(rec[role].sha256)) throw runtimeError('paddle_manifest_asset_invalid', 'The OCR model manifest is incomplete.');
      }
      // The loader and the runtime glue are verified, then loaded from the same address (the CSP allows only this site).
      await fetchVerified(rec.ortLoader); await fetchVerified(rec.ortGlue);
      progress(onProgress, 'loading_models', 0.08, 'Loading the local OCR engine');
      const [wasm, det, recModel, dictBytes] = await Promise.all([fetchVerified(rec.ortWasm), fetchVerified(rec.detModel), fetchVerified(rec.recModel), fetchVerified(rec.recDict)]);
      if (!global.ort) await loadScript(rec.ortLoader.path + '?v=' + BUILD);
      const ort = global.ort;
      if (!ort?.InferenceSession) throw runtimeError('paddle_runtime_unavailable', 'The bundled ONNX runtime did not start.');
      ort.env.wasm.numThreads = 1;              // no cross-origin isolation is needed
      ort.env.wasm.proxy = false;
      ort.env.wasm.wasmPaths = ROOT + '/';      // the glue module is imported from here
      ort.env.wasm.wasmBinary = wasm;           // the verified bytes; no second, unverified download
      const opts = { executionProviders: ['wasm'], graphOptimizationLevel: 'all' };
      progress(onProgress, 'loading_models', 0.16, 'Starting the local OCR models');
      const detSession = await ort.InferenceSession.create(new Uint8Array(det), opts);
      const recSession = await ort.InferenceSession.create(new Uint8Array(recModel), opts);
      const dict = JSON.parse(new TextDecoder().decode(dictBytes));
      if (!Array.isArray(dict) || dict.length < 50) throw runtimeError('paddle_dict_invalid', 'The OCR character list is invalid.');
      return { ort, detSession, recSession, chars: ['', ...dict, ' '], manifest };
    })().catch(e => { ready = null; throw e; });
    return ready;
  }

  // ---------- Image helpers ----------
  function toCanvas(source) {
    if (source instanceof HTMLCanvasElement) return source;
    const c = document.createElement('canvas');
    c.width = source.width; c.height = source.height;
    c.getContext('2d', { willReadFrequently: true }).drawImage(source, 0, 0);
    return c;
  }
  function resized(canvas, w, h) {
    const c = document.createElement('canvas'); c.width = w; c.height = h;
    const x = c.getContext('2d', { willReadFrequently: true });
    x.fillStyle = '#fff'; x.fillRect(0, 0, w, h); x.imageSmoothingQuality = 'high';
    x.drawImage(canvas, 0, 0, w, h);
    return x.getImageData(0, 0, w, h).data;
  }

  // DetResizeForTest + NormalizeImage + ToCHW. PaddleOCR reads images as BGR, so channel 0 is blue.
  function detTensor(ort, canvas) {
    const long = Math.max(canvas.width, canvas.height);
    const s = long < DET.minLong ? DET.minLong / long : long > DET.maxLong ? DET.maxLong / long : 1;
    const w = Math.max(32, Math.round(canvas.width * s / 32) * 32), h = Math.max(32, Math.round(canvas.height * s / 32) * 32);
    const px = resized(canvas, w, h), n = w * h, data = new Float32Array(3 * n);
    const mean = [0.485, 0.456, 0.406], std = [0.229, 0.224, 0.225];
    for (let i = 0; i < n; i++) {
      const r = px[i * 4], g = px[i * 4 + 1], b = px[i * 4 + 2];
      data[i] = (b / 255 - mean[0]) / std[0];
      data[n + i] = (g / 255 - mean[1]) / std[1];
      data[2 * n + i] = (r / 255 - mean[2]) / std[2];
    }
    return { tensor: new ort.Tensor('float32', data, [1, 3, h, w]), w, h };
  }

  // ---------- DBPostProcess ----------
  function hull(points) {                      // monotone chain convex hull
    points.sort((a, b) => a[0] - b[0] || a[1] - b[1]);
    const cross = (o, a, b) => (a[0] - o[0]) * (b[1] - o[1]) - (a[1] - o[1]) * (b[0] - o[0]);
    const lower = [], upper = [];
    for (const p of points) { while (lower.length >= 2 && cross(lower[lower.length - 2], lower[lower.length - 1], p) <= 0) lower.pop(); lower.push(p); }
    for (let i = points.length - 1; i >= 0; i--) { const p = points[i]; while (upper.length >= 2 && cross(upper[upper.length - 2], upper[upper.length - 1], p) <= 0) upper.pop(); upper.push(p); }
    upper.pop(); lower.pop();
    return lower.concat(upper);
  }
  // cv2.minAreaRect on a convex hull (rotating calipers): centre, width along the angle, height, angle.
  function minAreaRect(h) {
    if (h.length === 1) return { cx: h[0][0], cy: h[0][1], w: 0, hgt: 0, a: 0 };
    let best = null;
    for (let i = 0; i < h.length; i++) {
      const p = h[i], q = h[(i + 1) % h.length];
      const a = Math.atan2(q[1] - p[1], q[0] - p[0]), c = Math.cos(a), s = Math.sin(a);
      let minU = Infinity, maxU = -Infinity, minV = Infinity, maxV = -Infinity;
      for (const r of h) { const u = r[0] * c + r[1] * s, v = -r[0] * s + r[1] * c; if (u < minU) minU = u; if (u > maxU) maxU = u; if (v < minV) minV = v; if (v > maxV) maxV = v; }
      const area = (maxU - minU) * (maxV - minV);
      if (!best || area < best.area) best = { area, a, minU, maxU, minV, maxV };
    }
    const c = Math.cos(best.a), s = Math.sin(best.a), mu = (best.minU + best.maxU) / 2, mv = (best.minV + best.maxV) / 2;
    return { cx: mu * c - mv * s, cy: mu * s + mv * c, w: best.maxU - best.minU, hgt: best.maxV - best.minV, a: best.a };
  }
  // get_mini_boxes: the four corners, ordered top-left, top-right, bottom-right, bottom-left.
  function corners(rect) {
    const c = Math.cos(rect.a), s = Math.sin(rect.a), hw = rect.w / 2, hh = rect.hgt / 2;
    const pts = [[-hw, -hh], [hw, -hh], [hw, hh], [-hw, hh]].map(([u, v]) => [rect.cx + u * c - v * s, rect.cy + u * s + v * c]);
    pts.sort((p, q) => p[0] - q[0]);
    const [l1, l2] = pts[0][1] <= pts[1][1] ? [pts[0], pts[1]] : [pts[1], pts[0]];
    const [r1, r2] = pts[2][1] <= pts[3][1] ? [pts[2], pts[3]] : [pts[3], pts[2]];
    return [l1, r1, r2, l2];
  }
  const inside = (box, x, y) => {             // point in convex quad (clockwise or anticlockwise)
    let sign = 0;
    for (let i = 0; i < 4; i++) { const p = box[i], q = box[(i + 1) % 4]; const c = (q[0] - p[0]) * (y - p[1]) - (q[1] - p[1]) * (x - p[0]); if (c !== 0) { const sg = c > 0 ? 1 : -1; if (sign && sg !== sign) return false; sign = sg; } }
    return true;
  };
  function boxScore(prob, w, h, box) {        // box_score_fast: mean probability inside the box
    let x0 = Math.max(0, Math.floor(Math.min(...box.map(p => p[0])))), x1 = Math.min(w - 1, Math.ceil(Math.max(...box.map(p => p[0]))));
    let y0 = Math.max(0, Math.floor(Math.min(...box.map(p => p[1])))), y1 = Math.min(h - 1, Math.ceil(Math.max(...box.map(p => p[1]))));
    let sum = 0, n = 0;
    for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) if (inside(box, x + 0.5, y + 0.5)) { sum += prob[y * w + x]; n++; }
    return n ? sum / n : 0;
  }
  function detect(prob, w, h, scaleX, scaleY) {
    const bin = new Uint8Array(w * h);
    for (let i = 0; i < bin.length; i++) bin[i] = prob[i] > DET.thresh ? 1 : 0;
    const label = new Int32Array(w * h), boxes = [], stack = [];
    let next = 0;
    for (let start = 0; start < bin.length && boxes.length < DET.maxCandidates; start++) {
      if (!bin[start] || label[start]) continue;
      next++; label[start] = next; stack.push(start);
      const rows = new Map();                 // each row's leftmost and rightmost pixel: enough for the hull
      while (stack.length) {
        const i = stack.pop(), x = i % w, y = (i - x) / w;
        const r = rows.get(y); if (!r) rows.set(y, [x, x]); else { if (x < r[0]) r[0] = x; if (x > r[1]) r[1] = x; }
        for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) {
          const nx = x + dx, ny = y + dy; if (nx < 0 || ny < 0 || nx >= w || ny >= h) continue;
          const j = ny * w + nx; if (bin[j] && !label[j]) { label[j] = next; stack.push(j); }
        }
      }
      const pts = [];
      for (const [y, [a, b]] of rows) pts.push([a, y], [b + 1, y], [a, y + 1], [b + 1, y + 1]);
      const rect = minAreaRect(hull(pts));
      if (Math.min(rect.w, rect.hgt) < DET.minSize) continue;
      const box = corners(rect);
      if (boxScore(prob, w, h, box) < DET.boxThresh) continue;
      // unclip (pyclipper offset of a rectangle by area * ratio / perimeter), then the minimum rectangle again
      const d = rect.w * rect.hgt * DET.unclipRatio / (2 * (rect.w + rect.hgt));
      const big = { cx: rect.cx, cy: rect.cy, w: rect.w + 2 * d, hgt: rect.hgt + 2 * d, a: rect.a };
      if (Math.min(big.w, big.hgt) < DET.minSize + 2) continue;
      boxes.push(corners(big).map(([x, y]) => [Math.max(0, x * scaleX), Math.max(0, y * scaleY)]));
    }
    return boxes;
  }
  // sorted_boxes: top to bottom, then left to right within 10 pixels of height.
  function sortBoxes(boxes) {
    const b = boxes.slice().sort((p, q) => p[0][1] - q[0][1] || p[0][0] - q[0][0]);
    for (let i = 0; i < b.length - 1; i++) for (let j = i; j >= 0; j--) {
      if (Math.abs(b[j + 1][0][1] - b[j][0][1]) < 10 && b[j + 1][0][0] < b[j][0][0]) { const t = b[j]; b[j] = b[j + 1]; b[j + 1] = t; } else break;
    }
    return b;
  }

  // get_rotate_crop_image: the box straightened into an upright strip (bilinear sampling).
  function crop(src, sw, sh, box) {
    const dist = (p, q) => Math.hypot(p[0] - q[0], p[1] - q[1]);
    const W = Math.max(1, Math.round(Math.max(dist(box[0], box[1]), dist(box[2], box[3]))));
    const H = Math.max(1, Math.round(Math.max(dist(box[0], box[3]), dist(box[1], box[2]))));
    const c = document.createElement('canvas'); c.width = W; c.height = H;
    const ctx = c.getContext('2d', { willReadFrequently: true }), out = ctx.createImageData(W, H), o = out.data;
    const [p0, p1, , p3] = box;
    for (let v = 0; v < H; v++) for (let u = 0; u < W; u++) {
      const fu = (u + 0.5) / W, fv = (v + 0.5) / H;
      const x = p0[0] + fu * (p1[0] - p0[0]) + fv * (p3[0] - p0[0]) - 0.5, y = p0[1] + fu * (p1[1] - p0[1]) + fv * (p3[1] - p0[1]) - 0.5;
      const x0 = Math.max(0, Math.min(sw - 1, Math.floor(x))), y0 = Math.max(0, Math.min(sh - 1, Math.floor(y)));
      const x1 = Math.min(sw - 1, x0 + 1), y1 = Math.min(sh - 1, y0 + 1), ax = Math.max(0, Math.min(1, x - x0)), ay = Math.max(0, Math.min(1, y - y0));
      const k = (v * W + u) * 4;
      for (let ch = 0; ch < 3; ch++) {
        const a = src[(y0 * sw + x0) * 4 + ch], b = src[(y0 * sw + x1) * 4 + ch], cc = src[(y1 * sw + x0) * 4 + ch], d = src[(y1 * sw + x1) * 4 + ch];
        o[k + ch] = (a * (1 - ax) + b * ax) * (1 - ay) + (cc * (1 - ax) + d * ax) * ay;
      }
      o[k + 3] = 255;
    }
    ctx.putImageData(out, 0, 0);
    if (H / W < 1.5) return c;
    const r = document.createElement('canvas'); r.width = H; r.height = W;   // tall boxes are turned (np.rot90)
    const rx = r.getContext('2d'); rx.translate(0, W); rx.rotate(-Math.PI / 2); rx.drawImage(c, 0, 0);
    return r;
  }

  // resize_norm_img for the recogniser: height 48, width in proportion (at least 320), padded with zeros.
  function recTensor(ort, strip) {
    const ratio = strip.width / strip.height;
    const tw = Math.min(REC.maxWidth, Math.max(REC.minWidth, Math.ceil(REC.height * ratio)));
    const rw = Math.min(tw, Math.max(1, Math.ceil(REC.height * ratio)));
    const px = resized(strip, rw, REC.height), n = tw * REC.height, data = new Float32Array(3 * n);
    for (let y = 0; y < REC.height; y++) for (let x = 0; x < rw; x++) {
      const k = (y * rw + x) * 4, i = y * tw + x;
      data[i] = (px[k + 2] / 255 - 0.5) / 0.5; data[n + i] = (px[k + 1] / 255 - 0.5) / 0.5; data[2 * n + i] = (px[k] / 255 - 0.5) / 0.5;
    }
    return new ort.Tensor('float32', data, [1, 3, REC.height, tw]);
  }
  // CTCLabelDecode: best class per step, repeats and blanks removed, the score is the mean of the kept probabilities.
  function ctc(out, chars) {
    const [, T, C] = out.dims, p = out.data;
    let text = '', sum = 0, n = 0, prev = -1;
    for (let t = 0; t < T; t++) {
      let best = 0, bp = -Infinity;
      for (let c = 0; c < C; c++) { const v = p[t * C + c]; if (v > bp) { bp = v; best = c; } }
      if (best !== 0 && best !== prev) { text += chars[best] || ''; sum += bp; n++; }
      prev = best;
    }
    return { text, score: n ? sum / n : 0 };
  }

  // Lines: boxes whose centres sit at the same height form one line, read left to right.
  function toLines(items) {
    const rows = [];
    for (const it of items.slice().sort((a, b) => a.cy - b.cy)) {
      const row = rows.find(r => Math.abs(r.cy - it.cy) < Math.max(4, 0.5 * Math.min(r.h, it.h)));
      if (row) { row.items.push(it); row.cy = (row.cy * (row.items.length - 1) + it.cy) / row.items.length; row.h = Math.max(row.h, it.h); }
      else rows.push({ cy: it.cy, h: it.h, items: [it] });
    }
    return rows.map(r => r.items.sort((a, b) => a.x - b.x).map(i => i.text).join(' '));
  }

  async function recognize(source, options = {}) {
    const onProgress = options.onProgress;
    const m = await load(onProgress);
    const canvas = toCanvas(source), sw = canvas.width, sh = canvas.height;
    const src = canvas.getContext('2d', { willReadFrequently: true }).getImageData(0, 0, sw, sh).data;
    progress(onProgress, 'detecting_text', 0.25, 'Finding text');
    const d = detTensor(m.ort, canvas);
    const dout = await m.detSession.run({ [m.detSession.inputNames[0]]: d.tensor });
    const prob = dout[m.detSession.outputNames[0]].data;
    const boxes = sortBoxes(detect(prob, d.w, d.h, sw / d.w, sh / d.h));
    const items = [];
    for (let i = 0; i < boxes.length; i++) {
      progress(onProgress, 'reading_text', 0.3 + 0.65 * i / Math.max(1, boxes.length), `Reading line ${i + 1} of ${boxes.length}`);
      const strip = crop(src, sw, sh, boxes[i]);
      const out = await m.recSession.run({ [m.recSession.inputNames[0]]: recTensor(m.ort, strip) });
      const r = ctc(out[m.recSession.outputNames[0]], m.chars);
      if (!r.text.trim() || r.score < REC.minScore) continue;
      const b = boxes[i], ys = b.map(p => p[1]);
      items.push({ text: r.text.trim(), score: r.score, box: b, x: Math.min(...b.map(p => p[0])), cy: (Math.min(...ys) + Math.max(...ys)) / 2, h: Math.max(...ys) - Math.min(...ys) });
    }
    progress(onProgress, 'reading_text', 1, 'Text read');
    return { text: toLines(items).join('\n'), lines: items.map(({ text, score, box }) => ({ text, score, box })), engine: 'paddleocr-ppocrv5-mobile' };
  }

  global.TeghPaddleOCR = Object.freeze({ recognize, load, root: ROOT, manifestSha256: MANIFEST_SHA256, internals: Object.freeze({ hull, minAreaRect, corners, ctc, sortBoxes }) });
})(window);
