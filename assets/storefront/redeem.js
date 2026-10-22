/**
 * The points card's behaviour: the slider and the number field are one control, and the discount beside
 * them is previewed as either moves. The preview is arithmetic only; the server's answer replaces it.
 * Apply and Remove send the points (0 removes) and show the card the server answers with.
 * With a Rewards panel the card has tabs, switched here without a request, and Apply/Update sends the ticked
 * reward ids the same way. Ticking keeps the selection line and the unaffordable rows in step with the balance.
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
   * Sends `{ points }` or `{ rewards }` and shows the card the server answers with.
   *
   * @param {Element} root
   * @param {{points: number}|{rewards: number[]}} payload
   */
  function send(root, payload) {
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
      Promise.resolve(transport(payload)).then(unlock, unlock);
      return;
    }

    var body = new URLSearchParams();
    if (payload.rewards) {
      // Present and empty takes every reward off.
      body.append("rewards", payload.rewards.join(","));
    } else {
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
      send(root, { points: points });
    }
  }

  /** Points as SettingsHelper::points_text() prints them, from the template and labels the panel carries. */
  function pointsText(panel, points) {
    return (panel.getAttribute("data-points-template") || "%1$s %2$s")
      .replace("%1$s", group(panel, String(points)))
      .replace(
        "%2$s",
        panel.getAttribute(points === 1 ? "data-label-one" : "data-label-many") ||
          "",
      );
  }

  /** The panel's ticked reward boxes. */
  function tickedBoxes(panel) {
    return Array.prototype.filter.call(
      panel.querySelectorAll("[data-yl-reward]"),
      function (box) {
        return box.checked;
      },
    );
  }

  /**
   * The Rewards panel after a tick: a row whose cost would take the selection past the balance is disabled with
   * "Not enough …" until the selection drops, the line counts the selection, and Apply/Update is enabled only
   * while the ticks differ from what is applied (the boxes' `checked` attribute).
   */
  function refreshRewards(panel) {
    var boxes = Array.prototype.slice.call(
      panel.querySelectorAll("[data-yl-reward]"),
    );
    var ticked = tickedBoxes(panel);
    var sum = ticked.reduce(function (total, box) {
      return total + numberAttr(box, "data-cost");
    }, 0);
    var balance = numberAttr(panel, "data-balance");
    var isChanged = boxes.some(function (box) {
      return box.checked !== box.defaultChecked;
    });

    boxes.forEach(function (box) {
      if (box.checked || box.hasAttribute("data-denied")) {
        return;
      }

      var isShort = sum + numberAttr(box, "data-cost") > balance;
      var desc = box.parentNode.querySelector("[data-yl-desc]");

      box.disabled = isShort;
      if (desc) {
        desc.textContent = isShort
          ? panel.getAttribute("data-short-text") || ""
          : desc.getAttribute("data-text") || "";
      }
    });

    var line = panel.querySelector("[data-yl-selection]");

    if (line) {
      if (!isChanged) {
        line.textContent = line.getAttribute("data-text") || "";
      } else if (ticked.length === 0) {
        line.textContent = panel.getAttribute("data-none-text") || "";
      } else {
        line.textContent = (panel.getAttribute("data-selection-template") || "")
          .replace("%1$s", String(ticked.length))
          .replace("%2$s", pointsText(panel, sum));
      }
    }

    var button = panel.querySelector("[data-yl-apply-rewards]");

    if (button) {
      button.disabled = !isChanged;
    }
  }

  function applyRewards(root, button) {
    var panel = button.closest("[data-yl-rewards]");

    if (!panel) {
      return;
    }

    send(root, {
      rewards: tickedBoxes(panel).map(function (box) {
        return Number(box.value);
      }),
    });
  }

  /** Opens one tab of the card and hides the others' panels; no request. */
  function selectTab(root, tab) {
    root.querySelectorAll("[data-yl-tab]").forEach(function (each) {
      var isOpen = each === tab;
      var panel = root.querySelector(
        "#" + CSS.escape(each.getAttribute("aria-controls") || ""),
      );

      each.setAttribute("aria-selected", isOpen ? "true" : "false");
      each.tabIndex = isOpen ? 0 : -1;
      if (panel) {
        panel.hidden = !isOpen;
      }
    });
  }

  document.addEventListener("click", function (event) {
    var target = event.target instanceof Element ? event.target : null;
    var button = target
      ? target.closest(
          "[data-yl-apply], [data-yl-remove], [data-yl-apply-rewards], [data-yl-tab]",
        )
      : null;
    var root = button ? rootOf(button) : null;

    if (!root) {
      return;
    }
    if (button.matches("[data-yl-tab]")) {
      selectTab(root, button);
    } else if (button.matches("[data-yl-apply-rewards]")) {
      applyRewards(root, button);
    } else if (button.matches("[data-yl-remove]")) {
      send(root, { points: 0 });
    } else {
      apply(root);
    }
  });

  // The WAI-ARIA tabs pattern: the arrow keys, Home and End move to a tab and open it.
  document.addEventListener("keydown", function (event) {
    var target = event.target;

    if (!(target instanceof Element) || !target.matches("[data-yl-tab]")) {
      return;
    }

    var tabs = Array.prototype.slice.call(
      target.parentNode.querySelectorAll("[data-yl-tab]"),
    );
    var index = tabs.indexOf(target);
    var next = null;

    if (event.key === "ArrowRight") {
      next = tabs[(index + 1) % tabs.length];
    } else if (event.key === "ArrowLeft") {
      next = tabs[(index - 1 + tabs.length) % tabs.length];
    } else if (event.key === "Home") {
      next = tabs[0];
    } else if (event.key === "End") {
      next = tabs[tabs.length - 1];
    }

    var root = next ? rootOf(target) : null;

    if (root) {
      event.preventDefault();
      selectTab(root, next);
      next.focus();
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

    if (!root) {
      return;
    }
    if (target.matches("[data-yl-points]")) {
      sync(root, true);
    } else if (target.matches("[data-yl-reward]")) {
      refreshRewards(target.closest("[data-yl-rewards]"));
    }
  });
})();
