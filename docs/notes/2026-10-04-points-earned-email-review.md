# Points earned email: open findings from its reviews

**Purpose:** the findings still open from two reviews of the points earned email, kept for later: the 2026-10-04 code review of `paul/feat/email-points-earned`, and the 2026-10-05 `/code-review` and `/yaytidy` of `6f74844`. For each one: how the code behaves today, why that is a problem, and a suggested fix. The other findings were fixed in `6f74844` and `trace/fix/points-earned-email-review`, and are not listed here.

**Update when:** a finding is fixed or dropped; remove its section. Delete this note when no section is left.

Citations give a path and a symbol; search for the symbol.

## Test gap

- **Today:** `f4290fe` deleted the feature's integration tests, `tests/integration/Emails/PointsEarnedEmailTest.php` (21 tests) and `YayMailPointsEarnedTest.php`, and the shared `tests/integration/WooCommerceEmailsFixture.php`. The launcher's review fixes then deleted `tests/integration/WooCommerceOrdersFixture.php`, which no test used any more.
- **Why it is a problem:** nothing exercises the send path. A regression in any of these ships unseen:
  - one send per award, never a second for a duplicate;
  - the award kept when the mailer throws;
  - apostrophes and other entities in plain text (`LoyaltyEmail::get_content_plain()`);
  - the value line hidden by default;
  - the preview sample;
  - the weekly cap (`EmailThrottle`): the send at the cap is skipped, a failed send isn't counted, and a send after 7 days passes;
  - the relay (`Emails::relay_award()`) passing only the row id, and `PointsEarnedEmail::trigger()` skipping an award that was clawed back. On WooCommerce 10.8+ with deferred emails, the send runs later from Action Scheduler, which is when a refund can land first. The 2026-10-05 hand-run check covered only immediate sending.
- **Suggested fix:** restore both fixtures and the email tests from history: `WooCommerceEmailsFixture.php` from `2054e97^`, and `WooCommerceOrdersFixture.php` from `957cc74`. Turn this one email back on in them, and make `WooCommerceOrdersFixture::detach_yaymail()` put back what it removes. Then add the cap and relay cases.

## The greeting and balance sentences have three copies

- **Today:** "Hi %s," and "Your balance is now %s." are written out in `templates/emails/yayloyalty-points-earned.php`, in `templates/emails/plain/yayloyalty-points-earned.php`, and in `PointsEarnedYayMailEmail::get_default_elements()`.
- **Why it is a problem:** a wording change made in one copy leaves the others behind, and each copy's msgid must match for translations to apply.
- **Suggested fix:** build both sentences in `PointsEarnedEmail::build_values()`, as `earned_text` is built, and print them from the templates. This adds template variables, which are a public contract, so it is left to the feature's owner.

## YayMail's subject render doesn't get the email

- **Today:** YayMail hooks `woocommerce_email_subject_{id}` at priority 10 with 2 accepted arguments (`YayMail\Emails\EmailsLoader::register_subject_shortcode_filters()`). So it never sees the third argument, the email being sent, and renders subject shortcodes for an email that isn't about an order with empty render data. To work around it, `PointsEarnedShortcodes::hold_subject_email()` holds the email at priority 9, and `release_subject_email()` drops it at 11.
- **Why it is a problem:** the workaround depends on YayMail keeping priority 10. If that changes, the subject's shortcodes render blank, with no error. Every other plugin's email that isn't about an order has the same gap.
- **Suggested fix:** in YayMail, accept the third `$email` argument and pass `'email' => $email` in the render data when the object isn't a `WC_Order`. `PointsEarnedShortcodes::values()` already reads `$render_data['email']`. Once YayLoyalty can require that YayMail version, delete the constructor, both callbacks and `$subject_email`. Raise it with the YayMail team.
