/**
 * Product-page earning line: when the shopper picks a variation, show that variation's sentence and bonus lines
 * (WooCommerce's variation data carries them as `yayloyalty_earn` and `yayloyalty_earn_bonuses`); on Clear, put
 * the cheapest one's back. Both are HTML the server escaped (ProductEarnMessage::sentence() and bonus_lines()),
 * so they go in as innerHTML. A variation with no bonus clears the bonus area.
 *
 * Plain script, no build step. The class names are ProductEarnMessage::CSS_CLASS (and its __text and __bonuses) and
 * the data keys are set in ProductEarnMessage::add_variation_sentence() (includes/Engine/Storefront/ProductEarnMessage.php).
 */
(function ($) {
  "use strict";

  /**
   * @param {Element}     el   The element whose content is swapped.
   * @param {string|null} html The variation's HTML; null puts the element's data-default back.
   * @return {string} What it now shows.
   */
  function swap(el, html) {
    if (html === null) {
      html = el.getAttribute("data-default") || "";
    }

    el.innerHTML = html;

    return html;
  }

  /**
   * @param {string|null} sentence The variation's sentence; null puts the cheapest one back.
   * @param {string|null} bonuses  The variation's bonus lines; null puts the cheapest one's back.
   */
  function show(sentence, bonuses) {
    var el = document.querySelector(".yayloyalty-product-earn");

    if (!el) {
      return;
    }

    el.hidden = swap(el.querySelector(".yayloyalty-product-earn__text"), sentence) === "";

    var area = el.querySelector(".yayloyalty-product-earn__bonuses");

    if (area) {
      area.hidden = swap(area, bonuses) === "";
    }
  }

  $(document).on("found_variation", ".variations_form", function (event, variation) {
    if (typeof variation.yayloyalty_earn === "string") {
      show(
        variation.yayloyalty_earn,
        typeof variation.yayloyalty_earn_bonuses === "string" ? variation.yayloyalty_earn_bonuses : "",
      );
    }
  });

  $(document).on("reset_data", ".variations_form", function () {
    show(null, null);
  });
})(jQuery);
