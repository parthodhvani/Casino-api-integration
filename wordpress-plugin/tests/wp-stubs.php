<?php
/**
 * Minimal in-memory WordPress stubs for functional testing of the plugin
 * services WITHOUT a real WordPress install. Only the functions/classes the
 * plugin actually uses are implemented, with just enough behavior to exercise
 * the create / update / skip / validate / image-resolve paths.
 *
 * @package JackpotSync\Tests
 */

error_reporting(E_ALL & ~E_DEPRECATED);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

$GLOBALS['_jp_posts']    = [];   // id => post array
$GLOBALS['_jp_options']  = [];
$GLOBALS['_jp_trans']    = [];
$GLOBALS['_jp_next_id']  = 1;
$GLOBALS['_jp_actions']  = [];

/* ---- Error / response objects ---------------------------------------- */

class WP_Error {
    private $code;
    private $message;
    private $data;
    public function __construct($code = '', $message = '', $data = '') {
        $this->code = $code; $this->message = $message; $this->data = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}

function is_wp_error($thing) { return $thing instanceof WP_Error; }

class WP_REST_Response {
    private $data; private $status;
    public function __construct($data = null, $status = 200) { $this->data = $data; $this->status = $status; }
    public function get_data() { return $this->data; }
    public function get_status() { return $this->status; }
}

class WP_REST_Request {
    private $body; private $headers; private $json;
    public function __construct($body = '', $headers = []) {
        $this->body = $body;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->json = json_decode($body, true);
    }
    public function get_body() { return $this->body; }
    public function get_header($name) { $n = strtolower($name); return $this->headers[$n] ?? null; }
    public function get_json_params() { return $this->json; }
}

/* ---- Options ---------------------------------------------------------- */

function get_option($key, $default = false) {
    return array_key_exists($key, $GLOBALS['_jp_options']) ? $GLOBALS['_jp_options'][$key] : $default;
}
function update_option($key, $value, $autoload = null) { $GLOBALS['_jp_options'][$key] = $value; return true; }
function add_option($key, $value) { $GLOBALS['_jp_options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['_jp_options'][$key]); return true; }

function get_transient($k) { return $GLOBALS['_jp_trans'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['_jp_trans'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['_jp_trans'][$k]); return true; }

/* ---- Misc helpers ----------------------------------------------------- */

function wp_parse_args($args, $defaults = []) {
    $args = is_array($args) ? $args : [];
    return array_merge($defaults, $args);
}
function sanitize_text_field($str) { return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $str))); }
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
function current_time($type = 'mysql', $gmt = 0) { return $type === 'timestamp' ? time() : date('Y-m-d H:i:s'); }
function wp_json_encode($data) { return json_encode($data); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function __($s, $d = null) { return $s; }
function esc_html__($s, $d = null) { return $s; }

function add_action($hook, $cb, $prio = 10, $args = 1) { $GLOBALS['_jp_actions'][$hook][] = $cb; return true; }
function add_filter($hook, $cb, $prio = 10, $args = 1) { return true; }
function do_action($hook, ...$a) { return true; }
function apply_filters($hook, $value, ...$a) { return $value; }
function register_rest_route($ns, $route, $args) { return true; }
function rest_url($path = '') { return 'https://example.test/wp-json/' . ltrim($path, '/'); }
function post_type_exists($t) { return true; }
function wp_next_scheduled($h) { return time() + 3600; }
function wp_schedule_event($t, $r, $h) { return true; }
function wp_clear_scheduled_hook($h) { return true; }
function delete_expired_transients($force = false) { return 0; }
function wp_cache_flush() { return true; }
function wp_cache_delete($id, $group = '') { return true; }
function clean_post_cache($id) { return true; }

/* ---- Posts / meta ----------------------------------------------------- */

function wp_insert_post($args, $wp_error = false) {
    $id = $GLOBALS['_jp_next_id']++;
    $GLOBALS['_jp_posts'][$id] = [
        'ID'            => $id,
        'post_type'     => $args['post_type'] ?? 'post',
        'post_status'   => $args['post_status'] ?? 'publish',
        'post_title'    => $args['post_title'] ?? '',
        'post_modified' => current_time('mysql'),
        'meta'          => [],
        'thumbnail'     => 0,
    ];
    return $id;
}

function wp_update_post($args) {
    $id = (int) ($args['ID'] ?? 0);
    if (isset($GLOBALS['_jp_posts'][$id])) {
        foreach ($args as $k => $v) {
            if ($k !== 'ID') { $GLOBALS['_jp_posts'][$id][$k] = $v; }
        }
    }
    return $id;
}

function wp_delete_post($id, $force = false) { unset($GLOBALS['_jp_posts'][$id]); return true; }

function update_post_meta($id, $key, $value) {
    if (!isset($GLOBALS['_jp_posts'][$id])) { return false; }
    $GLOBALS['_jp_posts'][$id]['meta'][$key] = $value;
    return true;
}
function get_post_meta($id, $key = '', $single = false) {
    if (!isset($GLOBALS['_jp_posts'][$id])) { return $single ? '' : []; }
    if ($key === '') { return $GLOBALS['_jp_posts'][$id]['meta']; }
    $v = $GLOBALS['_jp_posts'][$id]['meta'][$key] ?? '';
    return $single ? $v : [$v];
}
function set_post_thumbnail($id, $thumb) {
    if (isset($GLOBALS['_jp_posts'][$id])) { $GLOBALS['_jp_posts'][$id]['thumbnail'] = $thumb; return true; }
    return false;
}
function get_post_thumbnail_id($id) { return $GLOBALS['_jp_posts'][$id]['thumbnail'] ?? 0; }

/* ---- Query engine ----------------------------------------------------- */

function _jp_meta_matches($post, $meta_query) {
    if (empty($meta_query)) { return true; }
    $relation = strtoupper($meta_query['relation'] ?? 'AND');
    $clauses  = array_filter($meta_query, 'is_array');
    $results  = [];
    foreach ($clauses as $clause) {
        $key     = $clause['key'] ?? '';
        $value   = $clause['value'] ?? '';
        $compare = strtoupper($clause['compare'] ?? '=');
        $have    = $post['meta'][$key] ?? null;
        if ($have === null) { $results[] = false; continue; }
        if ($compare === 'LIKE') {
            $results[] = (stripos((string) $have, (string) $value) !== false);
        } else {
            $results[] = ((string) $have === (string) $value);
        }
    }
    if (empty($results)) { return true; }
    return $relation === 'OR' ? in_array(true, $results, true) : !in_array(false, $results, true);
}

function _jp_run_query($args) {
    $posts = array_values($GLOBALS['_jp_posts']);

    if (!empty($args['post_type'])) {
        $posts = array_filter($posts, fn($p) => $p['post_type'] === $args['post_type']);
    }
    if (!empty($args['post_status']) && $args['post_status'] !== 'any') {
        $status = (array) $args['post_status'];
        $posts = array_filter($posts, fn($p) => in_array($p['post_status'], $status, true));
    }
    if (isset($args['title']) && $args['title'] !== '') {
        $posts = array_filter($posts, fn($p) => $p['post_title'] === $args['title']);
    }
    if (isset($args['s']) && $args['s'] !== '') {
        $posts = array_filter($posts, fn($p) => stripos($p['post_title'], $args['s']) !== false);
    }
    if (!empty($args['meta_query'])) {
        $posts = array_filter($posts, fn($p) => _jp_meta_matches($p, $args['meta_query']));
    }

    $posts = array_values($posts);

    $orderby = $args['orderby'] ?? '';
    if ($orderby === 'modified') {
        usort($posts, fn($a, $b) => strcmp($b['post_modified'], $a['post_modified']));
    }

    if (isset($args['offset']) && (int) $args['offset'] > 0) {
        $posts = array_slice($posts, (int) $args['offset']);
    }

    $ppp = $args['posts_per_page'] ?? -1;
    if ($ppp !== -1) {
        $posts = array_slice($posts, 0, (int) $ppp);
    }

    return $posts;
}

class WP_Query {
    public $posts = [];
    public function __construct($args = []) {
        $rows = _jp_run_query($args);
        if (($args['fields'] ?? '') === 'ids') {
            $this->posts = array_map(fn($p) => $p['ID'], $rows);
        } else {
            $this->posts = $rows;
        }
    }
    public function have_posts() { return !empty($this->posts); }
}

function get_posts($args = []) {
    $rows = _jp_run_query($args);
    if (($args['fields'] ?? '') === 'ids') {
        return array_map(fn($p) => $p['ID'], $rows);
    }
    return $rows;
}

/* ---- Test helpers ----------------------------------------------------- */

function _jp_reset_store() {
    $GLOBALS['_jp_posts']   = [];
    $GLOBALS['_jp_options'] = [];
    $GLOBALS['_jp_trans']   = [];
    $GLOBALS['_jp_next_id'] = 1;
}

function _jp_seed_attachment($title, $attached_file) {
    $id = $GLOBALS['_jp_next_id']++;
    $GLOBALS['_jp_posts'][$id] = [
        'ID'            => $id,
        'post_type'     => 'attachment',
        'post_status'   => 'inherit',
        'post_title'    => $title,
        'post_modified' => current_time('mysql'),
        'meta'          => ['_wp_attached_file' => $attached_file],
        'thumbnail'     => 0,
    ];
    return $id;
}
