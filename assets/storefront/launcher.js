/**
 * Storefront launcher behaviour: open/close the panel, keep focus inside it, let the shopper drag the
 * button to another spot (kept for later pages), and load "Recent activity" once, after the first open
 * (the balance is already in the page; history is a second request so a shopper checking their points
 * never waits on it).
 *
 * Plain script, no build step. Markup and data come from includes/Engine/Storefront/templates/.
 * Class names here are scanned by the storefront Tailwind build, so they must stay literal.
 */
(function () {
  "use strict";

  var DRAG_CLOSE_PX = 80;
  // Below this a press on the button is a click, not a move.
  var MOVE_THRESHOLD_PX = 6;
  var FOCUSABLE =
    'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])';

  function init(root) {
    var trigger = root.querySelector("[data-yl-open]");
    var panel = root.querySelector(".yl-launcher-panel");
    var overlay = root.querySelector(".yl-launcher-overlay");
    var isActivityLoaded = false;
    var isSeenSent = false;
    var closeTimer = null;

    function isOpen() {
      return panel.getAttribute("data-state") === "open";
    }

    function open() {
      window.clearTimeout(closeTimer);
      panel.hidden = false;
      overlay.hidden = false;
      document.documentElement.classList.add("yl-launcher-open");
      trigger.setAttribute("aria-expanded", "true");
      window.requestAnimationFrame(function () {
        panel.setAttribute("data-state", "open");
        overlay.setAttribute("data-state", "open");
        var first = panel.querySelector(FOCUSABLE);
        if (first) {
          first.focus();
        }
      });
      if (!isSeenSent) {
        isSeenSent = true;
        markSeen(root);
      }
      if (!isActivityLoaded) {
        isActivityLoaded = true;
        // A failed load is retried on the next open rather than left broken for the page's lifetime.
        loadActivity(root, function () {
          isActivityLoaded = false;
        });
      }
    }

    function close() {
      if (!isOpen()) {
        return;
      }
      panel.setAttribute("data-state", "closed");
      overlay.setAttribute("data-state", "closed");
      panel.style.transform = "";
      trigger.setAttribute("aria-expanded", "false");
      document.documentElement.classList.remove("yl-launcher-open");
      closeTimer = window.setTimeout(function () {
        panel.hidden = true;
        overlay.hidden = true;
      }, longestTransitionMs(panel));
      trigger.focus();
    }

    function trapFocus(event) {
      var items = panel.querySelectorAll(FOCUSABLE);
      if (!items.length) {
        return;
      }
      var first = items[0];
      var last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }

    var wasMoved = bindMove(root, trigger);

    trigger.addEventListener("click", function () {
      if (wasMoved()) {
        return;
      }
      if (isOpen()) {
        close();
      } else {
        open();
      }
    });

    Array.prototype.forEach.call(
      root.querySelectorAll("[data-yl-close]"),
      function (el) {
        el.addEventListener("click", close);
      },
    );

    panel.addEventListener("keydown", function (event) {
      if (event.key === "Escape") {
        close();
      } else if (event.key === "Tab") {
        trapFocus(event);
      }
    });

    bindDragToClose(panel, root.querySelector("[data-yl-handle]"), close);
  }

  /**
   * How long the panel's close transition runs, read from its computed style: the CSS owns the
   * durations (desktop fade, mobile slide, none under reduced motion), so the panel is hidden only
   * once it has finished moving.
   */
  function longestTransitionMs(element) {
    var style = window.getComputedStyle(element);
    var durations = style.transitionDuration.split(",");
    var delays = style.transitionDelay.split(",");
    var longestMs = 0;
    durations.forEach(function (duration, index) {
      var totalSeconds =
        parseFloat(duration) + parseFloat(delays[index] || delays[0] || "0");
      longestMs = Math.max(longestMs, totalSeconds * 1000);
    });
    return longestMs;
  }

  /**
   * The bottom sheet's grab bar and header: drag down past DRAG_CLOSE_PX to dismiss, otherwise snap back.
   * Only while the bar is drawn (mobile), and never from the ✕, whose click the capture would swallow.
   */
  function bindDragToClose(panel, handle, close) {
    if (!handle) {
      return;
    }
    var bar = handle.querySelector(".yl-launcher-handle");
    var startY = null;

    handle.addEventListener("pointerdown", function (event) {
      if (
        !bar ||
        !bar.getClientRects().length ||
        event.target.closest("[data-yl-close]")
      ) {
        return;
      }
      startY = event.clientY;
      handle.setPointerCapture(event.pointerId);
    });
    handle.addEventListener("pointermove", function (event) {
      if (startY === null) {
        return;
      }
      var dragDistancePx = Math.max(0, event.clientY - startY);
      panel.style.transform = "translateY(" + dragDistancePx + "px)";
    });
    function release(event, canClose) {
      if (startY === null) {
        return;
      }
      var dragDistancePx = event.clientY - startY;
      startY = null;
      panel.style.transform = "";
      if (canClose && dragDistancePx > DRAG_CLOSE_PX) {
        close();
      }
    }
    handle.addEventListener("pointerup", function (event) {
      release(event, true);
    });
    // The browser took the gesture (scroll, system swipe): snap back, never close.
    handle.addEventListener("pointercancel", function (event) {
      release(event, false);
    });
  }

  /**
   * The shopper can drag the button anywhere inside the launcher's offset; launcher-spot.js keeps the spot
   * for later pages and opens the panel on the button's side.
   * Returns a check the click handler asks, so the click that ends a drag does not open the panel.
   */
  function bindMove(root, trigger) {
    var spot = window.yayloyaltyLauncherSpot;
    var start = null;
    var isMoving = false;
    var wasMoved = false;

    if (!spot) {
      return function () {
        return false;
      };
    }

    trigger.addEventListener("pointerdown", function (event) {
      if (event.button !== 0) {
        return;
      }
      start = {
        x: event.clientX,
        y: event.clientY,
        rect: trigger.getBoundingClientRect(),
        area: moveArea(root, trigger),
      };
      isMoving = false;
      wasMoved = false;
    });
    trigger.addEventListener("pointermove", function (event) {
      if (start === null) {
        return;
      }
      var dx = event.clientX - start.x;
      var dy = event.clientY - start.y;
      if (!isMoving) {
        if (
          Math.abs(dx) < MOVE_THRESHOLD_PX &&
          Math.abs(dy) < MOVE_THRESHOLD_PX
        ) {
          return;
        }
        isMoving = true;
        trigger.setPointerCapture(event.pointerId);
        root.classList.add("yl-launcher-moving");
      }
      dx = clamp(
        dx,
        start.area.left - start.rect.left,
        start.area.left + start.area.width - start.rect.left,
      );
      dy = clamp(
        dy,
        start.area.top - start.rect.top,
        start.area.top + start.area.height - start.rect.top,
      );
      trigger.style.transform = "translate(" + dx + "px, " + dy + "px)";
    });
    function release() {
      if (start === null) {
        return;
      }
      var area = start.area;
      start = null;
      if (!isMoving) {
        return;
      }
      isMoving = false;
      wasMoved = true;
      root.classList.remove("yl-launcher-moving");
      var rect = trigger.getBoundingClientRect();
      var position = {
        x:
          area.width > 0
            ? clamp((rect.left - area.left) / area.width, 0, 1)
            : 0,
        y:
          area.height > 0
            ? clamp((rect.top - area.top) / area.height, 0, 1)
            : 0,
      };
      trigger.style.transform = "";
      spot.apply(root, position);
      spot.save(position);
    }
    trigger.addEventListener("pointerup", release);
    trigger.addEventListener("pointercancel", release);

    return function () {
      var moved = wasMoved;
      wasMoved = false;
      return moved;
    };
  }

  /**
   * Where the button's top-left corner may go: the viewport inset by --yl-offset, less the button and the
   * pill under it. The same box the CSS spreads --yl-x and --yl-y across.
   */
  function moveArea(root, trigger) {
    var offsetPx =
      parseFloat(
        window.getComputedStyle(root).getPropertyValue("--yl-offset"),
      ) || 0;
    var pillPx = parseFloat(
      window.getComputedStyle(trigger).marginBottom || "0",
    );
    var viewport = document.documentElement;
    return {
      left: offsetPx,
      top: offsetPx,
      width: viewport.clientWidth - 2 * offsetPx - trigger.offsetWidth,
      height:
        viewport.clientHeight - 2 * offsetPx - trigger.offsetHeight - pillPx,
    };
  }

  function clamp(value, min, max) {
    return Math.max(min, Math.min(value, max));
  }

  /**
   * Tells the server the panel was opened, so its "You earned 40 new points." is spent from the next page on
   * (NewPointsNotice). Fire and forget: a failed call only leaves the notice up one page longer.
   */
  function markSeen(root) {
    var url = root.getAttribute("data-seen-url");
    if (!url) {
      return;
    }
    window
      .fetch(url, {
        method: "POST",
        credentials: "same-origin",
        headers: { "X-WP-Nonce": root.getAttribute("data-nonce") || "" },
      })
      .catch(function () {});
  }

  function loadActivity(root, onFail) {
    var url = root.getAttribute("data-activity-url");
    var section = root.querySelector("[data-yl-activity]");
    if (!url || !section) {
      return;
    }
    var list = section.querySelector("[data-yl-activity-list]");
    var rowTemplate = section.querySelector("template[data-yl-activity-row]");
    var error = section.querySelector("[data-yl-activity-error]");
    error.hidden = true;

    window
      .fetch(url, {
        credentials: "same-origin",
        headers: { "X-WP-Nonce": root.getAttribute("data-nonce") || "" },
      })
      .then(function (response) {
        if (!response.ok) {
          throw new Error("HTTP " + response.status);
        }
        return response.json();
      })
      .then(function (data) {
        var items = (data && data.items) || [];
        list.textContent = "";
        // The empty state is its own box, so the bordered list steps aside for it.
        list.hidden = !items.length;
        if (!items.length) {
          section.querySelector("[data-yl-activity-empty]").hidden = false;
          return;
        }
        items.forEach(function (item) {
          var row = rowTemplate.content.firstElementChild.cloneNode(true);
          row.querySelector("[data-yl-label]").textContent = item.label;
          row.querySelector("[data-yl-meta]").textContent = item.meta;
          var delta = row.querySelector("[data-yl-delta]");
          delta.textContent = item.delta;
          delta.classList.add("yayloyalty-account__badge--" + item.tone);
          list.appendChild(row);
        });
      })
      .catch(function () {
        list.textContent = "";
        list.hidden = true;
        error.hidden = false;
        onFail();
      });
  }

  function boot() {
    Array.prototype.forEach.call(
      document.querySelectorAll(".yayloyalty-launcher"),
      init,
    );
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
