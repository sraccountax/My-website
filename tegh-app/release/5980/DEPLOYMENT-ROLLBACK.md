# Tegh 5.9.8 — isolated staging and rollback checklist

**HOLD / NOT SEALED. Do not overwrite production.** This checklist is based on the supplied PHP application/configuration, not an inspection of your actual IONOS account.

## Stage1: establish a genuinely isolated environment

1. Create a separate staging document root and a separate empty/test database with credentials unable to access the live database. A host name containing “test” is not evidence of isolation.
2. Back up the existing application, complete database, private configuration, retained documents/storage and keys together. Verify the backup can be read. Keep production untouched.
3. Record web-serving PHP version/extensions separately from CLI PHP. Required core paths use PDO MySQL, mbstring, ZipArchive, DOM/XML and OpenSSL. Verify supported database engine, strict SQL settings, InnoDB, charset/collation and actual upload/body/time/memory limits. Run the private CLI readiness probe from the evidence bundle; exit77 means blocked, not pass. It does not connect to a database.
4. Confirm staging HTTPS and configure the private `app.base_url` to that exact staging host. Preserve `app.secret` for a retained database; changing it invalidates existing encrypted/HMAC data and tokens. For a brand-new isolated environment use new private credentials/secret. Never post secrets in chat or put completed config in public web files.
5. Point all email destinations to a controlled sink before tests, disable outbound customer/payment/filing integrations and keep public signup emergency force-off false. The connected-provider rollout remains hard-disabled even if a fake provider key is present. Confirm filesystem storage is private and not a copy of accessible customer documents.
6. Extract the cumulative ZIP to a new empty staging root. It is flat: app.html, api and assets are at the top level. Do not copy the verification ZIP into web space. Compare SHA-256 and every FILE-MANIFEST entry. Do not assume predecessor website files remain.
7. Supply private config outside the web root, using the application's supported `SR_ACCOUNTAX_CONFIG` or private sibling directory. Verify permissions and .htaccess protections with the actual web server. Existing `config.example.php` is a template only, never a working secret-bearing configuration.

## Stage2: upgrade and actual application verification

8. Open the staging app as Platform Owner. Confirm visible/bootstrap/API identity5.9.8/5980/44. Invalidate PHP OPcache/web caches through an authorized host operation if stale code remains; do not delete a live database to fix a cached HTML version.
9. Inspect protected schema preflight. For valid44, confirm no migration/data step is requested. For43, confirm paired backup then use the protected upgrader; never import schema.sql over retained tables. Do not edit the numeric schema marker. Re-run preflight and migration safely, then compare ledger history counts and entitlement revisions to prove idempotency.
10. On disposable clones, test interrupted/partial44 recovery, missing columns/indexes/FKs and backup restoration. Failed readiness must not be relabelled successful. InnoDB transaction tests, true concurrent clients and HTTP response-loss tests are required; source lint cannot substitute.
11. Create fictional companiesA/B plus suspended membershipC. Exercise all transfer directions/cards, category workbook round-trip, exact before/after counts, tax preservation, status/period locks, cross-company ID/token/account/CSRF attempts and lost-response retries. For category-only commit prove journal/voucher/line counts and tax/source evidence unchanged.
12. Reconcile all33 registered reports and applicable variants against independent expected SQL fixtures. Include opening cutover, advances before application, direct settlement, FX carrying values, reversals, cash/accrual, control exceptions, empty/unicode/large datasets and25000/25001limits. Test all cached/legacy output URLs against the same model. Do not accept a report solely because it renders.
13. Run all authenticated importer entry points with the legacy-navigator spy, supported CSV/XLS/XLSX/PDF/OFX/QFX corpus, malformed/duplicate/evidence/date ambiguities and complete preview export selection equality. Test actual1366/1440/1920screens, rails,100/125/150/200%native zoom, mobile, keyboard/focus/Escape and accessibility scan/screen reader. The supplied narrow fixtures are not native-zoom proof.
14. Verify invitation-only/public registration at the server and pre-paint CTA; invite click/Enter, one/multi-company permissions, existing/new account password flow, terms versions, rate limits, malformed/replayed/revoked/expired tokens, concurrent accept and inviter loss of authority. Invitation acceptance must work while public signup is off.
15. Verify actual SMTP TLS/STARTTLS, sender/reply-to and SPF/DKIM/DMARC Authentication-Results using a controlled inbox. Test Sent/Sent with warning/Deferred/Manual review/Failed. Interrupted sending must not silently resend. DNS-record presence alone is insufficient evidence of delivery.
16. Test deletion transitions, replacement exclusion of suspended/revoked users, last-company state, stale gate cache, late fetches, cross-tab storage and Back/BFCache. Failed/unknown deletion must preserve current selection. Perform only on fictional disposable companies.
17. Open generated workbooks in the actual supported Excel application and verify no repair warning, typed dates/money, protected/editable ranges, frozen/filterable headers and formula-injection resistance. Review PDF/print pagination and totals. Non-Western glyph fallback is searchable but the output is not tagged PDF/UA.

## Stage3: independent review and sealing

18. Give a separate qualified accountant the expected and actual journals/reports, assumptions and exception logs. Obtain explicit review of all financial invariants. Obtain separate security review of authorization, tokens, outbox, provider boundary, state transitions and sensitive evidence. Record reviewer identity/date/scope/findings.
19. Correct defects found in staging/review and rerun affected/regression gates. Only when every required gate has genuinely passed, create a sealed release record referencing its exact source/tests and regenerate the archive/manifest/checksum. Do not merely rename this HOLD ZIP.

## Rollback and irreversible-data warning

**Before any schema upgrade or staging writes:** the prior matching application and database backup can be restored together. A clean5980-to5970file rollback on unchanged valid44may be structurally compatible, but it restores the known defects; do not use it as production approval.

**After any upgrade, invitation acceptance, entitlement migration, category decision, deletion or posting:** preserve diagnostics, stop further writes and restore the paired application+database+private storage snapshot to an isolated location first. Verify IDs, counts, balances, token/outbox state and required secret. Restore the approved snapshot through authorized host controls. Do not DROP newly populated tables, run reverse destructive SQL, set the schema marker backwards or place an older PHP app over a newer active database and assume correctness.

Restoring a backup discards changes after that snapshot and may restore previously consumed invitation tokens or operation receipts. Keep mail and integrations disabled during recovery; review/revoke affected pending links and sessions through authorized workflows before reopening. Never resend queued mail blindly after restore. Production cutover and rollback are not performed by this candidate package.
