(function($){
  function applyPreview(){
    var text   = $('#mhfgfwc_text_color').val()   || '#ffffff';
    var bg     = $('#mhfgfwc_bg_color').val()     || '#0071a1';
    var bcolor = $('#mhfgfwc_border_color').val() || '#0071a1';
    var bsize  = parseInt($('#mhfgfwc_border_size').val(), 10);
    var rad    = parseInt($('#mhfgfwc_radius').val(), 10);

    if (isNaN(bsize)) bsize = 2;
    if (isNaN(rad))   rad   = 25;

    $('#mhfgfwc-preview .mhfgfwc-preview-btn').attr('style',
      'color:'+text+';background:'+bg+';border:'+bsize+'px solid '+bcolor+';border-radius:'+rad+'px;'
    );
  }

  $(document).on('input change', '#mhfgfwc_text_color,#mhfgfwc_bg_color,#mhfgfwc_border_color,#mhfgfwc_border_size,#mhfgfwc_radius', applyPreview);
  $(applyPreview);
})(jQuery);
