# R196: Easier GST and payroll set-up for India companies

R196 is R195 plus the changes below. India only; Canadian companies are unchanged. Schema stays 46. There is no migration.

## 1. GST Set-up (new screen)
**Before:** setting up GST took four steps:
1. Find "GST rate" among 30 rule categories.
2. Fill a 12-field form for each rate: code, description, state, effective dates, rate, amount, thresholds, source notification, link and notes.
3. Have each rule approved.
4. Go to another tab to create the GST codes from each rule.

**Now:** one screen, **Settings › GST Set-up**, also opened from the dashboard and India Home.

**The rates:** each comes with a short note and can be ticked.

| Rate | Note | Ticked at first |
|---|---|---|
| 5% | Essential goods and many everyday goods and services | yes |
| 18% | Most services and most other goods | yes |
| 40% | Luxury and sin goods (special rate) | no |
| 0% | Nil-rated goods and services | no |
| 3% | Gold, silver and jewellery | no |
| 0.25% | Rough precious and semi-precious stones | no |

**The rest of the screen:**
- a box for another rate;
- a "from" date (the start of the books);
- the GSTR-1 B2C (large) invoice limit, suggested at ₹1,00,000.

**Confirming:** the person ticks "I have checked these rates apply…" and presses **Create GST codes**. For each rate, Tegh then:
- saves it as a statutory rule;
- approves it;
- creates its two codes: CGST + SGST (or UTGST) for sales within the state, and IGST for other states.

**Who can do it:** only the Company Owner or an Admin, the same people who approve rules today.

**Source of each rule:** "Confirmed by <email> in GST set-up (suggested by Tegh)".

**Rates already set up:** shown as "Set up · codes GST18 and IGST18". Repeating the set-up changes nothing.

**A changed figure:** a different figure from the same date is refused. It needs a later date, or a change in India Masters.

## 2. Payroll Set-up (new screen)
**Before:** setting up payroll took four steps:
1. Add employees and pay components as generic "Records" in India Masters (14 record types).
2. Add and approve EPF, ESIC and Professional Tax through the 12-field rule form.
3. Build a salary structure for each employee.
4. Only then run payroll.

**Now:** one screen, **Payroll › Payroll Set-up**, also opened from the dashboard and India Home. It has three steps.

**Step 1, Deductions:** tick what applies. The figures are suggested and can be edited:
- **EPF:** employee 12% and employer 12% of Basic, with Basic counted up to ₹15,000.
- **ESIC:** employee 0.75% and employer 3.25% of gross pay, only when gross is ₹21,000 or less.
- **Professional Tax:** a monthly amount for the company's state (suggested ₹200; check the state's slab).

After a "from" date and one confirmation, Tegh saves and approves those rules. It also creates the pay components:
- Basic, HRA and Special allowance;
- Income tax (TDS), entered in each pay run;
- the EPF, ESIC and Professional Tax deductions and employer contributions ticked.

**Step 2, Employees:** name, date of joining, PAN (optional), and monthly Basic, HRA and Special allowance. The monthly gross shows as you type.
- **EPF, ESIC, PT:** ticks for each employee.
- **Saving:** creates the employee (code E001, E002…) and their salary structure together.
- **Edit:** changes the details. A new salary applies from a chosen date; earlier months keep the earlier salary.
- **Turning on a deduction later:** ticking EPF, ESIC or PT afterwards adds it to the existing employees' structures.

**Step 3, Pay:** opens Pay Runs.

**Who can do it:**
- the deductions: Company Owner or Admin;
- employees: anyone with payroll permission.

**Advanced options:** India Masters keeps every option (other components, LWF, slabs, UAN, bank details…). Details already saved there are kept when an employee is edited.

## 3. Fixed: India screens that sent their data in the wrong form
Several India screens sent their data in a form the server refused ("The request body is not valid JSON"). The same features worked through the API, which the earlier tests used. The affected actions:
- Payroll: Save structure;
- Pay Runs: Calculate, Post and Reverse;
- TDS and TCS: Record and Reverse;
- GST Returns: GSTR-2B import and marking a return period prepared or filed.

All now work from the screens. Suite `74-r196` calculates a pay run and saves a structure from the screens.

## 4. Other changes
- **Dashboard:** "Get your books going" opens the new screens for GST and payroll.
- **India Home:** the checklist's GST and payroll items open the new screens.
- **Payroll page:** with no employees, it offers Payroll Set-up instead of pointing to India Masters.
- **Menus:** "GST Set-up" is under Settings, next to Company details; "Payroll Set-up" is first in the Payroll menu. Both are India only.

## 5. Release token
`5990-r196-easy-setup` (app.html, .htaccess, the gate and preflight scripts).

## 6. Tests
**`74-r196`:** a new gate suite, using a new user and India company:
- GST Set-up from the screen, checked rule by rule and code by code;
- API refusals: unconfirmed, a changed figure from the same date, a Canadian company;
- Payroll Set-up and two employees from the screen;
- a pay run calculated from the screen:
  - EPF ₹1,800 on ₹20,000 Basic (capped at ₹15,000);
  - ESIC ₹98 and ₹423 on ₹13,000 gross, none on ₹30,000;
  - Professional Tax ₹200;
  - net ₹28,000 and ₹11,622;
- Save structure from the screen;
- both screens at 390 px.

**`73-r195`:** now expects the dashboard's GST and payroll steps to open the new screens.
