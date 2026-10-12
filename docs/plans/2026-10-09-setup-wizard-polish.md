# Setup Wizard Polish Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. There is no spec; the Requirements section below is the source.

**Status:** done 2026-10-09
**Branch:** `<name>/feat/wizard-polish` from `dev/edd`; PR into `dev/edd`
**Goal:** trim the setup wizard's helper copy, show the purchase rate on step 2, and animate the progress bar.
**Spec:** none, planned from the request. Follows [the setup wizard plan](2026-10-09-setup-wizard.md) (done).
**Architecture:** SPA-only edits inside `apps/admin/src/components/setup-wizard/`. The step 2 rate line reuses `pointsPerCurrencyLabel()`, so it reads the rate the wizard will save (6 points for 3.00 reads "2 pts per 1.00"), the same label the rest of the admin uses. The animation is a CSS transform transition on each segment, so no animation library is added.
**Touches:** `SetupWizardModal.tsx`, `NamePointsStep.tsx`, `EarnPointsStep.tsx`, `SpendPointsStep.tsx`, `LauncherLookStep.tsx`, `WizardStepParts.tsx`, all in `apps/admin/src/components/setup-wizard/`. No shared code, no PHP.
**Tests:** none. Copy and a CSS transition are neither money math nor a reproduced bug. The rate line calls existing helpers and does no new math.

## Requirements

- **Goal:** the wizard carries less explanatory text, step 2 states its rate, and the progress bar moves visibly between steps.
- **Out of scope:** the `/setup` route, the form shape (`setup-wizard.form.ts`), the review step's rows, step headings and descriptions, and every helper line not listed in criterion 1.
- **Acceptance criteria:**
  1. These strings are gone from the SPA (`git grep` finds none of them under `apps/admin/src`):
     - "Both forms, so nothing reads “1 points”." (step 1)
     - "These three become your campaigns. From tomorrow you edit them on the Campaigns screen, not here." (step 2)
     - "Below this the slider is disabled, so nobody spends 7 points for 7 cents." (step 3)
     - "100% lets a loyal customer pay entirely in points. Lower it if you always want a card payment." (step 3)
     - "At the rate in step 2, a customer earns back %s%% of what they spend." (step 3)
     - "One colour, used for the button and the panel header. Everything else follows your theme." (step 4)
     - "Under 30 minutes from activation" (step 5 footer)
  2. Step 2 shows a "Rate: …" line under the points/spend inputs. With 1 point for every 1.00 it reads "Rate: 1 pt per $1.00" (in the store’s currency format). With 6 points for every 3.00 it reads "Rate: 2 pts per $1.00". When the spend is 0 the line is hidden.
  3. Each of the five progress segments fills left to right over 300 ms when its step is reached, and empties the same way on Back. With the OS "reduce motion" setting on, segments switch instantly.
  4. End to end: a full walk through steps 1→5 and back to 1 shows none of the removed text, the rate line on step 2, and the animated bar; "Turn on my program" still saves.
- **Decided with the user:**
  - Item 3, what "the rate" on step 2 means → a separate line under the inputs ("Rate: 1 point per $1.00"), with the example preview unchanged.
  - Item 5, the animation → a segment fill sweep (~300 ms, left to right, reversed on Back, off under reduced motion).

## Global constraints

- Run from the repo root on Node 22 (`nvm use`). pnpm only. Any pnpm command may run `pnpm install` and `composer install` first.
- `./scripts/run.sh dev` can rewrite `composer.json` and `scoper.inc.php`: check `git status` before committing and stage files by name. `composer.json` is already modified on `dev/edd` in this checkout; leave it out.
- Translatable strings use the `yayloyalty` text domain. Strings with placeholders get `/* translators: */`.
- Number and money formatting goes through `formatPoints` / `formatStoreNumber` / `parseWPCurrency` / `pointsPerCurrencyLabel`, never `toLocaleString()`.
- Files stay under 200 lines. Comments at minimal depth: one-line docblocks, a why only where code would look wrong.
- No Pro code: the wizard is the same in Lite and Pro.
- Known failures on `dev/edd`: admin `lint` (whole repo) and `tsc --noEmit` (TS2307 for `@emoji-mart/*` and `@radix-ui/react-progress`). Expect **no new errors** in the changed files.
- Commits follow Conventional Commits and end with the `Co-Authored-By` trailer from CLAUDE.md. Commit only when the user asks.

## Review focus

- **Dead code after removals.** Removing the step 3 earn-back line leaves `givenBack`, `formatStoreDecimal`, `pointsForSpend` and the `earnPoints`/`earnSpend` watches unused in `SpendPointsStep.tsx`, and removing the step 2 note leaves `WizardNote` with no caller. All must go, or lint fails on the changed files. Checked in Task 1 Step 6.
- **Spend of 0 on step 2.** `rateFromSpend(0, n)` returns `null`; the rate line must hide, not render "Rate: 0 pts" or throw. Checked in Task 2 Step 2.
- **Jump from review.** An Edit link on step 5 jumps to step 2, so three segments empty at once and Continue refills them all at once. Both must animate without a stuck half-filled segment. Checked in Task 3 Step 2.

---

### Task 1: Remove the helper copy

**Files:**
- Modify: `apps/admin/src/components/setup-wizard/NamePointsStep.tsx`
- Modify: `apps/admin/src/components/setup-wizard/EarnPointsStep.tsx`
- Modify: `apps/admin/src/components/setup-wizard/SpendPointsStep.tsx`
- Modify: `apps/admin/src/components/setup-wizard/LauncherLookStep.tsx`
- Modify: `apps/admin/src/components/setup-wizard/SetupWizardModal.tsx`
- Modify: `apps/admin/src/components/setup-wizard/WizardStepParts.tsx`

**Interfaces:**
- Produces: `WizardStepParts.tsx` no longer exports `WizardNote`.

- [ ] **Step 1: `NamePointsStep.tsx`.** Delete the "Both forms…" `<span>` after the two label inputs. Change the docblock to `/** Step 1: what the points are called. */`.

- [ ] **Step 2: `EarnPointsStep.tsx`.** Delete the `<WizardNote>` block ("These three become your campaigns…") and drop `WizardNote` from the `./WizardStepParts` import.

- [ ] **Step 3: `WizardStepParts.tsx`.** Delete the `WizardNote` export; it has no caller left.

- [ ] **Step 4: `SpendPointsStep.tsx`.** Delete the three helper lines: the earn-back `sprintf` line under "What are points worth?", "Below this the slider is disabled…" and "100% lets a loyal customer…". Then delete what only they used: `givenBack` and its comment, `earnPoints`/`earnSpend` from the `useWatch` names, `formatStoreDecimal` from the import, and `pointsForSpend` from the `./setup-wizard.form` import.

- [ ] **Step 5: `LauncherLookStep.tsx` and `SetupWizardModal.tsx`.** In step 4, delete the "One colour, used for the button…" line under the colour picker. In the modal footer, delete the "Under 30 minutes from activation" `<span>`; the step 5 branch becomes the bare `<LoadingButton>` with no fragment.

- [ ] **Step 6: Verify**

Run: `git grep -nE "1 points”|These three become|7 cents|pay entirely in points|earns back|One colour, used|Under 30 minutes|WizardNote" -- apps/admin/src`
Expected: no output.

Run: `pnpm --filter @yayloyalty/admin-page exec eslint src/components/setup-wizard`
Expected: no errors.

Run: `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit 2>&1 | grep setup-wizard`
Expected: no output (the baseline TS2307 errors elsewhere remain).

- [ ] **Step 7: Commit**

Stage: the six files above.
Message: `refactor(admin): trim setup wizard helper copy`

### Task 2: Show the rate on step 2

**Files:**
- Modify: `apps/admin/src/components/setup-wizard/EarnPointsStep.tsx`

**Interfaces:**
- Consumes: `rateFromSpend(spend: number, points: number): number | null` and `pointsPerCurrencyLabel(rate: number): string` from `@/lib/helpers/points.helper`.

- [ ] **Step 1: Add the rate line**

In `EarnPointsStep`, compute `const rate = rateFromSpend(earnSpend, earnPoints);`. Directly under the points/spend input row, above "Calculated on the order subtotal…", render when `rate !== null`:

```tsx
<div className="text-sm font-medium">
  {/* translators: %s: the purchase rate, e.g. "2 pts per $1.00". */}
  {sprintf(__('Rate: %s', 'yayloyalty'), pointsPerCurrencyLabel(rate))}
</div>
```

- [ ] **Step 2: Verify in the admin**

Reopen the wizard: from `/Users/shrief/Local-Sites/yaydevs/app/public`, run `wp eval 'delete_option("yayloyalty_setup_completed");'` through the site socket (see the wp-cli memory), with `./scripts/run.sh dev` running.
Open: `http://yaydevs.local/wp-admin/admin.php?page=yayloyalty`, Continue to step 2.
Action and expected:
- 1 for every 1.00 → "Rate: 1 pt per $1.00".
- 6 for every 3.00 → "Rate: 2 pts per $1.00"; the preview still reads "A $50.00 order earns 100 points".
- Spend 0 → the rate line disappears and the page does not crash.

Then run the Task 1 Step 6 eslint and tsc commands; expected the same results.

- [ ] **Step 3: Commit**

Stage: `apps/admin/src/components/setup-wizard/EarnPointsStep.tsx`
Message: `feat(admin): show the purchase rate on setup wizard step 2`

### Task 3: Animate the progress bar

**Files:**
- Modify: `apps/admin/src/components/setup-wizard/SetupWizardModal.tsx` (the progress bar `div` under the header)

- [ ] **Step 1: Make each segment a track with a fill**

Each segment becomes a `bg-border h-1 flex-1 overflow-hidden` track holding a `bg-primary h-full origin-left` fill, with `transition-transform duration-300 ease-out motion-reduce:transition-none` and `scale-x-100` when `index < step`, else `scale-x-0`. Use `transform` (not `width`) so it stays on the compositor. Keep `cn` for the class switch.

- [ ] **Step 2: Verify in the admin**

Open the wizard as in Task 2 Step 2.
Action and expected:
- Continue 1→5: each new segment sweeps in from the left over about 300 ms.
- Back: the last filled segment sweeps out.
- On step 5, Edit "Customers earn": segments 3–5 empty together, and Continue refills 3–5 together, with none left half-filled.
- With macOS System Settings → Accessibility → Display → Reduce motion on: segments switch instantly.
- Then "Turn on my program": the success toast "Your loyalty program is on." shows and the wizard closes (criterion 4).

Then run the Task 1 Step 6 eslint and tsc commands; expected the same results.

- [ ] **Step 3: Commit**

Stage: `apps/admin/src/components/setup-wizard/SetupWizardModal.tsx`
Message: `feat(admin): animate the setup wizard progress bar`
