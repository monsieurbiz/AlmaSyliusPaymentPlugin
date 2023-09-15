
$(function() {
    console.log('TOTO !!!!');
    $('input[data-payment*=alma-method]').on('click', function() {
        $(this).parents('.item').next('.content').show();
    });
});