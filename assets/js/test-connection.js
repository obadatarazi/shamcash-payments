(function ($) {
    'use strict';

    function showResult($result, ok, message) {
        $result
            .removeClass('success error')
            .addClass(ok ? 'success' : 'error')
            .empty();

        var $icon = $('<span/>', {
            'class': 'dashicons ' + (ok ? 'dashicons-yes-alt' : 'dashicons-warning'),
            'aria-hidden': 'true'
        });

        $result.append($icon).append(document.createTextNode(' ' + message)).show();
    }

    $(document).on('click', '#shamcash-test-connection-btn', function (e) {
        e.preventDefault();

        var $btn     = $(this);
        var $result  = $('#shamcash-test-connection-result');
        var original = $btn.text();

        $btn.prop('disabled', true).text(shamcashTest.testing);
        $result.removeClass('success error').empty().hide();

        var agentKey  = $('#woocommerce_shamcash_agent_key').val() || '';
        var secretKey = $('#woocommerce_shamcash_secret_key').val() || '';
        var baseUrl   = $('#woocommerce_shamcash_base_url').val() || '';

        $.post(shamcashTest.ajaxUrl, {
            action:     'shamcash_test_connection',
            nonce:      shamcashTest.nonce,
            agent_key:  agentKey,
            secret_key: secretKey,
            base_url:   baseUrl
        })
        .done(function (response) {
            if (response.success) {
                showResult($result, true, response.data && response.data.message ? response.data.message : shamcashTest.success);
            } else {
                showResult(
                    $result,
                    false,
                    response.data && response.data.message ? response.data.message : shamcashTest.failed
                );
            }
        })
        .fail(function () {
            showResult($result, false, shamcashTest.failed);
        })
        .always(function () {
            $btn.prop('disabled', false).text(original);
        });
    });
})(jQuery);
