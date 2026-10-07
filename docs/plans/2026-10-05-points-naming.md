# Points Naming Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Read the spec linked below before starting; the plan argues from it.

**Status:** active
**Goal:** merchants rename the loyalty currency on Settings → Advanced. The new name appears wherever a customer sees a quantity, and everywhere in the three free emails. Admin screens keep "points".
**Spec:** [docs/specs/2026-10-05-points-naming-design.md](../specs/2026-10-05-points-naming-design.md)
**Architecture:** reuse the existing `advanced.points_label_*` settings. The Advanced page owns one form that saves only dirty fields, and the Pro fraud form becomes a section inside it. `LoyaltyEmail` adds a `{points_label}` placeholder (plural unless the email set a count) for subjects and headings, and fixed email sentences use `sprintf` with `SettingsHelper::points_label_plural()`. Storefront quantities already go through `points_text()`, so no storefront code changes.
**Touches:** `includes/Helpers/SettingsHelper.php` (shared: storefront, emails, YayMail, REST), `includes/Engine/Emails/*` (LoyaltyEmail is shared: Pro emails extend it), `apps/admin/src/pages/settings/*`, `apps/admin/src/pro/settings/*`
**Tests:**
- `tests/integration/Settings/SettingsHelperPointsLabelTest.php` (integration): trim, 30-character cap, empty value ignored, a partial save doesn't store labels.
- `tests/integration/Emails/PointsLabelPlaceholderTest.php` (integration): the placeholder gives the plural, and PointsEarned keeps the singular for 1.
- `apps/admin/tests/advanced-settings.form.test.ts` (Vitest): the save payload has only dirty fields, and the label schema rejects bad values.

## Implementation notes (2026-10-05)

- The Vitest file lives in `apps/admin/tests/`, because `vitest.config.ts` includes only `tests/**/*.test.ts`.
- `AdvancedPointsNamingCard` and `AdvancedFraudLimitsSection` read the form through `useFormContext` (the page wraps it in `FormProvider`), matching `EmailsThrottleCard`, instead of taking a `control` prop.
- `LoyaltyEmail::format_string()` sets the plural only for the duration of the call and then clears it, so a later count-based value set by `trigger()` is never masked.
- The integration tests extend `TestCase` and snapshot and restore `yayloyalty_settings`, because the options table is the site's own, not a scratch copy.

## Global constraints (verbatim from spec / CLAUDE.md)
- Product PHP runs on PHP 7.4. Not allowed: enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union types.
- Lite names Pro only in `YayLoyaltyPro::boot()`. SPA Pro code lives in `apps/admin/src/pro/`, is imported only via `@/pro`, and renders only behind `IS_PRO_BUILD`.
- Every sentence that takes the label is a `__()` with `%s` and a `/* translators: */` comment. Never concatenate the label in.
- Output of the label is escaped (`esc_html`/`esc_attr`).
- The label is used as entered. Defaults stay `Point` / `Points`.
- Labels are trimmed, required, and at most 30 characters.
- `{points_label}` matches the line's number when the line has one, and is the plural otherwise.
- Scope: admin screens keep "points". Storefront text without a quantity, Design storefront defaults and Pro emails are out of scope.
- Files stay under 200 lines.
- Do not hand-edit `vendor/`, `assets/dist/`, `release/`.
- Branch: current `paul/feat/setting-advanced-fraud-limit-max-points-per-customer-per-day` (or a new branch from `dev/edd` if you prefer).
- Conventional Commits with a `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` trailer. Commit only when asked.

## Baseline (already failing on `dev/edd`)
| Check | Status on `dev/edd` | Expected after this plan |
|---|---|---|
| `composer lint` | exits 1 on warnings only | no new warnings in the files we touch |
| Admin `lint` | fails | no new errors in the files we touch |
| `tsc --noEmit` | fails with TS2307 for emoji-mart / progress only | no other errors |
| Vitest | fails because there are no tests | passes once Task 2's test exists |

`composer test:integration` exiting 0 doesn't prove it ran: read the test count in its output.

---

## Task 1: SettingsHelper label rules
**Files:** `includes/Helpers/SettingsHelper.php`, `tests/integration/Settings/SettingsHelperPointsLabelTest.php` (new)

1. In `schema()`, change both label fields to type `'points_label'`.
2. In `coerce()`, add `case 'points_label'`. It returns `mb_substr( trim( sanitize_text_field( (string) $value ) ), 0, 30 )`.
3. In `apply_schema()`, skip a field whose type is `'points_label'` when the coerced value is `''`. This applies to both read and write:
   - On read, the translated default stands.
   - On write, the stored value is kept.
4. Add `public static function points_label_plural(): string`. It returns `self::get_settings()['advanced']['points_label_plural']`.
5. Add the test `SettingsHelperPointsLabelTest extends IntegrationTestCase`. Each case deletes `yayloyalty_settings` in `setUp`.
   ```php
   update_settings(['advanced'=>['points_label_singular'=>'  Star  ','points_label_plural'=>str_repeat('x',40)]]);
   assertSame('Star', get_settings()['advanced']['points_label_singular']);
   assertSame(30, mb_strlen(get_settings()['advanced']['points_label_plural']));
   update_settings(['advanced'=>['points_label_plural'=>'   ']]);   // ignored
   assertSame(str_repeat('x',30), get_settings()['advanced']['points_label_plural']);
   delete_option(...); update_settings(['advanced'=>['max_points_per_day'=>5]]);
   assertArrayNotHasKey('points_label_plural', get_option('yayloyalty_settings')['advanced']);
   assertSame(__('Points','yayloyalty'), SettingsHelper::points_label_plural());
   ```
6. Verify: `composer test:integration -- --filter SettingsHelperPointsLabelTest`. The output shows `OK (n tests…)` with n ≥ 1.
7. Commit both files: `feat(settings): validate points naming labels`.

**Interfaces:** `SettingsHelper::points_label_plural(): string`. The existing `points_label( int )` and `points_text( int )` are unchanged.

## Task 2: Advanced tab, one form with the Points naming card
**Files:**
- `apps/admin/src/pages/settings/advanced-settings.form.ts` (new)
- `apps/admin/tests/advanced-settings.form.test.ts` (new)
- `apps/admin/src/pages/settings/AdvancedPointsNamingCard.tsx` (new)
- `apps/admin/src/pages/settings/AdvancedSettingsPage.tsx`
- `apps/admin/src/pages/settings/advanced-settings.copy.ts`
- `apps/admin/src/pro/settings/AdvancedFraudLimitsForm.tsx` → rename to `AdvancedFraudLimitsSection.tsx`
- `apps/admin/src/pro/index.ts`

1. Write `advanced-settings.form.ts`:
   - `advancedSettingsFormSchema`: a z.object with these fields:
     - `advanced`: `settingsSchema.shape.advanced.pick({ max_points_per_day: true })` extended with `points_label_singular` and `points_label_plural`, each `z.string().trim().min(1).max(30)`.
   - `type AdvancedSettingsFormValues`.
   - `advancedSettingsDefaults(settings: Settings): AdvancedSettingsFormValues`.
   - `buildAdvancedSettingsUpdate(values: AdvancedSettingsFormValues, dirty: FieldNamesMarkedBoolean<AdvancedSettingsFormValues>): SettingsUpdate`. It includes only the dirty fields, and omits a group with no dirty fields.
2. Write the Vitest file `advanced-settings.form.test.ts`:
   - Only `advanced.max_points_per_day` dirty → `{ advanced: { max_points_per_day: 5 } }`, with no label key.
   - Only the plural dirty → `{ advanced: { points_label_plural: 'Stars' } }`.
   - Nothing dirty → `{}`.
   - The schema rejects `'   '` and a 31-character string.
3. Write `AdvancedPointsNamingCard({ control }: { control: Control<AdvancedSettingsFormValues> })`:
   - A Card titled "Points naming" with Singular and Plural `Input`s and inline errors.
   - The "Storefront reads" line comes from `useWatch`:
     - `You have %1$s %2$s`, filled with `formatPoints(9850)` and the plural;
     - `Earn 1 %1$s per %2$s`, filled with the singular and `parseWPCurrency(1)`;
     - `Redeem %1$s %2$s for %3$s off`, filled with `formatPoints(500)`, the plural and `parseWPCurrency(5)`.
   - Helper text: "Used everywhere the customer sees a quantity, including the three emails. Admin screens keep the word “points”."
   - Strings go in `advanced-settings.copy.ts` as functions.
4. Convert the Pro fraud form into `AdvancedFraudLimitsSection({ control })`:
   - It keeps the Card, the label, the `NumberInput` bound to `advanced.max_points_per_day`, and the help text.
   - Remove its `useForm`, Save, toast and `UnsavedChangeDialog`.
   - Re-export it from `apps/admin/src/pro/index.ts` in place of `AdvancedFraudLimitsForm`.
5. Rewrite `AdvancedSettingsPage`. It owns:
   - `useForm({ resolver: zodResolver(advancedSettingsFormSchema), values: advancedSettingsDefaults(data), resetOptions: { keepDirtyValues: true } })`;
   - `useRouteLeaveGuard`, `UnsavedChangeDialog`, and one "Save changes" button that is disabled when the form isn't dirty or a save is pending.
   - On submit, it posts `buildAdvancedSettingsUpdate(values, dirtyFields)`, then calls `form.reset(advancedSettingsDefaults(saved), { keepDirtyValues: false })` and shows the existing toasts.
   - Layout, top to bottom: `AdvancedPointsNamingCard`, then `IS_PRO_BUILD ? <AdvancedFraudLimitsSection control/> : <the existing upsell Card>`, then Save.
   - Lite submits only the two label fields. `max_points_per_day` stays at its loaded state and is never dirty.
6. Verify:
   - `pnpm --filter @yayloyalty/admin-page test`: the new file passes.
   - `node node_modules/typescript/bin/tsc --noEmit` from `apps/admin`: only the baseline TS2307 errors.
   - In the browser with `./scripts/run.sh dev pro`, open Advanced:
     - the card is prefilled with `Point` / `Points`;
     - the line updates as you type;
     - clearing a field shows an error and disables Save;
     - saving `Star` / `Stars` and reloading keeps them;
     - changing only the fraud limit and saving adds no label key under `advanced` (check with `wp option get yayloyalty_settings --format=json`).
   - Repeat with `./scripts/run.sh dev lite`: the naming card and the upsell card show, and Save works.
   - `git status`: revert any `composer.json` or `scoper.inc.php` rewritten by the dev sync.
7. Commit the files listed above: `feat(settings): add points naming to the Advanced tab`.

## Task 3: The three free emails follow the label
**Files:**
- `includes/Engine/Emails/LoyaltyEmail.php`
- `includes/Engine/Emails/PointsEarnedEmail.php`, `includes/Engine/Emails/PointsExpiredEmail.php`, `includes/Engine/Emails/PointsExpiringSoonEmail.php`
- the HTML and plain templates under `includes/Engine/Emails/templates/emails/`
- `tests/integration/Emails/PointsLabelPlaceholderTest.php` (new)

1. In `LoyaltyEmail`, override `public function format_string( $string )`. When `$this->placeholders['{points_label}']` is unset or `''`, set it to `SettingsHelper::points_label_plural()`, then return `parent::format_string( $string )`. Because it runs at send time, the label resolves in the customer's locale. PointsEarnedEmail's per-count value wins once its trigger has set it. Pro emails inherit this, but their defaults are unchanged.
2. Change the default subjects and headings:
   - PointsEarned heading → `'You earned {points_label}'`
   - Expired subject → `'[{site_title}]: Some of your {points_label} have expired'`
   - Expired heading → `'Your {points_label} have expired'`
   - ExpiringSoon subject → `'[{site_title}]: Your {points_label} expire soon'`
   - ExpiringSoon heading → `'Your {points_label} expire soon'`

   Titles and descriptions stay unchanged, because they are for the merchant.
3. Change the template bodies, both HTML and plain, using `sprintf( esc_html__( '… %s …' ), esc_html( SettingsHelper::points_label_plural() ) )`:
   - `'Thanks for being part of our loyalty program. New %s have been added to your account.'`
   - `'View your %s'` (HTML CTA) and `'View your %s:'` (plain)
   - `'Some of your loyalty %s will expire soon. Use them on your next order before they are gone.'`
   - `'Some of your loyalty %s have expired and are no longer in your balance.'`
4. Write the test `PointsLabelPlaceholderTest extends IntegrationTestCase`:
   ```php
   SettingsHelper::update_settings(['advanced'=>['points_label_singular'=>'Star','points_label_plural'=>'Stars']]);
   WC()->mailer();                                   // load WC_Email first
   $soon = new PointsExpiringSoonEmail();
   assertSame('Your Stars expire soon', $soon->format_string($soon->get_default_heading()));
   $earned = new PointsEarnedEmail();
   $earned->placeholders['{points}'] = '1';
   $earned->placeholders['{points_label}'] = SettingsHelper::points_label(1);   // what trigger() sets
   assertStringContainsString('You earned 1 Star', $earned->format_string($earned->get_default_subject()));
   ```
   Build each email only after `WC()->mailer()` exists, as memory [wc-email-not-autoloadable-at-init] warns.
5. Verify:
   - `composer test:integration -- --filter PointsLabelPlaceholderTest`: OK, with n ≥ 2.
   - In WooCommerce → Settings → Emails → Points expiring soon → preview: the subject placeholder and the body show `Stars`.
6. Commit: `feat(emails): use the points label in loyalty emails`.

## Task 4: End-to-end verification (spec AC 8)
1. `./scripts/run.sh dev pro`.
2. Settings → Advanced: set `Star` / `Stars`, Save, reload. The values persist.
3. On the storefront, check every quantity:
   - the launcher shows `Earn 1 Star per $1 spent` and the balance's screen-reader label says `Stars`;
   - the guest panel says `Create an account and get 50 Stars` and the product estimate says `… Stars`;
   - the activity titles and the balance heading still say "points" (out of scope).
   If any quantity still shows "points", route it through `SettingsHelper::points_text()`.
4. Place an order. The Points earned email says `You earned 50 Stars` (subject) and `View your Stars` (CTA).
5. In the admin, Dashboard, Customers and the Advanced field labels still say "points".
6. A store with an already-saved WC subject still shows its saved text (AC 6).
7. `composer test:integration`: all new tests pass, and the test count is shown.
8. `pnpm --filter @yayloyalty/admin-page test`: passes.
9. Repeat step 2 with `./scripts/run.sh dev lite`.

## Review focus
- Saving only the fraud limit must not store translated default labels (Task 2 dirty payload; Task 1 test).
- The `{points_label}` placeholder must not override PointsEarnedEmail's per-count value (Task 3 test).
- The label must be escaped everywhere it is output. It is merchant input placed inside `esc_html__` output.
- No Pro code may leak into Lite (`AdvancedFraudLimitsSection` only via `@/pro`, behind `IS_PRO_BUILD`).
