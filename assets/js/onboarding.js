(function ($) {
    'use strict';

    $(document).on('click', '#shamcash-onboarding .shamcash-dismiss', function (e) {
        e.preventDefault();

        var $notice = $('#shamcash-onboarding');

        $.post(shamcashOnboarding.ajaxUrl, {
            action: 'shamcash_dismiss_onboarding',
            nonce: shamcashOnboarding.nonce
        }).always(function () {
            $notice.fadeOut(200, function () {
                $(this).remove();
            });
        });
    });
})(jQuery);
