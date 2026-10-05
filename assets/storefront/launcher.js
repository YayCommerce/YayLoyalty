/**
 * Storefront launcher behaviour: open/close the panel, keep focus inside it, and load "Recent activity"
 * once, after the first open (the balance is already in the page; history is a second request so a
 * shopper checking their points never waits on it). Near the page bottom the button folds to its icon,
 * so it covers less of the footer.
 *
 * Plain script, no build step. Markup and data come from includes/Engine/Storefront/templates/.
 * Class names here are scanned by the storefront Tailwind build, so they must stay literal.
 */
(function () {
  "use strict";

  var DRAG_CLOSE_PX = 80;
  var COLLAPSE_WITHIN_PX = 160;
  var SCROLL_DEADBAND_PX = 4;
  var FOCUSABLE =
    'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])';

  function init(root) {
    var trigger = root.querySelector("[data-yl-open]");
    var panel = root.querySelector(".yl-launcher-panel");
    var overlay = root.querySelector(".yl-launcher-overlay");
    var isActivityLoaded = false;
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

    trigger.addEventListener("click", function () {
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
    if (trigger.hasAttribute("data-yl-collapsible")) {
      bindCollapseOnScroll(trigger);
    }
  }

  /**
   * Folds the button to its icon when the shopper scrolls down to within COLLAPSE_WITHIN_PX of the
   * page bottom, and unfolds it on the first scroll up. Only collapsing looks at the distance, so
   * resting on that line cannot flip the state back and forth. The CSS owns the sizes and the motion.
   */
  function bindCollapseOnScroll(trigger) {
    var page = document.documentElement;
    var lastY = window.scrollY;
    var isFramePending = false;

    function update() {
      isFramePending = false;
      var maxY = Math.max(0, page.scrollHeight - window.innerHeight);
      // Clamped: the bounce back from an overscroll past the bottom is not the shopper scrolling up.
      var y = Math.min(Math.max(window.scrollY, 0), maxY);
      var delta = y - lastY;
      // lastY is left alone here so a slow scroll still adds up to a direction.
      if (Math.abs(delta) < SCROLL_DEADBAND_PX) {
        return;
      }
      lastY = y;
      if (delta < 0) {
        trigger.removeAttribute("data-collapsed");
      } else if (
        maxY - y <= COLLAPSE_WITHIN_PX &&
        // A page this short is near its bottom from the first pixel; it would fold on any scroll.
        maxY >= 2 * COLLAPSE_WITHIN_PX
      ) {
        trigger.setAttribute("data-collapsed", "");
      }
    }

    window.addEventListener(
      "scroll",
      function () {
        if (!isFramePending) {
          isFramePending = true;
          window.requestAnimationFrame(update);
        }
      },
      { passive: true },
    );
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

  /** The bottom sheet's handle: drag down past DRAG_CLOSE_PX to dismiss, otherwise snap back. */
  function bindDragToClose(panel, handle, close) {
    if (!handle) {
      return;
    }
    var startY = null;

    handle.addEventListener("pointerdown", function (event) {
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
        if (!items.length) {
          section.querySelector("[data-yl-activity-empty]").hidden = false;
          return;
        }
        items.forEach(function (item) {
          var row = rowTemplate.content.firstElementChild.cloneNode(true);
          row.querySelector("[data-yl-label]").textContent = item.label;
          row.querySelector("[data-yl-meta]").textContent = item.meta;
          row.querySelector("[data-yl-delta]").textContent = item.delta;
          list.appendChild(row);
        });
      })
      .catch(function () {
        list.textContent = "";
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
