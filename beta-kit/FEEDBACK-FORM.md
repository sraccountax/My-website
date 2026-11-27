# Tegh beta feedback form

Build this form in Microsoft Forms, Google Forms or similar, using the questions below in this order. Keep it to one page; it takes a tester about three minutes.

Set the form to collect email addresses only from the testers you invited. Do **not** add respondents to any mailing list (keep marketing consent separate; CASL).

---

**Intro text**
> Thank you for testing Tegh. Please describe one problem or idea per form. Use sample data only; screenshots must not show real client, employee or bank information. Urgent problems (cannot sign in, you see another company's data, numbers are wrong after posting) email **[support email]** right away.

| # | Question | Type | Required | Options / hint |
|---|---|---|---|---|
| 1 | Your email | short text (or collected automatically) | yes | |
| 2 | What kind of feedback is this? | choice | yes | Something is wrong (bug) · Wrong amount or total · Confusing screen or wording · Missing feature or idea · Layout or phone problem · Other |
| 3 | How serious is it? | choice | yes | **Blocking**: I cannot continue · **Wrong figures**: amounts, tax or totals look wrong · **Annoying**: works, but hard to use · **Minor**: small polish |
| 4 | Screen | short text | yes | the title at the top of the page, for example "Customer Invoice & Note Register" |
| 5 | What did you do? (steps) | paragraph | yes | "1. Opened … 2. Clicked … 3. Entered …" |
| 6 | What did you expect to happen? | paragraph | yes | |
| 7 | What actually happened? | paragraph | yes | copy any message shown on screen |
| 8 | Screenshot | file upload | no | sample data only |
| 9 | Device and browser | choice + other | yes | Windows · Mac · iPhone/iPad · Android · Other; then the browser: Chrome · Edge · Safari · Firefox |
| 10 | Theme and layout | choice | no | Light · Dark · System; side menu or top menu |
| 11 | Date and time it happened | date/time | no | helps us find it in the logs |
| 12 | May we contact you about this? | yes/no | yes | |

---

## Daily triage (for you)
Copy each response into `feedback-log.csv`. Then sort by severity:
- **Blocking / wrong figures / another company's data:**
  - reply the same day;
  - stop inviting new testers until it is fixed;
  - make a backup before any fix is deployed.
- **Annoying / minor:** collect them and fix them in the next update.
- After each update, ask the tester who reported the problem to check it again, and mark it **Verified**.
