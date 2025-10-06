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
  $(document).on('change', '.mhfgfwc-status-toggle', function() {
    var $chk = $(this);
    var status = $chk.prop('checked') ? 1 : 0;
    $.post(mhfgfwcAdmin.ajax_url, {
      action:  'mhfgfwc_toggle_status',
      nonce:   mhfgfwcAdmin.nonce,
      rule_id: $chk.data('rule-id'),
      status:  status
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
