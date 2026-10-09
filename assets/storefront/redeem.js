/**
 * The points card's behaviour: the slider and the number field are one control, and the discount beside
 * them is previewed as either moves. The preview is arithmetic only; the server's answer replaces it.
 * Apply and Remove send the points (0 removes) and show the card the server answers with.
 *
 * Plain script, no build step. Markup and data come from includes/Engine/Storefront/templates/redeem-card.php.
 * Listeners are delegated from the document, because the classic checkout replaces the card's DOM on
 * every order-review refresh and the blocks re-render it.
 */
(function () {
  "use strict";

  function rootOf(element) {
    return element.closest("[data-yl-redeem]");
  }

  function numberAttr(element, name) {
    return parseFloat(element.getAttribute(name)) || 0;
  }

  /** A typed number as the slider would take it: inside min…max, on a multiple of the step. */
  function clamp(range, raw) {
    var min = numberAttr(range, "min");
    var max = numberAttr(range, "max");
    var step = numberAttr(range, "step") || 1;
    var points = Math.floor(Number(raw));

    if (!isFinite(points)) {
      return min;
    }
    points = Math.min(max, points);
    points -= points % step;

    return Math.max(min, points);
  }

  /** Money as LauncherCopy::money() prints it: the store's format, and no decimals on a whole amount. */
  function money(root, amount) {
    var decimals =
      amount === Math.floor(amount) ? 0 : numberAttr(root, "data-decimals");
    var parts = amount.toFixed(decimals).split(".");

    parts[0] = parts[0].replace(
      /\B(?=(\d{3})+(?!\d))/g,
      root.getAttribute("data-thousand-sep") || "",
    );

    return (root.getAttribute("data-price-format") || "%1$s%2$s")
      .replace("%1$s", root.getAttribute("data-symbol") || "")
      .replace(
        "%2$s",
        parts.join(root.getAttribute("data-decimal-sep") || "."),
      );
  }

  /** What the points buy, floored to the currency's smallest unit: the arithmetic of Clamp::value_of(). */
  function showOff(root, points) {
    var off = root.querySelector("[data-yl-off]");
    var rate = numberAttr(root, "data-rate");

    if (!off || rate <= 0) {
      return;
    }

    var scale = Math.pow(10, numberAttr(root, "data-decimals"));
    var value =
      Math.floor(Math.round((points / rate) * scale * 1e6) / 1e6) / scale;

    off.textContent = (root.getAttribute("data-off-template") || "%s").replace(
      "%s",
      money(root, value),
    );
  }

  /** Disables every control and returns the function that puts them back as they were. */
  function lock(root) {
    var controls = Array.prototype.filter.call(
      root.querySelectorAll("input, button"),
      function (control) {
        return !control.disabled;
      },
    );

    root.setAttribute("data-busy", "1");
    controls.forEach(function (control) {
      control.disabled = true;
    });

    return function unlock() {
      root.removeAttribute("data-busy");
      controls.forEach(function (control) {
        control.disabled = false;
      });
    };
  }

  /** The card the server answered with takes this one's place; an empty answer means nothing renders now. */
  function replaceCard(root, html) {
    var holder = document.createElement("template");

    holder.innerHTML = String(html || "").trim();
    root.replaceWith(holder.content);
  }

  /** WooCommerce's own refresh of the totals around the card; on any other page the new card is the whole update. */
  function refreshTotals() {
    var jQuery = window.jQuery;

    if (!jQuery) {
      return;
    }
    if (document.querySelector("form.checkout")) {
      jQuery(document.body).trigger("update_checkout");
    } else if (document.querySelector(".woocommerce-cart-form")) {
      jQuery(document.body).trigger("wc_update_cart");
    }
  }

  function send(root, points) {
    if (root.hasAttribute("data-busy")) {
      return;
    }

    var unlock = lock(root);
    var transport = window.yayloyaltyRedeemTransport;

    // On the Cart and Checkout blocks the cart store takes the request and the block redraws the card.
    // An answer with the same HTML redraws nothing, so the controls are put back either way.
    if (
      root.closest("[data-yl-redeem-blocks]") &&
      typeof transport === "function"
    ) {
      Promise.resolve(transport(points)).then(unlock, unlock);
      return;
    }

    var body = new URLSearchParams();
    body.append("points", String(points));
    body.append("security", root.getAttribute("data-nonce") || "");

    window
      .fetch(root.getAttribute("data-endpoint"), {
        method: "POST",
        credentials: "same-origin",
        body: body,
      })
      .then(function (response) {
        return response.json();
      })
      .then(function (json) {
        if (!json || !json.success || !json.data) {
          throw new Error("refused");
        }
        replaceCard(root, json.data.html);
        refreshTotals();
      })
      .catch(unlock);
  }

  /**
   * The typed number as the slider takes it: moves the slider and the preview, and rewrites the field
   * when asked. Returns the points, or null on a card without the form.
   */
  function sync(root, rewriteField) {
    var range = root.querySelector("[data-yl-range]");
    var field = root.querySelector("[data-yl-points]");

    if (!range || !field) {
      return null;
    }

    var points = clamp(range, field.value);

    range.value = String(points);
    if (rewriteField) {
      field.value = String(points);
    }
    showOff(root, points);

    return points;
  }

  function apply(root) {
    var field = root.querySelector("[data-yl-points]");
    var points = field && !field.disabled ? sync(root, true) : null;

    if (points !== null) {
      send(root, points);
    }
  }

  document.addEventListener("click", function (event) {
    var target = event.target instanceof Element ? event.target : null;
    var button = target
      ? target.closest("[data-yl-apply], [data-yl-remove]")
      : null;
    var root = button ? rootOf(button) : null;

    if (!root) {
      return;
    }
    if (button.matches("[data-yl-remove]")) {
      send(root, 0);
    } else {
      apply(root);
    }
  });

  // The card can sit inside the checkout form: Enter applies the points and must never place the order.
  document.addEventListener("keydown", function (event) {
    var target = event.target;

    if (
      event.key !== "Enter" ||
      !(target instanceof Element) ||
      !target.matches("[data-yl-points]")
    ) {
      return;
    }
    event.preventDefault();

    var root = rootOf(target);

    if (root) {
      apply(root);
    }
  });

  document.addEventListener("input", function (event) {
    var target = event.target;
    var root = target instanceof Element ? rootOf(target) : null;

    if (!root) {
      return;
    }

    if (target.matches("[data-yl-range]")) {
      root.querySelector("[data-yl-points]").value = target.value;
      showOff(root, Number(target.value));
    } else if (target.matches("[data-yl-points]")) {
      // The field keeps what was typed until it is left; the slider and the preview follow the clamped number.
      sync(root, false);
    }
  });

  document.addEventListener("change", function (event) {
    var target = event.target;
    var root = target instanceof Element ? rootOf(target) : null;

    if (root && target.matches("[data-yl-points]")) {
      sync(root, true);
    }
  });
})();
