/**
 * The order screen's "Loyalty points" box: its button gives the order's missing points back through
 * POST yayloyalty/v1/orders/{id}/points, shows a refusal in the box's error line, and reloads on success
 * so the box renders its new state.
 *
 * Plain script, no build step. The markup comes from includes/Engine/Admin/OrderPointsMetabox.php;
 * wp-api-fetch sends the REST nonce in wp-admin.
 */
(function () {
  "use strict";

  var BOX = ".yayloyalty-order-points";

  document.addEventListener("click", function (event) {
    var button = event.target.closest(BOX + " button");

    if (!button || !window.wp || !window.wp.apiFetch) {
      return;
    }

    event.preventDefault();

    var box = button.closest(BOX);
    var error = box.querySelector(".yayloyalty-order-points__error");
    var note = box.querySelector('[name="yayloyalty_note"]');
    var points = box.querySelector('[name="yayloyalty_points"]');
    var data = { note: note ? note.value : "" };

    // An empty field is left out, so the route answers with its own "enter the points" error.
    if (points && points.value !== "") {
      data.points = parseInt(points.value, 10);
    }

    if (error) {
      error.hidden = true;
      error.textContent = "";
    }

    button.disabled = true;

    window.wp
      .apiFetch({
        path: "/yayloyalty/v1/orders/" + box.dataset.orderId + "/points",
        method: "POST",
        data: data,
      })
      .then(function () {
        window.location.reload();
      })
      .catch(function (failure) {
        if (error) {
          error.textContent = failure && failure.message ? failure.message : String(failure);
          error.hidden = false;
        }

        button.disabled = false;
      });
  });
})();
