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
    var radius = asInt($('#mhfgfwc_radius').val(), 25);

    var style = 'color:' + text +
                ';background:' + bg +
                ';border:' + bsize + 'px solid ' + border +
                ';border-radius:' + radius + 'px;';

    $('.mhfgfwc-preview-btn').attr('style', style);
  }

  // init color pickers
  $('.mhfgfwc-color').wpColorPicker({
    change: updatePreview,
    clear:  updatePreview
  });

  // keep preview in sync
  $('#mhfgfwc_border_size, #mhfgfwc_radius').on('input change', updatePreview);
  $('#mhfgfwc_text_color, #mhfgfwc_bg_color, #mhfgfwc_border_color').on('input', updatePreview);

  updatePreview();
});

