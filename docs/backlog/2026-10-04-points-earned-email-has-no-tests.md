# The points earned email's send path has no tests

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the 2026-10-04 code review of `paul/feat/email-points-earned`, extended by the 2026-10-05 `/code-review` of `6f74844`.

## Today

`f4290fe` deleted the feature's integration tests, `tests/integration/Emails/PointsEarnedEmailTest.php` (21 tests) and `YayMailPointsEarnedTest.php`, and the shared `tests/integration/WooCommerceEmailsFixture.php`. The launcher's review fixes then deleted `tests/integration/WooCommerceOrdersFixture.php`, which no test used any more.

## Why it matters

Nothing exercises the send path. A regression in any of these ships unseen:

- one send per award, never a second for a duplicate;
- the award kept when the mailer throws;
- apostrophes and other entities in plain text (`LoyaltyEmail::get_content_plain()`);
- the value line hidden by default;
- the preview sample;
- the weekly cap (`EmailThrottle`): the send at the cap is skipped, a failed send isn't counted, and a send after 7 days passes;
- the relay (`Emails::relay_award()`) passing only the row id, and `LedgerEntryEmail::trigger()`, which `PointsEarnedEmail` inherits, skipping an award that was clawed back. On WooCommerce 10.8+ with deferred emails, the send runs later from Action Scheduler, which is when a refund can land first. The 2026-10-05 hand-run check covered only immediate sending.

## Suggested fix

Restore both fixtures and the email tests from history: `WooCommerceEmailsFixture.php` from `2054e97^`, and `WooCommerceOrdersFixture.php` from `957cc74`. Turn this one email back on in them, and make `WooCommerceOrdersFixture::detach_yaymail()` put back what it removes. Then add the cap and relay cases.

The old `detach_yaymail()` clears the subject hook with `remove_all_filters( 'woocommerce_email_subject_' . PointsEarnedEmail::ID )`. Since `6f74844`, that hook also carries `PointsEarnedShortcodes::hold_subject_email()` and `release_subject_email()`, so restored unchanged, the fixture would remove them too. Remove only YayMail's own callback there.

## Done when

- [ ] `tests/integration/WooCommerceEmailsFixture.php` and `tests/integration/WooCommerceOrdersFixture.php` exist, and `WooCommerceOrdersFixture::detach_yaymail()` removes only YayMail's callbacks, leaving `PointsEarnedShortcodes::hold_subject_email()` and `release_subject_email()` hooked, and restores what it removes.
- [ ] An integration test covers each case listed under Why it matters, up to and including the preview sample.
- [ ] An integration test covers the weekly cap: the send at the cap is skipped, a failed send isn't counted, and a send after 7 days passes.
- [ ] An integration test covers the relay: `Emails::relay_award()` passes only the row id, and `LedgerEntryEmail::trigger()` skips an award clawed back before a deferred send runs.
- [ ] `composer test:integration` runs those tests and they pass (check the test count, not only the exit code).
