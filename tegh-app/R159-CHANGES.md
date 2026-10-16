# R159 changes over R158: onboarding for every company, archiving, and deletion through Tegh support

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r159-tegh`.
Status: **private beta candidate** (invitation-only, sample data only). `productionReady: false`.
Database: added on first use. Two nullable columns, `companies.archived_at` and `companies.archived_by`, and one table, `company_deletion_requests`. Earlier versions ignore them.

## Every new company has its own onboarding
**What was wrong.**
- **Settings › Add company** offered "Default Chart" and chose it unless changed. A second company therefore arrived with about 44 accounts and the starter tax codes already in place.
- Its onboarding then showed the chart and tax steps as already handled, and the chart template was refused ("chart not empty").

**Now.**
- **Every company added in the app starts its own onboarding**, from the first-company screen, Settings › Add company, or the guided form. It shows step 1 of 6 as complete, the other five open, and every module locked.
- **The company starts with an empty chart and no tax codes.** In onboarding step 2 you choose how to set up *this* company's chart of accounts: import, template or by hand. In step 3 you do the same for its tax codes.
  - The server enforces this for any company created with onboarding, whatever the request asks.
  - Companies created without onboarding are unchanged: API books and the platform owner's test companies.
- The Add company form no longer offers the chart choice. A note says that onboarding follows.
- **Each company's onboarding is separate.** A finished company opens Home. A new company opens its onboarding, and switching between them shows each one's own state.
- **Everything set up in onboarding belongs to that company only.** This covers the chart template, the tax codes from a file or template, and the bank accounts. Other companies' accounts and tax codes do not change, and no tax code points to another company's accounts. Gate checks PC-02 and PC-04 cover this.

## Archive a company
- **Where:** Account & Access › the company's **Actions › Archive**, or **Company Details › Archive Company**. Only the company owner can archive.
- **What it does:** the company leaves the company switcher, company lists and reports for **every member**, and nobody can open or change it. This covers its books, client viewing links and invitations. Its records are kept unchanged, and the archive is recorded in its audit history.
- **Bringing it back:** **Account & Access › Archived companies** lists the owner's archived companies with **Restore**. Restore brings the company back as it was.
- **Fixed on the way:** the app kept its copy of the company list for 60 seconds, renewed by every request while it was in use. An archived or restored company could keep showing, or stay missing, until the page was reloaded. The copy is now refreshed after every company change.

## Permanent deletion goes through Tegh support
- **A company owner no longer deletes a company directly.** **Actions › Request deletion** (or Company Details › Request Deletion) opens the request form:
  - the company name, typed exactly;
  - the owner's password;
  - a reason for Tegh support;
  - confirmation that the final backup was downloaded.
- **On sending:**
  - the company is archived at once;
  - Tegh support is emailed: the `support_email` in config.php's operator section, and every platform owner;
  - the request waits on **Platform Owner Home › Company deletion requests**.
- **The platform owner decides.** The request shows who asked, the reason, and how many records the deletion would remove (counts only, never the records).
  - **Approve:** the platform owner types the company name and their own password. The company is then deleted with the same checks and deletion log as in R158; the log names the request.
  - **Reject:** a note to the owner is required. The company stays archived and can be restored.
  - The owner is emailed either way.
- **The owner can cancel** a pending request; the company stays archived. **Restoring** a company cancels its pending request.
- `operations/company-delete` now answers 409 (`company_delete_by_request`) for anyone but the platform owner. The platform-owner override in Platform Owner Home › Companies is unchanged.
- Deletion requests are kept after the company is deleted, as the record of who asked and who decided.

## Gate tests
**27-r159 (15 checks).**
- **Onboarding (PC-01…04):**
  - a second company added in the browser: own onboarding, empty chart, no tax codes, onboarding page opens;
  - its template and tax file affect only that company;
  - each company's lock state in the browser;
  - server enforcement.
- **Archive (AR-01…04):**
  - archive: hidden from the lists, refused when opened, records unchanged;
  - owner only (a Company Admin gets 403; someone who is not a member gets 404);
  - a Company Admin no longer sees it, and nobody can be invited to it;
  - restore;
  - archive and restore in the browser.
- **Deletion requests (DR-01…07):**
  - direct deletion refused for an owner;
  - request checks (name, password, backup, reason), duplicate refused, support emailed;
  - only the platform owner sees the inbox, with record counts;
  - reject (note required, owner emailed);
  - cancel, and restore cancels;
  - approve (name and password checked; no row left in any company table; log names the request; owner emailed);
  - the whole flow in the browser: the owner requests, the platform owner approves.

**26-r158 DEL-05** now follows the request and approval in the browser. DEL-01…04 (the platform owner deleting directly through the API) are unchanged.

## Upgrading from R158
- Back up first.
- Confirm that the database user may add columns and tables. R159 adds two columns to `companies` and the table `company_deletion_requests` on first use.
- Upload the whole package. The cache token is r159-tegh.
- Make sure `operator.support_email` in config.php is a mailbox you read, because deletion requests are sent there.
- The beta-ops backup scripts are unchanged from R158.
