# Changelog style guide

<!-- Purpose: how yayrelease / yayrelease-fix / workers turn `yr changelog collect` output into a
customer changelog. Update when: the team changes its changelog voice or groups. -->

A changelog is read by store owners deciding whether to update, not by developers. Each line
answers: **what is different for me after updating?**

## Groups

Use only these four, in this order. `yr changelog render` prints each with the edition's own prefix
(e.g. `- Added:` / `- Improved:` / `- Fixed:` / `- Security:`, or YayBoost's `* New:` / `* Improvement:` / `* Fix:`).

| group | for |
|---|---|
| `new` | something the user can do now that they could not before |
| `improved` | something that already existed works better, looks better, is faster, or is compatible with a new WordPress/WooCommerce version |
| `fixed` | something that was broken now works |
| `security` | a security hardening (say what is protected, never how to exploit it) |

## Merge, don't list

- One line per **user-visible change**, not per commit. Five commits that build one feature → one `new` line. A fix plus its two follow-up fixes → one `fixed` line.
- Fold a fix of a feature that is new in this same release into the feature's line. Users never saw the bug.
- If two lines describe the same screen or feature, merge them: "Promotion Wheel: dynamic coupons, campaign analytics and order attribution".
- Aim for 3–8 lines. A long list hides the change people care about.

## Write it plainly

- Name the feature as the user sees it in wp-admin or on the store, not the class or file: "Checkout block", not `CheckoutBlockRenderer`.
- Say the effect: "Discounts now show on the cart page when the cart has a single product", not "Fix condition evaluation".
- Present tense, active, no jargon: no "refactor", "hook", "REST", "SPA", "transient", "nonce", ticket numbers, PR numbers or commit hashes.
- Start with the thing, then what changed: "Order emails: the points earned line no longer shows 0 for guest orders".
- No blame, no internal history ("finally", "as requested by", "reverted").
- Compatibility: "Compatibility with WooCommerce 11.3" (group `improved`).
- Support fixes listed in `support_fixes` are customer-facing by definition: include each one (merged with a matching commit line if it is the same fix).
- Keep the repo's existing tone. Read the last two entries in the changelog file before writing.

## Leave out

Everything in `internal_dropped`, plus: tests, CI, build scripts, lint/format, docs, refactors with
no visible effect, dependency bumps with no visible effect, version bumps, and changes to `.claude/`.
When unsure whether something is visible, leave it out and list it under "left out" in the draft
you show the user, so they can add it back.

## Draft format

Write the draft as JSON, then render and show it:

```json
{"version": "3.6.3", "date": "2026-11-04", "entries": [
  {"group": "new", "text": "Free gifts: choose which gift a customer gets when several rules match"},
  {"group": "fixed", "text": "Cart discounts no longer disappear after changing the shipping method"}
]}
```

`yr changelog render --draft=<file>` shows exactly what will be written; `yr changelog insert
--draft=<file> --tree=<release worktree> [--replace-unreleased]` writes it. Show the user the
rendered text and the "left out" list before inserting.

## Example

Commits:

```
feat(gift): pick a gift when several rules match
fix(gift): picker shows twice on mobile           ← follow-up of the new feature: fold in
fix(cart): discount lost after shipping change
Fix phpcs                                          ← internal
refactor(rules): split evaluator                   ← internal
chore: tested up to WC 11.3
```

Changelog:

```
= Nov 4, 2026 - Version 3.6.3 =
- Added: Free gifts: customers choose their gift when several gift rules match
- Improved: Compatibility with WooCommerce 11.3
- Fixed: Cart discounts no longer disappear after changing the shipping method
```
