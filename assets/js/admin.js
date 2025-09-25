jQuery(function($) {
  /**
   * Initialize SelectWoo on product and user selector fields
   */
  function initWcfgSelects() {
    $('.wcfg-product-select, .wcfg-user-select').each(function() {
      var $select = $(this);
      if ($select.hasClass('select2-hidden-accessible')) return;

      // Use WooCommerce's SelectWoo instead of select2
      $select.selectWoo({
        placeholder: $select.data('placeholder') || ($select.hasClass('wcfg-user-select') ? 'Search for a user...' : 'Search for a product...'),
        minimumInputLength: 2,
        ajax: {
          url: wcfgAdmin.ajax_url,
          dataType: 'json',
          delay: 250,
          data: function(params) {
            return {
              action: $select.hasClass('wcfg-user-select') ? 'wcfg_search_users' : 'wcfg_search_products',
              nonce:  wcfgAdmin.nonce,
              q:      params.term
            };
          },
          processResults: function(response) {
            if (response.success && Array.isArray(response.data)) {
              return { results: response.data };
            }
            return { results: [] };
          }
        }
      });
    });
  }

  // On initial load
  initWcfgSelects();

  // Re-initialize on focus or when new rows are added
  $(document).on('focus', '.wcfg-product-select, .wcfg-user-select', initWcfgSelects);
  $(document).on('wcfg_after_add_row', initWcfgSelects);

  // Handle status toggle inline change
  $(document).on('change', '.wcfg-status-toggle', function() {
    var $chk = $(this);
    var status = $chk.prop('checked') ? 1 : 0;
    $.post(wcfgAdmin.ajax_url, {
      action:  'wcfg_toggle_status',
      nonce:   wcfgAdmin.nonce,
      rule_id: $chk.data('rule-id'),
      status:  status
    });
  });

  // Initialize datepicker/timepicker
  $('.wcfg-datepicker').datetimepicker({
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
