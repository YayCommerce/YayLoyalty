/**
 * The points card on the Cart and Checkout blocks: a slot fill that shows the card the server rendered
 * (extensions.yayloyalty.card on the Store API cart), so the blocks and the classic pages share one template.
 * Apply and Remove stay in redeem.js; here they are routed through the cart store, which redraws the card.
 *
 * Plain script, no build step and no JSX. Its values come from window.yayloyaltyRedeemBlocks
 * (StoreApiAdapter::enqueue()).
 */
(function () {
  "use strict";

  var element = window.wp && window.wp.element;
  var plugins = window.wp && window.wp.plugins;
  var checkout = window.wc && window.wc.blocksCheckout;

  if (
    !element ||
    !plugins ||
    !checkout ||
    !checkout.ExperimentalDiscountsMeta
  ) {
    return;
  }

  var config = window.yayloyaltyRedeemBlocks || {};

  /** WooCommerce passes every fill the cart's `extensions`. The block editor's sample cart has none of ours. */
  function Card(props) {
    var data = props.extensions && props.extensions.yayloyalty;
    var html = data && typeof data.card === "string" ? data.card : "";

    if (html === "") {
      return null;
    }

    return element.createElement("div", {
      "data-yl-redeem-blocks": "",
      // The server's own template, which escapes every value it prints.
      dangerouslySetInnerHTML: { __html: html },
    });
  }

  plugins.registerPlugin("yayloyalty-redeem", {
    scope: "woocommerce-checkout",
    render: function () {
      return element.createElement(
        checkout.ExperimentalDiscountsMeta,
        null,
        element.createElement(Card, null),
      );
    },
  });

  window.yayloyaltyRedeemTransport = function (points) {
    return checkout.extensionCartUpdate({
      namespace: "yayloyalty-redeem",
      data: { points: points },
    });
  };

  if (config.code && config.label) {
    checkout.registerCheckoutFilters("yayloyalty", {
      coupons: function (coupons) {
        if (!Array.isArray(coupons)) {
          return coupons;
        }

        return coupons.map(function (coupon) {
          return coupon && coupon.code === config.code
            ? Object.assign({}, coupon, { label: config.label })
            : coupon;
        });
      },
    });
  }
})();
