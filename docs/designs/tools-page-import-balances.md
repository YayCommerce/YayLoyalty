---
id: UX-3
title: Tools page: import balances
status: ready
from: BS-5
surfaces: [admin]
editions: [pro, lite]
mockup:
created: 2026-11-19
---
# UX-3 Tools page: import balances

## Job
A store admin moving from another loyalty plugin uploads a CSV of email + points. They check what will happen to each customer, fix or accept the errors, and apply it. Done means every valid row is a ledger row and the merchant knows which rows were skipped and why.

## Where it lives
A new **Tools** item in the YayLoyalty header menu, after Rewards and before Settings, guarded by `yayloyalty_run_tools` (administrators by default). Route `#/tools`. It reuses `PageLayout`, `Card`, `Button`, `Table`, `Alert`, `Badge` and `Field` from `apps/admin/src/components/ui/`. Export and bulk adjust stay where they work today, on Customers. Tools links to them and doesn't copy them.

## Flow
1. Tools → **Import balances** card → *Choose CSV file*.
2. The file is read in the browser. A header row with `email` and `points` is required, `note` is optional.
3. Preview runs on the server in chunks, with a progress line. Then the summary and the rows table show.
4. *Import N rows* → a confirm dialog → apply runs in chunks with a progress bar → a success summary.
5. Other paths:
   - Re-upload the same file: its rows show as **Already imported** and nothing applies.
   - A wrong header: an inline error before any request.
   - Apply interrupted (tab closed, network): uploading the same file again applies only the rows that didn't land.

## Screens

### Tools page
```
Tools
Move points in and out in bulk.

┌ Import balances ─────────────────────────────────────────┐
│ Add points from a CSV with the columns email, points and │
│ an optional note. Customers aren't emailed.              │
│ [Choose CSV file]   Download a sample CSV                │
└──────────────────────────────────────────────────────────┘
┌ Export balances ────────────────┐ ┌ Adjust points in bulk ─────────┐
│ Every customer's balance as CSV.│ │ Select customers or a segment  │
│ [Export CSV]                    │ │ on Customers, then Adjust.     │
└─────────────────────────────────┘ │ [Go to Customers]              │
                                    └────────────────────────────────┘
```
Reuses: `PageLayout`, `Card`, `Button`, and `useCsvExport` + `exportCustomersToCsv` for Export.

### Import preview
```
Import balances · points-export.csv                    [Choose another file]
┌──────────────────────────────────────────────────────────────────────┐
│ 1,204 ready · +86,400 points   12 errors   3 already imported        │
└──────────────────────────────────────────────────────────────────────┘
[All] [Ready] [Errors] [Already imported]          Download errors CSV
 Row  Email                 Points   Balance now → after   Status
 2    ann@example.com        +500     120 → 620            Ready
 7    bob@example        —        —                    ✕ No customer with this email
 9    cy@example.com         -50      20 → -30             ✕ Would leave a negative balance
                                              [Cancel]  [Import 1,204 rows]
```
Reuses: `Table`, `Badge`, `Segmented` (filter), `Alert`, `downloadCsv` / `toCsv` (errors CSV).

## States
- **Empty:** only the cards.
- **Reading/previewing:** the button disabled, "Checking rows… 1,500 of 4,000".
- **Applying:** a progress bar, "Importing… 600 of 1,204". Leaving the page asks first (`beforeunload`).
- **Success:** "Imported 1,204 rows (+86,400 points)", and the summary stays with a *Done* button.
- **Partial failure:** "1,100 imported, 104 failed. Upload the same file again, unchanged, to retry the failed rows."
- **Error:** a bad header, or more than 50,000 rows, as an inline `Alert`. A server error goes through `toastApiError`.
- **No permission:** no menu item, and the route is guarded (404 page as other guarded routes).
- **Pro in Free:** the same page in both editions. Nothing is Pro.

## Copy
- Card title "Import balances"; body "Add points from a CSV with the columns email, points and an optional note. Customers aren't emailed."
- Row statuses: "Ready", "Already imported", "No customer with this email", "Points must be a whole number other than 0", "Email appears more than once in the file", "Would leave a negative balance".
- Confirm: "Import {n} rows? This adds {points} points across {n} customers and can't be undone in one step."
- Ledger note when a row has none: "Imported from {file name}".

## Accessibility
The file input is a real `<input type="file" accept=".csv,text/csv">` behind the button label. Progress uses `role="progressbar"` with `aria-valuenow`. Row status is text, not colour only. The confirm dialog traps focus (existing `Dialog`).

## Decisions
- Add only: the points add to the balance as `ADJUST` rows, so lifetime earned moves (BS-5).
- Unknown emails are errors, and no accounts are created.
- No customer emails.
- Imported points follow the store's current expiry rules from the import date.
- Retry-safe: the import id is a hash of the file's parsed rows, so the same file, even re-saved by a spreadsheet, never applies a row twice. A file whose rows were edited is a new import.

## Acceptance
- Tools appears for an administrator and not for a shop manager.
- A 3-row CSV with one unknown email previews 2 ready and 1 error, and applying writes 2 `adjust`/`import` ledger rows.
- Uploading the same file again shows 2 already imported and applies nothing.
- Export CSV on Tools downloads every customer's balance.
- *Go to Customers* opens the Customers page.
