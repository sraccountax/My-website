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
| H-10 | H-INST | startup/status after install | **PASS** | 200 {"ok":true,"version":"5.9.9","build":5990,"schemaVersion":43,"expectedSchemaVersion":46,"userId":"user_80d9167590471b2a8e7ed0356303dfab","coreReady":true,"aiReady":true,"native |
| H-11 | H-INST | Fresh install: protected database upgrade (Schema 43 → 46) completes from the owner session | **PASS** | 200 steps schema46.5990.accounting_notes_r67:completed,schema46.5990.invoice_note_tax_columns_r69:completed,schema46.5990.accounting_note_settlements_r70:completed,schema46.5990.co |
| H-12 | H-INST | After upgrade: startup status Schema 46, not degraded | **PASS** | {"s":46,"d":false,"w":null} |
| H-M01 | H-MAIL | SMTP delivery test (STARTTLS + AUTH) reaches sandbox | **PASS** | 200 sent=true outcome=accepted mailfile=1790770710.417628.eml X-Sandbox-Tls: True / X-Sandbox-Auth: True / X-Rcpt: owner@gate.test |
| H-M02 | H-MAIL | Test email to non-owner address refused | **PASS** | 403 |
| H-M-editor | H-MAIL | Invitation email for editor delivered over STARTTLS+AUTH with single-use link | **PASS** | 200 delivery=sent link=present msg=The mail server accepted the message for delivery. |
| H-A-editor | H-MAIL | Invitee (editor) accepts and sets password | **PASS** | 200 {"accepted":true,"auth":{"user":{"id":"user_40b8dd5e119efe82b353d5cfea55dbe8","email":"editor@gate.test","displayName":" |
| H-R-editor | H-MAIL | Invitation link cannot be reused | **PASS** | 410 |
| H-L-editor | H-MAIL | editor can sign in | **PASS** | 200 |
| H-M-viewer | H-MAIL | Invitation email for viewer delivered over STARTTLS+AUTH with single-use link | **PASS** | 200 delivery=sent link=present msg=The mail server accepted the message for delivery. |
| H-A-viewer | H-MAIL | Invitee (viewer) accepts and sets password | **PASS** | 200 {"accepted":true,"auth":{"user":{"id":"user_c047ddd7f9026ef6b28c51fe36f663c8","email":"viewer@gate.test","displayName":" |
| H-R-viewer | H-MAIL | Invitation link cannot be reused | **PASS** | 410 |
| H-L-viewer | H-MAIL | viewer can sign in | **PASS** | 200 |
| H-M-admin | H-MAIL | Invitation email for admin delivered over STARTTLS+AUTH with single-use link | **PASS** | 200 delivery=sent link=present msg=The mail server accepted the message for delivery. |
| H-A-admin | H-MAIL | Invitee (admin) accepts and sets password | **PASS** | 200 {"accepted":true,"auth":{"user":{"id":"user_9ac8c8098e608bba60d2351ae2ab0832","email":"admin@gate.test","displayName":"A |
| H-R-admin | H-MAIL | Invitation link cannot be reused | **PASS** | 410 |
| H-L-admin | H-MAIL | admin can sign in | **PASS** | 200 |
| H-M-other | H-MAIL | Invitation email for admin delivered over STARTTLS+AUTH with single-use link | **PASS** | 200 delivery=sent link=present msg=The mail server accepted the message for delivery. |
| H-A-other | H-MAIL | Invitee (admin) accepts and sets password | **PASS** | 200 {"accepted":true,"auth":{"user":{"id":"user_e15edca61e3c8cd0d93a2a088dd06c20","email":"otherowner@gate.test","displayNam |
| H-R-other | H-MAIL | Invitation link cannot be reused | **PASS** | 410 |
| H-L-other | H-MAIL | admin can sign in | **PASS** | 200 |
| D-MAILMSG | H-MAIL | Successful invitation send shows a success diagnostic (not "rejected") | **PASS** | {"message":"The mail server accepted the message for delivery.","nextAction":"Recipient can use the new link.","reference":"08bf923331c1ec09","smtpCode":250,"stage":"final_data_acc |
| TX-00 | ACCT | Starter codes present in new BC company | **PASS** | "AB,BC,GST,MB,NB,NL,NS,NT,NU,ON,PE,QC,SK,YT" |
| AC-01 | ACCT | Opening balances post a balanced journal: Dr bank $10,000 / Cr equity $10,000 | **PASS** | PUT 200/200 POST 201 {"posted":true,"openingBalanceImport":{"id":"opening_fcd846097594bd0d8d0a779d594e081d","journalEntryId":"journal_5ccb21410c8ce7b328c7f9003fa2c4a9","effectiveDa |
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
| AC-06 | ACCT | Vendor payment $525 settles GST-only bill | **PASS** | 201 {"payment":{"id":"payment_80caa1c0b1e27fea1d362892abda7272","type":"vendor","flowKind":"vendor_payme |
| BK-01 | ACCT | CSV statement import: preview shows 5 rows, approve imports 5 | **PASS** | rows=5 selected=5 approve=201 {"batch":{"id":"import_d109c20ba1161144e3a8085c3b15ed84","status":"needs_review","rowCount":5,"selectedCount":5,"omittedCount":0,"duplicateCount":0,"c |
| BK-02 | ACCT | Re-importing the same statement: all 5 rows flagged duplicate, none imported | **PASS** | dups=5 approve=422 count=5 |
| TX-14 | ACCT | Bank deposit $1,149.75 with QC code: income $1,000, GST $50 → 2100, QST $99.75 → 2115 | **PASS** | {"1000":114975,"2100":-5000,"2115":-9975,"4000":-100000} |
| TX-15 | ACCT | Bank purchase $1,149.75 with QC code: ITC $50 → 1100, QST ITR $99.75 → 1115 | **PASS** | {"1000":-114975,"1100":5000,"1115":9975,"6000":100000} |
| TX-16 | ACCT | Bank purchase $1,120 with BC code: ITC $50, PST $70 in cost ($1,070) | **PASS** | {"1000":-112000,"1100":5000,"6000":107000} |
| AC-07 | ACCT | Three simultaneous posts of the same bank line create one journal | **PASS** | statuses 200,409,200 journals=1 |
| AC-08 | ACCT | Posting a bank line to its own bank GL account (same-account transfer) refused | **PASS** | 422 {"error":"Transfer From and Transfer To must be different accounts.","code":"transfer_same_account"} |
| AC-08b | ACCT | Owner transfer posted to equity instead | **PASS** | 200 |
| AC-09 | ACCT | Re-posting an already posted bank line refused / no second journal | **PASS** | 200 journals=1 |
| AC-10 | ACCT | Balanced manual journal $123.45 posts | **PASS** | 201 {"journalEntry":{"id":"journal_757e9cad15fd8f01e35be483468ce8b2","status":"posted"},"approvalId":null,"approvalRequired" |
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
| AC-30 | ACCT | Trial Balance identical on re-run | **PASS** | "[{\"id\":\"account_8329315ae47a61f492c4121a629e4cac\",\"code\":\"1000\",\"name\":\"Business Chequing\",\"type\":\"asset\",\"normalBalance\":\"debit\",\"openingDebitCents\":0,\"ope |
| AC-31 | ACCT | Editing the QC code after posting leaves posted history unchanged | **PASS** | [200,true] |
| QT-01 | TAX | QC company invoice $1,000: GST $50 → 2100, QST $99.75 → 2115 | **PASS** | {"1200":114975,"2100":-5000,"2115":-9975,"4000":-100000} |
| QT-02 | TAX | QC bill $500: GST ITC $25.00 → 1100, QST ITR $49.88 (49.875 rounded half-up) → 1115; AP $574.88 | **PASS** | {"1100":2500,"1115":4988,"2050":-57488,"6000":50000} |
| QT-03 | TAX | QC statement import (2 rows) | **PASS** | 201 {"batch":{"id":"import_8db4b6c1ddadc21e980006c295231e53","status":"needs_review","rowCount":2,"selec |
| QT-04a | TAX | Remittance with period end after the payment date refused | **PASS** | [422,"sales_tax_period_invalid"] |
| QT-04 | TAX | QST remittance $49.87: Dr 2115 $99.75, Cr 1115 $49.88, Cr bank $49.87 | **PASS** | [200,{"1000":-4987,"1115":-4988,"2115":9975}] |
| QT-05 | TAX | After QST remittance: 2115 = $0.00 and 1115 = $0.00 | **PASS** | [0,0] |
| QT-06 | TAX | GST remittance $25.00: Dr 2100 $50, Cr 1100 $25, Cr bank $25; 2100 and 1100 back to $0 | **PASS** | [200,{"1000":-2500,"1100":-2500,"2100":5000},0,0] |
| QT-07 | TAX | GST/HST (tax) Summary report includes QST accounts 2115 and 1115 | **PASS** | status 200 codes "code":"1100" "code":"1110" "code":"1115" "code":"2100" "code":"2110" "code":"2115" |
| CG-01 | TAX | Create custom tax accounts 2120/1120 | **PASS** | 201/201 {"account":{"id":"account_4ae2ddc34b620a27982b74eba6a074a3","gifiCode":null}} |
| CG-02 | TAX | Starter ON code edited to post HST to custom 2120/1120 | **PASS** | 200 |
| CG-03 | TAX | ON invoice $200: HST $26.00 → 2120 (custom), nothing to 2100 | **PASS** | {"1200":22600,"2120":-2600,"4000":-20000} |
| CG-04 | TAX | ON bill $100: HST ITC $13.00 → 1120 (custom) | **PASS** | {"1120":1300,"2050":-11300,"6000":10000} |
| CG-05 | TAX | Trial Balance shows 2120 Cr $26.00 and 1120 Dr $13.00 | **PASS** | [2600,1300] |
| CG-06 | TAX | General Ledger for 2120 lists the $26.00 credit | **PASS** | status 200 {"currency":"CAD","start":"2026-01-01","end":"2026-09-30","account":{"id":"account_4ae2ddc34b620a27982b74eba6a074a3","code":"2120","name":"HST Payable (custom)" |
| CG-07 | TAX | Tax Summary report includes custom 2120 ($26.00) and 1120 ($13.00) | **PASS** | status 200 |
| CG-08 | TAX | Dashboard tax card reports custom-account HST: other tax accounts net $13.00 owed (2120 $26 − 1120 $13) | **PASS** | "gstHstCollectedCents":0 "gstHstRecoverableCents":0 "gstHstNetCents":0 "pstPayableCents":0 "pstRecoverableCents":0 "qstPayableCents":0 "qstRecoverableCents":0 |
| NR-01 | TAX | Company NOT registered for GST/HST: default taxable invoice line charges no GST/PST | **PASS** | starter codes=14 mode=codes journal={"1200":10000,"4000":-10000} |
| NR-02 | TAX | Unregistered company: $100 bill with BC code — GST $5 and PST $7 added to cost ($112), no ITC | **PASS** | {"2050":-11200,"6000":11200} |
| NR-03 | TAX | Unregistered company: a code chosen explicitly on the line is still applied (GST $5) | **PASS** | {"1200":10500,"2100":-500,"4000":-10000} |
| CB-01 | TAX | Cash basis: receipt of $565 (half) recognises HST $65.00 in 2100 and revenue $500 | **PASS** | [-6500,-50000] |
| CB-02 | TAX | Cash basis: invoice issue posting recorded for review | **INFO** | {} |
| FX-01 | FX | Add USD at 1.35 | **PASS** | 201 |
| FX-01b | FX | Add USD bank account | **PASS** | 201 {"bankAccount":{"id":"bank_bec1b5391fdda47dba122d793548e534","accountType":"bank","accountSubtype":"chequing"}} |
| FX-02 | FX | USD 1,000 invoice at 1.35: revenue CAD $1,350.00, GST CAD $67.50, AR CAD $1,417.50 | **PASS** | [201,{"1200":141750,"2100":-6750,"4000":-135000}] |
| FX-03 | FX | USD 1,050 receipt at 1.40: bank CAD $1,470.00, AR −$1,417.50, FX gain $52.50 | **PASS** | 201 {"payment":{"id":"payment_3da5d69bf680b9eff41d6dd573646748","type":"customer","flowKind":"customer_receipt","status":"po lines: 1010	147000	0 / 1200	0	141750 / 6850	0	5250 |
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
| RO-09 | SEC | Editor can create a draft invoice | **PASS** | 201 {"invoice":{"id":"invoice_b223b9bae17bb10543b72443c3e266fe","number":"INV-1008","status":"draft","to |
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
| PY-02 | PAY | Employee can be created without a SIN (Employee ID only) | **PASS** | 201 {"employee":{"id":"employee_d381f1f5ce9fa622e132be2160ce115a"}} |
| PY-03 | PAY | No SIN (ciphertext or last four) stored in the database | **PASS** | "0" |
| PY-03b | PAY | No SIN stored for any company on the gate database | **PASS** | "0" |
| PY-04 | PAY | Payroll workspace API returns no SIN fields/values | **PASS** |  |
| PY-05 | PAY | Company backup contains no SIN value | **PASS** | 200 size=15335 |
| PY-06 | PAY | Payroll notice text present in app bundle ("Payroll Support Tool") | **PASS** |  |
| PY-07 | PAY | Payroll rate tables: year/version reported for owner verification | **INFO** |  |
| BR-01 | REC | Backup export downloads a signed .tegh archive | **PASS** | 200 application/vnd.tegh.backup bytes=41675 |
| BR-02 | REC | Restore into a new company succeeds | **PASS** | 201 {"restored":true,"companyId":"company_8e9b3ed92e718d385f7e360ceb418933","companyName":"Gate Test BC Ltd (Restored copy)","payloadSha256":"0eb3bf6831d3650e8158fa8080f6fe5781df79 |
| BR-03 | REC | Restored company Trial Balance equals source, account by account | **PASS** | {"1000":861300,"1010":147000,"1100":23800,"1115":19950,"1200":321776,"2050":-226975,"2100":-36850,"2110":-6300,"2115":-19950,"3000":-962345,"4000":-605001,"6000":488845,"6850":-525 |
| BR-04 | REC | Restored record counts equal source (documents, journals, bank lines, tax codes, tax detail, payments, notes) | **PASS** | "invoices=8 bills=3 customers=6 vendors=2 journal_entries=22 bank_transactions=5 tax_codes=15 document_tax_lines=18 party_payments=3 accounting_notes=1" |
| BR-05 | REC | Restored AR and AP ageing totals equal source | **PASS** | [321776,226975] |
| BR-08 | REC | Tampered backup refused with a 4xx integrity message (no 500) | **PASS** | 422 backup_integrity_invalid |
| BR-06 | REC | Tax codes, rates and GL mapping identical after restore | **PASS** | "AB:GST:5000:2100/1100:active,BC:GST:5000:2100/1100:active,BC:PST:7000:2110/-:active,GST:GST:5000:2100/1100:active,MB:GST:5000:2100/1100:active,MB:RST:7000:2110/-:active,NB:HST:150 |
| BR-07 | REC | No cross-company account references after restore | **PASS** | "0" |
| BR-09 | REC | Restored document tax detail points to restored accounts only | **PASS** | "0" |
| PF-01 | OPS | API median latency under 1.5 s for dashboard, reports and tax codes (gate host, small data set) | **PASS** | auth/me 9ms / workspace/summary 13ms / portal/trial-balance 9ms / portal/aging 9ms / professional-output/v5980/tax-summary 23ms / professional-output/v5980/general-ledger 23ms / re |
| PF-02 | OPS | 20 concurrent dashboard loads all succeed | **PASS** | statuses ok=20/20 in 123 ms |
| LG-01 | OPS | Incident log (private, outside web root) contains no passwords, session cookies, CSRF tokens or SINs | **PASS** |  |
| LG-02 | OPS | Web access log contains no client-view tokens or invitation tokens (tokens travel in the fragment/body) | **PASS** |  |
| LG-03 | OPS | PHP error log: warnings recorded (see evidence) | **INFO** | PHP Warning:  POST Content-Length of 11534510 bytes exceeds the limit of 8388608 bytes in Unknown on line 0' |
| SP-01 | OPS | In-app support route responds | **PASS** | 200 {"requests":[],"platformOwner":true} |
| SP-02 | OPS | Public contact page offers a working contact path (form posts to /api/marketing) | **INFO** | no mailto |
| UP-01 | UPG | After uploading R139 over R118: startup status not degraded, schema 46 | **PASS** | schema 46 degraded false warning null |
| UP-02 | UPG | Existing company opens (dashboard summary) | **PASS** | 200 |
| UP-03 | UPG | R137 tables/columns created automatically on the first ordinary API request (no manual migration) | **PASS** | "tax_codes,tax_code_components,document_tax_lines" |
| UP-04 | UPG | Ledger balances unchanged by the upgrade | **PASS** | "1000\t50000\n1200\t55000\n2100\t-5000\n4000\t-100000" |
| UP-05 | UPG | Existing company stays on its previous tax rules (legacy mode) until it adds tax codes | **PASS** | "legacy" |
| UP-06 | UPG | Post-upgrade invoice ($200, BC customer, legacy rules) posts GST $10 to 2100 | **PASS** | 1200	21000 / 2100	-1000 / 4000	-20000 |
| UP-07 | UPG | Pre-upgrade invoice can be settled after upgrade | **PASS** | 201 balance 0 |
| UP-08 | UPG | Existing company: Add Canadian tax codes creates 14 codes, QST accounts 2115/1115, and switches to tax codes | **PASS** | 201 codes=14 |
| UP-09 | UPG | All journals balance after upgrade | **PASS** | "0" |
