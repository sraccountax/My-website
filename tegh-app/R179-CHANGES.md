# R179 — PaddleOCR in Document Intake

R179 = R178 plus PaddleOCR for reading photos and scanned pages in Document Intake. Tesseract stays as the fallback.

## What changes for users

Document Intake (Payables › Document Intake) now reads receipt photos, phone pictures and scanned PDF pages with
PaddleOCR PP-OCRv5. It reads faint and low-contrast documents better. On the synthetic test set, the café receipt
photo that Tesseract misread (total 16.17) now reads correctly (9.00 + 1.17 = 10.17), and every field on all seven
test documents is right.

- PDFs that contain text are read as before (PDF.js), without OCR.
- If PaddleOCR cannot run (an older browser, a missing or changed file, any error), the page is read with Tesseract
  as before. The user sees no difference except the result.
- Everything runs in the browser. No image or text is sent to any outside service, and nothing new is stored.
- The first photo or scan in a session downloads about 27 MB (runtime and models), cached by the browser after that.
  A page takes about 3–11 seconds on the test machine.
- Bank statement reading and the Invoice Intelligence word positions still use Tesseract (no change).

## What was copied from PaddleOCR (github.com/PaddlePaddle/PaddleOCR, Apache License 2.0)

| From PaddleOCR | In Tegh |
|---|---|
| PP-OCRv5 mobile text detector (`PP-OCRv5_mobile_det`) | `assets/tegh-ocr/paddle-r179/ppocrv5-mobile-det.onnx` |
| PP-OCRv5 mobile Latin recogniser (`latin_PP-OCRv5_mobile_rec`, English and French) and its character list | `ppocrv5-mobile-rec-latin.onnx`, `ppocrv5-mobile-rec-latin-dict.json` |
| Pre- and post-processing: DetResizeForTest, NormalizeImage, DBPostProcess, sorted_boxes, get_rotate_crop_image, resize_norm_img, CTCLabelDecode | JavaScript port in `assets/tegh-paddle-ocr-r179.js` |

- The models are the official files from PaddlePaddle on Hugging Face (revisions recorded in `manifest.json` and
  `NOTICE.txt`), converted to ONNX with paddle2onnx 2.1.0 (PaddlePaddle 3.3.1, opset 14). The weights are unchanged.
  The ONNX outputs were compared with Paddle Inference on the same input: largest difference 2.0e-05 (detector) and
  1.3e-05 (recogniser), with the same characters chosen.
- PaddleOCR's Python runtime (PaddlePaddle) cannot run in a browser; the models run on ONNX Runtime Web 1.30.0
  (MIT licence), single-threaded WebAssembly from the site itself.

## Safety

- `manifest.json` lists the size and SHA-256 of every runtime file; the manifest's own SHA-256 is pinned in
  `tegh-paddle-ocr-r179.js`. A file that does not match is refused and Tesseract reads the page instead.
- OCR text is treated as untrusted, exactly as before: it only fills the review form, which the user checks before
  anything is posted.
- No change to the Content Security Policy (`'wasm-unsafe-eval'` was already allowed for Tesseract).

## Licences

`assets/tegh-ocr/paddle-r179/` contains `NOTICE.txt`, the PaddleOCR Apache 2.0 licence, the ONNX Runtime MIT licence
and its third-party notices. Help › Open Source / AI Licences now lists PaddleOCR, ONNX Runtime Web, Tesseract and
PDF.js with links to their licences, and no longer says that no third-party model weights are distributed.

## Files

| Area | Files |
|---|---|
| Browser | `assets/tegh-paddle-ocr-r179.js` (new); `assets/tegh-ocr/paddle-r179/` (new, about 27 MB); `assets/tegh-native-ocr-v5220.js`; `assets/tegh-portal-v5990.js` (licences page); `assets/tegh-gate-v5990.js`; `assets/tegh-preflight-v5990.js`; `app.html` |
| Server | `.htaccess`: `.onnx` served as `application/octet-stream`; long caching for the new runtime and model files |
| Database | No change |
| Cache token | `5990-r179-paddle` |

Upload check: the hosting account must accept files of about 14 MB (`ort-wasm-simd-threaded.wasm`). If any of these
files is missing on the server, Document Intake still works, with Tesseract.
