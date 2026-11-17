# R191: India company set-up (IN-7a)

R191 is R190 plus the changes below.

**Scope:**
- Every change is inside the India edition, which is still off by default.
- **Canadian companies:** their screens, set-up and figures do not change.

## 1. First company of an India sign-in
- **Before:** a person who signed in to the India edition with no India company yet saw the Canadian set-up wizard: Canadian time zones, "fiscal year", provinces, HST, CAD and a chequing account.
- **Now:** they see the India company form (section 2), and creating the company opens it.

## 2. India company form
The same form is used:
- for the first company of an India sign-in;
- by Settings › Add company;
- for editing in Company details.

**Business:**
- company name;
- legal name (blank = the company name);
- trade name;
- constitution;
- nature of business.

**Tax and legal registrations:**
- PAN, and GSTIN with GST registration type;
- TAN and CIN/LLPIN;
- MSME category and Udyam number;
- IEC;
- EPF establishment code and ESIC employer code;
- Professional Tax registration (PTRC) and enrolment (PTEC);
- Shops and Establishment registration and Labour Welfare Fund registration;
- LEI and FSSAI.

Each is checked against its published format where one exists, for example:
- Udyam `UDYAM-XX-00-0000000`;
- EPF 5 letters and 7 digits;
- ESIC 17 digits;
- LEI 20 characters;
- FSSAI 14 digits.

**Registered address:**
- flat, door or building; street or road; area or locality; landmark;
- city or town; district;
- state or union territory; PIN code;
- country India.

**Books:**
- **Currency:** INR (fixed; any other currency is refused).
- **Time zone:** any time zone in the world, Asia/Kolkata by default.
- **Financial year:** its start and end are chosen with date pickers.
  - Choosing the start proposes an end 12 months later.
  - A first year from incorporation can be up to 18 months long.
  - The end must be after the start.
- **Start of books, and accounting basis.**
- **First bank account:** a current or savings account; there is no chequing account.
- **Chart of accounts:** the Indian starter chart or blank.

**Where the registrations are kept:**
- The registrations go into the India Masters company profile, so there is one record, with history.
- The GSTIN also becomes the company's first GST registration record (India Masters › Records).

## 3. Company details (Settings › Company details)
- **What it shows:** every registration, the address, the time zone and the financial year.
- **Edit company details** (Owner or Admin):
  - **Registrations:** saved as a new India Masters company profile version from today.
  - **Financial year:** can change only while the company has no entries.
  - **State:** cannot change once invoices record it.
  - **PAN and TAN:** shown in full only to the Owner or an Admin.

## 4. Company Setup and onboarding (India companies)
- **Company Setup cards:**
  - Company Details;
  - India Masters;
  - Chart of Accounts;
  - India Home;
  - Currency Exchange Rates;
  - Dashboard Figures;
  - Period Locking.

  There is no GST/HST, PST, province or GIFI card.
- **Onboarding step texts** say financial year, GST registration, GST codes, and current, savings and cash credit accounts.
- **Bank Accounts › Add account** offers:
  - current;
  - savings;
  - fixed deposit;
  - cash credit;
  - overdraft;
  - credit card;
  - other deposit account;
  - other borrowing.

## 5. Customers and vendors: full Indian address
**The form** (the full customer and vendor form and the quick-add dialog) has:
- flat, door or building;
- street or road;
- area or locality;
- landmark;
- city or town;
- district;
- state;
- PIN code (6 digits, checked);
- country.

**On invoices:**
- Address lines 1 and 2 are composed from the parts, for example "Shop 3, Sai Plaza, FC Road" and "Shivajinagar, Near Fergusson College".
- The district is added when it differs from the city.
- Invoices, statements and exports therefore print the whole address, through the invoice's saved customer address.

**Editing:** the parts are kept, so the form opens with them filled in.

## 6. Database
Schema stays 46. These are first-use additions in India-only tables:
- `company_india_profile`: new columns `address_json` and `fy_start_date`;
- new table `india_party_addresses`.

**Backups:** they include the new table; a restore creates it.

**India Masters company profile:** eight new optional fields:
- EPF establishment code and ESIC employer code;
- PTRC and PTEC;
- Shops and Establishment and Labour Welfare Fund;
- LEI and FSSAI.

**Shared code (additive):**
- The bank account kinds `current`, `fixed_deposit` and `cash_credit` were added to the server's list.
- The customer and vendor save paths read the India address fields only in India companies. They are ignored for Canadian companies (tested).

## 7. Fixed during testing
- A blank legal name was refused; it now means the company name.
- **Sign-in to the India edition failed:** reading the company profile at sign-in used a date function that is not loaded there. It now falls back.
