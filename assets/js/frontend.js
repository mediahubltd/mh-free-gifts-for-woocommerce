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

  function ensureToggleIcon($toggle) {
    var $icon = $toggle.find('.mhfgfwc-toggle-icon');

    if (!$icon.length) {
      $icon = $('<span class="mhfgfwc-toggle-icon dashicons dashicons-arrow-down-alt2"></span>');
      $toggle.append($icon);
    }

    return $icon;
  }

  function applyToggleState($wrap, isOpen) {
    var $toggle = $wrap.find('.mhfgfwc-show-gifts-toggle');
    var $panel = $wrap.next('.mhfgfwc-gift-section');
    var $icon = ensureToggleIcon($toggle);

    if (!$panel.length) {
      return;
    }

    if (isOpen) {
      $panel.show().removeClass('mhfgfwc-hidden');
      $wrap.addClass('open');
      $toggle.addClass('opened');
      $icon.removeClass('dashicons-arrow-down-alt2')
           .addClass('dashicons-arrow-up-alt2');
      return;
    }

    $panel.hide().addClass('mhfgfwc-hidden');
    $wrap.removeClass('open');
    $toggle.removeClass('opened');
    $icon.removeClass('dashicons-arrow-up-alt2')
         .addClass('dashicons-arrow-down-alt2');
  }

  /* -----------------------------------------------------------
   * Toggle UI
   * ----------------------------------------------------------- */
  function initToggleBehaviour(context) {
    const $context = context ? $(context) : $(document);
    const $wraps = $context.filter('.woocommerce-form-coupon-toggle')
      .add($context.find('.woocommerce-form-coupon-toggle'));

    $wraps.each(function () {
      var $wrap = $(this);
      var $toggle = $wrap.find('.mhfgfwc-show-gifts-toggle');
      var $panel = $wrap.next('.mhfgfwc-gift-section');

      ensureToggleIcon($toggle);

      if ($panel.length) {
        applyToggleState($wrap, false);
      }

      if (!$wrap.hasClass('mhfgfwc-init')) {
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
    var willOpen = !$link.hasClass('opened');

    if (!$panel.length) return;

    if (willOpen) {
      $panel.stop(true, true)
        .removeClass('mhfgfwc-hidden')
        .slideDown(200);
      applyToggleState($wrap, true);
    } else {
      $panel.stop(true, true).slideUp(200, function () {
        $panel.addClass('mhfgfwc-hidden');
      });
      $wrap.removeClass('open');
      $link.removeClass('opened');
      ensureToggleIcon($link)
        .removeClass('dashicons-arrow-up-alt2')
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
        nonce: mhfgfwcFrontend.nonce,
        context: $('form.woocommerce-checkout').length ? 'checkout' : 'cart'
      },
      success: function (res) {
        if (res && res.success && res.data && typeof res.data.html !== 'undefined') {
          var html = $.trim(res.data.html);
          var $toggleWrap = $section.prev('.woocommerce-form-coupon-toggle');
          var wasOpen = $toggleWrap.hasClass('open') ||
            $toggleWrap.find('.mhfgfwc-show-gifts-toggle').hasClass('opened') ||
            $section.is(':visible');

          if (!html) {
            $section.empty().hide();
            $section.addClass('mhfgfwc-hidden');
            applyToggleState($toggleWrap, false);
            $toggleWrap.hide();
            return;
          }

          $toggleWrap.show();

          function refreshGiftSection() {
            $section.html(html);

            // Remove greyed-out class if any
            $section.removeClass('mhfgfwc-disabled-rule').css('opacity', 1);

            // Re-initialise toggle UI + button bindings
            initToggleBehaviour($toggleWrap);
            applyToggleState($toggleWrap, wasOpen);

            // If you have a global event binder, call it
            if (typeof window.mhfgfwcBindGiftEvents === 'function') {
              window.mhfgfwcBindGiftEvents($section[0]);
            }
          }

          // Only animate if the panel is already open; fadeTo() would
          // otherwise reveal a collapsed section before we re-sync it.
          if (wasOpen) {
            $section.stop(true, true).fadeTo(120, 0.3, function () {
              refreshGiftSection();
              $section.fadeTo(120, 1);
            });
            return;
          }

          refreshGiftSection();
        }
      }
    });

  });

});
