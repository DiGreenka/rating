jQuery(function ($) {

    // Обработка формы голосования (работает с form.tackle-vote-form)
    $(document).on('submit', '.tackle-vote-form', function (e) {
        e.preventDefault();
        var $form = $(this);

        // берем type_id из обёртки
        var typeId = $form.closest('.tackle-rating-frontend').data('type-id') || 0;

        var selected = $form.find('input[name="vote_ids[]"]:checked').map(function () {
            return $(this).val();
        }).get();

        if (selected.length === 0) {
            alert('Выберите хотя бы один вариант для голосования');
            return;
        }

        $.post(tackleFrontend.ajax_url, {
            action: 'tackle_vote_multiple',
            ids: selected,
            type_id: typeId,
            nonce: tackleFrontend.nonce
        }, function (response) {
            if (response && response.success) {
                updateRatings(response.data);
                // опционально снять чекбоксы
                $form.find('input[name="vote_ids[]"]').prop('checked', false);
            } else {
                alert((response && response.data) ? response.data : 'Ошибка голосования');
            }
        }, 'json').fail(function () {
            alert('Ошибка запроса. Попробуйте ещё раз.');
        });
    });

    // Обработка формы предложения варианта
    $(document).on('submit', '.tackle-suggest-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var name = $form.find('input[name="name"]').val().trim();
        var type_id = $form.find('input[name="type_id"]').val();
        var category_id = $form.find('select[name="category_id"]').val();

        if (!name || !type_id || !category_id) {
            alert('Заполните все поля');
            return;
        }

        $.post(tackleFrontend.ajax_url, {
            action: 'tackle_suggest',
            name: name,
            type_id: type_id,
            category_id: category_id,
            nonce: tackleFrontend.nonce
        }, function (res) {
            if (res && res.success) {
                $form.siblings('.tackle-suggest-msg').text(res.data || 'Спасибо!');
                $form[0].reset();
            } else {
                $form.siblings('.tackle-suggest-msg').text(res && res.data ? res.data : 'Ошибка предложения');
            }
        }, 'json').fail(function () {
            alert('Ошибка запроса. Попробуйте ещё раз.');
        });
    });

    // Нормализуем и анимируем обновление
    function updateRatings(data) {
        // data может быть объектом {id: {votes,percent}, ...} или массив [{id, votes, percent}, ...]
        var items = [];

        if (Array.isArray(data)) {
            // уже массив объектов
            items = data.map(function (it) {
                return {
                    id: parseInt(it.id, 10),
                    votes: parseInt(it.votes, 10),
                    percent: parseInt(it.percent, 10)
                };
            });
        } else if (typeof data === 'object' && data !== null) {
            for (var k in data) {
                if (!Object.prototype.hasOwnProperty.call(data, k)) continue;
                var v = data[k];
                items.push({
                    id: parseInt(k, 10),
                    votes: parseInt(v.votes, 10),
                    percent: parseInt(v.percent, 10)
                });
            }
        } else {
            return;
        }

        items.forEach(function (item) {
            var $li = $('#tackle-item-' + item.id);
            if (!$li.length) return;

            var $votes = $li.find('.votes-count');
            var $percent = $li.find('.percent-count');
            var $bar = $li.find('.progress-bar');

            // плавно заменить числа
            $votes.stop(true, true).fadeOut(150, function () {
                $(this).text(item.votes).fadeIn(150);
            });
            $percent.stop(true, true).fadeOut(150, function () {
                $(this).text(item.percent + '%').fadeIn(150);
            });

            // анимация ширины барa
            $bar.stop(true).animate({ width: item.percent + '%' }, 800);
        });
    }

});
