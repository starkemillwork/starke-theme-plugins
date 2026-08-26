<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * ==========================================================================
 * QUOTE PRICE/DATA LOCK CONTROLLER
 * ==========================================================================
 */

class Quote_Lock_Controller {

    private static $instance;
    private $is_quote_locked = false;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
            self::$instance->init_hooks();
        }
        return self::$instance;
    }

    public function __construct() {
        // Constructor is intentionally empty; hooks are initialized below.
    }
    
    public function init_hooks() {
        add_action('woocommerce_before_calculate_totals', [$this, 'run_quote_lock_check'], 10);
        add_action('woocommerce_package_rates', [$this, 'run_quote_lock_check_from_rates'], 998, 2);
        //add_action('woocommerce_checkout_order_processed', [$this, 'clear_quote_lock_data']);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'clear_quote_lock_data'], 10, 1);
        add_action('woocommerce_cart_emptied', [$this, 'clear_quote_lock_data']);
        add_action('wp_footer', [$this, 'render_lost_lock_popup']);

        // --- NEW: Failsafes to clear the stuck quote if items are manually removed ---
        add_action('woocommerce_cart_item_removed', [$this, 'maybe_clear_lock_if_empty']);
    }

    public function run_quote_lock_check() {
        $is_initial_cart_lock = WC()->session->get('is_initial_cart_lock', false);
        if ($is_initial_cart_lock) {
            $this->is_quote_locked = true;
        }   
     
        if ($this->is_quote_locked) {
            $locked_quote = WC()->session->get('locked_quote_data', null);
            if (!empty($locked_quote)) {
                $this->apply_locked_pricing($locked_quote);
            }
            return;
        }
        
        $cart = WC()->cart;
        $session = WC()->session;

        if (!$cart || !$session || $cart->is_empty()) {
            $this->is_quote_locked = false;
            return;
        }
        $locked_quote = $session->get('locked_quote_data', null);
        if (empty($locked_quote)) {
            $this->is_quote_locked = false;
            return;
        }
        $original_quote = wc_get_order($locked_quote['quote_id']);
        if (!$original_quote) {
            $this->is_quote_locked = false;
            return;
        }

        $this->is_quote_locked = $this->are_cart_and_order_identical($cart, $original_quote);

        // --- NEW: Simply detect "Broken Lock" State ---
        // If it is NOT locked, but we HAVE quote data, and it WAS an active quote...
        // We ALWAYS set the flag to TRUE. The Frontend JS will handle the "Show Once" logic via Cookie.
        if ( ! $this->is_quote_locked && ! empty($locked_quote) && $original_quote->get_status() === 'active-quote' ) {
             WC()->session->set( 'starke_trigger_lost_lock_popup', true );
        } else {
             // If it IS locked (or not a quote), ensure the flag is FALSE.
             WC()->session->set( 'starke_trigger_lost_lock_popup', false );
        }
        // --- END NEW ---

        if ($this->is_quote_locked) {
            $this->apply_locked_pricing($locked_quote);
        }
    }

    public function run_quote_lock_check_from_rates($rates, $package) {
        $this->run_quote_lock_check();
        return $rates; // It's a filter, so we must return the rates.
    }

    public function is_quote_locked() {
        return $this->is_quote_locked;
    }

    /**
     * Forcefully enables the quote lock.
     * This is called when we programmatically load a quote into the cart,
     * ensuring the lock is active before any other cart actions can fire.
     */
    public function force_quote_lock() {
        $this->is_quote_locked = true;
    }

    public function clear_quote_lock_data($order) {
        if (WC()->session) {
            WC()->session->set('locked_quote_data', null);
        }
    }
    
    /**
     * Actively destroys the stuck quote session if the user manually removes the last item.
     */
    public function maybe_clear_lock_if_empty() {
        if ( WC()->cart && WC()->cart->is_empty() ) {
            // Safely clear the session directly without calling the order-based function
            if ( WC()->session ) {
                WC()->session->set('locked_quote_data', null);
            }
            $this->is_quote_locked = false;
        }
    }

    public function set_locked_quote_session($order, $is_fully_locked = false) {
        if (!$order || !WC()->session) {
            return;
        }

        // --- Data for APPLYING the lock (prices, shipping costs, etc.) ---
        $shipping_rates_snapshot = [];
        foreach ($order->get_items('shipping') as $item_id => $item) {
        $shipping_taxes = $item->get_taxes();
        if (isset($shipping_taxes['total']) && is_array($shipping_taxes['total'])) {
            $shipping_taxes['total'] = array_filter($shipping_taxes['total'], 'is_numeric');
        }
        $shipping_rates_snapshot[] = [
            'label' => $item->get_name(), // NEW: Store the stable label
            'cost' => $item->get_total(),
            'taxes' => $shipping_taxes, // Capture SANITIZED tax data for shipping
        ];
        }

        $items_snapshot = [];
        foreach ($order->get_items() as $item) {
            $full_meta_for_restore = [];
            foreach ($item->get_meta_data() as $meta) {
                if (strpos($meta->key, '_') === 0) continue;
                $full_meta_for_restore[$meta->key] = $meta->value;
            }

            $comparable_meta_for_key = [];
            foreach ($this->get_comparable_meta_keys() as $meta_key) {
                $value = $item->get_meta($meta_key, true);
                if ($value !== null && $value !== '') {
                    $comparable_meta_for_key[$meta_key] = (string)$value;
                }
            }
            ksort($comparable_meta_for_key);
            $unique_key = md5($item->get_product_id() . json_encode($comparable_meta_for_key));
            $item_taxes = $item->get_taxes();
            if (isset($item_taxes['total']) && is_array($item_taxes['total'])) {
                $item_taxes['total'] = array_filter($item_taxes['total'], 'is_numeric');
            }
            if (isset($item_taxes['subtotal']) && is_array($item_taxes['subtotal'])) {
                $item_taxes['subtotal'] = array_filter($item_taxes['subtotal'], 'is_numeric');
            }
            $items_snapshot[$unique_key] = [
                'total'     => $item->get_total(),
                'full_meta' => $full_meta_for_restore,
                'taxes'     => $item_taxes, // Capture SANITIZED tax data for items
            ];
        }

        // --- Snapshot for FULL LOCK data ---
        $fully_locked_snapshot = [];
        if ($is_fully_locked) {
            $fees_snapshot = [];
            foreach ( $order->get_fees() as $fee_item ) {
                $fees_snapshot[] = [
                    'name'      => $fee_item->get_name(),
                    'total'     => $fee_item->get_total(),
                    'taxable'   => $fee_item->get_tax_status() === 'taxable',
                    'tax_class' => $fee_item->get_tax_class(),
                ];
            }

            $historical_tax_totals = [];
            foreach ($order->get_items('tax') as $item_id => $tax_item) {
                $rate_code = WC_Tax::get_rate_code($tax_item->get_rate_id());
                if ($rate_code) {
                     $total_tax_for_rate = $tax_item->get_tax_total() + $tax_item->get_shipping_tax_total();
                     $historical_tax_totals[$rate_code] = (object) [
                        'rate_id'          => $tax_item->get_rate_id(),
                        'is_compound'      => $tax_item->is_compound(),
                        'label'            => $tax_item->get_label(),
                        'amount'           => $total_tax_for_rate,
                        'formatted_amount' => wc_price($total_tax_for_rate),
                    ];
                }
            }

            $fully_locked_snapshot = [
                'fees'           => $fees_snapshot,
                'total'          => $order->get_total(),
                'tax_totals'     => $historical_tax_totals,
                'payment_method' => $order->get_payment_method(),
                'payment_terms'  => $order->get_meta('_starke_payment_terms', true),
            ];

            wc_get_logger()->warning('$fully_locked_snapshot: ' . print_r($fully_locked_snapshot, true), ['source' => 'sample-tax-debug2']);

        }

        // --- Data for COMPARING the lock state ---
        $quote_data = [
            'quote_id'                => $order->get_id(),
            'items_snapshot'          => $items_snapshot,
            'shipping_rates_snapshot' => $shipping_rates_snapshot,
            'is_fully_locked'         => $is_fully_locked,
            'fully_locked_snapshot'   => $fully_locked_snapshot,
            'comparison_items'        => $this->get_simplified_items_snapshot($order),
            'comparison_primary_addr' => $this->get_simplified_address_snapshot($order, 'primary'),
            'comparison_samples_addr' => $this->get_simplified_address_snapshot($order, 'samples'),
            'comparison_shipping'     => $this->get_simplified_shipping_snapshot($order),
        ];

        wc_get_logger()->warning('$quote_data: ' . print_r($quote_data, true), ['source' => 'load_order_debug']);
        
        WC()->session->set('locked_quote_data', $quote_data);
    }

    private function apply_locked_pricing($locked_quote) {
        $is_fully_locked = !empty($locked_quote['is_fully_locked']);

        add_action('woocommerce_before_calculate_totals', function($cart) use ($locked_quote, $is_fully_locked) {
            $this->apply_locked_item_data($cart, $locked_quote, $is_fully_locked);
        }, 15, 1);

        if ($is_fully_locked) {
            add_filter('woocommerce_package_rates', function($rates, $package) use ($locked_quote, $is_fully_locked) {
                return $this->apply_locked_shipping_prices($rates, $package, $locked_quote, $is_fully_locked);
            }, 15, 2);

            add_action('woocommerce_cart_calculate_fees', [$this, 'apply_locked_fees'], 20, 1);
            add_filter('woocommerce_calculated_total', [$this, 'apply_locked_total'], 100, 2);
            add_action('woocommerce_calculate_totals', [$this, 'apply_locked_tax_totals_to_cart'], 99, 1);
        }
    }

   
    public function apply_locked_item_data($cart_obj, $locked_quote, $is_fully_locked) {
        if (empty($locked_quote) || empty($locked_quote['items_snapshot'])) {
            return;
        }
        $locked_items_map = $locked_quote['items_snapshot'];

        foreach ($cart_obj->get_cart() as $cart_item_key => &$cart_item) {
            $comparable_meta_for_key = [];
            foreach ($this->get_comparable_meta_keys() as $meta_key) {
                 if (isset($cart_item[$meta_key]) && $cart_item[$meta_key] !== null && $cart_item[$meta_key] !== '') {
                    $comparable_meta_for_key[$meta_key] = (string)$cart_item[$meta_key];
                 }
            }
            ksort($comparable_meta_for_key);
            $unique_key = md5($cart_item['product_id'] . json_encode($comparable_meta_for_key));

            if (isset($locked_items_map[$unique_key])) {
                $locked_item_data = $locked_items_map[$unique_key];


                // Since your items are always quantity 1, the total is the price.
                $locked_price = $locked_item_data['total'];
                $cart_item['data']->set_price($locked_price);
                
                // Manually clear the charge display keys before restoring.
                // This prevents "live" values from persisting.
                $cart_item['rabbet_setup_charge'] = null;
                $cart_item['relief_angle_setup_charge'] = null;
                $cart_item['quantity_discount'] = null;
                $cart_item['price_per_foot'] = null;
                
                if (isset($locked_item_data['full_meta'])) {
                    foreach ($locked_item_data['full_meta'] as $key => $value) {
                        $cart_item[$key] = $value;
                    }
                    $cart_obj->cart_contents[$cart_item_key] = $cart_item;
                }
            }
        }
        unset($cart_item);
    }

    public function apply_locked_shipping_prices($rates, $package, $locked_quote, $is_fully_locked) {
        if (empty($locked_quote) || empty($locked_quote['shipping_rates_snapshot'])) {
            return $rates;
        }
        $locked_rates_map = array_column($locked_quote['shipping_rates_snapshot'], null, 'label');

        foreach ($rates as $rate_id => $rate) {
            $current_label = $rate->get_label();
            if (function_exists('impersonation_is_active') && impersonation_is_active() && $rate->get_label() === 'LTL Shipping') {
                continue;
            }            
            if (isset($locked_rates_map[$current_label])) {
                $locked_rate_data = $locked_rates_map[$current_label];
                $rate->cost = $locked_rate_data['cost'];
                if ($is_fully_locked && isset($locked_rate_data['taxes'])) {
                    $taxes_to_apply = []; // Default to a safe empty array.
                    // Defensively check that the 'total' key exists and that its value is an array.
                    if ( isset($locked_rate_data['taxes']['total']) && is_array($locked_rate_data['taxes']['total']) ) {
                        // If the structure is correct, use that data.
                        $taxes_to_apply = $locked_rate_data['taxes']['total'];
                    }
                    // Assign the sanitized value to the rate's taxes property.
                    $rate->set_taxes($taxes_to_apply);
                } else if (!$is_fully_locked) {
                    // *** THE FIX IS HERE ***
                    // For NORMAL lock, recalculate taxes on the historical cost using CURRENT rates from TaxJar
                    if ( class_exists( 'Starke_TaxJar_API' ) ) {
                        $taxjar    = new Starke_TaxJar_API();
                        $tax_rates = $taxjar->get_formatted_rate_array( $package['destination'] );

                        wc_get_logger()->warning('TaxJar $tax_rates: ' . print_r($tax_rates, true), ['source' => 'tax_debug']);

                    } else {
                        // Fallback just in case the class isn't loaded
                        $tax_rates = WC_Tax::find_rates( $package['destination'] );

                        wc_get_logger()->warning('Fallback $tax_rates: ' . print_r($tax_rates, true), ['source' => 'tax_debug']);
                    }

                    if (!empty($tax_rates)) {
                        $rate->taxes = WC_Tax::calc_shipping_tax($rate->cost, $tax_rates);
                    } else {
                        $rate->taxes = [];
                    }
                }
            }
        }
        return $rates;
    }

    private function are_cart_and_order_identical(WC_Cart $cart, WC_Order $order) {
        $locked_quote = WC()->session->get('locked_quote_data');
        if (empty($locked_quote)) {
            return false; 
        }

        // --- 1. Items Comparison ---
        $current_cart_items   = $this->get_simplified_items_snapshot($cart);
        $original_quote_items = $locked_quote['comparison_items'] ?? [];
        
        $items_count_match = (count($current_cart_items) === count($original_quote_items));
        // Check if keys differ
        $items_diff = array_diff_key($original_quote_items, $current_cart_items);
        $items_match = $items_count_match && empty($items_diff);

        // If it is NOT a fully locked quote, ONLY items matter. 
        // Return immediately to bypass address and shipping checks.
        if ( empty( $locked_quote['is_fully_locked'] ) ) {
            return $items_match;
        }
        
        // --- 2. Primary Address Comparison ---
        $current_primary_address   = $this->get_simplified_address_snapshot($cart, 'primary');
        $original_primary_address  = $locked_quote['comparison_primary_addr'] ?? [];
        $primary_address_match     = ($current_primary_address === $original_primary_address);

        // --- 3. Samples Address Comparison ---
        // HELPER: Detect if samples exist in Cart or Quote
        $cart_has_samples = false;
        foreach ($current_cart_items as $item) {
            // Check for explicit 'sample' meta flag
            if (isset($item['meta']['sample']) && $item['meta']['sample']) {
                $cart_has_samples = true; 
                break;
            }
        }

        $quote_has_samples = false;
        foreach ($original_quote_items as $item) {
             if (isset($item['meta']['sample']) && $item['meta']['sample']) {
                $quote_has_samples = true; 
                break;
            }
        }

        $current_samples_address   = $this->get_simplified_address_snapshot($cart, 'samples');
        $original_samples_address  = $locked_quote['comparison_samples_addr'] ?? [];
        
        // FIX: Only compare samples address if samples are actually involved.
        // If neither has samples, the address is irrelevant (and might be stale in session), so ignore mismatch.
        if ( ! $cart_has_samples && ! $quote_has_samples ) {
             $samples_address_match = true;
        } else {
             $samples_address_match = ($current_samples_address === $original_samples_address);
        }

        // --- 4. Shipping Comparison ---
        $current_shipping   = $this->get_simplified_shipping_snapshot($cart);
        $original_shipping  = $locked_quote['comparison_shipping'] ?? [];
        $shipping_match     = ($current_shipping === $original_shipping);

        // --- Conditional check for FULL LOCK ---
        $payment_method_match = true;
        if (!empty($locked_quote['is_fully_locked'])) {
            $snapshot = $locked_quote['fully_locked_snapshot'] ?? [];
            $original_payment_method = $snapshot['payment_method'] ?? '';
            $current_payment_method = WC()->session->get('chosen_payment_method');
            $payment_method_match = ($original_payment_method === $current_payment_method);
        }
        $result = $items_match && $primary_address_match && $samples_address_match && $shipping_match && $payment_method_match;
        return $result;
    }

    private function get_comparable_meta_keys() {
         return [
            'linear_feet', 'thickness', 'width', 'length', 'first_rabbet',
            'first_rabbet_thickness', 'first_rabbet_width', 'reliefangle',
            'backrelief', 'species', 'finish', 'stain', 'sheen', 'custom_name', 
            'knifecost', 'markup', 'waste', 'similar_profiles', 'custom_description',
        ];
    }

    private function get_simplified_items_snapshot($source) {
        $simplified_items = [];
        $items = is_a($source, 'WC_Order') ? $source->get_items() : $source->get_cart();
        $comparable_meta_keys = $this->get_comparable_meta_keys();

        foreach ($items as $key => $item) {
            $product_id = is_a($source, 'WC_Order') ? $item->get_product_id() : $item['product_id'];
            $item_total = is_a($source, 'WC_Order') ? $item->get_total() : $item['line_total'];

            // REMOVED: 'quantity' is no longer needed in the snapshot.
            $item_snapshot = [
                'product_id'   => $product_id,
                'meta'         => [],
                'total'        => $item_total,
            ];
            foreach ($comparable_meta_keys as $meta_key) {
                $value = is_a($source, 'WC_Order') ? $item->get_meta($meta_key, true) : ($item[$meta_key] ?? null);
                if ($value !== null && $value !== '') {
                    $item_snapshot['meta'][$meta_key] = (string)$value;
                }
            }
            ksort($item_snapshot['meta']);
            
            $unique_key = md5($product_id . json_encode($item_snapshot['meta']));
            $simplified_items[$unique_key] = $item_snapshot;
        }
        
        ksort($simplified_items);
        return $simplified_items;
    }

    private function get_simplified_shipping_snapshot($source) {
        $simplified_shipping = [];
        if (is_a($source, 'WC_Order')) {
            foreach ($source->get_items('shipping') as $item) {
                $simplified_shipping[] = $item->get_name();
            }
        } else { // Assumes WC_Cart/session context
            // NEW: We must translate the chosen rate IDs into labels
            $chosen_method_ids = (array) WC()->session->get('chosen_shipping_methods', []);
            $packages = WC()->cart->get_shipping_packages();
            
            foreach ($packages as $package_index => $package) {
                if (isset($chosen_method_ids[$package_index])) {
                    $chosen_rate_id = $chosen_method_ids[$package_index];
                    
                    // Rates are stored in the session by package index
                    $available_rates_data = WC()->session->get('shipping_for_package_' . $package_index);
                    
                    if (!empty($available_rates_data['rates']) && isset($available_rates_data['rates'][$chosen_rate_id])) {
                        $rate_object = $available_rates_data['rates'][$chosen_rate_id];
                        $simplified_shipping[] = $rate_object->get_label();
                    }
                }
            }
        }
        sort($simplified_shipping);
        return $simplified_shipping;
    }

    /**
     * Creates a simplified snapshot of a shipping address for comparison.
     *
     * @param WC_Cart|WC_Order $source The source object (cart or order).
     * @param string $type The type of address ('primary' or 'samples').
     * @return array A simplified address array with city, state, and postcode.
     */
    private function get_simplified_address_snapshot($source, $type = 'primary') {
        $address = [
            'city'     => '',
            'state'    => '',
            'postcode' => '',
        ];
    
        if ($type === 'primary') {
            if (is_a($source, 'WC_Order')) {
                $address['city'] = $source->get_shipping_city();
                $address['state'] = $source->get_shipping_state();
                $address['postcode'] = $source->get_shipping_postcode();
            } else { // Assumes WC_Cart/session context
                $customer = WC()->customer;
                if ($customer) {
                    $address['city'] = $customer->get_shipping_city();
                    $address['state'] = $customer->get_shipping_state();
                    $address['postcode'] = $customer->get_shipping_postcode();
                }
            }
        } elseif ($type === 'samples') {
            if (is_a($source, 'WC_Order')) {
                $samples_data = $source->get_meta('_samples_full_shipping_address', true);
                if (is_array($samples_data)) {
                    $address['city'] = $samples_data['city'] ?? '';
                    $address['state'] = $samples_data['state'] ?? '';
                    $address['postcode'] = $samples_data['postcode'] ?? '';
                }
            } else { // Assumes WC_Cart/session context
                $samples_data = WC()->session->get('samples_full_shipping_address');
                 if (is_array($samples_data)) {
                    $address['city'] = $samples_data['city'] ?? '';
                    $address['state'] = $samples_data['state'] ?? '';
                    $address['postcode'] = $samples_data['postcode'] ?? '';
                }
            }
        }
        
        // Normalize for a consistent and case-insensitive comparison
        $address['city'] = strtolower(trim($address['city']));
        $address['state'] = strtolower(trim($address['state']));
        $address['postcode'] = strtolower(trim($address['postcode']));
    
        return $address;
    }

    /**
     * Callback function to apply locked fees to the cart.
     * It removes all other fees and adds the fees from the order snapshot.
     *
     * @param WC_Cart $cart The cart object.
     */
    public function apply_locked_fees($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        // FIXED: WooCommerce does not have a remove_fee() method on the cart object.
        // We must use the fees_api() to remove all existing fees safely.
        if ( method_exists( $cart, 'fees_api' ) ) {
            $cart->fees_api()->remove_all_fees();
        } else {
            // Fallback for older WooCommerce versions
            $cart->fees = array(); 
        }

        $locked_quote = WC()->session->get('locked_quote_data');
        $snapshot = $locked_quote['fully_locked_snapshot'] ?? [];
        if (!empty($snapshot['fees']) && is_array($snapshot['fees'])) {
            foreach ($snapshot['fees'] as $fee_data) {
                $cart->add_fee($fee_data['name'], $fee_data['total'], $fee_data['taxable'], $fee_data['tax_class']);
            }
        }
    }

    /**
     * Callback function to force the final cart total to match the original order.
     *
     * @param float   $total The calculated total.
     * @param WC_Cart $cart  The cart object.
     * @return float The locked total from the order snapshot.
     */
    public function apply_locked_total($total, $cart) {
        $locked_quote = WC()->session->get('locked_quote_data');
        $snapshot = $locked_quote['fully_locked_snapshot'] ?? [];
        if (isset($snapshot['total'])) {
            return (float) $snapshot['total'];
        }
        return $total;
    }

    /**
     * UPDATED: Callback function to force the cart's tax data to match the original order.
     * This now applies tax data to each item individually and then sets the overall totals.
     *
     * @param WC_Cart $cart The cart object.
     */
    public function apply_locked_tax_totals_to_cart($cart) {
        $locked_quote = WC()->session->get('locked_quote_data');
        $snapshot = $locked_quote['fully_locked_snapshot'] ?? [];

        // Check if we have the necessary locked data
        if (!isset($snapshot['tax_totals']) || empty($locked_quote['items_snapshot'])) {
            return;
        }

        $locked_items_map = $locked_quote['items_snapshot'];
        $new_cart_contents_tax = 0;

        // 1. Apply historical tax data to each individual cart item
        foreach ($cart->get_cart() as $cart_item_key => &$cart_item) {
            // Generate the same unique key used to identify the item
            $comparable_meta_for_key = [];
            foreach ($this->get_comparable_meta_keys() as $meta_key) {
                if (isset($cart_item[$meta_key]) && $cart_item[$meta_key] !== null && $cart_item[$meta_key] !== '') {
                    $comparable_meta_for_key[$meta_key] = (string)$cart_item[$meta_key];
                }
            }
            ksort($comparable_meta_for_key);
            $unique_key = md5($cart_item['product_id'] . json_encode($comparable_meta_for_key));

            // Find the corresponding item in our locked snapshot
            if (isset($locked_items_map[$unique_key]) && !empty($locked_items_map[$unique_key]['taxes'])) {
                $locked_taxes = $locked_items_map[$unique_key]['taxes'];

                // Set the line item's tax data, mirroring adjust_item_tax_for_samples()
                $cart->cart_contents[$cart_item_key]['line_tax_data'] = $locked_taxes;
                $line_tax_total = array_sum($locked_taxes['total'] ?? []);
                $cart->cart_contents[$cart_item_key]['line_tax'] = $line_tax_total;
                $cart->cart_contents[$cart_item_key]['line_subtotal_tax'] = array_sum($locked_taxes['subtotal'] ?? []);

                // Accumulate the total tax for all cart items
                $new_cart_contents_tax += $line_tax_total;
            }
        }
        unset($cart_item); // Unset the reference to avoid side effects

        // 2. Apply the overall locked tax totals to the main cart object
        
        // This sets the display for tax lines (e.g., "NJ Tax: $X.XX")
        $cart->tax_totals = $snapshot['tax_totals'];

        // This sets the sum of all taxes (items + shipping + fees)
        $total_tax_from_snapshot = array_sum(wp_list_pluck($snapshot['tax_totals'], 'amount'));
        $cart->set_total_tax($total_tax_from_snapshot);

        // This sets the sum of just the item taxes, which we calculated in the loop
        $cart->set_cart_contents_tax($new_cart_contents_tax);
    }

    /**
     * Renders the "Lost Lock" popup.
     * UPDATE: We render this ALWAYS on checkout (hidden by default).
     * This allows JS to trigger it dynamically when the Store API flag changes.
     */
    public function render_lost_lock_popup() {
        // 1. Only render on Checkout page
        if ( ! is_checkout() ) {
            return;
        }

        // REMOVED: The check for 'starke_trigger_lost_lock_popup'.
        // We output the HTML regardless, so it's ready for JS to use.
        
        ?>
        <div id="starke-lost-lock-popup-overlay" class="starke-popup-overlay" style="display: none;"></div>

        <div id="starke-lost-lock-popup" class="infoPopUp2" style="display: none;">
            
            <div id="infoHeader_div">
                <label id="infoPopUpTitle_label">Active Quote Updated</label>
                <button type="button" id="starke-lost-lock-close-x" style="visibility: hidden;">X</button>
            </div>

            <div id="infoContent_div">
                <div id="starke-popup-text">
                    The changes you made have updated the cart pricing.<br><br>
                    This quote is now using <strong>current live pricing</strong> instead of the original quote pricing.<br><br>
                    To restore the Active Quote pricing, you must revert these changes.
                </div>
                
                <div class="popup-actions" style="justify-content: center;">
                    <button type="button" id="starke-lost-lock-ok-btn">OK, I Understand</button>
                </div>
            </div>

        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const popup = document.getElementById('starke-lost-lock-popup');
            const overlay = document.getElementById('starke-lost-lock-popup-overlay');
            const okBtn = document.getElementById('starke-lost-lock-ok-btn');

            if (!okBtn) return; // Safety check

            // Close Function
            okBtn.addEventListener('click', function() {
                if (popup) popup.style.display = 'none';
                if (overlay) overlay.style.display = 'none';
                
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
            });
        });
        </script>
        <?php
    }

} // Class end

// Initialize the class as a singleton.
Quote_Lock_Controller::get_instance();