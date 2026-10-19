# Phase 3: Ways to earn

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 1 Tasks 3 and 5 (`BirthdayHandler::advertised_value()`, `BirthdayFields`)
**Delivers:** "Celebrate your birthday" in the member launcher panel, the My Account Rewards tab and the Ways to earn block/shortcode; a member with no birthday on file gets it as a link to Account details.
**Exit criterion:** Acceptance 6 on the Local site, checked by rendering `[yayloyalty_ways_to_earn]` and the member launcher panel for a guest, a member with a birthday and a member without one (Task 2 Step 3); a Lite package of this tree passes `validate-build.php` with the boot check (Task 2 Step 4).

Found while expanding: rows are built per request (`LauncherPayload` caches nothing per user), so the Pro filter may read `get_current_user_id()`. Only `templates/panel-member.php` and `templates/ways-to-earn.php` print rows. `account-rewards.php` includes `ways-to-earn.php`, and `panel-guest.php` builds its own pitch from `$view['earn']`, not rows. So the guest launcher panel shows no birthday row; guests see it in the Ways to earn block and shortcode.

### Task 1: Lite seam for earn rows

**Files:**
- Modify: `includes/Engine/Storefront/LauncherEarnRules.php`
- Modify: `includes/Engine/Storefront/templates/panel-member.php`
- Modify: `includes/Engine/Storefront/templates/ways-to-earn.php`

**Interfaces:** Produces filter `yayloyalty_launcher_earn_rows` ( array $rows, array $earn ): array (README contract). Each row may carry `url` (string; absent or '' means no link). Update the row-shape docblocks in `with_daily_limit()`, `member_rows()` and `for_viewer()` to `array{trigger: string, label: string, value: string, url?: string}`.

- [ ] **Step 1: Apply the filter as `member_rows()`'s return**, with a hook docblock: "Ways to earn rows for the current viewer. Internal Pro seam, not a public API." Guests reach it through `for_viewer()` too, before the signup row is put first.
- [ ] **Step 2: In both templates, print the label as `<a href="<?php echo esc_url( $row['url'] ); ?>">` when `! empty( $row['url'] )`, else as now.** Launcher link classes: `yl:text-primary yl:hover:underline yl:underline-offset-4`, as the launcher's own rewards link has (already in the compiled CSS).
- [ ] **Step 3: Verify nothing changes without a subscriber:**
  - `composer test`: expected `OK (58 tests, …)`.
  - `composer lint -- <the three files>`: no findings.
  - Before Task 2 lands, render `do_shortcode( '[yayloyalty_ways_to_earn]' )` as user 1 in `wp eval`. Expected: the same rows as on `dev/edd`, with no links.
- [ ] **Step 4: Commit.**
  - Stage: the three files.
  - Message: `feat(storefront): let a build add Ways to earn rows`.

### Task 2: Pro birthday row

**Files:**
- Create: `includes/Pro/Birthday/BirthdayEarnRow.php`
- Modify: `includes/Pro/YayLoyaltyPro.php` (`( new BirthdayEarnRow() )->register();` in `boot()`)

**Interfaces:**
- Consumes: the filter; `PointsEngine::build( new CampaignRepository() )->advertised( CampaignTrigger::BIRTHDAY, ContextFactory::for_advert( Dates::now() ) )`, which returns null when no live birthday campaign applies to everyone; `CustomerRepository::find_by_user_id()`.
- Produces: `BirthdayEarnRow::register(): void` and `add_row( array $rows ): array`, hooked with one argument (it needs nothing from `$earn`).

Row: `trigger` `birthday`; label `__( 'Celebrate your birthday', 'yayloyalty' )`; value `SettingsHelper::points_text( (int) $advert['value'] )`; `url` `wc_customer_edit_account_url()` for a logged-in viewer with no birthday on file, else ''. Appended last. No row when `advertised()` returns null. An exception from the engine is logged with `Log::warning( 'BirthdayEarnRow', … )` and costs only this row, as `LauncherEarnRules::advertised()` does. The engine is built once per request (static).

- [ ] **Step 1: Implement and register `BirthdayEarnRow`.**
- [ ] **Step 2: Lint:** `composer lint -- includes/Pro/Birthday/BirthdayEarnRow.php includes/Pro/YayLoyaltyPro.php` → no findings.
- [ ] **Step 3: Verify on the Local site** (campaign 18 live, 150 points). Use `wp eval`, rendering `do_shortcode( '[yayloyalty_ways_to_earn]' )` for each viewer and the member panel through `Launcher::markup( Launcher::view( $design, $payload, true ) )`. The launcher is hidden for admins in the browser.
  - As a guest (`wp_set_current_user( 0 )`): the shortcode lists "Celebrate your birthday" with 150 in the site's points label, and no link.
  - As user 23 (no birthday on file): the shortcode and the member panel show the label as a link to the Account details URL.
  - As user 1 (birthday Oct 19 on file): the row has no link.
  - With campaign 18 paused (put back to active afterwards): no birthday row for anyone.
- [ ] **Step 4: Verify the Lite package without cutting a release.** `release` writes a new version into the tree and is only for cutting a release (`.claude/rules/release.md`). Instead, in the scratchpad:
  - Unpack `release/yayloyalty-lite-1.0.0.zip`.
  - Swap in the tracked `includes/` minus `includes/Pro/` and `includes/Engine/Register/RegisterDev.php`, the current root PHP files, and a scratch `BUILD_TYPE=lite` build of the SPA as `assets/dist/admin/`.
  - Regenerate `release-manifest.json`'s SHA-256 map and re-zip.
  - Run `YAYLOYALTY_WP_CLI="<wp with the Local socket> --path=<site>" YAYLOYALTY_WP_CLI_ARGS="--skip-plugins=yayloyalty" php scripts/validate-build.php <zip> lite --require-boot`.
  - Expected: `19 checks passed.`, including "Lite: Pro named only by the guarded seam" and "Booted plugin reports is_pro() === false".
- [ ] **Step 5: Commit.**
  - Stage: both files.
  - Message: `feat(birthday): advertise the birthday campaign in Ways to earn`.
