<?php
/*
Plugin Name: Booklyft
Description: Booking system with AJAX rescheduling, editable bookings, email templates, services, admin management, and notifications.
Version: 1.6.0
Author: Perplexity
Text Domain: booklyft
*/

if (!defined('ABSPATH')) exit;

class Booklyft {
    const VERSION = '1.6.0';
    const BOOKINGS_TABLE = 'booklyft_bookings';
    const SERVICES_TABLE = 'booklyft_services';
    const SETTINGS_KEY = 'booklyft_settings';

    public function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_front_assets']);
        add_action('admin_post_booklyft_save_service', [$this, 'save_service']);
        add_action('admin_post_booklyft_delete_service', [$this, 'delete_service']);
        add_action('admin_post_booklyft_update_booking', [$this, 'update_booking']);
        add_action('admin_post_booklyft_edit_booking', [$this, 'handle_edit_booking']);
        add_action('admin_post_booklyft_reschedule_booking', [$this, 'handle_reschedule_booking']);
        add_action('admin_post_booklyft_submit_booking', [$this, 'handle_booking']);
        add_action('admin_post_nopriv_booklyft_submit_booking', [$this, 'handle_booking']);
        add_action('wp_ajax_booklyft_get_slots', [$this, 'ajax_get_slots']);
        add_action('wp_ajax_nopriv_booklyft_get_slots', [$this, 'ajax_get_slots']);
        add_action('wp_ajax_booklyft_check_availability', [$this, 'ajax_check_availability']);
        add_action('wp_ajax_nopriv_booklyft_check_availability', [$this, 'ajax_check_availability']);
        add_shortcode('booklyft_booking_form', [$this, 'booking_form_shortcode']);
        add_shortcode('booklyft_services', [$this, 'services_shortcode']);
        add_shortcode('booklyft_calendar', [$this, 'calendar_shortcode']);
    }

    public static function activate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$wpdb->prefix}" . self::SERVICES_TABLE . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(200) NOT NULL,
            duration int NOT NULL DEFAULT 60,
            price decimal(10,2) NOT NULL DEFAULT 0,
            description text NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}" . self::BOOKINGS_TABLE . " (
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
            updated_at datetime NULL,
            PRIMARY KEY (id),
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
                'slot_start' => '09:00',
                'slot_end' => '17:00',
                'slot_interval' => '30',
                'email_created_subject' => 'Booking Received',
                'email_confirmed_subject' => 'Booking Confirmed',
                'email_rescheduled_subject' => 'Booking Rescheduled',
                'email_cancelled_subject' => 'Booking Cancelled',
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
            'slot_start' => '09:00',
            'slot_end' => '17:00',
            'slot_interval' => '30',
            'email_created_subject' => 'Booking Received',
            'email_confirmed_subject' => 'Booking Confirmed',
            'email_rescheduled_subject' => 'Booking Rescheduled',
            'email_cancelled_subject' => 'Booking Cancelled',
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
        add_submenu_page('booklyft', 'Calendar', 'Calendar', 'manage_options', 'booklyft-calendar', [$this, 'calendar_page']);
        add_submenu_page('booklyft', 'Services', 'Services', 'manage_options', 'booklyft-services', [$this, 'services_page']);
        add_submenu_page('booklyft', 'Settings', 'Settings', 'manage_options', 'booklyft-settings', [$this, 'settings_page']);
        add_submenu_page('booklyft', 'Edit Booking', 'Edit Booking', 'manage_options', 'booklyft-edit-booking', [$this, 'edit_booking_page']);
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
            'slot_start' => preg_match('/^\d{2}:\d{2}$/', ($input['slot_start'] ?? '')) ? $input['slot_start'] : '09:00',
            'slot_end' => preg_match('/^\d{2}:\d{2}$/', ($input['slot_end'] ?? '')) ? $input['slot_end'] : '17:00',
            'slot_interval' => max(15, absint($input['slot_interval'] ?? 30)),
            'email_created_subject' => sanitize_text_field($input['email_created_subject'] ?? 'Booking Received'),
            'email_confirmed_subject' => sanitize_text_field($input['email_confirmed_subject'] ?? 'Booking Confirmed'),
            'email_rescheduled_subject' => sanitize_text_field($input['email_rescheduled_subject'] ?? 'Booking Rescheduled'),
            'email_cancelled_subject' => sanitize_text_field($input['email_cancelled_subject'] ?? 'Booking Cancelled'),
        ];
    }

    private function admin_css() {
        $s = $this->get_settings();
        return '<style>
        #adminmenu #toplevel_page_booklyft .wp-menu-image:before,#adminmenu #toplevel_page_booklyft.current .wp-menu-image:before{color:' . esc_attr($s['admin_accent']) . '}
        .booklyft-wrap{max-width:1200px;margin:20px 20px 20px 0;padding:0}
        .booklyft-card{background:#fff;border:1px solid #eed9d7;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);padding:18px;margin:0 0 18px}
        .booklyft-hero{background:linear-gradient(135deg,' . esc_attr($s['admin_color']) . ',' . esc_attr($s['admin_accent']) . ');color:#fff;border-radius:16px;padding:20px 22px;margin-bottom:18px}
        .booklyft-hero h1,.booklyft-hero h2{color:#fff;margin:0}
        .booklyft-btn,.button.button-primary{background:' . esc_attr($s['admin_color']) . ';border-color:' . esc_attr($s['admin_color']) . ';color:#fff}
        .booklyft-btn:hover,.button.button-primary:hover{background:' . esc_attr($s['admin_accent']) . ';border-color:' . esc_attr($s['admin_accent']) . ';color:#fff}
        .booklyft-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #eed9d7;border-radius:10px;overflow:hidden}
        .booklyft-table th{background:#fbeceb;color:' . esc_attr($s['admin_color']) . ';text-align:left;padding:10px;border-bottom:1px solid #eed9d7}
        .booklyft-table td{padding:10px;border-bottom:1px solid #f2e3e1;vertical-align:top}
        .booklyft-field{display:flex;flex-direction:column;gap:6px;margin-bottom:12px}
        .booklyft-field input,.booklyft-field select,.booklyft-field textarea{padding:10px;border:1px solid #d8c2bf;border-radius:8px}
        .booklyft-field input:focus,.booklyft-field select:focus,.booklyft-field textarea:focus{border-color:' . esc_attr($s['admin_accent']) . ';box-shadow:0 0 0 1px ' . esc_attr($s['admin_accent']) . ';outline:none}
        .booklyft-pill{display:inline-block;background:#fbeceb;color:' . esc_attr($s['admin_color']) . ';padding:5px 10px;border-radius:999px;font-size:12px}
        .booklyft-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}
        .booklyft-slot{display:inline-block;margin:4px 6px 4px 0;padding:8px 10px;border-radius:999px;border:1px solid #d8c2bf;background:#fff}
        .booklyft-slot.booklyft-slot-busy{opacity:.45;text-decoration:line-through}
        .booklyft-day{background:#fff;border:1px solid #eed9d7;border-radius:12px;padding:14px}
        .booklyft-day h3{margin-top:0;color:' . esc_attr($s['admin_color']) . '}
        .booklyft-day ul{margin:0;padding-left:18px}
        </style>';
    }

    public function enqueue_admin_assets() {
        echo $this->admin_css();
    }

    public function enqueue_front_assets() {
        wp_register_style('booklyft', false, [], self::VERSION);
        wp_add_inline_style('booklyft', '.booklyft-wrap{max-width:900px;margin:20px auto;padding:20px;border:1px solid #ddd;border-radius:10px}.booklyft-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.booklyft-field{display:flex;flex-direction:column;gap:6px;margin-bottom:12px}.booklyft-field input,.booklyft-field select,.booklyft-field textarea{padding:10px;border:1px solid #ccc;border-radius:6px}.booklyft-btn{background:#1e73be;color:#fff;border:none;padding:12px 16px;border-radius:6px;cursor:pointer}.booklyft-msg{padding:10px;margin:10px 0;border-radius:6px}.booklyft-ok{background:#e7f7ea;color:#1c6b2a}.booklyft-err{background:#fdecec;color:#a11}.booklyft-table{width:100%;border-collapse:collapse}.booklyft-table th,.booklyft-table td{border:1px solid #ddd;padding:8px;text-align:left}');
        wp_enqueue_style('booklyft');
    }

    public function services_shortcode() {
        global $wpdb;
        $services = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}" . self::SERVICES_TABLE . " WHERE active=1 ORDER BY id DESC");
        ob_start();
        echo '<div class="booklyft-wrap"><h2>Services</h2><div class="booklyft-grid">';
        foreach ($services as $s) {
            echo '<div class="booklyft-card"><h3>' . esc_html($s->name) . '</h3><p>' . esc_html($s->description) . '</p><p><strong>Duration:</strong> ' . intval($s->duration) . ' minutes</p><p><strong>Price:</strong> ' . esc_html(number_format((float)$s->price, 2)) . '</p></div>';
        }
        echo '</div></div>';
        return ob_get_clean();
    }

    private function get_slots_for_date($date) {
        $s = $this->get_settings();
        $slots = [];
        $start = strtotime($date . ' ' . $s['slot_start']);
        $end = strtotime($date . ' ' . $s['slot_end']);
        $interval = max(15, absint($s['slot_interval'])) * 60;
        for ($t = $start; $t <= $end; $t += $interval) {
            $slots[] = date('H:i', $t);
        }
        return $slots;
    }

    private function booked_times_for_date($date, $exclude_id = 0) {
        global $wpdb;
        if ($exclude_id) {
            $rows = $wpdb->get_col($wpdb->prepare("SELECT booking_time FROM {$wpdb->prefix}" . self::BOOKINGS_TABLE . " WHERE booking_date=%s AND id!=%d AND status IN ('pending','confirmed')", $date, $exclude_id));
        } else {
            $rows = $wpdb->get_col($wpdb->prepare("SELECT booking_time FROM {$wpdb->prefix}" . self::BOOKINGS_TABLE . " WHERE booking_date=%s AND status IN ('pending','confirmed')", $date));
        }
        return array_map(fn($v) => substr($v,0,5), $rows ?: []);
    }

    private function send_booking_email($email, $subject, $message) {
        $settings = $this->get_settings();
        add_filter('wp_mail_from', function() use ($settings) { return $settings['from_email']; });
        wp_mail($email, $subject, $message);
    }

    private function email_template($type, $booking, $service) {
        $settings = $this->get_settings();
        $subjects = [
            'created' => $settings['email_created_subject'],
            'confirmed' => $settings['email_confirmed_subject'],
            'rescheduled' => $settings['email_rescheduled_subject'],
            'cancelled' => $settings['email_cancelled_subject'],
        ];
        $verbs = [
            'created' => 'received',
            'confirmed' => 'confirmed',
            'rescheduled' => 'rescheduled',
            'cancelled' => 'cancelled',
        ];
        $subject = $subjects[$type] ?? $subjects['created'];
        $verb = $verbs[$type] ?? 'updated';
        $body = "Hello {$booking->customer_name},\n\n"
              . "Your booking for {$service->name} has been {$verb}.\n"
              . "Date: {$booking->booking_date}\n"
              . "Time: {$booking->booking_time}\n"
              . "Status: {$booking->status}\n\n"
              . "Thank you,\n"
              . $settings['brand_name'];
        return [$subject, $body];
    }

    public function booking_form_shortcode() {
        global $wpdb;
        $services = $wpdb->get_results("SELECT id,name,price,duration FROM {$wpdb->prefix}" . self::SERVICES_TABLE . " WHERE active=1 ORDER BY name ASC");
        $s = $this->get_settings();
        ob_start();
        ?>
        <div class="booklyft-wrap">
            <div class="booklyft-hero"><h2><?php echo esc_html($s['brand_name']); ?></h2><p>Book your appointment in a few simple steps.</p></div>
            <div class="booklyft-card">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="booklyft-form">
                    <input type="hidden" name="action" value="booklyft_submit_booking">
                    <input type="hidden" name="booking_id" value="0">
                    <?php wp_nonce_field('booklyft_book', 'booklyft_nonce'); ?>
                    <div class="booklyft-grid">
                        <div class="booklyft-field"><label>Name</label><input required type="text" name="customer_name"></div>
                        <div class="booklyft-field"><label>Email</label><input required type="email" name="customer_email"></div>
                        <div class="booklyft-field"><label>Phone</label><input type="text" name="customer_phone"></div>
                        <div class="booklyft-field"><label>Service</label><select required name="service_id" id="booklyft-service"><?php foreach ($services as $service) : ?><option value="<?php echo esc_attr($service->id); ?>"><?php echo esc_html($service->name . ' - ' . intval($service->duration) . ' mins'); ?></option><?php endforeach; ?></select></div>
                        <div class="booklyft-field"><label>Date</label><input required type="date" name="booking_date" id="booklyft-date"></div>
                        <div class="booklyft-field"><label>Time</label><select required name="booking_time" id="booklyft-time"><option value="">Choose a date first</option></select></div>
                    </div>
                    <div class="booklyft-field"><label>Notes</label><textarea name="notes" rows="4"></textarea></div>
                    <button class="booklyft-btn" type="submit">Submit Booking</button>
                </form>
            </div>
            <div class="booklyft-card"><h3>Today’s Slots</h3><div id="booklyft-slot-list"></div></div>
        </div>
        <script>
        (function(){
          const dateEl=document.getElementById('booklyft-date');
          const timeEl=document.getElementById('booklyft-time');
          const slotList=document.getElementById('booklyft-slot-list');
          async function loadSlots(){
            const date=dateEl.value;
            if(!date)return;
            const url=new URL('<?php echo esc_url(admin_url('admin-ajax.php')); ?>');
            url.searchParams.set('action','booklyft_get_slots');
            url.searchParams.set('date',date);
            const res=await fetch(url);
            const json=await res.json();
            timeEl.innerHTML='';
            slotList.innerHTML='';
            (json.data.slots||[]).forEach(s=>{
              const o=document.createElement('option');
              o.value=s.time;
              o.textContent=s.time+(s.available?'':' (booked)');
              if(!s.available)o.disabled=true;
              timeEl.appendChild(o);
              const span=document.createElement('span');
              span.className='booklyft-slot '+(s.available?'':'booklyft-slot-busy');
              span.textContent=s.time;
              slotList.appendChild(span);
            });
          }
          dateEl.addEventListener('change',loadSlots);
          if(dateEl.value) loadSlots();
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    public function calendar_shortcode() {
        global $wpdb;
        $days = $wpdb->get_results("SELECT booking_date, COUNT(*) AS total FROM {$wpdb->prefix}" . self::BOOKINGS_TABLE . " GROUP BY booking_date ORDER BY booking_date DESC LIMIT 30");
        ob_start();
        echo '<div class="booklyft-wrap"><div class="booklyft-hero"><h2>Booking Calendar</h2><p>Recent activity by day.</p></div><div class="booklyft-grid">';
        foreach ($days as $day) {
            $bookings = $wpdb->get_results($wpdb->prepare("SELECT b.booking_time, b.status, b.customer_name, s.name AS service_name, b.id FROM {$wpdb->prefix}" . self::BOOKINGS_TABLE . " b LEFT JOIN {$wpdb->prefix}" . self::SERVICES_TABLE . " s ON s.id=b.service_id WHERE b.booking_date=%s ORDER BY b.booking_time ASC", $day->booking_date));
            echo '<div class="booklyft-day"><h3>' . esc_html($day->booking_date) . ' <span class="booklyft-pill">' . intval($day->total) . ' bookings</span></h3><ul>';
            foreach ($bookings as $b) {
                $edit = wp_nonce_url(admin_url('admin.php?page=booklyft-edit-booking&id=' . intval($b->id)), 'booklyft_edit_booking_' . intval($b->id));
                echo '<li><strong>' . esc_html($b->booking_time) . '</strong> - ' . esc_html($b->customer_name) . ' (' . esc_html($b->service_name) . ') <em>' . esc_html($b->status) . '</em> <a href="' . esc_url($edit) . '">edit</a></li>';
            }
            echo '</ul></div>';
        }
        echo '</div></div>';
        return ob_get_clean();
    }

    public function ajax_get_slots() {
        $date = sanitize_text_field($_GET['date'] ?? '');
        if (!$date) wp_send_json_success(['slots' => []]);
        $slots = $this->get_slots_for_date($date);
        $booked = $this->booked_times_for_date($date, absint($_GET['exclude_id'] ?? 0));
        $out = [];
        foreach ($slots as $slot) {
            $out[] = ['time' => $slot, 'available' => !in_array($slot, $booked, true)];
        }
        wp_send_json_success(['slots' => $out]);
    }

    public function ajax_check_availability() {
        global $wpdb;
        $date = sanitize_text_field($_GET['date'] ?? '');
        $time = sanitize_text_field($_GET['time'] ?? '');
        $exclude = absint($_GET['exclude_id'] ?? 0);
        $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::BOOKINGS_TABLE . " WHERE booking_date=%s AND booking_time=%s AND status IN ('pending','confirmed')";
        $args = [$date, $time];
        if ($exclude) {
            $sql .= " AND id!=%d";
            $args[] = $exclude;
        }
        $count = $wpdb->get_var($wpdb->prepare($sql, ...$args));
        wp_send_json_success(['available' => ((int)$count === 0)]);
    }

    public function handle_booking() {
        if (!isset($_POST['booklyft_nonce']) || !wp_verify_nonce($_POST['booklyft_nonce'], 'booklyft_book')) wp_die('Invalid nonce');
        global $wpdb;
        $booking_id = absint($_POST['booking_id'] ?? 0);
        $service_id = absint($_POST['service_id']);
        $date = sanitize_text_field($_POST['booking_date']);
        $time = sanitize_text_field($_POST['booking_time']);
        $busy = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::BOOKINGS_TABLE . " WHERE booking_date=%s AND booking_time=%s AND status IN ('pending','confirmed')" . ($booking_id ? " AND id!=%d" : ""),
            $booking_id ? [$date, $time, $booking_id] : [$date, $time]
        ));
        if ($busy > 0) wp_die('Selected time is already booked');

        $data = [
            'service_id' => $service_id,
            'customer_name' => sanitize_text_field($_POST['customer_name']),
            'customer_email' => sanitize_email($_POST['customer_email']),
            'customer_phone' => sanitize_text_field($_POST['customer_phone'] ?? ''),
            'booking_date' => $date,
            'booking_time' => $time,
            'notes' => sanitize_textarea_field($_POST['notes'] ?? ''),
            'updated_at' => current_time('mysql'),
        ];

        if ($booking_id) {
            $data['status'] = sanitize_text_field($_POST['status'] ?? 'confirmed');
            $wpdb->update($wpdb->prefix . self::BOOKINGS_TABLE, $data, ['id' => $booking_id]);
        } else {
            $data['status'] = 'pending';
            $wpdb->insert($wpdb->prefix . self::BOOKINGS_TABLE, $data);
        }

        $service = $wpdb->get_row($wpdb->prepare("SELECT name FROM {$wpdb->prefix}" . self::SERVICES_TABLE . " WHERE id=%d", $service_id));
        $booking = (object)$data;
        $booking->customer_name = $data['customer_name'];
        $booking->booking_date = $date;
        $booking->booking_time = $time;
        $booking->status = $data['status'];

        [$subject, $body] = $this->email_template($booking_id ? 'rescheduled' : 'created', $booking, $service);
        $this->send_booking_email($data['customer_email'], $subject, $body);

        wp_redirect(add_query_arg('booklyft', 'success', wp_get_referer() ?: home_url('/')));
        exit;
    }

    public function admin_page() {
        global $wpdb;
        echo $this->admin_css();
        $bookings = $wpdb->get_results("SELECT b.*, s.name AS service_name FROM {$wpdb->prefix}" . self::BOOKINGS_TABLE . " b LEFT JOIN {$wpdb->prefix}" . self::SERVICES_TABLE . " s ON s.id=b.service_id ORDER BY b.id DESC LIMIT 200");
        echo '<div class="wrap booklyft-wrap"><div class="booklyft-hero"><h1>Booklyft Bookings</h1><p>Manage appointments in your branded dashboard.</p></div><div class="booklyft-card"><table class="booklyft-table"><thead><tr><th>ID</th><th>Customer</th><th>Service</th><th>Date</th><th>Time</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach ($bookings as $row) {
            $edit_url = wp_nonce_url(admin_url('admin.php?page=booklyft-edit-booking&id=' . intval($row->id)), 'booklyft_edit_booking_' . intval($row->id));
            $confirm_url = wp_nonce_url(admin_url('admin-post.php?action=booklyft_update_booking&id=' . intval($row->id) . '&status=confirmed'), 'booklyft_update_booking_' . intval($row->id));
            $cancel_url = wp_nonce_url(admin_url('admin-post.php?action=booklyft_update_booking&id=' . intval($row->id) . '&status=cancelled'), 'booklyft_update_booking_' . intval($row->id));
            echo '<tr><td>' . intval($row->id) . '</td><td>' . esc_html($row->customer_name) . '</td><td>' . esc_html($row->service_name) . '</td><td>' . esc_html($row->booking_date) . '</td><td>' . esc_html($row->booking_time) . '</td><td><span class="booklyft-pill">' . esc_html($row->status) . '</span></td><td><a class="button" href="' . esc_url($edit_url) . '">Edit</a> <a class="button" href="' . esc_url($confirm_url) . '">Confirm</a> <a class="button" href="' . esc_url($cancel_url) . '">Cancel</a></td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    public function calendar_page() {
        echo do_shortcode('[booklyft_calendar]');
    }

    public function services_page() {
        global $wpdb;
        echo $this->admin_css();
        $services = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}" . self::SERVICES_TABLE . " ORDER BY id DESC");
        echo '<div class="wrap booklyft-wrap"><div class="booklyft-hero"><h1>Booklyft Services</h1><p>Create and manage your service offerings.</p></div><div class="booklyft-card"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="booklyft_save_service">';
        wp_nonce_field('booklyft_service', 'booklyft_service_nonce');
        echo '<table class="form-table"><tr><th>Name</th><td><input type="text" name="name" required></td></tr><tr><th>Duration</th><td><input type="number" name="duration" value="60" required></td></tr><tr><th>Price</th><td><input type="number" step="0.01" name="price" value="0" required></td></tr><tr><th>Description</th><td><textarea name="description" rows="4"></textarea></td></tr></table><p><button class="button button-primary" type="submit">Save Service</button></p></form></div><div class="booklyft-card"><h2>Existing Services</h2><table class="booklyft-table"><thead><tr><th>ID</th><th>Name</th><th>Duration</th><th>Price</th><th>Active</th><th>Action</th></tr></thead><tbody>';
        foreach ($services as $service) {
            $delete_url = wp_nonce_url(admin_url('admin-post.php?action=booklyft_delete_service&id=' . intval($service->id)), 'booklyft_delete_service_' . intval($service->id));
            echo '<tr><td>' . intval($service->id) . '</td><td>' . esc_html($service->name) . '</td><td>' . intval($service->duration) . '</td><td>' . esc_html(number_format((float)$service->price, 2)) . '</td><td>' . esc_html($service->active ? 'Yes' : 'No') . '</td><td><a class="button" href="' . esc_url($delete_url) . '">Delete</a></td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    public function settings_page() {
        echo $this->admin_css();
        $s = $this->get_settings();
        echo '<div class="wrap booklyft-wrap"><div class="booklyft-hero"><h1>Booklyft Settings</h1><p>Branding, slots, and email preferences.</p></div><div class="booklyft-card"><form method="post" action="options.php">';
        settings_fields('booklyft_settings_group');
        echo '<table class="form-table">';
        echo '<tr><th>Brand Name</th><td><input type="text" name="booklyft_settings[brand_name]" value="' . esc_attr($s['brand_name']) . '"></td></tr>';
        echo '<tr><th>Admin Color</th><td><input type="text" name="booklyft_settings[admin_color]" value="' . esc_attr($s['admin_color']) . '"></td></tr>';
        echo '<tr><th>Accent Color</th><td><input type="text" name="booklyft_settings[admin_accent]" value="' . esc_attr($s['admin_accent']) . '"></td></tr>';
        echo '<tr><th>From Email</th><td><input type="email" name="booklyft_settings[from_email]" value="' . esc_attr($s['from_email']) . '"></td></tr>';
        echo '<tr><th>Timezone</th><td><input type="text" name="booklyft_settings[timezone]" value="' . esc_attr($s['timezone']) . '"></td></tr>';
        echo '<tr><th>Enable Slots</th><td><label><input type="checkbox" name="booklyft_settings[slots_enabled]" value="1" ' . checked($s['slots_enabled'], '1', false) . '> Allow bookings</label></td></tr>';
        echo '<tr><th>Slot Start</th><td><input type="time" name="booklyft_settings[slot_start]" value="' . esc_attr($s['slot_start']) . '"></td></tr>';
        echo '<tr><th>Slot End</th><td><input type="time" name="booklyft_settings[slot_end]" value="' . esc_attr($s['slot_end']) . '"></td></tr>';
        echo '<tr><th>Slot Interval</th><td><input type="number" name="booklyft_settings[slot_interval]" value="' . esc_attr($s['slot_interval']) . '" min="15" step="15"></td></tr>';
        echo '<tr><th>Created Subject</th><td><input type="text" name="booklyft_settings[email_created_subject]" value="' . esc_attr($s['email_created_subject']) . '"></td></tr>';
        echo '<tr><th>Confirmed Subject</th><td><input type="text" name="booklyft_settings[email_confirmed_subject]" value="' . esc_attr($s['email_confirmed_subject']) . '"></td></tr>';
        echo '<tr><th>Rescheduled Subject</th><td><input type="text" name="booklyft_settings[email_rescheduled_subject]" value="' . esc_attr($s['email_rescheduled_subject']) . '"></td></tr>';
        echo '<tr><th>Cancelled Subject</th><td><input type="text" name="booklyft_settings[email_cancelled_subject]" value="' . esc_attr($s['email_cancelled_subject']) . '"></td></tr>';
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

    public function update_booking() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        $id = absint($_GET['id'] ?? 0);
        $status = sanitize_text_field($_GET['status'] ?? 'pending');
        if (!in_array($status, ['pending','confirmed','cancelled','completed'], true)) wp_die('Bad status');
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'booklyft_update_booking_' . $id)) wp_die('Invalid nonce');
        global $wpdb;
        $wpdb->update($wpdb->prefix . self::BOOKINGS_TABLE, ['status' => $status, 'updated_at' => current_time('mysql')], ['id' => $id]);
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}" . self::BOOKINGS_TABLE . " WHERE id=%d", $id));
        $service = $wpdb->get_row($wpdb->prepare("SELECT name FROM {$wpdb->prefix}" . self::SERVICES_TABLE . " WHERE id=%d", $booking->service_id));
        [$subject, $body] = $this->email_template($status === 'cancelled' ? 'cancelled' : 'confirmed', $booking, $service);
        $this->send_booking_email($booking->customer_email, $subject, $body);
        wp_redirect(admin_url('admin.php?page=booklyft'));
        exit;
    }

    public function handle_edit_booking() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        $id = absint($_POST['id'] ?? 0);
        if (!isset($_POST['booklyft_nonce']) || !wp_verify_nonce($_POST['booklyft_nonce'], 'booklyft_edit_' . $id)) wp_die('Invalid nonce');

        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}" . self::BOOKINGS_TABLE . " WHERE id=%d", $id));
        if (!$booking) wp_die('Booking not found');

        $data = [
            'service_id' => absint($_POST['service_id']),
            'customer_name' => sanitize_text_field($_POST['customer_name']),
            'customer_email' => sanitize_email($_POST['customer_email']),
            'customer_phone' => sanitize_text_field($_POST['customer_phone'] ?? ''),
            'booking_date' => sanitize_text_field($_POST['booking_date']),
            'booking_time' => sanitize_text_field($_POST['booking_time']),
            'status' => sanitize_text_field($_POST['status'] ?? $booking->status),
            'notes' => sanitize_textarea_field($_POST['notes'] ?? ''),
            'updated_at' => current_time('mysql'),
        ];

        $wpdb->update($wpdb->prefix . self::BOOKINGS_TABLE, $data, ['id' => $id]);
        wp_redirect(admin_url('admin.php?page=booklyft-edit-booking&id=' . $id . '&updated=1'));
        exit;
    }

    public function handle_reschedule_booking() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        $id = absint($_POST['id'] ?? 0);
        if (!isset($_POST['booklyft_nonce']) || !wp_verify_nonce($_POST['booklyft_nonce'], 'booklyft_reschedule_' . $id)) wp_die('Invalid nonce');
        $_POST['booking_id'] = $id;
        $_POST['status'] = 'confirmed';
        $this->handle_booking();
    }
}

register_activation_hook(__FILE__, ['Booklyft', 'activate']);
new Booklyft();