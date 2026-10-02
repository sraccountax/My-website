# Audit: Tegh-Updated-Package.zip ("Tegh-5_9_9-R146-Marketing-Aligned-STAGING")

Audited 2026-10-02 on the gate host (Apache 2.4 + PHP 8.3 HTTPS, fresh MariaDB, STARTTLS mail sandbox).

| | |
|---|---|
| Uploaded ZIP SHA-256 | `6fb6db080d865f1d37459a238523542146dce4f8b930e1453d094ea65c8628dd` |
| Based on | R146 (`9a2fa4ae…7f82a`), as its `marketingUpdate` block states |
| FILE-MANIFEST.sha256 | 349 entries, all OK; the file list matches the package exactly |
| productionReady / acceptanceComplete | false / false (unchanged, correctly) |

**Verdict: do not deploy this ZIP as packaged.** Its content is sound, but every file in it is mode `0600`. Use the permission-corrected copy instead: identical content, SHA-256 `ccf584d1bf86c1703c5470686d20628ab300d6da7678ccc6e4997e79511a3b48`. Before going live, also get owner/legal sign-off on the revised Privacy and Terms.

## 1. What changed compared with R146
- **Application code: unchanged.** `api/`, `assets/`, `app.html`, `client-view.html` and `.htaccess` are byte-identical to R146.
- **Pages changed (17):** `index`, `product`, `ask-tegh`, `solutions`, `subscriptions`, `resources`, `company`, `security`, `contact`, `migration`, `privacy`, `terms`, `404`, and the four guides.
- **Manifests:** `RELEASE-MANIFEST.json`, `PACKAGE-MANIFEST.json` and `tegh-build.json` change only `package`/`packageFile` and add a `marketingUpdate` block. That block correctly says the app tests were inherited, not re-run, and that browser rendering was not checked.
- **Release identity:** still R146 with cache token `5990-r146-tegh`. That is correct, because no asset changed.

## 2. Findings
| ID | Severity | Finding | Evidence | Fix |
|---|---|---|---|---|
| AUD-1 | **Blocker** | All 350 ZIP entries are `-rw-------` (0600); R146 was 0644. Unpacked over an existing install, as a host's "extract" does, Apache cannot read `.htaccess` or any file. Every page, asset and API call returns **403**. | Upgrade test UP-01 failed with a login 403. Reproduced directly: `/`, `/index.html`, `/app.html`, `/assets/tegh-portal-v5990.js` and `/api/index.php?route=health` all return 403. Apache logs `AH00529 … Permission denied` (`as-shipped-403-apache-error.txt`). | Rebuilt the ZIP with 644 files and 755 folders, content unchanged. The R118 → package upgrade then passed **13/13**. |
| AUD-2 | Medium (process) | `privacy.html` and `terms.html` are legal documents. They now carry version 2026-10-01 and new sections (payroll and SIN scope, client viewing links, built-in intelligence, assistance limits). | Text diff below. The wording matches the product. | Owner/legal approval before invitations; record it with the other owner-review items. |
| AUD-3 | Low (docs) | README and DEPLOYMENT-NOTES do not mention the marketing update. There is no change-notes file for it. The manifests' top-level `testStatus` and `executedLocalGates` still describe the R146 run, as the `marketingUpdate` block discloses. | — | Add a short note, or rely on this report. |
| AUD-4 | Low (cosmetic) | On phones, some new product-page cards (for example "Tegh brief") keep a fixed height, leaving empty space under short text. | Phone screenshots of the product page. | Optional CSS tweak. |

No security, accuracy or broken-link defects were found in the changed pages.

## 3. Content accuracy (every new claim checked against the code)

**Features and screens**

| Claim | Result |
|---|---|
| Credit/debit notes; combined invoice and note registers; note PDFs | Present (R120–R135) |
| Over-application of credits blocked | Present: server rejects amounts above the remaining balance |
| Bank suggestions with confidence and reason; matching to existing payments | Present (R143/R144) |
| Statement converter balance checks; scanned statements need CSV/OFX | Matches R145 |
| Document Intake local extraction; French labels; English OCR model | Matches R145/R146 |
| Screens named: Cash Forecast, GIFI, Agent Centre, Requested Downloads, Collection Drafts, fixed assets, budgets, recurring entries, expense vouchers, period locks, Dashboard Figures, temporary support access | All present |

**Payroll**

| Claim | Result |
|---|---|
| Canada outside Quebec | Matches R142 |
| No SIN storage (R130) | Matches |
| No direct deposit, ROE, T4/XML or CRA transmission | No such code exists |

**Client viewing links**

| Claim | Result |
|---|---|
| Link lifetime 7 days to 1 year | Server allows 7, 30, 90, 180 or 365 days |
| Shareable reports | Server catalogue matches the list on the page |
| Payroll, audit history and working papers not shareable | Matches |

**Tegh Intelligence**

| Claim | Result |
|---|---|
| No external AI | Matches R144; test IN-64 found no outbound calls |
| Findings listed | Match `insights_r144.php` |
| Who to chase first: amount, days late and payment habits | Matches |

**Ask Tegh examples, asked live on a seeded company**

| Question | What Tegh Assist did |
|---|---|
| "Who owes me money?" | Receivable Ageing: $3,649.00, of which $2,599.00 is overdue |
| "How much did I spend on travel last quarter?" | Answered from the books for Q3 2026 |
| "Anything unusual?" | Opened Tegh Intelligence |
| "am I making money this year?" | Profit $1,825.00 |
| "what bills are due this week?" | 1 bill, $226.00 |
| "upload my bank statement" | Opened Upload Statement |
| "Create a customer invoice." | Opened New Invoice |

All seven behave as the page describes, with no JavaScript errors.

**Technical changes**

| Change | Result |
|---|---|
| `forceSignIn` removed from guide Sign In links | No code reads it, so the behaviour is unchanged and now consistent with the other pages |
| New contact topics | Server accepts free text up to 120 characters |

## 4. Static and browser checks
- 20 HTML pages:
  - every internal link and `#anchor` resolves;
  - all JSON-LD parses;
  - every public page has a title and meta description.
- No external or tracking scripts, and no new inline scripts, so the Content-Security-Policy needs no change. The scripts added to the guides are JSON-LD data blocks.
- 17 public pages rendered at 1440×900 and 390×844: all return 200, with **0 console/CSP errors**, **0 horizontal overflow** and **0 failed requests**. 17 distinct internal link targets: 0 bad.

## 5. Full gate on the exact uploaded ZIP

| Run | Result |
|---|---|
| Full suite | **402 PASS / 3 FAIL / 7 INFO** |
| Paths and headers | 40/40 |
| UI matrix | 0 screens with issues |
| Swipe traps | 0 |

The gate's own install step resets permissions to 644, which is why AUD-1 shows up only in the upgrade step.

**The three FAILs (DI-UI1, DI-UI2, DI-UI3)** are 30-second click timeouts on the Document Intake screen in the full run. The application code is byte-identical to R146, which passed these tests. A rerun of `16-r145.mjs` on the same install passed **45/45**, including all three, plus 1 INFO.

They are recorded as FAIL for the full run. They were not reproducible, and no package change can explain them. The Document Intake screen test should be made more robust against timing. They were **not** counted as passed in the totals above.

**Upgrade R118 → this ZIP**

| Package | Result |
|---|---|
| As shipped | FAIL, AUD-1 |
| Permission-corrected copy | **13/13 PASS** |

Evidence is in this folder:
- gate summary and test matrix;
- `mkt-check.json`;
- the R145 full-run and rerun results;
- the Apache 403 log lines.

Scripts: `beta-gate-evidence/scripts/mkt-check.mjs` and `mkt-assist.mjs`.
