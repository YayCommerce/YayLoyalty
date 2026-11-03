/**
 * The order screen's "Loyalty points" box: its button runs the order's award again through
 * POST yayloyalty/v1/orders/{id}/points (no body: the award decides the points), shows a refusal in the
 * box's error line, and reloads on success so the box renders its new state.
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
    if (error) {
      error.hidden = true;
      error.firstElementChild.textContent = "";
    }

    button.disabled = true;

    window.wp
      .apiFetch({
        path: "/yayloyalty/v1/orders/" + box.dataset.orderId + "/points",
        method: "POST",
      })
      .then(function () {
        window.location.reload();
      })
      .catch(function (failure) {
        if (error) {
          error.firstElementChild.textContent = failure && failure.message ? failure.message : String(failure);
          error.hidden = false;
        }

        button.disabled = false;
      });
  });
})();
