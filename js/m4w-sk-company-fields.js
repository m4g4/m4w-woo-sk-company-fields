jQuery(function ($) {
    var fieldSelectors = [
        '#billing_company_field',
        '#billing_ico_field',
        '#billing_dic_field',
        '#billing_ic_dph_field',
    ];

    var inputSelectors = [
        '#billing_company',
        '#billing_ico',
        '#billing_dic',
        '#billing_ic_dph',
    ];

    function resetFieldValidation(inputSelector) {
        var $parent = $(inputSelector).closest('.form-row');
        $parent.find('.m4w-validation-error').remove();
        $parent.removeClass('woocommerce-invalid woocommerce-invalid-required-field woocommerce-validated');
    }

    function setFieldValid(inputSelector) {
        var $parent = $(inputSelector).closest('.form-row');
        $parent.find('.m4w-validation-error').remove();
        $parent.removeClass('woocommerce-invalid woocommerce-invalid-required-field')
               .addClass('woocommerce-validated');
    }

    function setFieldInvalid(inputSelector, message) {
        var $input = $(inputSelector);
        var $parent = $input.closest('.form-row');
        $parent.find('.m4w-validation-error').remove();
        $parent.removeClass('woocommerce-validated')
               .addClass('woocommerce-invalid');
        $('<span>', {
            class: 'm4w-validation-error',
            text: message
        }).insertAfter($input);
    }

    function isCompanyChecked() {
        return $('#billing_company_customer').is(':checked');
    }

    function toggle(show) {
        $.each(fieldSelectors, function (i, selector) {
            if (show) {
                $(selector).slideDown();
            } else {
                $(selector).slideUp();
                $(inputSelectors[i]).val('');
                resetFieldValidation(inputSelectors[i]);
            }
        });
    }

    toggle(isCompanyChecked());

    $('#billing_company_customer').on('change', function () {
        toggle(this.checked);
    });

    function validateIco(ico) {
        ico = ico.replace(/\s/g, '');
        if (!/^\d{8}$/.test(ico)) {
            return 'IČO musí mať presne 8 číslic.';
        }
        var weights = [8, 7, 6, 5, 4, 3, 2];
        var sum = 0;
        for (var i = 0; i < 7; i++) {
            sum += parseInt(ico[i], 10) * weights[i];
        }
        var remainder = sum % 11;
        var check;
        if (remainder === 0) {
            check = 1;
        } else if (remainder === 1) {
            return 'Zadané IČO nie je platné (neplatný kontrolný súčet).';
        } else {
            check = 11 - remainder;
        }
        if (parseInt(ico[7], 10) !== check) {
            return 'Zadané IČO nie je platné (neplatný kontrolný súčet).';
        }
        return '';
    }

    function validateDic(dic) {
        dic = dic.replace(/\s/g, '');
        if (dic === '') return '';
        if (!/^\d{10}$/.test(dic)) {
            return 'DIČ musí mať presne 10 číslic.';
        }
        return '';
    }

    function validateIcDph(icDph) {
        icDph = icDph.replace(/\s/g, '').toUpperCase();
        if (icDph === '') return '';
        if (/^SK\d{10}$/.test(icDph)) return '';
        if (/^CZ\d{8,10}$/.test(icDph)) return '';
        return 'IČ DPH musí byť v tvare SK za ktorým nasleduje 10 číslic (napr. SK1234567890).';
    }

    function validateField(inputSelector, validator) {
        var $input = $(inputSelector);
        if ($input.length === 0) return true;
        var val = $input.val() || '';

        if (!isCompanyChecked()) {
            resetFieldValidation(inputSelector);
            return true;
        }

        if (val.trim() === '') {
            if (inputSelector === '#billing_ico') {
                setFieldInvalid(inputSelector, 'Ak nakupujete na firmu, zadajte aspoň IČO.');
                return false;
            }
            resetFieldValidation(inputSelector);
            return true;
        }

        var msg = validator(val);
        if (msg) {
            setFieldInvalid(inputSelector, msg);
            return false;
        }

        setFieldValid(inputSelector);
        return true;
    }

    var validationRules = [
        { input: '#billing_ico', validator: validateIco },
        { input: '#billing_dic', validator: validateDic },
        { input: '#billing_ic_dph', validator: validateIcDph },
    ];

    $.each(validationRules, function (i, rule) {
        $(rule.input).on('blur change', function () {
            validateField(rule.input, rule.validator);
        });
        $(rule.input).on('input', function () {
            if ($(this).closest('.form-row').hasClass('woocommerce-invalid')) {
                validateField(rule.input, rule.validator);
            }
        });
    });

    $(document.body).on('checkout_place_order', function () {
        var allValid = true;
        $.each(validationRules, function (i, rule) {
            if (!validateField(rule.input, rule.validator)) {
                allValid = false;
            }
        });
        return allValid;
    });

    $(document.body).on('updated_checkout checkout_error', function () {
        if (isCompanyChecked()) {
            $.each(validationRules, function (i, rule) {
                $(rule.input).trigger('blur');
            });
        } else {
            $.each(validationRules, function (i, rule) {
                resetFieldValidation(rule.input);
            });
        }
    });
});
