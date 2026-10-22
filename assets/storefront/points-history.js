/**
 * My Account points history: the filter tabs and page links swap the "Points history" section in place
 * and push their URL, so Back, Forward and a reload land on the same view. The section comes from
 * AccountHistoryAjax (the URL in the section's data-endpoint), which renders the same partial as the tab;
 * any failure falls back to following the link.
 *
 * Plain script, no build step. The section id is AccountRewards::HISTORY_ANCHOR, and the section and its
 * links carry data-page and data-filter (includes/Engine/Storefront/templates/points-history.php and
 * points-history-pages.php).
 */
(function () {
  "use strict";

  var SECTION_ID = "yayloyalty-points-history";
  var LINKS = "a.yayloyalty-account__tab, a.yayloyalty-account__page";
  var pending = null;

  /**
   * @param {{page: string, filter: string}} view
   * @param {string}  url  The page's own URL for that view, pushed or followed on failure.
   * @param {boolean} push Add a history entry (false when replaying one).
   */
  function load(view, url, push) {
    var section = document.getElementById(SECTION_ID);

    if (!section || !section.dataset.endpoint || !window.fetch) {
      window.location.href = url;
      return;
    }

    if (pending) {
      pending.abort();
    }

    var controller = typeof AbortController === "function" ? new AbortController() : null;
    var request = new URL(section.dataset.endpoint, window.location.href);

    request.searchParams.set("history_page", view.page);
    request.searchParams.set("points_type", view.filter);
    pending = controller;
    section.setAttribute("aria-busy", "true");

    fetch(request.toString(), { credentials: "same-origin", signal: controller ? controller.signal : undefined })
      .then(function (response) {
        return response.json();
      })
      .then(function (body) {
        var holder = document.createElement("div");

        holder.innerHTML = body && body.success ? body.data.html : "";

        var next = holder.querySelector("#" + SECTION_ID);

        if (!next) {
          throw new Error("No history section");
        }

        section.replaceWith(next);

        if (push) {
          window.history.pushState({ yayloyaltyHistory: view }, "", url);
        }

        // A page link sits under the table: bring the section's top back into view.
        if (next.getBoundingClientRect().top < 0) {
          next.scrollIntoView({ block: "start" });
        }
      })
      .catch(function (error) {
        if (error && error.name === "AbortError") {
          return;
        }

        window.location.href = url;
      })
      .then(function () {
        if (pending === controller) {
          pending = null;
        }
      });
  }

  /** @param {HTMLElement} el The section or a link: its data-page and data-filter. */
  function viewOf(el) {
    return { page: el.dataset.page || "1", filter: el.dataset.filter || "all" };
  }

  document.addEventListener("click", function (event) {
    var link = event.target.closest ? event.target.closest(LINKS) : null;

    if (!link || !link.dataset.page || !link.closest("#" + SECTION_ID)) {
      return;
    }

    // Let a new tab or window open as usual.
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return;
    }

    event.preventDefault();

    if (link.getAttribute("aria-current") !== "page") {
      load(viewOf(link), link.href, true);
    }
  });

  var landed = document.getElementById(SECTION_ID);

  // Record the view the shopper landed on, so going Back to it loads it again.
  if (landed) {
    window.history.replaceState({ yayloyaltyHistory: viewOf(landed) }, "");
  }

  window.addEventListener("popstate", function (event) {
    if (event.state && event.state.yayloyaltyHistory) {
      load(event.state.yayloyaltyHistory, window.location.href, false);
    }
  });
})();
