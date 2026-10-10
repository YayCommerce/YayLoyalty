/**
 * Product-page earning line: when the shopper picks a variation, show that variation's sentence
 * (WooCommerce's variation data carries it as `yayloyalty_earn`); on Clear, put the cheapest one back.
 *
 * Plain script, no build step. Markup comes from includes/Engine/Storefront/ProductEarnMessage.php.
 */
(function ($) {
  "use strict";

  function box() {
    return document.querySelector(".yayloyalty-product-earn");
  }

  function show(text) {
    var el = box();

    if (!el) {
      return;
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
    var el = box();

    if (el) {
      show(el.getAttribute("data-default") || "");
    }
  });
})(jQuery);
