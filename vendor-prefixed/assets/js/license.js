/**
 * License activate/update/remove via REST API.
 * Slug-agnostic: discovers all window[`${slug}LicenseData`] objects on the page
 * so one script handles all YayCommerce plugins.
 *
 * Each plugin's LicenseHandler::enqueue_license_scripts() localizes:
 *   window[`${slug}LicenseData`] = { slug, apiSettings: { restUrl, restNonce } }
 */
jQuery(document).ready(function () {
  // Discover all registered plugin license data objects
  var dataKeys = Object.keys(window).filter(function (k) {
    return k.endsWith('LicenseData') && window[k] && window[k].apiSettings;
  });

  dataKeys.forEach(function (dataKey) {
    initLicenseUI(window[dataKey]);
  });

  function initLicenseUI(data) {
    var slug = data.slug || data.apiSettings.slug || '';
    if (!slug) return;

    var REST_URL = data.apiSettings.restUrl;
    var REST_NONCE = data.apiSettings.restNonce;
    var POST_OPTIONS = {
      method: 'POST',
      headers: {
        'Content-type': 'application/json',
        'x-wp-nonce': REST_NONCE,
      },
    };

    var escapedSlug = CSS.escape(slug);
    jQuery('.yaycommerce-license-layout').on(
      'click',
      ".yaycommerce-activate-license-button[data-plugin='" + escapedSlug + "']",
      handleActivate
    );
    jQuery('.yaycommerce-license-layout').on(
      'click',
      ".yaycommerce-update-license[data-plugin='" + escapedSlug + "']",
      handleUpdate
    );
    jQuery('.yaycommerce-license-layout').on(
      'click',
      ".yaycommerce-remove-license[data-plugin='" + escapedSlug + "']",
      handleRemove
    );
    jQuery('.yaycommerce-license-layout').on(
      'click',
      '#' + jQuery.escapeSelector(slug) + '_license_card .yaycommerce-license-message .yaycommerce-license-message__close',
      function () {
        hideMessage(slug);
      }
    );

    function handleActivate(event) {
      event.preventDefault();
      clearMessages();
      var plugin = jQuery(this).data('plugin');
      beforeCallAPI(plugin, 'activate');
      hideMessage(plugin);
      var licenseKey = jQuery('#' + jQuery.escapeSelector(plugin) + '_license_input').val();

      fetch(REST_URL + '/license/activate', Object.assign({}, POST_OPTIONS, {
        body: JSON.stringify({ license_key: licenseKey, plugin: plugin }),
      }))
        .then(function (r) { return r.json(); })
        .then(function (response) {
          afterCallAPI(plugin, 'activate');
          if (response.success) {
            replaceContent(response);
          }
          showMessage(response, 'activate');
        });
    }

    function handleUpdate(event) {
      event.preventDefault();
      clearMessages();
      var plugin = jQuery(this).data('plugin');
      beforeCallAPI(plugin, 'update');

      fetch(REST_URL + '/license/update', Object.assign({}, POST_OPTIONS, {
        body: JSON.stringify({ plugin: plugin }),
      }))
        .then(function (r) { return r.json(); })
        .then(function (response) {
          afterCallAPI(plugin, 'update');
          replaceContent(response);
          showMessage(response, 'update');
        });
    }

    function handleRemove(event) {
      event.preventDefault();
      clearMessages();
      var plugin = jQuery(this).data('plugin');
      beforeCallAPI(plugin, 'remove');

      fetch(REST_URL + '/license/delete', Object.assign({}, POST_OPTIONS, {
        body: JSON.stringify({ plugin: plugin }),
      }))
        .then(function (r) { return r.json(); })
        .then(function (response) {
          afterCallAPI(plugin, 'remove');
          replaceContent(response);
          showMessage({ success: true }, 'remove');
        });
    }

    function replaceContent(data) {
      jQuery('#' + jQuery.escapeSelector(data.slug) + '_license_card').replaceWith(data.html);
      updateImportantNotice();
    }

    function updateImportantNotice() {
      // If any activate card remains on the page → still has inactive licenses
      var hasInactive = jQuery('.yaycommerce-license-settings .yaycommerce-activate-license-button').length > 0;
      var $notice = jQuery('.yaycommerce-license__important-notice').not('.yaycommerce-license__important-notice--expired');
      if (hasInactive) {
        $notice.attr('data-display', 'true').show();
      } else {
        $notice.attr('data-display', 'false').hide();
      }
    }

    function hideMessage(s) {
      jQuery('#' + jQuery.escapeSelector(s) + '_license_card .yaycommerce-license-message')
        .removeClass('show')
        .html('');
    }
  }

  function clearMessages() {
    jQuery('.message').removeClass('active');
  }

  function showMessage(data, action) {
    var id = 'message-' + action + '-' + (data.success ? 'success' : 'error');
    var el = document.getElementById(id);
    if (el) {
      el.classList.add('active');
      setTimeout(function () { el.classList.remove('active'); }, 2000);
    }
  }

  function beforeCallAPI(plugin, action) {
    var sel = "[data-plugin='" + CSS.escape(plugin) + "']";
    var btnClass = {
      activate: '.yaycommerce-activate-license-button',
      update: '.yaycommerce-update-license',
      remove: '.yaycommerce-remove-license'
    };
    // Show spinner only on the clicked button
    if (btnClass[action]) {
      jQuery(btnClass[action] + sel + ' .activate-loading').css('display', 'inline-flex');
    }
    // Disable all buttons for this plugin
    jQuery('.yaycommerce-activate-license-button' + sel).attr('disabled', true);
    jQuery('.yaycommerce-update-license' + sel).attr('disabled', true);
    jQuery('.yaycommerce-remove-license' + sel).attr('disabled', true);
  }

  function afterCallAPI(plugin, action) {
    var sel = "[data-plugin='" + CSS.escape(plugin) + "']";
    // Hide all spinners
    jQuery('.yaycommerce-activate-license-button' + sel + ' .activate-loading').hide();
    jQuery('.yaycommerce-update-license' + sel + ' .activate-loading').hide();
    jQuery('.yaycommerce-remove-license' + sel + ' .activate-loading').hide();
    // Re-enable all buttons
    jQuery('.yaycommerce-activate-license-button' + sel).attr('disabled', false);
    jQuery('.yaycommerce-update-license' + sel).attr('disabled', false);
    jQuery('.yaycommerce-remove-license' + sel).attr('disabled', false);
  }
});
