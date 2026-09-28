# Tegh 5.9.9 Build 5990 Schema 45 — R12 runtime candidate

R12 contains the final confirmation-layer corrections found during authenticated R11 staging QA:

- Action confirmations now render above every existing Tegh modal, including the full-screen bank-statement verification preview, so Continue and Cancel are visible and pointer-accessible.
- Escape handling is installed at the window capture boundary. Dismissing an action confirmation no longer lets an older underlying modal process the same key event.
- Bank-statement duplicate and missing-balance acknowledgements use Tegh's awaited confirmation API directly. They no longer depend on replaying a click while the verification preview is busy.
- The active asset cache revision is `5990-r12`, ensuring overwritten staging files are fetched immediately.

The R11 Native Agent lease repair and no-horizontal-scroll rules remain unchanged. No Schema 45 migration file or checksum changed. The release remains HOLD / NOT SEALED until this exact ZIP passes the complete disposable runtime lab and authenticated IONOS replay.
