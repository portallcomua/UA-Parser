jQuery(function ($) {
    'use strict';
    var collected = [];
    var selected = [];

    function log(message) {
        var box = $('#uap50-log');
        box.text('[' + new Date().toLocaleTimeString() + '] ' + message + '\n' + box.text());
    }
    function progress(value, label) {
        $('#uap50-bar').css('width', value + '%');
        $('#uap50-percent').text(value + '%');
        $('#uap50-status').text(label);
    }
    function call(action, data) {
        data = data || {};
        data.action = action;
        data.nonce = UAP50.nonce;
        return $.post(UAP50.ajax, data);
    }
    function esc(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }
    function render() {
        if (!collected.length) {
            $('#uap50-results').html('<p>Поки немає результатів.</p>');
            $('#uap50-publish,#uap50-export').prop('disabled', true);
            return;
        }
        var html = '<div class="uap50-grid">';
        collected.forEach(function (item) {
            html += '<article class="uap50-item"><label><input type="checkbox" checked disabled> <b>' + esc(item.title || 'Без назви') + '</b></label>';
            html += '<small>' + esc(item.source_url) + '</small>';
            if (item.images && item.images.length) html += '<img src="' + esc(item.images[0]) + '" alt="">';
            html += '<p>' + esc((item.description || '').replace(/<[^>]*>/g, '').slice(0, 220)) + '</p>';
            if (item.price) html += '<strong>' + esc(item.price) + '</strong>';
            html += '</article>';
        });
        html += '</div>';
        $('#uap50-results').html(html);
        $('#uap50-publish,#uap50-export,#uap50-export-profile-btn').prop('disabled', false);
    }

    $('#uap50-scan').on('click', function () {
        var url = $('#uap50-url').val().trim();
        if (!url) return log('Вкажіть URL.');
        progress(20, 'Аналізуємо сторінку…');
        call('uap50_scan', {url: url}).done(function (response) {
            if (!response.success) return log(response.data && response.data.message ? response.data.message : 'Помилка аналізу.');
            var html = '<ul>';
            response.data.fields.forEach(function (field) {
                html += '<li><b>' + esc(field.label) + ':</b> ' + esc(field.value || 'Не знайдено') + '</li>';
            });
            html += '</ul>';
            $('#uap50-fields').html(html);
            progress(100, 'Аналіз завершено');
            log('Аналіз сторінки завершено.');
        }).fail(function (xhr) {
            progress(0, 'Помилка');
            log(xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : 'Не вдалося проаналізувати сторінку.');
        });
    });

    $('#uap50-collect').on('click', function () {
        var url = $('#uap50-url').val().trim();
        if (!url) return log('Вкажіть URL.');
        progress(25, 'Збираємо дані…');
        call('uap50_collect', {url: url}).done(function (response) {
            if (!response.success) return log(response.data && response.data.message ? response.data.message : 'Помилка збору.');
            collected = response.data.items || [];
            render();
            progress(100, 'Збір завершено');
            log('Зібрано записів у тимчасовому наборі: ' + collected.length);
        }).fail(function (xhr) {
            progress(0, 'Помилка');
            log(xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : 'Не вдалося зібрати дані.');
        });
    });

    $('#uap50-results').on('change', '.uap50-select', function () { selected = $('.uap50-select:checked').map(function(){ return this.value; }).get(); $('#uap50-publish').prop('disabled', !selected.length); });

    $('#uap50-crawl').on('click', function () {
        var url = $('#uap50-url').val().trim();
        if (!url) return log('Вкажіть URL категорії або сторінки.');
        progress(5, 'Скануємо пов’язані сторінки…');
        call('uap50_crawl', {url: url}).done(function(response) {
            if (!response.success) return log(response.data && response.data.message ? response.data.message : 'Помилка обходу.');
            collected = response.data.items || []; render(); progress(100, 'Обхід завершено');
            log('Перевірено сторінок: ' + response.data.scanned + '; додано нових матеріалів: ' + response.data.added + '.');
        }).fail(function(xhr) { progress(0, 'Помилка'); log(xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : 'Не вдалося обійти сторінки.'); });
    });

    $('#uap50-publish').on('click', function () {
        if (!collected.length) return;
        if (!window.confirm('Опублікувати зібрані матеріали на цьому сайті?')) return;
        progress(20, 'Публікуємо…');
        call('uap50_publish', {
            post_type: $('#uap50-type').val(),
            post_status: $('#uap50-status').val(),
            selected: $('.uap50-select:checked').map(function(){ return this.value; }).get()
        }).done(function (response) {
            if (!response.success) return log(response.data && response.data.message ? response.data.message : 'Помилка публікації.');
            progress(100, 'Публікацію завершено');
            log('Створено: ' + response.data.count + '; дублікатів пропущено: ' + response.data.duplicates);
            (response.data.created || []).forEach(function (item) {
                log('Створено #' + item.id + ': ' + item.title);
            });
        }).fail(function (xhr) {
            progress(0, 'Помилка');
            log(xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : 'Не вдалося опублікувати.');
        });
    });

    $('#uap50-export').on('click', function () {
        var form = $('<form method="post"></form>').attr('action', UAP50.export);
        form.append($('<input type="hidden" name="action">').val('uap50_export'));
        form.append($('<input type="hidden" name="_wpnonce">').val(UAP50.exportNonce));
        $('body').append(form);
        form.trigger('submit');
        form.remove();
    });
});
