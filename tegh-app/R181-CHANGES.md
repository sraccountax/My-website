# R181 — Books in any currency, with exchange rates recorded by date

R181 = R180 plus worldwide currencies. A company outside Canada can keep its books in its own currency (for example INR),
and every foreign-currency document is converted at the exchange rate recorded in Tegh for the document's date.

**Off by default.** Site & Release Settings › Release › **Worldwide currencies**: off, platform owner only, or
everyone. With it off, Tegh behaves as in R180.

## What users get (when the switch allows it)

1. **Books in a chosen currency.** When creating a company outside Canada, choose the currency the books are kept in.
   - The default is the country's currency (India → INR, United States → USD, euro countries → EUR, and so on).
   - **Canadian companies stay in CAD**, because GST/HST returns and Canadian payroll are in CAD.
   - The base currency cannot change after the first transaction.
   - For a country with a single time zone (for example India), the company's time zone is set to it, so "today" is
     the local date.
2. **All current currencies on documents.** Invoices, bills, expenses, notes and payments can be in any of 148 current
   ISO 4217 currencies, from the official list published on 17 September 2026. The 3-decimal currencies are not
   available yet (see Limits).
3. **Exchange rates by date** (Settings › Currency Exchange Rates):
   - record a rate for a currency and date (1 unit of the foreign currency = X in the base currency);
   - paste several lines at once (`date, currency, rate`);
   - see the rate history;
   - correcting a rate is recorded in the audit trail.
4. **The rate check on every foreign-currency document.** It applies to invoices, vendor invoices, expenses,
   credit/debit notes, customer and vendor payments, refunds, recurring invoices and bank statements.
   - Tegh uses the rate recorded for the document's date.
   - If that date has none (a weekend or holiday), Tegh uses the most recent earlier rate, up to 7 days old. The company
     can set this from 0 to 31 days.
   - If there is no such rate, **the document is not saved**. The message names the currency and date that need a rate.
   - A different rate typed on the document asks for confirmation once. The recorded rate and the rate used are written
     to the audit trail.
   - Forms fill in the recorded rate when the currency or date changes. They say which date's rate is used, or that a
     rate is missing.
   - Bank statements in a foreign currency convert each transaction at the rate for its own date.
5. **Amounts in the company's currency.** Screens, labels, chart axes and Tegh's written insights show the base
   currency. For every Canadian company that is still CAD.
6. **Payments at a different rate** post the realised exchange gain or loss to account 6850, as before; the rate is
   now the one for the payment date.

## Existing companies

Every company that existed before R181 keeps the R180 rule ("one current rate per currency") until its owner chooses
**Use the rate recorded for each document's date** on the Currency Exchange Rates screen. Choosing it copies each
currency's current rate into the history at its date. Companies created while the switch is on use rates by date.

## Limits (not hidden)

- **Not available as a base currency yet:**
  - currencies without decimal places (JPY, KRW, VND, CLP, ISK, …);
  - currencies with 3 decimal places (KWD, BHD, OMR, JOD, TND, LYD, IQD);
  - high-denomination currencies whose ordinary amounts exceed Tegh's limit of 1,000,000,000 per amount (IDR, IRR,
    LAK, UZS, COP, LBP, SYP, MMK, KHR, MNT, TZS, MGA, CDF, ARS, NGN, MWK, SSP).

  Each needs a change to how amounts are stored.
- **3-decimal currencies** cannot be used on documents yet, because amounts are stored in hundredths.
- **Precision:** rates are stored to 6 decimal places. A rate below 0.005 (for example KRW into USD) would lose
  accuracy, so it is refused with an explanation.
- **Revaluation:** month-end revaluation of open foreign balances (unrealised gain/loss) is not included.
- **Entering rates:** rates are entered or pasted by the user. Tegh does not download rates from a central bank.
- **Display:** amounts use Canadian number formatting with the currency's symbol (₹1,234.56). Indian lakh grouping
  (₹1,00,000.00) comes with the India edition.

## Also fixed

- **Conversion overflow:** amount × rate could overflow PHP's integer range for large amounts at large rates, and PHP
  would then silently use an approximate number. Those cases now use exact integer arithmetic. Every case that did not
  overflow keeps the R180 calculation, so existing results are unchanged. (R180's formula also rounds a cent wrong in
  rare cases where the product exceeds 2^53, which needs a single amount above about $64 million at a 1.4 rate. R181
  keeps that behaviour for amounts that fit, so existing books are unchanged.)
- **JPY and CHF:** the rates screen offered them while the server refused them. With the switch on, both work.

## Files

| Area | Files |
|---|---|
| Server | `api/currencies_r181.php` (new), `api/currency_data_r181.php` (new, generated from the ISO 4217 list), `api/index.php`, `api/companies.php`, `api/books.php`, `api/accounting.php`, `api/advanced.php`, `api/documents_r151.php`, `api/payments.php`, `api/imports.php`, `api/operations.php`, `api/site_settings_r174.php`, `api/bootstrap.php`, `api/insights_r144.php` |
| Browser | `assets/tegh-portal-v5990.js`, `assets/tegh-charts-r177.js`, `assets/tegh-dashboard-r177.js`, `assets/tegh-native-ocr-v5220.js`, `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js`, `app.html` |
| Database | Added on first use, as R137, R141, R153 and R174 do: table `company_exchange_rates`, and columns `companies.fx_rate_policy` (default `current`) and `companies.fx_rate_max_age_days` (default 7). **Nothing is added while the switch is off and no company uses rates by date.** No existing row is changed. Rollback: drop the table and the two columns. |
| Cache token | `5990-r181-fx` |
