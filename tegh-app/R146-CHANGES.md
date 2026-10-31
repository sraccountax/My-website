# R146 changes over R145: OCR clean-up fix (DEF-16)

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r146-tegh`. No database migration. No server code changed.
Status: **staging candidate**, `productionReady: false`. The host acceptance items are still open.

## DEF-16 (High, Document Intake)
R145 added an image clean-up step before OCR, which turns the page grey and stretches its contrast. On a clean scan with little text (most of the page white paper), both contrast points landed on the white background. Every slightly off-white pixel was then turned black, and OCR read nothing.

**Example:** a scanned courier invoice with six short lines. R145 read 0 of 6 fields; R146 reads all 6.

**Fix:** the contrast stretch is applied only to pages that are genuinely low-contrast (a 24–95 grey-level spread). Other pages are only turned grey. File: `assets/tegh-native-ocr-v5220.js`.

**Test:** DI-I7 in `16-r145.mjs`, a scanned bill that is mostly blank page. Across the seven test documents, Document Intake now reads 47 of 47 fields (R145: 41/47; R144: 19/47).

The same fix was made in the SR AccounTax website Bank Statement Converter v10, which received the R145 statement reader.
