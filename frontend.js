jQuery(function($){
    $('#tackle-rating-frontend').on('click', '.tackle-vote-btn', function(){
        const id = $(this).data('id');
        if(!id) return;
        $.post(tackleFrontend.ajax_url, {
            action: 'tackle_vote',
            nonce: tackleFrontend.nonce,
            id: id
        }, function(res){
            alert(res.success ? res.data : 'Ошибка: ' + res.data);
            if(res.success) location.reload();
        });
    });

    $('#tackle-suggest-form').submit(function(e){
        e.preventDefault();
        const name = $('#tackle-suggest-name').val().trim();
        if(!name) {
            alert('Введите название');
            return;
        }
        $.post(tackleFrontend.ajax_url, {
            action: 'tackle_suggest',
            nonce: tackleFrontend.nonce,
            name: name
        }, function(res){
            alert(res.success ? res.data : 'Ошибка: ' + res.data);
            if(res.success) $('#tackle-suggest-name').val('');
        });
    });
});
