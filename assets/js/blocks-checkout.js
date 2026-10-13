(function () {
    'use strict';

    var settings = (window.wc && window.wc.wcSettings && window.wc.wcSettings.getSetting)
        ? window.wc.wcSettings.getSetting('shamcash_data', {})
        : {};

    if (!window.wc || !window.wc.wcBlocksRegistry || !window.wp || !window.wp.element) {
        return;
    }

    var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
    var createElement = window.wp.element.createElement;
    var decodeEntities = (window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities)
        ? window.wp.htmlEntities.decodeEntities
        : function (s) { return s; };

    var label = decodeEntities(settings.title || 'ShamCash');

    var Label = function () {
        return createElement('span', { className: 'shamcash-blocks-label' }, label);
    };

    var Content = function () {
        return createElement(
            'div',
            { className: 'shamcash-blocks-description' },
            decodeEntities(settings.description || '')
        );
    };

    registerPaymentMethod({
        name: 'shamcash',
        label: createElement(Label, null),
        content: createElement(Content, null),
        edit: createElement(Content, null),
        canMakePayment: function () {
            return true;
        },
        ariaLabel: label,
        supports: {
            features: settings.supports || ['products']
        }
    });
})();
