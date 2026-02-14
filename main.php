<?php
/**
 * Plugin Name: Waorders
 * Description: Lightweight WooCommerce -> WhatsApp integration with license activation (SaaS-validated). License stored in a separate option to avoid accidental overwrites.
 * Version: 1.2.0
 * Author: kwafo Nana Mensah
 * Text Domain: wc-wa-shop-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WC_WA_Shop_AI' ) ) :

    final class WC_WA_Shop_AI {

        const OPTION_GROUP   = 'wc_wa_shop_ai_options';
        const OPTION_NAME    = 'wc_wa_shop_ai_settings';
        const OPTION_LICENSE = 'wc_wa_shop_ai_license';

        private static $instance = null;

        private $defaults = array(
            'wa_phone_id'           => '',
            'wa_access_token'       => '',
            'wa_admin_number'       => '',
            'enable_admin_notify'   => 'yes',
            'enable_customer_notify'=> 'yes',
            
            'customer_thankyou_template'     => "Thanks for your order #{order_id}. Total: {order_total}. We'll notify you when it's shipped.",

            // Customer templates per status
            'customer_template_pending'    => 'Hi {customer_name}, your order #{order_id} is pending. Total: {order_total}.',
            'customer_template_processing' => 'Good news {customer_name}! Your order #{order_id} is now being processed.',
            'customer_template_completed'  => 'Hi {customer_name}, your order #{order_id} has been completed. Thank you!',
            'customer_template_cancelled'  => 'Hi {customer_name}, your order #{order_id} has been cancelled.',

            // Admin templates per status
            'admin_template_pending'    => 'New order #{order_id} (PENDING)\nCustomer: {customer_name}\nTotal: {order_total}',
            'admin_template_processing' => 'Order #{order_id} is now PROCESSING.',
            'admin_template_completed'  => 'Order #{order_id} COMPLETED.',
            'admin_template_cancelled'  => 'Order #{order_id} CANCELLED.',

            'enable_product_button' => 'yes',
            'product_button_text'   => 'Order via WhatsApp',

            // license fields intentionally not stored here any longer (moved to separate option)
        );

        /**
         * Singleton accessor.
         *
         * @return WC_WA_Shop_AI
         */
        public static function instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
                self::$instance->init_hooks();
            }
            return self::$instance;
        }

        public static function activate_plugin() {
            $instance = self::instance();
            $instance->activate();
        }

        public static function deactivate_plugin() {
            $instance = self::instance();
            $instance->deactivate();
        }

        private function init_hooks() {
            // initialize options
            add_action( 'init', array( $this, 'maybe_register_options' ) );

            // admin pages + settings
            add_action( 'admin_menu', array( $this, 'admin_menu' ) );
            add_action( 'admin_menu', array( $this, 'license_menu' ) );
            add_action( 'admin_init', array( $this, 'register_settings' ) );

            // woocommerce hooks
            add_action( 'woocommerce_single_product_summary', array( $this, 'output_product_button' ), 35 );
            add_shortcode( 'wa_chat_button', array( $this, 'shortcode_chat_button' ) );

            add_action( 'woocommerce_thankyou', array( $this, 'maybe_notify_on_order' ), 10, 1 );
            // add_action( 'woocommerce_new_order', array( $this, 'maybe_notify_admin_on_new_order' ), 10, 1 );

            // Order status change hooks
            add_action('woocommerce_order_status_changed',array( $this, 'handle_order_status_change' ),10,4);
            

            // assets
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );

            // AJAX
            add_action( 'wp_ajax_wc_wa_license_deactivate', array( $this, 'ajax_license_deactivate' ) );
        }

        /**
         * Activation handler - create options if missing.
         */
        public function activate() {
            $stored = get_option( self::OPTION_NAME );
            if ( $stored === false ) {
                add_option( self::OPTION_NAME, $this->defaults );
            } else {
                if ( ! is_array( $stored ) ) {
                    $stored = array();
                }
                $merged = wp_parse_args( $stored, $this->defaults );
                update_option( self::OPTION_NAME, $merged );
            }

            $license = get_option( self::OPTION_LICENSE );
            if ( $license === false ) {
                add_option( self::OPTION_LICENSE, array(
                    'license_key'     => '',
                    'license_status'  => 'inactive',
                    'license_expires' => '',
                ) );
            } else {
                // normalize license option
                if ( ! is_array( $license ) ) {
                    $license = array();
                }
                $license = wp_parse_args( $license, array(
                    'license_key'     => '',
                    'license_status'  => 'inactive',
                    'license_expires' => '',
                ) );
                update_option( self::OPTION_LICENSE, $license );
            }
        }

        public function deactivate() {
            // nothing to clean up on deactivation for now
        }

        /**
         * Ensure main settings exist and merge defaults carefully.
         */
        public function maybe_register_options() {
            $stored = get_option( self::OPTION_NAME );
            if ( $stored === false ) {
                add_option( self::OPTION_NAME, $this->defaults );
                $stored = $this->defaults;
            }

            if ( ! is_array( $stored ) ) {
                $stored = array();
            }

            $merged = wp_parse_args( $stored, $this->defaults );

            if ( $merged !== $stored ) {
                // update only if we've filled missing keys
                update_option( self::OPTION_NAME, $merged );
            }

            // ensure license option exists (do not mix with main settings)
            $license = get_option( self::OPTION_LICENSE );
            if ( $license === false || ! is_array( $license ) ) {
                $license = wp_parse_args( (array) $license, array(
                    'license_key'     => '',
                    'license_status'  => 'inactive',
                    'license_expires' => '',
                ) );
                update_option( self::OPTION_LICENSE, $license );
            }
        }

        /**
         * Register settings fields.
         */
        public function register_settings() {
            register_setting( self::OPTION_GROUP, self::OPTION_NAME, array( $this, 'sanitize_settings' ) );

            add_settings_section(
                'wc_wa_main_section',
                'Settings',
                '__return_null',
                self::OPTION_GROUP
            );

            $fields = array(
                'wa_phone_id' => 'WhatsApp Phone ID (from Meta / Cloud API)',
                'wa_access_token' => 'WhatsApp Access Token',
                'wa_admin_number' => 'Admin WhatsApp Number (E.164, e.g., 233XXXXXXXXX)',
                'enable_admin_notify' => 'Enable admin order notifications',
                'enable_customer_notify' => 'Enable customer order notifications',
                //Customer thank You
                'customer_thankyou_template'  =>  'Customer template - Thank you',

                // Customer templates
                'customer_template_pending'    => 'Customer template - Pending',
                'customer_template_processing' => 'Customer template - Processing',
                'customer_template_completed'  => 'Customer template - Completed',
                'customer_template_cancelled'  => 'Customer template - Cancelled',

                // Admin templates
                'admin_template_pending'    => 'Admin template - Pending',
                'admin_template_processing' => 'Admin template - Processing',
                'admin_template_completed'  => 'Admin template - Completed',
                'admin_template_cancelled'  => 'Admin template - Cancelled',
                
                'enable_product_button' => 'Show product "Order via WhatsApp" button',
                'product_button_text' => 'Product button text',
                

            );

            foreach ( $fields as $key => $label ) {
                add_settings_field(
                    $key,
                    $label,
                    array( $this, 'render_field' ),
                    self::OPTION_GROUP,
                    'wc_wa_main_section',
                    array( 'key' => $key )
                );
            }
        }

        /**
         * Sanitize settings coming from the Settings API.
         *
         * @param mixed $input
         * @return array
         */
        public function sanitize_settings( $input ) {
            if ( ! is_array( $input ) ) {
                $current = get_option( self::OPTION_NAME );
                if ( ! is_array( $current ) ) {
                    $current = $this->defaults;
                }
                return $current;
            }

            $out = get_option( self::OPTION_NAME );
            if ( ! is_array( $out ) ) {
                $out = $this->defaults;
            }
            $out = wp_parse_args( $out, $this->defaults );

            $out['wa_phone_id'] = isset( $input['wa_phone_id'] ) ? sanitize_text_field( $input['wa_phone_id'] ) : $out['wa_phone_id'];
            $out['wa_access_token'] = isset( $input['wa_access_token'] ) ? sanitize_text_field( $input['wa_access_token'] ) : $out['wa_access_token'];
            $out['wa_admin_number'] = isset( $input['wa_admin_number'] ) ? sanitize_text_field( $input['wa_admin_number'] ) : $out['wa_admin_number'];

            $out['enable_admin_notify'] = ( isset( $input['enable_admin_notify'] ) && $input['enable_admin_notify'] === 'yes' ) ? 'yes' : 'no';
            $out['enable_customer_notify'] = ( isset( $input['enable_customer_notify'] ) && $input['enable_customer_notify'] === 'yes' ) ? 'yes' : 'no';

            
             $out['customer_thankyou_template'] = isset( $input['customer_thankyou_template'] ) ? wp_kses_post( $input['customer_thankyou_template'] ) : $out['customer_thankyou_template'];


            $out['customer_template_pending'] = isset( $input['customer_template_pending'] ) ? wp_kses_post( $input['customer_template_pending'] ) : $out['customer_template_pending'];
            $out['customer_template_processing'] = isset( $input['customer_template_processing'] ) ? wp_kses_post( $input['customer_template_processing'] ) : $out['customer_template_processing'];
            $out['customer_template_completed'] = isset( $input['customer_template_completed'] ) ? wp_kses_post( $input['customer_template_completed'] ) : $out['customer_template_completed'];
            $out['customer_template_cancelled'] = isset( $input['customer_template_cancelled'] ) ? wp_kses_post( $input['customer_template_cancelled'] ) : $out['customer_template_cancelled'];


            $out['admin_template_pending'] = isset( $input['admin_template_pending'] ) ? wp_kses_post( $input['admin_template_pending'] ) : $out['admin_template_pending'];
            $out['admin_template_processing'] = isset( $input['admin_template_processing'] ) ? wp_kses_post( $input['admin_template_processing'] ) : $out['admin_template_processing'];
            $out['admin_template_completed'] = isset( $input['admin_template_completed'] ) ? wp_kses_post( $input['admin_template_completed'] ) : $out['admin_template_completed'];
            $out['admin_template_cancelled'] = isset( $input['admin_template_cancelled'] ) ? wp_kses_post( $input['admin_template_cancelled'] ) : $out['admin_template_cancelled'];

            $out['enable_product_button'] = ( isset( $input['enable_product_button'] ) && $input['enable_product_button'] === 'yes' ) ? 'yes' : 'no';
            $out['product_button_text'] = isset( $input['product_button_text'] ) ? sanitize_text_field( $input['product_button_text'] ) : $out['product_button_text'];


            // license remains managed via separate option
            return $out;
        }

        /**
         * Render settings fields.
         */
        public function render_field( $args ) {
              $is_pro = $this->is_license_active();
            $key = $args['key'];
            $opts = get_option( self::OPTION_NAME );
            $opts = wp_parse_args( is_array( $opts ) ? $opts : array(), $this->defaults );

            switch ( $key ) {
                case 'wa_phone_id':
                case 'wa_access_token':
                case 'wa_admin_number':
                case 'product_button_text':
                    printf(
                        '<input type="text" name="%s[%s]" value="%s" class="regular-text" />',
                        esc_attr( self::OPTION_NAME ),
                        esc_attr( $key ),
                        esc_attr( isset( $opts[ $key ] ) ? $opts[ $key ] : '' )
                    );
                    break;

                case 'enable_admin_notify':
                case 'enable_customer_notify':
                case 'enable_product_button':
                    printf(
                        '<label><input type="checkbox" name="%s[%s]" value="yes" %s/> Enabled</label>',
                        esc_attr( self::OPTION_NAME ),
                        esc_attr( $key ),
                        checked( 'yes', isset( $opts[ $key ] ) ? $opts[ $key ] : 'no', false )
                    );
                    break;

                case 'customer_thankyou_template':
                case 'customer_template_pending':
                case 'customer_template_processing':
                case 'customer_template_completed':
                case 'customer_template_cancelled':
                case 'admin_template_pending':
                case 'admin_template_processing':
                case 'admin_template_completed':
                case 'admin_template_cancelled':    
                    
                    $value = isset( $opts[ $key ] ) ? $opts[ $key ] : '';

                    // Allow thank-you template in FREE
                    if ( ! $is_pro && $key !== 'customer_thankyou_template' ) {

                        echo '<p style="color:#888;font-weight:600;">Pro Feature — Upgrade to unlock order status automation.</p>';

                        printf(
                            '<textarea disabled rows="6" cols="60" class="large-text code" style="background:#f5f5f5;">%s</textarea>',
                            esc_textarea( $value )
                        );

                    } else {

                        printf(
                            '<textarea name="%s[%s]" rows="6" cols="60" class="large-text code">%s</textarea>',
                            esc_attr( self::OPTION_NAME ),
                            esc_attr( $key ),
                            esc_textarea( $value )
                        );

                    }

                    echo '<p class="description">Available placeholders: {order_id}, {order_total}, {customer_name}, {order_items}</p>';

                    break;
                default:
                    break;
            }
        }

        /**
         * Admin menu page.
         */
        public function admin_menu() {
            add_menu_page(
                'WaOrders',
                'WaOrders',
                'manage_options',
                'wc-wa-shop-ai',
                array( $this, 'settings_page' ),
                'dashicons-whatsapp',
                56
            );
        }

        /**
         * License submenu.
         */
        public function license_menu() {
            add_submenu_page(
                'wc-wa-shop-ai',
                'License Activation',
                'License',
                'manage_options',
                'wc-wa-shop-ai-license',
                array( $this, 'license_page' )
            );
        }

        /**
         * Settings page HTML.
         */
        public function settings_page() {
            $opts = get_option( self::OPTION_NAME );
            $opts = wp_parse_args( is_array( $opts ) ? $opts : array(), $this->defaults );
            $license = $this->get_license_data();
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'WaOrders', 'wc-wa-shop-ai' ); ?></h1>

                <?php if ( ! $this->is_license_active() ) : ?>
                    <div style="margin:20px 0;padding:20px;background:#fff;border:1px solid #ccd0d4;border-left:4px solid #2271b1;">
                        
                        <h2 style="margin-top:0;">🚀 Upgrade to WaOrders Pro</h2>

                        <p>
                            Unlock powerful automation for your WooCommerce store:
                        </p>

                        <ul style="list-style:disc;padding-left:20px;">
                            <li>✔ Automatic order status updates (Processing, Completed, Cancelled)</li>
                            <li>✔ Admin WhatsApp notifications</li>
                            <li>✔ Full template customization</li>
                            <li>✔ Advanced WhatsApp automation system</li>
                        </ul>

                        <p style="margin-top:15px;">
                            <a href="https://www.waorders.com/#pricing"
                            class="button button-primary button-large"
                            target="_blank">
                            Upgrade to Pro
                            </a>

                            <a href="<?php echo admin_url( 'admin.php?page=wc-wa-shop-ai-license' ); ?>"
                            class="button"
                            style="margin-left:10px;">
                            Enter License Key
                            </a>
                        </p>
                    </div>
                <?php endif; ?>

                <form method="post" action="options.php">
                    <?php
                    settings_fields( self::OPTION_GROUP );
                    do_settings_sections( self::OPTION_GROUP );
                    submit_button();
                    ?>
                </form>

                <hr />
                <h2><?php esc_html_e( 'Quick Shortcodes & Usage', 'wc-wa-shop-ai' ); ?></h2>
                <p><code>[wa_chat_button]</code> — displays a generic WhatsApp chat/order button (can be used anywhere)</p>
                <p><?php esc_html_e( 'Product pages automatically show a "Order via WhatsApp" button when enabled.', 'wc-wa-shop-ai' ); ?></p>
                <p><strong>License status:</strong> <?php echo esc_html( $license['license_status'] ?? 'inactive' ); ?></p>
            </div>
            <?php
        }


        /**
         * Enqueue frontend assets (if present).
         */
        public function enqueue_assets() {
            if ( ! is_admin() ) {
                wp_register_style( 'wc-wa-shop-ai-frontend', plugins_url( 'assets/frontend.css', __FILE__ ) );
                wp_enqueue_style( 'wc-wa-shop-ai-frontend' );
                wp_register_script( 'wc-wa-shop-ai-frontend-js', plugins_url( 'assets/frontend.js', __FILE__ ), array( 'jquery' ), false, true );
                wp_localize_script( 'wc-wa-shop-ai-frontend-js', 'wcWaShopAi', array(
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                ) );
                wp_enqueue_script( 'wc-wa-shop-ai-frontend-js' );
            }
        }

        /**
         * Enqueue admin styles.
         */
        public function admin_assets( $hook ) {
            if ( strpos( $hook, 'wc-wa-shop-ai' ) === false && strpos( $hook, 'wc-wa-shop-ai-license' ) === false ) {
                return;
            }
            wp_enqueue_style( 'wc-wa-shop-ai-admin', plugins_url( 'assets/admin.css', __FILE__ ) );
        }
         /**
         * Handle WooCommerce order status changes.
         *
         * @param int    $order_id
         * @param string $old_status
         * @param string $new_status
         * @param WC_Order $order
         */
        public function handle_order_status_change( $order_id, $old_status, $new_status, $order ) {
            // PRO LOCK
            if ( ! $this->is_license_active() ) {
                return;
            }

            if ( ! $order instanceof WC_Order ) {
                return;
            }

            // Prevent duplicate sends per status
            $meta_key = '_wa_notified_status_' . $new_status;

            if ( $order->get_meta( $meta_key ) ) {
                return;
            }

            $opts = get_option( self::OPTION_NAME );
            $opts = wp_parse_args( is_array( $opts ) ? $opts : array(), $this->defaults );

            // Notify customer
            if ( isset( $opts['enable_customer_notify'] ) && $opts['enable_customer_notify'] === 'yes' ) {
                $this->send_customer_order_message( $order_id, $new_status );
            }

            // Notify admin
            if ( isset( $opts['enable_admin_notify'] ) && $opts['enable_admin_notify'] === 'yes' ) {
                $this->send_admin_order_message( $order_id, $new_status );
            }

            // Mark this status as notified
            $order->update_meta_data( $meta_key, time() );
            $order->save();
        }

        /**
         * Output product button on single product pages.
         */
        public function output_product_button() {
            $opts = get_option( self::OPTION_NAME );
            $opts = wp_parse_args( is_array( $opts ) ? $opts : array(), $this->defaults );

            if ( isset( $opts['enable_product_button'] ) && $opts['enable_product_button'] === 'yes' ) {
                global $product;
                if ( ! $product ) {
                    return;
                }
                $text = isset( $opts['product_button_text'] ) ? $opts['product_button_text'] : $this->defaults['product_button_text'];
                $product_id = $product->get_id();
                $title = $product->get_name();
                $price = wc_get_price_to_display( $product );
                $permalink = get_permalink( $product_id );

                $message = rawurlencode( sprintf( "Hello, I want to order:\n%s\nPrice: %s\nLink: %s", $title, wc_price( $price ), $permalink ) );

                $wa_number = isset( $opts['wa_admin_number'] ) ? $opts['wa_admin_number'] : '';
                $wa_link = $this->build_wa_click_link( $wa_number, $message );

                echo '<div class="wc-wa-product-button">';
                echo '<a class="button alt wc-wa-btn" href="' . esc_url( $wa_link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $text ) . '</a>';
                echo '</div>';
            }
        }

        /**
         * Shortcode [wa_chat_button].
         */
        public function shortcode_chat_button( $atts ) {
            $opts = get_option( self::OPTION_NAME );
            $opts = wp_parse_args( is_array( $opts ) ? $opts : array(), $this->defaults );

            $atts = shortcode_atts( array(
                'text' => isset( $opts['product_button_text'] ) ? $opts['product_button_text'] : $this->defaults['product_button_text'],
                'number' => isset( $opts['wa_admin_number'] ) ? $opts['wa_admin_number'] : '',
                'message' => '',
            ), $atts, 'wa_chat_button' );

            $message = $atts['message'] ?: "Hello, I need help with a product.";
            $message = rawurlencode( $message );
            $link = $this->build_wa_click_link( $atts['number'], $message );

            return '<a class="button wc-wa-shortcode-btn" href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $atts['text'] ) . '</a>';
        }

        private function build_wa_click_link( $number, $message ) {
            $number = preg_replace( '/\D+/', '', $number );
            if ( empty( $number ) ) {
                return 'https://web.whatsapp.com/send?text=' . $message;
            }
            return 'https://wa.me/' . $number . '?text=' . $message;
        }

        /**
         * Order triggers
         */
        public function maybe_notify_on_order( $order_id ) {
            if ( ! $order_id ) {
                return;
            }
            $opts = get_option( self::OPTION_NAME );
            $opts = wp_parse_args( is_array( $opts ) ? $opts : array(), $this->defaults );

            if ( isset( $opts['enable_customer_notify'] ) && $opts['enable_customer_notify'] === 'yes' ) {
                $this->send_customer_order_message( $order_id );
            }
        }

        // public function maybe_notify_admin_on_new_order( $order_id ) {
        //     if ( ! $order_id ) {
        //         return;
        //     }
        //     $opts = get_option( self::OPTION_NAME );
        //     $opts = wp_parse_args( is_array( $opts ) ? $opts : array(), $this->defaults );

        //     if ( isset( $opts['enable_admin_notify'] ) && $opts['enable_admin_notify'] === 'yes' ) {
        //         $this->send_admin_order_message( $order_id );
        //     }
        // }

        private function send_customer_order_message( $order_id ,$status = null) {
            
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return;
            }
     

            $billing_phone = $order->get_billing_phone();
            $phone_number = $this->normalize_phone_for_wa( $billing_phone );

            if ( empty( $phone_number ) ) {
                return;
            }

            $opts = get_option( self::OPTION_NAME );
            $opts = wp_parse_args( is_array( $opts ) ? $opts : array(), $this->defaults );


            $template_key = 'customer_template_' . $status;

            if ($status === null) {
                 $template_key = 'customer_thankyou_template' . $status;
             }
                
            $template = $opts[ $template_key ] ?? $this->defaults[ $template_key ] ?? '';

            $message = $this->populate_template( $template, $order );
            
            
            $this->send_whatsapp_message_smart( $phone_number, $message );
        }

        private function send_admin_order_message( $order_id ,$status = null) {
            // if ($status === null) {
            //     return "One parameter: status";
            //  }
            $opts = get_option( self::OPTION_NAME );
            $opts = wp_parse_args( is_array( $opts ) ? $opts : array(), $this->defaults );
            $admin_number = isset( $opts['wa_admin_number'] ) ? $opts['wa_admin_number'] : '';
            $admin_number = $this->normalize_phone_for_wa( $admin_number );
            if ( empty( $admin_number ) ) {
                return;
            }
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return;
            }
            $template_key = 'admin_template_' . $status;
            $template = $opts[ $template_key ] ?? $this->defaults[ $template_key ] ?? '';

            $message = $this->populate_template( $template, $order );
            // $this->send_whatsapp_cloud_text_message( $admin_number, $message );
            $this->send_whatsapp_message_smart( $admin_number, $message );
        }

        private function normalize_phone_for_wa( $phone ) {
            if ( empty( $phone ) ) {
                return '';
            }
            $phone = trim( $phone );
            $digits = preg_replace( '/\D+/', '', $phone );
            return $digits;
        }
       

        private function populate_template( $template, $order ) {
            $order_id = $order->get_id();
            $order_total = wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) );
            $customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
            $items = array();
            foreach ( $order->get_items() as $item ) {
                $product_name = $item->get_name();
                $qty = $item->get_quantity();
                $items[] = sprintf( '%s x%d', $product_name, $qty );
            }
            $order_items = implode( "\n", $items );
            $replacements = array(
                '{order_id}' => $order_id,
                '{order_total}' => $order_total,
                '{customer_name}' => $customer_name,
                '{order_items}' => $order_items,
            );
            return strtr( $template, $replacements );
        }

        /**
         * Send message via WhatsApp Cloud API (gated by license).
         */
        private function send_whatsapp_cloud_text_message( $to_digits, $message_body ) {
            $opts = get_option( self::OPTION_NAME );
            $opts = wp_parse_args( is_array( $opts ) ? $opts : array(), $this->defaults );

            $phone_id = isset( $opts['wa_phone_id'] ) ? $opts['wa_phone_id'] : '';
            $access_token = isset( $opts['wa_access_token'] ) ? $opts['wa_access_token'] : '';

            if ( empty( $phone_id ) || empty( $access_token ) ) {
                return false;
            }

            $payload = array(
                'messaging_product' => 'whatsapp',
                'to' => $to_digits,
                'type' => 'text',
                'text' => array( 'body' => $message_body ),
            );
          

            $url = "https://graph.facebook.com/v24.0/" . rawurlencode( $phone_id ) . "/messages";
          
            $args = array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode( $payload ),
                'timeout' => 15,    
            );

            $response = wp_remote_post( $url, $args );
   
            if ( is_wp_error( $response ) ) {
                return false;
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( intval( $code ) >= 200 && intval( $code ) < 300 ) {
                set_transient( 'wa_last_chat_' . $to_digits, time(), DAY_IN_SECONDS );
                return true;
            }
           

            return false;
        }
        private function send_whatsapp_message_smart( $to, $text ) {

            if ( $this->is_session_open( $to ) ) {
                // Session open → send free text
                $this->send_whatsapp_cloud_text_message( $to, $text );
                return;
            }
            return $this->send_whatsapp_session_reopen_template_with_text( $to, $text );

          
        }
        private function send_whatsapp_session_reopen_template_with_text( $to, $text ) {

            $opts = get_option( self::OPTION_NAME );
            $opts = wp_parse_args( is_array( $opts ) ? $opts : array(), $this->defaults );

            $payload = array(
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'template',
                'template' => array(
                    'name' => 'order_update_notification',
                    'language' => array(
                        'code' => 'en',
                    ),
                    'components' => array(
                        array(
                            'type' => 'body',
                            'parameters' => array(
                                array(
                                    'type' => 'text',
                                    'text' => $text
                                )
                            ),
                        ),
                    ),
                ),
            );

            $url = "https://graph.facebook.com/v24.0/" . rawurlencode( $opts['wa_phone_id'] ) . "/messages";

            return wp_remote_post( $url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $opts['wa_access_token'],
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode( $payload ),
                'timeout' => 15,
            ) );
        }


        /* --------------------------
         * LICENSE: separate option storage + admin page
         * -------------------------- */

        /**
         * Retrieve license data from separate option.
         *
         * @return array
         */
        private function get_license_data() {
            $license = get_option( self::OPTION_LICENSE );

            if ( ! is_array( $license ) ) {
                $license = array();
            }
            $license = wp_parse_args( $license, array(
                'license_key'     => '',
                'license_status'  => 'inactive',
                'license_expires' => '',
            ) );
            return $license;
        }

        /**
         * Update license option (merges with existing).
         *
         * @param array $data
         * @return array
         */
        private function update_license_data( array $data ) {
            $current = $this->get_license_data();
            $merged = wp_parse_args( $data, $current );
            update_option( self::OPTION_LICENSE, $merged );
            return $merged;
        }

        /**
         * License admin page + activation logic.
         */
        public function license_page() {
            $license = $this->get_license_data();

            $license_key = $license['license_key'];
            $status = $license['license_status'];
            $expires = $license['license_expires'];

            // Handle activation form
            if ( isset( $_POST['wc_wa_license_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['wc_wa_license_nonce'] ), 'wc_wa_license_activate' ) ) {
                if ( isset( $_POST['license_key'] ) ) {
                    $key = sanitize_text_field( wp_unslash( $_POST['license_key'] ) );
                    $check = $this->check_license_with_server( $key );
                    if ( is_array( $check ) && isset( $check['valid'] ) && $check['valid'] === true ) {
                        // success -> save into separate license option
                        $this->update_license_data( array(
                            'license_key'     => $key,
                            'license_status'  => 'active',
                            'license_expires' => isset( $check['expires_at'] ) ? sanitize_text_field( $check['expires_at'] ) : '',
                        ) );
                        echo '<div class="notice notice-success is-dismissible"><p>License activated successfully.</p></div>';
                        // refresh local vars
                        $license = $this->get_license_data();
                        $license_key = $license['license_key'];
                        $status = $license['license_status'];
                        $expires = $license['license_expires'];
                    } else {
                        echo '<div class="notice notice-error is-dismissible"><p>License invalid or server returned an error.</p></div>';
                    }
                }
            }

            // Handle deactivation form
            if ( isset( $_POST['wc_wa_license_deactivate_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['wc_wa_license_deactivate_nonce'] ), 'wc_wa_license_deactivate' ) ) {
                $this->update_license_data( array(
                    'license_key'     => '',
                    'license_status'  => 'inactive',
                    'license_expires' => '',
                ) );
                echo '<div class="notice notice-success is-dismissible"><p>License deactivated.</p></div>';
                $license = $this->get_license_data();
                $license_key = $license['license_key'];
                $status = $license['license_status'];
                $expires = $license['license_expires'];
            }

            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'License Activation', 'wc-wa-shop-ai' ); ?></h1>
                <form method="post" class="wc-wa-license-form">
                    <?php wp_nonce_field( 'wc_wa_license_activate', 'wc_wa_license_nonce' ); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="license_key">License Key</label></th>
                            <td><input name="license_key" id="license_key" type="text" value="<?php echo esc_attr( $license_key ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row">Status</th>
                            <td>
                                <?php if ( $status === 'active' ): ?>
                                    <span style="color:green;font-weight:bold;">ACTIVE</span>
                                    <?php if ( ! empty( $expires ) ): ?>
                                        &nbsp; (expires: <?php echo esc_html( $expires ); ?>)
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:red;font-weight:bold;">INACTIVE</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( 'Activate License' ); ?>
                </form>

                <?php if ( $status === 'active' ) : ?>
                    <form method="post" style="margin-top:10px;">
                        <?php wp_nonce_field( 'wc_wa_license_deactivate', 'wc_wa_license_deactivate_nonce' ); ?>
                        <?php submit_button( 'Deactivate License', 'secondary', 'deactivate_license' ); ?>
                    </form>
                <?php endif; ?>

               
            </div>
            <?php
        }

        /**
         * AJAX deactivate license.
         */
        public function ajax_license_deactivate() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'forbidden', 403 );
            }
            check_ajax_referer( 'wc_wa_license_deactivate', 'nonce' );
            $this->update_license_data( array(
                'license_key'     => '',
                'license_status'  => 'inactive',
                'license_expires' => '',
            ) );
            wp_send_json_success();
        }

        /**
         * Call to SaaS license server.
         *
         * Expected JSON response example:
         *  { "valid": true, "expires_at": "2026-01-01" }
         *
         * @param string $license_key
         * @return bool|array
         */
        private function check_license_with_server( $license_key ) {
            $license_key = trim( $license_key );
            if ( empty( $license_key ) ) {
                return false;
            }

            $payload = array(
                'license_key' => $license_key,
                'domain' => home_url(),
            );
            
            // TODO: Replace with your real license server endpoint (use HTTPS)
            $endpoint = 'http://localhost:8000/api/check-license';

            $args = array(
                'body' => wp_json_encode( $payload ),
                'headers' => array(
                    'Content-Type' => 'application/json',
                    // 'Authorization' => 'Bearer your_server_secret',
                ),
                'timeout' => 20,
            );

            $response = wp_remote_post( $endpoint, $args );
            

            if ( is_wp_error( $response ) ) {
                return false;
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( intval( $code ) < 200 || intval( $code ) > 299 ) {
                return false;
            }

            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );

            if ( ! is_array( $data ) ) {
                return false;
            }

            return $data;
        }

        /* --------------------------
         * License helpers
         * -------------------------- */

        public function get_license_status() {
            $license = $this->get_license_data();
           
            return isset( $license['license_status'] ) ? $license['license_status'] : 'inactive';
        }

        public function is_license_active() {
            return ( $this->get_license_status() === 'active' );
        }
        private function is_session_open( $phone ) {
            $last = get_transient( 'wa_last_chat_' . $phone );
            return $last && ( time() - $last ) < DAY_IN_SECONDS;
            
        }

    }

endif;

// Register activation/deactivation hooks
register_activation_hook( __FILE__, array( 'WC_WA_Shop_AI', 'activate_plugin' ) );
register_deactivation_hook( __FILE__, array( 'WC_WA_Shop_AI', 'deactivate_plugin' ) );

// Instantiate plugin
WC_WA_Shop_AI::instance();
