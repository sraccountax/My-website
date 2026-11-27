# R199: fixes from the group bookkeeping audit

R199 is R198 plus the changes below. It covers both editions. Schema stays 46, and there is no migration. Existing companies, documents and journals are unchanged. Group-level features (intercompany links, reconciliation, eliminations, translation, group close) are planned for R200 and R201.

## Accounting
- **Bills from vendors outside Canada:** a Canadian company no longer assumes its home province's tax.
  - **Before:** a bill with no tax code chosen got the company's home code. A US vendor's bill in an Ontario company was charged 13% HST, and an input tax credit that does not exist was claimed.
  - **Now:** a vendor whose country is not the company's gets no tax unless a code is chosen.
  - **Unchanged:** a code chosen on the bill is still used, Canadian vendors work as before, and India companies are not affected.
  - **Coverage:** single-amount bills, bills with lines, and vendor credit notes.
- **Zero-rated and Exempt tax codes:** two new starter codes for Canadian companies, both at 0%:
  - **"Zero-rated — 0% GST/HST":** for exports of services, basic groceries and similar.
  - **"Exempt — no GST/HST":** for most financial services, long-term residential rent and similar.
  - **Who gets them:** new companies get them automatically. An existing company gets them from Tax Codes › Create starter codes, which skips codes it already has.
- **Payment exchange rate:** a payment in another currency now prefills the rate for the payment date.
  - **Before:** the form used the rate the invoice or bill was recorded at, so no exchange gain or loss was booked unless the rate was changed by hand.
  - **Now:** it prefills the company's saved rate for the payment date, or the latest saved rate when dated rates are off. A note under the field shows the document's own rate and says the difference posts to foreign exchange gain or loss.
  - **Changing the date:** the rate is looked up again.
- **Paying more than the open balance:**
  - **The message:** the refusal now names the open balance and the extra amount (code `payment_exceeds_balance`).
  - **In the payment form:** Tegh offers to record the open balance against the invoice or bill and keep the extra as an advance to the same customer or vendor. These are two ordinary payments; the advance can be applied later.
  - **Imported bank lines:** these still pay at most the open balance.

## Companies and editions
- **India company from a Canada sign-in:** asking for an India company while signed in to the Canada edition is now refused.
  - **Before:** the PAN, GSTIN and constitution were dropped without an error, and a Canadian company with CAD books was created.
  - **Now:** the request is refused with the message "Sign in with the India edition to create an India company" (code `india_company_needs_india_edition`).
- **Users page:** the company list now shows only the signed-in edition's companies, like the company switcher. Before, a Canada sign-in listed the person's India companies.

## Screens
- **Dashboard, cash at bank:** a negative balance keeps its minus sign on the same line as the figure.
  - **Before:** at 1440 px "−$491,648" wrapped so the minus stood alone above "$491,648".
  - **Now:** the figure shrinks to fit its column and shows in the critical colour when negative. KPI figures elsewhere also stay on one line.
- **Dashboard, foreign-currency bank account:** the balance is labelled as shown in the company's currency, for example "USD Operating $8,352.00 (USD account, in CAD)".
- **Registers and reports:**
  - **Currency:** amounts in the table's own currency show without the currency code, because the caption already says it ("12 of 12 records · CAD").
  - **Zero:** shows as "–".
  - **Other currencies:** amounts keep their code (for example "USD 1,000.00").
  - **Totals:** the footer totals keep the code ("CAD 3,865.00").
- **Dark theme:** these screens now meet 4.5:1 contrast.
  - **Financial Analyst:** opening and projected closing cash were dark text on a dark card, and the minimum-cash and first-negative-date cards stayed light.
  - **Also fixed:** the Tegh Intelligence "what if" label and the guidance list on the payment screens.
- **Phone header (480 px and narrower):** the company name and mode now have their own row under the header buttons. Before, they showed as "Aur…" and "Full Acc".
- **Sidebar:** long group names such as "Advanced Accounting" wrap instead of being cut off.
- **Tax Codes help:** the example now reads "GST and QST, or GST and PST" instead of mentioning CGST and SGST.

## Tests
- **Suite 77-r199 (10 tests):**
  - foreign and Ontario bills, with and without a chosen code;
  - India details refused;
  - Zero-rated export invoice;
  - overpayment message;
  - Users page companies by edition;
  - dashboard at 1440 px and 390 px;
  - dark Financial Analyst contrast;
  - the payment form (rate prefill, note, payment plus advance, exchange gain of $27.00);
  - registers and the Tax Codes wording.
- **Expected figures, worked out by hand:**
  - US$1,000 at 1.365 = $1,365.00, with no tax;
  - $1,000 at 13% HST = $130.00;
  - US$1,000 received at 1.392 against 1.365 = $27.00 gain.
