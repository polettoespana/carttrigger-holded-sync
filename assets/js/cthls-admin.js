/* global cthls, jQuery */
(function ($) {
    'use strict';

    // ── Test connection ──────────────────────────────────────────────────────
    $('#cthls-test-btn').on('click', function () {
        var $btn        = $(this);
        var $result     = $('#cthls-test-result');
        var originalText = $btn.text();

        $btn.prop('disabled', true).text(cthls.i18n_testing);
        $result.removeClass('success error').text('');

        $.post(ajaxurl, {
            action:   'cthls_test_connection',
            nonce:    cthls.nonce,
            api_key:  $('#cthls_api_key').val(),
        })
        .done(function (res) {
            if (res.success) {
                $result.addClass('success').text('✓ ' + res.data.message);
            } else {
                $result.addClass('error').text('✗ ' + res.data.message);
            }
        })
        .fail(function () {
            $result.addClass('error').text('✗ ' + cthls.i18n_error);
        })
        .always(function () {
            $btn.prop('disabled', false).text(originalText);
        });
    });

    // ── Manual push ──────────────────────────────────────────────────────────
    $('#cthls-push-btn').on('click', function () {
        var $btn         = $(this);
        var $result      = $('#cthls-push-result');
        var originalText = $btn.text();

        $btn.prop('disabled', true).text(cthls.i18n_pushing);
        $result.removeClass('success error').text('');

        $.post(ajaxurl, {
            action: 'cthls_manual_push',
            nonce:  cthls.nonce,
        })
        .done(function (res) {
            if (res.success) {
                $result.addClass('success').text('✓ ' + res.data.message);
                setTimeout(function () { location.reload(); }, 1500);
            } else {
                $result.addClass('error').text('✗ ' + res.data.message);
                $btn.prop('disabled', false).text(originalText);
            }
        })
        .fail(function () {
            $result.addClass('error').text('✗ ' + cthls.i18n_error);
            $btn.prop('disabled', false).text(originalText);
        });
    });

    // ── Manual pull ──────────────────────────────────────────────────────────
    $('#cthls-pull-btn').on('click', function () {
        var $btn         = $(this);
        var $result      = $('#cthls-pull-result');
        var originalText = $btn.text();

        $btn.prop('disabled', true).text(cthls.i18n_pulling);
        $result.removeClass('success error').text('');

        $.post(ajaxurl, {
            action: 'cthls_manual_pull',
            nonce:  cthls.nonce,
        })
        .done(function (res) {
            if (res.success) {
                $result.addClass('success').text('✓ ' + res.data.message);
                setTimeout(function () { location.reload(); }, 1500);
            } else {
                $result.addClass('error').text('✗ ' + res.data.message);
                $btn.prop('disabled', false).text(originalText);
            }
        })
        .fail(function () {
            $result.addClass('error').text('✗ ' + cthls.i18n_error);
            $btn.prop('disabled', false).text(originalText);
        });
    });

    // ── Sync warehouse name on change ────────────────────────────────────────
    $(document).on('change', '#cthls_warehouse_id', function () {
        $('#cthls_warehouse_name').val($(this).find('option:selected').text());
    });

    // ── Load warehouses ──────────────────────────────────────────────────────
    $('#cthls-load-warehouses').on('click', function () {
        var $btn         = $(this);
        var $select      = $('#cthls_warehouse_id');
        var $result      = $('#cthls-warehouses-result');
        var saved        = $select.val();
        var originalText = $btn.text();

        $btn.prop('disabled', true).text(cthls.i18n_loading);
        $result.removeClass('success error').text('');

        $.post(ajaxurl, {
            action: 'cthls_get_warehouses',
            nonce:  cthls.nonce,
        })
        .done(function (res) {
            if (res.success && Array.isArray(res.data) && res.data.length) {
                $select.empty();
                $select.append('<option value="">' + cthls.i18n_select_warehouse + '</option>');
                $.each(res.data, function (i, wh) {
                    var id   = wh.id || wh.warehouseId || wh._id || '';
                    var name = wh.name || wh.warehouseName || id;
                    $select.append($('<option>', { value: id, selected: (id === saved), text: name }));
                });
                $('#cthls_warehouse_name').val($select.find('option:selected').text());
                $result.addClass('success').text('✓');
            } else if ( res.success ) {
                $result.addClass('error').text('✗ ' + cthls.i18n_no_warehouses);
            } else {
                $result.addClass('error').text('✗ ' + (res.data && res.data.message ? res.data.message : JSON.stringify(res.data)));
            }
        })
        .fail(function () {
            $result.addClass('error').text('✗ ' + cthls.i18n_error);
        })
        .always(function () {
            $btn.prop('disabled', false).text(originalText);
        });
    });

    // ── Reschedule ───────────────────────────────────────────────────────────
    $('#cthls-reschedule-btn').on('click', function () {
        var $btn         = $(this);
        var $result      = $('#cthls-reschedule-result');
        var originalText = $btn.text();

        $btn.prop('disabled', true).text(cthls.i18n_loading);
        $result.removeClass('success error').text('');

        $.post(ajaxurl, {
            action: 'cthls_reschedule',
            nonce:  cthls.nonce,
        })
        .done(function (res) {
            if (res.success) {
                $result.addClass('success').text('✓ ' + res.data.message);
            } else {
                $result.addClass('error').text('✗ ' + res.data.message);
            }
        })
        .fail(function () {
            $result.addClass('error').text('✗ ' + cthls.i18n_error);
        })
        .always(function () {
            $btn.prop('disabled', false).text(originalText);
        });
    });

    // ── Clear log ────────────────────────────────────────────────────────────
    $('#cthls-clear-log').on('click', function () {
        $.post(ajaxurl, {
            action: 'cthls_clear_log',
            nonce:  cthls.nonce,
        }).done(function (res) {
            if (res.success) {
                location.reload();
            }
        });
    });

}(jQuery));
