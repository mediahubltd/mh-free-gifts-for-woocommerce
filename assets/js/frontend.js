jQuery(function($) {
    // Ensure Dashicons CSS is loaded on the frontend
    if ( typeof wp !== 'undefined' && wp && wp.style && ! wp.style.isRTL ) {
        $('head').append(
            '<link rel="stylesheet" href="' + wcfgFrontend.dashicons_url + '" type="text/css"/>'
        );
    }

    // Initial icon setup for the toggle link
    $('.wcfg-show-gifts-toggle').each(function() {
        var $link = $(this);
        // flex container so icon is pushed to far right
        $link.css({
            display: 'flex',
            'justify-content': 'space-between',
            'align-items': 'center'
        });
        if ( !$link.find('.wcfg-toggle-icon').length ) {
            $link.append('<span class="wcfg-toggle-icon dashicons dashicons-arrow-down-alt2"></span>');
        }
    });

    /**
     * Toggle the inline gift section under the coupon-style toggle,
     * swapping the arrow icon direction.
     */
    $(document).on('click', '.wcfg-show-gifts-toggle', function(e) {
        e.preventDefault();
        var $link    = $(this);
        var $wrapper = $link.closest('.woocommerce-form-coupon-toggle');
        var $section = $('.wcfg-gift-section');
        var $icon    = $link.find('.wcfg-toggle-icon');

        // Toggle open state
        $wrapper.toggleClass('open');
        $section.slideToggle();

        // Swap arrow direction
        $icon.toggleClass('dashicons-arrow-down dashicons-arrow-up-alt2');
    });

    /**
     * Handle adding a gift via AJAX
     */
    $(document).on('click', '.wcfg-add-gift', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var pid  = $btn.data('product');
        var rid  = $btn.data('rule');

        $btn.prop('disabled', true).text(wcfgFrontend.i18n.adding);

        $.post(wcfgFrontend.ajax_url_add, {
            nonce:   wcfgFrontend.nonce,
            product: pid,
            rule:    rid
        }).done(function(response) {
            if ( response.success ) {
                location.reload();
            } else {
                alert(response.data.message || wcfgFrontend.i18n.ajax_error);
                $btn.prop('disabled', false).text(wcfgFrontend.i18n.add);
            }
        }).fail(function() {
            alert(wcfgFrontend.i18n.ajax_error);
            $btn.prop('disabled', false).text(wcfgFrontend.i18n.add);
        });
    });

    /**
     * Handle removing a gift via AJAX
     */
    $(document).on('click', '.wcfg-remove-gift', function(e) {
        e.preventDefault();
        var $btn    = $(this);
        var itemKey = $btn.data('item-key');

        $btn.prop('disabled', true).text(wcfgFrontend.i18n.removing);

        $.post(wcfgFrontend.ajax_url_remove, {
            nonce:    wcfgFrontend.nonce,
            item_key: itemKey
        }).done(function(response) {
            if ( response.success ) {
                location.reload();
            } else {
                alert(response.data.message || wcfgFrontend.i18n.ajax_error);
                $btn.prop('disabled', false).text(wcfgFrontend.i18n.remove);
            }
        }).fail(function() {
            alert(wcfgFrontend.i18n.ajax_error);
            $btn.prop('disabled', false).text(wcfgFrontend.i18n.remove);
        });
    });

});
