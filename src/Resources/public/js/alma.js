$(function() {
    $('.ui.form .item').each(function(i, e) {
        $(e).find('.ui.radio').on('click', function() {
            $('.ui.form .item .content .hidden').hide();
            if ($(e).find('input[type=radio]').is(':checked')) {
                $(e).find('.hidden').show();
            }
        });
    });
});