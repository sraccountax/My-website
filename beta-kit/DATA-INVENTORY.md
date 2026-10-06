# Tegh beta: what the software collects, stores and sends

A factual inventory taken from the R155 code. It is input for your **privacy notice and beta terms** (beta step 6).
- It is not legal advice and does not decide which privacy laws apply.
- Items marked **YOU** depend on your hosting and business choices; fill them in.
- Have the final text reviewed before any real client data is allowed.

## 1. Who operates the service (YOU)
- **Legal name of the business operating Tegh:**
- **Contact for privacy questions and requests** (a working mailbox that someone reads):
- **Support address for testers:**
- **Hosting provider and data location:**
  - the IONOS data centre region of the beta site (for example Canada, US or EU);
  - the location of the off-site backup copy.

## 2. Personal information Tegh stores
| What | Whose | Where it comes from | Notes |
|---|---|---|---|
| Name, email address, password (stored only as a one-way hash) | each user | invitation acceptance | Passwords are never stored in readable form. |
| Sign-in sessions | each user | sign-in | Expire after 12 hours (config `session_hours`). Sign-out, removal from a company and a password reset end them. |
| Hashed IP address and hashed browser identifier with sign-in, sign-up, invitation and reset attempts | each user and visitor | throttling and abuse protection | Stored as SHA-256 hashes, not readable addresses. Failed sign-in attempts are deleted after 2 days. |
| Password-reset requests | each user | "Forgot password" | Deleted after 2 days. A reset link works once. |
| Terms and privacy acceptance (version, time, request reference) | each user | invitation acceptance | Records version **2026-10-01** from R155 (the version printed on the pages). |
| Audit history (who did what, when) | users acting in a company | every change | Kept with the company's records. |
| Company details: name, legal name, address, phone, email, province/country, fiscal settings, tax registration | the business | Company setup / Details | |
| Customers and vendors: names, contact names, emails, phones, addresses | third parties of each company | entered or imported by users | Personal information of people who are not Tegh users. |
| Accounting records: invoices, bills, notes, payments, journals, bank transactions, budgets, fixed assets | each company | entered or imported | |
| Uploaded files: bank statements, receipts, Document Intake files, invoice attachments | each company | uploads | Stored outside the web root, each under its own company. Files from another company cannot be downloaded (gate 24-beta BS-02/03). |
| Employees (Payroll Support): name, province, pay details | employees of a company | entered by users | **No SIN is stored.** Tegh refuses or discards SIN fields (gate PY-01…05). Canada outside Quebec only. |
| Client viewing links: client email, one-time codes, viewing sessions | a company's clients | created by users | Codes expire after 10 minutes; links can be revoked (gate CV-01…16). |
| Outbound email records (recipient, status) | invitees, clients | invitations, resets, client codes | Used for delivery diagnostics. |

## 3. What leaves the server
| Recipient | What | When |
|---|---|---|
| Your SMTP mail provider (**YOU**: name it) | recipient address and message: invitations, password resets, client viewing codes | when such an email is sent |
| Cloudflare Turnstile (only if `captcha.enabled` is true in `config.php`; **off** in the template) | the visitor's browser talks to Cloudflare for the bot check | sign-up or sign-in pages, when enabled |
| Nobody else | | **No analytics or session recording**: Microsoft Clarity was removed in R141 (DEF-01). **No AI provider**: the release is native-only and `aiConfigured` is false. **OCR runs in the browser** from files bundled in the package; the site's Content-Security-Policy allows connections only to the site itself and Cloudflare. |

## 4. Who can see a company's records
- Members of that company, according to their role:
  - **viewer** reads;
  - **editor** creates and changes records;
  - **admin** also manages members;
  - **owner** controls everything.

  Role limits are checked by gate RO-01…12.
- People given a client viewing link see only the reports shared with them (CV-05…08).
- The platform owner (you) can see platform-level user lists and support requests, and must keep server and database access restricted.

## 5. Keeping, exporting and deleting
| Need | What Tegh offers today |
|---|---|
| Export of a company's records | Company backup (`.tegh`, signed, includes uploaded files); report exports to PDF, Excel and CSV |
| Delete a company | Settings: company deletion (removes its records and files; a deletion log is kept) |
| Remove a person's access | Remove the member from the company (access ends immediately; gate 24-beta BS-04). The platform owner can deactivate a user. |
| **Delete a person's user account entirely** | **Not available in the app.** Deactivation keeps the name and email. Before real users, decide a manual procedure (database deletion of the user, sessions and acceptance records) or ask for a delete-account feature. |
| Backups | The daily host backups (`beta-ops/`) keep 14 days locally by default, plus the off-site copy. A deleted record stays in backups until they age out; say so in the notice. |
| Beta reset | If you reset the beta database, all beta records are removed; tell testers in advance (the beta notice says data may be reset). |

## 6. Points the privacy notice and beta terms should cover
- Who you are and how to contact you (section 1).
- What is collected and why (section 2), including other people's information that testers enter (customers, vendors, employees). During the beta, **sample data only**.
- Where it is stored and who processes it (sections 1 and 3).
- Who can access it (section 4).
- How long it is kept, how to export it and how to ask for deletion (section 5).
- Security measures, in plain words: hashed passwords, HTTPS, company separation, throttling, backups.
- What happens if something goes wrong: your breach-response steps and who is notified.
- Beta limitations:
  - no guarantee of availability;
  - data may be reset;
  - not for filing taxes or payroll;
  - keep your own authoritative records;
  - Payroll Support is limited to Canada outside Quebec.
- Ownership of records stays with the business that enters them; export is available.
- The terms version that testers accept. From R155 it is recorded as **2026-10-01**. If you change the text, change the version in `api/release_v5980.php` and on both pages together.
- Promotional email is separate from beta acceptance. Do not add testers to marketing lists without separate consent (CASL).
