
$(function() {
    console.log('test cclaire !!!!');
    $('input[data-payment*=alma-method]').on('click', function() {
        console.log('click click !!!!');
        $(this).parents('.item').next('.content').show();
    });
});