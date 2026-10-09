/**
 * The points card on the Cart and Checkout blocks: a slot fill that shows the card the server rendered
 * (the `card` field of the plugin's Store API cart extension), so the blocks and the classic pages share one template.
 * Apply and Remove stay in redeem.js; here they are routed through the cart store, which redraws the card.
 *
 * Plain script, no build step and no JSX. Its values, the Store API names included, come from
 * window.yayloyaltyRedeemBlocks (RedeemCard::enqueue_block_assets()).
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

  // Without the Store API names there is nothing to read the card from or post the points to.
  if (!config.namespace || !config.callback) {
    return;
  }

  /** WooCommerce passes every fill the cart's `extensions`. The block editor's sample cart has none of ours. */
  function Card(props) {
    var data = props.extensions && props.extensions[config.namespace];
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
      namespace: config.callback,
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
