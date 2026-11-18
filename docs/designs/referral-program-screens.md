---
id: UX-2
title: Referral program screens
status: ready
from: P-3
surfaces: [admin, my-account, checkout]
editions: [pro]
mockup:
created: 2026-11-17
---
# UX-2 Referral program screens

**Source wireframe:** `~/Downloads/YayLoyalty admin interface/YayLoyalty Referral.dc.html`. It's the user's wireframe for the whole program, "Pro 1.1", seven artboards; it isn't in the repo (an HTML canvas, not an image).

**Seen in the wireframe:**
- **1a Admin, referral settings:** what the friend gets (a discount, minimum order, coupon expiry), what the advocate gets (points or coupon), when it pays out (hold days, order must be complete), qualifying rules, attribution (cookie window, code at checkout), fraud controls, and a live preview of the customer's card.
- **1b Admin, referral report:** a click → signup → order → completed funnel, revenue and cost tiles, and a referrals table with status chips and a reason under each.
- **1c Approval queue:** held and flagged referrals, Approve/Reject per row, bulk approve of unflagged rows, and a reject reason from a list.
- **1d My Account "Referrals" tab:** the offer sentence, link and code with Copy, share buttons, four counts, and the invitation list with customer-worded statuses.
- **1e Launcher card and share sheet.**
- **1f Lite upsell.**
- **1g Landing page for the friend.**

This design covers the **1.1.0 slice** (BS-3, "Revised"): 1a without fraud controls, code at checkout or advocate coupon; 1b's table without the report; 1c without flags; 1d without code and share buttons. The rest is later.

## Job
- **A store admin** turns referrals on, sets what the friend gets (a first-order discount), what the referrer (the advocate) gets (points), and how long the payout is held. They see every referral and its status, and they can pay a held one early or reject it with a reason. Done means: the settings save, the Referrals list matches what happened, and an approved or rejected referral never surprises the customer.
- **A customer** opens My Account → Referrals, copies their link, and later sees each friend's progress in plain words, including when their points arrive. Done means: one click copies the link, and "where are my points" is answered on the page.
- **A friend** who signs up through the link finds the discount already applied at checkout on a qualifying first order.

## Where it lives
- **Admin, new header menu item "Referral"** (Pro only), between Rewards and Settings in `apps/admin/src/components/layout/HeaderMenu.tsx`. It needs `CAP.VIEW`. It has three tabs, built like the Settings page's sub-routes (`router.tsx` `settings/general|expiry|design|emails`):
  - `#/referral/referrals`, the default
  - `#/referral/approvals`, with a count badge when anything is waiting
  - `#/referral/settings`
  - In Lite, the menu item is absent and the routes are the 404 page, like `#/ledger`. The wireframe's Lite upsell (1f) is later.
- **My Account, new endpoint "Referrals"** (`referrals`), after Rewards in the account menu, registered like `my-points` (`includes/Engine/Storefront/AccountMyPoints.php:39-48`, Pro class). It shows only while the program is on.
- **Ways to earn, existing list:** a "Refer a friend" row with the advocate's points, added like birthday's (`includes/Pro/Birthday/BirthdayEarnRow.php`). It shows in the launcher, My Points, the shortcode, the block and the page builder elements.
- **Checkout, existing totals:** the friend's discount is a coupon line labelled "Referral discount", in classic and block checkout. It's a virtual coupon (`CouponProvider`, `docs/knowledge/architecture.md` Redemption), so nothing new is drawn beyond the label.

## Flow
1. **Admin setup:** Referral → Settings, switch **Program live** on, keep or change the defaults, and **Save changes**. The defaults are:
   - friend: $10 fixed off, minimum order $50, coupon valid 30 days
   - advocate: 1,000 points
   - hold: 14 days
   - exclude orders with another coupon: on
   - cookie: 30 days
2. **Customer:** opens My Account → Referrals, reads the offer, clicks **Copy link**, and sends it.
3. **Friend:** opens the link (the cookie is set), registers within 30 days, and the coupon is attached to the account. At checkout with $50 or more, "Referral discount −$10.00" is already applied.
4. **Order completes:** the referral turns **On hold**. The customer's list says "On the way · arrives 19 Sept".
5. **Hold ends:** the points are paid automatically. The referral turns **Rewarded**, and the customer's list says "Completed +1,000".

Other paths:
- **Pay early or reject:** Referral → Approvals lists the held referrals. **Approve** pays now, and **Reject** asks for a reason from a list and pays nothing. The friend keeps their discount either way.
- **Refund inside the hold:** the referral turns Rejected with the reason "Order refunded during the hold". No points move.
- **No qualifying order before the coupon expires:** the referral turns Expired.
- **Existing customer opens a link:** nothing is attributed, and checkout behaves as normal.
- **First use:** the Referrals tab says how referrals start. With the program off, the customer tab, the Ways to earn row and the checkout discount are gone, and admin shows Settings with the switch off.

## Screens

### Referral → Settings (1a, without fraud controls)
```
 Referral                    [ Referrals ] [ Approvals 2 ] [ Settings ]
 ┌──────────────────────────────────────────────────┐ ┌───────────────────────────────┐
 │ Program live                              [ On ] │ │ What the customer sees        │
 ├──────────────────────────────────────────────────┤ │ ┌───────────────────────────┐ │
 │ 1 What the friend gets                           │ │ │ Give $10, get 1,000 points │ │
 │   Discount on their first order                  │ │ │ Your friend gets $10 off   │ │
 │   [ 10.00 ] USD   (•) Fixed  ( ) Percentage      │ │ │ their first order over $50.│ │
 │   Minimum order    [ 50.00 ] USD                 │ │ │ You get 1,000 points 14    │ │
 │   Coupon expires   [ 30 ] days after signing up  │ │ │ days after it completes.   │ │
 ├──────────────────────────────────────────────────┤ │ │ [ shop.example/?yl_ref=…  ]│ │
 │ 2 What the advocate gets                         │ │ │              [ Copy link ] │ │
 │   [ 1,000 ] points   = $10.00                    │ │ └───────────────────────────┘ │
 ├──────────────────────────────────────────────────┤ └───────────────────────────────┘
 │ 3 When it pays out                               │
 │   Hold for [ 14 ] days after the order completes │
 │   0 pays as soon as the order completes.         │
 ├──────────────────────────────────────────────────┤
 │ 4 Qualifying rules                               │
 │   [x] Exclude orders that used another coupon    │
 │   First order only. Self-referrals never count.  │
 ├──────────────────────────────────────────────────┤
 │ 5 Attribution                                    │
 │   Cookie window [ 30 ] days from the click       │
 └──────────────────────────────────────────────────┘
                                   [ Save changes ]
```
- **Page and tabs:** `PageLayout` with the tab links, as the Settings page's tabs (`apps/admin/src/pages/settings/`).
- **Program live:** `Switch`, off by default on a new install. Turning it off hides every storefront piece. Existing referrals keep their state, and held ones still release.
- **Sections 1–5:** numbered `Card`s with `Field`, `FieldLabel` and `FieldDescription`, as the general settings page.
  - Money uses the store-currency `MoneyInput` (`components/ui/custom/amount-inputs.tsx`).
  - Days and points use `NumberInput` with a unit, as the campaign editor (`pro/campaigns/editor/CampaignEditorEarn.tsx:147-180`).
  - No money value next to the points: the point's value comes from the flexible discount reward, which this page doesn't load (decided while building P-3).
- **Fixed or Percentage:** a two-option `RadioGroup`. Percentage shows "%" in place of the currency; above 100 shows the validation error rather than clamping.
- **Minimum order:** 0 means no minimum. The same value is the qualifying minimum (BL-7 decided), so a qualifying order always uses the coupon.
- **Hold for:** 0–90 days, default 14.
- **Exclude orders that used another coupon:** `Checkbox`, on by default.
- **Cookie window:** 1–365 days, default 30.
- **Preview rail:** a static render of the customer's offer card from the current form values, as 1a's rail. It updates as fields change and is read-only.
- **Save changes:** the settings page's existing save bar and toast. Changes apply to new referrals only; held ones keep the terms they were created under, and the help text under the button says so.

### Referral → Referrals (1b's table, without the report)
```
 Referral                    [ Referrals ] [ Approvals 2 ] [ Settings ]
 108 referrals · 84 rewarded

 [ Search advocate or friend          ]
 ( All 108 ) ( Rewarded 84 ) ( On hold 7 ) ( Pending 12 ) ( Rejected 5 ) ( Expired 0 ) ( Reversed 0 )

 ┌──────────────────┬──────────────────┬───────────────────────────┬───────┬─────────┬──────────┬─────────┐
 │ Advocate         │ Friend           │ Status                    │ Order │ Value   │ Reward   │ Date    │
 ├──────────────────┼──────────────────┼───────────────────────────┼───────┼─────────┼──────────┼─────────┤
 │ Marta Bergström  │ p•••@example.com │ REWARDED                  │ #4166 │ $148.00 │ 1,000 pts│ 14 Aug  │
 │ Priya R.         │ l•••@example.com │ ON HOLD  releases 17 Sept │ #4198 │ $96.00  │ 1,000 pts│ 3 Sept  │
 │ Marta Bergström  │ y•••@example.com │ PENDING  no qualifying    │ —     │ —       │ —        │ 2 Sept  │
 │                  │                  │          order yet        │       │         │          │         │
 │ Tom Weaver       │ t•••@example.com │ REJECTED self-referral    │ #4177 │ $58.00  │ —        │ 28 Aug  │
 └──────────────────┴──────────────────┴───────────────────────────┴───────┴─────────┴──────────┴─────────┘
 1–20 of 108                                                       ‹ Previous   Next ›
```
- **Search:** advocate or friend name or email, debounced, as the Ledger page's search (`pro/ledger/StoreLedger.tsx`, `DrawerTabFilters`).
- **Status pills:** a segmented filter with counts, one per status, as 1b.
- **Table:** the Ledger table's markup (`pro/ledger/StoreLedgerTable.tsx`).
  - **Advocate:** name, linking to the customer drawer (nested route `referral/referrals/:userId`).
  - **Friend:** masked email (`p•••@example.com`) as in the wireframe, with the full email on hover in a `Tooltip` for users with `CAP.VIEW`.
  - **Status:** a `Badge` plus one muted line of reason: the release date for On hold, "no qualifying order yet" for Pending, the reject reason for Rejected, "no order before the coupon expired" for Expired, and "order refunded" for Reversed.
  - **Order:** a link to the WooCommerce order. **Value:** the order total in its currency. **Reward:** the advocate's points, paid or due.
  - **Date:** the referral's signup date.
- **Pagination:** 20 per page, as the Ledger page. No row actions here: the actions live in Approvals, so this list stays a record.

### Referral → Approvals (1c, without flags)
```
 Referral                    [ Referrals ] [ Approvals 2 ] [ Settings ]
 Approvals
 2 referrals are on hold. They pay themselves when the hold ends; approve to pay early.

 [x] 2 selected  |  [ Approve selected ]  [ Reject selected ]

 ┌──┬──────────────────┬──────────────────┬───────────────────┬───────┬────────┬──────────┬───────────────────┐
 │  │ Advocate         │ Friend           │ Why it is here    │ Order │ Value  │ Held since│                  │
 ├──┼──────────────────┼──────────────────┼───────────────────┼───────┼────────┼──────────┼───────────────────┤
 │✓ │ Priya R.         │ l•••@example.com │ HOLD ENDING       │ #4198 │ $96.00 │ 3 Sept   │ Reject  Approve   │
 │  │                  │                  │ hold ends 17 Sept │       │        │          │                   │
 │✓ │ Daniel O.        │ j•••@example.com │ HOLD ENDING       │ #4202 │ $132.00│ 4 Sept   │ Reject  Approve   │
 └──┴──────────────────┴──────────────────┴───────────────────┴───────┴────────┴──────────┴───────────────────┘
 The friend keeps their discount either way. Rejecting only withholds the advocate's points.
```
- **List:** only `on_hold` referrals, oldest hold end first. The table and its row checkboxes are the Customers list's (`pages/customers/`, `bulk-actions-menu.tsx`).
- **Approve:** pays the advocate now and turns the row Rewarded. Bulk "Approve selected" works the same.
- **Reject:** opens the reject dialog with a reason from a fixed list:
  - "Suspected duplicate account"
  - "Order refunded or cancelled"
  - "Not a genuine referral"
  - "Other"
  
  The reason shows in the Referrals list and as "Didn't qualify" to the customer. Bulk "Reject selected" asks for one reason for all.
- **Dialog:** `ConfirmDialog` with a `Select`, as the campaign archive dialog (`pro/campaigns/archive/CampaignArchiveDialog.tsx`). Its text is: "Rejecting withholds %s points from %s. The friend keeps their discount. This can't be undone."
- **Who can act:** Approve and Reject need `CAP.MANAGE_CAMPAIGNS`. Without it the list is read-only with no checkboxes.
- **Tab count:** the number of held referrals shows on the Approvals tab and the header item. It's hidden at 0.

### Customer drawer → Referrals (Pro)

From the "YayLoyalty Customer Drawer" design (artboard 3f "Customer drawer — Referrals (Pro)" and 3a's empty state), fitted to this program's statuses and link.

- **Tab:** "Referrals" after Redemptions, with the advocate's referral count beside the label like the other tabs. Lite shows the tab with the Pro badge and the upgrade surface, as Redemptions does.
- **Tiles (three, `CustomerDrawerStat` style):** "Referred" with "{n} rewarded · {n} pending" under it; "Points earned" with "{n}% of their lifetime total"; "Revenue referred" with "first orders only" (the friends' first orders that qualified, in base currency).
- **Referral link card:** the customer's `?yl_ref=CODE` link, read-only, with **Copy** ("Copied" for 2 seconds). Under it, one line from the settings: "{points} to {first name} and {discount} off for the friend, when the friend's first order completes. {n}-day window." and a "Referral settings" link to `#/referral/settings`. Opening the tab creates the customer's code when they have none, so every Pro customer has a link (Decided).
- **Table:** Referred (friend name and email), Signed up (date), First order ("#{number} · {total}", or "no qualifying order yet"), To {first name} ("+{points}" when rewarded, "+{points} on hold" while held, "—" otherwise), To friend ("{discount} · used" when the first order carried the code, "· available until {date}" while pending, "· expired"), Status (the same `Badge` and labels as Referral → Referrals).
- **Pending note:** a muted line under a pending row: "Waiting on a first order. The window closes {date}, after which no points are paid."
- **Empty state:** the link card still shows, then "No referrals yet. The link works from the day the account exists."
- **Paging:** 10 rows with the drawer's pager.

### My Account → Referrals (1d, without code and share buttons)
```
 ┌───────────────────────────────────────────────────────────────────┐
 │ Give $10, get 1,000 points                         worth $10 to you│
 │ Your friend gets $10 off their first order over $50. You get       │
 │ 1,000 points once their order completes and clears our 14-day hold.│
 │                                                                     │
 │ Your link                                                          │
 │ ┌───────────────────────────────────────────┐  [ Copy link ]       │
 │ │ https://shop.example/?yl_ref=BERGSTROM-4K2│                      │
 │ └───────────────────────────────────────────┘                      │
 ├───────────────┬───────────────┬──────────────────┬────────────────┤
 │ Invited  11   │ Ordered  6    │ Points earned    │ On the way     │
 │               │               │ 6,000 ($60.00)   │ 1,000 · 19 Sept│
 └───────────────┴───────────────┴──────────────────┴────────────────┘
 Your invitations
 ┌──────────────────┬─────────────────────────────┬─────────────┬──────────┐
 │ Friend           │ Status                      │ Invited     │ You earned│
 │ p•••@example.com │ Completed                   │ 14 Aug 2026 │ +1,000   │
 │ r•••@example.com │ On the way · arrives 19 Sept│ 5 Sept 2026 │ 1,000    │
 │ y•••@example.com │ Signed up · waiting on a    │ 2 Sept 2026 │ —        │
 │                  │ first order                 │             │          │
 │ t•••@example.com │ Expired · no order within   │ 2 Jun 2026  │ —        │
 │                  │ 30 days                     │             │          │
 └──────────────────┴─────────────────────────────┴─────────────┴──────────┘
 Email addresses are partly hidden to protect your friends' privacy.
```
- **Layout:** server-rendered PHP template in the My Account content area, styled with the account tokens and section classes (`assets/storefront/account/account-rewards.css`, `.yayloyalty-account__section`, `__title`, `__hint`, `__button`).
- **Offer:** the heading and sentence come from the settings, worded as in Copy. The hold is named so "where are my points" is answered.
- **Link and Copy link:** a read-only input selected on focus, `dir="ltr"`. The button uses `navigator.clipboard.writeText()` and falls back to selecting the text. It shows "Copied" for 2 seconds.
- **Counts:** four tiles in the points card's style (`points-card.css`). "On the way" shows the next release date and is hidden at 0.
- **Invitations:** a table in the points history's style (`templates/points-history.php`), newest first, 10 per page with the history's pager. Statuses in the customer's words:
  - Pending → "Signed up · waiting on a first order"
  - On hold → "On the way · arrives {date}"
  - Rewarded → "Completed"
  - Rejected → "Didn't qualify"
  - Expired → "Expired · no order within {n} days"
  - Reversed → "Order refunded"
- **Narrow screens:** below 600px the four counts become two by two, the table becomes stacked rows (as the history does), and Copy link goes full width under the input.

**Ways to earn row, wherever Ways to earn shows:**
```
 ┌───┐ Refer a friend                                 1,000 points
 │ ♥ │ When a friend you referred completes their first order.
 └───┘
```
- **Row:** `rule-list-item.php` through the `yayloyalty_launcher_earn_rows` filter, with a new "users" icon in `AccountMyPointsIcon`. Its link goes to My Account → Referrals.

## States
- **Empty:**
  - Referrals list: "No referrals yet. They appear here when someone signs up through a customer's link." With filters set: "No referrals match these filters."
  - Approvals: "Nothing waiting. Held referrals appear here until their hold ends."
  - My Account: the invitations table is replaced by "No invitations yet. Share your link and they'll appear here." The counts read 0.
- **Loading:** the admin tabs use `Skeleton` rows, as the Ledger page. My Account is server-rendered, so it has no loading state.
- **Saving:**
  - Settings: the save bar's spinner.
  - Approve and Reject: the row's buttons are disabled with a spinner. Bulk actions disable the bar.
- **Success:**
  - Settings: toast "Referral settings saved".
  - Approve: toast "Paid %s points to %s", and the row leaves Approvals.
  - Reject: toast "Referral rejected", and the row leaves Approvals.
  - Copy: "Copied".
- **Validation error:** under each field, with Save blocked:
  - "Enter an amount above 0."
  - "Percentage must be 1 to 100."
  - "Enter 0 to 90 days."
  - "Enter 1 to 365 days."
  - "Enter at least 1 point."
- **Failure:**
  - Admin tabs: `ErrorState` "Could not load referrals." with Retry.
  - Approve or Reject on a referral that changed meanwhile: toast "This referral was already paid or rejected. The list has been refreshed."
  - Copy failure: the text is selected for Ctrl/Cmd+C.
  - A checkout coupon that no longer applies (expired, under the minimum) simply isn't applied. Checkout is never blocked.
- **No permission:**
  - Without `CAP.VIEW`: no Referral menu item, and the routes are the 404 page.
  - Without `CAP.MANAGE_CAMPAIGNS`: Approvals and Settings are read-only, with no actions and disabled fields.
- **Pro feature seen in Lite:** none in 1.1.0. Lite has no menu item, tab, row or discount. The 1f upsell is later.
- **Program off:** the storefront pieces are gone. Admin keeps the list and Approvals, and held referrals still release.

## Copy
- **Header and tabs:** menu "Referral". Tabs "Referrals", "Approvals", "Settings".
- **Settings:**
  - Switch: "Program live".
  - Section 1 "What the friend gets": "Discount on their first order", "Fixed", "Percentage", "Minimum order", help "0 means no minimum. The discount won't apply below it.", "Coupon expires", unit "days after signing up".
  - Section 2 "What the advocate gets": unit "points", help "Lands in their balance like any other earning, and shows in their points history."
  - Section 3 "When it pays out": "Hold for", unit "days after the friend's order completes", help "0 pays as soon as the order completes. A refund during the hold cancels the reward."
  - Section 4 "Qualifying rules": checkbox "Exclude orders that used another coupon", note "First order only. Self-referrals never count."
  - Section 5 "Attribution": "Cookie window", unit "days from the click", help "Set when the link is opened. A friend who signs up within this window is credited to the advocate."
  - Preview heading: "What the customer sees". Button: "Save changes", help "Changes apply to new referrals only."
- **Referrals tab:**
  - Description: "%1$s referrals · %2$s rewarded". Search: "Search advocate or friend".
  - Filters: "All", "Rewarded", "On hold", "Pending", "Rejected", "Expired", "Reversed".
  - Columns: "Advocate", "Friend", "Status", "Order", "Value", "Reward", "Date".
  - Reason lines: "releases %s", "no qualifying order yet", "no order before the coupon expired", "order refunded".
- **Approvals tab:**
  - Description: "%s referrals are on hold. They pay themselves when the hold ends; approve to pay early."
  - Buttons: "Approve", "Reject", "Approve selected", "Reject selected".
  - Column "Why it is here", chip "Hold ending", "hold ends %s". Footer "The friend keeps their discount either way. Rejecting only withholds the advocate's points."
  - Dialog title "Reject this referral?", reason label "Reason", reasons "Suspected duplicate account", "Order refunded or cancelled", "Not a genuine referral", "Other".
  - Toasts "Paid %1$s points to %2$s", "Referral rejected".
  - Empty: "Nothing waiting. Held referrals appear here until their hold ends."
- **My Account:**
  - Menu "Referrals". Heading "Give %1$s, get %2$s", aside "worth %s to you".
  - Offer sentence: "Your friend gets %1$s off their first order over %2$s. You get %3$s once their order completes and clears our %4$s-day hold." With no hold, the ending is "…once their order completes." With no minimum, the first part is "…off their first order."
  - Link: "Your link", "Copy link", "Copied".
  - Counts: "Invited", "Ordered", "Points earned", "On the way".
  - Invitations: "Your invitations", columns "Friend", "Status", "Invited", "You earned". Privacy note "Email addresses are partly hidden to protect your friends' privacy."
  - Status words: as listed under Screens. Empty: "No invitations yet. Share your link and they'll appear here."
- **Ways to earn:** "Refer a friend", "When a friend you referred completes their first order."
- **Checkout:** coupon line label "Referral discount".

## Accessibility
- **Fields:** every settings field has a `FieldLabel`. The radio group has a legend, "Discount type". The My Account link input has the visible label "Your link".
- **Tabs:** the admin tabs are links with `aria-current="page"` on the active one. The Approvals count is read as "Approvals, 2 waiting".
- **Approvals:**
  - Row buttons carry the friend in their accessible name ("Approve referral of l•••@example.com").
  - The reject dialog traps focus, starts on the reason, and returns focus to the button that opened it.
  - Toasts are announced (the existing toaster's live region).
- **Copy:** "Copied" sits in an `aria-live="polite"` span.
- **Tables:** the storefront invitations table uses real `<table>` headers. On narrow screens each stacked row keeps its header as a visible label.
- **Status:** shown as text in every badge and status cell, never colour alone.
- **RTL:** logical CSS properties, as the account CSS already uses. The link input and order numbers stay LTR.

## Decisions
- **Navigation:** Referral gets its own Pro header item with three tabs, as in the wireframe, not a button on Campaigns. The program has its own settings and queue, which a campaign row can't hold. Alternative not taken: settings inside a Referral campaign (the earlier UX-2 draft).
- **Customer screen:** a dedicated My Account "Referrals" tab, as 1d, instead of a card in Rewards, because the invitation list needs room. Alternative not taken: a card in My Points.
- **Approvals without flags:** the queue in 1.1.0 is "hold ending, pay early or reject". Flags, the cap and their review are later (BL-8). Unflagged referrals release themselves, as 1c says.
- **No referral code or share buttons in 1.1.0:** a code only helps once checkout accepts it, and share buttons come with the launcher card and share sheet (1e), later.
- **Reject reasons:** a fixed list, as 1c, so the customer can be told something true ("Didn't qualify") and the list can show why.
- **Friend emails are masked** in admin, as the wireframe shows, with the full email on hover for viewers. Alternative not taken: full emails in the column.
- **Settings change only new referrals:** held referrals keep the terms they were created under, as the 1a footer says.

## Acceptance
- Referral → Settings: set $10 fixed, $50 minimum, 30 days, 1,000 points, 14-day hold, then Save and reload. The values are kept and the preview card reads "Give $10.00, get 1,000 points" (money in the store's price format). Percentage 150 is blocked with "Percentage must be 1 to 100."
- As a customer, My Account → Referrals shows the offer, a `?yl_ref=` link and Copy link → "Copied", and the clipboard holds the link. With the program off, the tab and the Ways to earn row are gone.
- A new visitor opens the link and registers. With a $60 cart, checkout (classic and block) shows "Referral discount −$10.00". With a $40 cart it doesn't.
- Customers → open the advocate → Referrals: the tiles count the referral, the link shows with Copy, and the row shows the friend, the first order and the status. A customer with no referrals sees the link and the empty line.
- After that order completes, Referral → Referrals shows it On hold with "releases {date}". The customer's list shows "On the way · arrives {date}".
- Referral → Approvals → Approve on that row: toast "Paid 1,000 points to {advocate}". The row shows Rewarded in Referrals, the customer's list shows "Completed +1,000", and their balance rose 1,000.
- Reject a held referral with "Not a genuine referral": the Referrals list shows Rejected with that reason, the customer's list shows "Didn't qualify", and no points are paid.
- A user without manage-campaigns sees the Referrals and Approvals tabs read-only. In the Lite build, no Referral menu item exists and `#/referral/referrals` is the 404 page.
- At 375px, My Account → Referrals shows the counts two by two, stacked invitation rows, and a full-width Copy link, with no horizontal scroll.
