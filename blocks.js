(function () {
    'use strict';

    if (!window.wc || !window.wc.wcBlocksRegistry || !window.wp || !window.wp.element) {
        return;
    }

    var registry = window.wc.wcBlocksRegistry;
    var element = window.wp.element;
    var decodeEntities = window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities : function (value) {
        return value;
    };
    function register(gatewayId, fallbackTitle, fallbackNetwork) {
        var settings = window.wc.wcSettings && window.wc.wcSettings.getSetting
            ? window.wc.wcSettings.getSetting(gatewayId + '_data', {})
            : {};
        if (!settings.active) {
            return;
        }
        var title = decodeEntities(settings.title || fallbackTitle);

        function Label() {
        return element.createElement(
            'span',
            { className: 'psdu-block-label' },
            element.createElement('span', { className: 'psdu-block-label__token', 'aria-hidden': 'true' }, settings.tokenMark || 'T'),
            element.createElement('span', null, title),
            element.createElement('small', null, settings.shortNetwork || fallbackNetwork)
        );
        }

        function Content() {
        return element.createElement(
            'div',
            { className: 'psdu-block-content' },
            element.createElement('p', null, decodeEntities(settings.description || 'Pay with USDT on the selected network.')),
            element.createElement('strong', null, decodeEntities(settings.network || fallbackNetwork)),
            element.createElement('span', null, decodeEntities(settings.details || 'The exact amount, address and QR code will appear after the order is confirmed.'))
        );
        }

        registry.registerPaymentMethod({
            name: gatewayId,
            label: element.createElement(Label, null),
            ariaLabel: title,
            content: element.createElement(Content, null),
            edit: element.createElement(Content, null),
            canMakePayment: function () { return true; },
            supports: { features: settings.supports || ['products'] }
        });
    }

    register('partsyhub_direct_usdt', 'USDT (TRON / TRC20)', 'TRC20');
    register('haoj1e_direct_usdt_erc20', 'USDT (Ethereum / ERC20)', 'ERC20');
}());
