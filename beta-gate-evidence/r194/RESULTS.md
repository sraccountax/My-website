# R194: Tegh mark and Sign out on the first-company screen; Invoice Intelligence job list: results

Package: `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R194-PRIVATE-BETA.zip`, SHA-256 `8871ee0bb525c206c564ff44c56db1233397a98f990ecc9dd0d203c440f8a1aa`, 450 files. Release token `5990-r194-first-signout`.

**Where this was run:** everything here was run on the test server (gate.test) with synthetic users and companies. **Nothing was run on the IONOS server or on books-test.sraccountax.ca.**

The changes are described in `tegh-app/R194-CHANGES.md`.

## 1. What was reported, and what was found
**The empty green square beside "Tegh":** the mark on the sign-in and first-company screens still held two letters of a retired mark, which a later stylesheet hides. It now shows the Tegh T (`tegh-mark.svg`), as the sidebar does.

**No way to leave the first-company screen:** it now shows "Signed in as <email>" and **Sign out** above:
- the Guided/Full choice;
- the Canadian company form;
- the India company form.

**The Canadian form after an India sign-in (previous screenshot):** not reproduced on R193 or R194. An India sign-in with no India company gets the India form in every browser state tried:
- a fresh browser;
- one remembering a Canadian company;
- one with Full Accounting saved;
- one with all of these.

Your screenshot also lacks the onboarding bar ("Onboarding · Step 1 of 6") that R157 and later put above the Canadian form. That points to the server running an older package, or the browser using a cached older gate script.

**Also fixed: the Invoice Intelligence job list.** It answered HTTP 500 once in the R193 gate: expiring stale job leases deadlocked with a worker. It now retries once, then lists without expiring.

## 2. Full gate (automated, fresh install of this exact ZIP)
| Result | Count |
|---|---|
| PASS | 1223 |
| INFO | 13 |
| FAIL | 6 |

**The six failures** are the known ones (since R186): BS-07, BS-09, PW-01, WR-09, DI-SCAN and DD-01.

**Other checks:**
- the reset ran completely: identity recorded, FILE-MANIFEST 449 entries OK;
- ZIP entries: all 0644;
- path and header checks: 45 PASS, 0 FAIL;
- E2E: 53/53;
- the 20 UI runs: no screens with issues.

**Suites:**

| Suite | Result |
|---|---|
| `72-r194` (new) | 5/5 |
| `71-r193` | 7/7 |
| `69-in7` (India first company) | 7/7 |
| `45-r170` (Invoice Intelligence) | 57 PASS, 6 INFO (accuracy measurements) |
| `25-r157` (onboarding and first company) | passed |

**`72-r194` checks:**
- **UI-01 (Canada, no company):** the T mark shows, and the Guided/Full choice shows Signed in as and Sign out, at 1440 px and 390 px, with no overflow. Sign out ends the session (`auth/me` 401) and returns to sign-in.
- **UI-02 (Canada):** after Full Accounting, the Canadian company form keeps Sign out, and Sign out from it works.
- **UI-03 (India, no India company):** the India form shows Sign out at 1440 px and 390 px, and Sign out works.
- **R194-01:** signing out created no company.
- **R194-02:** the job list answers (200) and puts a job whose lease ran out back in the queue.

**Not tested:** the deadlock retry itself is checked by code review only: a deadlock cannot be produced on demand.

Screenshots are in `shots/`.
