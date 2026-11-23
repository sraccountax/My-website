# R194: Tegh mark and Sign out on the first-company screen; Invoice Intelligence job list

R194 is R193 plus the changes below. Schema stays 46. There is no migration.

## 1. Both editions: the Tegh mark on the sign-in and first-company screens
**The problem:** the green square beside "Tegh · Accounting and payroll" on the left of the first-company screen was empty. It still held the letters of a retired mark, which a later stylesheet hides.

**Now:** it shows the Tegh T, the same image as the sidebar.

## 2. Both editions: Sign out on the first-company screen
**The problem:** a person who signed in without a company could only create one; there was no way to leave the screen.

**Now:** the first-company screen shows "Signed in as <email>" with a **Sign out** button above the form. It appears on:
- the Guided/Full Accounting choice;
- the Canadian company form;
- the India company form.

Sign out ends the session on the server and returns to the sign-in page. Nothing is created. If the server cannot confirm sign out, the button says so and stays on the screen.

## 3. Both editions: Invoice Intelligence job list
**The problem:** opening the job list first expires leases that have run out. When a worker claimed or finished a job at the same moment, the database could deadlock, and the list answered HTTP 500. Seen once in the R193 gate.

**Now:** the list retries the expiry once, then lists without it. The next list or claim expires those leases.

Claiming a job is unchanged: it still expires leases inside its own transaction.

## 4. Release token
`5990-r194-first-signout` (app.html, .htaccess, the gate and preflight scripts).

## 5. Tests
**`72-r194`:** a new gate suite:
- the mark shows the T;
- Sign out appears on the Canadian choice, the Canadian form and the India form, at 1440 px and 390 px;
- Sign out ends the session and returns to sign-in;
- the job list still answers and expires a lease that has run out.

The deadlock retry itself is checked by code review only: a deadlock cannot be produced on demand on the test server.
