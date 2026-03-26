jQuery(function ($) {
  function asInt(val, fallback) {
    var n = parseInt(val, 10);
    return Number.isNaN(n) ? fallback : n;   // 0 stays 0; only NaN falls back
  }

  function updatePreview() {
    var text   = $('#mhfgfwc_text_color').val()   || '#ffffff';
    var bg     = $('#mhfgfwc_bg_color').val()     || '#000000';
    var border = $('#mhfgfwc_border_color').val() || bg;

    var bsize  = asInt($('#mhfgfwc_border_size').val(), 2);
    var fsize  = asInt($('#mhfgfwc_button_font_size').val(), 15);
    var radius = asInt($('#mhfgfwc_radius').val(), 25);
    var cartHeadingSize = asInt($('#mhfgfwc_cart_heading_font_size').val(), 15);
    var checkoutToggleSize = asInt($('#mhfgfwc_checkout_toggle_font_size').val(), 18);
    var cartHeadingText = $('#mhfgfwc_cart_heading_text').val() || 'Choose Your Free Gift';
    var checkoutToggleText = $('#mhfgfwc_checkout_toggle_text').val() || 'Free Gift';
    var addText = $('#mhfgfwc_add_button_text').val() || 'Add Gift';
    var removeText = $('#mhfgfwc_remove_button_text').val() || 'Remove Gift';

    var style = 'color:' + text +
                ';background:' + bg +
                ';border:' + bsize + 'px solid ' + border +
                ';font-size:' + fsize + 'px' +
                ';border-radius:' + radius + 'px;';

    $('.mhfgfwc-preview-btn').attr('style', style);
    $('.mhfgfwc-preview-btn--add').text(addText);
    $('.mhfgfwc-preview-btn--remove').text(removeText);
    $('.mhfgfwc-preview-heading').text(cartHeadingText).css('font-size', cartHeadingSize + 'px');
    $('.mhfgfwc-preview-toggle').text(checkoutToggleText).css('font-size', checkoutToggleSize + 'px');
  }

  // init color pickers
  $('.mhfgfwc-color').wpColorPicker({
    change: updatePreview,
    clear:  updatePreview
  });

  // keep preview in sync
  $('#mhfgfwc_border_size, #mhfgfwc_button_font_size, #mhfgfwc_radius, #mhfgfwc_cart_heading_font_size, #mhfgfwc_checkout_toggle_font_size').on('input change', updatePreview);
  $('#mhfgfwc_text_color, #mhfgfwc_bg_color, #mhfgfwc_border_color, #mhfgfwc_cart_heading_text, #mhfgfwc_checkout_toggle_text, #mhfgfwc_add_button_text, #mhfgfwc_remove_button_text').on('input', updatePreview);

  updatePreview();
});
