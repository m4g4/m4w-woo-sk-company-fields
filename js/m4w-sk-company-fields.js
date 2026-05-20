jQuery(function ($) {
    var fields = [
        '#billing_company_field',
        '#billing_ico_field',
        '#billing_dic_field',
        '#billing_ic_dph_field',
    ];

    var inputs = [
        '#billing_company',
        '#billing_ico',
        '#billing_dic',
        '#billing_ic_dph',
    ];

    function toggle(show) {
        $.each(fields, function (i, selector) {
            if (show) {
                $(selector).slideDown();
            } else {
                $(selector).slideUp();
                $(inputs[i]).val('');
            }
        });
    }

    toggle($('#billing_company_customer').is(':checked'));

    $('#billing_company_customer').on('change', function () {
        toggle(this.checked);
    });
});
