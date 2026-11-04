/**
 * The order screen's "Loyalty points" box: grants the points a completed order missed through
 * POST yayloyalty/v1/orders/{id}/grant-points, then reloads so the order note shows.
 *
 * Plain script, no build step. The markup and strings come from includes/Engine/Admin/OrderMissingPoints.php.
 */
(function () {
  "use strict";

  var config = window.yayloyaltyMissingPoints;
  var box = document.querySelector(".yayloyalty-missing-points");

  if (!config || !box || !window.wp || !window.wp.apiFetch || !box.querySelector(".yayloyalty-missing-points__grant")) {
    return;
  }

  var button = box.querySelector(".yayloyalty-missing-points__grant");
  var pointsInput = box.querySelector("#yayloyalty-grant-points");
  var noteInput = box.querySelector("#yayloyalty-grant-note");
  var notifyInput = box.querySelector("#yayloyalty-grant-notify");
  var error = box.querySelector(".yayloyalty-missing-points__error");

  function showError(message) {
    error.textContent = message;
    error.hidden = false;
  }

  // The inputs sit inside the order's own form: Enter would save the order instead of granting.
  [pointsInput, noteInput].forEach(function (input) {
    input.addEventListener("keydown", function (event) {
      if (event.key === "Enter") {
        event.preventDefault();
        button.click();
      }
    });
  });

  button.addEventListener("click", function () {
    var points = Number(pointsInput.value);

    error.hidden = true;

    if (!Number.isInteger(points) || points < 1) {
      showError(config.i18n.invalid);
      return;
    }

    button.disabled = true;

    window.wp
      .apiFetch({
        path: "/yayloyalty/v1/orders/" + box.dataset.orderId + "/grant-points",
        method: "POST",
        data: { points: points, note: noteInput.value, notify: notifyInput.checked },
      })
      .then(function (response) {
        box.textContent = config.i18n.granted.replace("%s", response.points_text);
        window.location.reload();
      })
      .catch(function (failure) {
        button.disabled = false;
        showError((failure && failure.message) || config.i18n.failed);
      });
  });
})();
