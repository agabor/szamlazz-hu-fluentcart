<?php
/**
 * Plugin Name: Számlázz.hu FluentCart Integration
 * Plugin URI: https://webshop.tech/szamlazz-hu-fluentcart
 * Description: Generates invoices on Számlázz.hu for FluentCart orders
 * Version: 0.0.1
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
use \SzamlaAgent\Buyer;
use \SzamlaAgent\Document\Invoice\Invoice;
use \SzamlaAgent\Item\InvoiceItem;

/**
 * Create database table on plugin activation
 */
register_activation_hook(__FILE__, function() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'szamlazz_invoices';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        order_id bigint(20) NOT NULL,
        invoice_number varchar(255) NOT NULL,
        invoice_id varchar(255) DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY order_id (order_id),
        KEY invoice_number (invoice_number)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
});

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
 * Register REST API endpoint for invoice download
 */
add_action('rest_api_init', function() {
    register_rest_route('szamlazz-hu/v1', '/invoice/(?P<invoice_number>[a-zA-Z0-9\-]+)/download', [
        'methods' => 'GET',
        'callback' => 'szamlazz_hu_download_invoice',
        'permission_callback' => '__return_true', // No authorization required yet
        'args' => [
            'invoice_number' => [
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field'
            ]
        ]
    ]);
});

/**
 * Download invoice PDF callback
 */
function szamlazz_hu_download_invoice($request) {
    global $wpdb;
    
    try {
        $invoice_number = $request->get_param('invoice_number');
        
        // Get API key from settings
        $api_key = get_option('szamlazz_hu_agent_api_key', '');
        
        if (empty($api_key)) {
            return new WP_Error(
                'api_key_missing',
                'Agent API Key is not configured',
                ['status' => 500]
            );
        }
        
        // Check if invoice exists in database
        $table_name = $wpdb->prefix . 'szamlazz_invoices';
        $invoice_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE invoice_number = %s",
            $invoice_number
        ));
        
        if (!$invoice_record) {
            return new WP_Error(
                'invoice_not_found',
                'Invoice not found',
                ['status' => 404]
            );
        }
        
        // Create Számla Agent
        $agent = SzamlaAgentAPI::create($api_key);
        
        // Get invoice PDF
        $result = $agent->getInvoicePdf($invoice_number);
        
        // Check if PDF was retrieved successfully
        if ($result->isSuccess()) {
            $result->downloadPdf();
            exit;
        } else {
            return new WP_Error(
                'pdf_download_failed',
                'Failed to download invoice PDF: ' . $result->getMessage(),
                ['status' => 500]
            );
        }
        
    } catch (\Exception $e) {
        error_log('Számlázz.hu download error: ' . $e->getMessage());
        return new WP_Error(
            'download_error',
            'Error downloading invoice: ' . $e->getMessage(),
            ['status' => 500]
        );
    }
}

/**
 * Hook into FluentCart order creation
 */
add_action('fluent_cart/order_created', function($data) {
    global $wpdb;
    
    try {
        // Get API key from settings
        $api_key = get_option('szamlazz_hu_agent_api_key', '');
        
        if (empty($api_key)) {
            throw new \Exception('Agent API Key is not configured. Please configure it in Settings > Számlázz.hu FluentCart');
        }
        
        $order = $data['order'];
        $order_id = $order->id;
        
        // Check if invoice already exists for this order
        $table_name = $wpdb->prefix . 'szamlazz_invoices';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE order_id = %d",
            $order_id
        ));
        
        if ($existing) {
            error_log("Invoice already exists for order $order_id: {$existing->invoice_number}");
            return;
        }
        
        // Create Számla Agent
        $agent = SzamlaAgentAPI::create($api_key);
        $agent->setPdfFileSave(false);
        
        // Get checkout data and VAT number
        $checkout_data = FluentCart\App\Models\Cart::where('order_id', $data['order']['id'])->first()['checkout_data'];
        $vat_number = $checkout_data['tax_data']['vat_number'] ?? null;
        
        // Get billing address
        $billing = $order->billing_address;
        if (!$billing) {
            throw new \Exception("No billing address found for order $order_id");
        }
        
        // Parse meta data for additional info
        $meta = json_decode($billing->meta, true);
        
        // Initialize buyer variables with defaults
        $buyer_name = $billing->name;
        $buyer_postcode = $billing->postcode;
        $buyer_city = $billing->city;
        $buyer_address = $billing->address_1 . ($billing->address_2 ? ' ' . $billing->address_2 : '');
        $buyer_vat_id = null;
        
        // If VAT number is provided, get taxpayer data from NAV
        if (!empty($vat_number)) {
            try {
                $taxpayer_response = $agent->getTaxPayer($vat_number);
                $taxpayer_xml = $taxpayer_response->getTaxPayerData();
                
                if ($taxpayer_xml) {
                    // Parse XML
                    $xml = new \SimpleXMLElement($taxpayer_xml);
                    
                    // Register namespaces
                    $xml->registerXPathNamespace('ns2', 'http://schemas.nav.gov.hu/OSA/3.0/api');
                    $xml->registerXPathNamespace('ns3', 'http://schemas.nav.gov.hu/OSA/3.0/base');
                    
                    // Extract taxpayer name
                    $taxpayer_short_name = $xml->xpath('//ns2:taxpayerShortName');
                    $taxpayer_name = $xml->xpath('//ns2:taxpayerName');
                    
                    if (!empty($taxpayer_short_name)) {
                        $buyer_name = (string)$taxpayer_short_name[0];
                    } elseif (!empty($taxpayer_name)) {
                        $buyer_name = (string)$taxpayer_name[0];
                    }
                    
                    // Extract VAT ID components and construct full VAT ID
                    $taxpayer_id = $xml->xpath('//ns3:taxpayerId');
                    $vat_code = $xml->xpath('//ns3:vatCode');
                    $county_code = $xml->xpath('//ns3:countyCode');
                    
                    if (!empty($taxpayer_id) && !empty($vat_code) && !empty($county_code)) {
                        $buyer_vat_id = sprintf(
                            '%s-%s-%s',
                            (string)$taxpayer_id[0],
                            (string)$vat_code[0],
                            (string)$county_code[0]
                        );
                    }
                    
                    // Extract address from taxpayer data
                    $postal_code = $xml->xpath('//ns3:postalCode');
                    $city = $xml->xpath('//ns3:city');
                    $street_name = $xml->xpath('//ns3:streetName');
                    $public_place = $xml->xpath('//ns3:publicPlaceCategory');
                    $number = $xml->xpath('//ns3:number');
                    $door = $xml->xpath('//ns3:door');
                    
                    if (!empty($postal_code)) {
                        $buyer_postcode = (string)$postal_code[0];
                    }
                    
                    if (!empty($city)) {
                        $buyer_city = (string)$city[0];
                    }
                    
                    if (!empty($street_name)) {
                        $address_parts = [(string)$street_name[0]];
                        
                        if (!empty($public_place)) {
                            $address_parts[] = (string)$public_place[0];
                        }
                        
                        if (!empty($number)) {
                            $address_parts[] = (string)$number[0];
                        }
                        
                        if (!empty($door)) {
                            $address_parts[] = (string)$door[0];
                        }
                        
                        $buyer_address = implode(' ', $address_parts);
                    }
                }
            } catch (\Exception $e) {
                error_log("Failed to fetch taxpayer data for VAT number $vat_number: " . $e->getMessage());
                // Continue with default billing address if taxpayer lookup fails
            }
        }
        
        // Create buyer with taxpayer data or billing address
        $buyer = new Buyer(
            $buyer_name,
            $buyer_postcode,
            $buyer_city,
            $buyer_address
        );
        
        // Set VAT ID if available
        if (!empty($buyer_vat_id)) {
            $buyer->setTaxNumber($buyer_vat_id);
        }
        
        // Set buyer email if available
        if (isset($meta['other_data']['email'])) {
            $buyer->setEmail($meta['other_data']['email']);
        }
        
        // Create invoice
        $invoice = new Invoice(Invoice::INVOICE_TYPE_P_INVOICE);
        $invoice->setBuyer($buyer);
        
        // Set currency
        //$invoice->setCurrency($order->currency);
        
        // Get order items
        $items = \FluentCart\App\Models\OrderItem::where('order_id', $order_id)->get();
        
        if ($items->isEmpty()) {
            throw new \Exception("No items found for order $order_id");
        }
        
        // Add items to invoice
        foreach ($items as $order_item) {
            // Convert amounts from cents to currency units
            $net_price = $order_item->unit_price / 100;
            $vat_amount = $order_item->tax_amount / $order_item->quantity / 100;
            $gross_amount = $order_item->line_total / 100;
            
            $item = new InvoiceItem(
                $order_item->title,
                $net_price
            );
            
			$item->setNetPrice($net_price);
            $item->setVatAmount($vat_amount);
            $item->setGrossAmount($gross_amount + $vat_amount);
            
            $invoice->addItem($item);
        }
        
        // Generate invoice
        $result = $agent->generateInvoice($invoice);
        
        // Check if invoice was created successfully
        if ($result->isSuccess()) {
            $invoice_number = $result->getDocumentNumber();
            
            // Save to database
            $wpdb->insert(
                $table_name,
                [
                    'order_id' => $order_id,
                    'invoice_number' => $invoice_number,
                    'invoice_id' => $result->getDataObj()->invoiceId ?? null
                ],
                ['%d', '%s', '%s']
            );
            
            // Add order note
            $note = sprintf(
                'Számlázz.hu invoice created: %s',
                $invoice_number
            );
        } else {
            throw new \Exception('Failed to generate invoice: ' . $result->getMessage());
        }
        
    } catch (\Exception $e) {
        file_put_contents('/var/www/error.txt', var_export($e, true));
        error_log('Számlázz.hu error for order ' . ($order_id ?? 'unknown') . ': ' . $e->getMessage());
    }
}, 10, 1);
