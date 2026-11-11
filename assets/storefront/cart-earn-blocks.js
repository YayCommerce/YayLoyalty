/**
 * The cart and checkout earn card on the Cart and Checkout blocks: a slot fill in the order summary that shows the
 * card the server rendered (the `html` field of the plugin's Store API cart extension), so the blocks and the classic
 * pages share one template. The Store API sends fresh extensions with every cart change, so the card follows a
 * quantity change or a removed line without a reload.
 *
 * Plain script, no build step and no JSX. The namespace comes from window.yayloyaltyCartEarnBlocks
 * (CartEarnPreview::enqueue_blocks() in includes/Engine/Storefront/CartEarnPreview.php).
 */
(function () {
  "use strict";

  var element = window.wp && window.wp.element;
  var plugins = window.wp && window.wp.plugins;
  var checkout = window.wc && window.wc.blocksCheckout;
  var config = window.yayloyaltyCartEarnBlocks || {};

  if (!element || !plugins || !checkout || !checkout.ExperimentalOrderMeta || !config.namespace) {
    return;
  }

  /** WooCommerce passes every fill the cart's `extensions`. The block editor's sample cart has none of ours. */
  function Card(props) {
    var data = props.extensions && props.extensions[config.namespace];
    var html = data && typeof data.html === "string" ? data.html : "";

    if (html === "") {
      return null;
    }

    return element.createElement("div", {
      "data-yl-cart-earn-blocks": "",
      // The server's own template, which escapes every value it prints.
      dangerouslySetInnerHTML: { __html: html },
    });
  }

  plugins.registerPlugin("yayloyalty-cart-earn", {
    scope: "woocommerce-checkout",
    render: function () {
      return element.createElement(checkout.ExperimentalOrderMeta, null, element.createElement(Card, null));
    },
  });
})();
