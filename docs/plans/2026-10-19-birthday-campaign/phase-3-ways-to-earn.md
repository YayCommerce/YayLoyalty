# Phase 3: Ways to earn

**Plan:** `README.md` · **Depends on:** Phase 1 Tasks 3 and 5 · **Delivers:** "Celebrate your birthday" in the launcher, the My Account Rewards tab and the Ways to earn block.
**Exit criterion:** Acceptance 6 on the storefront (as a guest, a member with a birthday, a member without one); `./scripts/run.sh release lite --no-prompt` validates; the Pro release run validates.

### Task 1: Lite seam for earn rows

**Files:** Modify `includes/Engine/Storefront/LauncherEarnRules.php` (apply `yayloyalty_launcher_earn_rows` at the end of `member_rows()`; widen the row shape with optional `url`), and the templates that print rows: `templates/panel-member.php`, `templates/panel-guest.php`, `templates/account-rewards.php`, `templates/ways-to-earn.php` (wrap the label in `<a href="esc_url( url )">` when `url` is non-empty). Check whether `LauncherPayload` caches rows per user before relying on `get_current_user_id()` in the filter.
**Interfaces:** Produces filter `yayloyalty_launcher_earn_rows` (README contract).
Steps: expand after Phase 2 lands.

### Task 2: Pro birthday row

**Files:** Create `includes/Pro/Birthday/BirthdayEarnRow.php`; modify `includes/Pro/YayLoyaltyPro.php`.
**Interfaces:** Consumes the filter, `PointsEngine::advertised( CampaignTrigger::BIRTHDAY, ContextFactory::for_advert( Dates::now() ) )`, `BirthdayFields::is_offered()`, `get_birthday_month()`. Row: `trigger` `birthday`, label "Celebrate your birthday", value `SettingsHelper::points_text( points )`, `url` = `wc_customer_edit_account_url()` for a logged-in member with no birthday, else ''. No row when nothing is advertised.
Steps: expand after Phase 2 lands. Includes the Lite build check (`./scripts/run.sh release lite --no-prompt`, then `composer install`).
