# R179 results

**Package:** `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R179-PRIVATE-BETA.zip`

| Package detail | Value |
|---|---|
| SHA-256 | `40fe1ab8a29b84f8459bfbddf64526d84c8b00127b67766dc4306f2b736e4171` |
| Size | 29,779,783 bytes |
| Files | FILE-MANIFEST 467 entries (all verified on the gate host); 0 entries not 0644 |
| Source commit | `9c69479` (code `6cf9bcc`) |

## Full gate on this exact ZIP

The gate host was reset with the ZIP (empty database, fresh install). Every suite then ran once, followed by the tail.
All of this was on the test host with synthetic companies. Nothing was installed on the IONOS staging or
production site, and nothing was tested there.

| Part | Result |
|---|---|
| All suites (79 result files) | **1001 PASS**, 13 INFO, 8 FAIL in the full run. Two of those (R-47, R-48) passed on the one re-run; see below |
| R179 suite `53-r179` | **13/13 PASS** |
| R178 suite `52-r178` | 8/8 PASS |
| Upgrade from R118 | 13/13 PASS |
| Screen matrix | 20 runs, 0 screens with issues |
| Phone swipe-trap scan (390 × 844) | 0 traps |
| Paths and headers | 45 PASS, 0 FAIL |

### What R179 changes in the existing results

**DI-I6 now passes.** DI-I6 is the low-contrast café receipt photo, and it failed on every gate run from R173 to R178.
PaddleOCR reads all six fields. Tesseract had read the total as 16.17 instead of 10.17.

### Known failures (unchanged from R177 and R178)

| Check | Why |
|---|---|
| BS-07, BS-09 | Policy pages are not published on the freshly reset test host. Publishing them in Site & Release Settings fixes these. |
| PW-01, WR-09 | Public-page wording checks that also failed on R175–R178. R179 does not change the public pages. |
| DI-SCAN | This check expects the old R144 reader to fail on a browser function (`getOrInsertComputed`). The test browser here does not reproduce that failure. It is not a reading-quality check; Document Intake read the scan correctly (PO-I4, PO-I7). |
| DD-01 | Top-bar click timing on this host; it fails the same way on R173. |

### R-47 and R-48 (Guided statements): failed once, passed on re-run

In the full run, R-47 timed out after 30 s waiting for the Guided mapping table. R-48 checks the state that R-47
leaves behind, so it failed with it. Both passed on R177. I re-ran suite `31-r163` once on the same install, after
restarting the host services (they had stopped while the container was idle). The re-run created fresh companies, as
every run does, and passed **46/46**, R-47 and R-48 included.

R179 does not change the Guided screens or statement import. The changed browser files are the OCR loader, the licences
page in the portal, and the cache token. So this looks like timing on the test host. It is recorded here rather than
dismissed. Evidence: `logs/31-r163-full-gate.json` and `logs/31-r163-rerun.json`.

## What the R179 suite checks (`tests/53-r179.mjs`)

| Check | Result |
|---|---|
| PO-01 | The 6 runtime and model files match the manifest's size and SHA-256; the manifest matches the hash pinned in the code; the 4 licence notices are installed |
| PO-I1 – PO-I7 | All 7 synthetic documents, every expected field right (47 fields). The 2 text PDFs use PDF text. Each of the 5 photos and scans is read by PaddleOCR in about 3–11 s |
| PO-LOCAL | Reading all 7 documents made 40 requests, all to the site itself, none elsewhere |
| PO-FALLBACK | With the PaddleOCR files blocked, Tesseract reads the receipts and every field is right |
| PO-TAMPER | One changed byte in the recogniser model: the file is refused (`paddle_integrity_failed`) and Tesseract reads the page |
| PO-UI | Payables › Document Intake: uploading the café receipt photo fills in MAPLE LEAF CAFE, 7781, 2026-08-21, 9.00 + 1.17 = 10.17 ✓ (`screens/r179-intake-cafe.png`) |
| PO-LIC | Help › Open Source / AI Licences lists PaddleOCR, ONNX Runtime Web, Tesseract and PDF.js; all 7 licence links open |

## Model conversion (checked before packaging, on this machine)

The detector and Latin recogniser are the official PaddlePaddle files from Hugging Face. They were converted with
paddle2onnx 2.1.0 (PaddlePaddle 3.3.1, opset 14) and compared with Paddle Inference on the same input. The largest
difference was 2.0e-05 for the detector and 1.3e-05 for the recogniser, and the recogniser chose the same characters.
Revisions and source-file hashes are in `tegh-app/assets/tegh-ocr/paddle-r179/manifest.json`.

## Not done

- Not installed on IONOS staging or production. Before staging, confirm the host accepts the 14 MB
  `ort-wasm-simd-threaded.wasm` and serves `.onnx` files (the R179 `.htaccess` sets the type). Then check that Document
  Intake reports PaddleOCR on a receipt photo. If those files are missing, Document Intake still works, with Tesseract.
- Tested only in the gate host's Chromium. Safari and Firefox were not tested. Any browser that cannot run PaddleOCR
  falls back to Tesseract.
