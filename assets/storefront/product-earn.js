/**
 * Product-page earning line: when the shopper picks a variation, show that variation's sentence
 * (WooCommerce's variation data carries it as `yayloyalty_earn`); on Clear, put the cheapest one back.
 *
 * Plain script, no build step. The class names are ProductEarnMessage::CSS_CLASS (and its __line) and the
 * data key is set in ProductEarnMessage::add_variation_sentence() (includes/Engine/Storefront/ProductEarnMessage.php).
 */
(function ($) {
  "use strict";

  /** @param {string|null} text The variation's sentence; null puts the cheapest one back. */
  function show(text) {
    var el = document.querySelector(".yayloyalty-product-earn");

    if (!el) {
      return;
    }

    if (text === null) {
      text = el.getAttribute("data-default") || "";
    }

    el.querySelector(".yayloyalty-product-earn__line").textContent = text;
    el.hidden = text === "";
  }

  $(document).on("found_variation", ".variations_form", function (event, variation) {
    if (typeof variation.yayloyalty_earn === "string") {
      show(variation.yayloyalty_earn);
    }
  });

  $(document).on("reset_data", ".variations_form", function () {
    show(null);
  });
})(jQuery);
