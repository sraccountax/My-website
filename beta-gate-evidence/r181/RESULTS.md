# R181 results

**Package:** `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R181-PRIVATE-BETA.zip`

| Package detail | Value |
|---|---|
| SHA-256 | `da816a9726f8d3af940d8e97e18d89b8c1f4e4ba07cbe52c334978cca057e672` |
| Size | 26,839,223 bytes |
| Files | FILE-MANIFEST 425 entries, all verified on the gate host; 0 entries not 0644 |
| Source commit | `c4c8a56` (code `ff813bc`) |

Everything below ran on the gate test host with synthetic companies. Nothing was installed on IONOS staging or
production.

## 1. Canadian companies are unchanged (before/after comparison)

Method:

1. Before any R181 code was written, I copied the gate database with R180 installed after its full gate (68
   companies).
2. I recorded what the 62 Canadian companies show, two ways:
   - **Data:** 9 server requests per company (workspace, workspace summary, dashboard summary, profit and loss, balance
     sheet, trial balance, receivable and payable ageing, control balances).
   - **Screens:** the visible text of 17 screens for 3 companies.

   That gives 609 files.
3. I recorded R180 twice. The two captures were identical, after removing only timing fields and the order of rows the
   server returns unordered. So the comparison has no noise.
4. For each R181 run I restored the same database copy and the same pinned clock, captured again and compared.

| Run | Result |
|---|---|
| R181 code, switch **off** | **609 files identical** to R180. No R181 table or column was created |
| Final R181 ZIP as installed by the full gate, switch **off** | **609 files identical** to R180. No R181 table or column was created |
| R181 code, switch **on for everyone** | 65 differences, all expected: each company's workspace gains `organization.fx` (62 files; its rule is still "current", the R180 rule), and the Currency Exchange Rates screen shows the new layout (3 files). No figure, report or other screen changed |

Scripts and reports: `tests/r181-capture.mjs`, `tests/r181-compare.py`, `tests/run-compare.sh`, `comparison/`.

## 2. Full gate on this exact ZIP

Fresh install from the ZIP, every suite once, then the tail.

| Part | Result |
|---|---|
| All suites (81 result files) | **1017 PASS**, 13 INFO, 6 FAIL (the same six known failures as R177–R180) |
| R181 suite `55-r181` | **11/11 PASS** |
| R180 `54-r180`, R179 `53-r179` | 3/3, 13/13 PASS |
| Upgrade from R118 | 13/13 PASS |
| Screen matrix | 20 runs, 0 screens with issues |
| Phone swipe-trap scan (390 × 844) | 0 traps |
| Paths and headers | 45 PASS, 0 FAIL |

Known failures, unchanged:

| Check | Why |
|---|---|
| BS-07, BS-09 | Policy pages are not published on the reset test host |
| PW-01, WR-09 | Public-page wording checks, failing since R175 |
| DI-SCAN | An environment check of the old R144 reader |
| DD-01 | Top-bar click timing on this host |

## 3. What the R181 suite checks

Expected amounts are worked out in the test from the rates it records (amount × rate), not taken from Tegh.

| Check | Result |
|---|---|
| FX-01 | Switch off: an INR company outside Canada is refused, as in R180; sign-in data reports the switch off |
| FX-02 | Switch on for the owner: India company → INR, rates by date, Asia/Kolkata, 7 days. Canada + INR refused. JPY, KWD and IDR refused, each with its reason |
| FX-03 | 4 rates saved in one request. Refused: a future date, KWD (3 decimals), KRW at 0.004 (precision). Lookup for 28 Sep uses 26 Sep. Nothing within 7 days of 10 Sep |
| FX-04 | USD 1,000.00 invoice on 29 Sep → ₹88,400.00. On 28 Sep → ₹88,120.00 (26 Sep rate). On 10 Sep → not saved (`fx_rate_missing`), and no invoice row is created. A typed rate of 87 → asks first (409); confirmed → ₹87,000.00, with 1 audit entry holding both rates |
| FX-05 | Payment on 30 Sep at 88.52: bank +₹88,520.00; exchange gain ₹120.00 credited to 6850; every journal entry balances |
| FX-06 | USD statement: each transaction converted at its own date's rate (26, 29 and 30 Sep) |
| FX-07 | The base currency cannot change after the first transaction |
| FX-08 | A Canadian company with the switch on: rule still "current"; a USD draft invoice uses the typed rate exactly, as in R180 |
| FX-09 | A user of another company cannot read or record this company's rates (403), and nothing is written |
| FX-10 | Screens: India form defaults to INR; ₹ amounts on the dashboard; rate history shown; the invoice editor fills in 88.120000 for 28 Sep and clears the rate for 10 Sep, saying none is recorded; no page errors |
| FX-11 | With the switch off again, the India company keeps its INR books and its rates-by-date rule |

## Found during development and fixed before packaging

- **Sign-in data reported the switch off:** `auth/me` is answered before the currency module loads. It now reads the
  setting directly.
- **Invoice rate lookup failed:** it sent the query inside the route. It now uses parameters.
- **Rate not filled in on currency change:** the invoice editor redraws its currency field, so the listener now runs in
  the capture phase and waits for the redraw.
- **Rate overwritten on opening a document (design flaw):** an early version filled in the rate whenever a
  foreign-currency form opened. That would have replaced a deliberately confirmed rate when editing a document, so it
  was removed. Rates are filled in only when the user changes the currency or date.

## Not done

- Not installed on IONOS staging or production. With the switch on, the first use needs the ALTER privilege, as R137,
  R141 and R153 did.
- Limits are listed in `tegh-app/R181-CHANGES.md`:
  - base currencies without decimals, with 3 decimals, or high-denomination;
  - month-end revaluation;
  - downloading rates from a central bank;
  - Indian lakh grouping.
