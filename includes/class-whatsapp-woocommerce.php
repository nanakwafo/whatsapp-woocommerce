<?php
class WhatsApp_WooCommerce {
    public function __construct() {
        $statuses = ['pending', 'processing', 'completed', 'cancelled'];
        foreach ($statuses as $status) {
            add_action("woocommerce_order_status_{$status}", [$this, 'send_whatsapp_message'], 10, 1);
        }
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'WhatsApp Settings',
            'WhatsApp Settings',
            'manage_options',
            'whatsapp-woocommerce',
            [$this, 'settings_page']
        );
    }
    public function register_settings() {
        register_setting('whatsapp_woocommerce_settings', 'whatsapp_access_token');
        register_setting('whatsapp_woocommerce_settings', 'whatsapp_phone_number_id');


        // Templates per status
        register_setting('whatsapp_woocommerce_settings', 'whatsapp_template_pending');
        register_setting('whatsapp_woocommerce_settings', 'whatsapp_template_processing');
        register_setting('whatsapp_woocommerce_settings', 'whatsapp_template_completed');
        register_setting('whatsapp_woocommerce_settings', 'whatsapp_template_cancelled');

        // New settings
        register_setting('whatsapp_woocommerce_settings', 'whatsapp_notify_statuses');
        register_setting('whatsapp_woocommerce_settings', 'whatsapp_notify_customer');
        register_setting('whatsapp_woocommerce_settings', 'whatsapp_notify_admin');
        register_setting('whatsapp_woocommerce_settings', 'whatsapp_admin_phone');
    }
    public function settings_page() {
        $statuses = ['pending', 'processing', 'completed', 'cancelled'];
        $selected_statuses = (array) get_option('whatsapp_notify_statuses', []);
        ?>
        <div class="wrap">
            <h1>WhatsApp WooCommerce Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('whatsapp_woocommerce_settings'); ?>
                <?php do_settings_sections('whatsapp_woocommerce_settings'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Access Token</th>
                        <td><input type="text" name="whatsapp_access_token"
                                   value="<?php echo esc_attr(get_option('whatsapp_access_token')); ?>" size="50"/></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Phone Number ID</th>
                        <td><input type="text" name="whatsapp_phone_number_id"
                                   value="<?php echo esc_attr(get_option('whatsapp_phone_number_id')); ?>" size="50"/></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Template: Pending</th>
                        <td>
        <textarea name="whatsapp_template_pending" rows="3" cols="50"><?php
            echo esc_textarea(get_option('whatsapp_template_pending', 'Hi {name}, your order #{order_id} is now Pending.')); ?></textarea>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Template: Processing</th>
                        <td>
        <textarea name="whatsapp_template_processing" rows="3" cols="50"><?php
            echo esc_textarea(get_option('whatsapp_template_processing', 'Hi {name}, your order #{order_id} is now Processing.')); ?></textarea>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Template: Completed</th>
                        <td>
        <textarea name="whatsapp_template_completed" rows="3" cols="50"><?php
            echo esc_textarea(get_option('whatsapp_template_completed', 'Hi {name}, your order #{order_id} has been Completed. 🎉')); ?></textarea>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Template: Cancelled</th>
                        <td>
        <textarea name="whatsapp_template_cancelled" rows="3" cols="50"><?php
            echo esc_textarea(get_option('whatsapp_template_cancelled', 'Hi {name}, your order #{order_id} has been Cancelled.')); ?></textarea>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Notify on Status</th>
                        <td>
                            <?php foreach ($statuses as $status): ?>
                                <label>
                                    <input type="checkbox" name="whatsapp_notify_statuses[]" value="<?php echo esc_attr($status); ?>"
                                        <?php checked(in_array($status, $selected_statuses)); ?> />
                                    <?php echo ucfirst($status); ?>
                                </label><br/>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Send to Customer</th>
                        <td><input type="checkbox" name="whatsapp_notify_customer" value="1"
                                <?php checked(get_option('whatsapp_notify_customer'), 1); ?> /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Send to Admin</th>
                        <td><input type="checkbox" name="whatsapp_notify_admin" value="1"
                                <?php checked(get_option('whatsapp_notify_admin'), 1); ?> /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Admin Phone Number</th>
                        <td>
                            <input type="text" name="whatsapp_admin_phone"
                                   value="<?php echo esc_attr(get_option('whatsapp_admin_phone')); ?>" size="20"/>
                            <p class="description">Enter phone in international format (e.g., 233593601491).</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    public function send_whatsapp_message($order_id) {
        $order = wc_get_order($order_id);
        $status = $order->get_status(); // e.g., "processing"

        $enabled_statuses = (array) get_option('whatsapp_notify_statuses', []);
        if (!in_array($status, $enabled_statuses)) {
            return; // Skip if this status is not selected
        }

        $access_token   = get_option('whatsapp_access_token');

        $phone_number_id = get_option('whatsapp_phone_number_id');

        //  Pick correct template
        $template_option = "whatsapp_template_{$status}";
        $message_template = get_option($template_option, "Hi {name}, your order #{order_id} is now {$status}.");

        $message = str_replace(
            ['{name}', '{order_id}', '{status}'],
            [$order->get_billing_first_name(), $order->get_order_number(), ucfirst($status)],
            $message_template
        );

        // ✅ Send to customer
        if (get_option('whatsapp_notify_customer')) {

            $this->send_whatsapp_api($this->sanitize_phone($order->get_billing_phone()), $message, $access_token, $phone_number_id);
        }

        // ✅ Send to admin
        if (get_option('whatsapp_notify_admin')) {
            $admin_phone = get_option('whatsapp_admin_phone');
            if ($admin_phone) {
                $admin_message = "Order #{$order->get_order_number()} ({$order->get_billing_first_name()}) is now {$status}. Total: {$order->get_total()} {$order->get_currency()}";

                $this->send_whatsapp_api($admin_phone, $admin_message, $access_token, $phone_number_id);
            }
        }
     }
    private function send_whatsapp_api($to, $message, $access_token, $phone_number_id){

         $response = wp_remote_post(

             "https://graph.facebook.com/v22.0/{$phone_number_id}/messages",
             [
                 'headers' => [
                     'Authorization' => 'Bearer ' . $access_token,
                     'Content-Type'  => 'application/json',
                 ],
                 'body' => wp_json_encode([
                     'messaging_product' => 'whatsapp',
                     'to'                => $to,
                     'type'              => 'text',
                     'text'              => ['body' => $message],
                 ]),
             ]
         );

         if (is_wp_error($response)) {

             error_log('WhatsApp API error: ' . $response->get_error_message());
         } else {
            // die(wp_remote_retrieve_body($response));
             error_log('WhatsApp API response: ' . wp_remote_retrieve_body($response));
         }
     }
    private function sanitize_phone($phone) {
        $phone = preg_replace('/\D+/', '', $phone); // remove non-digits
        if (strpos($phone, '0') === 0) {
            // Convert local 0-starting number to e.g. Ghana (+233)
            $phone = '233' . substr($phone, 1);
        }
        return $phone;
    }
}

new WhatsApp_WooCommerce();
