(function () {
    'use strict';

    if (!window.wc || !window.wc.wcBlocksRegistry || !window.wc.wcSettings || !window.wp || !window.wp.element) {
        return;
    }

    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const { getSetting } = window.wc.wcSettings;
    const { createElement } = window.wp.element;
    const paymentIds = ['whop_card', 'whop_bank_transfer', 'whop_crypto'];
    const euCountries = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'];

    const contextCurrency = (cartData) => String(
        (cartData.cartTotals && (cartData.cartTotals.currency_code || cartData.cartTotals.currencyCode)) || ''
    ).toLowerCase();

    const contextCountry = (cartData) => String(
        (cartData.billingAddress && cartData.billingAddress.country) || ''
    ).toUpperCase();

    const isEligible = (id, cartData) => {
        const currency = contextCurrency(cartData);
        const country = contextCountry(cartData);

        if (!currency) {
            return id === 'whop_card';
        }

        if (id === 'whop_crypto') {
            return currency === 'usd';
        }

        if (id === 'whop_bank_transfer') {
            return (country === 'US' && currency === 'usd') || (euCountries.includes(country) && currency === 'eur');
        }

        return id === 'whop_card';
    };

    paymentIds.forEach((id) => {
        const settings = getSetting(id + '_data', {});
        const title = settings.title || id;
        const description = settings.description || '';
        const iconUrl = settings.icon_url || '';

        registerPaymentMethod({
            name: id,
            label: createElement(
                'span',
                { className: 'whop-native-block-label' },
                createElement('span', null, title),
                iconUrl ? createElement('img', { src: iconUrl, alt: '', 'aria-hidden': true }) : null
            ),
            content: createElement('div', null, description),
            edit: createElement('div', null, description),
            ariaLabel: title,
            canMakePayment: (cartData) => isEligible(id, cartData || {}),
            supports: {
                features: settings.supports || ['products'],
            },
        });
    });
}());
