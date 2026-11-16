---
id: P-2
title: Page builder widgets for Elementor, Bricks and Divi
status: in-progress
from: BS-2
roadmap: R-3
design:
trello:
type: feat
branch: feat/p-2-page-builder-widgets-for-elementor-bricks-and
pr:
editions: [pro, lite]
created: 2026-11-16
---
# P-2 Page builder widgets for Elementor, Bricks and Divi

## Goal
In Elementor, Bricks and Divi a merchant finds a "YayLoyalty" group with four elements (Points balance, Ways to earn, Ways to redeem, Redeem card), drags one onto a page, and sees it in the builder: the real output when there is some, otherwise a labelled placeholder saying what it shows and when. On the live page each element prints exactly what its shortcode prints. A site without a builder loads none of the builder classes. Done means every part of R-3's acceptance passes the Verify list in both editions.

## Non-goals
- Elementor 4 atomic (v4) widgets: classic `Widget_Base` widgets only (BL-3).
- Divi 5 native modules: Divi 4 module API only; Divi 5 runs it through its compatibility layer (BS-2 decision).
- Content or style controls: the shortcodes take no attributes, so the elements add none. The builders' own Advanced/Style tabs (margins, wrapper CSS) still apply.
- Sample data in the builder preview (BS-2 decision: placeholder).
- A Gutenberg block for the redeem card.

## Findings
1. The redeem card returns `''` without a cart or with an empty cart (`includes/Engine/Storefront/RedeemCard.php:176`), and every placement returns `''` while the program is off or there's nothing to show (`includes/Engine/Storefront/Placements.php:149-160`). In a builder this is the usual case, so the placeholder is the main preview path, not an edge case → T1.
2. `Placements::render()` enqueues `ACCOUNT_STYLE` from inside the render (`Placements.php:163`), and `RedeemCard::enqueue_assets()` does the same for its style and script (`RedeemCard.php:144`). Elementor and Divi re-render an element over AJAX after the preview frame loaded, so a style enqueued then never reaches the frame → each adapter enqueues both stylesheets in its builder frame (T2–T4). `ACCOUNT_STYLE` is registered on `init` (`AccountMyPoints.php:45`), so it is enqueueable by then.
3. Bricks and Divi are themes; they load after `plugins_loaded`, where YayLoyalty boots. Detecting them by constant at boot would always fail → the integration only adds hooks that fire solely when the builder is there (`elementor/widgets/register` at `plugins/elementor/includes/managers/widgets.php:158`, `et_builder_ready` at `themes/Divi/includes/builder/framework.php:861`, Bricks on `init` guarded by `class_exists( '\Bricks\Elements' )`), and names a builder class only inside those callbacks (`integrations.md`, Partner plugins).
4. Divi 4's partial Visual Builder support renders a module through the `et_fb_ajax_render_shortcode` AJAX action (`themes/Divi/includes/builder/functions.php:2234`), where `et_core_is_fb_enabled()` (`themes/Divi/core/functions.php:398`) is not the whole check → T4's preview test covers both the VB page and that AJAX request.
5. Bricks 2.4.5 is installed locally (`themes/bricks`). Its `\Bricks\Element` base is only `require`d inside `Elements::init_elements()` on `init` priority 10 (`themes/bricks/includes/elements.php:13,21`), so our elements register on `init` priority 11 or later. `Elements::register_element( $file, $name, $class )` `require_once`s the file and, without a class name, takes the *last declared class* (`themes/bricks/includes/elements.php:253-264`), which our autoloader can make the abstract base → T3 always passes all three arguments. Builder detection: `bricks_is_builder()` (the canvas page, `functions.php:251`) and `bricks_is_builder_call()` (the REST `render_element` re-render, `functions.php:290`, `includes/api.php:37`). Bricks' own Shortcode element shows the same pattern: render the real thing, a placeholder only in the builder (`includes/elements/shortcode.php`). We keep the shared placeholder rather than Bricks' `render_element_placeholder()` so all three builders show the same box.
6. differs: `CLAUDE.md` (Layout: "`includes/Integrations/` YayMail, YayPricing, YayCurrency"), `docs/knowledge/integrations.md` (Partner plugins table) and `docs/knowledge/architecture.md:124` (Storefront placements list) don't know the builders → T5 updates them.

## Approach
- **Shared list** `includes/Integrations/PageBuilders/PlacementWidgets.php`: the four placements once (key, shortcode tag, translated title), and `output( string $key, bool $is_preview ): string`, which runs the placement's shortcode through `do_shortcode()` and, only when that is `''` and `$is_preview`, returns the placeholder (title plus one line on when it shows, escaped, in the account tab's state card that `no-ways-card.php` already borrows). Every adapter calls `output()`; nothing else renders.
- **Boot** `includes/Integrations/PageBuilders/PageBuildersIntegration.php` (singleton, like `YayPricingIntegration`), constructed in `YayLoyalty.php` next to the other integrations: adds the three builders' hooks only.
- **Elementor** `includes/Integrations/PageBuilders/Elementor/`: on `elementor/elements/categories_registered` add category `yayloyalty`; on `elementor/widgets/register` register four widgets, each a tiny subclass of an abstract `PlacementWidget extends \Elementor\Widget_Base` (name, title, icon, category; `render()` echoes `output()` with preview = `Plugin::$instance->editor->is_edit_mode()`); on `elementor/preview/enqueue_styles` enqueue the styles.
- **Bricks** `includes/Integrations/PageBuilders/Bricks/`: on `init` priority 11 (after Bricks loads its element base at 10) call `\Bricks\Elements::register_element( $file, $name, $class )` for four subclasses of an abstract `PlacementElement extends \Bricks\Element` (category `yayloyalty`, labelled through the `bricks/builder/i18n` filter; `render()` prints `output()` inside `render_attributes( '_root' )`, preview = `bricks_is_builder() || bricks_is_builder_call()`); enqueue the styles on `wp_enqueue_scripts` while `bricks_is_builder()`.
- **Divi 4** `includes/Integrations/PageBuilders/Divi/`: on `et_builder_ready` instantiate four subclasses of an abstract `PlacementModule extends \ET_Builder_Module` (`$vb_support = 'partial'`, no fields, `render()` returns `output()`, preview = VB page or the `et_fb_ajax_render_shortcode` request); enqueue the styles on `wp_enqueue_scripts` while `et_core_is_fb_enabled()`.
- **Editions:** all Lite code. Pro changes only what the shortcodes print, through the filters they already apply. Nothing names Pro (hard rule 1).
- **Storage, REST, migrations:** none. Builders save their own element references; removing YayLoyalty leaves the builder's usual "missing widget" state.

## Tasks
- [x] T1 Shared placement list, output and builder placeholder — `includes/Integrations/PageBuilders/PlacementWidgets.php` (new), `includes/Engine/Storefront/templates/builder-placeholder.php` (new; borrows `account-rewards-state.css`'s card, as `no-ways-card.php` does, so no new CSS)
  - Reuse: `Placements::BALANCE_SHORTCODE` / `WAYS_TO_EARN_SHORTCODE` / `WAYS_TO_REDEEM_SHORTCODE` and `RedeemCard::SHORTCODE` (the shortcodes themselves, so output matches); `OutputCapture::capture()` for the placeholder template; `ScriptName::ACCOUNT_STYLE`
  - Callers: `PlacementWidgets::title()` → each element's label (T2–T4); `PlacementWidgets::output()` → each element's `render()` (T2–T4); `PlacementWidgets::enqueue_preview_styles()` → each adapter's preview-styles hook (T2–T4)
- [ ] T2 Elementor widgets — `includes/Integrations/PageBuilders/PageBuildersIntegration.php` (new), `includes/Integrations/PageBuilders/Elementor/ElementorWidgets.php` (new), `includes/Integrations/PageBuilders/Elementor/PlacementWidget.php` (new), four subclasses `PointsBalanceWidget`, `WaysToEarnWidget`, `WaysToRedeemWidget`, `RedeemCardWidget` (new), `includes/YayLoyalty.php`
  - Reuse: `SingletonTrait` as in `includes/Integrations/YayPricing/YayPricingIntegration.php`; `PlacementWidgets` (T1); `Placements::BLOCK_CATEGORY` for the category slug
  - Callers: `PageBuildersIntegration::get_instance()` → `YayLoyalty::__construct()`; `ElementorWidgets::register_category()` → action `elementor/elements/categories_registered`; `register_widgets()` → action `elementor/widgets/register`; widget classes → Elementor's widgets manager
- [ ] T3 Bricks elements — `includes/Integrations/PageBuilders/Bricks/BricksElements.php` (new), `includes/Integrations/PageBuilders/Bricks/PlacementElement.php` (new), four subclasses (new), `includes/Integrations/PageBuilders/PageBuildersIntegration.php`
  - Reuse: `PlacementWidgets` (T1); Bricks' `\Bricks\Elements::register_element()`, `bricks_is_builder()`, `bricks_is_builder_call()`
  - Callers: `BricksElements::register()` → action `init` priority 11; `BricksElements::category_label()` → filter `bricks/builder/i18n`; `enqueue_builder_styles()` → action `wp_enqueue_scripts`; element classes → Bricks' element registry
- [ ] T4 Divi 4 modules — `includes/Integrations/PageBuilders/Divi/DiviModules.php` (new), `includes/Integrations/PageBuilders/Divi/PlacementModule.php` (new), four subclasses (new), `includes/Integrations/PageBuilders/PageBuildersIntegration.php`
  - Reuse: `PlacementWidgets` (T1); Divi's `ET_Builder_Module`, `et_core_is_fb_enabled()`
  - Callers: `DiviModules::register()` → action `et_builder_ready`; `enqueue_builder_styles()` → action `wp_enqueue_scripts`; module classes → instantiated in `register()`, which is how Divi 4 registers modules
- [ ] T5 Knowledge and readme — `CLAUDE.md` (Layout row for `includes/Integrations/`), `docs/knowledge/integrations.md` (Partner plugins: Elementor, Bricks, Divi rows), `docs/knowledge/architecture.md` (Storefront placements line), `readme.txt` (FAQ line naming the builder elements) [risk: 1]
  - Reuse: n/a

**Tests:** [unit] test_output_is_the_shortcode_or_a_placeholder_only_in_preview, test_empty_output_stays_empty_on_the_live_page_and_becomes_the_placeholder_in_preview, test_an_unknown_key_prints_nothing (tests/php/unit/Integrations/PageBuilders/PlacementWidgetsTest.php) — a live page must print exactly the shortcode's output, and `''` must stay `''` outside a builder; [manual] each builder's drag, preview and front-end render (Verify) — the builders aren't in wp-env and Bricks/Divi are paid themes

**E2E:** none

## Verify
- `yayh test run unit` → exit 0 (unit)
- `composer lint` → no new errors in `includes/Integrations/PageBuilders/` (the `dev` baseline still fails, see known-issues)
- `php -l` on every new file → exit 0
- `wp eval 'do_action("init"); echo class_exists("YayLoyalty\\Integrations\\PageBuilders\\Elementor\\PlacementWidget", false) || class_exists("YayLoyalty\\Integrations\\PageBuilders\\Divi\\PlacementModule", false) || class_exists("YayLoyalty\\Integrations\\PageBuilders\\Bricks\\PlacementElement", false) ? "loaded" : "none";'` with Elementor deactivated and a non-Divi, non-Bricks theme → none
- Elementor (4.4.0, local): edit a page → panel search "points" shows the four elements under YayLoyalty → drag each in → Points balance, Ways to earn and Ways to redeem show real output for the admin (or the placeholder when the admin is outside the program); Redeem card shows the placeholder with an empty cart and the real card with items in the cart → update the page.
- Elementor front end: the same page and a page holding the four shortcodes, both signed in as a customer with points and a cart → `curl -s` of each (with the customer's cookie) shows the same `.yayloyalty-placement` / redeem card markup; no placeholder card (its hint text) on the live page.
- Divi 4.27.1 (local): Visual Builder → Add module → the four YayLoyalty modules → each renders (real or placeholder) and keeps its styles after a module settings change → save → front end matches the shortcodes as above.
- Bricks (2.4.5, local; activate the Bricks theme): edit a page with Bricks → elements panel → YayLoyalty category with the four elements → drag each in → real output or the placeholder, styled, and still styled after a settings change re-renders it → save → front end matches the shortcodes as above.
- Program paused (General settings off): every element prints nothing on the live page and the placeholder in each builder.
- Lite: `./scripts/run.sh release lite <version> --no-prompt` → the validator passes; the four elements work the same in a Lite install (Ways to redeem and Redeem card without Pro's reward rows).
- Pro: with an active Pro reward, Redeem card in each builder's live page lists the reward row, as `[yayloyalty_redeem]` does.

## Risks
- A builder changes its element API (Elementor 4's atomic editor, Divi 5): the elements stop registering, the pages show the builder's own "missing widget" state, and the shortcodes still work. Rollback: remove the `PageBuildersIntegration::get_instance()` line in `YayLoyalty.php`.
- A builder class named outside its hook fatals a site without that builder: T2–T4 keep every builder class behind its callback, and the `wp eval` check proves none loads.
- Styles missing in a builder frame after an AJAX re-render: each adapter enqueues both stylesheets up front (Finding 2); checked in the Elementor and Divi steps.
