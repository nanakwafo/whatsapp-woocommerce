<?php
class WhatsApp_WooCommerce {
    public function __construct() {
        add_action('woocommerce_order_status_processing', [$this, 'send_whatsapp_message'], 10, 1);
    }

    public function send_whatsapp_message($order_id) {
        $order = wc_get_order($order_id);
        $phone = $order->get_billing_phone();
        $message = "Hi " . $order->get_billing_first_name() .
            ", thank you for your order #" . $order->get_order_number() . "!";

        // Placeholder for WhatsApp API call
        error_log("Send WhatsApp to {$phone}: {$message}");
    }
}

new WhatsApp_WooCommerce();
