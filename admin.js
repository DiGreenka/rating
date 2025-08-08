jQuery(function($){
    function ajaxAction(action, ids, data = {}) {
        data.action = action;
        data.nonce = tackleAdmin.nonce;
        data.ids = ids;

        return $.post(tackleAdmin.ajax_url, data);
    }

    $('#tackle-approve-btn').on('click', function(){
        let ids = $('.tackle-checkbox:checked').map(function(){ return $(this).val(); }).get();
        if(ids.length === 0) { alert('Выберите варианты'); return; }
        ajaxAction('tackle_admin_approve', ids).done(function(res){
            if(res.success) location.reload();
            else alert(res.data || 'Ошибка');
        });
    });

    $('#tackle-delete-btn').on('click', function(){
        let ids = $('.tackle-checkbox:checked').map(function(){ return $(this).val(); }).get();
        if(ids.length === 0) { alert('Выберите варианты'); return; }
        if(!confirm('Удалить выбранные?')) return;
        ajaxAction('tackle_admin_delete', ids).done(function(res){
            if(res.success) location.reload();
            else alert(res.data || 'Ошибка');
        });
    });

    $('#tackle-merge-btn').on('click', function(){
        let ids = $('.tackle-checkbox:checked').map(function(){ return $(this).val(); }).get();
        if(ids.length < 2) { alert('Выберите минимум 2 варианта'); return; }
        $('#tackle-merge-section').show();
    });

    $('#tackle-merge-confirm').on('click', function(){
        let ids = $('.tackle-checkbox:checked').map(function(){ return $(this).val(); }).get();
        let new_name = $('#tackle-merge-name').val().trim();
        if(!new_name) { alert('Введите новое название'); return; }

        $.post(tackleAdmin.ajax_url, {
            action: 'tackle_admin_merge',
            nonce: tackleAdmin.nonce,
            ids: ids,
            new_name: new_name
        }).done(function(res){
            if(res.success) location.reload();
            else alert(res.data || 'Ошибка');
        });
    });

    $('#tackle-merge-cancel').on('click', function(){
        $('#tackle-merge-section').hide();
        $('#tackle-merge-name').val('');
    });

    $('#tackle-add-manual-form').on('submit', function(e){
        e.preventDefault();
        let name = $('#tackle-add-manual-name').val().trim();
        if(!name) { alert('Введите название'); return; }

        $.post(tackleAdmin.ajax_url, {
            action: 'tackle_admin_add_manual',
            nonce: tackleAdmin.nonce,
            name: name
        }).done(function(res){
            if(res.success) {
                $('#tackle-add-manual-msg').text(res.data);
                $('#tackle-add-manual-name').val('');
                location.reload();
            } else {
                $('#tackle-add-manual-msg').text(res.data);
            }
        });
    });

    // Выделение чекбоксов
    $('#tackle-select-all').on('change', function(){
        $('.tackle-checkbox').prop('checked', $(this).prop('checked'));
    });
});
