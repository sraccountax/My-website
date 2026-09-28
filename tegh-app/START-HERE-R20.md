# Tegh R20 — start here

**5.9.9 / Build 5990 / Schema 46 — staging candidate, not a production seal.**

This is a cumulative upgrade from the exact R19 source archive with SHA-256:
`dc36578ce7c7f7c842aae5b66f3ef61b120e985dc33afcb958fc7e66f74cb77f`.

R20 adds monthly/comparative P&L and working invoice Edit, Send and Attachments workflows. Unlike R19, **R20 changes PHP and requires the protected Schema 46 upgrade**. Read `UPGRADE-SCHEMA-46-R20.md` before uploading or upgrading.

Only upload the application ZIP to the intended staging application root. Never upload the separate tests/evidence ZIP. Preserve private configuration and private document storage; this package contains neither customer data nor hosting/mail credentials.

Read `R20-CHANGES.md` for functionality, test boundaries and remaining release gates. Older R18/R19 notes retained in this cumulative archive are historical, not the current upgrade instructions.
