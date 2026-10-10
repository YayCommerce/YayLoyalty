# The points earned email's rendering has no tests

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the 2026-10-04 code review of `paul/feat/email-points-earned`, extended by the 2026-10-05 `/code-review` of `6f74844`.

## Today

`tests/integration/Emails/PointsEarnedEmailTest.php` covers the send path's risks: a throwing mailer, one send per award, a deferred send after a refund, and the weekly cap (`EmailThrottle`). It runs on `tests/integration/WooCommerceOrdersFixture.php`, whose `detach_yaymail()` removes only YayMail's own callbacks and puts them back.

Nothing covers what the email renders. `f4290fe` deleted the old rendering tests, and the team chose to bring back only the send-path risks.

## Why it matters

A regression in any of these ships unseen:

- apostrophes and other entities in plain text (`LoyaltyEmail::get_content_plain()`);
- the value line hidden by default;
- the preview sample (`PointsEarnedEmail::prepare_preview()`).

## Suggested fix

Add the rendering cases to `PointsEarnedEmailTest`, taking them from `f4290fe^:tests/integration/Emails/PointsEarnedEmailTest.php`, if a rendering bug makes them worth their upkeep.

## Done when

- [x] `tests/integration/WooCommerceOrdersFixture.php` exists, and `WooCommerceOrdersFixture::detach_yaymail()` removes only YayMail's callbacks, leaving `PointsEarnedShortcodes::hold_subject_email()` and `release_subject_email()` hooked, and restores what it removes. — `WooCommerceOrdersFixture::detach_yaymail()`, `reattach_yaymail()`
- [x] An integration test covers a throwing mailer and a re-fired completion. — `PointsEarnedEmailTest::test_a_throwing_mailer_leaves_the_award_intact()`, `test_a_refired_completion_sends_no_second_email()`
- [x] An integration test covers the weekly cap: the send at the cap is skipped, a failed send isn't counted, and a send after 7 days passes. — `PointsEarnedEmailTest::test_the_weekly_cap_skips_the_send_at_the_cap()`
- [x] An integration test covers the relay: `Emails::relay_award()` passes only the row id, and `LedgerEntryEmail::trigger()` skips an award clawed back before a deferred send runs. — `PointsEarnedEmailTest::test_a_deferred_send_skips_an_award_refunded_first()`
- [ ] An integration test covers each case listed under Why it matters.
- [ ] `composer test:integration` runs those tests and they pass (check the test count, not only the exit code).
