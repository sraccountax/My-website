| ID | Area | Test | Result | Evidence |
|---|---|---|---|---|
| H-01 | H-INST | GET /api/health over HTTPS before install | **PASS** | 200 {"ok":true,"service":"Tegh","version":"5.9.9","build":5990,"schemaVersion":46} |
| H-02 | H-INST | auth/me on empty DB reports setup required | **PASS** | 428 {"error":"Initial owner setup is required.","code":"setup_required"} |
| H-03 | H-INST | Setup with wrong key refused 403 | **PASS** | 403 |
| H-04 | H-INST | Setup from foreign Origin refused | **PASS** | true |
| H-05 | H-INST | Fresh install: owner setup succeeds (201) and schema created | **PASS** | 201 |
| H-06 | H-INST | Schema tables created on fresh DB | **PASS** | 158 tables |
| H-07 | H-INST | Second setup locked (409) | **PASS** | 409 |
| H-08 | H-INST | Login over HTTPS; version/build/schema | **PASS** | ["5.9.9","5990","46"] |
| H-09 | H-INST | Session cookie flags Secure, HttpOnly, SameSite | **PASS** | tegh_session=<redacted>; expires=<redacted>; Max-Age=43200; path=/; secure; HttpOnly; SameSite=Strict / tegh_session_csrf=<redacted>; expires=<redacted>; Max-Age=43200; path=/; sec |
| H-10 | H-INST | startup/status after install | **PASS** | 200 {"ok":true,"version":"5.9.9","build":5990,"schemaVersion":43,"expectedSchemaVersion":46,"userId":"user_14207b7c6b665e144d58a6205886ecef","coreReady":true,"aiReady":true,"native |
| H-11 | H-INST | Fresh install: protected database upgrade (Schema 43 → 46) completes from the owner session | **PASS** | 200 steps schema46.5990.accounting_notes_r67:completed,schema46.5990.invoice_note_tax_columns_r69:completed,schema46.5990.accounting_note_settlements_r70:completed,schema46.5990.co |
| H-12 | H-INST | After upgrade: startup status Schema 46, not degraded | **PASS** | {"s":46,"d":false,"w":null} |
| H-M01 | H-MAIL | SMTP delivery test (STARTTLS + AUTH) reaches sandbox | **PASS** | 200 sent=true outcome=accepted mailfile=1792461470.843512.eml X-Sandbox-Tls: True / X-Sandbox-Auth: True / X-Rcpt: owner@gate.test |
| H-M02 | H-MAIL | Test email to non-owner address refused | **PASS** | 403 |
| H-M-editor | H-MAIL | Invitation email for editor delivered over STARTTLS+AUTH with a single-use invitation code | **PASS** | 200 delivery=sent code=present msg=The mail server accepted the message for delivery. |
| H-A-editor | H-MAIL | Invitee (editor) accepts and sets password | **PASS** | 200 {"accepted":true,"auth":{"user":{"id":"user_d79c68520a30536e81c2304be141a33a","email":"editor@gate.test","displayName":" |
| H-R-editor | H-MAIL | Invitation code cannot be reused | **PASS** | 410 |
| H-L-editor | H-MAIL | editor can sign in | **PASS** | 200 |
| H-M-viewer | H-MAIL | Invitation email for viewer delivered over STARTTLS+AUTH with a single-use invitation code | **PASS** | 200 delivery=sent code=present msg=The mail server accepted the message for delivery. |
| H-A-viewer | H-MAIL | Invitee (viewer) accepts and sets password | **PASS** | 200 {"accepted":true,"auth":{"user":{"id":"user_1c8b75c5c34e2a5a0726b0ebbd58daeb","email":"viewer@gate.test","displayName":" |
| H-R-viewer | H-MAIL | Invitation code cannot be reused | **PASS** | 410 |
| H-L-viewer | H-MAIL | viewer can sign in | **PASS** | 200 |
| H-M-admin | H-MAIL | Invitation email for admin delivered over STARTTLS+AUTH with a single-use invitation code | **PASS** | 200 delivery=sent code=present msg=The mail server accepted the message for delivery. |
| H-A-admin | H-MAIL | Invitee (admin) accepts and sets password | **PASS** | 200 {"accepted":true,"auth":{"user":{"id":"user_a63ab6dfa351916ed590691b3ff049e4","email":"admin@gate.test","displayName":"A |
| H-R-admin | H-MAIL | Invitation code cannot be reused | **PASS** | 410 |
| H-L-admin | H-MAIL | admin can sign in | **PASS** | 200 |
| H-M-other | H-MAIL | Invitation email for admin delivered over STARTTLS+AUTH with a single-use invitation code | **PASS** | 200 delivery=sent code=present msg=The mail server accepted the message for delivery. |
| H-A-other | H-MAIL | Invitee (admin) accepts and sets password | **PASS** | 200 {"accepted":true,"auth":{"user":{"id":"user_5d41f037109f2f5a2c426a42f9c3b44c","email":"otherowner@gate.test","displayNam |
| H-R-other | H-MAIL | Invitation code cannot be reused | **PASS** | 410 |
| H-L-other | H-MAIL | admin can sign in | **PASS** | 200 |
| D-MAILMSG | H-MAIL | Successful invitation send shows a success diagnostic (not "rejected") | **PASS** | {"message":"The mail server accepted the message for delivery.","nextAction":"Recipient can use the new invitation code.","reference":"f88d42de7af7df61","smtpCode":250,"stage":"fin |
| TX-00b | ACCT | DEF-08: BC company starter code for QC is GST only (not registered with Revenu Québec) | **PASS** | "GST" |
| TX-00c | ACCT | DEF-08: BC company keeps BC PST on its home-province code | **PASS** | "GST,PST" |
| TX-00d | ACCT | After registering in Quebec, owner edits the QC code to add QST 9.975% → 2115/1115 | **PASS** | 200 |
| TX-00 | ACCT | Starter codes present in new BC company | **PASS** | "AB,BC,GST,MB,NB,NL,NS,NT,NU,ON,PE,QC,SK,YT" |
| AC-01 | ACCT | Opening balances post a balanced journal: Dr bank $10,000 / Cr equity $10,000 | **PASS** | PUT 200/200 POST 201 {"posted":true,"openingBalanceImport":{"id":"opening_40bc997650e05a56fcd7f568b36e9952","journalEntryId":"journal_2fa2b1d437a9ab3777a190fac591088d","effectiveDa |
| AC-02 | ACCT | Posting the same opening balances twice does not double them | **PASS** | second post 400 {"error":"Enter at least one non-zero opening balance.","code":"request_error"}; bank now 1000000 |
| TX-01 | ACCT | ON customer $1,000: HST $130.00 to 2100; AR $1,130.00 | **PASS** | {"1200":113000,"2100":-13000,"4000":-100000} |
| TX-02 | ACCT | BC customer $1,000: GST $50.00 → 2100, PST $70.00 → 2110; AR $1,120.00 | **PASS** | {"1200":112000,"2100":-5000,"2110":-7000,"4000":-100000} |
| TX-03 | ACCT | QC customer $1,000: GST $50.00 → 2100, QST $99.75 → 2115; AR $1,149.75 | **PASS** | {"1200":114975,"2100":-5000,"2115":-9975,"4000":-100000} |
| TX-04 | ACCT | AB customer 3 × $166.67 = $500.01: GST $25.00; AR $525.01 | **PASS** | {"1200":52501,"2100":-2500,"4000":-50001} |
| TX-05 | ACCT | Exemption: No-tax line $200 + BC taxable $100 → GST $5, PST $7 only on the taxable line | **PASS** | {"1200":31200,"2100":-500,"2110":-700,"4000":-30000} |
| TX-06 | ACCT | Invoice with non-existent taxCodeId refused, nothing saved | **PASS** | 422 {"error":"A selected tax code is no longer active. Choose another under Tax code.","code":"tax_code_unavailable"} |
| TX-06b | ACCT | Vendor invoice with non-existent taxCodeId refused | **PASS** | 422 {"error":"The selected tax code is no longer active. Choose another.","code":"tax_code_unavailable"} |
| TX-06e | ACCT | Expense with non-existent taxCodeId refused | **PASS** | 422 {"error":"The selected tax code is no longer active.","code":"tax_code_unavailable"} |
| TX-07 | ACCT | Invoice with another company's taxCodeId refused, nothing saved | **PASS** | 422 {"error":"A selected tax code is no longer active. Choose another under Tax code.","code":"tax_code_unavailable"} |
| TX-07b | ACCT | Vendor invoice with another company's taxCodeId refused | **PASS** | 422 {"error":"The selected tax code is no longer active. Choose another.","code":"tax_code_unavailable"} |
| TX-07e | ACCT | Expense with another company's taxCodeId refused | **PASS** | 422 {"error":"The selected tax code is no longer active.","code":"tax_code_unavailable"} |
| TX-08a | ACCT | Temporary 1% code on $100 → $1.00 to 2100 | **PASS** | {"1200":10100,"2100":-100,"4000":-10000} |
| TX-08 | ACCT | Used code removed → inactive; invoice with inactive taxCodeId refused | **PASS** | status=inactive invoice 422 {"error":"A selected tax code is no longer active. Choose another under Tax code.","code":"tax_code_ |
| TX-08b | ACCT | Vendor invoice with inactive taxCodeId refused | **PASS** | 422 |
| TX-08c | ACCT | Earlier invoice keeps its 1% snapshot after the code became inactive | **PASS** | {"1200":10100,"2100":-100,"4000":-10000} |
| TX-09 | ACCT | BC purchase $1,000 (BC code): ITC $50 → 1100; PST $70 in cost (expense $1,070); AP $1,120 | **PASS** | {"1100":5000,"2050":-112000,"6000":107000} |
| TX-10 | ACCT | QC purchase $1,000 (QC code): GST ITC $50 → 1100, QST ITR $99.75 → 1115; AP $1,149.75 | **PASS** | {"1100":5000,"1115":9975,"2050":-114975,"6000":100000} |
| TX-11 | ACCT | GST-only supplier $500: ITC $25; AP $525 | **PASS** | {"1100":2500,"2050":-52500,"6000":50000} |
| TX-12 | ACCT | Expense $113.00 tax-included with ON code: expense $100.00, HST ITC $13.00 | **PASS** | {"1000":-11300,"1100":1300,"6000":10000} |
| TX-13 | ACCT | Credit note $200 + tax on BC invoice: reverses GST $10.00 (2100) and PST $14.00 (2110); AR −$224.00 | **PASS** | {"1200":-22400,"2100":1000,"2110":1400,"4000":20000} |
| AC-03 | ACCT | Five simultaneous identical receipt submissions (same operation key) record one payment | **PASS** | statuses 201,200,200,200,200 payments=1 |
| AC-04 | ACCT | Invoice fully settled after credit note + receipt: balance due $0.00 | **PASS** | "0" |
| AC-05 | ACCT | Over-payment of a settled invoice refused | **PASS** | 409 {"error":"Choose an open issued invoice.","code":"payment_document_unavailable"} |
| AC-06 | ACCT | Vendor payment $525 settles GST-only bill | **PASS** | 201 {"payment":{"id":"payment_95b2a0f20f853665ada6396c30735ebf","type":"vendor","flowKind":"vendor_payme |
| BK-01 | ACCT | CSV statement import: preview shows 5 rows, approve imports 5 | **PASS** | rows=5 selected=5 approve=201 {"batch":{"id":"import_594e4316c7738dae45c975b4f46e3517","status":"needs_review","rowCount":5,"selectedCount":5,"omittedCount":0,"duplicateCount":0,"c |
| BK-02 | ACCT | Re-importing the same statement: all 5 rows flagged duplicate, none imported | **PASS** | dups=5 approve=422 count=5 |
| TX-14 | ACCT | Bank deposit $1,149.75 with QC code: income $1,000, GST $50 → 2100, QST $99.75 → 2115 | **PASS** | {"1000":114975,"2100":-5000,"2115":-9975,"4000":-100000} |
| TX-15 | ACCT | Bank purchase $1,149.75 with QC code: ITC $50 → 1100, QST ITR $99.75 → 1115 | **PASS** | {"1000":-114975,"1100":5000,"1115":9975,"6000":100000} |
| TX-16 | ACCT | Bank purchase $1,120 with BC code: ITC $50, PST $70 in cost ($1,070) | **PASS** | {"1000":-112000,"1100":5000,"6000":107000} |
| AC-07 | ACCT | Three simultaneous posts of the same bank line create one journal | **PASS** | statuses 409,200,409 journals=1 |
| AC-08 | ACCT | Posting a bank line to its own bank GL account (same-account transfer) refused | **PASS** | 422 {"error":"Transfer From and Transfer To must be different accounts.","code":"transfer_same_account"} |
| AC-08b | ACCT | Owner transfer posted to equity instead | **PASS** | 200 |
| AC-09 | ACCT | Re-posting an already posted bank line refused / no second journal | **PASS** | 200 journals=1 |
| AC-10 | ACCT | Balanced manual journal $123.45 posts | **PASS** | 201 {"journalEntry":{"id":"journal_464a81111341b1c8c74ebea8c17724a9","status":"posted"},"approvalId":null,"approvalRequired" |
| AC-11 | ACCT | Unbalanced manual journal ($100.00 / $99.99) refused | **PASS** | 400 {"error":"Journal entry debits and credits must balance exactly.","code":"request_error"} |
| AC-12 | ACCT | Manual journal to AR control account 1200 refused (subledger protection) | **PASS** | 409 {"error":"Account 1200 Accounts Receivable follows your customer and vendor documents, so a journal cannot post to it. U |
| AC-12b | ACCT | Recurring journal template posting to AR control 1200 refused | **PASS** | 409 {"error":"Account 1200 Accounts Receivable follows your customer and vendor documents, so a journal cannot post to it. U |
| AC-12c | ACCT | Manual journal to AP control account 2050 refused | **PASS** | 409 {"error":"Account 2050 Accounts Payable follows your customer and vendor documents, so a journal cannot post to it. Use  |
| AC-13 | ACCT | Future-dated journal refused | **PASS** | 400 |
| AC-14 | ACCT | Close books through 2026-08-31 | **PASS** | 200 {"closedThroughDate":"2026-08-31"} |
| AC-15 | ACCT | Journal dated in locked period refused | **PASS** | 409 {"error":"This date is in a locked period. Reopen the period in Settings → Period Locking before pos |
| AC-16 | ACCT | Invoice dated in locked period refused | **PASS** | 409 {"error":"This date is in a locked period. Reopen the period in Settings → Period Locking before pos |
| AC-17 | ACCT | Vendor invoice dated in locked period refused | **PASS** | 409 {"error":"This date is in a locked period. Reopen the period in Settings → Period Locking before pos |
| AC-18 | ACCT | Receipt dated in locked period refused | **PASS** | 409 {"error":"This date is in a locked period. Reopen the period in Settings → Period Locking before pos |
| AC-19 | ACCT | Every journal entry in the database balances (debits = credits) | **PASS** | "0" |
| AC-20 | ACCT | GL balances equal the independently accumulated expected ledger (BC company) | **PASS** | {"1000":861300,"1100":23800,"1115":19950,"1200":321776,"2050":-226975,"2100":-30100,"2110":-6300,"2115":-19950,"3000":-962345,"4000":-470001,"6000":488845} |
| AC-21 | ACCT | 2100 GST/HST payable net: 130+50+50+25+5+1−10+50 collected, 50+50+25+13+50+50 ITCs in 1100 | **PASS** | [-30100,23800] |
| AC-22 | ACCT | 2110 PST $70+$7−$14 = $63.00; 2115 QST $99.75+$99.75 = $199.50; 1115 QST ITR $99.75+$99.75 = $199.50 | **PASS** | [-6300,-19950,19950] |
| AC-23 | ACCT | AR control 1200 = open customer invoices (SQL subledger): $1,130 + $1,149.75 + $525.01 + $312 + $101 = $3,217.76 | **PASS** | [321776,321776] |
| AC-24 | ACCT | AP control 2050 = open vendor invoices: $1,120 + $1,149.75 | **PASS** | [226975,226975] |
| AC-25 | ACCT | AR ageing report total = AR subledger ($3,217.76) and = AR control 1200 | **PASS** | status 200 total=321776 keys=type,asOf,partyId,parties,cutoffs,labels,totals,rows |
| AC-26 | ACCT | AP ageing report total = AP control ($2,269.75) | **PASS** | status 200 total=226975 |
| AC-27 | ACCT | Trial Balance report equals the GL computed by SQL, account by account | **PASS** | {"1000":861300,"1100":23800,"1115":19950,"1200":321776,"2050":-226975,"2100":-30100,"2110":-6300,"2115":-19950,"3000":-962345,"4000":-470001,"6000":488845} |
| AC-28 | ACCT | Trial Balance debits = credits | **PASS** | 1715671 |
| AC-29 | ACCT | Balance Sheet: Assets = Liabilities + Equity, difference $0, assets = SQL asset total | **PASS** | sqlAssets=1226826 status 200 totals {"assetsCents":1226826,"liabilitiesCents":283325,"equityCents":943501,"liabilitiesEquityCents":1226826,"differenceCents":0} |
| AC-30 | ACCT | Trial Balance identical on re-run | **PASS** | "[{\"id\":\"account_095d04f537fbf2e9422cbd7c24550dd0\",\"code\":\"1000\",\"name\":\"Business Chequing\",\"type\":\"asset\",\"normalBalance\":\"debit\",\"openingDebitCents\":0,\"ope |
| AC-31 | ACCT | Editing the QC code after posting leaves posted history unchanged | **PASS** | [200,true] |
| QT-01 | TAX | QC company invoice $1,000: GST $50 → 2100, QST $99.75 → 2115 | **PASS** | {"1200":114975,"2100":-5000,"2115":-9975,"4000":-100000} |
| QT-02 | TAX | QC bill $500: GST ITC $25.00 → 1100, QST ITR $49.88 (49.875 rounded half-up) → 1115; AP $574.88 | **PASS** | {"1100":2500,"1115":4988,"2050":-57488,"6000":50000} |
| QT-03 | TAX | QC statement import (2 rows) | **PASS** | 201 {"batch":{"id":"import_827846afb225e93596b7a638fabbe179","status":"needs_review","rowCount":2,"selec |
| QT-04a | TAX | Remittance with period end after the payment date refused | **PASS** | [422,"sales_tax_period_invalid"] |
| QT-04 | TAX | QST remittance $49.87: Dr 2115 $99.75, Cr 1115 $49.88, Cr bank $49.87 | **PASS** | [200,{"1000":-4987,"1115":-4988,"2115":9975}] |
| QT-05 | TAX | After QST remittance: 2115 = $0.00 and 1115 = $0.00 | **PASS** | [0,0] |
| QT-06 | TAX | GST remittance $25.00: Dr 2100 $50, Cr 1100 $25, Cr bank $25; 2100 and 1100 back to $0 | **PASS** | [200,{"1000":-2500,"1100":-2500,"2100":5000},0,0] |
| QT-07 | TAX | GST/HST (tax) Summary report includes QST accounts 2115 and 1115 | **PASS** | status 200 codes "code":"1100" "code":"1110" "code":"1115" "code":"2100" "code":"2110" "code":"2115" |
| CG-01 | TAX | Create custom tax accounts 2120/1120 | **PASS** | 201/201 {"account":{"id":"account_9d7fd01a8c1bf6e1c732184100b05050","gifiCode":null}} |
| CG-02 | TAX | Starter ON code edited to post HST to custom 2120/1120 | **PASS** | 200 |
| CG-03 | TAX | ON invoice $200: HST $26.00 → 2120 (custom), nothing to 2100 | **PASS** | {"1200":22600,"2120":-2600,"4000":-20000} |
| CG-04 | TAX | ON bill $100: HST ITC $13.00 → 1120 (custom) | **PASS** | {"1120":1300,"2050":-11300,"6000":10000} |
| CG-05 | TAX | Trial Balance shows 2120 Cr $26.00 and 1120 Dr $13.00 | **PASS** | [2600,1300] |
| CG-06 | TAX | General Ledger for 2120 lists the $26.00 credit | **PASS** | status 200 {"currency":"CAD","start":"2026-01-01","end":"2026-09-30","account":{"id":"account_9d7fd01a8c1bf6e1c732184100b05050","code":"2120","name":"HST Payable (custom)" |
| CG-07 | TAX | Tax Summary report includes custom 2120 ($26.00) and 1120 ($13.00) | **PASS** | status 200 |
| CG-08 | TAX | Dashboard tax card reports custom-account HST: other tax accounts net $13.00 owed (2120 $26 − 1120 $13) | **PASS** | "gstHstCollectedCents":0 "gstHstRecoverableCents":0 "gstHstNetCents":0 "pstPayableCents":0 "pstRecoverableCents":0 "qstPayableCents":0 "qstRecoverableCents":0 |
| NR-01 | TAX | Company NOT registered for GST/HST: default taxable invoice line charges no GST/PST | **PASS** | starter codes=14 mode=codes journal={"1200":10000,"4000":-10000} |
| NR-02 | TAX | Unregistered company: $100 bill with BC code — GST $5 and PST $7 added to cost ($112), no ITC | **PASS** | {"2050":-11200,"6000":11200} |
| NR-03 | TAX | Unregistered company: a code chosen explicitly on the line is still applied (GST $5) | **PASS** | {"1200":10500,"2100":-500,"4000":-10000} |
| CB-01 | TAX | Cash basis: receipt of $565 (half) recognises HST $65.00 in 2100 and revenue $500 | **PASS** | [-6500,-50000] |
| CB-02 | TAX | Cash basis: invoice issue posting recorded for review | **INFO** | {} |
| FX-01 | FX | Add USD at 1.35 | **PASS** | 201 |
| FX-01b | FX | Add USD bank account | **PASS** | 201 {"bankAccount":{"id":"bank_5951272b5ec108e5aeaf994ac26323af","accountType":"bank","accountSubtype":"chequing"}} |
| FX-02 | FX | USD 1,000 invoice at 1.35: revenue CAD $1,350.00, GST CAD $67.50, AR CAD $1,417.50 | **PASS** | [201,{"1200":141750,"2100":-6750,"4000":-135000}] |
| FX-03 | FX | USD 1,050 receipt at 1.40: bank CAD $1,470.00, AR −$1,417.50, FX gain $52.50 | **PASS** | 201 {"payment":{"id":"payment_2e917856ab0208b1a949f2c1ddaa3aa6","type":"customer","flowKind":"customer_receipt","status":"po lines: 1010	147000	0 / 1200	0	141750 / 6850	0	5250 |
| FX-04 | FX | USD invoice fully settled (foreign balance 0) | **PASS** | "0" |
| SI-02 | SEC | Other company: BC invoice detail by ID refused | **PASS** | 404 {"error":"That invoice is not available in this company.","code":"invoice_unavailable"} |
| SI-03 | SEC | Other company: BC invoice PDF model refused | **PASS** | 404 {"error":"That invoice output is unavailable.","code":"invoice_output_unavailable"} |
| SI-04 | SEC | Other company: payment against BC invoice refused | **PASS** | 409 {"error":"Choose an open issued invoice.","code":"payment_document_unavailable"} |
| SI-05 | SEC | Other company: editing BC tax code by ID refused | **PASS** | 422 {"error":"GST: choose at least one GL account (sales or purchases).","code":"tax_code_account_requir |
| SI-06 | SEC | Other company: deleting BC tax code refused | **PASS** | 404 {"error":"That tax code no longer exists.","code":"tax_code_not_found"} |
| SI-07 | SEC | Other company: posting a BC bank transaction refused | **PASS** | 409 {"error":"A selected transaction is no longer available for posting.","code":"transaction_unavailable"} |
| SI-08 | SEC | Other company: credit note against BC invoice refused | **PASS** | 404 {"error":"The original invoice is unavailable in this company.","code":"note_source_missing"} |
| SI-09 | SEC | Other company: backup export of BC refused | **PASS** | 403 {"error":"The selected company is unavailable.","code":"company_forbidden"} |
| SI-10 | SEC | Other company: BC GL account ledger by account ID refused / empty | **PASS** | 404 {"error":"The selected General Ledger account was not found.","code":"account_not_found"} |
| SI-11 | SEC | Other company: invoicing a BC customer ID refused | **PASS** | 400 {"error":"Choose a valid customer.","code":"request_error"} |
| SI-12 | SEC | Other company: listing BC client viewing links refused | **PASS** | 403 |
| RO-01 | SEC | Viewer cannot create invoices | **PASS** | 403 |
| RO-02 | SEC | Viewer cannot post journals | **PASS** | 403 |
| RO-03 | SEC | Viewer cannot create tax codes | **PASS** | 403 |
| RO-04 | SEC | Viewer cannot reopen a locked period | **PASS** | 403 |
| RO-05 | SEC | Viewer cannot post bank transactions | **PASS** | 403 |
| RO-06 | SEC | Viewer can read the Trial Balance | **PASS** | 200 |
| RO-07 | SEC | Viewer cannot invite users | **PASS** | 422 |
| RO-08 | SEC | Viewer cannot export a backup | **PASS** | 403 |
| RO-09 | SEC | Editor can create a draft invoice | **PASS** | 201 {"invoice":{"id":"invoice_527fb6c455b3455f2a9bcbac8f1c89e9","number":"INV-1008","status":"draft","to |
| RO-10 | SEC | Editor cannot reopen a locked period | **PASS** | 403 |
| RO-11 | SEC | Editor cannot invite an owner | **PASS** | 422 |
| RO-12 | SEC | Company admin cannot grant the Owner role | **PASS** | 422 {"error":"Choose one Company Admin, Editor or Viewer role for each company. Protected roles cannot b |
| CS-01 | SEC | POST without CSRF token refused | **PASS** | 403 |
| CS-02 | SEC | POST with wrong CSRF token refused | **PASS** | 403 |
| CS-03 | SEC | POST from foreign Origin refused | **PASS** | 403 |
| CS-04 | SEC | Form-encoded (simple-request) POST refused | **PASS** | 400 |
| XS-01 | SEC | XSS payload customer stored as text (rendering checked in UI run) | **INFO** | 201 |
| UP-01 | SEC | Receipt upload of .php refused | **PASS** | 400 {"error":"This file extension is not supported.","code":"request_error"} |
| UP-02 | SEC | Receipt upload of .svg refused | **PASS** | 400 |
| UP-03 | SEC | PHP disguised as .png refused by content check | **PASS** | 415 {"error":"The file contents do not match an allowed format.","code":"file_type_i |
| UP-04 | SEC | Valid PDF receipt accepted and stored outside the web root | **PASS** | 201 key=company_…/receipts/….pdf |
| UP-05 | SEC | Statement upload-start with .php refused | **PASS** | 415 {"error":"This statement file extension is not supported.","code":"file_extensio |
| UP-06 | SEC | Statement over 10 MB refused | **PASS** | 413 |
| UP-07 | SEC | Receipt over 10 MB refused | **PASS** | 400 |
| EL-01 | SEC | Malformed JSON, SQL-like IDs, oversize and wrong-type input: clean 4xx, no paths/stack traces/SQL | **PASS** | 400:{"error":"The request body is not valid JSON.","code":"reque / 404:{"error":"That invoice is not available in this company.","c / 400:{"error":"Customer name must be 160 charac |
| EL-02 | SEC | Authenticated API responses are not cacheable (Cache-Control no-store/private) | **PASS** | cache-control=private, no-store, max-age=0 |
| EL-03 | SEC | API does not expose X-Powered-By / PHP version | **PASS** | x-powered-by=null server=Apache/2.4.58 (Ubuntu) |
| EL-04 | SEC | Server error log has no PHP fatal errors during the gate run | **PASS** |  |
| TH-01 | SEC | Repeated wrong passwords are throttled (429 or CAPTCHA required) before 24 attempts | **PASS** | 401,401,401,401,401,401,401,401,401,401,401,401,401,401,401,401,401,401,401,401,429,429,429,429 |
| TH-02 | SEC | Correct password while throttled is still refused (no bypass) | **PASS** | 429 |
| SI-01 | SEC | Other-company admin: workspace summary with X-Company-Id of BC refused | **PASS** | 403 {"error":"The selected company is unavailable.","code":"company_forbidden"} |
| SI-13 | SEC | Anonymous API request refused (401) | **PASS** | 401 |
| SE-01 | SEC | After logout the old session cookie is rejected | **PASS** | 200 -> 401 |
| CV-01 | CVL | Create viewing link; token only in URL fragment; link email delivered to client test inbox | **PASS** | 201 url=https://gate.test/client-view.html#<token> email=true |
| CV-02 | CVL | Request code: code emailed to client address (masked in response) | **PASS** | 200 {"sentTo":"cl••••@gate.test","companyName":"Gate Test BC Ltd","clientName":"Test Client Person","expiresInMinutes":10} |
| CV-03 | CVL | Wrong code refused with tries left | **PASS** | 422 That code is not right. 4 tries left. |
| CV-04 | CVL | Correct code signs in (Secure HttpOnly cookie) | **PASS** | 200 |
| CV-05 | CVL | Client dashboard loads summary only | **PASS** | 200 keys=periodStart,periodEnd,bankBalanceCents,unpaidInvoicesCents,overdueInvoicesCents,openInvoiceCount,openBillCount,payableSubledgerCents,taxSummary,incomeYtdCents,expensesYtdC |
| CV-06 | CVL | Shared report (P&L) loads | **PASS** | 200 |
| CV-07-general-ledger | CVL | Unshared report general-ledger refused | **PASS** | 403 |
| CV-07-trial-balance | CVL | Unshared report trial-balance refused | **PASS** | 403 |
| CV-07-payroll-runs | CVL | Unshared report payroll-runs refused | **PASS** | 403 |
| CV-07-audit-history | CVL | Unshared report audit-history refused | **PASS** | 403 |
| CV-07-ar-ageing | CVL | Unshared report ar-ageing refused | **PASS** | 403 |
| CV-08 | CVL | Client session cannot call the accountant API | **PASS** | 401 |
| CV-09 | CVL | Used code cannot be reused | **PASS** | 410 |
| CV-10 | CVL | Guessing: after 5 wrong codes even the right code is refused | **PASS** | 422,422,422,422,422,429 then correct=429 |
| CV-11 | CVL | Code requests limited to 5 per hour per link | **PASS** | 200,200,200,429,429 |
| CV-12 | CVL | Expired code (10 min TTL) refused | **PASS** | 410 |
| CV-13 | CVL | Expired client session refused | **PASS** | 401 |
| CV-14 | CVL | Revoking the link ends active sessions and blocks new codes | **PASS** | before 200 after 401 start 410 |
| CV-15 | CVL | Guessed/random token refused without revealing company | **PASS** | 404 {"error":"This viewing link is not valid. Ask your accountant for a new one.","c |
| CV-16 | CVL | Expired link refused | **PASS** | 410 |
| PY-00 | PAY | Enable Payroll Support for the ON test company | **PASS** | 201 {"payroll":{"enabled":true,"calculationVersion":"CRA-T4127-2026.2-verified-tax","supportedYear":2026,"postingMode":"draf |
| PY-01 | PAY | Employee with SIN fields submitted: refused or SIN discarded | **INFO** | 422 sin_not_accepted Tegh does not store Social Insurance Numbers (SINs). Remove the SIN and use the Employee ID instead. |
| PY-02 | PAY | Employee can be created without a SIN (Employee ID only) | **PASS** | 201 {"employee":{"id":"employee_f2cfaeb429f7bc35684580e9878d5f98"}} |
| PY-03 | PAY | No SIN (ciphertext or last four) stored in the database | **PASS** | "0" |
| PY-03b | PAY | No SIN stored for any company on the gate database | **PASS** | "0" |
| PY-04 | PAY | Payroll workspace API returns no SIN fields/values | **PASS** |  |
| PY-05 | PAY | Company backup contains no SIN value | **PASS** | 200 size=15312 |
| PY-06 | PAY | Payroll notice text present in app bundle ("Payroll Support Tool") | **PASS** |  |
| PY-07 | PAY | Payroll rate tables: year/version reported for owner verification | **INFO** | "effectiveFrom":"2026-01-01" "effectiveTo":"2026-06-30" "effectiveFrom":"2026-07-01" "effectiveTo":"2026-12-31" |
| BR-01 | REC | Backup export downloads a signed .tegh archive | **PASS** | 200 application/vnd.tegh.backup bytes=42392 |
| BR-02 | REC | Restore into a new company succeeds | **PASS** | 201 {"restored":true,"companyId":"company_09e4fa7eb75727241f047111059089b1","companyName":"Gate Test BC Ltd (Restored copy)","payloadSha256":"40377fa8f2ba2021b5ab8f2a320ff766b59e08 |
| BR-03 | REC | Restored company Trial Balance equals source, account by account | **PASS** | {"1000":861300,"1010":147000,"1100":23800,"1115":19950,"1200":321776,"2050":-226975,"2100":-36850,"2110":-6300,"2115":-19950,"3000":-962345,"4000":-605001,"6000":488845,"6850":-525 |
| BR-04 | REC | Restored record counts equal source (documents, journals, bank lines, tax codes, tax detail, payments, notes) | **PASS** | "invoices=8 bills=3 customers=6 vendors=2 journal_entries=22 bank_transactions=5 tax_codes=15 document_tax_lines=18 party_payments=3 accounting_notes=1" |
| BR-05 | REC | Restored AR and AP ageing totals equal source | **PASS** | [321776,226975] |
| BR-08 | REC | Tampered backup refused with a 4xx integrity message (no 500) | **PASS** | 422 backup_integrity_invalid |
| BR-06 | REC | Tax codes, rates and GL mapping identical after restore | **PASS** | "AB:GST:5000:2100/1100:active,BC:GST:5000:2100/1100:active,BC:PST:7000:2110/-:active,GST:GST:5000:2100/1100:active,MB:GST:5000:2100/1100:active,NB:HST:15000:2100/1100:active,NL:HST |
| BR-07 | REC | No cross-company account references after restore | **PASS** | "0" |
| BR-09 | REC | Restored document tax detail points to restored accounts only | **PASS** | "0" |
| PF-01 | OPS | API median latency under 1.5 s for dashboard, reports and tax codes (gate host, small data set) | **PASS** | auth/me 34ms / workspace/summary 34ms / portal/trial-balance 125ms / portal/aging 129ms / professional-output/v5980/tax-summary 126ms / professional-output/v5980/general-ledger 133 |
| PF-02 | OPS | 20 concurrent dashboard loads all succeed | **PASS** | statuses ok=20/20 in 254 ms |
| LG-01 | OPS | Incident log (private, outside web root) contains no passwords, session cookies, CSRF tokens or SINs | **PASS** |  |
| LG-02 | OPS | Web access log contains no client-view tokens or invitation tokens (tokens travel in the fragment/body) | **PASS** |  |
| LG-03 | OPS | PHP error log: warnings recorded (see evidence) | **INFO** | PHP Warning:  POST Content-Length of 11534510 bytes exceeds the limit of 8388608 bytes in Unknown on line 0' |
| SP-01 | OPS | In-app support route responds | **PASS** | 200 {"requests":[],"platformOwner":true} |
| SP-02 | OPS | Public contact page offers a working contact path (form posts to /api/marketing) | **INFO** | no mailto |
| UP-01 | UPG | After uploading the new release over R118: startup status not degraded, schema 46 | **PASS** | schema 46 degraded false warning null |
| UP-02 | UPG | Existing company opens (dashboard summary) | **PASS** | 200 |
| UP-03 | UPG | R137 tables/columns created automatically on the first ordinary API request (no manual migration) | **PASS** | "tax_codes,tax_code_components,document_tax_lines" |
| UP-04 | UPG | Ledger balances unchanged by the upgrade | **PASS** | "1000\t50000\n1200\t55000\n2100\t-5000\n4000\t-100000" |
| UP-05 | UPG | Existing company stays on its previous tax rules (legacy mode) until it adds tax codes | **PASS** | "legacy" |
| UP-06 | UPG | Post-upgrade invoice ($200, BC customer, legacy rules) posts GST $10 to 2100 | **PASS** | 1200	21000 / 2100	-1000 / 4000	-20000 |
| UP-07 | UPG | Pre-upgrade invoice can be settled after upgrade | **PASS** | 201 balance 0 |
| UP-08 | UPG | Existing company: Add Canadian tax codes creates 14 codes, QST accounts 2115/1115, and switches to tax codes | **PASS** | 201 codes=14 |
| UP-09 | UPG | All journals balance after upgrade | **PASS** | "0" |
| UP-10 | UPG | R141 migration: existing company country is Canada; province widened to VARCHAR(10) on companies, customers, vendors | **PASS** | Canada / widened=3 |
| UP-11 | UPG | DEF-08 on an upgraded BC company: Manitoba starter code is GST only | **PASS** | GST |
| UP-12 | UPG | Dashboard Figures available on the upgraded company (table created on first use) | **PASS** | 200 |
| UP-13 | UPG | Tegh Intelligence works on the upgraded company (brief, findings, chase list, cash) | **PASS** | 200 lines=4 |
| D8-01 | R141 | Quebec company: home code QC = GST 5% + QST 9.975% | **PASS** | "GST 5000 + QST 9975" |
| D8-02 | R141 | Quebec company: BC code = GST only (no BC PST unless registered there) | **PASS** | "GST 5000" |
| D8-03 | R141 | Quebec company: MB and SK codes = GST only | **PASS** | ["GST 5000","GST 5000"] |
| D8-04 | R141 | Quebec company: ON code still HST 13% (federal HST applies everywhere) | **PASS** | "HST 13000" |
| D8-05 | R141 | Non-home PST province codes explain how to add the provincial tax | **PASS** | GST only. Charge British Columbia PST only if you are registered with British Columbia. Businesses outside British Columbia may have to register if they sell to customers there. Ch |
| CO-01 | R141 | Company in United States / NY can be created | **PASS** | 201 {"company":{"id":"company_602d1181857bb567dfe194c34951f216","name":"R141 New York LLC","legalName":"R141 New York LLC","businessType":"corpo |
| CO-02 | R141 | Stored country and state; no Canadian starter codes; accounting only (payroll is Canada only) | **PASS** | ["United States/NY/accounting","0"] |
| CO-03 | R141 | Canadian company with non-Canadian province refused | **PASS** | [422,"province_invalid"] |
| CO-04 | R141 | Unknown country refused | **PASS** | [422,"country_invalid"] |
| CO-05 | R141 | Payroll-only company outside Canada refused | **PASS** | [422,"payroll_canada_only"] |
| CO-06 | R141 | Payroll Support setup refused for a company outside Canada | **PASS** | [422,"payroll_canada_only"] |
| FC-01 | R141 | Tax codes for a country (US) and a state (US-NY) saved | **PASS** | US:US,NY:US-NY |
| FC-02 | R141 | Foreign customers saved with country and optional state | **PASS** | Brooklyn Client/United States/NY; London Client/United Kingdom/-; Los Angeles Client/United States/CA |
| FC-03 | R141 | Canadian customer without a province refused | **PASS** | [422,"province_invalid"] |
| FC-04 | R141 | NY customer $1,000: state code US-NY 8.875% → $88.75 | **PASS** | [201,8875] |
| FC-05 | R141 | California customer (no US-CA code): falls back to country code US 6% → $60.00 | **PASS** | [201,6000] |
| FC-06 | R141 | UK customer with no UK code: no tax | **PASS** | [201,0] |
| FC-07 | R141 | BC company selling to a US customer (no US code): no Canadian tax applied | **PASS** | [201,0] |
| FC-08 | R141 | Vendor with country India / state MH saved | **PASS** | [201,"India/MH"] |
| FC-09 | R141 | Company Details: state changed to NJ | **PASS** | [200,"NJ"] |
| OB-01 | R141 | Invitation email carries a 10-character code and no secret in any link (the only link is the plain Create account page) | **PASS** | code present · link https://gate.test/app.html?register=1 |
| OB-02 | R141 | Invitation details looked up by POST body (email + code) | **PASS** | 200 |
| OB-03 | R141 | The same code with a different email address is refused | **PASS** | 410 |
| OB-04 | R141 | POST lookup from a foreign origin refused | **PASS** | true |
| OB-05 | R141 | Invitation codes never appear in the web access log | **PASS** | 0 log line(s) contain the code |
| OB-06 | R141 | Password reset email link carries the token after # | **PASS** | 200 https://gate.test/app.html#passwordReset=<token> |
| TR-01 | R141 | Tax Code report usage: BC code document count equals the saved tax detail (SQL) | **PASS** | 4 |
| DM-01 | R141 | Map "Money in the bank" to GL 1000 only | **PASS** | 200 |
| DM-02 | R141 | Map "Profit this year" to 4000 minus 6000 | **PASS** | 200 |
| DM-03 | R141 | Dashboard bank figure = GL 1000 balance (SQL) | **PASS** | 861300 |
| DM-04 | R141 | Dashboard profit = −(4000) − 6000 (SQL), mapped cards listed | **PASS** | [216156,["bank","profit"]] |
| DM-05 | R141 | Wrong account types for profit refused | **PASS** | [422,"dashboard_account_type"] |
| DM-06 | R141 | Viewer cannot change dashboard figures | **PASS** | 403 |
| DM-07 | R141 | Another company's GL account refused | **PASS** | [422,"dashboard_account_unavailable"] |
| DM-08 | R141 | Reset: dashboard figures back to the standard calculation | **PASS** | [1008300,221406,0] |
| CL-01 | R141 | No Microsoft Clarity script or CSP allowance anywhere in the package | **PASS** |  |
| PW-01 | R141 | Product page says Payroll Support Tool, Canada excluding Quebec (R142 wording), no "payroll engine" | **PASS** |  |
| PW-02 | R141 | In-app Payroll Support notice says Canadian payroll outside Quebec (R142 wording) | **PASS** |  |
| WR-01 | R142 | Ontario company, BC starter code note (exact owner-approved text) | **PASS** | "GST only. Charge British Columbia PST only if you are registered with British Columbia. Businesses outside British Columbia may have to register if they sell to customers there. C |
| WR-02 | R142 | Ontario company, QC starter code note (exact text) | **PASS** | "GST only. Charge Quebec QST only if you are registered with Quebec. Businesses outside Quebec may have to register if they sell to customers there. Check with the province. Once r |
| WR-03 | R142 | Ontario company, BC and QC starter codes still GST only | **PASS** | ["GST","GST"] |
| WR-04 | R142 | Sales tax guide carries the six approved sentences | **PASS** |  |
| WR-05 | R142 | Old guide sentences removed ("works out GST/HST from the customer's province", "Some items are zero-rated or exempt") | **PASS** |  |
| WR-06 | R142 | Product Activity note: specific identification, no LIFO, count-based steps, no "yet" | **PASS** |  |
| WR-07 | R142 | Ask Tegh Product Activity answer matches (no "does not track yet") | **PASS** |  |
| WR-08 | R142 | Payroll notice (both bundles) says outside Quebec; old "provinces and territories" claim gone | **PASS** |  |
| WR-09 | R142 | Product and Subscriptions pages say Canada, excluding Quebec; no "Canada only" | **PASS** |  |
| WR-10 | R142 | The claim matches the code: a Quebec employee is refused | **PASS** | 422 quebec_payroll_unsupported |
| WR-11 | R142 | Payroll workspace lists the loaded CRA T4127 tables (label from date) | **PASS** | "CRA T4127 — January 2026/2026-01-01 ; CRA T4127 — July 2026/2026-07-01" |
| IN-00 | R144 | Insights endpoint answers (owner) | **PASS** | 200 |
| IN-01 | R144 | Duplicate vendor invoice found: Northern Office Supply NO-1 / NO-2, $226.00, 7 days apart | **PASS** | ["Northern Office Supply has two vendor invoices for $226.00 dated 2026-09-08 and 2026-09-01 (NO-2 and NO-1)."] |
| IN-02 | R144 | Duplicate customer invoice found: Coastal Rentals, two × $525.00 on 2026-09-20 | **PASS** | ["INV-1002 and INV-1003 are both $525.00 to Coastal Rentals on 2026-09-20."] |
| IN-03 | R144 | Unusual vendor invoice: Rogers RW-09 $565.00 = 5.0× the usual $113.00 (last 4) | **PASS** | ["RW-09 is $565.00, about 5× the usual $113.00 from this vendor (last 4 invoices)."] |
| IN-04 | R144 | Only RW-09 is flagged as unusual (RW-08 at the usual amount is not) | **PASS** | 1 |
| IN-05 | R144 | Tax code mismatch: ON code on an invoice to a BC customer | **PASS** | ["Tax code ON on INV-1004 doesn't match the customer's region"] |
| IN-06 | R144 | Unassigned Expense balance $75.00 flagged | **PASS** |  |
| IN-07 | R144 | Bank line waiting more than 30 days flagged (1 line, oldest 2026-08-20) | **PASS** | ["1 bank line waiting more than 30 days The oldest unposted bank line is from 2026-08-20. Until they are posted or matched, the books and the bank will not agree."] |
| IN-08 | R144 | No false alarms: no negative bank and no payroll findings in this company | **PASS** | duplicate_invoice,duplicate_bill,unusual_amount,parked_balance,tax_code_region,stale_bank_lines |
| IN-10 | R144 | Brief: cash $4,887.00, up $4,943.50 from 30 days ago (−$56.50 on 2026-09-01) | **PASS** | Cash in the bank is $4,887.00, up $4,943.50 from 30 days ago. |
| IN-11 | R144 | Brief: 2 customer invoices overdue ($2,599.00), oldest 62 days late | **PASS** | 2 customer invoices are overdue ($2,599.00); the oldest is 62 days late. |
| IN-12 | R144 | Brief: 4 vendor invoices past due ($452.00); 2 due in the next 7 days ($452.00) | **PASS** | 4 vendor invoices are past due ($452.00). / 2 vendor invoices are due in the next 7 days ($452.00). |
| IN-13 | R144 | Brief: GST/HST owing $167.00 (collected 260+25+25+39 − credits 52+65+52+13) | **PASS** | You owe about $167.00 in GST/HST (collected minus input tax credits). |
| IN-14 | R144 | Brief: 2 bank lines waiting in Match and Post | **PASS** | 2 bank lines are waiting in Match and Post. |
| IN-15 | R144 | Brief: profit so far this year $1,825.00 (income 3,300 − expenses 1,475) | **PASS** | Profit so far this year is $1,825.00. |
| IN-16 | R144 | Brief: anomaly count matches the Worth-a-look list | **PASS** | Tegh noticed 6 things worth a look, starting with: possible duplicate invoice to Coastal Rentals. |
| IN-20 | R144 | Who to chase first: Lakeview Dental ($2,260.00, 62 days) ahead of Coastal Rentals ($339.00, 3 days) | **PASS** | [["Lakeview Dental",226000,62],["Coastal Rentals",33900,3]] |
| IN-21 | R144 | Cash runway: balance $4,887.00 and six months of monthly change | **PASS** | {"balanceCents":488700,"months":[{"month":"2026-04","netChangeCents":0},{"month":"2026-05","netChangeCents":0},{"month":"2026-06","netChangeCents":0},{"month":"2026-07","netChangeC |
| IN-30 | R144 | Bank suggestion for the pending Rogers line: 6300 Telephone and Internet, ON code, 67% (2 of 2 similar lines × 2/3) | **PASS** | {"accountId":"account_f106b5080efe0919b54f576de598c1a2","accountLabel":"6300 · Telephone and Internet","taxCode":"CODE:taxcode_ec8cd6ee53b1805059b7a450d1b9f218","taxName":"ON","con |
| IN-31 | R144 | No suggestion for a line with no history (no guessing) | **PASS** | {"suggestions":{}} |
| IN-40 | R144 | "how much did I spend on telephone this year" → $1,000.00 (bills 4×100+500, bank 2×50) | **PASS** | You spent $1,000.00 on 6300 Telephone and Internet in this year. |
| IN-41 | R144 | "what were my sales this year" → $3,300.00 | **PASS** | Sales (income) were $3,300.00 in this year. |
| IN-42 | R144 | "biggest expenses this year" → 6300 Telephone and Internet $1,000.00 first, then 6400 Office Supplies $400.00 | **PASS** | Your biggest expense in this year was Telephone and Internet ($1,000.00). [{"label":"6300 Telephone and Internet","amountCents":100000},{"label":"6400 Office Supplies","amountCents |
| IN-43 | R144 | "top customers this year" → Lakeview Dental $2,000.00 before tax, then Coastal Rentals $1,300.00 | **PASS** | Your biggest customer in this year was Lakeview Dental ($2,000.00 before tax, 1 invoice). [{"label":"Lakeview Dental","amountCents":200000},{"label":"Coastal Rentals","amountCents" |
| IN-44 | R144 | "how much did we pay rogers in september" → vendor answer: invoices $565.00, payments $0.00 in September 2026 | **PASS** | Rogers Wireless: vendor invoices of $565.00 and payments of $0.00 in September 2026. |
| IN-47 | R144 | "advertising and marketing this year" → only 6100 Advertising and Promotion, $0.00, this year (not March; "and" ignored) | **PASS** | You spent $0.00 on 6100 Advertising and Promotion in this year. |
| IN-45 | R144 | "telephone in Q3" → $800.00 (RW-07 100 + RW-08 100 + RW-09 500 + bank Aug 50 + Sep 50) | **PASS** | You spent $800.00 on 6300 Telephone and Internet in Q3 2026. |
| IN-46 | R144 | Not a books question → not answered (falls through to normal Assist) | **PASS** | {"answered":false,"currency":"CAD"} |
| IN-50 | R144 | "Not a problem" keeps the finding listed as dismissed and drops it from the brief count | **PASS** | 200 |
| IN-51 | R144 | Undo restores it | **PASS** |  |
| IN-52 | R144 | Dismissal recorded in the audit trail | **PASS** | "2" |
| IN-61 | R144 | Insights, questions and suggestions create no journal entries and change no bank line | **PASS** | ["15","pending"] |
| IN-62 | R144 | Company isolation: another company cannot get suggestions for this company's bank line | **PASS** | 200 {"suggestions":{}} |
| IN-63 | R144 | Dismiss refuses a bad CSRF token | **PASS** | 403 |
| IN-64 | R144 | insights_r144.php makes no outbound calls (no curl, sockets, HTTP clients or AI provider) | **PASS** |  |
| W1-01 | E2E | New Customer form saves the customer with province and terms | **PASS** | "ON/30" |
| W2-01 | E2E | New Invoice form: 2 × $500 to an Ontario customer issues for $1,130.00 (HST 13%) | **PASS** | INV-1001/113000/sent / 1130 shown |
| W2-02 | E2E | Issued invoice posts Dr 1200 $1,130 / Cr 4000 $1,000 / Cr 2100 $130 | **PASS** | "1200:113000,2100:-13000,4000:-100000" |
| W3-01 | E2E | Credit note from the register: return 1 × $500 posts Dr 4000 $500, Dr 2100 $65, Cr 1200 $565 | **PASS** | ["56500","1200:-56500,2100:6500,4000:50000"] |
| W3-02 | E2E | Invoice outstanding after credit note = $565.00 | **PASS** | "56500" |
| W4-01 | E2E | Payment form pre-fills the outstanding $565.00; saving settles the invoice (balance 0, status paid) | **PASS** | ["565.00","0/paid"] |
| W4-02 | E2E | Receipt posts Dr 1000 $565 / Cr 1200 $565 | **PASS** | "1000:56500,1200:-56500" |
| W5-01 | E2E | New Vendor form saves the vendor with province | **PASS** | "ON" |
| W6-01 | E2E | New Vendor Invoice: $200 + HST posts Dr 6400 $200, Dr 1100 $26, Cr 2050 $226 | **PASS** | ["22600","1100:2600,2050:-22600,6400:20000"] |
| W7-01 | E2E | Vendor payment pre-fills $226.00; saving pays the bill (balance 0) | **PASS** | ["226.00","0"] |
| W7-02 | E2E | Vendor payment posts Dr 2050 $226 / Cr 1000 $226 | **PASS** | "1000:-22600,2050:22600" |
| W8-01 | E2E | GL Journal Entry: depreciation Dr 6810 $100 / Cr 1590 $100 posts | **PASS** | "1590:-10000,6810:10000" |
| W-JS | E2E | No JavaScript errors during W3–W8 | **PASS** |  |
| W9-01 | E2E | Upload Statement → preview (3 rows, money in $565.00, money out $282.50) → Continue imports 3 unposted bank lines | **PASS** | rows=3 summary=3 selected / 3 rows · 3 search results · Money out $282.50 · Money in $565.00 |
| W9-02 | E2E | Import creates no General Ledger entries | **PASS** | "0" |
| W9-03 | E2E | Uploading the same statement again: every row marked Duplicate, nothing selected, import disabled | **PASS** | dup=3 disabled=true 0 selected / 3 rows · 3 search results · Money out $0.00 · Money in $0.00 |
| W9-04 | E2E | Cancel import removes the draft preview | **PASS** | "0" |
| W10-01 | E2E | Post a $56.50 phone bill to 6300 with the ON code: Dr 6300 $50.00, Dr 1100 $6.50, Cr 1000 $56.50 | **PASS** | ["posted","1000:-5650,1100:650,6300:5000"] |
| W10-02 | E2E | Posting preview shows the HST split before posting | **PASS** |  |
| W10-JS | E2E | No JavaScript errors in Match and Post | **PASS** |  |
| W11-01a | E2E | Selecting the $565.00 bank line offers the recorded payment (Maple Leaf Cafe · INV-1001) | **PASS** | Already recorded?This bank line matches a payment you recorded. Linking uses the existing entry, so nothing is posted twice.Maple Leaf Cafe · INV-1001 · $565.00 |
| W11-02a | E2E | Link: bank line posted against the payment's own journal entry; payment linked | **PASS** | posted/journal_f357a9876387a8eb8969e5a09ec025a8 / btx_91695aa3fdf995c9047823eb430ac883/journal_f357a9876387a8eb8969e5a09ec025a8 |
| W11-03a | E2E | Nothing posted twice: linking creates no journal entry | **PASS** | "7" |
| W11-01b | E2E | Selecting the $226.00 bank line offers the recorded payment (Northern Office Supply · NOS-5521) | **PASS** | Already recorded?This bank line matches a payment you recorded. Linking uses the existing entry, so nothing is posted twice.Northern Office Supply · NOS-5521 ·  |
| W11-02b | E2E | Link: bank line posted against the payment's own journal entry; payment linked | **PASS** | posted/journal_2220dbdf5fa58a6cc54d7addb62f2a16 / btx_52d9e4f8be88f95b63630007c1e7faa3/journal_2220dbdf5fa58a6cc54d7addb62f2a16 |
| W11-03b | E2E | Nothing posted twice: linking creates no journal entry | **PASS** | "7" |
| W11-04 | E2E | GL 1000 equals the bank statement closing balance ($282.50) | **PASS** | "28250" |
| W11-JS | E2E | No JavaScript errors | **PASS** |  |
| W12-00 | E2E | A balanced period that ends in the future says it can be completed once the period has ended (not "Ready to complete") | **PASS** |  |
| W12-01 | E2E | Reconciliation: bank $282.50 = books $282.50, difference $0.00, ready to complete | **PASS** |  |
| W12-02 | E2E | Complete Reconciliation saves a completed snapshot (period end today, difference 0) | **PASS** | "complete/2026-10-01/0" |
| W13-01 | E2E | Profit and Loss: income $500, expenses $350 (6300 $50, 6400 $200, 6810 $100), net $150 | **PASS** | Total ExpensesCAD 0.00CAD 0.00CAD 0.00CAD 0.00CAD 0.00CAD 0.00CAD 0.00CAD 0.00CAD 0.00CAD 350.00CAD 350.00CAD 0.00CAD 350.00N/ANet Profit / (Loss)Net profit / ( |
| W13-02 | E2E | Balance Sheet: bank $282.50, assets $215.00 = liabilities $65.00 + equity $150.00, difference $0.00 | **PASS** |  |
| W13-03 | E2E | Trial Balance: closing debits = closing credits = $665.00 (1000 282.50 + 1100 32.50 + 6300 50 + 6400 200 + 6810 100), difference $0.00 | **PASS** | 665.00 / 665.00 / 0.00 |
| W14-01 | E2E | Dashboard: money in the bank $282.50, customers owe $0.00, you owe suppliers $0.00, profit this year $150.00 | **PASS** | Money in the bank↗$282.50What your books show todayCash a / Profit this year↗$150.00Jan 1, 2026 – Oct 1, 2026Net pro |
| W14-02 | E2E | Dashboard sales tax card: HST owing $32.50 (collected $65.00 − paid $32.50) | **PASS** | GST/HST payable$32.50 |
| W12-JS | E2E | No JavaScript errors in reconciliation, reports, dashboard | **PASS** |  |
| W15-00 | E2E | Payroll setup screen creates payroll controls (RP account, biweekly, regular remitter) | **PASS** | "123456789RP0001/biweekly/regular" |
| W15-01 | E2E | Add Employee saves Priya Sharma, ON, biweekly, $52,000 salary, no SIN stored | **PASS** | "Priya Sharma/ON/biweekly/5200000/nosin" |
| W16-01 | E2E | Draft pay run figures: gross $2,000.00, CPP $110.99, EI $32.60 (independent), net = gross − deductions | **PASS** | ["200000","11099","3260","160159"] |
| W16-02 | E2E | Income tax within $0.05 of the hand calculation ($254.83) | **PASS** | Tegh 25482 |
| W16-03 | E2E | Employee verified (formula source, evidence note stored) | **PASS** | "1" |
| W16-04 | E2E | Verify Pay Run dialog: button stays disabled until a reference is entered AND the "I have reviewed and verified…" box is ticked | **PASS** | empty=true refOnly=true ticked=true |
| W16-05 | E2E | Pay run verified (status no longer draft) | **PASS** | verified / gl ready_to_post |
| W16-06 | E2E | Post to GL: Dr 7000 wages $2,000, Dr 7010 employer CPP+EI; Cr 2300 net, 2310 tax, 2320 CPP (both), 2330 EI (both); balanced | **PASS** | gl=posted got 2300:-160159,2310:-25482,2320:-22198,2330:-7824,7000:200000,7010:15663 want 2300:-160159,2310:-25482,2320:-22198,2330:-7824,7000:200000,7010:15663 |
| W16-07 | E2E | Employer CPP = employee CPP ($110.99); employer EI = 1.4 × employee EI ($45.64) | **PASS** | ["11099","4564"] |
| W16-JS | E2E | No JavaScript errors in payroll | **PASS** |  |
| W17-01 | E2E | Assist "how much money is in the bank" shows $282.50 (answer or the Bank Accounts page it opens) | **PASS** | PAGE: Manage Bank Accounts … able · Account Number Not AvailableActiveBook Balance (CAD)$282.50Latest Statement Bal |
| W17-02 | E2E | Assist "who owes me money" reflects receivables of $0.00 | **PASS** | Receivable Ageing As of Oct 1, 2026 Nobody owes you money right now. See the details Open full report Change dates |
| W17-03 | E2E | Assist "what is my profit this year" answers a loss of $2,006.63 | **PASS** | Profit & Loss Jan 1, 2026 – Oct 1, 2026 You made a loss of $2,006.63. Money earned $500.00 · Costs $2,506.63 See the details Open full report Change dates |
| W18-01 | E2E | Invoice PDF downloads and shows INV-1001, the customer, HST $130.00 and total $1,130.00 | **PASS** | %PDF- INVOICE E2E Workflow Ltd INV-1001 Oct 1, 2026 · CAD From Bill to Invoice details ON, Canada Tax number: 123456789RT0001  |
| W18-02 | E2E | Profit and Loss export downloads and contains Service Revenue 500.00 and Wages and Salaries 2,000.00 | **PASS** | Tegh-Profit-and-Loss.csv |
| W17-JS | E2E | No JavaScript errors in Assist and exports | **PASS** |  |
| CV-A1 | R145 | Statement A: every row read with an amount and a date (12 rows, none needing manual entry) | **PASS** | [12,0] |
| CV-A2 | R145 | Statement A: net, money out and money in match the statement | **PASS** | [11213,347732,358945] |
| CV-A3 | R145 | Statement A: opening and closing balances read and the rows balance to them | **PASS** | [512345,523558,true,false] |
| CV-A4 | R145 | Statement A: first and last dates (years from the statement period) and the reviewed hand-off | **PASS** | ["2026-01-02","2026-01-30",null,12,512345,523558] |
| CV-A5 | R145 | Statement A: the review screen says the balances agree | **PASS** | Opening balance $… with these rows, comes to the closing balance of $… printed on the statement ✓ All 6 running balances agree. |
| CV-A6 | R145 | Statement A: wrapped description lines are joined to their transaction, and the side panel is ignored | **PASS** | [9,false] |
| CV-B1 | R145 | Statement B: every row read with an amount and a date (10 rows, none needing manual entry) | **PASS** | [10,0] |
| CV-B2 | R145 | Statement B: net, money out and money in match the statement | **PASS** | [59867,91232,151099] |
| CV-B3 | R145 | Statement B: opening and closing balances read and the rows balance to them | **PASS** | [-245000,-185133,true,true] |
| CV-B4 | R145 | Statement B: first and last dates (years from the statement period) and the reviewed hand-off | **PASS** | ["2025-12-08","2026-01-06",null,10,-245000,-185133] |
| CV-B5 | R145 | Statement B: the review screen says the balances agree | **PASS** | Balance owed $… with these rows, comes to the new balance owed of $… printed on the statement ✓ |
| CV-B6 | R145 | Card statement: December rows get 2025 and January rows 2026; payments are money in, charges money out; the payment slip after "Continued" is not a row | **PASS** | ["2025-12-30",[true,true,true],false] |
| CV-C1 | R145 | Statement C: every row read with an amount and a date (9 rows, none needing manual entry) | **PASS** | [9,0] |
| CV-C2 | R145 | Statement C: net, money out and money in match the statement | **PASS** | [135352,52103,187455] |
| CV-C3 | R145 | Statement C: opening and closing balances read and the rows balance to them | **PASS** | [800000,935352,true,false] |
| CV-C4 | R145 | Statement C: first and last dates (years from the statement period) and the reviewed hand-off | **PASS** | ["2025-09-02","2025-09-15",null,9,800000,935352] |
| CV-C5 | R145 | Statement C: the review screen says the balances agree | **PASS** | Opening balance $… with these rows, comes to the closing balance of $… printed on the statement ✓ All 5 running balances agree. All 4 page totals agree. |
| CV-C6 | R145 | Chequing statement: all page totals (Debits/Credits count and amount) agree; balance-forward lines and the footer note are not rows | **PASS** | [4,true,false] |
| CV-E1 | R145 | Statement E: every row read with an amount and a date (4 rows, none needing manual entry) | **PASS** | [4,0] |
| CV-E2 | R145 | Statement E: net, money out and money in match the statement | **PASS** | [235438,14562,250000] |
| CV-E3 | R145 | Statement E: opening and closing balances read and the rows balance to them | **PASS** | [100000,335438,true,false] |
| CV-E4 | R145 | Statement E: first and last dates (years from the statement period) and the reviewed hand-off | **PASS** | ["2026-03-02","2026-03-15",null,4,100000,335438] |
| CV-E5 | R145 | Statement E: the review screen says the balances agree | **PASS** | Opening balance $… with these rows, comes to the closing balance of $… printed on the statement ✓ All 4 running balances agree. |
| CV-D1 | R145 | Statement with a missing line: still read, but flagged as not balancing, with the running-balance difference marked on the row | **PASS** | [11,false,true,true,true] |
| CV-F1 | R145 | Statement with no column headings: the column reader steps aside and the general reader still reads it | **PASS** | [3,null,-77900,"2026-04-01"] |
| CV-B7 | R145 | Card statement read into a bank account: the review warns that a credit card account may be the right one | **PASS** | This looks like a credit card statement, but a bank account is selected. Choose the credit card account if that is the r |
| CV-UI1 | R145 | Converter screen: statement A shows a green "balances agree" check, 12 rows and nothing needing correction | **PASS** | 12 dated rows · Nothing imported or posted. |
| CV-UI2 | R145 | Confirming the review hands the rows and the statement balances to the server preview, which shows no difference | **PASS** | 12 reviewed rows · Jan 2, 2026 to Jan 30, 2026 · CAD. / preview 12 rows, opening 512345, closing 523558 |
| CV-UI3 | R145 | Converter screen: the card statement into the credit card account balances (owed amounts shown as owed) | **PASS** | Balance owed $2,450.00, with these rows, comes to the new balance owed of $1,851.33 printed on the statement ✓ |
| CV-UI4 | R145 | Converter screen: the statement with a missing line shows an amber "do not add up" check and marks the row | **PASS** | These rows do not add up: Opening balance $5,123.45, with these rows, comes to $3,735.58, not the closing balance of $5,235.58. Check for a missing or misread l |
| DI-I1 | R145 | Document Intake reads the text PDF invoice (two-column header, line items, HST, Net 30): every field right | **PASS** | pdf_text; partyName, documentNumber, documentDate, dueDate, subtotalCents, taxCents, totalCents, businessIdentifier, lineItems / R144 reader: 8/9 |
| DI-I2 | R145 | Document Intake reads the French (Québec) invoice: TPS + TVQ, "185,69 $" amounts, "15 mars 2026": every field right | **PASS** | pdf_text; partyName, documentNumber, documentDate, subtotalCents, taxCents, totalCents, lineItems / R144 reader: 1/7 |
| DI-I3 | R145 | Document Intake reads the phone-style receipt photo with a date that reads both ways (03/04/2026): every field right | **PASS** | ocr; partyName, documentNumber, documentDate, alternatives, subtotalCents, taxCents, totalCents / R144 reader: 5/7 |
| DI-I4 | R145 | Document Intake reads the scanned (image-only) PDF invoice: GST + PST, "Mar 20, 2026", Net 15: every field right | **PASS** | ocr; partyName, documentNumber, documentDate, dueDate, subtotalCents, taxCents, totalCents / R144 reader: 0/7 (error: this[#fr].getOrInsertComputed is not a function) |
| DI-I6 | R145 | Document Intake reads the tiny low-contrast café receipt photo: every field right | **PASS** | ocr; partyName, documentNumber, documentDate, subtotalCents, taxCents, totalCents / R144 reader: 1/6 |
| DI-I7 | R145 | Document Intake reads the scanned bill that is mostly blank page (little text): every field right | **PASS** | ocr; partyName, documentNumber, documentDate, subtotalCents, taxCents, totalCents / R144 reader: 0/6 (error: this[#fr].getOrInsertComputed is not a function) |
| DI-I5 | R145 | Document Intake reads the fuel receipt photo with tax "included" in the total: every field right | **PASS** | ocr; partyName, documentDate, subtotalCents, taxCents, totalCents / R144 reader: 4/5 |
| DI-CMP | R145 | Field accuracy across the six documents, R145 reader vs the R144 reader (for information) | **INFO** | R145 47/47 fields; R144 19/47 fields |
| DI-AMB | R145 | An ambiguous numeric date is never guessed: the date stays empty and both readings are offered | **PASS** | ["",2,true] |
| DI-SCAN | R145 | Scanned PDFs are read by OCR in this browser (the bundled PDF reader no longer needs Map.getOrInsertComputed) | **PASS** | ["ocr",true] |
| DI-UI0 | R145 | DEF-14: the Upload document panel shows a full-width "Upload and Extract Locally" button inside the panel | **PASS** | button 372×40 at x 890, form right edge 1262 |
| DI-UI1 | R145 | Document Intake screen: the uploaded invoice shows the read fields and "Subtotal + tax = total ✓" | **PASS** | INV-20418 / 2026-03-04 / 2026-04-03 / 359.00 / 46.67 / 405.67 / Subtotal + tax = total ✓ |
| DI-UI2 | R145 | Changing the tax so the amounts no longer add up shows the difference straight away | **PASS** | Subtotal + tax is more than the total by 3.33. Check the amounts against the source. |
| DI-UI3 | R145 | A receipt photo with 03/04/2026 offers "March 4, 2026" and "April 3, 2026"; choosing one fills the date and the review saves as ready | **PASS** | March 4, 2026 / April 3, 2026 → 2026-03-04; state ready |
| CV-JS | R145 | No JavaScript errors | **PASS** |  |
| CV-NOPOST | R145 | Nothing was imported, drafted or posted: no bank transactions, vendor invoices or journal entries in the test company | **PASS** | ["0","0","0"] |
| IC-01 | R149 | The invitation email shows the code, the invited email and the Create account page, and carries no setup link or token | **PASS** | [true,true,true,false,true] |
| IC-02 | R149 | Plain app.html: two tabs, Sign in selected | **PASS** | Sign in*,Create account |
| IC-03 | R149 | Create account tab asks for the email address and the invitation code, and names the email to look for | **PASS** | inviteEmail,inviteCode /  Create your account Tegh is in an invitation-only beta. When you are invited, you receive an email titled “Se |
| IC-04 | R149 | "Join Beta" (app.html?register=1) opens the Create account tab | **PASS** | Sign in,Create account* |
| IC-05 | R149 | The code is formatted as it is typed (lower case and spaces accepted) | **PASS** | typed "vxhuq j3g8t" → "VXHUQ-J3G8T" |
| IC-06 | R149 | Email + code opens the account form: invited email, company and role, suggested name; both tabs still shown | **PASS** | newperson-mvg1d0zp@gate.test / R144 Insights Inc — Viewer / "Newperson Mvg1d0zp" / Sign in,Create account* |
| IC-07 | R149 | Switching to Sign in and back keeps the invitation; arrow keys move between the tabs | **PASS** | newperson-mvg1d0zp@gate.test / sr-login-form |
| IC-08 | R149 | Creating the account with a typed name opens Tegh signed in; name, role and terms are stored | **PASS** | Priya Example-Test/invitation/terms / role viewer |
| IC-09 | R149 | A wrong code is refused with a plain message; the tab stays open | **PASS** | That email address and invitation code don’t match an open invitation. Check both against your email. Codes expire 72 ho |
| IC-10 | R149 | The right code with a different email address is refused | **PASS** | That email address and invitation code don’t match an open invitation. Check both against your email |
| IC-11 | R149 | An already-used code is refused | **PASS** | That email address and invitation code don’t match an open invitation. Check both against your email |
| IC-12 | R149 | A code with letters that are never used (O, I, L, 0, 1) is caught before sending | **PASS** | The invitation code has 10 letters and numbers, for example K7M2Q-PX9RT. It never contains 0, 1, O,  |
| IC-13 | R149 | Someone who already has an account: "Accept your invitation" with the current password only | **PASS** | {"name":false,"confirm":false,"btn":"Accept invitation"} |
| IC-14 | R149 | Phone (390×844): tabs, email and code fields, and the account form fit without sideways scrolling | **PASS** | {"sw":390,"cw":390} |
| IC-15 | R149 | Server: a name with < > is refused; an empty name falls back to the email-derived name (email + code) | **PASS** | [422,200,"Srv Mvg1d0zp"] |
| IC-16 | R149 | Guessing is limited: after 10 tries for one email within 15 minutes the server answers 429, even for the right code | **PASS** | [true,429] |
| IC-18 | R149 | Correct look-ups never use up the allowance (12 in a row all succeed), so one office can invite several people | **PASS** | true |
| IC-17 | R149 | After too many tries the Create account tab says to wait 15 minutes | **PASS** | Too many attempts. Wait 15 minutes, then try again. |
| IC-19 | R149 | An invitation link sent before R149 (token after #) still opens the account form | **PASS** | legacy-mvg1d0zp@gate.test / Sign in,Create account* |
| IC-JS | R149 | No JavaScript errors | **PASS** |  |
| DOC-01 | R151 | Itemized vendor invoice saves and posts (status open) | **PASS** | [201,"open"] |
| DOC-02 | R151 | Header totals are the sum of the lines: net $927.75, tax $16.24, total $943.99 | **PASS** | "92775/1624/94399/94399" |
| DOC-03 | R151 | Each line posts to its own GL account; recoverable tax to 1100; non-recoverable PST added to the paper line (6400) | **PASS** | ["1100:1367/0","1500:80000/0","2050:0/94399","6300:9100/0","6400:3932/0"] |
| DOC-04 | R151 | Three lines stored with their accounts, tax and cost-tax | **PASS** | "6300:9100:1183:0,6400:3675:441:257,1500:80000:0:0" |
| DOC-05 | R151 | Header keeps the largest line’s account (1500) for registers | **PASS** | "1500" |
| DOC-06 | R151 | Workspace returns the lines for editing | **PASS** | [true,3,"Printer paper"] |
| DOC-07 | R151 | Draft vendor invoice edited in full (lines replaced): total $276.00 | **PASS** | [200,"27600/2"] |
| DOC-08 | R151 | Issued edited draft posts the edited lines | **PASS** | [200,["1100:2600/0","2050:0/27600","6400:5000/0","6700:20000/0"]] |
| DOC-09 | R151 | Tax-inclusive line: $113.00 → net $100.00 + HST $13.00 | **PASS** | ["1100:1300/0","2050:0/11300","6300:10000/0"] |
| DOC-10 | R151 | Issued vendor invoice: due date and memo change; amounts untouched | **PASS** | [200,"2026-11-30/Pay by cheque/94399"] |
| DOC-11 | R151 | Issued vendor invoice: changing the number is refused | **PASS** | [422,"bill_edit_fields_forbidden"] |
| DOC-12 | R151 | Issued vendor invoice cannot be rewritten in full | **PASS** | [409,"posted_bill_edit_blocked"] |
| DOC-13 | R151 | A line on a control account is refused | **PASS** | [422,"document_line_account"] |
| DOC-14 | R151 | Another company’s GL account is refused | **PASS** | [422,"document_line_account"] |
| DOC-15 | R151 | Lines adding to zero are refused | **PASS** | [422,"bill_amount_invalid"] |
| DOC-16 | R151 | Single-amount vendor invoices (imports, recurring) still work unchanged: $100 + $13 | **PASS** | [201,["1100:1300/0","2050:0/11300","6400:10000/0"]] |
| DOC-17 | R151 | Customer invoice with two income accounts: total $1,695.00 | **PASS** | "169500" |
| NOTE-01 | R151 | Linked credit note posts to the GL account chosen on its line | **PASS** | [200,["1200:0/22600","2100:2600/0","4100:20000/0"]] |
| NOTE-02 | R151 | Linked credit note reduces the original invoice to $1,469.00 | **PASS** | "146900" |
| NOTE-03 | R151 | Credit note with a typed invoice number (not in Tegh) is saved as a draft with its own totals | **PASS** | [201,"none/OLD-778/35000/3900/38900"] |
| NOTE-04 | R151 | It posts as an open credit: each line to its GL account, HST reversed | **PASS** | [200,["1200:0/38900","2100:3900/0","4000:30000/0","4100:5000/0"]] |
| NOTE-05 | R151 | Register shows the reference, open credit $389.00 and both lines with accounts | **PASS** | ["OLD-778",38900,2,true] |
| NOTE-06 | R151 | The open credit can be applied to an invoice: $100 off → balance $1,369.00 | **PASS** | [200,"136900"] |
| NOTE-07 | R151 | Unlinked debit note posts Dr AR / Cr revenue / Cr HST and creates a $90.40 receivable | **PASS** | [200,["1200:9040/0","2100:0/1040","4000:0/8000"],"sent/9040/1"] |
| NOTE-08 | R151 | Unlinked supplier credit: cost and non-recoverable PST back out of 6400, GST out of 1100 | **PASS** | [200,["1100:0/500","2050:11200/0","6400:0/10700"]] |
| NOTE-09 | R151 | Linked supplier note credits the line’s GL account (6400), not the header account (1500) | **PASS** | [200,true,false,"2050:1246/0"] |
| NOTE-10 | R151 | Draft note edited on the main screen: same number, new totals and reference | **PASS** | [200,true,"X-1B/2260/1"] |
| NOTE-11 | R151 | A posted note cannot be edited | **PASS** | [409,"note_edit_unavailable"] |
| NOTE-12 | R151 | Without an original, the invoice number is required | **PASS** | [422,"note_reference_required"] |
| NOTE-13 | R151 | A note line on AR control is refused | **PASS** | [422,"document_line_account"] |
| NOTE-14 | R151 | Without an original, each line needs a GL account | **PASS** | [422,"document_line_account"] |
| NOTE-15 | R151 | A note with an application must have it reversed before voiding | **PASS** | [409,"note_has_settlements"] |
| NOTE-16 | R151 | Unlinked supplier credit voids with a reversing entry | **PASS** | [200,"void",["1100:500/0","2050:0/11200","6400:10700/0"]] |
| DOC-18 | R151 | GL 1200 = $1,170.40 (136,900 + 9,040 − 28,900) and the receivable trial balance builds without error | **PASS** | [117040,200] |
| DOC-19 | R151 | Customer ledger lists the typed-reference note | **PASS** | [200,true] |
| DOC-20 | R151 | Invoice & note register shows the reference as the original number | **PASS** | [200,true] |
| DOC-21 | R151 | Payable trial balance builds with itemized invoices and notes | **PASS** | 200 |
| DOC-22 | R151 | Cash basis: the itemized vendor invoice posts nothing until paid | **PASS** | [201,0] |
| DOC-23 | R151 | Cash basis: a $500 payment is spread over the lines’ accounts and recoverable tax to the cent | **PASS** | [201,["1100:724/0","1500:42373/0","6300:4820/0","6400:2083/0"]] |
| DOC-24 | R151 | Backup export succeeds with the new line tables present | **PASS** | 200 |
| UI-01 | R151UI | One form: Document type offers Invoice, Credit note and Debit note (customer and vendor) | **PASS** | Invoice/Credit note/Debit note / Vendor invoice/Supplier credit note/Supplier debit note |
| UI-02 | R151UI | Layout: details on top, line grid below with Product/Service, Description, Qty, Rate, Tax, Amount, Total and GL account; no side summary | **PASS** | #/product / service/description/qty/rate/tax/amount/total/gl account {"above":true,"gridWidth":100,"aside":false} |
| UI-03 | R151UI | Clicking “Add line” adds a row without the page jumping to the top | **PASS** | scroller .r22-workspace.r22-scroll-region.r22-primary-scroll.r22-task-flow before 148 after 148 rows 7 |
| UI-04 | R151UI | Removing a line and changing a line also keep the scroll position | **PASS** | before 148 after 148 |
| UI-05 | R151UI | Customer invoice with two lines (different tax and GL) saves and issues; totals and GL match the lines | **PASS** | Subtotal$380.00Tax$39.00Invoice total$419.00 ["1200:41900/0","2100:0/3900","4000:0/30000","4100:0/8000"] |
| UI-06 | R151UI | Vendor invoice with two lines posts each line to its own GL account | **PASS** | ["1100:2600/0","2050:0/122600","6400:20000/0","6950:100000/0"] |
| UI-07 | R151UI | Credit note: typing a number that is not in Tegh says so; saved against the customer with its own tax and GL | **PASS** | Not found in Tegh for this customer. Saved as a reference; tax and GL come from the lines  / posted/none/5650 |
| UI-08 | R151UI | Credit note: typing an existing invoice number links it; tax follows the original; “Return items” fills its lines | **PASS** | Linked to INV-1002 · Oct 1, 2026 · $419.00 open. Tax follows rows 2 taxLocked true |
| UI-09 | R151UI | Switching the document type in the dropdown changes the form (vendor invoice number → original invoice number) | **PASS** | Vendor•Choose vendorR151 Supplies Co+ Add New Vendor… / Original Invoice Number• / Note Date• / ReasonChoose a reasonGoods returned to supplierShort shipmentPrice differenceDamaged |
| UI-10 | R151UI | Invoice register: the row Actions menu has Edit, which opens the draft on the main screen (no pop-up); changes are saved | **PASS** | menu Edit invoice/View invoice details dialogs 0 total 2500 |
| UI-11 | R151UI | Issued invoice: Edit opens on the main screen with amounts locked; the due date changes | **PASS** | locked true 2026-12-15/41900 |
| UI-12 | R151UI | Invoice detail page: Edit opens the main-screen form, not a pop-up | **PASS** | open dialogs 0 |
| UI-13 | R151UI | Vendor register: Edit on an issued vendor invoice changes the memo only | **PASS** | Paid by EFT/122600 |
| UI-14 | R151UI | Note register: a draft note has Edit, which opens it on the main screen | **PASS** | DRAFT-REF-mvg1dl8s 700 |
| UI-17 | R151UI | Vendor invoice: “Make this a recurring vendor invoice” still creates the schedule | **PASS** | profile 90000/6950 |
| UI-18 | R151UI | Customer and product dropdowns still offer “add new” without leaving the form | **PASS** | + Add New Customer… / + Add Product or Service… |
| UI-15 | R151UI | Sidebar: right after choosing a menu item, the mouse wheel scrolls the menu by exactly the wheel amount (up and down) | **PASS** | max 521 start 0 → +60 → 60 (want 60) → −40 → 20 (want 20) |
| UI-16 | R151UI | Phone (390×844): the form fits; the line grid scrolls sideways inside its own box | **PASS** | {"sw":390,"cw":390,"grid":true,"ox":"auto"} |
| UI-JS | R151UI | No JavaScript errors | **PASS** |  |
| ST-G | R152 | Statement G — RBC-style (DD Mon, "Cheques & Debits", two pages): every row, the net, opening and closing balances, first/last dates; balances agree | **PASS** | got [16,-98181,842210,744029,"2026-01-02","2026-01-31",true,false,true] want [16,-98181,842210,744029,"2026-01-02","2026-01-31",true,false,true] |
| ST-H | R152 | Statement H — BMO-style (headings on two lines, "Closing totals"): every row, the net, opening and closing balances, first/last dates; balances agree | **PASS** | got [16,-98181,1200000,1101819,"2026-01-02","2026-01-31",true,false,true] want [16,-98181,1200000,1101819,"2026-01-02","2026-01-31",true,false,true] |
| ST-I | R152 | Statement I — Scotia-style (MM/DD, "Balance forward", overdrawn balances): every row, the net, opening and closing balances, first/last dates; balances agree | **PASS** | got [8,-312316,300000,-12316,"2026-01-02","2026-01-30",true,false,true] want [8,-312316,300000,-12316,"2026-01-02","2026-01-30",true,false,true] |
| ST-J | R152 | Statement J — Desjardins-style French (code column, "02 janv.", French amounts): every row, the net, opening and closing balances, first/last dates; balances agree | **PASS** | got [13,984957,1534212,2519169,"2026-01-02","2026-01-30",true,false,true] want [13,984957,1534212,2519169,"2026-01-02","2026-01-30",true,false,true] |
| ST-K | R152 | Statement K — National Bank-style French (DD/MM numeric dates, "Solde précédent"): every row, the net, opening and closing balances, first/last dates; balances agree | **PASS** | got [13,984957,2000000,2984957,"2026-01-02","2026-01-30",true,false,true] want [13,984957,2000000,2984957,"2026-01-02","2026-01-30",true,false,true] |
| ST-L | R152 | Statement L — Tangerine-style (signed amounts, full dates): every row, the net, opening and closing balances, first/last dates; balances agree | **PASS** | got [16,-98181,450000,351819,"2026-01-02","2026-01-31",true,false,true] want [16,-98181,450000,351819,"2026-01-02","2026-01-31",true,false,true] |
| ST-M | R152 | Statement M — Amex-style card (Dec→Jan, negative credits): every row, the net, opening and closing balances, first/last dates; balances agree | **PASS** | got [10,-9663,-212560,-222223,"2026-01-02","2026-01-28",true,true,true] want [10,-9663,-212560,-222223,"2026-01-02","2026-01-28",true,true,true] |
| ST-N | R152 | Statement N — French Visa (two date columns, "CR" credits): every row, the net, opening and closing balances, first/last dates; balances agree | **PASS** | got [10,-9663,-98765,-108428,"2026-01-02","2026-01-28",true,true,true] want [10,-9663,-98765,-108428,"2026-01-02","2026-01-28",true,true,true] |
| ST-P | R152 | Statement P — card with MM/DD dates across December→January: every row, the net, opening and closing balances, first/last dates; balances agree | **PASS** | got [6,-62238,-54321,-116559,"2025-12-08","2026-01-04",true,true,true] want [6,-62238,-54321,-116559,"2025-12-08","2026-01-04",true,true,true] |
| ST-Q | R152 | Statement Q — credit union (DR/CR column, OD balances): every row, the net, opening and closing balances, first/last dates; balances agree | **PASS** | got [6,11750,50000,61750,"2026-01-03","2026-01-29",true,false,true] want [6,11750,50000,61750,"2026-01-03","2026-01-29",true,false,true] |
| ST-R | R152 | Statement R — two-line rows (amount on the line under the description): every row, the net, opening and closing balances, first/last dates; balances agree | **PASS** | got [10,-7543,500000,492457,"2026-01-02","2026-01-21",true,false,true] want [10,-7543,500000,492457,"2026-01-02","2026-01-21",true,false,true] |
| ST-S | R152 | Statement S — French with thousands split into separate text pieces: every row, the net, opening and closing balances, first/last dates; balances agree | **PASS** | got [13,984957,2500000,3484957,"2026-01-02","2026-01-30",true,false,true] want [13,984957,2500000,3484957,"2026-01-02","2026-01-30",true,false,true] |
| ST-O1 | R152 | A PDF with three statements (Jan and Feb for …4567, Jan for …9012): all three are listed; for account …4567 both months are read together and each starts at the previous closing balance | **PASS** | got [["4567","4567","9012"],"acct:4567",20,842210,636629,true,true,"4567"] / 2 statements from this PDF are read together. Opening balance $8,422.10, with these rows, comes to the  |
| ST-O2 | R152 | The third statement in that PDF can be chosen on its own: 2 rows for account …9012 with its own balances | **PASS** | [2,25000,100000,125000,true,"9012"] |
| ST-AMB | R152 | Numeric dates that read correctly both ways inside the statement period (01/02 and 02/01 in Jan–Feb) are not guessed; choosing day/month reads them | **PASS** | {"amb":true,"undated":2,"dates":["2026-02-01","2026-01-02"],"reconciled":true} |
| ST-REG | R152 | The R145 statements (bank, card, page totals, missing line, ISO dates, no headings) read exactly as before | **PASS** | [["A",12,12,true,true,1],["B",10,10,true,true,1],["C",9,9,true,true,1],["D",11,11,false,false,1],["E",4,4,true,true,1],["F",3,3,null,null,1]] |
| ST-UI | R152 | Converter screen: a PDF with three statements says so, lists them under "Statement in this PDF", and reading statement 3 shows its 2 rows | **PASS** | This PDF holds 3 statements; choose which to read under Statement in this PDF. 16 dated ro / options 4: All 2 statements for account …4567, read together / Statement 1: Jan 1, 2026 |
| IN-01 | R152 | Vendor invoice (EN): the 4 lines are read as a table — description (wrapped line joined, item codes left out), quantity, unit price, amount, each checked — and they add up to the subtotal | **PASS** | [["Copy paper, letter, 10 reams","3",5499,16497,true],["Toner cartridge HP 26A (high yield, black)","2",18950,37900,true],["Desk organizer","1",2345,2345,true],["Delivery","1",1500 |
| IN-02 | R152 | Verifying it on the screen (vendor preselected by name) stores the reader's own candidate for learning — not the document text — and starts the supplier memory | **PASS** | state ready; extracted candidate 915 chars, no document text; memory AA-9999-9999/1 |
| IN-03 | R152 | Coding suggestions come from this vendor's earlier invoices: paper → 6400 and toner → 6300 by wording; the organizer and delivery get the vendor's most used account 6400 | **PASS** | [2,true,[[0,"6400","line_match"],[1,"6300","line_match"],[2,"6400","vendor_default"],[3,"6400","vendor_default"]]] / Most used on 2 earlier invoices from this vendor |
| IN-04 | R152 | "Open in Vendor Invoice Form" fills the vendor invoice form with the 4 lines and their suggested accounts, compares its total with the document total (a wrong tax code shows up); saving the draft links the document to the new vendor invoice | **PASS** | form lines 4; check before: The document shows a total of $611.54 with tax of $29.12. This form comes to $663.96 — the lines agree, so check the tax code on each line. / after GST: |
| IN-05 | R152 | The same invoice sent again (a different file) is flagged as a duplicate of the saved vendor invoice, by supplier and number | **PASS** | This May Be A DuplicateVendor invoice NW-2026-0142 · 2026-03-03 · 611.54 · Draft — same supplier and numberI checked: this is a different invoice.Needed before a draft or the vendo |
| IN-06 | R152 | The suggested account is preselected on its draft form, and a draft or workflow from the duplicate is refused until "this is a different invoice" is confirmed | **PASS** | preselected 6400; "Suggested: Most used on 3 earlier invoices from this vendor."; without confirmation 409 native_document_possible_duplicate; with 200 |
| IN-07 | R152 | French invoice with 03/04/2026: the date is not guessed (two readings offered); its lines (Qté, Prix unitaire, Montant) are read and checked | **PASS** | {"alts":[{"dateOrder":"mdy","documentDate":"2026-03-04"},{"dateOrder":"dmy","documentDate":"2026-04-03"}],"lines":[5100,5950,3275],"total":16471} |
| IN-08 | R152 | Choosing "April 3, 2026" and verifying teaches the supplier memory day/month order for this supplier | **PASS** | memory date order dmy |
| IN-09 | R152 | The next invoice from that supplier (05/06/2026) is read as June 5, 2026 from supplier memory, and the screen says why | **PASS** | 2026-06-05 / Supplier Memory AppliedDate read as day/month/year, as confirmed on earlier invoices from this supplier.From what you confirmed on earlier i |
| IN-10 | R152 | A supplier whose invoice number sits under an unusual label ("Our reference"): once the number is corrected by hand, the next invoice's number is found by the learned pattern | **PASS** | first read "", learned AA/99-9999, next read "HF/77-2096" |
| IN-11 | R152 | Privacy: the memory lookup sends only the extracted identity (name, email, business number, address) and no request carries the document text | **PASS** | 14 requests; memory keys partyName,partyEmail,businessIdentifier,address; document text sent: false |
| IN-12 | R152 | Company isolation: another company cannot read this company's coding suggestions or supplier memory | **PASS** | coding 404; memory 200 null |
| IN-13 | R152 | Backup export includes the supplier memory | **PASS** | 200, 164383 bytes zipped, supplier memory present: true |
| IN-JS | R152 | No page errors during the R152 journeys | **PASS** |  |
| CO-01 | R153 | Creating a company with an address, phone, email and short name stores them; the company list returns them with the printed address | **PASS** | ["ALP","500 Front St W","Suite 1200","Toronto","M5V 0A1","416-555-0123","office@alpine.example","500 Front St W\nSuite 1200\nToronto, ON M5V 0A1\nCanada"] |
| CO-02 | R153 | Without a short name, the initials of the company name are used ("Bayview Trading Associates" → BTA) | **PASS** | BTA (set by owner: false) |
| CO-03 | R153 | Company Details saves a new address and short name; invalid values are refused with a plain message | **PASS** | 200 BTA2/10 Water St/B3J 1A2/NS; bad email 422 "Enter a valid business email."; long short name 422 "Short name must be 12 characters or fewer." |
| CO-04 | R153 | A customer invoice prints the company address, phone and email (the seeded "ON, Canada" template address no longer stands in for it) | **PASS** | ["500 Front St W\nSuite 1200\nToronto, ON M5V 0A1\nCanada","416-555-0123","office@alpine.example"] |
| CO-05 | R153 | A template with its own address keeps it; an issued invoice keeps the address it was issued with after the company moves | **PASS** | template: PO Box 77, Toronto ON / issued invoice after move: 500 Front St W |
| UI-01 | R153 | Company Details shows the address block and short name; saving from the screen updates the company | **PASS** | 500 Front St W / Toronto / ALP; phone saved 416-555-0199 |
| MC-01 | R153 | Customer Invoice & Note Register with both companies: every line has a Co. label — ALP twice, BTA once | **PASS** | /Co./Document/Document Type · {"ALP":2,"BTA":1} |
| MC-02 | R153 | Profit and Loss with both companies: lines labelled ALP and BTA and total income $1,300.00 (ALP $1,050.00 + BTA $250.00), with no notice saying the figures were withheld | **PASS** | Total Income	CAD 1,300.00	 / page says "select one company": false |
| MC-03 | R153 | Receivable Ageing with both companies: the three open invoices are listed with their company | **PASS** | ["","ALP","ALP","BTA","Total 1–30"] |
| MC-04 | R153 | Day Book with both companies: each voucher row shows its company; the two companies' entries are not merged | **PASS** | ["ALP","ALP","BTA"] |
| MC-05 | R153 | Audit History, Tax Code Report and Trial Balance with both companies show the Co. column with both labels | **PASS** | [["Audit History",true,true,true],["Tax Code Report",true,true,true],["Trial Balance",true,true,true]] |
| MC-06 | R153 | Reports about one GL or bank account, and Budgets, never show only the first company's figures: each opens its combined screen (R154) | **PASS** | [["General Ledger Account Report",true,0],["Bank General Ledger Report",true,0],["Budget versus Actual",true,0]] |
| MC-07 | R153 | The Excel export of the combined register contains the Co. column and both companies | **PASS** | Tegh-Customer-Invoice-Note-Register.xlsx: Co. true, ALP true, BTA true |
| MC-08 | R153 | With one company selected, reports are unchanged: no Co. column | **PASS** | /Document/Document Type · 2 rows |
| NAV-01 | R153 | The arrow beside a module opens and closes its submenu without leaving the page, and two submenus can be open at once | **PASS** | after opening: Banking true, Receivables true; page stayed "Vendors"; after closing Banking: false; arrow 28×28 |
| NAV-02 | R153 | A submenu closed with its arrow stays closed while moving between pages of that module; clicking the module name opens it again | **PASS** | after navigating: false; after clicking the name: true |
| TH-01 | R153 | The profile menu has Light, Dark and System icon buttons (none in the top bar); Dark switches the app to dark and is remembered after reload; System follows the device fully (dark page on a dark device, light when it turns light) | **PASS** | top bar buttons 0; profile: Light theme:icon,Dark theme:icon,System theme:icon · dark dark → after reload dark · System on a dark device: dark (page brightness 56), device turns li |
| TH-02 | R153 | On a phone the three theme choices are in the profile menu, all visible, and each tap applies at once | **PASS** | ["dark:3","auto:3","light:3"] |
| TH-03 | R153 | The Appearance page shows the same Light, Dark and System icons | **PASS** | 3 icons; Theme System Follow this device’s light or dark setting. Light Use the light acc |
| R153-JS | R153 | No page errors during the R153 journeys | **PASS** |  |
| GL-01 | R154 | GL Account Report with both companies: choosing account 4000 shows each company's balance and the combined closing balance of $1,250.00 CR | **PASS** | {"GLA":"$1,000.00 CR","GLB":"$250.00 CR","Combined":"$1,250.00 CR"} |
| GL-02 | R154 | The ledger below the summary lists both companies' lines, each labelled GLA or GLB, grouped as "GLA · 4000 …" and "GLB · 4000 …" | **PASS** | ["GLA","GLB"] / GLA · 4000 · Service Revenue / GLA · 4000 · Service Revenue	CAD 0.00	CAD 1,000.00	-CAD 1,000.00	 |
| GL-03 | R154 | An account only one company has (6990) runs for that company and says the other has no such account | **PASS** | 2 summary rows; Not in GLB — Golden Lynx Bakery mvg1hwh2: that company has no account 6990. |
| BL-01 | R154 | Bank General Ledger Report with both companies: one bank account per company; lines labelled GLA and GLB; the receipts of $600.00 and $250.00 both appear | **PASS** | 2 bank pickers; ["GLA","GLB"] |
| BL-02 | R154 | Leaving one company out ("Not included") runs the report for the other company only | **PASS** | ["GLA"] |
| BU-01 | R154 | Budgets with both companies: every company's budget is listed with its company; planned $1,500.00 and actual $1,250.00 together | **PASS** | GLA:FY2026 plan mvg1hwh2, GLB:FY2026 plan mvg1hwh2 / Combined (budgets chosen for Budget vs Actual) / $1,500.00 / $1,250.00 / $250.00 / 2 of 2 companies |
| BU-02 | R154 | The combined Budget versus Actual lists account 4000 once per company: GLA planned 1,200.00 / actual 1,000.00, GLB 300.00 / 250.00 | **PASS** |  GLA 4000 Service Revenue — CAD 1,200.00 CAD 1,000.00 CAD 200.00  /  GLB 4000 Service Revenue — CAD 300.00 CAD 250.00 CAD 50.00  |
| EX-01 | R154 | The Excel export of the combined Budget versus Actual contains the Co. column and both companies' lines | **PASS** | Tegh-Budget-vs-Actual.xlsx: Co. true, GLA true, GLB true |
| BU-03 | R154 | Creating or editing a budget still needs one company; the combined page says so and has no create form | **PASS** | create form 0 |
| ONE-01 | R154 | With one company selected the GL Account Report, Bank GL Report and Budgets are unchanged (no Co. column, no company pickers) | **PASS** | GL account picker 1; bank pickers 0; combined budget list 0; budgets workspace 1 |
| R154-JS | R154 | No page errors during the R154 journeys | **PASS** |  |
| BS-01 | BETA | Public account creation is closed: registering without an invitation is refused | **PASS** | 403 registration_closed "New account registration is currently closed." |
| BS-02 | BETA | A Document Intake file of one company cannot be downloaded through another company or by a user without access; its own company gets the same bytes | **PASS** | own 200 same bytes true; via other company 404; user without access 403 |
| BS-03 | BETA | An invoice attachment of one company cannot be downloaded through another company | **PASS** | attachment saved; own 200 same bytes true; via other company 404 |
| BS-04 | BETA | A removed member loses access at once: the signed-in session is refused and signing in again gives no access to the company | **PASS** | before 200; remove 200 ; same session after 401; new sign-in 403 |
| BS-05 | BETA | A revoked invitation cannot be accepted | **PASS** | revoke 200; accept after revoke 410 "That email address and invitation code don’t match an open invitation. Check the code in your email, or ask for a new invitation." |
| BS-06 | BETA | Password reset: the email carries a one-time link; the new password works, the old one and the link are refused afterwards, and earlier sessions end | **PASS** | request 200; link in email; reset 200; reuse 410; new password true; old password refused; earlier session 401 |
| BS-07 | BETA | Accepting an invitation records the terms and privacy versions shown on the published Terms and Privacy pages | **PASS** | pages: terms 2026-10-07, privacy 2026-10-08; recorded 2026-10-07/2026-10-08 |
| BS-08 | BETA | A deletion request ("Delete login"): the person can no longer sign in, and their email address is gone from the account, invitations, sent-email records and the platform log | **PASS** | delete 200; records with the address before 3, after 0; account Deleted user/0/1; deletion record keeps a hash only true; sign-in refused |
| BS-10 | BETA | Invitations are refused while the operator details are incomplete (a config without them reports every missing field) | **PASS** | without operator config: 8 fields missing; invitation paths guarded 3; this host complete true |
| BR-10 | BETA | A company backup carries its uploaded files: restored into a new company, the Document Intake file and the invoice attachment come back byte for byte | **PASS** | export 200 (62909 bytes); restore 201; document 200 same true; attachment 200 same true |
| BA-01 | BETA | A payment sent twice with the same operation key (a repeated click or retry) is recorded once | **PASS** | responses 200/201/200; payments recorded 1; invoice balance 30000 (400.00 − 100.00 = 300.00) |
| BA-02 | BETA | Every posted journal entry on the gate database balances (debits = credits), and each company's trial balance nets to zero | **PASS** | 100 posted entries in 17 companies; unbalanced entries 0; companies out of balance 0 |
| BA-03 | BETA | Double-clicking Save and post on a new customer invoice creates one invoice | **PASS** | invoices created by a double-click: 1; posted invoice journals 1 |
| BM-01 | BETA | Currencies: a company cannot be created with a non-CAD ledger; a USD invoice shows in USD on its line, and the combined totals add its CAD amount (USD 1,000.00 × 1.35 = CAD 1,350.00), never the USD figure | **PASS** | USD-ledger company refused (422); line:  BBW INV-1002 Invoice — Boston Client mvg1iqvh Sep 15, 2026 Oct 15, 2026 USD 0.00 USD 1,000.00 USD 1,000.00 Sent Actions; totals: TOTAL CAD  |
| BN-01 | BETA | The private beta notice (config app.beta_notice) is visible in the top bar for every signed-in user (not covered by anything), readable in light and dark, and shows the full text when tapped | **PASS** | desktop "Private beta · sample data only" visible true; phone "Beta" visible true; contrast 6.8/10.0:1; tap shows: true |
| BS-09 | BETA | Terms and Privacy pages: the operator, contacts, hosting, beta conditions, deletion procedure and first-party measurement are shown; an incomplete configuration shows a warning | **PASS** | terms missing: none; privacy missing: none; incomplete warning: "This notice is incomplete: the operator of this Tegh service has not yet provided Legal name of the business o" |
| BD-01 | BETA | Accounting date: a browser whose own date is already Oct 1 (UTC+14) while it is still the evening of Sep 30 in Toronto opens Match and Post for a bank line dated Oct 1 (never From after To), and its report periods end on the Toronto date | **PASS** | bank lines 2026-09-28,2026-10-01; browser's own date 2026-10-01; Match and Post: opened; 4 period requests, workspace 2026-09-28..2026-10-01 200; inverted or refused none; Bank Rec |
| BETA-JS | BETA | No page errors during the beta checks | **PASS** |  |
| ON-01 | R157 | A company created with onboarding starts locked, with only the company step complete; its time zone is saved | **PASS** | 201; locked true; pending chart,tax,banks,imports,team; time zone America/Vancouver; accounts besides 9999: 0 |
| ON-02 | R157 | A company created without onboarding (every company before R157, and API-created books) is never locked | **PASS** | new API company required false, locked false; existing company required false, locked false |
| ON-03 | R157 | Chart of accounts from the standard template: the core accounts and two bank accounts are added; applying it twice is refused | **PASS** | 201, 44 accounts; core codes 1000,1100,1200,2000,2100,2110,3000,4000; bank accounts bank:1000,credit_card:2000; second time 409 chart_not_empty |
| ON-04 | R157 | Tax codes from a file: a row naming an account that is not in the chart is refused with its row number; a valid file creates each code with its taxes, rates and accounts | **PASS** | bad file 422: "Row 3: GL account 2999 is not in the chart of accounts."; good file 201; stored BC-GST-PST/GST/5000/2100/1100/1 BC-GST-PST/PST/7000/2110/-/0 ON-HST/HST/13000/2100/11 |
| ON-05 | R157 | Only an owner or admin can mark steps; the company step cannot be reopened; other steps can; the last step finishes onboarding, which then cannot be reopened | **PASS** | viewer 403; company step reopen 409; done 2,3,4,5; reopen tax → 4; finished true, locked false; reopen after finish 409 onboarding_finished; audit entries 7 |
| TZ-01 | R157 | The company time zone: an unknown zone is refused; Company Details saves a new one | **PASS** | unknown zone 422 "Choose a time zone from the list."; update 200 ; stored America/Edmonton |
| TZ-02 | R157 | The accounting date follows the company: on the same moment an invoice dated the UTC+14 company’s today is accepted there, and refused as a future date in a Toronto company when that day has not begun in Toronto or UTC | **PASS** | server moment 2026-10-01T16:41:31.229Z: Kiritimati 2026-10-02, Toronto 2026-10-01, UTC 2026-10-01; Kiritimati company 201; Toronto company 400 Invoice date cannot be in the future. |
| QA-01 | R157 | Quick Actions chosen in one company are used in another company of the same person where none were chosen yet | **PASS** | company B before: default; after choosing in A: {"version":3,"actionIds":["report.profit_loss","nav.bank_import","nav.customers"],"source":"user"} |
| ON-06 | R157 | A new user’s first screen is onboarding step 1 (create the company) with all six steps shown; the company step asks for the time zone | **PASS** | 1 Company / 2 Chart of accounts / 3 Tax codes / 4 Bank accounts / 5 Data import / 6 Team & invoices; time zone choices 8 |
| ON-07 | R157 | After the company is created the onboarding page opens; modules are locked (lock badges), a report opens the onboarding page instead, and a setup screen opens with a bar back to setup | **PASS** | first page onboarding; locked true; lock badges 8; steps 6; time zone America/Winnipeg; chart accounts 0; P&L → onboarding "Profit and Loss opens once setup is finished. Complete t |
| ON-08 | R157 | The chart of accounts step applies the template from the page and then shows what Tegh detects | **PASS** | "Tegh sees 44 accounts in this company."; template now disabled true |
| ON-09 | R157 | Signing in again while onboarding is incomplete opens the onboarding page again | **PASS** | after sign-in: onboarding |
| ON-10 | R157 | Settings lists Onboarding and the Tegh tour | **PASS** | Settings › Onboarding opens onboarding |
| ON-11 | R157 | Marking the remaining steps complete on the page finishes onboarding and unlocks Tegh | **PASS** | finished card 1; locked false; lock badges 0; completed in database 1 |
| ON-12 | R157 | The Tegh Assist tour spotlights the real menus step by step and ends with the bank statement call to action, which opens Upload Statement | **PASS** | 9 stops (9 spotlit): The Dashboard is your daily view* → Banking* → Receivables* → Payables* → General Ledger* → Reports* → Search and Ask Tegh* → Your profile* → Settings*; final  |
| ON-13 | R157 | Signing in after onboarding opens Home (not onboarding), nothing is locked, and the animated “start with a bank statement import” card is shown while there are no bank lines | **PASS** | after sign-in dashboard; locked false; bank card 1 |
| TZ-03 | R157 | In the browser a company’s “today” (new invoice date) is its own date: the UTC+14 company shows its date, the Toronto company Toronto’s | **PASS** | UTC+14 company 2026-10-02 (expected 2026-10-02); Toronto company 2026-10-01 (expected 2026-10-01) |
| QA-02 | R157 | Each Home shortcut shows its keyboard keys, and Ctrl+Alt+2 opens the second shortcut from another page | **PASS** | keys Ctrl+Alt+1, Ctrl+Alt+2, Ctrl+Alt+3; labels Profit & loss, Trial balance, Customers; Ctrl+Alt+2 from Customers opened trial-balance-general-ledger "Trial Balance" |
| BE-01 | R157 | Document Intake and Upload Statement are marked Beta with a reminder to check what is read before relying on it | **PASS** | document-intake: badge 1, "Beta. Document Intake reads vendor invoices and receipts in your browser. Check "; bank-imports: badge 1, "Beta. The statement reader (PDF converter) is  |
| BE-02 | R157 | A new vendor invoice has a Document Intake section marked Beta; choosing a file sends it through Document Intake, which reads it and offers “Create vendor invoice” | **PASS** | section above the form true, Beta 1; documents 0 → 1; now on document-intake; "Create vendor invoice" offered true |
| R157-JS | R157 | No page errors during the R157 checks | **PASS** |  |
| DEL-01 | R158 | The test company is in full use: rows in the tables added since R133 (onboarding, tax codes, notes, vendor invoice lines, client viewing link, code and session) | **PASS** | credit note 201; 29 tables hold rows of the company; children {"components":"1","billLines":"2","noteLines":"1","viewCodes":"1","viewSessions":"1"} |
| DEL-02 | R158 | Wrong company name, wrong password, no backup confirmation, and a non-owner are all refused; nothing is deleted | **PASS** | name 409 company_name_mismatch; password 403 password_incorrect; no backup 409 company_backup_required; Company Admin 403 permission_forbidden; company still there 1; rows unchange |
| DEL-03 | R158 | The platform owner (Tegh support) deletes the company directly: accepted, and no row of it is left in any table with a company_id (read from the live schema), nor its tax code components, vendor invoice lines, note lines, client sign-in codes or sessions | **PASS** | 200 ; company rows 0; left behind {}; children {"components":"0","billLines":"0","noteLines":"0","viewCodes":"0","viewSessions":"0"} |
| DEL-04 | R158 | The deletion is logged once with the record counts from before the deletion; other companies are untouched | **PASS** | log rows 1; logged onboarding 1, tax codes 1, client links 1; mismatches none; another company still has 2 journal entries |
| DEL-05 | R158 | In the browser: Account & Access › the company’s Actions › Request deletion on a used company, then Platform Owner Home › Approve; the company is gone and its rows are deleted | **PASS** | after the request the company is archived (active 0); approval 200; company rows 0; left behind {}; page errors none |
| PC-01 | R159 | Adding a second company in Settings › Add company starts its own onboarding: locked, the company step done, an empty chart and no tax codes (nothing copied from the first company or a default); the app opens its onboarding page | **PASS** | onboarding note 1, chart choice hidden true; new company locked true, pending chart,tax,banks,imports,team; its accounts 0, tax codes 0; page after creating onboarding |
| PC-02 | R159 | The second company’s chart template and tax codes are its own: the first company’s accounts and tax codes are unchanged, and the second company gets exactly what its own file says | **PASS** | first company before {"accounts":"44","taxCodes":"1","bankAccounts":"2"} after {"accounts":"44","taxCodes":"1","bankAccounts":"2"}, codes ON-HST; second company {"accounts":"44","t |
| PC-03 | R159 | Onboarding is per company in the browser: the finished first company opens Home without locks; the second company opens its onboarding with locked modules | **PASS** | first company opens dashboard (locked false); second company opens onboarding (locked true) |
| PC-04 | R159 | The server enforces it for every way a company is created: a company created with onboarding gets no pre-filled chart or starter tax codes even when the request asks for the default chart; without onboarding (API, platform test companies) the default chart is unchanged | **PASS** | with onboarding: {"accounts":"0","taxCodes":"0","bankAccounts":"0"}; without onboarding: {"accounts":"44","taxCodes":"14","bankAccounts":"2"} |
| AR-01 | R159 | The owner archives a company: it leaves the owner’s company list, cannot be opened (any request with it is refused), and is listed under Archived companies; its records are untouched | **PASS** | archive 200; in company list false; in archived list true; opening it 403 company_forbidden; archiving again 409 company_already_archived; active/archived 0/1; journal lines 2/3000 |
| AR-02 | R159 | Only the owner can archive or restore: a Company Admin gets 403, a person who is not a member or an unknown id gets 404; once archived, the Company Admin no longer sees it and nobody can be invited to it | **PASS** | Company Admin archiving 403; platform owner (not a member) 404; unknown id 404; Company Admin sees it before true, after archiving false, opening it 403, restoring it 403; inviting |
| AR-03 | R159 | Restore brings the company back to the list with the same records; it can be opened again | **PASS** | restore 200; in list true; opens 200; journal lines 2/30000/30000; restoring again 409; audit company.archived,company.restored |
| AR-04 | R159 | In the browser: Account & Access › Actions › Archive, confirm; the company leaves the table and appears under Archived companies; Restore brings it back | **PASS** | after Archive: in the company table {"pages":1,"here":0,"anywhere":0}, active 0; after Restore active 1; page errors none |
| DR-01 | R159 | A company owner can no longer delete directly: operations/company-delete answers 409 “done by Tegh support”, and the company is untouched | **PASS** | 409 company_delete_by_request: "Permanent deletion is done by Tegh support. Send a deletion request from Account & Access; you can archive the company now." |
| DR-02 | R159 | The deletion request checks name, password, backup and reason; a valid request archives the company, waits as pending, and emails Tegh support; a second request is refused | **PASS** | wrong name 409, wrong password 403, no backup 409, no reason 422; valid 201; duplicate 409 deletion_request_pending; request pending/r159-owner-mvg1r41v@gate.test/1; company active |
| DR-03 | R159 | Only the platform owner sees and decides requests: the company owner gets 403 on the support inbox; the platform owner sees the request with the amount of records it would remove | **PASS** | owner on the inbox 403; platform owner sees pending, from r159-owner-mvg1r41v@gate.test, {"tables":20,"rows":124,"invoices":1,"bills":0,"journalEntries":1} |
| DR-04 | R159 | Rejecting needs a note; a rejected request keeps the company (archived, restorable) and emails the owner; the owner can then restore it | **PASS** | reject without note 422; reject 200; request rejected; owner emailed 1; archived list shows rejected; deciding again 409 |
| DR-05 | R159 | Cancelling: the owner cancels a pending request (the company stays archived); restoring a company cancels its pending request | **PASS** | cancel 200 → cancelled, company active 0; restore with a pending request 200 (cancelled 1) → cancelled, active 1 |
| DR-06 | R159 | Approving needs the exact company name and the platform owner’s password; then the company is deleted: no row left in any company table, a deletion log naming the request, the request approved with its record counts, and the owner emailed | **PASS** | wrong name 409; wrong password 403; approve 200; company rows 0; left behind {}; deletion log names request true; request approved/1; owner emailed 1 |
| DR-07 | R159 | In the browser: the owner sends a deletion request from Account & Access; the company leaves the list and shows “waiting for Tegh support”; the platform owner approves it on Platform Owner Home and the company is gone | **PASS** | owner sees "R159 Epsilon mvg1r41v Oct 1, 2026 r159-owner-mvg1r41v@gate.test Deletion requested · waiting for Tegh support Actions"; after approval company rows 0, left behind {}; p |
| INV-01 | R159 | An address whose login was deleted is not given a second account: inviting it again is refused and points to Restore login (R160) | **PASS** | delete 200; invitation again 409 invitation_user_deleted: "This email was registered before and its login was deleted. Use “Restore login” to bring the old login back and send the  |
| INV-02 | R159 | An address whose login is deactivated is refused when inviting, with a clear message (not a code that can never be used); the deactivated person sees a clear message too | **PASS** | invite 409 invitation_user_deactivated: "This email belongs to a deactivated Tegh login. Use “Restore login” to reactivate it and send the person a password reset email."; email se |
| INV-03 | R159 | An existing active login is invited to more companies: accepting asks only for the current password and adds the access; an independent-workspace invitation for them is refused as unnecessary | **PASS** | workspace invitation 409 invitation_user_exists; company invitation 200; invitee page says existing user true; accepted with current password 200; membership viewer/active |
| IN-01 | R160 | A new email: the check says “new”; one invitation gives both their own workspace and access to a company; after accepting they have that access and can create their own company | **PASS** | check new; invitation 200; accepted 200; access viewer/active; own company 201 |
| IN-02 | R160 | A registered email: the check says “registered” with the companies they already have; an own-workspace invitation is refused; access to more companies works and is accepted with the current password | **PASS** | check registered, has company true; own workspace 409 invitation_user_exists; company they already have 409; new company 200, accepted with current password 200, access editor/acti |
| IN-03 | R160 | A deactivated login: the check says “deactivated” with the date; inviting is refused; Restore reactivates it with its company access and emails that it was previously deactivated, with a link that sets a new password | **PASS** | check deactivated (2026-10-01 16:15:19); invite 409 invitation_user_deactivated; restore 200; email "previously deactivated" true; new password 200; sign-in ok; access kept viewer/ |
| IN-04 | R160 | A deleted login: the check recognises the address from its fingerprint (“deleted”, with the date); inviting is refused; Restore puts the same login back (same id), emails that it was previously deleted, and the link sets a new password | **PASS** | address kept after deletion 0; check deleted (2026-10-01 16:15:22); invite 409 invitation_user_deleted; restore 200; same login true; email "previously deleted" true; new password  |
| IN-05 | R160 | Only the platform owner can check an email or restore a login; a company admin inviting a deleted address is told to ask Tegh support | **PASS** | admin check 403; admin restore 403; admin invites a deleted address 409 "This email had a Tegh login that was removed. Ask Tegh support to restore it, then invite it again." |
| UI-01 | R160 | Invitations & Access opens from Platform Owner Home with three tabs; a new email shows the two choices, and the company list opens only for “Work in my companies”; sending shows the result | **PASS** | tabs Invite Someone / Sent Invitations / Sign-up & Email; "New to Tegh What should r160-ui-mvg1srf5@gate.test be able t"; list hidden until chosen true, send off until a choice tru |
| UI-02 | R160 | A registered email shows “Already on Tegh”, no own-workspace choice, and marks the companies they already have | **PASS** | "Already on Tegh R160 New Mvg1srf5 r160-new-mvg1srf5@gate.test has a Tegh login since Oct 1"; own-workspace choice shown 0; company they have disabled true |
| UI-03 | R160 | A deleted email shows “Registered before · login deleted” and only Restore; Restore works from the page and offers to give access next | **PASS** | "Registered before · login deleted r160-uidel-mvg1srf5@gate.test was re"; invitation button 0; "Login restored for r160-uidel-mvg1srf5@gate.test The email was sent: it explains the |
| UI-04 | R160 | Sent invitations and Sign-up & email tabs show the list and the settings; no page errors on a phone width | **PASS** | sent rows 35; settings visible true; phone overflow 0px; page errors none |
| C-01 | R161 | 160 bills, invoices and expenses saved at the same moment in one company (16 at a time): every save succeeds, with no duplicate or skipped voucher number and every entry balanced | **PASS** | {"W":16,"N":160,"status":{"exp 201":40,"bill 201":80,"inv 201":40},"vouchers":160,"duplicateSerials":0,"duplicateNumbers":0,"serialGaps":0,"unbalanced":0} |
| S-01 | R161 | Seeding through the API: Northwind (400 customers, 150 vendors, 5,000 invoices, 2,000 bills, payments, 400 journals, 3,000 imported bank lines) and Lakeshore (150, 60, 1,500, 600, payments, 150, 1,000) complete without an error | **PASS** | {"name":"Volume Northwind Ltd","id":"company_9d3c70931a478ede6569b3a398df3ed3","counts":{"customers":400,"vendors":150,"invoices":5000,"bills":2000,"customerPayments":3698,"vendorP |
| V-011 | R161 | Northwind: Trial Balance after 5000 invoices, 2000 bills, 4890 payments and 400 journals equals the independently summed figures for every account, and debits equal credits | **PASS** | 200 in 617 ms; 14 accounts; debits $83,162,478.27 credits $83,162,478.27; all equal |
| V-012 | R161 | Northwind: customer and vendor balances (400 customers, 150 vendors) add up to the open receivables and payables, which equal the GL control accounts | **PASS** | AR $24,439,946.44 (expected $24,439,946.44, GL 1200 $24,439,946.44) in 289 ms; AP $1,987,690.34 (expected $1,987,690.34, GL 2050 $1,987,690.34) in 61 ms |
| V-013 | R161 | Northwind: Profit and Loss net income equals revenue less expenses summed independently; Balance Sheet and P&L each load in under 10 seconds | **PASS** | net income $67,832,249.10 expected $67,832,249.10; balance sheet 200 in 235 ms; P&L 200 in 77 ms |
| V-014 | R161 | Northwind: the Trial Balance and party balances each answer in under 10 seconds | **PASS** | GL 617 ms, receivables 289 ms, payables 61 ms |
| V-021 | R161 | Lakeshore: Trial Balance after 1500 invoices, 600 bills, 1483 payments and 150 journals equals the independently summed figures for every account, and debits equal credits | **PASS** | 200 in 107 ms; 14 accounts; debits $26,062,593.45 credits $26,062,593.45; all equal |
| V-022 | R161 | Lakeshore: customer and vendor balances (150 customers, 60 vendors) add up to the open receivables and payables, which equal the GL control accounts | **PASS** | AR $7,498,410.37 (expected $7,498,410.37, GL 1200 $7,498,410.37) in 49 ms; AP $623,457.16 (expected $623,457.16, GL 2050 $623,457.16) in 34 ms |
| V-023 | R161 | Lakeshore: Profit and Loss net income equals revenue less expenses summed independently; Balance Sheet and P&L each load in under 10 seconds | **PASS** | net income $21,127,851.34 expected $21,127,851.34; balance sheet 200 in 112 ms; P&L 200 in 93 ms |
| V-024 | R161 | Lakeshore: the Trial Balance and party balances each answer in under 10 seconds | **PASS** | GL 107 ms, receivables 49 ms, payables 34 ms |
| P-01 | R161 | Northwind: the complete output of all 22 report definitions (registers, ledgers, Bank General Ledger, ageing, trial balances, tax) answers in under 10 seconds each | **PASS** | general-ledger 200 722 ms; gl-account-ledger 200 789 ms; audit-history 200 271 ms; tax-summary 200 103 ms; currency-exposure 200 113 ms; reconciliation-summary 200 32 ms; ar-ageing |
| B-01 | R161 | Northwind in the browser: Profit and Loss shows the net income summed independently (67,832,249.10) | **PASS** | 1963 ms; net shown true; errors none |
| B-02 | R161 | Northwind: Receivable Ageing lists the open invoices and totals 24,439,946.44 | **PASS** | 2528 ms; 1769 rows; total shown true |
| B-03 | R161 | Northwind: the customer invoice register opens with 5000 invoices in time, and the search narrows it to one client | **PASS** | 12742 ms; 5000 rows shown; after search "Client 0007": 13; scrolled to the last row true; errors none |
| B-04 | R161 | Northwind: the period Trial Balance screen shows equal debit and credit totals of the independently summed amount | **PASS** | 1736 ms; 83,162,478.27 shown 4 times |
| B-05 | R161 | Both companies selected: Profit and Loss shows the combined net income 88,960,100.44 | **PASS** | 1607 ms; combined shown true; Northwind alone shown false |
| B-06 | R161 | Both companies: Receivable Ageing totals 31,938,356.81 and labels each line with its company | **PASS** | 2685 ms; 2296 rows; total shown true; company labels , VN |
| B-07 | R161 | Both companies: the all-time Day Book (over 25,000 lines) says it is too large to show complete and to narrow the filters; one month of the Day Book loads with lines from both companies | **PASS** | all time: clear "exceeds 25,000 rows" message (22035 ms); September 2026: 2013 lines in 2935 ms; errors none |
| B-08 | R161 | Guided mode with 3000 bank lines waiting: Home shows the count, the To Do opens the review queue in time, and no amount is covered by the scrollbar | **PASS** | home 1785 ms, count shown true; review queue "3000 matching" in 550 ms; amounts under the scrollbar 0; stale-workspace message false; errors none |
| M-01 | R161 | After the browser checks, no request is still running (a long-running request would hold up the host backup) | **PASS** | none |
| G-00 | GUIDED | Guided test company created with 8 imported statement lines | **PASS** | {"imported":8,"approve":201} |
| G-01 | GUIDED | Guided Home after the import: mode is Guided, the to-do card says "8 Transactions To Post" and the Banking menu badge shows 8 | **PASS** | mode guided; card true; badge 8 |
| G-01b | GUIDED | Signing in again in Guided mode always lands on Guided Home, never the Full Accounting Dashboard (5 reloads) | **PASS** | pages after each reload: guided-bookkeeping, guided-bookkeeping, guided-bookkeeping, guided-bookkeeping, guided-bookkeeping |
| G-02 | GUIDED | The Home to-do button opens Match and Post with all 8 lines unposted and the statement balance $183.60 (565.00 − 113.00 − 25.00 − 3×56.50 − 40.00 − 33.90) | **PASS** | 8 rows; Unposted; balance $183.60 |
| G-03 | GUIDED | Guided Match and Post: "STAPLES OFFICE SUPPLIES 4411" posts to 6400 Office Supplies with GST/HST included; the preview and the journal show 6400 Dr 100.00, 1100 Dr 13.00, 1000 Cr 113.00 | **PASS** | preview: HST 13% recoverable · 1100 · GST/HST Recoverable / $13.00 / — / 6400 · Office Supplies / $100.00 / — / Business Chequing / — / $113.00 / Total / $113.00 / $113.00; journal |
| G-04 | GUIDED | Guided Match and Post: "CLIENT DEPOSIT HARBOUR DESIGN" posts to 4000 Service Revenue with GST/HST included; the preview and the journal show 1000 Dr 565.00, 4000 Cr 500.00, 2100 Cr 65.00 | **PASS** | preview: Business Chequing / $565.00 / — / HST 13% collected · 2100 · GST/HST Payable / — / $65.00 / 4000 · Service Revenue / — / $500.00 / Total / $565.00 / $565.00; journal: 1000 |
| G-05 | GUIDED | Guided Match and Post: "MONTHLY ACCOUNT FEE" posts to 6800 Bank Charges with no tax; the preview and the journal show 6800 Dr 25.00, 1000 Cr 25.00 | **PASS** | preview: 6800 · Bank Charges and Interest / $25.00 / — / Business Chequing / — / $25.00 / Total / $25.00 / $25.00; journal: 1000:0:2500 6800:2500:0; want 1000:0:2500 6800:2500:0; e |
| G-06 | GUIDED | Bulk: the three Rogers lines selected together post to 6300 Telephone with GST/HST as three journals of Dr 6300 50.00 / Dr 1100 6.50 / Cr 1000 56.50; the preview totals 150.00, 19.50 and 169.50 | **PASS** | panel "3 Transactions Selected"; button "Post 3 Transactions"; preview HST 13% recoverable · 1100 · GST/HST Recoverable / $19.50 / — / 6300 · Telephone and Internet / $150.00 / — / |
| G-07 | GUIDED | After five lines are posted, Home counts 2 left to post, the Banking badge shows 2, and the Banking menu still opens after its badge changed | **PASS** | card 2 Transactions To Post; badge 2; after the badge changed, clicking Banking opens the Banking menu and page |
| G-08 | GUIDED | Exclude: "Owner Personal Purchase" is excluded from the books from Match and Post; it leaves the list of lines to post, creates no journal, and Home counts 1 | **PASS** | status excluded; journal lines 0; home 1 Transaction To Post |
| G-09 | GUIDED | Restore: "Review excluded lines" lists the excluded line, its Actions menu opens without ticking the row, and Restore returns it; Match and Post shows it unposted again and Home counts 2 | **PASS** | opening the row's Actions menu left the row unticked; listed 1; status pending; match-and-post Unposted; home 2 Transactions To Post |
| G-10 | GUIDED | Guided Match and Post: "OWNER PERSONAL PURCHASE" posts to 3100 Owner Draws with no tax (the restored line); the preview and the journal show 3100 Dr 40.00, 1000 Cr 40.00 | **PASS** | preview: 3100 · Owner Draws / Shareholder Advances / $40.00 / — / Business Chequing / — / $40.00 / Total / $40.00 / $40.00; journal: 1000:0:4000 3100:4000:0; want 1000:0:4000 3100: |
| G-11 | GUIDED | Undo a posting: the bank fee is voided from the bank transaction list with a reason; its journal leaves the books (1000 and 6800 back by 25.00) and the line can be restored and posted again to the same figures; the old voided entry can then not be brought back on top of the new one | **PASS** | restoring the old voided entry after the re-post: refused (409): This bank line went back to review after it was voided. Post it again from Match and Post instead of restoring the  |
| G-11b | GUIDED | The old voided fee posting stays history: restoring it through the journal route is refused and the books still show the fee once (6800 = 25.00) | **PASS** | old voided posting kept as bank_transaction_voided; journal-route restore 409 This voided bank posting belongs to a bank line that went back to review. Post the line again from Mat |
| G-12 | GUIDED | Phone width (390 × 844): Guided Home and Match and Post have no sideways page scroll, status pills stay on one line, the Home to-do opens Match and Post, and "Adobe Software Subscription" posts to 6200 Software with GST/HST: Dr 6200 30.00 / Dr 1100 3.90 / Cr 1000 33.90 | **PASS** | mode guided; sideways overflow: Home 0px, Match and Post 0px / after posting 0px; status pills wrapped: none; post button x 50–340; preview HST 13% recoverable · 1100 · GST/HST Rec |
| G-13 | GUIDED | All eight lines dealt with: Home shows no lines to post, offers "Bank Reconciliation" next, and the Banking badge is gone | **PASS** | to post shown false; reconciliation task true; badge "" |
| G-14 | GUIDED | Match: in "Match transactions" the eight posted lines are offered as exact source pairs and one click matches all of them | **PASS** | button "Match 8 Exact Pairs"; posted lines 8; all shown Matched |
| G-15 | GUIDED | General Ledger after the Guided session equals the hand-worked balances: Bank 183.60 Dr, GST/HST recoverable 36.40 Dr, GST/HST payable 65.00 Cr, revenue 500.00 Cr, expenses 305.00 Dr, owner draws 40.00 Dr; debits equal credits | **PASS** | got {"1000":18360,"1100":3640,"2100":-6500,"3100":4000,"4000":-50000,"6200":3000,"6300":15000,"6400":10000,"6800":2500}; want {"1000":18360,"1100":3640,"2100":-6500,"3100":4000,"40 |
| G-16 | GUIDED | Month-end checklist from Guided Home (shortcut "Month-End Checklist"): statement transactions and trial balance are ticked, and the bank reconciliation is flagged as still to do | **PASS** | {"Starting balances":"attention","Statement transactions":"pass","Duplicate statement lines":"pass","Trial balance":"pass","Customer control":"pass","Vendor control":"pass","Bank r |
| G-16b | GUIDED | Month-end checklist: an old completed reconciliation (to Aug 31) does not tick the bank reconciliation while September activity is unreconciled | **PASS** | {"cls":"attention","text":"Last completed reconciliation: August 31, 2026. Bank activity runs to September 9, 2026; reconcile to that date."} |
| G-17 | GUIDED | Owner reports agree with the hand-worked figures: Home shows money in the bank $183.60, GST/HST owed $28.60 (65.00 − 36.40) and profit $195.00 (500.00 − 305.00); Profit and Loss shows net income 195.00 | **PASS** | home bank true, tax true, profit true; P&L true |
| G-19 | GUIDED | Switching the company to Full Accounting: signing in again lands on the Dashboard (3 reloads) | **PASS** | pages after each reload: dashboard, dashboard, dashboard |
| G-18 | GUIDED | No script errors on any Guided screen during the session | **PASS** | no page errors recorded in G-03…G-12 |
| R-01 | R163 | Guided statements list the 12 months of the fiscal year (January to December 2026 for a December year end) | **PASS** | FY 2026-01-01..2026-12-31; months 2026-01,2026-02,2026-03,2026-04,2026-05,2026-06,2026-07,2026-08,2026-09,2026-10,2026-11,2026-12 |
| R-02 | R163 | A March 31 year end gives April to March; the current year (clock 2026-10-01) is April 2026 to March 2027 | **PASS** | current 2026-04..2027-03; earlier 2025-04..2026-03; years 2027-03-31,2026-03-31 |
| R-03 | R163 | A statement whose last line is February 3 is refused for May and the problem names February; February and March (issued early March) are accepted | **PASS** | May ok=false "This statement's last transaction is on February 3, 2026, so it was issued in February 2026 or early March 2026. Upload "; Feb true; Mar true; Jan false |
| R-04 | R163 | The check counts lines outside the fiscal year from the statement dates: 1 before January 1, 2026 and 0 after December 31, 2026 | **PASS** | before 1 (want 1); after 0 (want 0); lines 6; 2025-12-30..2026-02-03 |
| R-05 | R163 | After import the statement is recorded as February: 6 lines, coverage Dec 30 to Feb 3, and the month shows "uploaded" | **PASS** | {"month":"2026-02","label":"February 2026","status":"uploaded","id":"gsm_9797b4866386b75a903f2dcda57f067f","source":"guided","coverageStart":"2025-12-30","coverageEnd":"2026-02-03" |
| R-06 | R163 | One statement per month: a second statement for February is refused at the check and at recording; the same import cannot be recorded twice | **PASS** | check "A statement is already uploaded for February 2026. Remove it first to upload a different one."; re-attach 409 guided_month_taken |
| R-07 | R163 | Mapping groups the lines by keyword and direction: ROGERS WIRELESS (2), STAPLES OFFICE, CLIENT DEPOSIT, MONTHLY ACCOUNT, UNKNOWN THING; 5 lines mapped, 1 not mapped | **PASS** | groups ROGERS WIRELESS/out:2:6300 · Telephone and Internet:CODE:taxcode_3df2c4b288a5586c7f80295efa1b1646; CLIENT DEPOSIT/in:1:4000 · Service Revenue:; MONTHLY ACCOUNT/out:1:6800 ·  |
| R-08 | R163 | Reconciling before every line is posted is refused and says how many lines are left | **PASS** | {"reconciled":false,"matched":0,"remaining":0,"excluded":0,"excludedCents":0,"statementEnd":"2026-02-03","reason":"6 lines are not posted yet. Post or exclude every line before rec |
| R-09 | R163 | The group "Lines" view lists both Rogers lines with dates and amounts | **PASS** | [["2025-12-30","ROGERS WIRELESS 8000",-5650],["2026-01-12","ROGERS WIRELESS 8001",-5650]] |
| R-10 | R163 | Posting the groups (the deposit changed to HST included, the unknown line posted on its own to 6999) gives the hand-worked ledger: bank 304.00, HST paid 26.00, HST collected 65.00, sales 500.00 | **PASS** | GL {"1000":30400,"1100":2600,"2100":-6500,"4000":-50000,"6300":10000,"6400":10000,"6800":2500,"6999":1000}; want {"1000":30400,"1100":2600,"2100":-6500,"4000":-50000,"6300":10000," |
| R-11 | R163 | Without a closing balance the month waits; with the statement's closing balance 304.00 (hand sum) Tegh completes the bank reconciliation for Dec 30, 2025 to Feb 3, 2026 with difference 0 and all 6 lines cleared; the month shows "reconciled" | **PASS** | recon complete 0 2025-12-30 2026-02-03 6; month reconciled; repeat true |
| R-12 | R163 | The reconciliation report for that period agrees: adjusted balances equal, difference 0.00 | **PASS** | {"bankOpeningCents":0,"bankDepositsCents":56500,"bankWithdrawalsCents":26100,"unpostedBankCents":0,"bankTimingCents":0,"statementEndingCents":30400,"depositsInTransitCents":0,"outs |
| R-13 | R163 | An unposted statement can be removed (its lines are deleted and the month is free); a posted one cannot | **PASS** | removed 2; left 0; March empty; remove Feb 409 guided_month_has_postings |
| R-14 | R163 | Company isolation: another company cannot read the mapping, post, reconcile or remove this company's statement month (404), nor record its import (refused) | **PASS** | 404 guided_month_not_found, 404 guided_month_not_found, 404 guided_month_not_found, 404 guided_month_not_found, 404 guided_month_not_found, 422 guided_bank_required |
| R-15 | R163 | A 5,000-line statement is grouped into 10 keyword groups (500 lines each) in under 10 s, with the suggested account for the 9 known merchants | **PASS** | import 43533 ms; mapping 2017 ms; ADOBE SYSTEMS:500:6200 CLIENT DEPOSIT:500:4000 ESSO STATION:500:6900 HYDRO ONE:500:- INTACT INS:500:6950 MONTHLY ACCOUNT:500:6800 ROGERS WIRELESS: |
| R-16 | R163 | The 10 group decisions post all 5,000 lines, 100 per request (each under 15 s); bank, HST and every expense account equal the hand sums | **PASS** | calls 50; slowest 2960 ms; total 128411 ms; GL {"1000":-355000,"1100":2080000,"2100":-3250000,"4000":-25000000,"6200":1500000,"6300":2500000,"6400":5000000,"6500":1150000,"6600":62 |
| R-17 | R163 | Reconciling the 5,000-line statement completes in one request in under 15 s with 5,000 cleared lines | **PASS** | 1318 ms; {"reconciled":true,"matched":0,"remaining":0,"reconciliationId":"recon_7ecbee3a5545cb7a93016475c9915434","periodStart":"2026-04-01","periodEnd":"2026-04-28","statementClos |
| R-18 | R163 | Accountant review: findings and one total in CAD; the response carries no minutes, hours or rate | **PASS** | {"findings":[{"id":"unassigned","title":"Postings to Unassigned Expense","detail":"1 posting to 6999 Unassigned Expense needs a proper category.","count":1,"severity":"mistake"},{" |
| R-19 | R163 | Sending the request stores it, emails the breakdown to enquiry@sraccountax.ca, and returns an Outlook draft with the findings and total but no rate or hours | **PASS** | mail true; row sent 3000 16500 550; draft subject "Accountant request for R163 Gate mvg255jv (ref. acctreq_f2665194cf1a030c8595e2070c0d5aff)"; draft ok true |
| R-20 | R163 | Requests are limited to 5 per company per hour (the sixth is refused with 429) | **PASS** | 201,201,201,201,429 |
| R-32 | R163 | Full Accounting first: a January–March statement imported from Banking, two lines posted there and one deposit matched to an invoice payment; Guided takes the import in as the March statement with 3 to post, 2 posted and 1 matched | **PASS** | {"month":"2026-03","label":"March 2026","status":"uploaded","id":"gsm_1ae7287294f6683dc6f70b9b73e17575","source":"import","coverageStart":"2026-01-05","coverageEnd":"2026-03-28","l |
| R-33 | R163 | February and April (whose usual periods are inside January 5 – March 28) show "Included in the March 2026 statement"; January and May are empty | **PASS** | empty,covered,covered,empty |
| R-34 | R163 | Where to continue: post the 3 remaining lines of the March statement | **PASS** | {"action":"post","monthId":"gsm_1ae7287294f6683dc6f70b9b73e17575","month":"2026-03","label":"March 2026","bankAccountId":"bank_52fb89b2676312a15c99fda9c1c356ac","bankName":"Busines |
| R-35 | R163 | The March groups count the 2 posted and 1 matched lines as done; the deposit group of an HST registrant has no tax suggestion and must be chosen (posting without it is refused) | **PASS** | {"lines":6,"pending":3,"posted":2,"matched":1,"excluded":0,"mapped":3,"unmapped":0,"taxToChoose":1} refused 422 |
| R-36 | R163 | After posting the rest, a wrong closing balance (1,490.50) is refused with the difference (-10.00), Tegh's balance (1,500.50 = hand sum) and a hint that no opening balance is recorded | **PASS** | {"reconciled":false,"matched":0,"remaining":0,"excluded":0,"excludedCents":0,"statementEnd":"2026-03-28","needsBalance":true,"isCard":false,"statementClosingCents":149050,"teghClos |
| R-37 | R163 | With the right closing balance (1,500.50) the March reconciliation completes; the matched line is cleared through its book entry (6 cleared lines) | **PASS** | {"reconciled":true,"matched":0,"remaining":0,"reconciliationId":"recon_edef3b1d51d09edbe0499c60645eb401","periodStart":"2026-01-05","periodEnd":"2026-03-28","st; cleared 6 |
| R-38 | R163 | Where to continue: upload May (January's period is before the books start; February and April are included in March) | **PASS** | {"action":"upload","month":"2026-05","label":"May 2026","bankAccountId":"bank_52fb89b2676312a15c99fda9c1c356ac","bankName":"Business Chequing","fiscalYearEnd":"2026-12-31","uploade |
| R-39 | R163 | Full Accounting again: an April–May statement imported, posted and reconciled in Bank Reconciliation (the match no longer blocks it); Guided shows May as reconciled there and records it | **PASS** | {"month":"2026-05","label":"May 2026","status":"reconciled","id":"gsm_c33bbb6e1ee1f14648d90a13dad4ef0b","source":"import","coverageStart":"2026-04-02","coverageEnd":"2026-05-20","l |
| R-40 | R163 | In Full Accounting, a reconciliation that includes the matched deposit has no unexplained difference (it is in the books through the invoice payment) | **PASS** | {"bankOpeningCents":0,"bankDepositsCents":169500,"bankWithdrawalsCents":19450,"unpostedBankCents":0,"bankTimingCents":0,"statementEndingCents":150050,"depositsInTransitCents":0,"ou |
| R-41 | R163 | A Guided upload that a look at the month list took in before it was recorded is still recorded against the month chosen (June) | **PASS** | 200 2026-06 guided |
| R-42 | R163 | A February 28 year end: the current year is March 2026 – February 2027 (12 months) and the year ending February 29, 2024 starts March 1, 2023 | **PASS** | 2026-03-01..2027-02-28; 2023-03-01..2024-02-29 |
| R-43 | R163 | A company not registered for sales tax gets no tax suggestion and no tax question | **PASS** | CLIENT DEPOSIT/in:NO_TAX, ROGERS WIRELESS/out:NO_TAX |
| R-44 | R163 | A statement file with a balance column needs no typing: its closing balance (-81.50) is kept with the month and the month reconciles straight after posting | **PASS** | preview -8150; month -8150; {"reconciled":true,"matched":0,"remaining":0,"reconciliationId":"recon_379b01232a0ec9cc8b594960e9a34f2d","periodStart":"2026-03-02","periodEnd":"2026-03 |
| R-21 | R163 | Guided Home starts with the bank statement card; the top bar has "Connect with an accountant" right after Tegh Assist | **PASS** | card "START HERE · BANK STATEMENTS Upload Your Bank Statements, Month By Month Tegh groups the transactions, suggests where ea"; top bar Ctrl K / Create▾Import  / ASSIST / ACCOUNTA |
| R-22 | R163 | The statement page shows 12 month rows for January to December 2026, all "Not uploaded", and the PDF converter beside them | **PASS** | 2026-01:Not uploaded, 2026-02:Not uploaded, 2026-03:Not uploaded, 2026-04:Not uploaded, 2026-05:Not uploaded, 2026-06:Not uploaded, 2026-07:Not uploaded, 2026-08:Not uploaded, 2026 |
| R-23 | R163 | Uploading the February statement against May is stopped with "Upload it to February 2026 instead"; accepting moves it, and the fiscal-year note says 1 transaction falls before the year | **PASS** | problem "Not imported This statement's last transaction is on February 3, 2026, so it was issued in February 2026 or early March 2026. Upload it agai"; button "Upload It to Februar |
| R-24 | R163 | After the verification step the mapping page shows 6 lines in 5 groups, 5 mapped and 1 to choose | **PASS** | groups 5; stats Lines 6 Mapped automatically 5 Need an account 1 Posted 0 Tax to choose 1 |
| R-25 | R163 | The deposit group waits for a GST/HST choice; after choosing it and an account for the unmapped group, "Select all groups with an account" and Post books all 6 lines; Tegh then asks for the closing balance, refuses 300.00 with the difference and reconciles at 304.00 | **PASS** | before "Select All Groups with an Account (3)"; tax prompt 1; button "Post 6 Lines in 5 Groups"; refused "Not reconciled yet The statement says 300.00 but Tegh's balance for this a |
| R-26 | R163 | The month list then shows February as "Uploaded · reconciled"; Guided Home shows 1 of 12 months and "Continue: upload the March 2026 statement" | **PASS** | Feb "Uploaded · reconciled"; FY notes 1; home "1 of 12 months uploaded for the fiscal year jan 2026 – dec 2026 · 1 reconciled. Next: the statement issued in March 2026 for Business |
| R-27 | R163 | Next step "No" opens the financial statements page with Trial Balance, Profit and Loss and Balance Sheet | **PASS** | Your Financial Statements STEP 3 OF 3 · FINANCIAL STATEMENTS Your Books Are Ready To Review The trial balance, profit and loss and balance sheet are produced from the posted Genera |
| R-28 | R163 | Next step "Yes" asks what to record, switches to Full Accounting and opens Customer invoices | **PASS** | mode full-accounting; page invoices  |
| R-29 | R163 | Connect with an accountant: findings and a CAD quote, no hours or rate on the screen; sending opens the Outlook draft to enquiry@sraccountax.ca | **PASS** | quote "Quote to complete this work $165.00 CAD An estimate for the bookkeeping work listed, plus applicable GST/HST. It does not include tax returns (GST/HST, T2 or personal) or pa |
| R-30 | R163 | At phone width (390 px) the statement months and the mapping page have no sideways scroll, on the page or inside a table or card | **PASS** | months true; mapping true |
| R-47 | R163 | Back in Guided after Full Accounting: a "Welcome back to Guided" notice, and Guided Home offers "Continue: post the March 2026 statement", which opens that statement with the line posted in Full Accounting counted | **PASS** | notice "Welcome back to Guided 3 lines are still to post in Business Chequing. Your work in Full Accounting is included. Use "Continue: post the March 2026 statement" o"; button "C |
| R-48 | R163 | The month list shows the Banking import as the March statement, February as included in it, and a "Continue where you left off" banner | **PASS** | March "March 2026 Uploaded · 3 to post 4 lines · Jan 5, 2026 – Mar 28, 2026 · 1 posted · imported in Banking Review and Post"; February "Included in the March 2026 statement"; bann |
| R-31 | R163 | No script errors on the R163 screens | **PASS** | no page errors |
| S-01 | R163SEC | Cross-company IDOR: the attacker company cannot read the victim's mapping, lines, post, reconcile or remove its month (404) | **PASS** | statuses 404,404,404,404,404; victim posted 0 |
| S-02 | R163SEC | Cross-company: attaching the victim's import batch to the attacker company is refused (404) | **PASS** | status 404 |
| S-03 | R163SEC | CSRF: a POST with a wrong CSRF token is refused (403), on every R163 write route; a good-token read still works afterwards | **PASS** | statuses 403,403,403,403; good-token read true |
| S-04 | R163SEC | Email-header injection: a company name with CRLF + a forged Bcc cannot inject a header; the stored subject is single-line | **PASS** | status 201; subject has no CRLF true; name stored without CRLF true; subject "Tegh: accountant requested by R163SEC Evil mvg2a10q Bcc: attacker@evil.test ($0.00 CAD)" |
| S-05 | R163SEC | Stored XSS: a <script> payload in a bank-line description is stored raw but returned as data (the client escapes it); the API never executes it | **PASS** | group IMG SRC/out; returned verbatim as JSON string: "<img src=x onerror=alert(1)> ACME" |
| S-06 | R163SEC | Integrity: a deposit for an HST registrant cannot be posted without a tax choice (server refuses, not only the UI) | **PASS** | status 422 guided_tax_required |
| S-07 | R163SEC | Integrity: the client cannot mark a month reconciled by itself — reconcile with a wrong closing balance is refused with the difference | **PASS** | reconciled false; stored recon "NULL" |
| S-08 | R163SEC | Abuse: accountant requests are capped at 5 per company per hour (the 6th is 429), so the mail path is not a relay | **PASS** | 201,201,201,201,201,429 |
| S-09 | R163SEC | Secrets: the accountant review/quote never returns the hourly rate, the minutes per finding or the hours | **PASS** | keys findings,quoteCents,currency,taxNote |
| S-10 | R163SEC | Input bounds: an over-long note and a bad monthId are rejected cleanly (no 500) | **PASS** | longnote 201; traversal-monthId 400 |
| AS-01 | SEC2 | The session cookie is set HttpOnly + Secure + SameSite=Strict (not stealable by JS, not sent cross-site) | **PASS** | session "tegh_session=<v>; expires=Fri, 02 Oct 2026 04:28:45 GMT; Max-Age=43200; path=/; "; csrf flags secure=true strict=true |
| AS-02 | SEC2 | A wrong password is refused and sets no session cookie (no partial auth) | **PASS** | status 401; session cookie issued false |
| AS-03 | SEC2 | An authenticated write with a forged/garbage session cookie is unauthenticated (401), not honoured | **PASS** | status 401 |
| AS-04 | SEC2 | Logout invalidates the session server-side: the same cookie no longer works (401) | **PASS** | before 200; after logout 401 |
| AS-05 | SEC2 | Session and CSRF tokens are stored hashed at rest: the raw cookie value is not found in the sessions table | **PASS** | raw cookie present true; rows matching raw value in sessions 0 (0 = stored hashed) |
| AS-06 | SEC2 | Login reveals nothing about whether an account exists: a wrong password and a nonexistent email return identical status+code+message, and comparable timing (user-enumeration oracle closed) | **PASS** | identical response true; median real 833ms vs fake 884ms (ratio 1.06, <1.5 = no timing oracle) |
| CV2-01 | SEC2 | A client cannot pull a report outside the link's allowlist (shared: profit-loss; requested: balance-sheet) -> 403 | **PASS** | signin 200; shared 200; not-shared 403; payroll 403 |
| CV2-02 | SEC2 | Five wrong 6-digit codes lock the code (429); a 6-digit code cannot be brute-forced | **PASS** | 422,422,422,422,422,429 |
| CV2-03 | SEC2 | A client-view session cookie cannot call an authenticated company API (it is not a user session) -> 401 | **PASS** | guided/mapping 401; workspace 401 |
| CV2-04 | SEC2 | A revoked link cannot start a sign-in (410) | **PASS** | start on revoked link 410 |
| PY2-01 | SEC2 | A real SIN in an employee record is refused server-side (422), even disguised across fields | **PASS** | explicit SIN 422/sin_not_accepted; SIN-shaped id 422/sin_not_accepted |
| PY2-02 | SEC2 | Finalizing a pay run without the server-checked review confirmation is refused (422), not just hidden in the UI | **PASS** | 422 payroll_review_confirmation_required |
| PY2-03 | SEC2 | A pay run from another company cannot be finalized from this company (not found), even with review confirmed | **PASS** | foreign run none; finalize 404 payroll_run_not_found; its status n/a |
| M-01 | MFA | A privileged login with a correct password returns an MFA challenge, not a usable session (enrolment required on first run) | **PASS** | status 200; mfaRequired true; enrollmentRequired true; companies present false |
| M-02 | MFA | An unverified session is blocked from protected routes with mfa_enrollment_required (403) | **PASS** | status 403; code mfa_enrollment_required |
| M-03 | MFA | Enrolment begins only with the current password (step-up) and returns an otpauth secret | **PASS** | wrong-pw 401; begin 200; secret len 32; uri otpauth://totp/Tegh%3Aowner%40gate.test? |
| M-04 | MFA | Enrolment rejects an incorrect code (422 mfa_code_invalid) | **PASS** | status 422; code mfa_code_invalid |
| M-05 | MFA | A correct code confirms enrolment, verifies the session and returns 10 one-time recovery codes + the full identity | **PASS** | confirm 200; enabled true; recovery 10; identity true; protected-after 200 |
| M-06 | MFA | After enrolment, login is a two-step challenge for an *enrolled* account (mfaEnrolled true) | **PASS** | status 200; mfaRequired true; enrolled true |
| M-07 | MFA | The second step rejects a wrong TOTP (422) and blocks the app until a correct code (verification_required) | **PASS** | wrong-code 422/mfa_code_invalid; protected-while-unverified 403/mfa_verification_required |
| M-08 | MFA | A correct TOTP completes the second step and unlocks the app | **PASS** | verify 200; verified true; protected 200 |
| M-09 | MFA | A recovery code works as the second factor and is single-use (reuse is refused) | **PASS** | first-use 200; reuse 422 (422 = single-use enforced) |
| M-10 | MFA | TOTP replay is refused: the same code cannot be used for a second verification in the same step | **PASS** | first 200; replay 422; same-step true (422 expected when same step) |
| M-11 | MFA | Secrets at rest: the TOTP secret is stored encrypted (not the plaintext base32) and recovery codes are hashed | **PASS** | plaintext secret in cipher column 0; plaintext recovery stored 0; cipher bytes 32 (0/0 = encrypted & hashed) |
| M-12 | MFA | An enrolled account cannot turn MFA off while its role still requires it (409) | **PASS** | disable 409/mfa_required_for_role (must stay on for privileged role) |
| SP-01 | SESSION | A fresh owner session may change a member role without re-entering the password (recent sign-in) | **PASS** | status 200  |
| SP-02 | SESSION | After the sign-in is older than 15 minutes, a member change without the password is refused (403 recent_authentication_required) and nothing changes | **PASS** | status 403 code recent_authentication_required; role still editor |
| SP-03 | SESSION | With an old sign-in, a wrong password is refused and the right password allows the change | **PASS** | wrong 401 password_confirmation_invalid; right 200 |
| SP-04 | SESSION | A member's existing sessions end after role change | **PASS** | action 200 ; session alive before true, after false; rows 0 |
| SP-05 | SESSION | A member's existing sessions end after suspension | **PASS** | action 200 ; session alive before true, after false; rows 0 |
| SP-06 | SESSION | A member's existing sessions end after removal | **PASS** | action 200 ; session alive before true, after false; rows 0 |
| SP-07 | SESSION | Platform-owner admin user actions need a recent sign-in; MFA reset ends the target's sessions and is audited | **PASS** | no-password 403; with password 200; mfa rows 0; audit 1; member session alive false |
| SP-08 | SESSION | Approving or rejecting a company deletion request needs a recent sign-in | **PASS** | status 403 code recent_authentication_required |
| SP-09 | SESSION | Changing the password ends the user's other sessions | **PASS** | change 200 ; other session alive false; own true |
| SP-10 | SESSION | Reset request returns the identical status and body for an existing and a non-existing address | **PASS** | distinct bodies 1; statuses 200 |
| SP-11 | SESSION | Reset request timing does not reveal whether the address exists (median difference under 250 ms) | **PASS** | median existing 755 ms; non-existing 755 ms |
| SP-12 | SESSION | The reset email is still delivered for the existing address (sent after the response) | **PASS** | requests 4; email captured true |
| SP-13 | SESSION | Forwarded IP headers are ignored unless a trusted proxy is configured (rate-limit bucket follows the real peer) | **PASS** | distinct IP buckets for spoofed + plain requests: 1 |
| SP-14 | SESSION | Portal: with an old sign-in, "Reset two-step verification" opens a password dialog; Cancel changes nothing, the right password completes it | **PASS** | after cancel MFA rows 1; after password 0; dialogs left 0; page errors 0  |
| MO-01 | MONITOR | No backup status recorded: the sweep raises "no successful backup" and emails the operator | **PASS** | cron rc 2: 2026-10-20T02:28:09+00:00 monitor ALERT backup_missing lastBackup=none failedLogins=0 recipient=set; new alert emails 1 |
| MO-02 | MONITOR | A repeat inside the throttle window sends no second email and is counted for the next one (flood prevention) | **PASS** | new emails 0; suppressed count 2 |
| MO-03 | MONITOR | A successful run of the real backup script records success; the next sweep raises no backup alert | **PASS** | backup rc 0; status {"lastRunStatus":"ok","lastRunAt":"2026-10-20T02:28:27Z","lastRunMessage":"backup complete","lastSuccessAt":"2026-10-20T02:28:27Z"}; sweep: 2026-10-20T02:28:27+ |
| MO-04 | MONITOR | A failed backup run is recorded (last success kept) and raises "the last backup failed" | **PASS** | backup rc 1; status failed, last success 2026-10-20T02:28:27Z; sweep: 2026-10-20T02:28:27+00:00 monitor ALERT backup_failed lastBackup=2026-10-20T02:28:27+00:00 failedLogins=0 reci |
| MO-05 | MONITOR | Retention: local copies older than 14 days are deleted and the deletion is verified; younger copies stay | **PASS** | rc 0; 15-day copy present false; 13-day copy present true; retention: 3 local copies kept (limit 14 days) |
| MO-06 | MONITOR | A burst of failed sign-ins against many accounts from one source raises "suspicious sign-in activity" with counts only | **PASS** | sweep: 2026-10-20T02:28:44+00:00 monitor ALERT auth_suspicious lastBackup=2026-10-20T02:28:44+00:00 failedLogins=36 recipient=set; emails 1 |
| MO-07 | MONITOR | A serious server error raises an alert after the response; the email has the request reference but not the error text | **PASS** | php rc 0 ; emails 1; leaked text false |
| MO-08 | MONITOR | No alert email body contains email addresses of the people involved, hashes, tokens or amounts | **PASS** | alert emails checked 5; patterns found none |
| MO-09 | MONITOR | The monitoring script and its state are not reachable over the web | **PASS** | monitor-cron.php 403; monitor-state.json 403; monitoring_r164.php 403 |
| MO-10 | MONITOR | A change to a security setting in the private config file is audited (setting names only, no values) and alerted | **PASS** | sweep: 2026-10-20T02:28:44+00:00 monitor ALERT security_config lastBackup=2026-10-20T02:28:44+00:00 failedLogins=0 recipient=set; audit rows +1; metadata {"changedSettings":["app.s |
| AU-01 | AUDIT | A role change is recorded in the company audit trail with actor, old and new role, and a hash-chained entry | **PASS** | change 200; audit owner@gate.test/user_r164aurolemvg2dtnl/{"email":"r164-audit-role-mvg2dtnl@gate.test","from":"editor","to":"viewer"}/1 |
| AU-02 | AUDIT | Suspension and removal of a member are recorded with the reason | **PASS** | suspend 200 audited true; remove 200 audited true |
| AU-03 | AUDIT | Platform-owner user administration (deactivate, reactivate, MFA reset) is recorded in the platform audit log with request reference and source | **PASS** | statuses 200,200,200; audit owner@gate.test/user_r164auadminmvg2dtnl/1/1 ; owner@gate.test/user_r164auadminmvg2dtnl/1/1 ; owner@gate.test/user_r164auadminmvg2dtnl/1/1 |
| AU-04 | AUDIT | A company deletion request and the platform owner's decision are both recorded | **PASS** | request audited true; decision 200, audited rows 1 |
| AU-05 | AUDIT | Changing public registration (a security setting) needs a recent sign-in and is recorded with old/new value and reason | **PASS** | without password 403 recent_authentication_required; change 200; restore 200; audit rows 2 |
| AU-06 | AUDIT | MFA changes are recorded at account level (enrolment, verification, disable) without the secret or codes | **PASS** | MFA audit rows 147; rows containing a secret or code 0 |
| AU-07 | AUDIT | No audit record (company or platform) contains a password, session token or reset token | **PASS** | findings none; session hashes in audit 0 |
| FI-01 | FILES | Statement source file of another company cannot be downloaded (by ID, with either company header) | **PASS** | import source: own-company context 404; victim company header 403; owner control 200 |
| FI-02 | FILES | Invoice attachment of another company cannot be downloaded | **PASS** | invoice attachment: own-company context 404; victim company header 403; owner control 200 |
| FI-03 | FILES | Document Intake source (OCR input) of another company cannot be downloaded | **PASS** | intake document: own-company context 404; victim company header 403; owner control 200 |
| FI-04 | FILES | Invoice detail and its PDF model of another company cannot be read | **PASS** | invoice detail: own-company context 404; victim company header 403; owner control 200 |
| FI-05 | FILES | Reports and exports of another company cannot be generated (trial balance, GL export, backup export) | **PASS** | outsider reports/v5600/financial 403, requested-downloads 403, platform/members 403; owner controls 200,200,200; backup export 403 |
| FI-06 | FILES | Saved downloads are private to the person and company: another user's saved file ID is not found | **PASS** | save 201; owner same company 200; owner other company 404; outsider 403 |
| FI-07 | FILES | Downloads are served as attachments with nosniff, a sandbox CSP and no caching | **PASS** | status 200; disposition attachment; filename="x.csv"; nosniff nosniff; csp sandbox; default-src 'none'; cache private, no-store, max-age=0; marker visible false |
| FI-08 | FILES | Uploads: HTML disguised as PDF, SVG and a path-traversal file name are refused or neutralised; the stored name is random | **PASS** | html-as-pdf 415; svg 400; traversal name 201 stored as company_24cb30af94a210df42d7c3adf0cd8dba/receipts/<random>.pdf |
| FI-09 | FILES | Invitations: an outsider cannot list or create invitations for another company | **PASS** | list 200 (victim data shown false); create 422 (rows created 0); self-promotion in victim company 403; platform users 403; owner control 200 |
| FI-10 | FILES | Company list and switching: the outsider sees only their own company and cannot select another by header | **PASS** | companies listed 1 (own true); workspace with victim header 403; owner control 200 |
| FI-11 | FILES | Client viewing links and accountant connections of another company are not listed or changed | **PASS** | client-view links 403; accountant connection 403; owner controls 200,405 |
| RS-01 | RESTORE | Deleting a login and a company records both on the deletion register (outside the database and the backed-up storage), with no plain email | **PASS** | delete login 200 ; delete company 200 ; register has login true, company true; plain email present false |
| RS-02 | RESTORE | After the restore, the deleted login cannot sign in with its old password | **PASS** | login 401 invalid_credentials |
| RS-03 | RESTORE | After the restore, the deleted login's old session is refused and removed | **PASS** | old session auth/me 401; sessions left 0 |
| RS-04 | RESTORE | After the restore, the deleted company cannot be opened, even by its owner | **PASS** | workspace 410 company_deleted |
| RS-05 | RESTORE | Monitoring reports the reappeared login and company and an incident is recorded | **PASS** | sweep: 2026-10-20T02:29:00+00:00 monitor ALERT deleted_reappeared lastBackup=2026-10-20T02:28:44+00:00 failedLogins=1 recipient=set; incidents 3 |
| RS-06 | RESTORE | The platform owner can delete them again; afterwards monitoring is clear | **PASS** | re-delete login 200, company 200; sweep: 2026-10-20T02:29:01+00:00 monitor ok lastBackup=2026-10-20T02:28:44+00:00 failedLogins=1 recipient=set |
| RS-07 | RESTORE | The deletion register is not reachable over the web | **PASS** | attempts 404, 403 |
