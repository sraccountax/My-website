| ID | Area | Test | Result | Evidence |
|---|---|---|---|---|
| H-01 | H-INST | GET /api/health over HTTPS before install | **PASS** | 200 {"ok":true,"service":"Tegh","version":"5.9.9","build":5990,"schemaVersion":46} |
| H-02 | H-INST | auth/me on empty DB reports setup required | **PASS** | 428 {"error":"Initial owner setup is required.","code":"setup_required"} |
| H-03 | H-INST | Setup with wrong key refused 403 | **PASS** | 403 |
| H-04 | H-INST | Setup from foreign Origin refused | **PASS** | true |
| H-05 | H-INST | Fresh install: owner setup succeeds (201) and schema created | **PASS** | 201 |
| H-06 | H-INST | Schema tables created on fresh DB | **PASS** | 156 tables |
| H-07 | H-INST | Second setup locked (409) | **PASS** | 409 |
| H-08 | H-INST | Login over HTTPS; version/build/schema | **PASS** | ["5.9.9","5990","46"] |
| H-09 | H-INST | Session cookie flags Secure, HttpOnly, SameSite | **PASS** | tegh_session=<redacted>; expires=<redacted>; Max-Age=43200; path=/; secure; HttpOnly; SameSite=Strict / tegh_session_csrf=<redacted>; expires=<redacted>; Max-Age=43200; path=/; sec |
| H-10 | H-INST | startup/status after install | **PASS** | 200 {"ok":true,"version":"5.9.9","build":5990,"schemaVersion":43,"expectedSchemaVersion":46,"userId":"user_ece8423e92bdaf866e16960540affc2a","coreReady":true,"aiReady":true,"native |
| H-11 | H-INST | Fresh install: protected database upgrade (Schema 43 → 46) completes from the owner session | **PASS** | 200 steps schema46.5990.accounting_notes_r67:completed,schema46.5990.invoice_note_tax_columns_r69:completed,schema46.5990.accounting_note_settlements_r70:completed,schema46.5990.co |
| H-12 | H-INST | After upgrade: startup status Schema 46, not degraded | **PASS** | {"s":46,"d":false,"w":null} |
| H-M01 | H-MAIL | SMTP delivery test (STARTTLS + AUTH) reaches sandbox | **PASS** | 200 sent=true outcome=accepted mailfile=1790879661.404675.eml X-Sandbox-Tls: True / X-Sandbox-Auth: True / X-Rcpt: owner@gate.test |
| H-M02 | H-MAIL | Test email to non-owner address refused | **PASS** | 403 |
| H-M-editor | H-MAIL | Invitation email for editor delivered over STARTTLS+AUTH with single-use link | **PASS** | 200 delivery=sent link=present msg=The mail server accepted the message for delivery. |
| H-A-editor | H-MAIL | Invitee (editor) accepts and sets password | **PASS** | 200 {"accepted":true,"auth":{"user":{"id":"user_33a37ea78cb2e2622ef2f5eb940649ed","email":"editor@gate.test","displayName":" |
| H-R-editor | H-MAIL | Invitation link cannot be reused | **PASS** | 410 |
| H-L-editor | H-MAIL | editor can sign in | **PASS** | 200 |
| H-M-viewer | H-MAIL | Invitation email for viewer delivered over STARTTLS+AUTH with single-use link | **PASS** | 200 delivery=sent link=present msg=The mail server accepted the message for delivery. |
| H-A-viewer | H-MAIL | Invitee (viewer) accepts and sets password | **PASS** | 200 {"accepted":true,"auth":{"user":{"id":"user_2d68171fca55841ba1f73c3fe52a103b","email":"viewer@gate.test","displayName":" |
| H-R-viewer | H-MAIL | Invitation link cannot be reused | **PASS** | 410 |
| H-L-viewer | H-MAIL | viewer can sign in | **PASS** | 200 |
| H-M-admin | H-MAIL | Invitation email for admin delivered over STARTTLS+AUTH with single-use link | **PASS** | 200 delivery=sent link=present msg=The mail server accepted the message for delivery. |
| H-A-admin | H-MAIL | Invitee (admin) accepts and sets password | **PASS** | 200 {"accepted":true,"auth":{"user":{"id":"user_9bbdf78308dc7aa637fc7ed1402b9823","email":"admin@gate.test","displayName":"A |
| H-R-admin | H-MAIL | Invitation link cannot be reused | **PASS** | 410 |
| H-L-admin | H-MAIL | admin can sign in | **PASS** | 200 |
| H-M-other | H-MAIL | Invitation email for admin delivered over STARTTLS+AUTH with single-use link | **PASS** | 200 delivery=sent link=present msg=The mail server accepted the message for delivery. |
| H-A-other | H-MAIL | Invitee (admin) accepts and sets password | **PASS** | 200 {"accepted":true,"auth":{"user":{"id":"user_f7bf6a8cf68afc444aa8d5eb342bbe2d","email":"otherowner@gate.test","displayNam |
| H-R-other | H-MAIL | Invitation link cannot be reused | **PASS** | 410 |
| H-L-other | H-MAIL | admin can sign in | **PASS** | 200 |
| D-MAILMSG | H-MAIL | Successful invitation send shows a success diagnostic (not "rejected") | **PASS** | {"message":"The mail server accepted the message for delivery.","nextAction":"Recipient can use the new link.","reference":"f96318b72f5a536d","smtpCode":250,"stage":"final_data_acc |
| TX-00b | ACCT | DEF-08: BC company starter code for QC is GST only (not registered with Revenu Québec) | **PASS** | "GST" |
| TX-00c | ACCT | DEF-08: BC company keeps BC PST on its home-province code | **PASS** | "GST,PST" |
| TX-00d | ACCT | After registering in Quebec, owner edits the QC code to add QST 9.975% → 2115/1115 | **PASS** | 200 |
| TX-00 | ACCT | Starter codes present in new BC company | **PASS** | "AB,BC,GST,MB,NB,NL,NS,NT,NU,ON,PE,QC,SK,YT" |
| AC-01 | ACCT | Opening balances post a balanced journal: Dr bank $10,000 / Cr equity $10,000 | **PASS** | PUT 200/200 POST 201 {"posted":true,"openingBalanceImport":{"id":"opening_8f627b4ad5c5e33fcdbe86f8b0a405d2","journalEntryId":"journal_d1652366e4be355238f6c504931c2ea6","effectiveDa |
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
| AC-03 | ACCT | Five simultaneous identical receipt submissions (same operation key) record one payment | **PASS** | statuses 200,201,200,200,200 payments=1 |
| AC-04 | ACCT | Invoice fully settled after credit note + receipt: balance due $0.00 | **PASS** | "0" |
| AC-05 | ACCT | Over-payment of a settled invoice refused | **PASS** | 409 {"error":"Choose an open issued invoice.","code":"payment_document_unavailable"} |
| AC-06 | ACCT | Vendor payment $525 settles GST-only bill | **PASS** | 201 {"payment":{"id":"payment_29468b0a2812090e56c8ba0af4c7711e","type":"vendor","flowKind":"vendor_payme |
| BK-01 | ACCT | CSV statement import: preview shows 5 rows, approve imports 5 | **PASS** | rows=5 selected=5 approve=201 {"batch":{"id":"import_700d4da54c0dae06939d2a297492a182","status":"needs_review","rowCount":5,"selectedCount":5,"omittedCount":0,"duplicateCount":0,"c |
| BK-02 | ACCT | Re-importing the same statement: all 5 rows flagged duplicate, none imported | **PASS** | dups=5 approve=422 count=5 |
| TX-14 | ACCT | Bank deposit $1,149.75 with QC code: income $1,000, GST $50 → 2100, QST $99.75 → 2115 | **PASS** | {"1000":114975,"2100":-5000,"2115":-9975,"4000":-100000} |
| TX-15 | ACCT | Bank purchase $1,149.75 with QC code: ITC $50 → 1100, QST ITR $99.75 → 1115 | **PASS** | {"1000":-114975,"1100":5000,"1115":9975,"6000":100000} |
| TX-16 | ACCT | Bank purchase $1,120 with BC code: ITC $50, PST $70 in cost ($1,070) | **PASS** | {"1000":-112000,"1100":5000,"6000":107000} |
| AC-07 | ACCT | Three simultaneous posts of the same bank line create one journal | **PASS** | statuses 409,200,409 journals=1 |
| AC-08 | ACCT | Posting a bank line to its own bank GL account (same-account transfer) refused | **PASS** | 422 {"error":"Transfer From and Transfer To must be different accounts.","code":"transfer_same_account"} |
| AC-08b | ACCT | Owner transfer posted to equity instead | **PASS** | 200 |
| AC-09 | ACCT | Re-posting an already posted bank line refused / no second journal | **PASS** | 200 journals=1 |
| AC-10 | ACCT | Balanced manual journal $123.45 posts | **PASS** | 201 {"journalEntry":{"id":"journal_9b1c7a7808437cc502ab4af724d0a83e","status":"posted"},"approvalId":null,"approvalRequired" |
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
| AC-30 | ACCT | Trial Balance identical on re-run | **PASS** | "[{\"id\":\"account_918e9951ebd5b7ec1e1fcfb4e8fee6ee\",\"code\":\"1000\",\"name\":\"Business Chequing\",\"type\":\"asset\",\"normalBalance\":\"debit\",\"openingDebitCents\":0,\"ope |
| AC-31 | ACCT | Editing the QC code after posting leaves posted history unchanged | **PASS** | [200,true] |
| QT-01 | TAX | QC company invoice $1,000: GST $50 → 2100, QST $99.75 → 2115 | **PASS** | {"1200":114975,"2100":-5000,"2115":-9975,"4000":-100000} |
| QT-02 | TAX | QC bill $500: GST ITC $25.00 → 1100, QST ITR $49.88 (49.875 rounded half-up) → 1115; AP $574.88 | **PASS** | {"1100":2500,"1115":4988,"2050":-57488,"6000":50000} |
| QT-03 | TAX | QC statement import (2 rows) | **PASS** | 201 {"batch":{"id":"import_c05de2ab5747c371e11275ffd845cf12","status":"needs_review","rowCount":2,"selec |
| QT-04a | TAX | Remittance with period end after the payment date refused | **PASS** | [422,"sales_tax_period_invalid"] |
| QT-04 | TAX | QST remittance $49.87: Dr 2115 $99.75, Cr 1115 $49.88, Cr bank $49.87 | **PASS** | [200,{"1000":-4987,"1115":-4988,"2115":9975}] |
| QT-05 | TAX | After QST remittance: 2115 = $0.00 and 1115 = $0.00 | **PASS** | [0,0] |
| QT-06 | TAX | GST remittance $25.00: Dr 2100 $50, Cr 1100 $25, Cr bank $25; 2100 and 1100 back to $0 | **PASS** | [200,{"1000":-2500,"1100":-2500,"2100":5000},0,0] |
| QT-07 | TAX | GST/HST (tax) Summary report includes QST accounts 2115 and 1115 | **PASS** | status 200 codes "code":"1100" "code":"1110" "code":"1115" "code":"2100" "code":"2110" "code":"2115" |
| CG-01 | TAX | Create custom tax accounts 2120/1120 | **PASS** | 201/201 {"account":{"id":"account_4177f40eaaac601e7f8bf65bf67afeda","gifiCode":null}} |
| CG-02 | TAX | Starter ON code edited to post HST to custom 2120/1120 | **PASS** | 200 |
| CG-03 | TAX | ON invoice $200: HST $26.00 → 2120 (custom), nothing to 2100 | **PASS** | {"1200":22600,"2120":-2600,"4000":-20000} |
| CG-04 | TAX | ON bill $100: HST ITC $13.00 → 1120 (custom) | **PASS** | {"1120":1300,"2050":-11300,"6000":10000} |
| CG-05 | TAX | Trial Balance shows 2120 Cr $26.00 and 1120 Dr $13.00 | **PASS** | [2600,1300] |
| CG-06 | TAX | General Ledger for 2120 lists the $26.00 credit | **PASS** | status 200 {"currency":"CAD","start":"2026-01-01","end":"2026-09-30","account":{"id":"account_4177f40eaaac601e7f8bf65bf67afeda","code":"2120","name":"HST Payable (custom)" |
| CG-07 | TAX | Tax Summary report includes custom 2120 ($26.00) and 1120 ($13.00) | **PASS** | status 200 |
| CG-08 | TAX | Dashboard tax card reports custom-account HST: other tax accounts net $13.00 owed (2120 $26 − 1120 $13) | **PASS** | "gstHstCollectedCents":0 "gstHstRecoverableCents":0 "gstHstNetCents":0 "pstPayableCents":0 "pstRecoverableCents":0 "qstPayableCents":0 "qstRecoverableCents":0 |
| NR-01 | TAX | Company NOT registered for GST/HST: default taxable invoice line charges no GST/PST | **PASS** | starter codes=14 mode=codes journal={"1200":10000,"4000":-10000} |
| NR-02 | TAX | Unregistered company: $100 bill with BC code — GST $5 and PST $7 added to cost ($112), no ITC | **PASS** | {"2050":-11200,"6000":11200} |
| NR-03 | TAX | Unregistered company: a code chosen explicitly on the line is still applied (GST $5) | **PASS** | {"1200":10500,"2100":-500,"4000":-10000} |
| CB-01 | TAX | Cash basis: receipt of $565 (half) recognises HST $65.00 in 2100 and revenue $500 | **PASS** | [-6500,-50000] |
| CB-02 | TAX | Cash basis: invoice issue posting recorded for review | **INFO** | {} |
| FX-01 | FX | Add USD at 1.35 | **PASS** | 201 |
| FX-01b | FX | Add USD bank account | **PASS** | 201 {"bankAccount":{"id":"bank_ff8a5689095b1a2098626515dcfc4685","accountType":"bank","accountSubtype":"chequing"}} |
| FX-02 | FX | USD 1,000 invoice at 1.35: revenue CAD $1,350.00, GST CAD $67.50, AR CAD $1,417.50 | **PASS** | [201,{"1200":141750,"2100":-6750,"4000":-135000}] |
| FX-03 | FX | USD 1,050 receipt at 1.40: bank CAD $1,470.00, AR −$1,417.50, FX gain $52.50 | **PASS** | 201 {"payment":{"id":"payment_289217f3d12414ab9c198eeeb99e4081","type":"customer","flowKind":"customer_receipt","status":"po lines: 1010	147000	0 / 1200	0	141750 / 6850	0	5250 |
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
| RO-09 | SEC | Editor can create a draft invoice | **PASS** | 201 {"invoice":{"id":"invoice_089c90b06ffdacf4f38380f22be1bc3d","number":"INV-1008","status":"draft","to |
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
| PY-02 | PAY | Employee can be created without a SIN (Employee ID only) | **PASS** | 201 {"employee":{"id":"employee_039fcb06cd5e12e76d700163bc9fcee8"}} |
| PY-03 | PAY | No SIN (ciphertext or last four) stored in the database | **PASS** | "0" |
| PY-03b | PAY | No SIN stored for any company on the gate database | **PASS** | "0" |
| PY-04 | PAY | Payroll workspace API returns no SIN fields/values | **PASS** |  |
| PY-05 | PAY | Company backup contains no SIN value | **PASS** | 200 size=15257 |
| PY-06 | PAY | Payroll notice text present in app bundle ("Payroll Support Tool") | **PASS** |  |
| PY-07 | PAY | Payroll rate tables: year/version reported for owner verification | **INFO** | "effectiveFrom":"2026-01-01" "effectiveTo":"2026-06-30" "effectiveFrom":"2026-07-01" "effectiveTo":"2026-12-31" |
| BR-01 | REC | Backup export downloads a signed .tegh archive | **PASS** | 200 application/vnd.tegh.backup bytes=41773 |
| BR-02 | REC | Restore into a new company succeeds | **PASS** | 201 {"restored":true,"companyId":"company_5233a83f8b19f6aa6e0b55744e5333d0","companyName":"Gate Test BC Ltd (Restored copy)","payloadSha256":"dc0b568b3dcf3dc74e8f3ea7657e7a26c412a1 |
| BR-03 | REC | Restored company Trial Balance equals source, account by account | **PASS** | {"1000":861300,"1010":147000,"1100":23800,"1115":19950,"1200":321776,"2050":-226975,"2100":-36850,"2110":-6300,"2115":-19950,"3000":-962345,"4000":-605001,"6000":488845,"6850":-525 |
| BR-04 | REC | Restored record counts equal source (documents, journals, bank lines, tax codes, tax detail, payments, notes) | **PASS** | "invoices=8 bills=3 customers=6 vendors=2 journal_entries=22 bank_transactions=5 tax_codes=15 document_tax_lines=18 party_payments=3 accounting_notes=1" |
| BR-05 | REC | Restored AR and AP ageing totals equal source | **PASS** | [321776,226975] |
| BR-08 | REC | Tampered backup refused with a 4xx integrity message (no 500) | **PASS** | 422 backup_integrity_invalid |
| BR-06 | REC | Tax codes, rates and GL mapping identical after restore | **PASS** | "AB:GST:5000:2100/1100:active,BC:GST:5000:2100/1100:active,BC:PST:7000:2110/-:active,GST:GST:5000:2100/1100:active,MB:GST:5000:2100/1100:active,NB:HST:15000:2100/1100:active,NL:HST |
| BR-07 | REC | No cross-company account references after restore | **PASS** | "0" |
| BR-09 | REC | Restored document tax detail points to restored accounts only | **PASS** | "0" |
| PF-01 | OPS | API median latency under 1.5 s for dashboard, reports and tax codes (gate host, small data set) | **PASS** | auth/me 11ms / workspace/summary 15ms / portal/trial-balance 11ms / portal/aging 11ms / professional-output/v5980/tax-summary 22ms / professional-output/v5980/general-ledger 25ms / |
| PF-02 | OPS | 20 concurrent dashboard loads all succeed | **PASS** | statuses ok=20/20 in 86 ms |
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
| CO-01 | R141 | Company in United States / NY can be created | **PASS** | 201 {"company":{"id":"company_12a3f7db08b7d04456d0ce25140f70f3","name":"R141 New York LLC","legalName":"R141 New York LLC","businessType":"corpo |
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
| OB-01 | R141 | Invitation email link carries the token after # (not in the query string) | **PASS** | https://gate.test/app.html#accountSetup=<token> |
| OB-02 | R141 | Invitation details looked up by POST body | **PASS** | 200 |
| OB-03 | R141 | Old-style lookup (?token=) still works for links already sent | **PASS** | 200 |
| OB-04 | R141 | POST lookup from a foreign origin refused | **PASS** | true |
| OB-05 | R141 | POST lookups leave no token in the web access log (only the one old-style GET call does) | **PASS** | 1 log line(s) contain the token |
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
| IN-01 | R144 | Duplicate vendor invoice found: Northern Office Supply NO-1 / NO-2, $226.00, 7 days apart | **PASS** | ["Northern Office Supply has two vendor invoices for $226.00 dated 2026-09-01 and 2026-09-08 (NO-1 and NO-2)."] |
| IN-02 | R144 | Duplicate customer invoice found: Coastal Rentals, two × $525.00 on 2026-09-20 | **PASS** | ["INV-1003 and INV-1002 are both $525.00 to Coastal Rentals on 2026-09-20."] |
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
| IN-30 | R144 | Bank suggestion for the pending Rogers line: 6300 Telephone and Internet, ON code, 67% (2 of 2 similar lines × 2/3) | **PASS** | {"accountId":"account_9163e03f03d9bd498abd9ee56189b02a","accountLabel":"6300 · Telephone and Internet","taxCode":"CODE:taxcode_3c9d5a92b8234895bbeba9cef893485f","taxName":"ON","con |
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
| W10-01 | E2E | Post a $56.50 phone bill to 6300 with the ON code: Dr 6300 $50.00, Dr 1100 $6.50, Cr 1000 $56.50 | **PASS** | ["posted","1000:-5650,1100:650,6300:5000"] |
| W10-02 | E2E | Posting preview shows the HST split before posting | **PASS** |  |
| W10-JS | E2E | No JavaScript errors in Match and Post | **PASS** |  |
| W11-01a | E2E | Selecting the $565.00 bank line offers the recorded payment (Maple Leaf Cafe · INV-1001) | **PASS** | Already recorded?This bank line matches a payment you recorded. Linking uses the existing entry, so nothing is posted twice.Maple Leaf Cafe · INV-1001 · $565.00 |
| W11-02a | E2E | Link: bank line posted against the payment's own journal entry; payment linked | **PASS** | posted/journal_a1768660244d50048dbff4360448cc74 / btx_32622ebf6df2136e4bde706e605f2622/journal_a1768660244d50048dbff4360448cc74 |
| W11-03a | E2E | Nothing posted twice: linking creates no journal entry | **PASS** | "7" |
| W11-01b | E2E | Selecting the $226.00 bank line offers the recorded payment (Northern Office Supply · NOS-5521) | **PASS** | Already recorded?This bank line matches a payment you recorded. Linking uses the existing entry, so nothing is posted twice.Northern Office Supply · NOS-5521 ·  |
| W11-02b | E2E | Link: bank line posted against the payment's own journal entry; payment linked | **PASS** | posted/journal_605993932602cf79f9e7e133a2727b27 / btx_ee2ac85779f90677f4b25d017ef2dea7/journal_605993932602cf79f9e7e133a2727b27 |
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
| UI-01-desk | R144UI | Dashboard shows the Tegh brief with the cash line ($4,887.00) and a link to all insights | **PASS** | Tegh briefWorked out from your books just nowSee All InsightsCash in the bank is $4,887.00, up $4,943.50 from 30 days ago.2 customer invoices are overdue ($2,59 |
| UI-02-desk | R144UI | "See all insights" opens Tegh Intelligence with findings, the chase list (Lakeview Dental first) and the cash chart | **PASS** | Tegh Intelligence / Lakeview Dental$2,260.00 overdue across 1 invoice; the oldes |
| UI-03-desk | R144UI | "Not a problem" marks a finding, and Undo restores it | **PASS** | 6→5→6 |
| UI-04-desk | R144UI | What-if: adding $1,000 a month shows a runway sentence | **PASS** | Even with $1,000.00 more a month, cash would still grow by about $1,443.50 a month. |
| UI-05-desk | R144UI | A finding's Open button goes to its screen (duplicate vendor invoice → Vendor Invoice Register) | **PASS** | Vendor Invoice & Note Register |
| UI-06-desk | R144UI | Match and Post: the pending Rogers line shows "Suggested: 6300 · Telephone and Internet · 67% sure"; Use suggestion fills the account and tax code | **PASS** | Suggested: 6300 · Telephone and Internet · ON67% sureYou posted “Rogers Wireless Pad” to 6300 Telephone and Internet wit / 6300 · Telephone and Internet / ON · Ontario — HST 13% ·  |
| UI-07-desk | R144UI | Tegh Assist answers "how much did I spend on telephone this year" from the books: $1,000.00 | **PASS** | You spent $1,000.00 on 6300 Telephone and Internet in this year.Worked out by Tegh from your posted books · this year (2026-01-01 to 2026-10 |
| UI-08-desk | R144UI | Tegh Assist "top customers this year" lists Lakeview Dental first | **PASS** | Your biggest customer in this year was Lakeview Dental ($2,000.00 before tax, 1 invoice).Lakeview Dental$2,000.00Coastal Rentals$1,300.00Wor |
| UI-09-desk | R144UI | Tegh Assist "anything unusual?" opens Tegh Intelligence | **PASS** | Tegh Intelligence |
| UI-10-desk | R144UI | Ordinary Assist requests still route as before ("who owes me money" → receivables answer) | **PASS** | Receivable AgeingAs of Oct 1, 2026Customers owe you $3,649.00 in total.$2,599.00 of that is overdue · 4 open invoicesSee the detailsCurrent outstanding balances |
| UI-JS-desk | R144UI | No JavaScript errors | **PASS** |  |
| UI-01-phone | R144UI | Dashboard shows the Tegh brief with the cash line ($4,887.00) and a link to all insights | **PASS** | Tegh briefWorked out from your books just nowSee All InsightsCash in the bank is $4,887.00, up $4,943.50 from 30 days ago.2 customer invoices are overdue ($2,59 |
| UI-02-phone | R144UI | "See all insights" opens Tegh Intelligence with findings, the chase list (Lakeview Dental first) and the cash chart | **PASS** | Tegh Intelligence / Lakeview Dental$2,260.00 overdue across 1 invoice; the oldes |
| UI-03-phone | R144UI | "Not a problem" marks a finding, and Undo restores it | **PASS** | 6→5→6 |
| UI-04-phone | R144UI | What-if: adding $1,000 a month shows a runway sentence | **PASS** | Even with $1,000.00 more a month, cash would still grow by about $1,443.50 a month. |
| UI-05-phone | R144UI | A finding's Open button goes to its screen (duplicate vendor invoice → Vendor Invoice Register) | **PASS** | Vendor Invoice & Note Register |
| UI-06-phone | R144UI | Match and Post: the pending Rogers line shows "Suggested: 6300 · Telephone and Internet · 67% sure"; Use suggestion fills the account and tax code | **PASS** | Suggested: 6300 · Telephone and Internet · ON67% sureYou posted “Rogers Wireless Pad” to 6300 Telephone and Internet wit / 6300 · Telephone and Internet / ON · Ontario — HST 13% ·  |
| UI-07-phone | R144UI | Tegh Assist answers "how much did I spend on telephone this year" from the books: $1,000.00 | **PASS** | You spent $1,000.00 on 6300 Telephone and Internet in this year.Worked out by Tegh from your posted books · this year (2026-01-01 to 2026-10 |
| UI-08-phone | R144UI | Tegh Assist "top customers this year" lists Lakeview Dental first | **PASS** | Your biggest customer in this year was Lakeview Dental ($2,000.00 before tax, 1 invoice).Lakeview Dental$2,000.00Coastal Rentals$1,300.00Wor |
| UI-09-phone | R144UI | Tegh Assist "anything unusual?" opens Tegh Intelligence | **PASS** | Tegh Intelligence |
| UI-10-phone | R144UI | Ordinary Assist requests still route as before ("who owes me money" → receivables answer) | **PASS** | Receivable AgeingAs of Oct 1, 2026Customers owe you $3,649.00 in total.$2,599.00 of that is overdue · 4 open invoicesSee the detailsCurrent outstanding balances |
| UI-JS-phone | R144UI | No JavaScript errors | **PASS** |  |
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
| DI-I5 | R145 | Document Intake reads the fuel receipt photo with tax "included" in the total: every field right | **PASS** | ocr; partyName, documentDate, subtotalCents, taxCents, totalCents / R144 reader: 4/5 |
| DI-CMP | R145 | Field accuracy across the six documents, R145 reader vs the R144 reader (for information) | **INFO** | R145 41/41 fields; R144 19/41 fields |
| DI-AMB | R145 | An ambiguous numeric date is never guessed: the date stays empty and both readings are offered | **PASS** | ["",2,true] |
| DI-SCAN | R145 | Scanned PDFs are read by OCR in this browser (the bundled PDF reader no longer needs Map.getOrInsertComputed) | **PASS** | ["ocr",true] |
| DI-UI0 | R145 | DEF-14: the Upload document panel shows a full-width "Upload and Extract Locally" button inside the panel | **PASS** | button 372×40 at x 890, form right edge 1262 |
| DI-UI1 | R145 | Document Intake screen: the uploaded invoice shows the read fields and "Subtotal + tax = total ✓" | **PASS** | INV-20418 / 2026-03-04 / 2026-04-03 / 359.00 / 46.67 / 405.67 / Subtotal + tax = total ✓ |
| DI-UI2 | R145 | Changing the tax so the amounts no longer add up shows the difference straight away | **PASS** | Subtotal + tax is more than the total by 3.33. Check the amounts against the source. |
| DI-UI3 | R145 | A receipt photo with 03/04/2026 offers "March 4, 2026" and "April 3, 2026"; choosing one fills the date and the review saves as ready | **PASS** | March 4, 2026 / April 3, 2026 → 2026-03-04; state ready |
| CV-JS | R145 | No JavaScript errors | **PASS** |  |
| CV-NOPOST | R145 | Nothing was imported, drafted or posted: no bank transactions, vendor invoices or journal entries in the test company | **PASS** | ["0","0","0"] |
