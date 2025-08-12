<?php
/*
Plugin Name: Tackle Rating
Description: Голосование за снасти с админкой и предложениями.
Version: 1.0
Author: DiGreenka
*/

if (!defined('ABSPATH')) exit;

class TackleRatingPlugin {

    private $table_items;
		private $table_types;
		private $table_categories;
		private $nonce = 'tackle_nonce';
		private $table_type_fields;
		private $allowed_field_keys = ['test','length','spool'];
		private $field_labels = [
			'test'   => 'Тест',
			'length' => 'Длина',
			'spool'  => 'Объём шпули'
		];

		public function __construct() {
    global $wpdb;
    $this->table_items      = $wpdb->prefix . 'tackle_rating_items';
    $this->table_types      = $wpdb->prefix . 'tackle_rating_types';
    $this->table_categories = $wpdb->prefix . 'tackle_rating_categories';
	$this->table_type_fields = $wpdb->prefix . 'tackle_rating_type_fields';

    register_activation_hook(__FILE__, [$this, 'activate']);

    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
    add_action('wp_enqueue_scripts', [$this, 'frontend_scripts']);

    // AJAX для фронтенда
    add_action('wp_ajax_tackle_get_rating_html', [$this, 'ajax_get_rating_html']);
	add_action('wp_ajax_nopriv_tackle_get_rating_html', [$this, 'ajax_get_rating_html']);
    add_action('wp_ajax_tackle_vote_multiple', [$this, 'ajax_vote_multiple']);
    add_action('wp_ajax_nopriv_tackle_vote_multiple', [$this, 'ajax_vote_multiple']);
    add_action('wp_ajax_tackle_vote', [$this, 'ajax_vote']);
    add_action('wp_ajax_nopriv_tackle_vote', [$this, 'ajax_vote']);
    add_action('wp_ajax_tackle_suggest', [$this, 'ajax_suggest']);
    add_action('wp_ajax_nopriv_tackle_suggest', [$this, 'ajax_suggest']);

    // AJAX для админки
    add_action('wp_ajax_tackle_admin_change_category', [$this, 'ajax_admin_change_category']);
    add_action('wp_ajax_tackle_admin_add_type', [$this, 'ajax_admin_add_type']);
	add_action('wp_ajax_tackle_admin_delete_type', [$this, 'ajax_admin_delete_type']);
    add_action('wp_ajax_tackle_admin_add_category', [$this, 'ajax_admin_add_category']);
	add_action('wp_ajax_tackle_admin_delete_category', [$this, 'ajax_admin_delete_category']);
    add_action('wp_ajax_tackle_admin_add_manual', [$this, 'ajax_admin_add_manual']);
    add_action('wp_ajax_tackle_admin_approve', [$this, 'ajax_admin_approve']);
    add_action('wp_ajax_tackle_admin_delete', [$this, 'ajax_admin_delete']);
    add_action('wp_ajax_tackle_admin_merge', [$this, 'ajax_admin_merge']);
	add_action('wp_ajax_tackle_admin_set_type_fields', [$this, 'ajax_admin_set_type_fields']);

    // Шорткод
    add_shortcode('tackle_rating', [$this, 'shortcode']);
}
    public function activate() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Типы
    $sql_types = "CREATE TABLE IF NOT EXISTS {$this->table_types} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY name_unique (name),
        UNIQUE KEY slug_unique (slug)
    ) $charset_collate;";

    // Категории
    $sql_categories = "CREATE TABLE IF NOT EXISTS {$this->table_categories} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        rating_type_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL,
        PRIMARY KEY (id),
        KEY rating_type_id (rating_type_id),
        UNIQUE KEY unique_category (rating_type_id, slug)
    ) $charset_collate;";

    // Активные доп.поля для каждого типа
    $sql_type_fields = "CREATE TABLE IF NOT EXISTS {$this->table_type_fields} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        rating_type_id BIGINT UNSIGNED NOT NULL,
        field_key VARCHAR(20) NOT NULL, /* test|length|spool */
        PRIMARY KEY (id),
        UNIQUE KEY uniq (rating_type_id, field_key)
    ) $charset_collate;";

    // Элементы
    $sql_items = "CREATE TABLE IF NOT EXISTS {$this->table_items} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        rating_type_id BIGINT UNSIGNED NOT NULL,
        category_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL,
        field_test VARCHAR(255) DEFAULT '',
        field_length VARCHAR(255) DEFAULT '',
        field_spool VARCHAR(255) DEFAULT '',
        votes INT UNSIGNED NOT NULL DEFAULT 0,
        approved TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY unique_item (rating_type_id, category_id, slug),
        KEY rating_type_id (rating_type_id),
        KEY category_id (category_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_types);
    dbDelta($sql_categories);
    dbDelta($sql_type_fields);
    dbDelta($sql_items);
}


    public function admin_menu() {
    global $wpdb;

    // Общее количество не-одобренных элементов
    $pending_count = (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM {$this->table_items} WHERE approved = %d", 0)
    );

    // Формируем заголовок главного пункта (добавляем бейдж, если есть pending)
    $main_label = esc_html('Рейтинг снастей');
    if ($pending_count > 0) {
        $main_label .= ' <span class="update-plugins count-' . $pending_count . '"><span class="plugin-count">' . $pending_count . '</span></span>';
    }

    // Регистрация верхнего меню (главная страница — список типов)
    add_menu_page(
        'Рейтинг снастей',         // page_title
        $main_label,               // menu_title (может содержать HTML-бейдж)
        'manage_options',
        'tackle-rating',
        [$this, 'admin_page_types'],
        'dashicons-thumbs-up',
        80
    );

    // Подменю — динамически: для каждого типа показываем название + бейдж с количеством pending в этом типе
    $types = $wpdb->get_results("SELECT * FROM {$this->table_types} ORDER BY name ASC");
    if ($types) {
        foreach ($types as $type) {
            $pending_for_type = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$this->table_items} WHERE rating_type_id = %d AND approved = %d", $type->id, 0)
            );

            // безопасно выводим имя типа, а бейдж добавляем отдельно (HTML)
            $submenu_label = esc_html($type->name);
            if ($pending_for_type > 0) {
                $submenu_label .= ' <span class="update-plugins count-' . $pending_for_type . '"><span class="plugin-count">' . $pending_for_type . '</span></span>';
            }

            add_submenu_page(
                'tackle-rating',                        // parent slug
                $type->name,                            // page_title (без html)
                $submenu_label,                         // menu_title (с возможным бейджем)
                'manage_options',
                'tackle-rating-items-' . $type->id,
                function() use ($type) {
                    $this->admin_page_items($type->id);
                }
            );
        }
    }
}
	
		private function normalize_test($raw) {
    $s = trim(mb_strtolower((string)$raw));
    if ($s === '') return '';

    $s = str_replace(',', '.', $s);
    $s = preg_replace('/\s+/', '', $s);

    // диапазон "0.8-5"
    if (preg_match('/^(\d+(?:\.\d+)?)\s*[-–—]\s*(\d+(?:\.\d+)?)$/', $s, $m)) {
        $a = rtrim(rtrim($m[1], '0'), '.');
        $b = rtrim(rtrim($m[2], '0'), '.');
        return $a . '-' . $b . ' гр';
    }
    // одиночное число
    if (preg_match('/^\d+(?:\.\d+)?$/', $s)) {
        $num = (float)$s;
        return (intval($num) == $num ? intval($num) : rtrim(rtrim(number_format($num,2,'.',''), '0'), '.')) . ' гр';
    }
    // выдернем первое число из свободного текста
    if (preg_match('/(\d+(?:[.,]\d+)?)/', $raw, $m)) {
        $num = (float) str_replace(',', '.', $m[1]);
        return (intval($num) == $num ? intval($num) : rtrim(rtrim(number_format($num,2,'.',''), '0'), '.')) . ' гр';
    }
    return trim($raw);
}

private function normalize_length($raw) {
    $s = trim(mb_strtolower((string)$raw));
    if ($s === '') return '';

    $s = str_replace(',', '.', $s);
    $s = preg_replace('/\s+/', '', $s);

    // диапазон
    if (preg_match('/^(\d+(?:\.\d+)?)\s*[-–—]\s*(\d+(?:\.\d+)?)$/', $s, $m)) {
        $a = (float)$m[1]; $b = (float)$m[2];
        if ($a >= 100) $a /= 100; // 270 => 2.70
        if ($b >= 100) $b /= 100;
        return number_format($a,2,'.','') . '-' . number_format($b,2,'.','') . ' м';
    }
    // одиночное число
    if (preg_match('/^\d+(?:\.\d+)?$/', $s)) {
        $num = (float)$s;
        if ($num >= 100) $num /= 100;
        return number_format($num,2,'.','') . ' м';
    }
    if (preg_match('/(\d+(?:[.,]\d+)?)/', $raw, $m)) {
        $num = (float) str_replace(',', '.', $m[1]);
        if ($num >= 100) $num /= 100;
        return number_format($num,2,'.','') . ' м';
    }
    return trim($raw);
}

private function normalize_spool($raw) {
    $s = trim((string)$raw);
    if ($s === '') return '';
    $s = preg_replace('/\s+/', '', $s);
    $s = mb_strtoupper($s);

    // C5000 -> 5000C
    if (preg_match('/^([A-Z]+)(\d+)(.*)$/', $s, $m)) {
        return $m[2] . $m[1] . ($m[3] ?? '');
    }
    // 3000D-C -> 3000D-C (пропускаем как есть)
    if (preg_match('/^(\d+)([A-Z0-9\-\_]*)$/', $s, $m)) {
        return $m[1] . ($m[2] ?? '');
    }
    // если есть где-то число — переносим в начало
    if (preg_match('/(\d+)/', $s, $m)) {
        $num = $m[1];
        $rest = preg_replace('/.*' . preg_quote($num,'/') . '/', '', $s);
        return $num . $rest;
    }
    return $s;
}

private function build_slug($name, $field_test='', $field_length='', $field_spool='') {
    $parts = [ (string)$name ];
    if ($field_test)   $parts[] = $field_test;
    if ($field_length) $parts[] = $field_length;
    if ($field_spool)  $parts[] = $field_spool;
    $slug = sanitize_title(implode('-', $parts));
    return substr($slug, 0, 191);
}
		
		public function admin_page_types() {
    global $wpdb;
    $types = $wpdb->get_results("SELECT * FROM {$this->table_types} ORDER BY name ASC");
    ?>
    <div class="wrap">
        <h1>Рейтинг снастей — Типы</h1>

        <h2>Добавить новый тип рейтинга</h2>
        <form id="tackle-add-type-form">
            <input type="text" name="type_name" id="tackle-add-type-name" placeholder="Название (напр. Фидерные удилища)" style="width: 350px;" required />
            <button type="submit" class="button button-primary">Добавить тип</button>
        </form>
        <div id="tackle-type-msg" style="margin-top:10px;"></div>

        <h2 style="margin-top:30px;">Существующие типы</h2>
<table class="wp-list-table widefat fixed striped" style="max-width:600px;">
    <thead>
        <tr>
            <th>Название</th>
            <th>Шорткод</th>
            <th style="width:80px;">Действия</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($types): ?>
            <?php foreach ($types as $type): ?>
                <tr>
                    <td><?= esc_html($type->name) ?></td>
                    <td><code>[tackle_rating type_id="<?= intval($type->id) ?>"]</code></td>
                    <td>
                        <button type="button" class="button button-secondary tackle-delete-type" data-id="<?= intval($type->id) ?>">Удалить</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="3">Типов пока нет.</td></tr>
        <?php endif; ?>
    </tbody>
</table>
    </div>
    <?php
}
		
		public function admin_page_items($rating_type_id) {
    global $wpdb;

    // 1) Получаем элементы текущего типа для расчёта процентов
    $items = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$this->table_items} WHERE rating_type_id = %d ORDER BY votes DESC, name ASC",
            $rating_type_id
        )
    );
    $max_votes = 0;
    foreach ($items as $it) {
        if ($it->votes > $max_votes) {
            $max_votes = $it->votes;
        }
    }

    // 2) Категории текущего типа (для селекта)
    $categories = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$this->table_categories} WHERE rating_type_id = %d ORDER BY name ASC",
            $rating_type_id
        )
    );

    // 3) Список активных «дополнительных» полей для этого типа (если хранишь их в таблице)
    // Если у тебя нет таблицы активных полей, можно заменить это на статический массив:
    // $active_fields = [
    //     (object)['slug' => 'test',   'label' => 'Тест'],
    //     (object)['slug' => 'length', 'label' => 'Длина'],
    //     (object)['slug' => 'spool',  'label' => 'Объем шпули'],
    // ];
    $active_fields = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$this->table_fields} WHERE rating_type_id = %d ORDER BY id ASC",
            $rating_type_id
        )
    );

    /* ---------------- ВОТ ЗДЕСЬ БЛОК СОХРАНЕНИЯ ---------------- */
    if (isset($_POST['tackle_save_items']) && check_admin_referer('tackle_save_items_action', 'tackle_save_items_nonce')) {

        foreach ((array)($_POST['name'] ?? []) as $id => $name_raw) {
            $id = (int)$id;

            // 1) Собираем сырые значения из POST
            $name        = sanitize_text_field($name_raw);
            $test_raw    = isset($_POST['test'][$id])   ? sanitize_text_field($_POST['test'][$id])   : '';
            $length_raw  = isset($_POST['length'][$id]) ? sanitize_text_field($_POST['length'][$id]) : '';
            $spool_raw   = isset($_POST['spool'][$id])  ? sanitize_text_field($_POST['spool'][$id])  : '';
            $category_id = isset($_POST['category'][$id]) ? (int)$_POST['category'][$id] : 0;

            // 2) Нормализуем параметры
            $test   = $this->normalize_test($test_raw);
            $length = $this->normalize_length($length_raw);
            $spool  = $this->normalize_spool($spool_raw);

            // 3) Базовый слаг из названия
            $base_slug = sanitize_title($name);

            // 4) Добавляем нормализованные параметры в слаг (только непустые)
            $slug_parts = [$base_slug];
            if ($test !== '')   { $slug_parts[] = sanitize_title($test); }
            if ($length !== '') { $slug_parts[] = sanitize_title($length); }
            if ($spool !== '')  { $slug_parts[] = sanitize_title($spool); }
            $slug = substr(implode('-', $slug_parts), 0, 191);

            // 5) Формируем массив для UPDATE
            $update = [
                'name'         => $name,
                'slug'         => $slug,
                'field_test'   => $test,
                'field_length' => $length,
                'field_spool'  => $spool,
            ];
            if ($category_id > 0) {
                $update['category_id'] = $category_id;
            }

            // 6) Обновляем запись
            $wpdb->update(
                $this->table_items,
                $update,
                ['id' => $id],
                // форматы для $update
                array_merge(
                    ['%s','%s','%s','%s','%s'],
                    $category_id > 0 ? ['%d'] : []
                ),
                // форматы для WHERE
                ['%d']
            );
        }

        echo '<div class="updated"><p>Изменения сохранены</p></div>';

        // Перечитываем список после сохранения
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_items} WHERE rating_type_id = %d ORDER BY votes DESC, name ASC",
                $rating_type_id
            )
        );
        $max_votes = 0;
        foreach ($items as $it) {
            if ($it->votes > $max_votes) $max_votes = $it->votes;
        }
    }
    /* ---------------- КОНЕЦ БЛОКА СОХРАНЕНИЯ ---------------- */

    // 4) Рендер таблицы
    ?>
    <div class="wrap">
        <h1>Элементы рейтинга</h1>
        <form method="post">
            <?php wp_nonce_field('tackle_save_items_action', 'tackle_save_items_nonce'); ?>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th>Название</th>
                        <?php foreach ($active_fields as $f): ?>
                            <th><?php echo esc_html($f->label); ?></th>
                        <?php endforeach; ?>
                        <th>Голосов</th>
                        <th>Рейтинг (%)</th>
                        <th>Одобрен</th>
                        <th>Категория</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <?php $percent = $max_votes ? round(($item->votes / $max_votes) * 100, 1) : 0; ?>
                    <tr>
                        <td>
                            <input type="text" name="name[<?php echo $item->id; ?>]" value="<?php echo esc_attr($item->name); ?>">
                        </td>

                        <?php
                        // Подставляем значения полей в инпуты
                        foreach ($active_fields as $f):
                            $val = '';
                            if ($f->slug === 'test')   $val = $item->field_test;
                            if ($f->slug === 'length') $val = $item->field_length;
                            if ($f->slug === 'spool')  $val = $item->field_spool;
                        ?>
                            <td>
                                <input type="text" name="<?php echo esc_attr($f->slug); ?>[<?php echo $item->id; ?>]" value="<?php echo esc_attr($val); ?>">
                            </td>
                        <?php endforeach; ?>

                        <td><?php echo (int)$item->votes; ?></td>
                        <td><?php echo $percent; ?>%</td>
                        <td><?php echo $item->approved ? 'Да' : 'Нет'; ?></td>
                        <td>
                            <select name="category[<?php echo $item->id; ?>]">
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo (int)$cat->id; ?>" <?php selected($cat->id, $item->category_id); ?>>
                                        <?php echo esc_html($cat->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p><button type="submit" name="tackle_save_items" class="button button-primary">Сохранить изменения</button></p>
        </form>
    </div>
    <?php
}


    public function admin_scripts($hook) {
    // загружаем стили/скрипты на наших страницах подменю (hook содержит 'tackle-rating')
    if (strpos($hook, 'tackle-rating') === false) return;

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
	
	public function ajax_get_rating_html() {
    check_ajax_referer($this->nonce, 'nonce');

    $type_id = isset($_POST['type_id']) ? intval($_POST['type_id']) : 0;
    if (!$type_id) wp_send_json_error('Неверный type_id');

    ob_start();
    $this->render_frontend($type_id);
    $html = ob_get_clean();

    wp_send_json_success($html);
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

    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
    $new_name = isset($_POST['new_name']) ? sanitize_text_field($_POST['new_name']) : '';
    $new_category_id = isset($_POST['new_category_id']) ? intval($_POST['new_category_id']) : 0;

    if (!$new_name) wp_send_json_error('Введите новое название');
    if (count($ids) < 2) wp_send_json_error('Выберите минимум 2 варианта');
    if (!$new_category_id) wp_send_json_error('Выберите категорию');

    global $wpdb;

    // Получаем type_id первого элемента
    $type_id = $wpdb->get_var($wpdb->prepare(
        "SELECT rating_type_id FROM {$this->table_items} WHERE id = %d",
        $ids[0]
    ));

    if (!$type_id) wp_send_json_error('Ошибка: тип не найден');

    // Сумма голосов
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $total_votes = $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(votes) FROM {$this->table_items} WHERE id IN ($placeholders)",
        ...$ids
    ));

    // Удаляем старые записи
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$this->table_items} WHERE id IN ($placeholders)",
        ...$ids
    ));

    // Генерируем slug
    $slug = sanitize_title($new_name);
    $slug = substr($slug, 0, 191);

    // Добавляем новую запись
    $inserted = $wpdb->insert(
        $this->table_items,
        [
            'rating_type_id' => $type_id,
            'category_id'    => $new_category_id,
            'name'           => $new_name,
            'slug'           => $slug,
            'votes'          => intval($total_votes),
            'approved'       => 1
        ],
        ['%d','%d','%s','%s','%d','%d']
    );

    if (!$inserted) wp_send_json_error('Ошибка добавления: ' . $wpdb->last_error);

    wp_send_json_success('Варианты объединены');
}
	
	// AJAX: Удалить тип рейтинга
public function ajax_admin_delete_type() {
    check_ajax_referer($this->nonce, 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Доступ запрещён');

    $type_id = isset($_POST['type_id']) ? intval($_POST['type_id']) : 0;
    if (!$type_id) wp_send_json_error('Неверный ID');

    global $wpdb;

    // Удаляем все варианты этого типа
    $wpdb->delete($this->table_items, ['rating_type_id' => $type_id], ['%d']);

    // Удаляем все категории этого типа
    $wpdb->delete($this->table_categories, ['rating_type_id' => $type_id], ['%d']);

    // Удаляем сам тип
    $deleted = $wpdb->delete($this->table_types, ['id' => $type_id], ['%d']);

    if ($deleted === false) wp_send_json_error('Ошибка удаления типа рейтинга');

    wp_send_json_success('Рейтинг удалён');
}
	
	// AJAX: Удалить категорию
public function ajax_admin_delete_category() {
    check_ajax_referer($this->nonce, 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Доступ запрещён');

    $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
    $type_id     = isset($_POST['type_id']) ? intval($_POST['type_id']) : 0;

    if (!$category_id || !$type_id) wp_send_json_error('Неверные данные');

    global $wpdb;

    // Удаляем все варианты в этой категории
    $wpdb->delete($this->table_items, [
        'rating_type_id' => $type_id,
        'category_id'    => $category_id
    ], ['%d', '%d']);

    // Удаляем категорию
    $deleted = $wpdb->delete($this->table_categories, [
        'id'              => $category_id,
        'rating_type_id'  => $type_id
    ], ['%d', '%d']);

    if ($deleted === false) wp_send_json_error('Ошибка удаления категории');

    wp_send_json_success('Категория удалена');
}
	
	public function ajax_admin_set_type_fields() {
    check_ajax_referer($this->nonce, 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Нет прав');

    global $wpdb;
    $type_id = intval($_POST['type_id'] ?? 0);
    $fields  = (array)($_POST['fields'] ?? []);

    if (!$type_id) wp_send_json_error('Нет type_id');

    // Фильтруем по допустимым ключам
    $fields = array_values(array_intersect($fields, $this->allowed_field_keys));

    // чистим и пересоздаём
    $wpdb->delete($this->table_type_fields, ['rating_type_id' => $type_id], ['%d']);
    foreach ($fields as $key) {
        $wpdb->insert($this->table_type_fields, [
            'rating_type_id' => $type_id,
            'field_key'      => $key
        ], ['%d','%s']);
    }
    wp_send_json_success('Сохранено');
}

    // AJAX: Добавить вручную (админка)
    public function ajax_admin_add_manual() {
    check_ajax_referer($this->nonce, 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Доступ запрещён');

    $name        = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    $type_id     = isset($_POST['type_id']) ? intval($_POST['type_id']) : 0;
    $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;

    // дополнительные (могут быть не отправлены)
    $field_test   = isset($_POST['field_test'])   ? $this->normalize_test($_POST['field_test'])     : '';
    $field_length = isset($_POST['field_length']) ? $this->normalize_length($_POST['field_length']) : '';
    $field_spool  = isset($_POST['field_spool'])  ? $this->normalize_spool($_POST['field_spool'])   : '';

    if (!$name || !$type_id || !$category_id) wp_send_json_error('Введите название, тип и категорию');

    global $wpdb;

    $slug = $this->build_slug($name, $field_test, $field_length, $field_spool);

    $exists = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM {$this->table_items} 
        WHERE rating_type_id = %d AND category_id = %d AND slug = %s
    ", $type_id, $category_id, $slug));
    if ($exists) wp_send_json_error('Такой вариант уже есть');

    $ok = $wpdb->insert($this->table_items, [
        'rating_type_id' => $type_id,
        'category_id'    => $category_id,
        'name'           => $name,
        'slug'           => $slug,
        'field_test'     => $field_test,
        'field_length'   => $field_length,
        'field_spool'    => $field_spool,
        'votes'          => 1,
        'approved'       => 1
    ], ['%d','%d','%s','%s','%s','%s','%s','%d','%d']);

    if (!$ok) wp_send_json_error('Ошибка добавления: ' . $wpdb->last_error);
    wp_send_json_success('Вариант добавлен');
}
	
	// Добавление типа рейтинга
public function ajax_admin_add_type() {
    check_ajax_referer($this->nonce, 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Доступ запрещён');

    $name = isset($_POST['type_name']) ? sanitize_text_field($_POST['type_name']) : '';
    if (!$name) wp_send_json_error('Введите название');

    $slug = sanitize_title($name);

    $slug = substr($slug, 0, 255);

    global $wpdb;
    $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_types} WHERE slug = %s", $slug));
    if ($exists) wp_send_json_error('Такой тип уже существует');

    $inserted = $wpdb->insert(
        $this->table_types,
        ['name' => $name, 'slug' => $slug],
        ['%s', '%s']
    );
    if (!$inserted) wp_send_json_error('Ошибка добавления: ' . $wpdb->last_error);

    wp_send_json_success('Тип добавлен');
}
	
	public function ajax_admin_change_category() {
    check_ajax_referer($this->nonce, 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Доступ запрещён');

    $item_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $new_category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;

    if (!$item_id || !$new_category_id) wp_send_json_error('Неверные данные');

    global $wpdb;
    $updated = $wpdb->update(
        $this->table_items,
        ['category_id' => $new_category_id],
        ['id' => $item_id],
        ['%d'],
        ['%d']
    );

    if ($updated === false) wp_send_json_error('Ошибка обновления: ' . $wpdb->last_error);

    wp_send_json_success('Категория обновлена');
}

// Добавление категории к типу
public function ajax_admin_add_category() {
    check_ajax_referer($this->nonce, 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Доступ запрещён');

    $type_id = isset($_POST['type_id']) ? intval($_POST['type_id']) : 0;
    $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    if (!$type_id || !$name) wp_send_json_error('Неверные данные');

    global $wpdb;
    $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_categories} WHERE rating_type_id = %d AND LOWER(name) = LOWER(%s)", $type_id, $name));
    if ($exists) wp_send_json_error('Такая категория уже есть');

    $inserted = $wpdb->insert($this->table_categories, ['rating_type_id' => $type_id, 'name' => $name], ['%d','%s']);
    if (!$inserted) wp_send_json_error('Ошибка добавления: ' . $wpdb->last_error);

    wp_send_json_success('Категория добавлена');
}

    // Короткий код для фронтенда
    public function shortcode($atts) {
    $atts = shortcode_atts([
        'type_id' => 0
    ], $atts, 'tackle_rating');

    $type_id = intval($atts['type_id']);
    if (!$type_id) return '<p>Ошибка: не указан type_id в шорткоде.</p>';

    ob_start();
    $this->render_frontend($type_id);
    return ob_get_clean();
}

    private function render_frontend($type_id) {
    global $wpdb;

    // Получаем категории для этого типа
    $categories = $wpdb->get_results($wpdb->prepare("
        SELECT * FROM {$this->table_categories}
        WHERE rating_type_id = %d
        ORDER BY name ASC
    ", $type_id));

    if (!$categories) {
        echo "<p>Для этого рейтинга ещё нет категорий.</p>";
        return;
    }
		
		
		$active_field_keys = $wpdb->get_col(
    $wpdb->prepare("SELECT field_key FROM {$this->table_type_fields} WHERE rating_type_id = %d", $type_id)
);

    // Получаем все варианты для этого типа
    $items = $wpdb->get_results($wpdb->prepare("
        SELECT i.*, c.name AS category_name
        FROM {$this->table_items} i
        LEFT JOIN {$this->table_categories} c ON i.category_id = c.id
        WHERE i.rating_type_id = %d AND i.approved = 1
        ORDER BY c.name ASC, i.votes DESC, i.name ASC
    ", $type_id));

    if (!$items) {
        echo "<p>Вариантов пока нет.</p>";
        return;
    }

    // Группируем по категориям
    $grouped = [];
    $max_votes = 0;
    foreach ($items as $item) {
        $grouped[$item->category_name][] = $item;
        if ($item->votes > $max_votes) $max_votes = $item->votes;
    }

    ?>
    <div class="tackle-rating-frontend" data-type-id="<?= intval($type_id) ?>">
		<form class="tackle-vote-form">
			<?php foreach ($grouped as $category_name => $category_items): ?>
				<h3><?= esc_html($category_name) ?></h3>
				<ol>
					<?php foreach ($category_items as $item):
						$percent = $max_votes ? round(($item->votes / $max_votes) * 100) : 0;
					?>
						<li id="tackle-item-<?= intval($item->id) ?>" style="margin-bottom: 10px;">
							<label>
								<input type="checkbox" name="vote_ids[]" value="<?= intval($item->id) ?>" />
								<strong><?= esc_html($item->name) ?></strong> —
								<span class="votes-count"><?= intval($item->votes) ?></span> голосов
								(<span class="percent-count"><?= $percent ?>%</span>)
							</label>
							<div style="background: #ddd; width: 100%; height: 10px; border-radius: 5px; margin-top: 5px;">
								<div class="progress-bar" style="background: #4a74a4; width: <?= $percent ?>%; height: 10px; border-radius: 5px;"></div>
							</div>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endforeach; ?>
			<button type="submit" class="button button-primary btn">Проголосовать</button>
		</form>

        <h3>Предложить свой вариант</h3>
        <form class="tackle-suggest-form">
            <input type="hidden" name="type_id" value="<?= intval($type_id) ?>">
            <input class="tackle-suggest" type="text" name="name" placeholder="Название варианта" required style="width: 300px;" />
			<label>
                <select name="category_id" required>
                    <option value="">Выберите категорию</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= intval($cat->id) ?>"><?= esc_html($cat->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
			<?php if (in_array('test',$active_field_keys)): ?>
			  <p><label>Тест:</label><br><input type="text" name="test"></p>
			<?php endif; ?>
			<?php if (in_array('length',$active_field_keys)): ?>
			  <p><label>Длина:</label><br><input type="text" name="length"></p>
			<?php endif; ?>
			<?php if (in_array('spool',$active_field_keys)): ?>
			  <p><label>Объём шпули:</label><br><input type="text" name="spool"></p>
			<?php endif; ?>
            <button class="btn" type="submit">Предложить</button>
        </form>
        <div class="tackle-suggest-msg" style="margin-top: 10px;"></div>
    </div>
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

    $type_id = isset($_POST['type_id']) ? intval($_POST['type_id']) : 0;

    // placeholders для id
    $ids_placeholders = implode(',', array_fill(0, count($ids), '%d'));

    // проверяем, что все найденные и одобрены (и если передан type_id — что принадлежат ему)
    $params = $ids;
    $sql = "SELECT COUNT(*) FROM {$this->table_items} WHERE id IN ($ids_placeholders) AND approved = 1";
    if ($type_id) {
        $sql .= " AND rating_type_id = %d";
        $params[] = $type_id;
    }
    $count = $wpdb->get_var($wpdb->prepare($sql, ...$params));
    if ($count != count($ids)) {
        wp_send_json_error('Некоторые варианты не найдены или не одобрены');
    }

    // обновляем голоса
    foreach ($ids as $id) {
        $wpdb->query($wpdb->prepare("UPDATE {$this->table_items} SET votes = votes + 1 WHERE id = %d", $id));
    }

    // получаем список элементов для пересчёта процентов (по тому же type_id, если есть)
    if ($type_id) {
        $results = $wpdb->get_results($wpdb->prepare("SELECT id, votes FROM {$this->table_items} WHERE approved = 1 AND rating_type_id = %d ORDER BY votes DESC", $type_id), ARRAY_A);
    } else {
        $results = $wpdb->get_results("SELECT id, votes FROM {$this->table_items} WHERE approved = 1 ORDER BY votes DESC", ARRAY_A);
    }

    $max_votes = 0;
    foreach ($results as $row) {
        if ($row['votes'] > $max_votes) $max_votes = $row['votes'];
    }

    $data = [];
    foreach ($results as $row) {
        $percent = $max_votes ? round(($row['votes'] / $max_votes) * 100) : 0;
        $data[intval($row['id'])] = [
            'votes' => intval($row['votes']),
            'percent' => $percent
        ];
    }

    wp_send_json_success($data);
}



    // AJAX: Предложить вариант
	
		public function ajax_tackle_suggest() {
    check_ajax_referer($this->nonce, 'nonce');

    global $wpdb;
    $name        = sanitize_text_field($_POST['name'] ?? '');
    $type_id     = intval($_POST['type_id'] ?? 0);
    $category_id = intval($_POST['category_id'] ?? 0);

    $field_test   = $this->normalize_test($_POST['test']   ?? '');
    $field_length = $this->normalize_length($_POST['length'] ?? '');
    $field_spool  = $this->normalize_spool($_POST['spool']  ?? '');

    if (!$name || !$type_id || !$category_id) {
        wp_send_json_error('Заполните все обязательные поля');
    }

    $slug = $this->build_slug($name, $field_test, $field_length, $field_spool);

    $exists = $wpdb->get_row($wpdb->prepare("
        SELECT * FROM {$this->table_items}
        WHERE rating_type_id=%d AND category_id=%d AND slug=%s
    ", $type_id, $category_id, $slug));

    if ($exists) {
        // если есть — +1 голос
        $wpdb->update($this->table_items, [
            'approved' => 1,
            'votes'    => $exists->votes + 1
        ], ['id' => $exists->id], ['%d','%d'], ['%d']);
        wp_send_json_success('Такой вариант уже был — добавили +1 голос');
    }

    // создаём, но не одобряем
    $ok = $wpdb->insert($this->table_items, [
        'rating_type_id' => $type_id,
        'category_id'    => $category_id,
        'name'           => $name,
        'slug'           => $slug,
        'field_test'     => $field_test,
        'field_length'   => $field_length,
        'field_spool'    => $field_spool,
        'votes'          => 1,
        'approved'       => 0
    ], ['%d','%d','%s','%s','%s','%s','%s','%d','%d']);

    if ($ok) wp_send_json_success('Ваш вариант отправлен на модерацию');
    wp_send_json_error('Ошибка добавления');
}

}

new TackleRatingPlugin();
