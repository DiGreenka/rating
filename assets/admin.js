jQuery(function($){
    function ajaxAction(action, ids, data = {}) {
        data.action = action;
        data.nonce = tackleAdmin.nonce;
        data.ids = ids;
        // если на странице управления элементами есть type-id — добавляем
        var typeId = $('#tackle-admin-items').data('type-id');
        if (typeId) data.type_id = typeId;
        return $.post(tackleAdmin.ajax_url, data);
    }

    // Добавить тип
    $('#tackle-add-type-form').on('submit', function(e){
        e.preventDefault();
        var name = $('#tackle-add-type-name').val().trim();
        if (!name) return alert('Введите название типа');
        $.post(tackleAdmin.ajax_url, { action: 'tackle_admin_add_type', nonce: tackleAdmin.nonce, type_name: name })
            .done(function(res){ if(res.success) location.reload(); else alert(res.data || 'Ошибка'); });
    });

    // Добавить категорию (на странице типа)
    $('#tackle-add-category-form').on('submit', function(e){
        e.preventDefault();
        var typeId = $('#tackle-add-category-type-id').val();
        var name = $('#tackle-add-category-name').val().trim();
        if (!name) return alert('Введите название категории');
        $.post(tackleAdmin.ajax_url, { action: 'tackle_admin_add_category', nonce: tackleAdmin.nonce, type_id: typeId, name: name })
            .done(function(res){ if(res.success) location.reload(); else $('#tackle-add-category-msg').text(res.data || 'Ошибка'); });
    });

    // Добавить вручную вариант (на странице типа)
    $('#tackle-add-manual-form').on('submit', function(e){
        e.preventDefault();
        var name = $('#tackle-add-manual-name').val().trim();
        var typeId = $('#tackle-add-manual-type-id').val();
        var categoryId = $('#tackle-add-manual-category').val();
        if (!name || !categoryId) return alert('Введите название и выберите категорию');
        $.post(tackleAdmin.ajax_url, { action: 'tackle_admin_add_manual', nonce: tackleAdmin.nonce, name: name, type_id: typeId, category_id: categoryId })
            .done(function(res){ if(res.success) location.reload(); else $('#tackle-add-manual-msg').text(res.data || 'Ошибка'); });
    });
	
	// Удаление категории
jQuery(document).on('click', '.tackle-delete-category', function(){
    if(!confirm('Удалить эту категорию и все её варианты?')) return;

    var catId = jQuery(this).data('id');
    var typeId = jQuery('#tackle-add-category-type-id').val();

    jQuery.post(tackleAdmin.ajax_url, {
        action: 'tackle_admin_delete_category',
        category_id: catId,
        type_id: typeId,
        nonce: tackleAdmin.nonce
    }, function(response){
        if(response.success){
            alert(response.data);
            location.reload();
        } else {
            alert(response.data || 'Ошибка при удалении категории');
        }
    });
});

	// Удаление типа рейтинга
jQuery(document).on('click', '.tackle-delete-type', function(){
    if(!confirm('Удалить этот рейтинг, все его категории и все варианты?')) return;

    var typeId = jQuery(this).data('id');

    jQuery.post(tackleAdmin.ajax_url, {
        action: 'tackle_admin_delete_type',
        type_id: typeId,
        nonce: tackleAdmin.nonce
    }, function(response){
        if(response.success){
            alert(response.data);
            location.reload();
        } else {
            alert(response.data || 'Ошибка при удалении рейтинга');
        }
    });
});
	
	// Смена категории
$(document).on('change', '.tackle-change-category', function(){
    var id = $(this).data('id');
    var category_id = $(this).val();
    $.post(tackleAdmin.ajax_url, {
        action: 'tackle_admin_change_category',
        id: id,
        category_id: category_id,
        nonce: tackleAdmin.nonce
    }, function(response){
        if(!response.success){
            alert(response.data || 'Ошибка изменения категории');
        }
    });
});

// Объединение
$('#tackle-merge-confirm').on('click', function(){
    var selected = [];
    $('.tackle-checkbox:checked').each(function(){
        selected.push($(this).val());
    });
    var newName = $('#tackle-merge-name').val();
    var categoryId = $('#tackle-merge-category').val();

    $.post(tackleAdmin.ajax_url, {
        action: 'tackle_admin_merge',
        ids: selected,
        new_name: newName,
        new_category_id: categoryId,
        nonce: tackleAdmin.nonce
    }, function(response){
        alert(response.data);
        if(response.success) location.reload();
    });
});


    // Одобрить выбранные
    $('#tackle-approve-btn').on('click', function(){
        let ids = $('.tackle-checkbox:checked').map(function(){ return $(this).val(); }).get();
        if(ids.length === 0) { alert('Выберите варианты'); return; }
        ajaxAction('tackle_admin_approve', ids).done(function(res){ if(res.success) location.reload(); else alert(res.data || 'Ошибка'); });
    });

    // Удалить выбранные
    $('#tackle-delete-btn').on('click', function(){
        let ids = $('.tackle-checkbox:checked').map(function(){ return $(this).val(); }).get();
        if(ids.length === 0) { alert('Выберите варианты'); return; }
        if(!confirm('Удалить выбранные?')) return;
        ajaxAction('tackle_admin_delete', ids).done(function(res){ if(res.success) location.reload(); else alert(res.data || 'Ошибка'); });
    });

    // Объединить - показать панель
    $('#tackle-merge-btn').on('click', function(){
        let ids = $('.tackle-checkbox:checked').map(function(){ return $(this).val(); }).get();
        if(ids.length < 2) { alert('Выберите минимум 2 варианта'); return; }
        $('#tackle-merge-section').show();
    });

    $('#tackle-merge-confirm').on('click', function(){
        let ids = $('.tackle-checkbox:checked').map(function(){ return $(this).val(); }).get();
        let new_name = $('#tackle-merge-name').val().trim();
        if(!new_name) { alert('Введите новое название'); return; }
        ajaxAction('tackle_admin_merge', ids, { new_name: new_name }).done(function(res){ if(res.success) location.reload(); else alert(res.data || 'Ошибка'); });
    });

    $('#tackle-merge-cancel').on('click', function(){ $('#tackle-merge-section').hide(); $('#tackle-merge-name').val(''); });

    // Select all (на странице типа)
    $('#tackle-select-all').on('change', function(){ $('.tackle-checkbox').prop('checked', $(this).prop('checked')); });
	
	
	
$('#tackle-save-type-fields').on('click', function(){
    var typeId = $(this).data('type-id');
    var fields = [];
    $('.tackle-field-toggle:checked').each(function(){
      fields.push($(this).data('key'));
    });

    $.post(tackleAdmin.ajax_url, {
      action: 'tackle_admin_set_type_fields',
      nonce: tackleAdmin.nonce,
      type_id: typeId,
      fields: fields
    }, function(res){
      $('#tackle-save-type-fields-msg').text(res && res.success ? 'Сохранено' : (res.data || 'Ошибка'));
      if (res && res.success) {
        // обновить страницу, чтобы появились/исчезли колонки
        location.reload();
      }
    }, 'json');
  });	

});
