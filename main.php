<?php
/*
Plugin Name: WhatsApp WooCommerce
Plugin URI: https://yourdomain.com/whatsapp-woocommerce
Description: Automatically send WhatsApp notifications for WooCommerce orders.
Version: 1.0.0
Author: Kwafo Nana Mensah
Author URI: https://www.nanaengineer.dev/
License: GPLv2 or later
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Load core plugin file
require_once plugin_dir_path(__FILE__) . 'includes/class-whatsapp-woocommerce.php';
