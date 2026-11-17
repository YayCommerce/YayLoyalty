---
id: BS-2
title: Page builder widgets for Elementor, Bricks and Divi
status: decided
from: R-3
decision: A. Four native widgets per builder from one shared list
created: 2026-11-16
---
# BS-2 Page builder widgets for Elementor, Bricks and Divi

## Problem
R-3 (sprint 3, 1.1.0): merchants building pages in Elementor, Bricks or Divi can only reach the loyalty placements by pasting a shortcode into the builder's generic shortcode element. They want to drag in the points balance, ways to earn, ways to redeem and redeem card as named widgets and see them in the builder. Today the four exist only as shortcodes (`Placements.php:28-30`, `RedeemCard.php:62`) and three of them as blocks (`Placements.php:44-46`); the redeem card has no block.

Acceptance (R-3): in all three builders the four widgets can be dragged in, preview live in the builder, render the same as the shortcodes, and a site without the builder loads no builder code.

## Constraints
- **Editions:** pro and lite. The widgets are Lite code (`includes/Integrations/`), never `includes/Pro/`. Pro only changes what they print, through the filters the shortcodes already use (`RedeemCard::ROWS_FILTER`, Pro's `RewardsPanel`). Hard rule 1: nothing here may name Pro.
- **Render the same as the shortcodes:** each widget must call the existing callbacks (`Placements::balance()`, `ways_to_earn()`, `ways_to_redeem()`, the `[yayloyalty_redeem]` shortcode), not copy templates. The shortcodes take no attributes, so the widgets need no content controls.
- **Empty output is normal:** every placement returns `''` when the program is off or there's nothing to show (`Placements::render()`, `Placements.php:149`), and the redeem card returns `''` with no cart or an empty cart (`RedeemCard.php:176`). Inside a builder there is no cart and the editor is an admin who may be outside the program, so a bare render leaves an invisible, unselectable widget. The builder preview needs its own empty state.
- **Assets:** `Placements::render()` enqueues `ACCOUNT_STYLE` from inside the render, and `RedeemCard::enqueue_assets()` likewise. A builder that re-renders a widget over AJAX after the page loaded won't pick up a style enqueued that late, so the preview frame has to enqueue both styles up front.
- **No builder code without the builder:** Elementor is a plugin (`ELEMENTOR_VERSION`, hook `elementor/widgets/register`); Bricks and Divi are **themes**, which load after `plugins_loaded`, so their detection has to wait for `after_setup_theme`/`init` (Bricks: `\Bricks\Elements::register_element()` on `init`; Divi 4: `et_builder_ready`). Widget classes extend the builder's base class, so they must only be autoloaded inside those hooks, as `integrations.md` already requires for partner adapters.
- **PHP 7.4+**, no typed properties of builder types.
- **Local test sites:** Elementor 4.4.0 and Divi 4.27.1 are installed here; Bricks is not (paid theme).

## Options
### A. Four native widgets per builder from one shared list
- How: one Lite class lists the four placements once (slug, title, icon, render callable) and gives a `render_for_builder()` that returns the shortcode output, or a labelled placeholder when that output is `''` and the request is a builder preview. Three thin adapters under `includes/Integrations/PageBuilders/{Elementor,Bricks,Divi}/` each register one class per placement in the builder's own hook (Elementor `Widget_Base`, Bricks `\Bricks\Element`, Divi 4 `ET_Builder_Module` with `vb_support => 'partial'` so the Visual Builder renders the PHP output). Each adapter enqueues the account and redeem styles in its preview frame and puts the widgets in a "YayLoyalty" category.
- Pros: exactly the acceptance; one source of truth for titles, icons and output, so adding a fifth placement is one line per builder; no JS build for any builder (all three server-render).
- Cons: 12 small classes (4 × 3) because each builder wants one class per element; three builder-detection paths to test; Divi 4 partial VB support re-renders through AJAX and is slower than a native React module.
- Risks: Elementor 4's atomic editor still runs classic `Widget_Base` widgets but may change that; Divi 5 runs Divi 4 modules only through its compatibility layer.
- Size: L

### B. One "YayLoyalty" widget per builder with a placement picker
- How: same shared list and preview fallback as A, but each builder gets a single element with one select control (Balance / Ways to earn / Ways to redeem / Redeem card) that picks the callback.
- Pros: 3 classes instead of 12; one control and one test path per builder.
- Cons: the merchant drags "YayLoyalty" and then picks, so the four aren't findable by name in the builder's search, which is what the acceptance describes; it adds a setting the shortcodes don't have.
- Size: M

### C. Elementor and Bricks now, Divi behind a later item
- How: option A for Elementor and Bricks only; Divi merchants keep using the Code/Shortcode module, documented; Divi becomes a `kind=deferred` backlog note.
- Pros: cuts the oldest and least predictable API (Divi 4 vs 5) out of the sprint; ships sooner.
- Cons: misses R-3's acceptance for Divi; changes the scope of a 1.1.0 item.
- Size: M

## Recommendation
**A.** It is the only option that meets the acceptance as written, and the shared list keeps the 12 classes to a few lines each: the real work (output, empty state, styles) lives once. All three builders can server-render PHP, so there's no new JS build. Divi should target the Divi 4 module API with partial VB support: it is what's installed here, it is what most Divi stores run, and Divi 5 still loads it. What would change my mind: if the team wants Divi 5 native modules (a separate JS module package), Divi grows past the sprint and C becomes the honest choice. B is close on effort but trades away findability for fewer files.

Preview empty state: when the real output is `''` in a builder preview, print a small labelled box ("Points balance: shows the signed-in customer's points") rather than sample data. Sample data would mean a second rendering path through the templates (as `LauncherPreview` does for the launcher), and "render the same as the shortcodes" is easiest to keep when the preview shows either the real output or a placeholder.

Decided with the developer (2026-11-16): option A, the Divi 4 module API, and a labelled placeholder for empty previews.

## Open questions
- Bricks needs a licensed copy to build and check against; who provides it?
- Elementor 4.4's atomic (v4) elements: is a classic widget acceptable for 1.1.0, or do we want atomic widgets too? Default: classic only.
