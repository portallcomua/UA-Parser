<?php
/**
 * Plugin Name: UA Parser
 * Description: Збір даних з однієї публічної сторінки та базова публікація у WordPress/WooCommerce.
 * Version: 5.0.0-alpha.1
 * Author: portallcomua
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: ua-parser
 */
if (!defined('ABSPATH')) exit;

final class UA_Parser_50 {
    const VERSION = '5.0.0-alpha.1';
    const NONCE = 'uap50_nonce';
    const STORE = 'uap50_items_';

    public function __construct() {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
        add_action('wp_ajax_uap50_scan', array($this, 'scan'));
        add_action('wp_ajax_uap50_collect', array($this, 'collect'));
        add_action('wp_ajax_uap50_publish', array($this, 'publish'));
        add_action('admin_post_uap50_export', array($this, 'export'));
    }

    public function menu() {
        add_menu_page('UA Parser', 'UA Parser', 'manage_options', 'ua-parser', array($this, 'page'), 'dashicons-search', 58);
    }

    public function assets($hook) {
        if ($hook !== 'toplevel_page_ua-parser') return;
        wp_enqueue_style('uap50', plugin_dir_url(__FILE__) . 'assets/admin.css', array(), self::VERSION);
        wp_enqueue_script('uap50', plugin_dir_url(__FILE__) . 'assets/admin.js', array('jquery'), self::VERSION, true);
        wp_localize_script('uap50', 'UAP50', array(
            'ajax' => admin_url('admin-ajax.php'),
            'export' => admin_url('admin-post.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'exportNonce' => wp_create_nonce('uap50_export')
        ));
    }

    public function page() {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap uap50">
            <h1>UA Parser <small>5.0 alpha.1</small></h1>
            <p>Збір даних з публічної сторінки та публікація результатів на цьому WordPress-сайті.</p>
            <section class="uap50-card">
                <label for="uap50-url"><b>URL сайту або сторінки</b></label>
                <div class="uap50-row">
                    <input id="uap50-url" type="url" placeholder="https://example.com/product/example">
                    <button class="button" id="uap50-scan">Аналізувати</button>
                    <button class="button button-primary" id="uap50-collect">Зібрати</button>
                </div>
                <p class="description">Alpha.1 обробляє одну сторінку за раз. Перевіряйте права на використання контенту джерела.</p>
            </section>
            <section class="uap50-card">
                <div class="uap50-progress-label"><b id="uap50-status">Очікування</b><span id="uap50-percent">0%</span></div>
                <div class="uap50-progress"><span id="uap50-bar"></span></div>
                <div id="uap50-fields"></div>
            </section>
            <section class="uap50-card">
                <h2>Зібрані дані</h2>
                <div id="uap50-results"><p>Поки немає результатів.</p></div>
                <div class="uap50-row uap50-actions">
                    <button class="button" id="uap50-export" disabled>Експорт CSV</button>
                    <select id="uap50-type">
                        <option value="post">Записи WordPress</option>
                        <option value="page">Сторінки WordPress</option>
                        <option value="product">Товари WooCommerce</option>
                    </select>
                    <select id="uap50-status">
                        <option value="draft">Чернетка</option>
                        <option value="publish">Опублікувати одразу</option>
                    </select>
                    <button class="button button-primary" id="uap50-publish" disabled>Опублікувати на цьому сайті</button>
                </div>
            </section>
            <section class="uap50-card"><h2>Журнал</h2><pre id="uap50-log">UA Parser готовий.</pre></section>
            <p class="description">Це рання alpha-збірка. Пакетний обхід, візуальний вибір елементів, профілі експорту, галереї/атрибути товарів та updater ще не реалізовані.</p>
        </div>
        <?php
    }

    private function auth() {
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Недостатньо прав.'), 403);
        check_ajax_referer(self::NONCE, 'nonce');
    }

    private function safe_url($url) {
        $url = esc_url_raw(trim((string) $url), array('http', 'https'));
        if (!$url || !wp_http_validate_url($url)) return new WP_Error('uap_url', 'Некоректна HTTP/HTTPS адреса.');
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if (!$host || $host === 'localhost' || substr($host, -6) === '.local') return new WP_Error('uap_host', 'Локальні адреси заборонені.');
        $ip = gethostbyname($host);
        if ($ip === $host || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return new WP_Error('uap_ip', 'Приватні або зарезервовані IP-адреси заборонені.');
        return $url;
    }

    private function get_html($url) {
        $safe = $this->safe_url($url);
        if (is_wp_error($safe)) return $safe;
        $response = wp_safe_remote_get($safe, array(
            'timeout' => 20,
            'redirection' => 0,
            'limit_response_size' => 2500000,
            'headers' => array('User-Agent' => 'UA-Parser/5.0 WordPress plugin')
        ));
        if (is_wp_error($response)) return $response;
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code > 299) return new WP_Error('uap_http', 'Сайт повернув HTTP ' . $code . '.');
        $html = (string) wp_remote_retrieve_body($response);
        if ($html === '') return new WP_Error('uap_empty', 'Отримано порожню відповідь.');
        return $html;
    }

    private function absolute_url($value, $base) {
        $value = trim((string) $value);
        if ($value === '' || strpos($value, 'data:') === 0) return '';
        if (strpos($value, '//') === 0) return (wp_parse_url($base, PHP_URL_SCHEME) ?: 'https') . ':' . $value;
        if (preg_match('#^https?://#i', $value)) return $value;
        $parts = wp_parse_url($base);
        if (!$parts || empty($parts['host'])) return '';
        $origin = (isset($parts['scheme']) ? $parts['scheme'] : 'https') . '://' . $parts['host'];
        if (isset($parts['port'])) $origin .= ':' . (int) $parts['port'];
        if (strpos($value, '/') === 0) return $origin . $value;
        return $origin . trailingslashit(dirname(isset($parts['path']) ? $parts['path'] : '/')) . $value;
    }

    private function extract($html, $url) {
        $old = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $ok = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($old);
        if (!$ok) return new WP_Error('uap_html', 'Не вдалося розібрати HTML.');
        $xp = new DOMXPath($dom);

        $titleNode = $xp->query('//h1')->item(0);
        if (!$titleNode) $titleNode = $xp->query('//title')->item(0);
        $title = $titleNode ? trim(preg_replace('/\s+/u', ' ', $titleNode->textContent)) : '';

        $descNode = $xp->query('//meta[translate(@name,"DESCRIPTION","description")="description"]/@content')->item(0);
        $description = $descNode ? trim($descNode->nodeValue) : '';
        if (!$description) {
            $main = $xp->query('//article | //main | //*[@role="main"]')->item(0);
            if ($main) $description = trim(preg_replace('/\s+/u', ' ', $main->textContent));
        }

        $price = '';
        foreach (array('//*[@itemprop="price"]/@content', '//*[@itemprop="price"]', '//*[contains(@class,"price") or contains(@class,"Price")]') as $query) {
            $node = $xp->query($query)->item(0);
            if ($node) {
                $price = trim($node instanceof DOMAttr ? $node->value : $node->textContent);
                if ($price !== '') break;
            }
        }

        $images = array();
        foreach ($xp->query('//meta[@property="og:image"]/@content | //img[@src]/@src') as $node) {
            $src = $this->absolute_url($node->nodeValue, $url);
            if ($src && !in_array($src, $images, true)) $images[] = esc_url_raw($src);
            if (count($images) >= 10) break;
        }

        $canonical = $xp->query('//link[@rel="canonical"]/@href')->item(0);
        $source = $canonical ? $this->absolute_url($canonical->nodeValue, $url) : $url;
        return array(
            'id' => wp_generate_uuid4(),
            'source_url' => esc_url_raw($source),
            'title' => sanitize_text_field($title),
            'description' => wp_kses_post($description),
            'price' => sanitize_text_field($price),
            'images' => $images
        );
    }

    public function scan() {
        $this->auth();
        $url = isset($_POST['url']) ? wp_unslash($_POST['url']) : '';
        $html = $this->get_html($url);
        if (is_wp_error($html)) wp_send_json_error(array('message' => $html->get_error_message()), 400);
        $item = $this->extract($html, $url);
        if (is_wp_error($item)) wp_send_json_error(array('message' => $item->get_error_message()), 400);
        wp_send_json_success(array('fields' => array(
            array('label' => 'Заголовок', 'value' => $item['title']),
            array('label' => 'Опис', 'value' => wp_strip_all_tags($item['description'])),
            array('label' => 'Ціна', 'value' => $item['price']),
            array('label' => 'Зображень знайдено', 'value' => count($item['images']))
        )));
    }

    public function collect() {
        $this->auth();
        $url = isset($_POST['url']) ? wp_unslash($_POST['url']) : '';
        $html = $this->get_html($url);
        if (is_wp_error($html)) wp_send_json_error(array('message' => $html->get_error_message()), 400);
        $item = $this->extract($html, $url);
        if (is_wp_error($item)) wp_send_json_error(array('message' => $item->get_error_message()), 400);
        $key = self::STORE . get_current_user_id();
        $items = get_transient($key);
        if (!is_array($items)) $items = array();
        $items[] = $item;
        set_transient($key, $items, DAY_IN_SECONDS);
        wp_send_json_success(array('items' => $items, 'count' => count($items)));
    }

    private function results() {
        $items = get_transient(self::STORE . get_current_user_id());
        return is_array($items) ? $items : array();
    }

    private function price_value($raw) {
        $value = preg_replace('/[^0-9,.]/u', '', html_entity_decode(wp_strip_all_tags((string) $raw), ENT_QUOTES, 'UTF-8'));
        if (substr_count($value, ',') === 1 && strpos($value, '.') === false) $value = str_replace(',', '.', $value);
        else $value = str_replace(',', '', $value);
        return is_numeric($value) ? wc_format_decimal($value) : '';
    }

    private function image_id($url, $post_id) {
        if (!wp_http_validate_url($url)) return 0;
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $id = media_sideload_image(esc_url_raw($url), $post_id, null, 'id');
        return is_wp_error($id) ? 0 : (int) $id;
    }

    public function publish() {
        $this->auth();
        $items = $this->results();
        if (!$items) wp_send_json_error(array('message' => 'Немає зібраних даних.'), 400);
        $type = isset($_POST['post_type']) ? sanitize_key($_POST['post_type']) : 'post';
        $status = isset($_POST['post_status']) ? sanitize_key($_POST['post_status']) : 'draft';
        if (!in_array($type, array('post', 'page', 'product'), true)) wp_send_json_error(array('message' => 'Непідтримуваний тип.'), 400);
        if (!in_array($status, array('draft', 'publish'), true)) $status = 'draft';
        if ($type === 'product' && !class_exists('WooCommerce')) wp_send_json_error(array('message' => 'WooCommerce не активований.'), 400);

        $created = array();
        $duplicates = 0;
        foreach ($items as $item) {
            $source = isset($item['source_url']) ? esc_url_raw($item['source_url']) : '';
            $existing = get_posts(array(
                'post_type' => $type === 'product' ? 'product' : $type,
                'post_status' => array('publish', 'draft', 'pending', 'private'),
                'meta_key' => '_uap50_source_url',
                'meta_value' => $source,
                'fields' => 'ids',
                'numberposts' => 1
            ));
            if ($existing) { $duplicates++; continue; }

            $title = !empty($item['title']) ? sanitize_text_field($item['title']) : 'Матеріал без назви';
            $description = !empty($item['description']) ? wp_kses_post($item['description']) : '';
            if ($type === 'product') {
                $product = new WC_Product_Simple();
                $product->set_name($title);
                $product->set_status($status);
                $product->set_description($description);
                $price = $this->price_value(isset($item['price']) ? $item['price'] : '');
                if ($price !== '') $product->set_regular_price($price);
                $product->update_meta_data('_uap50_source_url', $source);
                $post_id = $product->save();
            } else {
                $post_id = wp_insert_post(array(
                    'post_type' => $type,
                    'post_status' => $status,
                    'post_title' => $title,
                    'post_content' => $description,
                    'meta_input' => array('_uap50_source_url' => $source)
                ), true);
                if (is_wp_error($post_id)) continue;
            }

            if ($post_id && !empty($item['images'][0])) {
                $attachment = $this->image_id($item['images'][0], $post_id);
                if ($attachment) set_post_thumbnail($post_id, $attachment);
            }
            if ($post_id) $created[] = array('id' => (int) $post_id, 'title' => $title);
        }
        wp_send_json_success(array('created' => $created, 'count' => count($created), 'duplicates' => $duplicates));
    }

    public function export() {
        if (!current_user_can('manage_options')) wp_die('Недостатньо прав.');
        check_admin_referer('uap50_export');
        $items = $this->results();
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=ua-parser-export.csv');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array('source_url', 'title', 'description', 'price', 'images'), ';');
        foreach ($items as $item) {
            fputcsv($out, array($item['source_url'], $item['title'], wp_strip_all_tags($item['description']), $item['price'], implode('|', $item['images'])), ';');
        }
        fclose($out);
        exit;
    }
}
new UA_Parser_50();
