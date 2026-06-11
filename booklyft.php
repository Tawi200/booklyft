<?php
/*
Plugin Name: Booklyft
Description: Booking system with services, availability, bookings, admin management, and email notifications.
Version: 1.1.0
Author: Tawanda Onyimo
Text Domain: booklyft
*/

if (!defined('ABSPATH')) exit;

class Booklyft {
    const VERSION = '1.1.0';
    const BOOKINGS_TABLE = 'booklyft_bookings';
    const SERVICES_TABLE = 'booklyft_services';
    const SETTINGS_KEY = 'booklyft_settings';

    public function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_booklyft_save_service', [$this, 'save_service']);
        add_action('admin_post_booklyft_delete_service', [$this, 'delete_service']);
        add_action('admin_post_booklyft_update_booking', [$this, 'update_booking']);
        add_action('admin_post_booklyft_submit_booking', [$this, 'handle_booking']);
        add_action('admin_post_nopriv_booklyft_submit_booking', [$this, 'handle_booking']);
        add_shortcode('booklyft_booking_form', [$this, 'booking_form_shortcode']);
        add_shortcode('booklyft_services', [$this, 'services_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_booklyft_check_availability', [$this, 'ajax_check_availability']);
        add_action('wp_ajax_nopriv_booklyft_check_availability', [$this, 'ajax_check_availability']);
    }

    public static function activate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $bookings = $wpdb->prefix . self::BOOKINGS_TABLE;
        $services = $wpdb->prefix . self::SERVICES_TABLE;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE $services (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(200) NOT NULL,
            duration int NOT NULL DEFAULT 60,
            price decimal(10,2) NOT NULL DEFAULT 0,
            description text NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset;");

        dbDelta("CREATE TABLE $bookings (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            service_id bigint(20) unsigned NOT NULL,
            customer_name varchar(200) NOT NULL,
            customer_email varchar(200) NOT NULL,
            customer_phone varchar(50) NULL,
            booking_date date NOT NULL,
            booking_time time NOT NULL,
            status varchar(50) NOT NULL DEFAULT 'pending',
            notes text NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY service_id (service_id),
            KEY booking_date (booking_date),
            KEY status (status)
        ) $charset;");

        if (!get_option(self::SETTINGS_KEY)) {
            add_option(self::SETTINGS_KEY, [
                'brand_name' => 'Booklyft',
                'admin_color' => '#7b1020',
                'admin_accent' => '#ef5a3c',
                'from_email' => get_option('admin_email'),
                'timezone' => get_option('timezone_string') ?: 'UTC',
                'slots_enabled' => '1',
            ]);
        }
    }

    public function get_settings() {
        $defaults = [
            'brand_name' => 'Booklyft',
            'admin_color' => '#7b1020',
            'admin_accent' => '#ef5a3c',
            'from_email' => get_option('admin_email'),
            'timezone' => get_option('timezone_string') ?: 'UTC',
            'slots_enabled' => '1',
        ];
        return wp_parse_args(get_option(self::SETTINGS_KEY, []), $defaults);
    }

    public function register_cpt() {
        register_post_type('booklyft_service', [
            'labels' => ['name' => 'Booklyft Services', 'singular_name' => 'Service'],
            'public' => false,
            'show_ui' => false,
            'supports' => ['title', 'editor'],
        ]);
    }

    public function admin_menu() {
        add_menu_page('Booklyft', 'Booklyft', 'manage_options', 'booklyft', [$this, 'admin_page'], 'dashicons-calendar-alt', 26);
        add_submenu_page('booklyft', 'Bookings', 'Bookings', 'manage_options', 'booklyft', [$this, 'admin_page']);
        add_submenu_page('booklyft', 'Services', 'Services', 'manage_options', 'booklyft-services', [$this, 'services_page']);
        add_submenu_page('booklyft', 'Settings', 'Settings', 'manage_options', 'booklyft-settings', [$this, 'settings_page']);
    }

    public function register_settings() {
        register_setting('booklyft_settings_group', self::SETTINGS_KEY, [$this, 'sanitize_settings']);
    }

    public function sanitize_settings($input) {
        return [
            'brand_name' => sanitize_text_field($input['brand_name'] ?? 'Booklyft'),
            'admin_color' => sanitize_hex_color($input['admin_color'] ?? '#7b1020') ?: '#7b1020',
            'admin_accent' => sanitize_hex_color($input['admin_accent'] ?? '#ef5a3c') ?: '#ef5a3c',
            'from_email' => sanitize_email($input['from_email'] ?? get_option('admin_email')),
            'timezone' => sanitize_text_field($input['timezone'] ?? 'UTC'),
            'slots_enabled' => !empty($input['slots_enabled']) ? '1' : '0',
        ];
    }

    public function admin_colors_css() {
        $s = $this->get_settings();
        return '<style>
            #adminmenu #toplevel_page_booklyft .wp-menu-image:before,
            #adminmenu #toplevel_page_booklyft.current .wp-menu-image:before { color: ' . esc_attr($s['admin_accent']) . '; }
            .booklyft-wrap{max-width:1100px;margin:20px 20px 20px 0;padding:0}
            .booklyft-card{background:#fff;border:1px solid #eed9d7;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);padding:18px;margin:0 0 18px}
            .booklyft-hero{background:linear-gradient(135deg,' . esc_attr($s['admin_color']) . ', ' . esc_attr($s['admin_accent']) . ');color:#fff;border-radius:16px;padding:20px 22px;margin-bottom:18px}
            .booklyft-hero h1,.booklyft-hero h2,.booklyft-hero h3{color:#fff;margin:0}
            .booklyft-btn,.button.button-primary{background:' . esc_attr($s['admin_color']) . ';border-color:' . esc_attr($s['admin_color']) . ';color:#fff}
            .booklyft-btn:hover,.button.button-primary:hover{background:' . esc_attr($s['admin_accent']) . ';border-color:' . esc_attr($s['admin_accent']) . ';color:#fff}
            .booklyft-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #eed9d7;border-radius:10px;overflow:hidden}
            .booklyft-table th{background:#fbeceb;color:' . esc_attr($s['admin_color']) . ';text-align:left;padding:10px;border-bottom:1px solid #eed9d7}
            .booklyft-table td{padding:10px;border-bottom:1px solid #f2e3e1}
            .booklyft-field{display:flex;flex-direction:column;gap:6px;margin-bottom:12px}
            .booklyft-field input,.booklyft-field select,.booklyft-field textarea{padding:10px;border:1px solid #d8c2bf;border-radius:8px}
            .booklyft-field input:focus,.booklyft-field select:focus,.booklyft-field textarea:focus{border-color:' . esc_attr($s['admin_accent']) . ';box-shadow:0 0 0 1px ' . esc_attr($s['admin_accent']) . ';outline:none}
            .booklyft-ok{background:#f8e9e6;color:' . esc_attr($s['admin_color']) . '}
            .booklyft-err{background:#fdecec;color:#a11}
            .booklyft-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}
            .booklyft-pill{display:inline-block;background:#fbeceb;color:' . esc_attr($s['admin_color']) . ';padding:5px 10px;border-radius:999px;font-size:12px}
        </style>';
    }

    public function enqueue_assets() {
        wp_register_style('booklyft', false, [], self::VERSION);
        wp_add_inline_style('booklyft', '.booklyft-wrap{max-width:900px;margin:20px auto;padding:20px;border:1px solid #ddd;border-radius:10px}.booklyft-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.booklyft-field{display:flex;flex-direction:column;gap:6px;margin-bottom:12px}.booklyft-field input,.booklyft-field select,.booklyft-field textarea{padding:10px;border:1px solid #ccc;border-radius:6px}.booklyft-btn{background:#1e73be;color:#fff;border:none;padding:12px 16px;border-radius:6px;cursor:pointer}.booklyft-msg{padding:10px;margin:10px 0;border-radius:6px}.booklyft-ok{background:#e7f7ea;color:#1c6b2a}.booklyft-err{background:#fdecec;color:#a11}.booklyft-table{width:100%;border-collapse:collapse}.booklyft-table th,.booklyft-table td{border:1px solid #ddd;padding:8px;text-align:left}');
        wp_enqueue_style('booklyft');
    }

    public function services_shortcode() {
        global $wpdb;
        $table = $wpdb->prefix . self::SERVICES_TABLE;
        $services = $wpdb->get_results("SELECT * FROM $table WHERE active=1 ORDER BY id DESC");
        ob_start();
        echo '<div class="booklyft-wrap"><h2>Services</h2><div class="booklyft-grid">';
        foreach ($services as $s) {
            echo '<div class="booklyft-card"><h3>' . esc_html($s->name) . '</h3><p>' . esc_html($s->description) . '</p><p><strong>Duration:</strong> ' . intval($s->duration) . ' minutes</p><p><strong>Price:</strong> ' . esc_html(number_format((float)$s->price, 2)) . '</p></div>';
        }
        echo '</div></div>';
        return ob_get_clean();
    }

    public function booking_form_shortcode() {
        global $wpdb;
        $table = $wpdb->prefix . self::SERVICES_TABLE;
        $services = $wpdb->get_results("SELECT id,name,price,duration FROM $table WHERE active=1 ORDER BY name ASC");
        $s = $this->get_settings();
        ob_start();
        ?>
        <div class="booklyft-wrap">
            <div class="booklyft-hero">
                <h2><?php echo esc_html($s['brand_name']); ?></h2>
                <p>Book your appointment in a few simple steps.</p>
            </div>
            <div class="booklyft-card">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="booklyft_submit_booking">
                    <?php wp_nonce_field('booklyft_book', 'booklyft_nonce'); ?>
                    <div class="booklyft-grid">
                        <div class="booklyft-field"><label>Name</label><input required type="text" name="customer_name"></div>
                        <div class="booklyft-field"><label>Email</label><input required type="email" name="customer_email"></div>
                        <div class="booklyft-field"><label>Phone</label><input type="text" name="customer_phone"></div>
                        <div class="booklyft-field"><label>Service</label><select required name="service_id"><?php foreach ($services as $service) : ?><option value="<?php echo esc_attr($service->id); ?>"><?php echo esc_html($service->name . ' - ' . intval($service->duration) . ' mins'); ?></option><?php endforeach; ?></select></div>
                        <div class="booklyft-field"><label>Date</label><input required type="date" name="booking_date"></div>
                        <div class="booklyft-field"><label>Time</label><input required type="time" name="booking_time"></div>
                    </div>
                    <div class="booklyft-field"><label>Notes</label><textarea name="notes" rows="4"></textarea></div>
                    <button class="booklyft-btn" type="submit">Submit Booking</button>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function handle_booking() {
        if (!isset($_POST['booklyft_nonce']) || !wp_verify_nonce($_POST['booklyft_nonce'], 'booklyft_book')) wp_die('Invalid nonce');
        global $wpdb;
        $table = $wpdb->prefix . self::BOOKINGS_TABLE;
        $data = [
            'service_id' => absint($_POST['service_id']),
            'customer_name' => sanitize_text_field($_POST['customer_name']),
            'customer_email' => sanitize_email($_POST['customer_email']),
            'customer_phone' => sanitize_text_field($_POST['customer_phone'] ?? ''),
            'booking_date' => sanitize_text_field($_POST['booking_date']),
            'booking_time' => sanitize_text_field($_POST['booking_time']),
            'notes' => sanitize_textarea_field($_POST['notes'] ?? ''),
            'status' => 'pending',
        ];
        $wpdb->insert($table, $data);
        $service = $wpdb->get_row($wpdb->prepare("SELECT name FROM {$wpdb->prefix}" . self::SERVICES_TABLE . " WHERE id=%d", $data['service_id']));
        $settings = $this->get_settings();
        add_filter('wp_mail_from', function() use ($settings) { return $settings['from_email']; });
        wp_mail($data['customer_email'], 'Booking Received', 'Your booking for ' . ($service->name ?? 'service') . ' has been received.');
        wp_redirect(add_query_arg('booklyft', 'success', wp_get_referer() ?: home_url('/')));
        exit;
    }

    public function ajax_check_availability() {
        global $wpdb;
        $date = sanitize_text_field($_GET['date'] ?? '');
        $time = sanitize_text_field($_GET['time'] ?? '');
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}" . self::BOOKINGS_TABLE . " WHERE booking_date=%s AND booking_time=%s AND status IN ('pending','confirmed')", $date, $time));
        wp_send_json_success(['available' => ((int)$count === 0)]);
    }

    public function admin_page() {
        global $wpdb;
        echo $this->admin_colors_css();
        $bookings = $wpdb->get_results(
            "SELECT b.*, s.name AS service_name
             FROM {$wpdb->prefix}" . self::BOOKINGS_TABLE . " b
             LEFT JOIN {$wpdb->prefix}" . self::SERVICES_TABLE . " s ON s.id=b.service_id
             ORDER BY b.id DESC
             LIMIT 200"
        );
        echo '<div class="wrap booklyft-wrap">';
        echo '<div class="booklyft-hero"><h1>Booklyft Bookings</h1><p>Manage appointments in your branded dashboard.</p></div>';
        echo '<div class="booklyft-card">';
        echo '<table class="booklyft-table"><thead><tr><th>ID</th><th>Customer</th><th>Service</th><th>Date</th><th>Time</th><th>Status</th></tr></thead><tbody>';
        foreach ($bookings as $row) {
            echo '<tr><td>' . intval($row->id) . '</td><td>' . esc_html($row->customer_name) . '</td><td>' . esc_html($row->service_name) . '</td><td>' . esc_html($row->booking_date) . '</td><td>' . esc_html($row->booking_time) . '</td><td><span class="booklyft-pill">' . esc_html($row->status) . '</span></td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    public function services_page() {
        global $wpdb;
        echo $this->admin_colors_css();
        $table = $wpdb->prefix . self::SERVICES_TABLE;
        $services = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC");
        echo '<div class="wrap booklyft-wrap">';
        echo '<div class="booklyft-hero"><h1>Booklyft Services</h1><p>Create and manage your service offerings.</p></div>';
        echo '<div class="booklyft-card">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="booklyft_save_service">';
        wp_nonce_field('booklyft_service', 'booklyft_service_nonce');
        echo '<table class="form-table">';
        echo '<tr><th>Name</th><td><input type="text" name="name" required></td></tr>';
        echo '<tr><th>Duration</th><td><input type="number" name="duration" value="60" required></td></tr>';
        echo '<tr><th>Price</th><td><input type="number" step="0.01" name="price" value="0" required></td></tr>';
        echo '<tr><th>Description</th><td><textarea name="description" rows="4"></textarea></td></tr>';
        echo '</table>';
        echo '<p><button class="button button-primary" type="submit">Save Service</button></p>';
        echo '</form></div>';
        echo '<div class="booklyft-card"><h2>Existing Services</h2><table class="booklyft-table"><thead><tr><th>ID</th><th>Name</th><th>Duration</th><th>Price</th><th>Active</th><th>Action</th></tr></thead><tbody>';
        foreach ($services as $service) {
            $delete_url = wp_nonce_url(admin_url('admin-post.php?action=booklyft_delete_service&id=' . intval($service->id)), 'booklyft_delete_service_' . intval($service->id));
            echo '<tr><td>' . intval($service->id) . '</td><td>' . esc_html($service->name) . '</td><td>' . intval($service->duration) . '</td><td>' . esc_html(number_format((float)$service->price, 2)) . '</td><td>' . esc_html($service->active ? 'Yes' : 'No') . '</td><td><a class="button" href="' . esc_url($delete_url) . '">Delete</a></td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    public function settings_page() {
        echo $this->admin_colors_css();
        $s = $this->get_settings();
        echo '<div class="wrap booklyft-wrap"><div class="booklyft-hero"><h1>Booklyft Settings</h1><p>Branding and booking preferences.</p></div><div class="booklyft-card"><form method="post" action="options.php">';
        settings_fields('booklyft_settings_group');
        echo '<table class="form-table">';
        echo '<tr><th>Brand Name</th><td><input type="text" name="booklyft_settings[brand_name]" value="' . esc_attr($s['brand_name']) . '"></td></tr>';
        echo '<tr><th>Admin Color</th><td><input type="text" name="booklyft_settings[admin_color]" value="' . esc_attr($s['admin_color']) . '"></td></tr>';
        echo '<tr><th>Accent Color</th><td><input type="text" name="booklyft_settings[admin_accent]" value="' . esc_attr($s['admin_accent']) . '"></td></tr>';
        echo '<tr><th>From Email</th><td><input type="email" name="booklyft_settings[from_email]" value="' . esc_attr($s['from_email']) . '"></td></tr>';
        echo '<tr><th>Timezone</th><td><input type="text" name="booklyft_settings[timezone]" value="' . esc_attr($s['timezone']) . '"></td></tr>';
        echo '<tr><th>Enable Slots</th><td><label><input type="checkbox" name="booklyft_settings[slots_enabled]" value="1" ' . checked($s['slots_enabled'], '1', false) . '> Allow bookings</label></td></tr>';
        echo '</table><p><button class="button button-primary" type="submit">Save Settings</button></p></form></div></div>';
    }

    public function save_service() {
        if (!current_user_can('manage_options') || !isset($_POST['booklyft_service_nonce']) || !wp_verify_nonce($_POST['booklyft_service_nonce'], 'booklyft_service')) wp_die('Unauthorized');
        global $wpdb;
        $wpdb->insert($wpdb->prefix . self::SERVICES_TABLE, [
            'name' => sanitize_text_field($_POST['name']),
            'duration' => absint($_POST['duration']),
            'price' => floatval($_POST['price']),
            'description' => sanitize_textarea_field($_POST['description']),
            'active' => 1,
        ]);
        wp_redirect(admin_url('admin.php?page=booklyft-services'));
        exit;
    }

    public function delete_service() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        $id = absint($_GET['id'] ?? 0);
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'booklyft_delete_service_' . $id)) wp_die('Invalid nonce');
        global $wpdb;
        $wpdb->delete($wpdb->prefix . self::SERVICES_TABLE, ['id' => $id]);
        wp_redirect(admin_url('admin.php?page=booklyft-services'));
        exit;
    }

    public function update_booking() {}
}

register_activation_hook(__FILE__, ['Booklyft', 'activate']);
new Booklyft();