# The customer drawer guesses an expiry reminder's deadline instead of reading it

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** Misleading-names review, 2026-10-30, branch trace/refactor/misleading-names (left out of the rename because it changes behaviour).

## Today

`Services\CustomerActivityService::record_expiring_soon_email_sent()` stores `points`, `expires_at` and `reminder_days` in the `expiring_soon_email_sent` row's data. `expires_at` is the soonest expiry date the email warned about, in UTC, from `Engine\Emails\PointsExpiringSoonEmail::trigger()`. `Controllers\ActivityRestController` sends the data as it is, and `activity.schema.ts` parses it as a record.

In `apps/admin/src/pages/customers/drawer/customer-drawer.activity-rows.ts`, the `expiring_soon_email_sent` case ignores `expires_at`. A comment says "The API does not send a deadline", and the code computes `deadline` as `occurred_at` plus `reminder_days` days, which `activityExpiringSoonEmailSubLine()` prints as "deadline was …".

## Why it matters

The guess is wrong whenever the reminder didn't go out exactly `reminder_days` before the expiry: the cron ran late, the merchant changed the reminder timing, or the customer's points expire on several dates. Support then tells a customer the wrong date their points expired.

## Suggested fix

Read `expires_at` from the row's data and format it with `formatWPDate()`, and delete the comment and the computation. Keep the computed date only as a fallback if rows without `expires_at` must still render; with no live users they don't. Left out of the rename because it changes the date the drawer shows.

## Done when

- [ ] The `expiring_soon_email_sent` case in `customer-drawer.activity-rows.ts` reads `expires_at` from the row's data.
- [ ] `customer-drawer.activity-rows.ts` has no comment saying the API does not send a deadline.
- [ ] For an `expiring_soon_email_sent` row whose `expires_at` is not `occurred_at` plus `reminder_days`, the customer drawer's Activity tab shows the `expires_at` date.
