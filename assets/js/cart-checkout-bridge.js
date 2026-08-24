/* global URL */
(function () {
    'use strict';

    var cartBlockCheckoutSelector = '.wc-block-cart__submit-button';
    var cartCheckoutMarker = 'whop_cart_checkout';

    document.addEventListener(
        'click',
        function (event) {
            if (
                event.defaultPrevented ||
                event.button !== 0 ||
                event.metaKey ||
                event.ctrlKey ||
                event.shiftKey ||
                event.altKey
            ) {
                return;
            }

            if (!(event.target instanceof Element)) {
                return;
            }

            var checkoutLink = event.target.closest(cartBlockCheckoutSelector);

            if (!checkoutLink || checkoutLink.tagName !== 'A') {
                return;
            }

            var target = checkoutLink.getAttribute('target');

            if (target && target !== '_self') {
                return;
            }

            var destination;

            try {
                destination = new URL(checkoutLink.href, window.location.href);
            } catch (error) {
                return;
            }

            if (destination.origin !== window.location.origin) {
                return;
            }

            if (destination.searchParams.get(cartCheckoutMarker) === '1') {
                return;
            }

            destination.searchParams.set(cartCheckoutMarker, '1');
            event.preventDefault();
            window.location.assign(destination.toString());
        },
        true
    );
}());
