<?php
/**
 * Plugin Name: Számlázz.hu FluentCart Integration
 * Plugin URI: https://webshop.tech/szamlazz-hu-fluentcart
 * Description: Generates invoices on Számlázz.hu for FluentCart orders
 * Version: 1.0.0
 * Author: Gábor Angyal
 * Author URI: https://webshop.tech
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: szamlazz-hu-fluentcart
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

require __DIR__ . DIRECTORY_SEPARATOR .'autoload.php';

use \SzamlaAgent\SzamlaAgentAPI;

/**
 * Register admin menu
 */
add_action('admin_menu', function() {
    add_options_page(
        'Számlázz.hu FluentCart Settings',
        'Számlázz.hu FluentCart',
        'manage_options',
        'szamlazz-hu-fluentcart',
        'szamlazz_hu_fluentcart_settings_page'
    );
});

/**
 * Register settings
 */
add_action('admin_init', function() {
    register_setting('szamlazz_hu_fluentcart_settings', 'szamlazz_hu_agent_api_key');
    
    add_settings_section(
        'szamlazz_hu_api_section',
        'API Settings',
        function() {
            echo '<p>Enter your Számlázz.hu API credentials below.</p>';
        },
        'szamlazz-hu-fluentcart'
    );
    
    add_settings_field(
        'szamlazz_hu_agent_api_key',
        'Agent API Key',
        function() {
            $value = get_option('szamlazz_hu_agent_api_key', '');
            echo '<input type="text" name="szamlazz_hu_agent_api_key" value="' . esc_attr($value) . '" class="regular-text" />';
            echo '<p class="description">Your Számlázz.hu Agent API Key</p>';
        },
        'szamlazz-hu-fluentcart',
        'szamlazz_hu_api_section'
    );
});

/**
 * Settings page callback
 */
function szamlazz_hu_fluentcart_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    if (isset($_GET['settings-updated'])) {
        add_settings_error('szamlazz_hu_messages', 'szamlazz_hu_message', 'Settings Saved', 'updated');
    }
    
    settings_errors('szamlazz_hu_messages');
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('szamlazz_hu_fluentcart_settings');
            do_settings_sections('szamlazz-hu-fluentcart');
            submit_button('Save Settings');
            ?>
        </form>
    </div>
    <?php
}

/**
 * Hook into FluentCart order creation
 */
add_action('fluent_cart/order_created', function($data) {
    try {
        // Get API key from settings
        $api_key = get_option('szamlazz_hu_agent_api_key', '');
        
        if (empty($api_key)) {
            throw new \Exception('Agent API Key is not configured. Please configure it in Settings > Számlázz.hu FluentCart');
        }
        
        // Számla Agent létrehozása alapértelmezett adatokkal
        $agent = SzamlaAgentAPI::create($api_key);
        $checkout_data = FluentCart\App\Models\Cart::where('order_id', $data['order']['id'])->first()['checkout_data'];
        $result = $agent->getTaxPayer($checkout_data['tax_data']['vat_number']);
        file_put_contents('/var/www/tax_payer_data.txt', var_export($result->getTaxPayerData(), true));

    } catch (\Exception $e) {
        file_put_contents('/var/www/error.txt', $e->getMessage());
        $agent->logError($e->getMessage());
    }
}, 10, 1);
