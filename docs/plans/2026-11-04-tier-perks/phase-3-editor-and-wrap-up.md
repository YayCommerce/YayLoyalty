# Phase 3: The reward editor's Tier section and wrap-up

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 2 landed and `/yayplancheck` passed: Phase 2 Task 1's REST validation, and Task 2's enforcement.
**Delivers:**
- Merchants set "Minimum tier" on coupon rewards in the editor.
- The Lite build is verified.
- The whole slice is walked end to end.
- The tiers backlog item reflects D9.

**Exit criterion:** spec AC 14, 15, 16 and 17 pass by Task 1 and Task 2's checks, and the end-to-end AC 18 passes on wp-env (Task 2).

### Task 1: The "Tier" section in the reward editor

**Files:**
- Modify: `apps/admin/src/pro/rewards/editor/reward-editor.form.ts`:
  - `RewardFormValues.min_tier_id`;
  - `rewardToFormValues()`, `rewardCreateSeed()` (null), `duplicateToFormValues()` (copied);
  - `formValuesToBody()` writes `withMinTier( storedConditions, values.min_tier_id )`;
  - `rewardFormSchema()` picks `min_tier_id: z.number().int().positive().nullable()`;
  - the new exported helpers `minTierOf()` and `withMinTier()`;
  - the `RewardFormValues` docblock now says the form shows only the tier entry.
- Modify: `apps/admin/src/pro/rewards/editor/RewardEditorSections.tsx`: step 3 `EditorSection` "Tier", rendered only when `type` is `fixed_coupon` or `percent_coupon`, with a `RewardEditorTier` child in the same file.

**Interfaces:**
- Consumes:
  - `TIER_QUERIES.list()` and `useTiersEnabled()` from `apps/admin/src/pro/tiers/tiers.queries.ts`;
  - `TierBadge` (or its colour dot) from `apps/admin/src/pro/tiers/TierBadge.tsx`;
  - Phase 2 Task 1's REST validation.
- Produces: `minTierOf()` and `withMinTier()` (README contract).

UI values (spec § UI):
- **Label:** "Minimum tier".
- **First option:** "Any tier" (value null).
- **Options:** live tiers sorted by threshold, each with a colour dot.
- **Help text:** "Only customers in this tier or a higher one see and can claim this reward."
- **Tiers off:** an outline `Alert` with `InfoIcon` reading "Tiers are off. Rewards that require a tier are hidden from every customer until you turn tiers on."
- **List query error:** the select is disabled, with an inline error and a retry.
- **Stored id not in the live list:** the select shows "Any tier", with the warning "The tier this reward required was removed."

If `RewardEditorSections.tsx` passes ~400 lines, stop and ask the user before splitting it out.

**Verification** (spec AC 14 and 15, wp-env):
1. On reward S (with a REST-set `user_role` entry), set Minimum tier → Silver and Save. `GET /rewards/{S}` shows both the `user_role` entry and `{type: "tier", operator: "gte", value: <Silver id>}`.
2. Set it back to "Any tier" and Save. Only the `user_role` entry remains.
3. Reopening the editor shows the saved choice.
4. The section shows on `fixed_coupon` and `percent_coupon` rewards, and not on the points discount.
5. With tiers off, the notice shows.
6. Run `tsc --noEmit` (exit 0), and `eslint` on both files (no errors).

Steps: expand after Phase 2 lands.

### Task 2: Lite check, end-to-end walkthrough, backlog

**Files:**
- Modify: `docs/backlog/2026-10-06-tiers-not-built.md`:
  - "Today" gains the multiplier, the reward "Minimum tier", and hiding;
  - "Still missing" drops the reward `TIER` line, and states that `CampaignConditionType::TIER` stays in `UNAVAILABLE` by design (master D9);
  - the Done when box "`TIER` leaves `UNAVAILABLE` in both condition types, and `ConditionsEvaluator` evaluates it" is reworded to "`RewardConditionType::TIER` leaves `UNAVAILABLE` and is enforced; `CampaignConditionType::TIER` stays unavailable (master D9)" and ticked.
- Modify: `docs/plans/2026-11-04-tier-perks/README.md`: the phase Status column only. The plan Status becomes `done YYYY-MM-DD` only when the user asks.

**Interfaces:**
- Consumes: everything above.
- Produces: none.

**Verification:**
1. **AC 16:** `DELETE /tiers/{Silver}` while R references it returns 409 listing R.
2. **AC 17:**
   - `./scripts/run.sh release pro 1.0.0 --no-prompt`, then `./scripts/run.sh release lite --no-prompt`. Both validations pass, and the Lite zip has no `includes/Pro/` and no Pro SPA chunk. Run `composer install` afterwards, and `git status` shows no rewritten version files (`git restore yayloyalty.php readme.txt` if it does).
   - With Lite active on wp-env: `Installer::verify()` returns `[]`, R's stored TIER entry is unchanged, and a Silver customer's 101-unit order earns 101 with `tier_multiplier` NULL.
3. **AC 18 (end to end),** on wp-env Pro with tiers on, Lifetime mode, the seed tiers, Silver's bonus at 100, the default purchase campaign at 1 pt per unit, and R "Minimum tier: Silver" set in the editor:
   1. A new customer's 800-unit completed order puts them in Silver with 900 points.
   2. Their next 100-unit order earns 125, labelled "Earned on order #{id} · Silver ×1.25" in the drawer.
   3. They see R in the launcher and on the redeem card, and claim it at checkout.
   4. A Bronze customer on the same site sees no R anywhere.

Steps: expand after Phase 2 lands.
