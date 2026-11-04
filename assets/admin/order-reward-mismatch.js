/**
 * The order screen's "Loyalty rewards" box: repairs one reward through
 * POST yayloyalty/v1/orders/{id}/reconcile-reward, then reloads so the box and the order note are current.
 *
 * Plain script, no build step. The markup and strings come from includes/Engine/Admin/OrderRewardMismatch.php.
 */
(function () {
  "use strict";

  var config = window.yayloyaltyRewardMismatch;
  var box = document.querySelector(".yayloyalty-reward-mismatch");

  if (!config || !box || !window.wp || !window.wp.apiFetch) {
    return;
  }

  var error = box.querySelector(".yayloyalty-reward-mismatch__error");
  var buttons = box.querySelectorAll(".yayloyalty-reward-mismatch__repair");

  function setDisabled(disabled) {
    buttons.forEach(function (button) {
      button.disabled = disabled;
    });
  }

  buttons.forEach(function (button) {
    button.addEventListener("click", function () {
      error.hidden = true;
      setDisabled(true);

      window.wp
        .apiFetch({
          path: "/yayloyalty/v1/orders/" + box.dataset.orderId + "/reconcile-reward",
          method: "POST",
          data: { code: button.dataset.code },
        })
        .then(function () {
          window.location.reload();
        })
        .catch(function (failure) {
          setDisabled(false);
          error.textContent = (failure && failure.message) || config.i18n.failed;
          error.hidden = false;
        });
    });
  });
})();
