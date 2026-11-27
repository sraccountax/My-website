# R200: Group & Intercompany

R200 is R199 plus the changes below. It applies to the Canada and India editions, but a link always joins two companies of the same edition. There is no migration and Schema stays 46. Three tables are created the first time the page or its API is used: `ic_pairs`, `ic_pair_accounts` and `ic_mirrors`. Existing companies, documents and journals are unchanged.

For anyone who keeps the books of several companies that trade with each other. There is **no separate menu page**; each part sits where that work is already done:
- **Company Details › Group companies:** linking companies (section 1).
- **Invoice Register:** a panel listing invoices to a linked company, shown only when there are any (section 2).
- **Month-End Close:** the intercompany reconciliation, the consolidation worksheet and closing the group (sections 3–5), shown only to a person with access to two or more companies.

A company that is not linked to another sees no change.

## 1. Linked companies
- **What a link holds:** two companies, plus:
  - the customer and the vendor each company uses for the other;
  - the expense account the other company's vendor invoices post to;
  - any accounts between them:
    - **balance accounts:** due to / due from, loans;
    - **income and expense accounts:** used for charges entered by journal, such as interest or recharges.
- **Who can link:** only the Owner or an Admin of both companies links or changes them (403 otherwise).
- **Checks:** a customer, vendor or account from the wrong company is refused (422), and the same two companies cannot be linked twice (409).

## 2. Intercompany invoices
- **The list:** invoices from the open company to a linked company, each with the state of the other company's vendor invoice.
- **"Create in <company>":** creates that vendor invoice as a draft in the other company. It copies:
  - the same number, date and due date;
  - the same lines and currency;
  - the other company's tax code with the same code (for example GST, ON or QC).
- **Where it posts:** the expense account from the link. Without one, the vendor's default account is used, then 6999 Unassigned Expense.
- **Review:** the draft is reviewed and posted there as usual.
- **Rules:** a mirror is made once per invoice (409 after that), and an invoice to an outside customer is refused (422).

## 3. Intercompany reconciliation
- **What it shows:** for each link, as at a date, what each company says the other owes it:
  - its receivable from the partner's customer;
  - less its payable to the partner's vendor;
  - plus its balance accounts.
- **Result:** the two figures cancel out when both sides are recorded. Otherwise the difference is shown, together with the number of the partner's vendor invoices still in draft.

## 4. Consolidation worksheet
- **Layout:** companies with one base currency side by side, then the eliminations, then the consolidated amount, for the profit and loss and the balance sheet.
- **Eliminated:**
  - income and expense from invoices, vendor invoices and notes between linked companies, and from the link's income and expense accounts;
  - receivables, payables and balance accounts between them.
- **Differences:** any amount that does not eliminate is shown on its own line ("Intercompany difference to investigate"). Group net income is not changed by it.
- **Currencies:** companies with different base currencies are refused for now. Translation between currencies comes in R201.

## 5. Close the group
- **What it does:** closes a period for several companies at once.
- **All or none:** every company is checked first (the person's role allows closing, and there are no pending bank transactions through the date). If one cannot be closed, none is, and every reason is listed.

## Tests
- **Suite 78-r200 (7 tests):**
  - linking, with each refusal;
  - mirrors;
  - reconciliation before and after the second draft is posted;
  - the consolidation worksheet;
  - group close;
  - the sections in Company Details, the Invoice Register and Month-End Close (no separate page or menu item) at 1440 px, at 390 px and in the dark theme.
- **Group used:** three companies (Ontario parent, BC branch, Quebec subsidiary). Every figure is worked out by hand:
  - **Fees:** $25,000 + GST twice and $10,000 + GST;
  - **Loan:** $100,000, with interest booked at $500 on one side and $450 on the other;
  - **Reconciliation:** a difference of $26,300 with one draft unposted, then $50;
  - **Consolidation:** $60,500 of intercompany income and $60,450 of expense eliminated, with $50 shown on its own line.
