/**
 * The points card's behaviour: one checkbox list, in one of two modes the page decides (`data-mode`).
 *
 * - Cart mode applies as it changes. Ticking the Points discount row opens it and applies the most it can take
 *   (`data-max`); unticking sends 0. A changed field or Enter applies the points once, DEBOUNCE_MS after the last
 *   of them. Ticking a reward row sends every ticked reward id.
 * - Checkout mode (inside the classic checkout form or the Checkout block) holds every change as a draft: the
 *   card opens collapsed to its summary while something is applied, Change opens the list, and Update sends the
 *   points and the reward ids in one request, or just collapses when nothing changed.
 *
 * In both, the discount beside the points field is previewed as it changes, and the unaffordable reward rows follow
 * the ticks. The preview is arithmetic only; the server's answer replaces the card. The field takes whole points
 * only. Leaving it (or Enter) rounds an amount down to the step or the max and says so; an amount below the
 * minimum stays as typed, shows an error and is never sent. An auto-max row has no field: a tick asks for
 * everything and the server applies the most the cart allows.
 *
 * Plain script, no build step. Markup and data come from includes/Engine/Storefront/templates/redeem-card.php.
 * Listeners are delegated from the document, because the classic checkout replaces the card's DOM on
 * every order-review refresh and the blocks re-render it.
 */
(function () {
  "use strict";

  /** How long the points field waits after the last change before it applies. */
  var DEBOUNCE_MS = 200;

  /** Where the card holds a draft instead of applying at once: the classic checkout form and the Checkout block. */
  var CHECKOUT_SELECTOR = "form.checkout, .wp-block-woocommerce-checkout";

  /** Each card's pending points request, so a field that fires both `change` and Enter sends once. */
  var pending = new WeakMap();

  /** Keys a whole number of points never needs; type="number" would take them. */
  var NON_DIGIT_KEYS = [".", ",", "e", "E", "+", "-"];

  /** The field's attribute holding the message for each readField() issue, printed by the template. */
  var NOTE_ATTRS = {
    max: "data-max-note",
    step: "data-step-note",
    min: "data-min-error",
  };

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

  /**
   * The typed number as the field takes it: whole points on a multiple of the step, at most the max. `issue` names
   * what changed it ("max", "step"), or is "min" when it is below the minimum: then points is null, because an
   * amount below the minimum is never raised to it.
   */
  function readField(field) {
    var min = numberAttr(field, "min");
    var max = numberAttr(field, "max");
    var step = numberAttr(field, "step") || 1;
    var typed = Number(field.value);
    var whole = Math.floor(typed);

    if (field.value === "" || !isFinite(whole) || whole < min) {
      return { points: null, issue: "min" };
    }
    if (whole > max) {
      return { points: max, issue: "max" };
    }

    var points = whole - (whole % step);

    return { points: points, issue: points !== typed ? "step" : "" };
  }

  /**
   * Shows the field's message for an issue, or hides it for none: "min" reads as an error, "max" as a limit, "step"
   * as neutral. While it shows, the server's note hides: that one is about the amount last sent.
   */
  function showNote(root, field, issue) {
    var note = root.querySelector("[data-yl-field-note]");
    var serverNote = root.querySelector("[data-yl-server-note]");
    var text = issue ? field.getAttribute(NOTE_ATTRS[issue]) || "" : "";

    if (serverNote && text !== "") {
      serverNote.hidden = true;
    }
    if (!note) {
      return;
    }
    note.textContent = text;
    note.hidden = text === "";
    note.classList.toggle("yayloyalty-redeem__note--error", issue === "min");
    note.classList.toggle("yayloyalty-redeem__note--warning", issue === "max");
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
   * The typed number as the field takes it, previewed. On a commit (the field left, or Enter) the field is
   * rewritten and its message says why; while typing nothing is rewritten or said. Returns the points, or null
   * on a card without the field or below the minimum.
   */
  function sync(root, isCommit) {
    var field = root.querySelector("[data-yl-points]");

    if (!field) {
      return null;
    }

    var read = readField(field);

    if (isCommit && read.points !== null) {
      field.value = String(read.points);
    }
    showNote(root, field, isCommit ? read.issue : "");
    showOff(root, read.points || 0);

    return read.points;
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

  /**
   * The typed points, written back as the field takes them, then applied once; nothing for a disabled field or
   * below the minimum. It sends the whole number typed, not the one written back: the server clamps it the same
   * way, and its card keeps the note naming the limit that bit, where the field's own note goes with the old card.
   */
  function schedulePoints(root) {
    var field = root.querySelector("[data-yl-points]");
    var typed = field ? Math.floor(Number(field.value)) : 0;
    var points = field && !field.disabled ? sync(root, true) : null;

    if (points !== null) {
      schedule(root, Math.max(points, typed));
    } else {
      window.clearTimeout(pending.get(root));
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

  /** The typed points as the field takes them; null on a card without the field, while it is disabled, or below the minimum. */
  function typedPoints(root) {
    var field = root.querySelector("[data-yl-points]");

    return field && !field.disabled ? readField(field).points : null;
  }

  /** Whether the ticked row's field holds less than the minimum: checkout's Update waits until it is fixed. */
  function isBelowMin(root) {
    var toggle = root.querySelector("[data-yl-slider-toggle]");
    var field = root.querySelector("[data-yl-points]");

    return Boolean(
      toggle &&
      toggle.checked &&
      field &&
      !field.disabled &&
      readField(field).issue === "min",
    );
  }

  /**
   * What Update sends. A ticked row whose field is disabled (the rewards leave it nothing) keeps the shopper's
   * request, so an untouched row is not lowered to its minimum. A row without a field (auto max) asks for its max.
   */
  function draftOf(root) {
    var toggle = root.querySelector("[data-yl-slider-toggle]");
    var typed = typedPoints(root);
    var points = 0;

    if (toggle && toggle.checked) {
      points =
        typed !== null
          ? typed
          : numberAttr(toggle, "data-requested") ||
            numberAttr(toggle, "data-max");
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

  /**
   * Update stays off while nothing is applied and nothing changed (there is nothing to send or collapse), and while
   * the field is below the minimum.
   */
  function refreshUpdate(root) {
    var update = root.querySelector("[data-yl-update]");

    if (update) {
      update.disabled =
        isBelowMin(root) ||
        (!root.hasAttribute("data-applied") && !isChanged(root));
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

  // The field takes whole points only. The card can sit inside the checkout form: Enter applies the points and
  // must never place the order.
  document.addEventListener("keydown", function (event) {
    var target = event.target;

    if (!(target instanceof Element) || !target.matches("[data-yl-points]")) {
      return;
    }
    if (NON_DIGIT_KEYS.indexOf(event.key) !== -1) {
      event.preventDefault();
      return;
    }
    if (event.key !== "Enter") {
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

    if (target.matches("[data-yl-points]")) {
      // The field keeps what was typed until it is left; the preview follows what it would take.
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
      send(root, {
        points: target.checked ? numberAttr(target, "data-max") : 0,
      });
    } else if (target.matches("[data-yl-points]")) {
      if (isDraft) {
        sync(root, true);
        refreshUpdate(root);
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
    } else if (isBelowMin(root)) {
      sync(root, true);
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
