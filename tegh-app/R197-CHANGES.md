# R197: Payroll pay heads and pay tables for India companies

R197 is R196 plus the changes below. India only; Canadian companies are unchanged. Schema stays 46. There is no migration.
Existing salary structures, pay runs, payslips and journals are unchanged and calculate as before.

## 1. Pay heads (Settings › Payroll Set-up)
The set-up screen lists the company's pay heads in three groups.

| Group | Fixed heads | Own heads |
|---|---|---|
| Earnings (gross pay) | Basic Pay, Dearness Pay, Dearness Allowance, Cell Phone Allowance, Travel Allowance, Medical Allowance | "+ Add allowance" |
| Deductions | EPF (employee), ESIC (employee), Professional Tax (when ticked), CPF, GPF, Insurance, Income tax (TDS), Loan Recovery | "+ Add deduction" |
| Paid by the company | EPF (employer), ESIC (employer) | — |

- Own heads can be renamed (the heading changes on payslips and registers) and removed. A removed head stays on past payslips.
- Fixed heads cannot be renamed or removed, and an own head cannot take the name of another head.
- EPF, ESIC and Professional Tax heads appear only when their rule is confirmed in the step above.

## 2. Employee pay table
Adding or editing an employee shows one row for every pay head. For each row the person chooses how it is worked out.

| How | Value | Per / of |
|---|---|---|
| Amount | rupees | Monthly, Annual, Semi-monthly or Bi-weekly |
| % of | percent (up to 4 decimals) | Basic Pay, DP, DA, Basic + DA, Basic + DP, Basic + DP + DA, or Gross pay (deductions only) |
| As per rule | — | EPF only: the PF wages (default Basic + DA) |
| Enter each month | — | TDS: typed on each pay run |

- **Pay runs stay monthly.** The other bases are only how the amount is entered: annual ÷ 12, semi-monthly × 2, bi-weekly × 26 ÷ 12, rounded to the paisa.
- A head left empty is not paid and does not appear on the payslip.
- The **Monthly** column, **Gross pay**, **Total deductions** and **Net pay** update as the person types (the server works them out the same way as a pay run). Employer contributions are shown below.
- **Pay from** is the effective date: a change applies to the whole month it falls in, as pay runs already do. For a new employee it follows the joining date unless it is changed.
- **Copy pay from** fills the table from another employee.
- The employee list shows each employee's monthly gross, deductions and net.
- Earlier the R196 form had three boxes (Basic, HRA, Special allowance) and saving an employee dropped heads added in India Masters. All active heads now show in the table and are kept.

## 3. Checks
- An earning cannot be a percentage of gross pay (gross includes it); a head cannot be a percentage of itself or of heads that depend on it.
- A head that the employee is not paid counts as zero in a "% of" base (EPF on Basic + DA with no DA is EPF on Basic). If none of the base heads is paid, saving is refused with the reason.
- At least one earning is required.

## 4. Unchanged
- EPF ceiling, ESIC limit, Professional Tax slabs, rounding to the rupee, proration by paid days, posting, reversal and registers.
- Professional Tax slabs and TDS sections are still kept in India Masters.

## Tests
- Suite 75-r197: ten employees, ten monthly payrolls (April 2026 – January 2027). Every head of every employee-month matches an independent calculation; each run posts one balanced journal; registers equal the sum of the runs; edits show values as entered; payslips open.
- Suite 74-r196 updated to the pay table. Suite 67-in5 unchanged and passing (older structures).
