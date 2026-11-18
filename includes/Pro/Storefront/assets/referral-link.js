/**
 * My Account → Referrals: Copy link puts the link on the clipboard and says "Copied" for 2 seconds.
 * Without clipboard access it selects the text, so Ctrl/Cmd+C copies it.
 */
(function () {
  document.querySelectorAll('[data-yl-referral-link]').forEach(function (box) {
    var input = box.querySelector('input');
    var button = box.querySelector('button');
    var status = box.querySelector('[aria-live]');
    var label = button.textContent;
    var timer = 0;

    input.addEventListener('focus', function () {
      input.select();
    });

    button.addEventListener('click', function () {
      var done = function () {
        button.textContent = button.getAttribute('data-copied');
        status.textContent = button.getAttribute('data-copied');
        window.clearTimeout(timer);
        timer = window.setTimeout(function () {
          button.textContent = label;
          status.textContent = '';
        }, 2000);
      };

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(input.value).then(done, function () {
          input.focus();
        });
      } else {
        input.focus();
      }
    });
  });
})();
