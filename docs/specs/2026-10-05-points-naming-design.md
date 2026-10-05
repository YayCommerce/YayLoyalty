# Points Naming Design

**Status:** accepted 2026-10-05
**Related:** design file `YayLoyalty Settings.dc.html` → Settings → Advanced (Free and Pro), "Points naming" card

## Goal

A merchant renames the loyalty currency (singular and plural, e.g. "Star" / "Stars") on Settings → Advanced. The new name appears wherever a customer sees a quantity of points (launcher, storefront panels) and everywhere in the three free emails (Points earned, Points expired, Points expiring soon). Admin screens keep the word "points".

## Context

- Storage already exists. `general.points_label_singular` and `general.points_label_plural` are in `includes/Helpers/SettingsHelper.php`. Their defaults are the translated `__( 'Point' )` / `__( 'Points' )`, which are never written to the option until a merchant saves them.
- `SettingsHelper::points_label( int )` returns singular at exactly 1 and plural otherwise. `points_text( int )` returns "50 Points".
- These already use the label: the launcher (`templates/launcher.php`), the amounts in `panel-guest.php` / `panel-member.php`, `LauncherCopy::earn_rate()` / `redeem_rate()` (Design token `{points_label}`), and `PointsEarnedEmail` (the `{points_label}` placeholder and `points_text`).
- The SPA mirror `apps/admin/src/lib/schema/settings.schema.ts` already has both fields. `useSaveSettingsMutation` posts a partial `SettingsUpdate`, and PHP writes only the fields it receives.
- Advanced tab today (`apps/admin/src/pages/settings/AdvancedSettingsPage.tsx`):
  - Pro renders `@/pro` `AdvancedFraudLimitsForm`, which owns its own `useForm` and Save.
  - Lite renders an upsell card.
- Every storefront string that shows a quantity already goes through `points_text()` / `points_label()`. What still says "points" directly in the three free emails:
  - subjects and headings: `PointsExpiredEmail`, `PointsExpiringSoonEmail`, and the `PointsEarnedEmail` heading
  - body and CTA text (HTML and plain) under `includes/Engine/Emails/templates/emails/`

## Scope

**In:**
- A "Points naming" card on Settings → Advanced, in both Lite and Pro, above the fraud limits. It has Singular and Plural inputs and a live "Storefront reads" line.
- One "Save changes" for the whole tab. It sends only the fields that changed.
- Validation in both the SPA and PHP: each label is trimmed, required, and at most 30 characters.
- Every "points" in the three free emails takes the label: default subjects and headings through a `{points_label}` placeholder, and body and CTA sentences through `sprintf`.
- A check that every storefront quantity uses `points_text()` / `points_label()`. They already do, so no code change is expected.

**Out:**
- Admin screens: all admin SPA wording keeps "points", as the design says.
- Storefront text without a quantity: activity row titles ("Points expired", "Order earned points"), the guest blurb, the member empty state.
- Design tab storefront_text defaults ("Your points", "Apply points", …) and a `{points_label}` token for them. The merchant can already edit these.
- Pro emails (Birthday points, Final reminder, Reward claimed).
- WooCommerce email titles and descriptions in WC → Settings → Emails, order notes, and YayMail shortcode descriptions. All of these are merchant-facing.
- Changing the defaults' case (they stay `Point` / `Points`).
- Lowercasing the label inside sentences. It is inserted as entered.
- Rewriting text a merchant has already saved (Design storefront text, WC email subject and heading options).
- The rest of the designed Advanced tab: uninstall toggle, debug logging, status panel, permissions, currency, performance, API and webhooks. The General tab placeholder is also out.

## Acceptance criteria

1. Both Lite and Pro builds show the Points naming card on Settings → Advanced, prefilled with the current labels (default `Point` / `Points`).
2. The "Storefront reads" line updates as the merchant types. It shows `You have 9,850 {plural}` · `Earn 1 {singular} per {currency 1}` · `Redeem 500 {plural} for {currency 5} off`, with money formatted by `parseWPCurrency`.
3. Save is blocked when a label is empty or only whitespace, or longer than 30 characters, and an inline error shows. A REST post that bypasses this has the label trimmed and cut to 30 characters. An empty value is ignored, so the stored or default label stays.
4. If only the fraud limit changes in Pro, the saved option gains `advanced.max_points_per_day` only. It does not gain `general.points_label_*` keys, so translated defaults keep following the site language. The reverse also holds.
5. With the labels set to `Star` / `Stars` and saved:
   - Launcher: `Earn 1 Star per $1 spent` and `Create an account and get 50 Stars`.
   - Member panel amounts and the guest product estimate: `50 Stars`. Activity titles and the balance heading keep "points".
   - Points earned email: subject `You earned 50 Stars`, heading `You earned Stars`, CTA `View your Stars`.
   - Expired and expiring-soon emails: subject, heading and body say `Stars`.
   - Admin screens (Dashboard, Customers, Advanced field labels) still say "points".
6. A store that has already saved a WC email subject or heading sees that saved text unchanged.
7. The Design tab launcher preview shows the saved label, because it is rendered on the server.
8. End-to-end verification:
   1. `./scripts/run.sh dev pro`.
   2. Settings → Advanced: set `Star` / `Stars`, Save, reload. The values persist.
   3. Open the storefront as a member and check the launcher and activity rows.
   4. Place an order and check the Points earned email.
   5. Run `composer test:integration`; the new tests pass and the test count is shown.
   6. Repeat step 2 with `./scripts/run.sh dev lite`.

## Constraints

- PHP 7.4: no PHP 8 syntax. Use `mb_substr` for the 30-character cap.
- Lite and Pro split:
  - The naming card lives in `apps/admin/src/pages/settings/` (free code).
  - The fraud section stays in `apps/admin/src/pro/`, is imported only through `@/pro`, and renders only behind `IS_PRO_BUILD`.
- Strings: every new sentence that takes the label is a `__()` with `%s` / `%1$s` and a `/* translators: */` comment. The label is never concatenated.
- Escaping: the label is merchant input, so templates escape it on output (`esc_html` / `esc_attr`).
- `{points_label}` matches the line's number when the line has one, and is the plural otherwise.
- Files stay under 200 lines.

## Approach

**Chosen:** extend the existing settings field and helpers. Do not add new storage.

**Architecture:**
- The Advanced page owns one react-hook-form form covering `general.points_label_singular`, `general.points_label_plural` and, in Pro, `advanced.max_points_per_day`.
- On submit it builds a `SettingsUpdate` from `dirtyFields` only.
- The Pro fraud form turns into a presentational section that receives the form `control`.
- On the PHP side, `LoyaltyEmail` supplies a `{points_label}` placeholder (plural unless the email set a count-based one) for subjects and headings. Fixed email sentences take `SettingsHelper::points_label_plural()` through `sprintf`.

**Components:**
- `apps/admin/src/pages/settings/AdvancedSettingsPage.tsx`: owns the form, Save, the `UnsavedChangeDialog` and route-leave guard, and the Lite upsell card.
- `apps/admin/src/pages/settings/advanced-settings.form.ts` (new): the form schema, default values from settings, and the dirty-fields → `SettingsUpdate` builder.
- `apps/admin/src/pages/settings/AdvancedPointsNamingCard.tsx` (new): two inputs and the "Storefront reads" line.
- `apps/admin/src/pro/settings/AdvancedFraudLimitsSection.tsx` (renamed from `AdvancedFraudLimitsForm.tsx`): the fraud field only, with no form and no Save.
- `includes/Helpers/SettingsHelper.php`: a `points_label` coerce type (trim, `sanitize_text_field`, 30-character cap; an empty value is skipped) and `points_label_plural(): string`.
- `includes/Engine/Emails/LoyaltyEmail.php`: `format_string()` sets the plural `{points_label}` when no subclass set one.
- `PointsEarnedEmail`, `PointsExpiredEmail`, `PointsExpiringSoonEmail` and their HTML and plain templates: use the label.

**Data and storage:**
- Existing option `yayloyalty_settings` → `general.points_label_singular` / `general.points_label_plural`.
- No migration and no schema version bump.
- Uninstall is unchanged.

**Integration points:**
- REST `POST settings` (existing).
- WooCommerce `WC_Email::format_string()` placeholders.
- The YayMail `points_label` / `balance_points` shortcodes already read `SettingsHelper` and need no change.

**UI:**
- shadcn `Card`, `Input` and `Label` in the existing Settings layout. Helper text under the card: "Used everywhere the customer sees a quantity, including the three emails. Admin screens keep the word “points”."
- The naming card comes first. Then Pro fraud limits, or the Lite upsell card. Then a single right-aligned "Save changes".

**Edge cases and failure modes:**
- Empty or whitespace input: the SPA blocks it, PHP ignores it, and the default applies.
- Input over 30 characters: the SPA blocks it and PHP truncates it.
- HTML in a label: stripped by `sanitize_text_field`, and output is escaped.
- A locale switch while sending an email: unsaved defaults resolve at send time in the customer's locale.
- A singular label equal to the plural label is allowed.
- A theme copy of an email template keeps its own wording.

**Decision record:** None. This follows the existing settings pattern.

## Alternatives considered

| Option | Why rejected |
|---|---|
| A separate Save per card | Two Save buttons on one tab, which differs from the design. |
| Lowercase defaults | Kept `Point` / `Points` so existing storefronts don't change. |
| Lowercasing the label inside sentences | Breaks proper names and German nouns. |
| Label in all customer text (activity titles, Design defaults, Pro emails) | Narrowed on 2026-10-05: the user chose quantities plus the three free emails, matching the design's helper text. |
| Following the label across all admin text | ~124 strings, and the design keeps "points" in admin. |

## Rollout

- Existing installs keep `Point` / `Points` until they save.
- Changing the msgids of rewritten sentences invalidates any existing translations of those strings. Note this in the changelog.
- Saved WC email subjects and headings are not migrated. The release notes tell merchants they can add `{points_label}`.

## Open questions

None.
