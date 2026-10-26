/**
 * Product-page earning line: when the shopper picks a variation, show that variation's sentence
 * (WooCommerce's variation data carries it as `yayloyalty_earn`); on Clear, put the cheapest one back.
 * Both are HTML the server escaped (ProductEarnMessage::sentence()), so they go in as innerHTML.
 *
 * Plain script, no build step. The class names are ProductEarnMessage::CSS_CLASS (and its __text) and the
 * data key is set in ProductEarnMessage::add_variation_sentence() (includes/Engine/Storefront/ProductEarnMessage.php).
 */
(function ($) {
  "use strict";

  /** @param {string|null} html The variation's sentence; null puts the cheapest one back. */
  function show(html) {
    var el = document.querySelector(".yayloyalty-product-earn");

    if (!el) {
      return;
    }

    if (html === null) {
      html = el.getAttribute("data-default") || "";
    }

    el.querySelector(".yayloyalty-product-earn__text").innerHTML = html;
    el.hidden = html === "";
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
