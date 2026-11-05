jQuery(document).ready(function () {
  var wooActive = window.yayRecommended.woo_active;
  if (wooActive === '') {
    getData('featured');
  } else {
    getData('woocommerce');
  }

  jQuery('.yay-recommended-plugins-layout .plugin-install-tab').on('click', function () {
    if (jQuery(this).children().hasClass('current') === false) {
      getData(jQuery(this).attr('data-tab'));
      jQuery('.yay-recommended-plugins-layout .plugin-install-tab a').each(function () {
        if (jQuery(this).hasClass('current') === true) {
          jQuery(this).removeClass('current');
        }
      });
      jQuery(this).children().addClass('current');
    }
  });
  jQuery('body').on(
    'click',
    '.yay-recommended-plugins-layout .plugin-action-buttons .activate-now',
    function () {
      var file = jQuery(this).attr('data-plugin-file');
      activatePlugin(jQuery(this), file);
    }
  );
  jQuery('body').on(
    'click',
    '.yay-recommended-plugins-layout .plugin-action-buttons .install-now',
    function () {
      if (!jQuery(this).hasClass('updating-message')) {
        var plugin = jQuery(this).attr('data-install-url');
        installPlugin(jQuery(this), plugin);
      }
    }
  );
  jQuery('body').on(
    'click',
    '.yay-recommended-plugins-layout .plugin-action-buttons .update-now',
    function () {
      if (!jQuery(this).hasClass('updating-message')) {
        var plugin = jQuery(this).attr('data-plugin');
        updatePlugin(jQuery(this), plugin);
      }
    }
  );
});

function getData(tab) {
  var loadingHtml = '<div class="loading-content"><span class="spinner is-active"></span></div>';
  jQuery('.yay-recommended-plugins-layout #the-list').html(loadingHtml);
  jQuery('.yay-recommended-plugins-layout .plugin-install-tab').addClass('loading-process');
  jQuery.ajax({
    url: yayRecommended.admin_ajax,
    type: 'POST',
    data: { action: 'yay_recommended_get_plugin_data', tab: tab, nonce: yayRecommended.nonce },
    success: function (response) {
      if (response.success === true) {
        jQuery('.yay-recommended-plugins-layout #the-list').html(response.data.html);
        jQuery('.yay-recommended-plugins-layout .plugin-install-tab').removeClass('loading-process');
      }
    },
  });
}

function activatePlugin(element, file) {
  element.addClass('button-disabled').attr('disabled', 'disabled').text('Processing...');
  jQuery.ajax({
    url: yayRecommended.admin_ajax,
    type: 'POST',
    data: { action: 'yay_recommended_activate_plugin', file: file, nonce: yayRecommended.nonce },
    success: function (response) {
      if (response.success === true) {
        var pluginStatus = jQuery(".yay-recommended-plugins-layout .plugin-status-inactive[data-plugin-file='" + file + "']");
        pluginStatus.text('Active').addClass('plugin-status-active').removeClass('plugin-status-inactive');
        element.removeClass('active-now').text('Activated');
      } else {
        element.removeClass('button-disabled').prop('disabled', false).text('Activated');
      }
    },
  });
}

function installPlugin(element, plugin) {
  var otherButtons = jQuery('.yay-recommended-plugins-layout .plugin-action-buttons .install-now').not(element);
  otherButtons.prop('disabled', true).addClass('button-disabled');
  element.removeClass('button-primary').addClass('updating-message').text('Installing...');
  jQuery.ajax({
    url: yayRecommended.admin_ajax,
    type: 'POST',
    data: { action: 'yay_recommended_upgrade_plugin', type: 'install', plugin: plugin, nonce: yayRecommended.nonce },
    success: function (response) {
      if (response.success === true) {
        element.removeClass('updating-message').addClass('updated-message installed button-disabled').attr('disabled', 'disabled').removeAttr('data-install-url').text('Installed!');
        setTimeout(function () {
          var pluginStatus = jQuery(".yay-recommended-plugins-layout .plugin-status-not-install[data-plugin-url='" + plugin + "']");
          pluginStatus.text('Active').addClass('plugin-status-active').removeClass('plugin-status-not-install').removeAttr('data-install-url');
          element.removeClass('install-now updated-message installed').text('Activated').removeAttr('aria-label');
        }, 500);
      } else {
        element.removeClass('updating-message').addClass('button-primary').text('Install Now');
      }
      otherButtons.prop('disabled', false).removeClass('button-disabled');
    },
    error: function () {
      element.removeClass('updating-message').addClass('button-primary').text('Install Now');
      otherButtons.prop('disabled', false).removeClass('button-disabled');
    },
  });
}

function updatePlugin(element, plugin) {
  element.addClass('updating-message').text('Updating...');
  jQuery.ajax({
    url: yayRecommended.admin_ajax,
    type: 'POST',
    data: { action: 'yay_recommended_upgrade_plugin', type: 'update', plugin: plugin, nonce: yayRecommended.nonce },
    success: function (response) {
      if (response.success === true) {
        element.removeClass('updating-message').addClass('updated-message button-disabled').attr('disabled', 'disabled').text('Updated!');
        if (response.data.active === false) {
          var pluginStatus = jQuery(".yay-recommended-plugins-layout .plugin-status-inactive[data-plugin-file='" + plugin + "']");
          pluginStatus.text('Active').addClass('plugin-status-active').removeClass('plugin-status-inactive').removeAttr('data-plugin-file');
        }
      } else {
        element.removeClass('updating-message').text('Update Now');
      }
    },
  });
}
