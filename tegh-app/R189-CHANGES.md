# R189: approval limits removed (Canada and India)

R189 = R188 plus the change below.

## What changed
Before R189, an amount above the approval limit (10,000.00 in the company currency, for Canadian and India companies alike) needed a second Company Owner. From R189 there is no amount-based approval limit. Each of these now posts or applies at once, whatever the amount:
- a manual journal entry;
- a vendor invoice;
- a transaction void (Void in any register);
- a customer or vendor credit or debit note (these were refused outright above the limit);
- an invoice void;
- a bank-line unpost;
- an interbank transfer reversal.

Roles are unchanged: who may post, void or unpost is the same as before. The audit log still records who did what.

## Requests already waiting for approval
Requests made before R189 keep waiting, and a second Company Owner can still approve them as before. The person who made a request still cannot approve it. They can also be left alone or cancelled the usual way.
- **Recommendation:** before deploying, approve or reject anything already waiting, so nothing is left half-done.

## Turning limits back on
An operator can turn the limits back on by adding `'approval_limits_enabled' => true` to the `accounting` section of the server's private `config.php`. The stored per-company limit (`accounting_controls.materiality_threshold_cents`) is kept unchanged and is used again when the limits are on.

## Not changed
- The extra identity check (MFA step-up) for large journal entries proposed by Ask Tegh (`ai.journal_step_up_threshold_cents`). This asks the same person to confirm who they are; it is not a second approver.
- The AI review agents' materiality setting, which only decides what they flag.

## Database
No schema change. Schema stays 46.
