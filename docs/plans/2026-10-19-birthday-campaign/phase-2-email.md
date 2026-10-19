# Phase 2: Birthday email

**Plan:** `README.md` · **Depends on:** Phase 1 Task 4 · **Delivers:** a birthday award emails "Happy birthday" with points and balance instead of Points earned.
**Exit criterion:** `composer test:integration -- --filter BirthdayPointsEmailTest` prints `OK`; Acceptance 7 email line holds.

### Task 1: Send the Birthday points email

**Files:**
- Modify: `includes/Pro/Emails/BirthdayPointsEmail.php` (extend `LedgerEntryEmail`; `templates_dir()` returns `__DIR__ . '/templates/'`; listen on `Emails::POINTS_EARNED_ACTION . '_notification'`; `accepts()` = source `birthday` and points > 0; `amounts()`/`build_values()` like `PointsEarnedEmail`; placeholders `{points}`, `{points_label}`, `{balance}`; `prepare_preview()`; drop "Nothing triggers it yet")
- Modify: `includes/Pro/Emails/templates/emails/yayloyalty-birthday-points.php`, `…/plain/yayloyalty-birthday-points.php` (greeting, "To celebrate your birthday, we added %s to your account.", balance sentence)
- Modify: `includes/Engine/Emails/PointsEarnedEmail.php` (`accepts()` rejects `LedgerSource::BIRTHDAY`)
- Test: `tests/integration/Emails/BirthdayPointsEmailTest.php`

**Interfaces:** see README contract (email rows).

Test assertions: after a birthday award, the mailer captured one email whose id is `yayloyalty_birthday_points` containing the formatted points, and none with id `yayloyalty_points_earned`; with the Birthday email disabled, no email at all. Follow `PointsEarnedEmailTest`'s capture fixture.

Steps: expand after Phase 1 lands. Manual check to include: WooCommerce → Settings → Emails → Birthday points preview renders points and balance.
