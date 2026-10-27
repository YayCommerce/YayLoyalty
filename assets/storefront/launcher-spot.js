/**
 * Puts the launcher's button where the shopper last dragged it. Launcher::render() prints this inline
 * right after the launcher's markup, so the button is placed before the first paint instead of jumping
 * there from its default corner. launcher.js saves a new spot through window.yayloyaltyLauncherSpot.
 *
 * A spot is { x, y }, each 0–1 across the area the CSS gives the button ([data-spot] in
 * apps/admin/src/styles/storefront-launcher-layout.css), so it fits any viewport.
 */
(function () {
  "use strict";

  var KEY = "yayloyalty-launcher-spot";

  function isFraction(value) {
    return typeof value === "number" && value >= 0 && value <= 1;
  }

  var spot = (window.yayloyaltyLauncherSpot = {
    apply: function (root, position) {
      root.style.setProperty("--yl-x", String(position.x));
      root.style.setProperty("--yl-y", String(position.y));
      root.setAttribute("data-spot", "");
      // The panel opens in the bottom corner on the button's side.
      root.setAttribute(
        "data-position",
        position.x < 0.5 ? "bottom_left" : "bottom_right",
      );
    },
    read: function () {
      try {
        var position = JSON.parse(window.localStorage.getItem(KEY));
        if (position && isFraction(position.x) && isFraction(position.y)) {
          return position;
        }
      } catch (error) {
        // Storage blocked or the value is not ours: the default bottom-right corner stands.
      }
      return null;
    },
    save: function (position) {
      try {
        window.localStorage.setItem(KEY, JSON.stringify(position));
      } catch (error) {
        // Storage blocked: the button stays put for this page only.
      }
    },
  });

  var root = document.querySelector(".yayloyalty-launcher");
  var position = spot.read();
  if (root && position) {
    spot.apply(root, position);
  }
})();
