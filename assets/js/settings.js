(function ($) {
    $(document).ready(function () {
        $('#whop-test-connection').on('click', function (event) {
            event.preventDefault();

            var button = $(this);
            var resultContainer = $('#whop-test-connection-result');

            button.prop('disabled', true);
            resultContainer.text('');

            $.post(
                WhopWooCommerceSettings.ajaxUrl,
                {
                    action: 'whop_test_connection',
                    nonce: WhopWooCommerceSettings.nonce,
                }
            ).done(function (response) {
                if (response.success) {
                    resultContainer.html('<div class="notice notice-success is-dismissible"><p>' + response.data.message + '</p></div>');
                } else {
                    resultContainer.html('<div class="notice notice-error is-dismissible"><p>' + response.data.message + '</p></div>');
                }
            }).fail(function (xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : 'Connection failed.';
                resultContainer.html('<div class="notice notice-error is-dismissible"><p>' + message + '</p></div>');
            }).always(function () {
                button.prop('disabled', false);
            });
        });
    });
})(jQuery);
