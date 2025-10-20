jQuery(function ($) {
  /**
   * Initialize SelectWoo on product, user, and category selector fields
   */
  function initWcfgSelects() {
    $('.mhfgfwc-product-select, .mhfgfwc-user-select, .mhfgfwc-category-select').each(function () {
      var $select = $(this);
      if ($select.hasClass('select2-hidden-accessible')) return;

      // Work out which AJAX action & placeholder to use
      var isUser      = $select.hasClass('mhfgfwc-user-select');
      var isCategory  = $select.hasClass('mhfgfwc-category-select');
      var action      = isUser
        ? 'mhfgfwc_search_users'
        : (isCategory ? 'mhfgfwc_search_categories' : 'mhfgfwc_search_products');

      var defaultPh   = isUser
        ? 'Search for a user...'
        : (isCategory ? 'Search for a category...' : 'Search for a product...');

      $select.selectWoo({
        placeholder: $select.data('placeholder') || defaultPh,
        minimumInputLength: 2,
        ajax: {
          url: (window.mhfgfwcAdmin && mhfgfwcAdmin.ajax_url) ? mhfgfwcAdmin.ajax_url : ajaxurl,
          dataType: 'json',
          delay: 250,
          data: function (params) {
            return {
              action: action,
              nonce: (window.mhfgfwcAdmin && mhfgfwcAdmin.nonce) ? mhfgfwcAdmin.nonce : '',
              q: params.term
            };
          },
          processResults: function (response) {
            return (response && response.success && Array.isArray(response.data))
              ? { results: response.data }
              : { results: [] };
          }
        },
      });

      // If PHP rendered no <option selected>, keep the widget visually empty.
      if (!$select.find('option:selected').length) {
        $select.val(null).trigger('change.select2');
      }
    });
  }

  // Initial load
  initWcfgSelects();

  // Re-init on focus or after dynamic additions
  $(document).on('focus', '.mhfgfwc-product-select, .mhfgfwc-user-select, .mhfgfwc-category-select', initWcfgSelects);
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
        $cb.prop('checked', previous);
        window.alert('Could not update status. Please try again.');
        return;
      }
      if (window.wp && wp.a11y && typeof wp.a11y.speak === 'function') {
        wp.a11y.speak('Rule status saved.');
      }
    })
    .fail(function () {
      $cb.prop('checked', previous);
      window.alert('Network error saving status. Please try again.');
    })
    .always(function () {
      $cb.prop('disabled', false).removeClass('is-saving');
    });
  });

  // Initialize datetime picker
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
