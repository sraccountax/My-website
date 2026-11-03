# R181 — Books in any currency, with recorded exchange rates

**Goal:** anyone in the world can keep a company's books in their own currency, for example INR. When a document is in
another currency (an invoice in USD or EUR), Tegh converts it at the exchange rate **recorded in Tegh for that date**,
and refuses to guess when no rate is recorded.

## What exists today (R180, from the source and the installed database)

- **Foreign amounts are already stored.** Every invoice, bill, expense, credit/debit note, payment and bank
  transaction stores `currency`, `exchange_rate_micros` and both foreign and base amounts. Realised exchange
  differences on payments post to account 6850 (Foreign Exchange Gain / Loss).
- **Base currency is fixed to CAD:** company setup refuses any other base currency (`companies.php:115`, `:282`).
- **Only 7 currencies are accepted:** CAD, USD, EUR, GBP, AUD, NZD, INR (`books.php`). The rates screen also offers
  JPY and CHF, which the server then rejects (an existing bug).
- **One rate per currency, with no history** (`company_currencies`). Every new document defaults to that one rate,
  whatever the document's date.
- **"Today" is Canadian time** (`canadian_today()`). A company in India recording the morning's rate would be told the
  date is "in the future".
- **About 120 browser places fall back to `'CAD'`.** `money()` defaults to CAD.
- **Conversion** multiplies amount × rate in PHP integers and can overflow for large amounts at large rates. Today's
  CAD rates never reach that.

## What R181 changes

1. **Release switch:** "Worldwide currencies" in Site & Release Settings, with off / platform owner only / everyone.
   **Off by default:** with it off, Tegh behaves exactly as R180.
2. **Choose the base currency at company creation** (when the switch allows it):
   - Any ISO 4217 currency with 2 decimal places that fits Tegh's amount limits (see Limits).
   - The default comes from the country (India → INR, United States → USD, a euro country → EUR, …).
   - **Canadian companies stay in CAD**, because GST/HST returns and Canadian payroll are in CAD.
   - The base currency **cannot be changed once the company has any transaction**, so books are never converted.
3. **Transaction currencies:** the full current ISO 4217 list (official list published 17 September 2026), minus the
   3-decimal currencies. That gives 148 currencies with their names.
4. **Exchange rates by date:**
   - New table `company_exchange_rates`: one rate per company, currency and date (1 unit of foreign currency = X base),
     with who recorded it and when.
   - Correcting a rate is recorded in the audit trail.
   - The rates screen shows the history per currency. Several dates can be entered at once by pasting
     `date, currency, rate` lines.
5. **The rate check on every foreign-currency document**, applied by the server at all 10 entry points (invoices,
   bills, expenses, credit/debit notes, customer and vendor payments, refunds, recurring invoices, bank statement
   import):
   - Tegh uses the rate recorded for the document's date.
   - If that date has none (a weekend or holiday), it uses the most recent earlier rate, up to **7 days** old (company
     setting, 0–31; 0 means the exact date only). The document shows which date's rate was used.
   - If no rate qualifies, the document is **not saved**. The message says which currency and date need a rate.
   - If the user types a different rate (for example the bank's actual rate), Tegh shows the recorded rate and asks
     for confirmation. The rate used and the recorded rate go into the audit trail.
   - Forms fill in the recorded rate for the chosen date and currency automatically.
6. **Per-company rate rule:**
   - "Use rates by date" is on for companies created while the switch is on.
   - Existing companies keep today's single-rate behaviour until their owner turns it on. Turning it on copies the
     current rates into the history at their dates.
7. **Base currency everywhere:** amounts, labels ("The General Ledger is in INR"), exports and PDFs use the
   company's base currency. For a Canadian company that is still CAD.
8. **Exact conversion:** amount × rate is calculated without overflow. Results for amounts that convert today are
   unchanged.
9. **Company time zone:** "today" for rate dates uses the company's time zone; Canadian companies keep Toronto time.

## Limits in R181 (stated, not hidden)

- **Not available as a base currency yet:**
  - currencies without decimal places (JPY, KRW, VND, CLP, ISK, …);
  - currencies with 3 decimal places (KWD, BHD, OMR, JOD, TND, LYD, IQD);
  - high-denomination currencies where Tegh's per-entry limit of 1,000,000,000 units is too small (IDR, IRR, LAK, UZS,
    COP, LBP, SYP, MMK, KHR, MNT, TZS, MGA, CDF, ARS, NGN, MWK, SSP).

  Each needs either different amount precision or larger limits. That is planned as a separate release.
- **Precision:** rates are stored to 6 decimal places. A pair whose rate is below 0.005 (for example KRW into USD)
  would lose accuracy, so it is refused with an explanation.
- **3-decimal currencies:** not offered as transaction currencies, because amounts are stored in hundredths.
- **Revaluation:** month-end revaluation of open foreign balances (unrealised gain/loss) is not in R181. Realised
  gain/loss on payment already works.
- **Rates are entered or pasted by the user.** Downloading official rates (for example from a central bank) is a later
  option.

## Safety (as agreed)

1. **Before code changes:** capture every Canadian screen, report, PDF and export from R180 on the gate host with
   synthetic companies.
2. **After the change:** produce the same outputs again and compare them exactly; any difference fails.
3. **Full gate**, plus new suite `55-r181`:
   - an INR company with USD and EUR invoices, bills and payments at recorded rates;
   - weekend fallback, a missing rate refused, a different rate confirmed;
   - a base-currency change refused after the first transaction;
   - Canadian and INR companies on one install not affecting each other;
   - switch off means no change anywhere.
4. **Migration (schema 47):** adds one table and two company columns. It changes no existing row; the rollback drops
   only what R181 added.
