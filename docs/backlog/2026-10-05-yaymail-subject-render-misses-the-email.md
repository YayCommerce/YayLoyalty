# YayMail's subject render doesn't get the email

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the 2026-10-05 `/code-review` and `/yaytidy` of `6f74844`.

## Today

YayMail hooks `woocommerce_email_subject_{id}` at priority 10 with 2 accepted arguments (`YayMail\Emails\EmailsLoader::register_subject_shortcode_filters()`). So it never sees the third argument, the email being sent, and renders subject shortcodes for an email that isn't about an order with empty render data. To work around it, `PointsEarnedShortcodes::hold_subject_email()` holds the email at priority 9, and `release_subject_email()` drops it at 11.

## Why it matters

The workaround depends on YayMail keeping priority 10. If that changes, the subject's shortcodes render blank, with no error. Every other plugin's email that isn't about an order has the same gap.

## Suggested fix

In YayMail, accept the third `$email` argument and pass `'email' => $email` in the render data when the object isn't a `WC_Order`. `PointsEarnedShortcodes::values()` already reads `$render_data['email']`. Once YayLoyalty can require that YayMail version, delete the constructor, both callbacks and `$subject_email`. Raise it with the YayMail team.

## Done when

- [ ] A YayMail release passes `'email' => $email` in the subject's render data for an email that isn't about an order.
- [ ] YayLoyalty requires that YayMail version or later before it loads `PointsEarnedShortcodes`.
- [ ] `PointsEarnedShortcodes` has no constructor, no `hold_subject_email()` or `release_subject_email()`, and no `$subject_email`.
