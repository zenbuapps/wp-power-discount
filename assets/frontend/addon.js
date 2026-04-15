(function ($) {
    'use strict';

    function openModal($card) {
        var productId = $card.data('product-id');
        // Find the matching <template> — E1 outputs it as a sibling of the label
        var $template = $('template.pd-addon-detail[data-product-id="' + productId + '"]');
        if (!$template.length) {
            return;
        }

        var $dialog = $('<dialog class="pd-addon-modal"></dialog>');
        var $body = $('<div class="pd-addon-modal-body"></div>');
        $body.html($template.html());
        $dialog.append($body);

        var $checkbox = $card.find('input[type="checkbox"]');
        var isChecked = $checkbox.prop('checked');

        var confirmLabel = isChecked
            ? (PowerDiscountAddon && PowerDiscountAddon.cancelSelect) || '取消加購'
            : (PowerDiscountAddon && PowerDiscountAddon.confirmSelect) || '選擇加購';

        var $footer = $('<div class="pd-addon-modal-footer"></div>');
        var $confirmBtn = $('<button type="button" class="button button-primary pd-addon-confirm"></button>');
        $confirmBtn.text(confirmLabel);
        $footer.append($confirmBtn);
        $dialog.append($footer);

        $('body').append($dialog);
        if (typeof $dialog[0].showModal === 'function') {
            $dialog[0].showModal();
        } else {
            // Fallback for browsers without <dialog> support (Firefox < 98 etc.)
            $dialog.attr('open', 'open').addClass('pd-addon-modal-fallback');
        }

        // Close on click outside content
        $dialog.on('click', function (e) {
            if (e.target === $dialog[0]) {
                closeDialog($dialog);
            }
        });

        // Close on ESC handled natively by <dialog>
        $dialog.on('cancel', function () {
            // Cancel event fires on ESC; let the native close proceed
        });

        $confirmBtn.on('click', function () {
            $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
            closeDialog($dialog);
        });

        $dialog.on('close', function () {
            $dialog.remove();
        });
    }

    function closeDialog($dialog) {
        if (typeof $dialog[0].close === 'function' && $dialog[0].open) {
            $dialog[0].close();
        } else {
            $dialog.removeAttr('open').remove();
        }
    }

    // Click the 查看詳細 button opens the modal
    $(document).on('click', '.pd-addon-details-btn', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $card = $(this).closest('.pd-addon-card');
        openModal($card);
    });

    // Clicking the card itself (excluding the details button) toggles the checkbox
    // The native <label> already does this, so no extra JS needed for toggle.

    // Keep .is-selected in sync with the checkbox state for browsers without :has() support
    $(document).on('change', '.pd-addon-card input[type="checkbox"]', function () {
        $(this).closest('.pd-addon-card').toggleClass('is-selected', this.checked);
    });

    // Initial sync on page load (e.g. browser restored form state)
    $(function () {
        $('.pd-addon-card input[type="checkbox"]').each(function () {
            $(this).closest('.pd-addon-card').toggleClass('is-selected', this.checked);
        });
    });

})(jQuery);
