/* global ctholded, jQuery */
(function ($) {
    'use strict';

    // ── Test connection ──────────────────────────────────────────────────────
    $('#ctholded-test-btn').on('click', function () {
        var $btn    = $(this);
        var $result = $('#ctholded-test-result');

        $btn.prop('disabled', true).text(ctholded.i18n_testing);
        $result.removeClass('success error').text('');

        $.post(ajaxurl, {
            action:   'ctholded_test_connection',
            nonce:    ctholded.nonce,
            api_key:  $('#ctholded_api_key').val(),
        })
        .done(function (res) {
            if (res.success) {
                $result.addClass('success').text('✓ ' + res.data.message);
            } else {
                $result.addClass('error').text('✗ ' + res.data.message);
            }
        })
        .fail(function () {
            $result.addClass('error').text('✗ ' + ctholded.i18n_error);
        })
        .always(function () {
            $btn.prop('disabled', false).text('Test connection');
        });
    });

    // ── Manual pull ──────────────────────────────────────────────────────────
    $('#ctholded-pull-btn').on('click', function () {
        var $btn    = $(this);
        var $result = $('#ctholded-pull-result');

        $btn.prop('disabled', true).text(ctholded.i18n_pulling);
        $result.removeClass('success error').text('');

        $.post(ajaxurl, {
            action: 'ctholded_manual_pull',
            nonce:  ctholded.nonce,
        })
        .done(function (res) {
            if (res.success) {
                $result.addClass('success').text('✓ ' + res.data.message);
            } else {
                $result.addClass('error').text('✗ ' + res.data.message);
            }
        })
        .fail(function () {
            $result.addClass('error').text('✗ ' + ctholded.i18n_error);
        })
        .always(function () {
            $btn.prop('disabled', false).text('Pull from Holded now');
        });
    });

    // ── Sync warehouse name on change ────────────────────────────────────────
    $(document).on('change', '#ctholded_warehouse_id', function () {
        $('#ctholded_warehouse_name').val($(this).find('option:selected').text());
    });

    // ── Load warehouses ──────────────────────────────────────────────────────
    $('#ctholded-load-warehouses').on('click', function () {
        var $btn    = $(this);
        var $select = $('#ctholded_warehouse_id');
        var $result = $('#ctholded-warehouses-result');
        var saved   = $select.val();

        $btn.prop('disabled', true).text('Loading…');
        $result.removeClass('success error').text('');

        $.post(ajaxurl, {
            action: 'ctholded_get_warehouses',
            nonce:  ctholded.nonce,
        })
        .done(function (res) {
            if (res.success && Array.isArray(res.data) && res.data.length) {
                $select.empty();
                $select.append('<option value="">' + '— Select warehouse —' + '</option>');
                $.each(res.data, function (i, wh) {
                    var id   = wh.id || wh.warehouseId || wh._id || '';
                    var name = wh.name || wh.warehouseName || id;
                    var sel  = (id === saved) ? ' selected' : '';
                    $select.append($('<option>', { value: id, selected: (id === saved), text: name }));
                });
                // Sync hidden name field with current selection.
                $('#ctholded_warehouse_name').val($select.find('option:selected').text());
                $result.addClass('success').text('✓');
            } else if ( res.success ) {
                $result.addClass('error').text('✗ No warehouses found. Response: ' + JSON.stringify(res.data).substring(0, 120));
            } else {
                $result.addClass('error').text('✗ ' + (res.data && res.data.message ? res.data.message : JSON.stringify(res.data)));
            }
        })
        .fail(function () {
            $result.addClass('error').text('✗ ' + ctholded.i18n_error);
        })
        .always(function () {
            $btn.prop('disabled', false).text('Load warehouses');
        });
    });

    // ── Clear log ────────────────────────────────────────────────────────────
    $('#ctholded-clear-log').on('click', function () {
        $.post(ajaxurl, {
            action: 'ctholded_clear_log',
            nonce:  ctholded.nonce,
        }).done(function (res) {
            if (res.success) {
                location.reload();
            }
        });
    });

}(jQuery));
