/**
 * The points card on the Cart and Checkout blocks: a slot fill that shows the card the server rendered
 * (the `card` field of the plugin's Store API cart extension), so the blocks and the classic pages share one template.
 * Apply, Remove and the rewards' Apply/Update stay in redeem.js; here they are routed through the cart store,
 * which redraws the card. The totals rows of reward coupons are relabelled with the reward's name.
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

  /** Sends the card's `{ points }` or `{ rewards }` through the cart store, which answers with the new cart. */
  window.yayloyaltyRedeemTransport = function (payload) {
    return checkout.extensionCartUpdate({
      namespace: config.callback,
      data: payload,
    });
  };

  /** The applied rewards from the cart store: WooCommerce runs the `coupons` filter without the cart's extensions. */
  function appliedRewards() {
    var store = window.wp.data && window.wp.data.select("wc/store/cart");
    var cart = store && store.getCartData ? store.getCartData() : null;
    var data = cart && cart.extensions && cart.extensions[config.namespace];

    return data && Array.isArray(data.rewards) ? data.rewards : [];
  }

  /** A totals row reads the reward's name, or the slider's label, never the virtual coupon's code. */
  checkout.registerCheckoutFilters("yayloyalty", {
    coupons: function (coupons) {
      if (!Array.isArray(coupons)) {
        return coupons;
      }

      var rewards = appliedRewards();

      return coupons.map(function (coupon) {
        var reward =
          coupon &&
          rewards.find(function (entry) {
            return entry && entry.code === coupon.code;
          });
        var label =
          coupon && config.code && coupon.code === config.code
            ? config.label
            : reward && reward.label;

        return label ? Object.assign({}, coupon, { label: label }) : coupon;
      });
    },
  });
})();
