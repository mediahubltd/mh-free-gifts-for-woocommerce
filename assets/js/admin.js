jQuery(function($) {
  /**
   * Initialize SelectWoo on product and user selector fields
   */
  function initWcfgSelects() {
      $('.mhfgfwc-product-select, .mhfgfwc-user-select').each(function () {
        var $select = $(this);
        if ($select.hasClass('select2-hidden-accessible')) return;

        $select.selectWoo({
          placeholder: $select.data('placeholder') || ($select.hasClass('mhfgfwc-user-select') ? 'Search for a user...' : 'Search for a product...'),
          minimumInputLength: 2,
          ajax: {
            url: mhfgfwcAdmin.ajax_url,
            dataType: 'json',
            delay: 250,
            data: function (params) {
              return {
                action: $select.hasClass('mhfgfwc-user-select') ? 'mhfgfwc_search_users' : 'mhfgfwc_search_products',
                nonce:  mhfgfwcAdmin.nonce,
                q:      params.term
              };
            },
            processResults: function (response) {
              return (response.success && Array.isArray(response.data)) ? { results: response.data } : { results: [] };
            }
          }
        });

        // If PHP rendered no <option selected>, ensure the widget shows empty too.
        if (!$select.find('option:selected').length) {
          $select.val(null).trigger('change.select2');
        }
      });
    }


  // On initial load
  initWcfgSelects();

  // Re-initialize on focus or when new rows are added
  $(document).on('focus', '.mhfgfwc-product-select, .mhfgfwc-user-select', initWcfgSelects);
  $(document).on('mhfgfwc_after_add_row', initWcfgSelects);

  // Handle status toggle inline change
    
    $(document).on('change', '.mhfgfwc-status-toggle', function () {
        const $cb      = $(this);
        const ruleId   = $cb.data('rule-id');
        const checked  = $cb.is(':checked');     // desired state
        const previous = !checked;               // for revert
        const payload  = {
          action:  'mhfgfwc_toggle_status',
          nonce:   (window.mhfgfwcAdmin && mhfgfwcAdmin.nonce) ? mhfgfwcAdmin.nonce : '',
          rule_id: ruleId,
          status:  checked ? 1 : 0
        };

        // lock UI
        $cb.prop('disabled', true).addClass('is-saving');

        $.ajax({
          url:    (window.mhfgfwcAdmin && mhfgfwcAdmin.ajax_url) ? mhfgfwcAdmin.ajax_url : ajaxurl,
          method: 'POST',
          data:   payload,
          dataType: 'json'
        })
        .done(function (resp) {
          if (!resp || resp.success !== true) {
            // server reported failure
            $cb.prop('checked', previous);
            window.alert('Could not update status. Please try again.');
            return;
          }
          // optional: lightweight confirmation
          if (window.wp && wp.a11y && typeof wp.a11y.speak === 'function') {
            wp.a11y.speak('Rule status saved.');
          }
        })
        .fail(function () {
          // network or server error
          $cb.prop('checked', previous);
          window.alert('Network error saving status. Please try again.');
        })
        .always(function () {
          $cb.prop('disabled', false).removeClass('is-saving');
        });
    });

  // Initialize datepicker/timepicker
  $('.mhfgfwc-datepicker').datetimepicker({
    dateFormat:   'yy-mm-dd',
    timeFormat:   'HH:mm',
    showSecond:   false,
    showMillisec: false,
    showMicrosec: false,
    showTimezone: false,
    controlType:  'select',
    oneLine:      true
  });
});
