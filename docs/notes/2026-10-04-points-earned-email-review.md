# Points earned email: open findings from the 2026-10-04 review

**Purpose:** the findings from the 2026-10-04 code review of `paul/feat/email-points-earned` that are still open, kept for later. For each one: how the code behaves today, why that is a problem, and a suggested fix. The other findings were fixed in `6f74844` and `trace/fix/points-earned-email-review`, and are not listed here.

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
  - the relay (`Emails::relay_award()`) passing only the row id, and `PointsEarnedEmail::trigger()` skipping an award that was clawed back.
- **Suggested fix:** restore both fixtures and the email tests from history: `WooCommerceEmailsFixture.php` from `2054e97^`, and `WooCommerceOrdersFixture.php` from `957cc74`. Turn this one email back on in them, and make `WooCommerceOrdersFixture::detach_yaymail()` put back what it removes. Then add the cap and relay cases.

## The greeting and balance sentences have three copies

- **Today:** "Hi %s," and "Your balance is now %s." are written out in `templates/emails/yayloyalty-points-earned.php`, in `templates/emails/plain/yayloyalty-points-earned.php`, and in `PointsEarnedYayMailEmail::get_default_elements()`.
- **Why it is a problem:** a wording change made in one copy leaves the others behind, and each copy's msgid must match for translations to apply.
- **Suggested fix:** build both sentences in `PointsEarnedEmail::build_values()`, as `earned_text` is built, and print them from the templates. This adds template variables, which are a public contract, so it is left to the feature's owner.
