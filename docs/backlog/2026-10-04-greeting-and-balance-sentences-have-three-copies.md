# The points earned email's greeting and balance sentences have three copies

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the 2026-10-04 code review of `paul/feat/email-points-earned`.

## Today

"Hi %s," and "Your balance is now %s." are written out in `includes/Engine/Emails/templates/emails/yayloyalty-points-earned.php`, in `includes/Engine/Emails/templates/emails/plain/yayloyalty-points-earned.php`, and in `PointsEarnedYayMailEmail::get_default_elements()`.

## Why it matters

A wording change made in one copy leaves the others behind, and each copy's msgid must match for translations to apply.

## Suggested fix

Build both sentences in `PointsEarnedEmail::build_values()`, as `earned_text` is built, and print them from the templates. This adds template variables, which are a public contract, so it is left to the feature's owner.

## Done when

- [ ] `PointsEarnedEmail::build_values()` returns the greeting and the balance sentence as values.
- [ ] Both email templates and `PointsEarnedYayMailEmail::get_default_elements()` print those values, and `git grep -n "Your balance is now"` finds only `PointsEarnedEmail`.
