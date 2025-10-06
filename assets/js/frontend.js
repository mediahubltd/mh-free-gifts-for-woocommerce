jQuery(function($){
  // --- Toggle setup (no CSS injection; dashicons are enqueued in PHP) ---
  $('.woocommerce-form-coupon-toggle').each(function(){
    var $wrap   = $(this);
    var $toggle = $wrap.find('.mhfgfwc-show-gifts-toggle');
    var $icon   = $toggle.find('.mhfgfwc-toggle-icon');
    var $panel  = $wrap.next('.mhfgfwc-gift-section');

    // Ensure icon exists and is "down" initially
    if (!$icon.length) {
      $icon = $('<span class="mhfgfwc-toggle-icon dashicons dashicons-arrow-down-alt2"></span>');
      $toggle.append($icon);
    } else {
      $icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
    }

    // Start collapsed
    if ($panel.length) {
      $panel.hide();
      $wrap.removeClass('open');
      $toggle.removeClass('opened');
    }
  });

  // Toggle open/close (like Woo coupon)
  $(document).on('click', '.mhfgfwc-show-gifts-toggle', function(e){
    e.preventDefault();
    var $link  = $(this);
    var $wrap  = $link.closest('.woocommerce-form-coupon-toggle');
    var $panel = $wrap.next('.mhfgfwc-gift-section');
    var $icon  = $link.find('.mhfgfwc-toggle-icon');

    if (!$panel.length) return;

    $panel.stop(true, true).slideToggle(200);
    $wrap.toggleClass('open');
    $link.toggleClass('opened');

    if ($link.hasClass('opened')) {
      $icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
    } else {
      $icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
    }
  });

  // --- Add gift ---
  $(document).on('click', '.mhfgfwc-add-gift', function(e){
    e.preventDefault();
    var $btn = $(this);
    var pid  = $btn.data('product');
    var rid  = $btn.data('rule');

    $btn.prop('disabled', true).text(mhfgfwcFrontend.i18n.adding);

    $.post(mhfgfwcFrontend.ajax_url_add, {
      nonce:   mhfgfwcFrontend.nonce,
      product: pid,
      rule:    rid
    }).done(function(response){
      if (response.success) {
        location.reload();
      } else {
        alert(response.data.message || mhfgfwcFrontend.i18n.ajax_error);
        $btn.prop('disabled', false).text(mhfgfwcFrontend.i18n.add);
      }
    }).fail(function(){
      alert(mhfgfwcFrontend.i18n.ajax_error);
      $btn.prop('disabled', false).text(mhfgfwcFrontend.i18n.add);
    });
  });

  // --- Remove gift ---
  $(document).on('click', '.mhfgfwc-remove-gift', function(e){
    e.preventDefault();
    var $btn    = $(this);
    var itemKey = $btn.data('item-key');

    $btn.prop('disabled', true).text(mhfgfwcFrontend.i18n.removing);

    $.post(mhfgfwcFrontend.ajax_url_remove, {
      nonce:    mhfgfwcFrontend.nonce,
      item_key: itemKey
    }).done(function(response){
      if (response.success) {
        location.reload();
      } else {
        alert(response.data.message || mhfgfwcFrontend.i18n.ajax_error);
        $btn.prop('disabled', false).text(mhfgfwcFrontend.i18n.remove);
      }
    }).fail(function(){
      alert(mhfgfwcFrontend.i18n.ajax_error);
      $btn.prop('disabled', false).text(mhfgfwcFrontend.i18n.remove);
    });
  });
});

// Render gifts UI into a given container element.
// You can replace this inner function with your existing renderer if you have one.
(function () {
	if (typeof window === 'undefined') return;

	function renderGiftsInto(container) {
		if (!container) return;

		// If you already append the same markup elsewhere, you can
		// refactor that code into a function and call it here instead.
		// Minimal, generic rendering (adjust classes & HTML to match your current UI):
		container.innerHTML = `
			<div class="mhfgfwc-gifts-grid" data-mhfgfwc-root>
				<!-- The same grid/items your classic cart renders.
					 If your PHP currently prints the grid, you can also expose
					 an endpoint that returns the HTML and fetch() it here. -->
			</div>
		`;

		// If your existing code binds events to ".mhfgfwc-gifts-grid", call the binder now:
		if (typeof window.mhfgfwcBindGiftEvents === 'function') {
			window.mhfgfwcBindGiftEvents(container.querySelector('[data-mhfgfwc-root]'));
		}
	}

	// Listen for a request from blocks.js to mount into a specific container.
	document.addEventListener('mhfgfwc:mount', function (ev) {
		try {
			const container = ev && ev.detail && ev.detail.container ? ev.detail.container : null;
			if (container) renderGiftsInto(container);
		} catch (e) {
			// swallow to avoid console noise for merchants
			// console.error('[mhfgfwc] mount failed', e);
		}
	});
})();