/**
 * The points card's behaviour: one checkbox list, in one of two modes the page decides (`data-mode`).
 *
 * - Cart mode applies as it changes. Ticking the Points discount row opens its slider and applies the most it
 *   can take; unticking sends 0. A release, a changed field or Enter applies the points once, DEBOUNCE_MS after the
 *   last of them. Ticking a reward row sends every ticked reward id.
 * - Checkout mode (inside the classic checkout form or the Checkout block) holds every change as a draft: the
 *   card opens collapsed to its summary while something is applied, Change opens the list, and Update sends the
 *   points and the reward ids in one request, or just collapses when nothing changed.
 *
 * In both, the slider and the number field are one control, the discount beside them is previewed as either
 * moves, and the unaffordable reward rows follow the ticks. The preview is arithmetic only; the server's answer
 * replaces the card.
 *
 * Plain script, no build step. Markup and data come from includes/Engine/Storefront/templates/redeem-card.php.
 * Listeners are delegated from the document, because the classic checkout replaces the card's DOM on
 * every order-review refresh and the blocks re-render it.
 */
(function () {
  "use strict";

  /** How long the slider waits after the last change before it applies. */
  var DEBOUNCE_MS = 200;

  /** Where the card holds a draft instead of applying at once: the classic checkout form and the Checkout block. */
  var CHECKOUT_SELECTOR = "form.checkout, .wp-block-woocommerce-checkout";

  /** Each card's pending slider request, so a release that fires both `change` and Enter sends once. */
  var pending = new WeakMap();

  function rootOf(element) {
    return element.closest("[data-yl-redeem]");
  }

  /**
   * The last amber notice this page showed. The server sends it once, as WooCommerce sends its notices, so a card
   * the page draws again (the checkout's own refresh, a reply, the blocks' redraw) comes without it: it is put
   * back until the page is left, as a WooCommerce notice at the top of the page stays through those refreshes,
   * or until the shopper acts on the card (send()), which counts as read.
   */
  var shownAlert = null;

  function keepAlert(root) {
    var alert = root.querySelector("[data-yl-alert]");
    var list = root.querySelector("[data-yl-list]");

    if (alert) {
      shownAlert = alert.cloneNode(true);
    } else if (shownAlert && list) {
      list.parentNode.insertBefore(shownAlert.cloneNode(true), list);
    }
  }

  /**
   * Gives every card not yet marked its mode, and its notice back. The server prints one markup for every page
   * (the blocks' card is shared by Cart and Checkout), so only the page can tell the mode.
   */
  function markModes() {
    document
      .querySelectorAll("[data-yl-redeem]:not([data-mode])")
      .forEach(function (root) {
        root.setAttribute(
          "data-mode",
          root.closest(CHECKOUT_SELECTOR) ? "checkout" : "cart",
        );
        keepAlert(root);
      });
  }

  function isCheckout(root) {
    return root.getAttribute("data-mode") === "checkout";
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

  /** Fills the range's track up to its thumb: WebKit has no fill of its own, so redeem.css reads this variable. */
  function paintRange(range) {
    var min = numberAttr(range, "min");
    var max = numberAttr(range, "max");
    var fill =
      max > min ? ((Number(range.value) - min) / (max - min)) * 100 : 100;

    range.style.setProperty("--yayloyalty-redeem-fill", fill + "%");
  }

  /** Whole digits grouped in threes with the store's thousand separator, read from `data-thousand-sep`. */
  function group(element, digits) {
    return digits.replace(
      /\B(?=(\d{3})+(?!\d))/g,
      element.getAttribute("data-thousand-sep") || "",
    );
  }

  /** Money as LauncherCopy::money() prints it: the store's format, and no decimals on a whole amount. */
  function money(root, amount) {
    var decimals =
      amount === Math.floor(amount) ? 0 : numberAttr(root, "data-decimals");
    var parts = amount.toFixed(decimals).split(".");

    parts[0] = group(root, parts[0]);

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

  /**
   * Sends the points, the reward ids, or both, each on its own, and shows the card the server answers with.
   * The shopper acting on the card has read the amber notice: it goes, and the answered card does not get it back.
   *
   * @param {Element} root
   * @param {{points?: number, rewards?: number[]}} payload
   */
  function send(root, payload) {
    if (root.hasAttribute("data-busy")) {
      return;
    }

    var alert = root.querySelector("[data-yl-alert]");

    shownAlert = null;
    if (alert) {
      alert.remove();
    }

    var unlock = lock(root);
    var transport = window.yayloyaltyRedeemTransport;

    // On the Cart and Checkout blocks the cart store takes the request and the block redraws the card.
    // An answer with the same HTML redraws nothing, so the controls are put back either way.
    if (
      root.closest("[data-yl-redeem-blocks]") &&
      typeof transport === "function"
    ) {
      Promise.resolve(transport(payload)).then(unlock, unlock);
      return;
    }

    var body = new URLSearchParams();
    if ("rewards" in payload) {
      // Present and empty takes every reward off.
      body.append("rewards", payload.rewards.join(","));
    }
    if ("points" in payload) {
      body.append("points", String(payload.points));
    }
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
    paintRange(range);
    if (rewriteField) {
      field.value = String(points);
    }
    showOff(root, points);

    return points;
  }

  /** Applies the points once, DEBOUNCE_MS after the last call for this card. */
  function schedule(root, points) {
    window.clearTimeout(pending.get(root));
    pending.set(
      root,
      window.setTimeout(function () {
        pending.delete(root);
        // A card replaced meanwhile (a totals refresh) has nothing left to send for.
        if (root.isConnected) {
          send(root, { points: points });
        }
      }, DEBOUNCE_MS),
    );
  }

  /** The typed points, clamped and written back, then applied once; nothing for a disabled field. */
  function schedulePoints(root) {
    var field = root.querySelector("[data-yl-points]");
    var points = field && !field.disabled ? sync(root, true) : null;

    if (points !== null) {
      schedule(root, points);
    }
  }

  /** The list's ticked reward boxes. */
  function tickedBoxes(list) {
    return Array.prototype.filter.call(
      list.querySelectorAll("[data-yl-reward]"),
      function (box) {
        return box.checked;
      },
    );
  }

  /**
   * The reward rows after a tick: a row whose cost would take the ticked rewards past the balance is disabled
   * until other ticks drop. Mirrors the reward rows' server-side `is_short`, the source of truth, over the list's
   * `data-balance` and each row's `data-cost`; this file ships in Lite, so it names no Pro class.
   */
  function refreshShort(list) {
    var sum = tickedBoxes(list).reduce(function (total, box) {
      return total + numberAttr(box, "data-cost");
    }, 0);
    var balance = numberAttr(list, "data-balance");

    list.querySelectorAll("[data-yl-reward]").forEach(function (box) {
      if (!box.checked && !box.hasAttribute("data-denied")) {
        box.disabled = sum + numberAttr(box, "data-cost") > balance;
      }
    });
  }

  /** The ids of the card's ticked rewards; none on a card without reward rows. */
  function tickedIds(root) {
    return tickedBoxes(root).map(function (box) {
      return Number(box.value);
    });
  }

  /** The typed points as the slider takes them; null on a card without the field, or while it is disabled. */
  function typedPoints(root) {
    var range = root.querySelector("[data-yl-range]");
    var field = root.querySelector("[data-yl-points]");

    return range && field && !field.disabled ? clamp(range, field.value) : null;
  }

  /**
   * What Update sends. A ticked row whose field is disabled (the rewards leave it nothing) keeps the shopper's
   * request, so an untouched row is not lowered to its minimum.
   */
  function draftOf(root) {
    var toggle = root.querySelector("[data-yl-slider-toggle]");
    var typed = typedPoints(root);
    var points = 0;

    if (toggle && toggle.checked) {
      points = typed !== null ? typed : numberAttr(toggle, "data-requested");
    }

    return { points: points, rewards: tickedIds(root) };
  }

  /** Whether the draft differs from what the server drew: the markup's own checked and value attributes. */
  function isChanged(root) {
    var toggle = root.querySelector("[data-yl-slider-toggle]");
    var typed = typedPoints(root);

    if (toggle && toggle.checked !== toggle.defaultChecked) {
      return true;
    }
    if (
      toggle &&
      toggle.checked &&
      typed !== null &&
      typed !== Number(root.querySelector("[data-yl-points]").defaultValue)
    ) {
      return true;
    }

    return Array.prototype.some.call(
      root.querySelectorAll("[data-yl-reward]"),
      function (box) {
        return box.checked !== box.defaultChecked;
      },
    );
  }

  /** Update stays off while nothing is applied and nothing changed: there is nothing to send or collapse. */
  function refreshUpdate(root) {
    var update = root.querySelector("[data-yl-update]");

    if (update) {
      update.disabled = !root.hasAttribute("data-applied") && !isChanged(root);
    }
  }

  function setExpanded(root, isExpanded) {
    var change = root.querySelector("[data-yl-change]");

    if (isExpanded) {
      root.setAttribute("data-expanded", "");
    } else {
      root.removeAttribute("data-expanded");
    }
    if (change) {
      change.setAttribute("aria-expanded", isExpanded ? "true" : "false");
    }
  }

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

    if (!root) {
      return;
    }
    if (isCheckout(root)) {
      sync(root, true);
      refreshUpdate(root);
    } else {
      schedulePoints(root);
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
      paintRange(target);
      showOff(root, Number(target.value));
    } else if (target.matches("[data-yl-points]")) {
      // The field keeps what was typed until it is left; the slider and the preview follow the clamped number.
      sync(root, false);
    } else {
      return;
    }
    if (isCheckout(root)) {
      refreshUpdate(root);
    }
  });

  document.addEventListener("change", function (event) {
    var target = event.target;
    var root = target instanceof Element ? rootOf(target) : null;

    if (!root) {
      return;
    }

    var isDraft = isCheckout(root);

    if (target.matches("[data-yl-slider-toggle]")) {
      var body = root.querySelector("[data-yl-slider-body]");
      var field = root.querySelector("[data-yl-points]");

      if (body) {
        body.hidden = !target.checked;
      }
      if (isDraft) {
        if (target.checked) {
          sync(root, true);
        }
        refreshUpdate(root);
        return;
      }
      window.clearTimeout(pending.get(root));
      send(root, { points: target.checked && field ? Number(field.max) : 0 });
    } else if (target.matches("[data-yl-range], [data-yl-points]")) {
      if (isDraft) {
        sync(root, true);
        refreshUpdate(root);
      } else if (target.matches("[data-yl-range]")) {
        schedule(root, Number(target.value));
      } else {
        schedulePoints(root);
      }
    } else if (target.matches("[data-yl-reward]")) {
      refreshShort(target.closest("[data-yl-list]"));
      if (isDraft) {
        refreshUpdate(root);
      } else {
        send(root, { rewards: tickedIds(root) });
      }
    }
  });

  // The field's chevrons step it as the browser's spin buttons did, firing the same events so the handlers above run.
  // mousedown, not click: preventDefault keeps focus where it was, as the admin's NumberInput does.
  document.addEventListener("mousedown", function (event) {
    var target = event.target instanceof Element ? event.target : null;
    var chevron = target && target.closest("[data-yl-step]");
    var field = chevron && chevron.parentElement.parentElement.querySelector("[data-yl-points]");

    if (!field || chevron.disabled || field.disabled) {
      return;
    }
    event.preventDefault();

    if (chevron.getAttribute("data-yl-step") === "up") {
      field.stepUp();
    } else {
      field.stepDown();
    }
    field.dispatchEvent(new Event("input", { bubbles: true }));
    field.dispatchEvent(new Event("change", { bubbles: true }));
  });

  // Change opens the checkout list; Update sends the draft, or only collapses the card when nothing changed.
  document.addEventListener("click", function (event) {
    var target = event.target instanceof Element ? event.target : null;
    var button = target && target.closest("[data-yl-change], [data-yl-update]");
    var root = button ? rootOf(button) : null;

    if (!root) {
      return;
    }

    if (button.matches("[data-yl-change]")) {
      setExpanded(root, true);
    } else if (isChanged(root)) {
      // The answered card has no data-expanded: it opens collapsed while something is applied.
      send(root, draftOf(root));
    } else {
      setExpanded(root, false);
    }
  });

  markModes();
  // The classic checkout's order-review refresh, replaceCard() and the blocks' redraw all bring a card unmarked.
  new MutationObserver(markModes).observe(document.body, {
    childList: true,
    subtree: true,
  });
})();
