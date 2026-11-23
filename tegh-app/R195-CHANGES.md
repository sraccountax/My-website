# R195: India set-up steps on the dashboard

R195 is R194 plus the change below. Schema stays 46. There is no migration.

## 1. India edition: "Get your books going" lists the India set-up
**The problem:** after an India company was created, the dashboard's "Get your books going" showed only the general steps:
- import a bank statement;
- add opening balances;
- send the first invoice.

It did not mention the registrations, chart of accounts, GST, TDS or payroll to set up before bookkeeping. The India set-up checklist existed only on India Home.

**Now an India company sees these steps, in order:**

| Step | What it asks for | Its button opens |
|---|---|---|
| 1 | Create your company | (done) |
| 2 | Add PAN, GSTIN and TAN | Company details |
| 3 | Review the chart of accounts | Chart of accounts |
| 4 | Add your bank accounts | Bank accounts |
| 5 | Approve GST rates and create GST codes | India Masters: Statutory rules; once rates are approved, the GST codes tab |
| 6 | Approve TDS sections (optional) | India Masters: Statutory rules |
| 7 | Set up payroll (optional) | India Masters: Records (employees, pay components); once components exist, India Payroll: Salary structures |
| 8 | Add opening balances | Opening balances |
| 9 | Import a bank statement | Bank statement import |
| 10 | Send your first invoice | A new customer invoice |

**When a step counts as done:** steps 2, 5, 6 and 7 use the same rules as the India Home set-up checklist. For the others:
- **Step 3:** done once an account has been changed or opening balances are posted.
- **Step 4:** done once there is an active bank account.

**Optional steps:** TDS and payroll are marked "(optional)" and are not counted in "x of y steps done". The highlighted next step is the first required step not done.

**Canadian companies:** unchanged; they keep their four steps.

## 2. Release token
`5990-r195-india-setup` (app.html, .htaccess, the gate and preflight scripts).

## 3. Tests
**`73-r195`:** a new gate suite:
- a new user creates an India company through the India form;
- the dashboard lists the India steps;
- each step's button opens its page;
- a new Canadian company still gets the four Canadian steps.
