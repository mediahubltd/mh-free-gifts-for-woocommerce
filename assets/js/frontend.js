/* global jQuery, mhfgfwcFrontend, wc_cart_fragments_params, wc_checkout_params */
jQuery(function ($) {

  /* -----------------------------------------------------------
   * Helpers
   * ----------------------------------------------------------- */
  function refreshAfterChange() {
    if (typeof wc_cart_fragments_params !== 'undefined') {
      $(document.body).trigger('wc_fragment_refresh');
    }

    if ($('.woocommerce-cart-form').length) {
      setTimeout(() => window.location.reload(), 250);
      return;
    }

    if ($('form.woocommerce-checkout').length) {
      $(document.body).trigger('update_checkout');
      return;
    }
  }

  function lockButton($btn, label) {
    $btn.prop('disabled', true)
        .addClass('is-loading')
        .data('orig', $btn.text())
        .text(label);
  }

  function unlockButton($btn, label) {
    const orig = $btn.data('orig');
    $btn.prop('disabled', false)
        .removeClass('is-loading')
        .text(label || orig || $btn.text());
  }

  /* -----------------------------------------------------------
   * Toggle UI
   * ----------------------------------------------------------- */
  function initToggleBehaviour(context) {
    const $root = context ? $(context) : $(document);

    $root.find('.woocommerce-form-coupon-toggle').each(function () {
      var $wrap = $(this);
      var $toggle = $wrap.find('.mhfgfwc-show-gifts-toggle');
      var $icon = $toggle.find('.mhfgfwc-toggle-icon');
      var $panel = $wrap.next('.mhfgfwc-gift-section');

      if (!$icon.length) {
        $icon = $('<span class="mhfgfwc-toggle-icon dashicons dashicons-arrow-down-alt2"></span>');
        $toggle.append($icon);
      }

      if ($panel.length && !$wrap.hasClass('mhfgfwc-init')) {
        $panel.hide();
        $wrap.removeClass('open');
        $toggle.removeClass('opened');
        $wrap.addClass('mhfgfwc-init');
      }
    });
  }

  initToggleBehaviour();

  $(document).on('click', '.mhfgfwc-show-gifts-toggle', function (e) {
    e.preventDefault();

    var $link = $(this);
    var $wrap = $link.closest('.woocommerce-form-coupon-toggle');
    var $panel = $wrap.next('.mhfgfwc-gift-section');
    var $icon = $link.find('.mhfgfwc-toggle-icon');

    if (!$panel.length) return;

    $panel.stop(true, true).slideToggle(200);
    $wrap.toggleClass('open');
    $link.toggleClass('opened');

    if ($link.hasClass('opened')) {
      $icon.removeClass('dashicons-arrow-down-alt2')
           .addClass('dashicons-arrow-up-alt2');
    } else {
      $icon.removeClass('dashicons-arrow-up-alt2')
           .addClass('dashicons-arrow-down-alt2');
    }
  });

  /* -----------------------------------------------------------
   * Add Gift
   * ----------------------------------------------------------- */
  $(document).on('click', '.mhfgfwc-add-gift', function (e) {
    e.preventDefault();
    var $btn = $(this);

    if ($btn.is(':disabled')) return;

    var pid = parseInt($btn.data('product'), 10) || 0;
    var rid = parseInt($btn.data('rule'), 10) || 0;

    lockButton($btn, mhfgfwcFrontend.i18n.adding);

    $.post(mhfgfwcFrontend.ajax_url_add, {
      nonce: mhfgfwcFrontend.nonce,
      product: pid,
      rule: rid
    })
      .done(function (response) {
        if (response && response.success) {
          if ($('form.woocommerce-checkout').length) {
            $(document.body).trigger('update_checkout');
          } else {
            refreshAfterChange();
          }
        } else {
          alert(response?.data?.message || mhfgfwcFrontend.i18n.ajax_error);
          unlockButton($btn, mhfgfwcFrontend.i18n.add);
        }
      })
      .fail(function () {
        alert(mhfgfwcFrontend.i18n.ajax_error);
        unlockButton($btn, mhfgfwcFrontend.i18n.add);
      });
  });

  /* -----------------------------------------------------------
   * Remove Gift
   * ----------------------------------------------------------- */
  $(document).on('click', '.mhfgfwc-remove-gift', function (e) {
    e.preventDefault();
    var $btn = $(this);

    if ($btn.is(':disabled')) return;

    var itemKey = $btn.data('item-key');

    lockButton($btn, mhfgfwcFrontend.i18n.removing);

    $.post(mhfgfwcFrontend.ajax_url_remove, {
      nonce: mhfgfwcFrontend.nonce,
      item_key: itemKey
    })
      .done(function (response) {
        if (response && response.success) {
          if ($('form.woocommerce-checkout').length) {
            $(document.body).trigger('update_checkout');
          } else {
            refreshAfterChange();
          }
        } else {
          alert(response?.data?.message || mhfgfwcFrontend.i18n.ajax_error);
          unlockButton($btn, mhfgfwcFrontend.i18n.remove);
        }
      })
      .fail(function () {
        alert(mhfgfwcFrontend.i18n.ajax_error);
        unlockButton($btn, mhfgfwcFrontend.i18n.remove);
      });
  });

  /* -----------------------------------------------------------
   * Checkout Refresh → Reload the Gift HTML
   * ----------------------------------------------------------- */
  $(document.body).on('updated_checkout', function () {

    var $section = $('.mhfgfwc-gift-section');
    if (!$section.length) return;

    $.ajax({
      type: 'POST',
      url: mhfgfwcFrontend.ajax_url_refresh,
      data: {
        nonce: mhfgfwcFrontend.nonce
      },
      success: function (res) {
        if (res && res.success && res.data && res.data.html) {

          // Keep wrapper, replace inner content only
          $section.fadeTo(120, 0.3, function () {

            $section.html(res.data.html);

            // Remove greyed-out class if any
            $section.removeClass('mhfgfwc-disabled-rule');

            // Re-initialise toggle UI + button bindings
            initToggleBehaviour($section);

            // If you have a global event binder, call it
            if (typeof window.mhfgfwcBindGiftEvents === 'function') {
              window.mhfgfwcBindGiftEvents($section[0]);
            }

            $section.fadeTo(120, 1);
          });
        }
      }
    });

  });

});
