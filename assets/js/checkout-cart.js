(function ($) {
    'use strict';

    var timer;

    function getCheckoutCart() {
        return $('.megurio-checkout-cart').first();
    }

    function schedule(key, qty) {
        var $cart = getCheckoutCart();
        var ajaxUrl = $cart.data('ajax-url');
        var nonce = $cart.data('nonce');

        if (!ajaxUrl || !nonce || !key) {
            return;
        }

        clearTimeout(timer);
        timer = setTimeout(function () {
            $.post(ajaxUrl, {
                action: 'megurio_update_cart_qty',
                nonce: nonce,
                key: key,
                qty: qty
            }, function (res) {
                if (res.success) {
                    window.location.reload();
                }
            });
        }, 500);
    }

    $(document).on('click', '.megurio-qty-minus', function () {
        var $input = $(this).next('.megurio-qty-input');
        var val = Math.max(1, parseInt($input.val(), 10) - 1);

        $input.val(val);
        schedule($input.data('key'), val);
    });

    $(document).on('click', '.megurio-qty-plus', function () {
        var $input = $(this).prev('.megurio-qty-input');
        var val = parseInt($input.val(), 10) + 1;

        $input.val(val);
        schedule($input.data('key'), val);
    });

    $(document).on('change', '.megurio-qty-input', function () {
        var $input = $(this);
        var val = parseInt($input.val(), 10);

        if (isNaN(val) || val < 1) {
            return;
        }

        schedule($input.data('key'), val);
    });
}(jQuery));
