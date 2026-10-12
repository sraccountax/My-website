# Tegh security review (white-box) + hardening notes

_Covers the R163 code and, by request, the auth/session layer, the client-viewing links and the payroll module._

Scope: the R163 code (`api/guided_r163.php`, the touched parts of `api/accounting.php`,
`api/imports.php`, `api/reconciliation_position_r122.php`, and the Guided client in
`assets/tegh-portal-v5990.js`), reviewed against the full source. This is a source review,
not a live test of any host. Live testing of an internet-facing host
(`books-test.sraccountax.ca`) needs the host owner's written authorization and should be
run from an environment that owner controls — it is out of scope here.

## Method
Static review of the new code for the web-app classes that matter most for a bookkeeping
app that moves money and holds third-party financial data: SQL injection, broken
authorization / IDOR across companies, CSRF, stored/reflected XSS, email-header injection,
and business-logic integrity (can a client post or reconcile something the server should
refuse). Each control below is also exercised by an adversarial regression test,
`32-r163-sec.mjs`, run against the isolated gate host.

## Findings
No vulnerabilities found in the R163 code. The controls below were confirmed present.

| # | Class | What was checked | Result |
|---|---|---|---|
| 1 | SQL injection | Every query in the R163 files; IN-lists | All parameterized. `IN (...)` lists use `implode(',', array_fill(0, n, '?'))` with bound values (`guided_placeholders()` and the equivalents in `accounting.php`). No user input is concatenated into any SQL string. |
| 2 | Authorization / IDOR | Every route in `handle_guided_r163()` | Each requires `require_user` → `require_company` → a role and/or `banking.*` permission. Writes additionally require `owner`/`bookkeeper` + `banking.match`. |
| 3 | Cross-company IDOR | `monthId`, `batchId`, `previewId`, `groupKey` | All resolved through `guided_month_row($companyId, $id)` / company-scoped lookups. A month, import, or preview from another company returns 404. (Gate R-14.) |
| 4 | CSRF | State-changing routes | `require_csrf()` on every POST (`check`, `attach`, `remove`, `post-group`, `post-lines`, `reconcile`, `accountant/request`). GET routes are read-only. |
| 5 | Email-header injection | `guided_accountant_request()` → `sr_mail_send()` | Subject (which embeds the user-controlled company name) passes `portal_header_value()`, which replaces `\r` and `\n` with spaces. Recipient is config-controlled and passes `safe_email()`. The note goes in the body only, after the header block. |
| 6 | Stored/reflected XSS | Guided statement, mapping, resume, accountant, toast | All user-controlled strings (line descriptions, keywords, examples, the covered-by label, finding titles/details, bank-account names) are rendered through `esc()`. `toast()` also escapes its title and message. |
| 7 | SSRF / open redirect | The Outlook draft URL | Built client-side with `URLSearchParams` / `encodeURIComponent`; the recipient is fixed to the configured address. No server request is made to a user-supplied URL. |
| 8 | Business-logic integrity | Posting, reconciling | Posting goes through `bank_transaction_post_service` (period locks, company scope). A deposit for a registrant with no tax choice is refused server-side (`guided_tax_required`), not only in the UI. A month reconciles only when the statement's closing balance equals Tegh's computed balance; the client cannot mark a month reconciled by itself. |
| 9 | Rate-limiting / abuse | `accountant/request` | Capped at 5 per company per hour (server-side count), so the outbound email path can't be used as a spam relay. |
| 10 | Secrets exposure | Accountant rate/hours | The hourly rate and the minutes per finding stay on the server; the API response, the client and the email draft carry only the findings and the total (gate R-18, R-29). |

## Tegh-wide hardening checklist (for whoever operates the host)
These are operational controls the application code can't set for you. Run them on the beta
host (for example `books-test.sraccountax.ca`) yourself — they need no attack, just
configuration review.

1. **Transport.** Force HTTPS (the app already redirects any host to HTTPS); enable HSTS at
   the web server; disable TLS 1.0/1.1; test with an SSL grading tool.
2. **Response headers.** Set `Content-Security-Policy` (the app is same-origin, so a strict
   policy is feasible), `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY` (or
   `frame-ancestors 'none'`), and `Referrer-Policy: strict-origin-when-cross-origin`.
3. **`config.php`.** Keep it outside the web root (deployment notes already say so). Confirm
   `/api/config.php` and `/api/*.php` source are not downloadable. Use a long random
   `app.secret` and a separate `app.setup_key`; set `accountant.request_email` to a mailbox
   you read.
4. **Database account.** The app needs `SELECT/INSERT/UPDATE/DELETE` and, for first-use table
   creation, `CREATE/ALTER`. It does **not** need `FILE`, `DROP` of other schemas, or
   `GRANT`. Scope the DB user to the one database.
5. **Uploads.** Statement/receipt uploads are stored outside the web root, each under its
   company (gate BS-02/03). Confirm the upload directory is non-executable and not served.
6. **Rate limits at the edge.** The app throttles sign-in, invitations and accountant
   requests; add a WAF or reverse-proxy rate limit on `/api/auth/*` as defence in depth.
7. **Mail.** Set SPF, DKIM and DMARC for the sending domain so the accountant-request and
   invitation emails are not spoofable and are delivered.
8. **Backups.** The backup encrypts an off-site copy (gate F1–F3); keep the age/recipient
   key off the app host.
9. **Monitoring.** `system-incidents.ndjson` records server errors (it already captured the
   lock-timeout during the gate run). Ship it somewhere and alert on `critical`.

## Auth / session (reviewed on request)
No vulnerabilities found. Confirmed, each exercised by `33-sec.mjs` (AS-01…05):

| Control | Detail |
|---|---|
| Session cookie flags | `Secure` + `HttpOnly` + `SameSite=Strict` on the session cookie; the CSRF cookie is the double-submit pattern (`Secure` + `SameSite=Strict`, readable by JS by design). (AS-01) |
| Token strength | Session and CSRF tokens are `base64url_encode(random_bytes(32))` — 256-bit CSPRNG. |
| Tokens at rest | Stored as HMAC-SHA256 (`secret_hash`), never plaintext; the raw cookie value does not appear in the `sessions` table. (AS-05) |
| Passwords | `password_hash` / `password_verify`, with `password_needs_rehash` upgrading on login. |
| Wrong password | Refused, no session cookie issued; no user enumeration from the message. (AS-02) |
| Forged cookie | A garbage session cookie is unauthenticated (401), not honoured. (AS-03) |
| Logout | Deletes the session row server-side; the old cookie stops working. (AS-04) |
| Session fixation | A fresh token is minted at login; no caller-supplied session id is accepted. |
| Throttling | `login_attempts` with a 15-minute window and an adaptive CAPTCHA; the setup key uses `hash_equals`. |

## Client-viewing links (reviewed on request)
No vulnerabilities found. Confirmed, each exercised by `33-sec.mjs` (CV2-01…04) and the existing `07-cv`:

| Control | Detail |
|---|---|
| Link token | 256-bit CSPRNG, stored hashed; delivered only in the URL fragment. |
| Email code | 6 digits, 10-minute TTL, **5-attempt lock** (6th → 429), single-use, `hash_equals` compare; 5 codes/hour. 5 guesses per 10 min against 10^6 is infeasible. (CV2-02) |
| Report scope | Each view/download is checked against the link's stored allowlist (`in_array($key, $allowed)` → 403 otherwise); payroll and audit are never offerable. (CV2-01) |
| Not a user session | A client-view cookie cannot call any authenticated company API — 401. (CV2-03) |
| Revocation / expiry | A revoked or expired link is refused (410). (CV2-04) |
| Management | Creating, revoking and reissuing links is owner-only; reissue mints a new token (old URL dies). |
| Isolation | The company and actor are derived from the validated link, so no cross-company reach. |

## Payroll (reviewed on request)
No vulnerabilities found. Confirmed, each exercised by `33-sec.mjs` (PY2-01…03) and the existing `08-pay`:

| Control | Detail |
|---|---|
| No SINs | SIN input is refused (422), including SIN-shaped values detected by checksum across any field; legacy stored SINs are purged. (PY2-01) |
| Authorization | Every write route requires CSRF + role (owner / owner+bookkeeper); sensitive actions add `payroll.manage`. |
| Finalize review gate | The server requires `reviewConfirmed === true` (422 otherwise) — not just a UI checkbox. (PY2-02) |
| Tamper-proof amounts | Each item's `locked_hash` is re-checked with `hash_equals` at finalize; altered amounts abort the posting. |
| Integrity checks | Every item needs a verified income-tax figure; the rate-snapshot digest and the year-to-date are checked for staleness; `FOR UPDATE` locks prevent double-finalize. |
| Cross-company | A pay run from another company cannot be finalized from this one (404). (PY2-03) |

## What a live test would add, and how to authorize it
A black-box test of the running host would add: real TLS/header verification, auth
brute-force and lockout behaviour under load, file-upload content checks, and session-cookie
flags as actually served. To run that safely: have the host owner put it in writing (scope,
window, methods), run it from an environment the owner controls, and use synthetic accounts
and data only. This review covers the application logic; the host checklist above covers the
deployment; the live test closes the loop on the running configuration.
