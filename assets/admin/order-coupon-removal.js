/**
 * The order screen's Remove link on a loyalty coupon line: asks whether to return the points the customer
 * spent on it before WooCommerce removes the line.
 *
 * A capture-phase listener stops WooCommerce's own click handler for the codes in
 * window.yayloyaltyCouponRemoval.coupons and opens a dialog. "Restore" and "Don't restore" click the link
 * again through jQuery, so WooCommerce sends its usual request, first adding `yayloyalty_restore` or
 * `yayloyalty_keep` and the nonce through WooCommerce's `woocommerce_order_meta_box_remove_coupon_ajax_data` filter.
 *
 * Plain script, no build step. The data comes from includes/Engine/Admin/OrderCouponRemoval.php.
 */
(function ($) {
  "use strict";

  var config = window.yayloyaltyCouponRemoval;
  var ITEMS = "#woocommerce-order-items";

  if (!config || !$) {
    return;
  }

  /** The code the next remove request is for; consumed by the filter. */
  var pending = null;
  /** The flag that request carries: `yayloyalty_restore` or `yayloyalty_keep`. */
  var choice = null;
  /** True while this script clicks the link itself, so the capture listener lets WooCommerce handle it. */
  var resending = false;
  var dialog = null;

  function format(template, value) {
    return template.replace("%s", value);
  }

  function button(className, label) {
    var element = document.createElement("button");
    element.type = "button";
    element.className = className;
    element.textContent = label;
    return element;
  }

  function buildDialog() {
    var element = document.createElement("dialog");
    element.className = "yayloyalty-coupon-removal";
    element.setAttribute("aria-labelledby", "yayloyalty-coupon-removal-title");
    element.innerHTML =
      '<div class="yayloyalty-coupon-removal__header"><h2 id="yayloyalty-coupon-removal-title"></h2></div>' +
      '<div class="yayloyalty-coupon-removal__body">' +
      '<p class="yayloyalty-coupon-removal__spent"></p>' +
      '<p class="yayloyalty-coupon-removal__unreturned"></p>' +
      "</div>" +
      '<div class="yayloyalty-coupon-removal__footer">' +
      '<span class="yayloyalty-coupon-removal__dismiss"></span>' +
      '<span class="yayloyalty-coupon-removal__choices"></span>' +
      "</div>";
    // A click on the backdrop lands on the dialog element itself: treat it as Cancel.
    element.addEventListener("click", function (event) {
      if (event.target === element) {
        element.close();
      }
    });
    document.body.appendChild(element);
    return element;
  }

  function resend(link) {
    resending = true;
    try {
      $(link).trigger("click");
    } finally {
      resending = false;
    }
  }

  function open(link, code) {
    var coupon = config.coupons[code];
    var i18n = config.i18n;

    dialog = dialog || buildDialog();
    dialog.querySelector("h2").textContent = format(i18n.title, coupon.name);
    dialog.querySelector(".yayloyalty-coupon-removal__spent").textContent = format(i18n.spent, coupon.points);

    var unreturned = dialog.querySelector(".yayloyalty-coupon-removal__unreturned");
    unreturned.textContent = coupon.points === coupon.remaining ? "" : format(i18n.unreturned, coupon.remaining);
    unreturned.hidden = coupon.points === coupon.remaining;

    var restore = button("button button-primary", format(i18n.restore, coupon.remaining));
    var keep = button("button", i18n.keep);
    var cancel = button("button", i18n.cancel);
    dialog.querySelector(".yayloyalty-coupon-removal__dismiss").replaceChildren(cancel);
    dialog.querySelector(".yayloyalty-coupon-removal__choices").replaceChildren(keep, restore);

    restore.addEventListener("click", function () {
      dialog.close();
      pending = code;
      choice = "yayloyalty_restore";
      resend(link);
    });
    keep.addEventListener("click", function () {
      dialog.close();
      pending = code;
      choice = "yayloyalty_keep";
      resend(link);
    });
    cancel.addEventListener("click", function () {
      dialog.close();
    });

    dialog.showModal();
    restore.focus();
  }

  document.addEventListener(
    "click",
    function (event) {
      var link = event.target instanceof Element ? event.target.closest(ITEMS + " a.remove-coupon") : null;

      if (!link || resending) {
        return;
      }

      var code = String($(link).data("code") || "").toLowerCase();

      if (!Object.prototype.hasOwnProperty.call(config.coupons, code)) {
        return;
      }

      event.preventDefault();
      event.stopImmediatePropagation();
      open(link, code);
    },
    true
  );

  // The code stays in config.coupons: if the request fails, the line stays and its next Remove must ask again.
  // After a restore succeeds the line is gone, and the order refuses that code again (CouponProvider).
  $(ITEMS).on("woocommerce_order_meta_box_remove_coupon_ajax_data", function (event, data) {
    if (pending !== null && data && String(data.coupon).toLowerCase() === pending) {
      data[choice] = 1;
      data.yayloyalty_nonce = config.nonce;
      pending = null;
    }

    return data;
  });
})(window.jQuery);
