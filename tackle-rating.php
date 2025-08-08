<?php
/*
Plugin Name: Tackle Rating
Description: Голосование за снасти с админкой и предложениями.
Version: 1.0
Author: Твой Ник
*/

if (!defined('ABSPATH')) exit;

class TackleRatingPlugin {

    private $table_items;
    private $nonce = 'tackle_nonce';

    public function __construct() {
        global $wpdb;
        $this->table_items = $wpdb->prefix . 'tackle_rating_items';

        register_activation_hook(__FILE__, [$this, 'activate']);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_scripts']);

        // AJAX для фронтенда
        add_action('wp_ajax_tackle_vote_multiple', [$this, 'ajax_vote_multiple']);
		add_action('wp_ajax_nopriv_tackle_vote_multiple', [$this, 'ajax_vote_multiple']);
        add_action('wp_ajax_tackle_vote', [$this, 'ajax_vote']);
        add_action('wp_ajax_nopriv_tackle_vote', [$this, 'ajax_vote']);
        add_action('wp_ajax_tackle_suggest', [$this, 'ajax_suggest']);
        add_action('wp_ajax_nopriv_tackle_suggest', [$this, 'ajax_suggest']);

        // AJAX для админки
        add_action('wp_ajax_tackle_admin_approve', [$this, 'ajax_admin_approve']);
        add_action('wp_ajax_tackle_admin_delete', [$this, 'ajax_admin_delete']);
        add_action('wp_ajax_tackle_admin_merge', [$this, 'ajax_admin_merge']);
        add_action('wp_ajax_tackle_admin_add_manual', [$this, 'ajax_admin_add_manual']);

        add_shortcode('tackle_rating', [$this, 'shortcode']);
    }

    public function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_items} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            votes INT UNSIGNED NOT NULL DEFAULT 0,
            approved TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY name_unique (name)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function admin_menu() {
        add_menu_page(
            'Рейтинг снастей',
            'Рейтинг снастей',
            'manage_options',
            'tackle-rating',
            [$this, 'admin_page'],
            'dashicons-thumbs-up',
            80
        );
    }

    public function admin_scripts($hook) {
        if ($hook !== 'toplevel_page_tackle-rating') return;
        wp_enqueue_style('tackle-admin-style', plugin_dir_url(__FILE__) . 'assets/admin.css', [], '1.0');
        wp_enqueue_script('tackle-admin-js', plugin_dir_url(__FILE__) . 'assets/admin.js', ['jquery'], '1.0', true);
        wp_localize_script('tackle-admin-js', 'tackleAdmin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce($this->nonce),
        ]);
    }

    public function frontend_scripts() {
        wp_enqueue_style('tackle-frontend-style', plugin_dir_url(__FILE__) . 'assets/frontend.css', [], '1.0');
        wp_enqueue_script('tackle-frontend-js', plugin_dir_url(__FILE__) . 'assets/frontend.js', ['jquery'], '1.0', true);
        wp_localize_script('tackle-frontend-js', 'tackleFrontend', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce($this->nonce),
        ]);
    }

    public function admin_page() {
        global $wpdb;

        $filter = isset($_GET['approved']) ? intval($_GET['approved']) : -1; // -1 = все

        if ($filter === -1) {
            $items = $wpdb->get_results("SELECT * FROM {$this->table_items} ORDER BY votes DESC, name ASC");
        } else {
            $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table_items} WHERE approved = %d ORDER BY votes DESC, name ASC", $filter));
        }

        $max_votes = 0;
        foreach ($items as $item) {
            if ($item->votes > $max_votes) $max_votes = $item->votes;
        }
        ?>
        <div class="wrap">
            <h1>Рейтинг снастей</h1>

            <form method="GET" style="margin-bottom:20px;">
                <input type="hidden" name="page" value="tackle-rating" />
                <label>Фильтр по одобрению:
                    <select name="approved" onchange="this.form.submit()">
                        <option value="-1" <?= $filter === -1 ? 'selected' : '' ?>>Все</option>
                        <option value="1" <?= $filter === 1 ? 'selected' : '' ?>>Одобренные</option>
                        <option value="0" <?= $filter === 0 ? 'selected' : '' ?>>Неодобренные</option>
                    </select>
                </label>
            </form>

            <form id="tackle-admin-form">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td style="width:30px;"><input type="checkbox" id="tackle-select-all" /></td>
                            <th>Название</th>
                            <th>Голосов</th>
                            <th>Рейтинг (%)</th>
                            <th>Одобрен</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$items) : ?>
                            <tr><td colspan="5">Вариантов нет</td></tr>
                        <?php else: ?>
                            <?php foreach ($items as $item): 
                                $percent = $max_votes ? round(($item->votes / $max_votes) * 100) : 0;
                            ?>
                            <tr>
                                <td><input type="checkbox" name="ids[]" value="<?= esc_attr($item->id) ?>" class="tackle-checkbox" /></td>
                                <td><?= esc_html($item->name) ?></td>
                                <td><?= intval($item->votes) ?></td>
                                <td><?= $percent ?>%</td>
                                <td><?= $item->approved ? 'Да' : 'Нет' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div style="margin-top: 15px;">
                    <button type="button" id="tackle-approve-btn" class="button button-primary">Одобрить выбранные</button>
                    <button type="button" id="tackle-delete-btn" class="button button-secondary">Удалить выбранные</button>
                    <button type="button" id="tackle-merge-btn" class="button">Объединить выбранные</button>
                </div>

                <div id="tackle-merge-section" style="margin-top: 10px; display:none;">
                    <input type="text" id="tackle-merge-name" placeholder="Новое название для объединения" style="width:300px;" />
                    <button type="button" id="tackle-merge-confirm" class="button button-primary">Объединить</button>
                    <button type="button" id="tackle-merge-cancel" class="button">Отмена</button>
                </div>
            </form>

            <hr>

            <h2>Добавить вариант вручную</h2>
            <form id="tackle-add-manual-form">
                <input type="text" id="tackle-add-manual-name" placeholder="Название варианта" style="width:300px;" required />
                <button type="submit" class="button button-primary">Добавить</button>
            </form>

            <div id="tackle-add-manual-msg" style="margin-top:10px;"></div>

        </div>
        <?php
    }

    // AJAX: Одобрить
    public function ajax_admin_approve() {
        check_ajax_referer($this->nonce, 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Доступ запрещён');

        if (empty($_POST['ids']) || !is_array($_POST['ids'])) wp_send_json_error('Нет выбранных вариантов');

        global $wpdb;
        $ids = array_map('intval', $_POST['ids']);
        $ids_placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $query = "UPDATE {$this->table_items} SET approved = 1 WHERE id IN ($ids_placeholders)";
        $result = $wpdb->query($wpdb->prepare($query, ...$ids));
        if ($result === false) wp_send_json_error('Ошибка обновления');

        wp_send_json_success('Выбранные варианты одобрены');
    }

    // AJAX: Удалить
    public function ajax_admin_delete() {
        check_ajax_referer($this->nonce, 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Доступ запрещён');

        if (empty($_POST['ids']) || !is_array($_POST['ids'])) wp_send_json_error('Нет выбранных вариантов');

        global $wpdb;
        $ids = array_map('intval', $_POST['ids']);
        $ids_placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $query = "DELETE FROM {$this->table_items} WHERE id IN ($ids_placeholders)";
        $result = $wpdb->query($wpdb->prepare($query, ...$ids));
        if ($result === false) wp_send_json_error('Ошибка удаления');

        wp_send_json_success('Выбранные варианты удалены');
    }

    // AJAX: Объединить
    public function ajax_admin_merge() {
        check_ajax_referer($this->nonce, 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Доступ запрещён');

        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : [];
        $new_name = isset($_POST['new_name']) ? sanitize_text_field($_POST['new_name']) : '';

        if (!$new_name) wp_send_json_error('Введите новое название');
        if (count($ids) < 2) wp_send_json_error('Выберите минимум 2 варианта для объединения');

        global $wpdb;

        // Считаем сумму голосов
        $ids_int = array_map('intval', $ids);
        $ids_placeholders = implode(',', array_fill(0, count($ids_int), '%d'));

        $total_votes = $wpdb->get_var($wpdb->prepare("SELECT SUM(votes) FROM {$this->table_items} WHERE id IN ($ids_placeholders)", ...$ids_int));

        // Удаляем объединяемые варианты
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->table_items} WHERE id IN ($ids_placeholders)", ...$ids_int));

        // Вставляем новый вариант с суммой голосов и approved=1
        $wpdb->insert(
            $this->table_items,
            [
                'name' => $new_name,
                'votes' => intval($total_votes),
                'approved' => 1,
            ],
            ['%s', '%d', '%d']
        );

        wp_send_json_success('Варианты объединены');
    }

    // AJAX: Добавить вручную (админка)
    public function ajax_admin_add_manual() {
        check_ajax_referer($this->nonce, 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Доступ запрещён');

        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        if (!$name) wp_send_json_error('Введите название');

        global $wpdb;

        // Проверка уникальности
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_items} WHERE name = %s", $name));
        if ($exists) wp_send_json_error('Такой вариант уже существует');

        $wpdb->insert(
            $this->table_items,
            [
                'name' => $name,
                'votes' => 0,
                'approved' => 1,
            ],
            ['%s', '%d', '%d']
        );

        wp_send_json_success('Вариант добавлен');
    }

    // Короткий код для фронтенда
    public function shortcode() {
        ob_start();
        $this->render_frontend();
        return ob_get_clean();
    }

    private function render_frontend() {
    global $wpdb;
    $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table_items} WHERE approved = 1 ORDER BY votes DESC, name ASC"));

    $max_votes = 0;
    foreach ($items as $item) {
        if ($item->votes > $max_votes) $max_votes = $item->votes;
    }
    ?>
    <div id="tackle-rating-frontend">
        <h2>Рейтинг снастей</h2>
        <?php if (!$items) : ?>
            <p>Вариантов пока нет.</p>
        <?php else: ?>
            <form id="tackle-vote-form">
                <ul>
                    <?php foreach ($items as $item):
                        $percent = $max_votes ? round(($item->votes / $max_votes) * 100) : 0;
                    ?>
                        <li style="margin-bottom: 10px;">
                            <label>
                                <input type="checkbox" name="vote_ids[]" value="<?= intval($item->id) ?>" />
                                <strong><?= esc_html($item->name) ?></strong> — <?= intval($item->votes) ?> голосов (<?= $percent ?>%)
                            </label>
                            <div style="background: #ddd; width: 100%; height: 10px; border-radius: 5px; margin-top: 5px;">
                                <div style="background: #4caf50; width: <?= $percent ?>%; height: 10px; border-radius: 5px;"></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <button type="submit" class="button button-primary">Проголосовать</button>
            </form>
        <?php endif; ?>

        <h3>Предложить свой вариант</h3>
        <form id="tackle-suggest-form">
            <input type="text" id="tackle-suggest-name" placeholder="Название варианта" required style="width: 300px;" />
            <button type="submit">Предложить</button>
        </form>
        <div id="tackle-suggest-msg" style="margin-top: 10px;"></div>
    </div>

    <script>
    jQuery(document).ready(function($){
        $('#tackle-vote-form').on('submit', function(e){
            e.preventDefault();
            var selected = [];
            $('#tackle-vote-form input[name="vote_ids[]"]:checked').each(function(){
                selected.push($(this).val());
            });
            if(selected.length === 0){
                alert('Выберите хотя бы один вариант для голосования');
                return;
            }
            $.ajax({
                url: tackleFrontend.ajax_url,
                method: 'POST',
                data: {
                    action: 'tackle_vote_multiple',
                    ids: selected,
                    nonce: tackleFrontend.nonce
                },
                success: function(response){
                    if(response.success){
                        alert('Спасибо за голос!');
                        location.reload();
                    } else {
                        alert(response.data || 'Ошибка голосования');
                    }
                }
            });
        });

        $('#tackle-suggest-form').on('submit', function(e){
            e.preventDefault();
            var name = $('#tackle-suggest-name').val().trim();
            if(!name){
                alert('Введите название варианта');
                return;
            }
            $.ajax({
                url: tackleFrontend.ajax_url,
                method: 'POST',
                data: {
                    action: 'tackle_suggest',
                    name: name,
                    nonce: tackleFrontend.nonce
                },
                success: function(response){
                    if(response.success){
                        $('#tackle-suggest-msg').text(response.data);
                        $('#tackle-suggest-name').val('');
                    } else {
                        $('#tackle-suggest-msg').text(response.data || 'Ошибка предложения');
                    }
                }
            });
        });
    });
    </script>
    <?php
}

    // AJAX: Голосовать
    public function ajax_vote() {
        check_ajax_referer($this->nonce, 'nonce');

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) wp_send_json_error('Неверный ID');

        global $wpdb;
        // Проверка, есть ли вариант и одобрен ли
        $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_items} WHERE id = %d AND approved = 1", $id));
        if (!$item) wp_send_json_error('Вариант не найден или не одобрен');

        $updated = $wpdb->query($wpdb->prepare("UPDATE {$this->table_items} SET votes = votes + 1 WHERE id = %d", $id));
        if ($updated === false) wp_send_json_error('Ошибка голосования');

        wp_send_json_success('Спасибо за голос!');
    }
	
	// AJAX: Голосовать за несколько вариантов
	public function ajax_vote_multiple() {
    check_ajax_referer($this->nonce, 'nonce');

    if (empty($_POST['ids']) || !is_array($_POST['ids'])) {
        wp_send_json_error('Нет выбранных вариантов');
    }

    global $wpdb;

    $ids = array_map('intval', $_POST['ids']);
    if (empty($ids)) wp_send_json_error('Нет корректных вариантов');

    $ids_placeholders = implode(',', array_fill(0, count($ids), '%d'));

    // Проверяем, что все варианты одобрены
    $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_items} WHERE id IN ($ids_placeholders) AND approved = 1", ...$ids));
    if ($count != count($ids)) {
        wp_send_json_error('Некоторые варианты не найдены или не одобрены');
    }

    // Обновляем голоса для каждого выбранного варианта
    foreach ($ids as $id) {
        $updated = $wpdb->query($wpdb->prepare("UPDATE {$this->table_items} SET votes = votes + 1 WHERE id = %d", $id));
        if ($updated === false) {
            wp_send_json_error('Ошибка голосования');
        }
    }

    wp_send_json_success('Спасибо за голос!');
}

    // AJAX: Предложить вариант
   public function ajax_suggest() {
    check_ajax_referer($this->nonce, 'nonce');

    $name = isset($_POST['name']) ? sanitize_text_field(trim($_POST['name'])) : '';
    if (!$name) {
        wp_send_json_error('Введите название');
    }

    global $wpdb;

    // Пытаемся получить вариант с таким именем
    $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_items} WHERE name = %s", $name));

    if ($existing) {
        if ($existing->approved == 1) {
            // Такой вариант уже одобрен — нельзя добавить повторно
            wp_send_json_error('Такой вариант уже существует и одобрен');
        } else {
            // Вариант есть, но не одобрен — увеличиваем голоса на 1
            $updated = $wpdb->update(
                $this->table_items,
                ['votes' => $existing->votes + 1],
                ['id' => $existing->id],
                ['%d'],
                ['%d']
            );

            if ($updated !== false) {
                wp_send_json_success('Вариант уже предлагался, добавлен дополнительный голос');
            } else {
                wp_send_json_error('Ошибка обновления голосов');
            }
        }
    } else {
        // Вариант новый — добавляем с 1 голосом
        $inserted = $wpdb->insert(
            $this->table_items,
            [
                'name' => $name,
                'votes' => 1,
                'approved' => 0,
            ],
            ['%s', '%d', '%d']
        );

        if ($inserted) {
            wp_send_json_success('Спасибо за предложение! Вариант появится после одобрения.');
        } else {
            wp_send_json_error('Ошибка добавления варианта');
        }
    }
}
}

new TackleRatingPlugin();
