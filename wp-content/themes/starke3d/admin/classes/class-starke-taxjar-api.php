<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Starke_TaxJar_API {
    private $api_key;
    private $api_url;

    public function __construct() {
        // Was hardcoded directly in source (a live key committed in plaintext).
        // Now read from the environment, set TAXJAR_API_KEY on each environment
        // (local/staging/production) instead of editing this file.
        $this->api_key = getenv( 'TAXJAR_API_KEY' ) ?: '';
        $this->api_url = getenv( 'TAXJAR_API_URL' ) ?: 'https://api.taxjar.com/v2/';
    }

    /**
     * Helper to grab the dynamic Store Address from WooCommerce Settings
     */
    private static function get_store_origin_address() {
        $base_location = wc_get_base_location(); // Returns array with 'country' and 'state'
        return [
            'country' => ! empty( $base_location['country'] ) ? $base_location['country'] : 'US',
            'state'   => ! empty( $base_location['state'] ) ? $base_location['state'] : '',
            'zip'     => get_option( 'woocommerce_store_postcode', '' ),
            'city'    => get_option( 'woocommerce_store_city', '' ),
            'street'  => get_option( 'woocommerce_store_address', '' )
        ];
    }

    /**
     * =========================================================================
     * PHASE 1: FETCH RATES
     * =========================================================================
     * 
     * Fetches the smart combined tax rate from TaxJar (/v2/taxes) and formats it 
     * to perfectly match WooCommerce's native WC_Tax::find_rates() array structure.
     */
    public function get_formatted_rate_array( $destination ) {
        if ( empty( $destination['country'] ) || empty( $destination['postcode'] ) || empty( $destination['state'] ) || empty( $destination['city'] ) ) {
            return [];
        }

        // TaxJar primarily handles US/CA. Fallback to Woo for others if needed.
        if ( $destination['country'] !== 'US' ) {
            return WC_Tax::find_rates( $destination ); 
        }

        $zip   = strtolower( trim( $destination['postcode'] ) );
        // Enforce U.S. Zip Code length to prevent mid-typing API calls
        if ( strlen( $zip ) < 5 ) {
            return [];
        }

        $state = $destination['state'];
        $city  = $destination['city'];
        // --- FIX: Capture street to trigger Rooftop Precision ---
        $street = isset($destination['street']) ? $destination['street'] : (isset($destination['address']) ? $destination['address'] : '');

        $cache_key = 'taxjar_v2_rate_' . md5( $zip . $state . $city . $street );
        $cached_rate = get_transient( $cache_key );

        // NEW: Check if the API previously failed during this user's session
        $api_failed_in_session = isset( WC()->session ) && WC()->session->get( 'taxjar_api_tax_rate_request_failed' );

        // If we have a cached rate AND the API hasn't failed recently, use the cache.
        // Otherwise, ignore the cache and force a new API attempt.
        if ( false !== $cached_rate && ! $api_failed_in_session ) {
            return $cached_rate;
        }
        
        // SWITCH TO THE SMART TAXES ENDPOINT
        $url = $this->api_url . 'taxes';
        
        // Get dynamic store origin from WooCommerce settings
        $origin = self::get_store_origin_address();

        // Build the required POST payload for origin/destination calculation
        $body = [
            'from_country' => $origin['country'],
            'from_zip'     => $origin['zip'],
            'from_state'   => $origin['state'],
            'from_city'    => $origin['city'],
            'to_country'   => $destination['country'],
            'to_zip'       => $zip,
            'to_state'     => $state,
            'to_city'      => $city,
            'to_street'    => $street, // --- FIX: Pass street to TaxJar ---
            'amount'       => 100, // Dummy amount required to trigger the engine
            'shipping'     => 0
        ];

        // SWITCH TO wp_remote_post
        $response = wp_remote_post( $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json'
            ],
            'body'    => json_encode( $body ),
            'timeout' => 5
        ]);

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {            
            // Safe Fallback: If TaxJar is down, flag the session and use local Woo rates
            if ( isset( WC()->session ) ) {
                WC()->session->set( 'taxjar_api_tax_rate_request_failed', true );
            }
            return WC_Tax::find_rates( $destination );
        }

        // On success, make sure we clear any old failure flags just in case
        if ( isset( WC()->session ) ) {
            WC()->session->set( 'taxjar_api_tax_rate_request_failed', false );
        }

        $response_body = json_decode( wp_remote_retrieve_body( $response ), true );
        
        // Extract the rate percentage from the new response structure
        $smart_rate = $response_body['tax']['rate'] ?? 0;
        
        // Convert decimal (0.0825) to percentage (8.25) for WooCommerce math
        $percentage_rate = (float) $smart_rate * 100;

        if ( $percentage_rate <= 0 ) {
            return [];
        }

        // --- NEW: Hybrid Skeleton Logic ---
        // Ask WooCommerce for the local rate to extract the correct Database ID and Label
        $local_rates = WC_Tax::find_rates( $destination );
        
        $rate_id_to_use = 1; // Fallback dummy ID
        $label_to_use   = ( ! empty( $state ) ? $state . ' Tax' : 'Sales Tax' ); // Fallback label

        if ( ! empty( $local_rates ) ) {
            reset( $local_rates );
            $rate_id_to_use = key( $local_rates ); // Grab the actual local DB ID (e.g., 2 or 3)
            $label_to_use   = $local_rates[ $rate_id_to_use ]['label'] ?? $label_to_use; // Grab the local label
        }

        // Mock the exact array structure WC_Tax::calc_tax expects, using the real DB ID
        $wc_tax_array = [
            $rate_id_to_use => [ 
                'rate'     => $percentage_rate,
                'label'    => $label_to_use,
                'shipping' => 'yes',
                'compound' => 'no'
            ]
        ];

        // Cache for 1 hour to prevent hitting API limits during checkout changes
        set_transient( $cache_key, $wc_tax_array, HOUR_IN_SECONDS );

        return $wc_tax_array;
    }

    /**
     * =========================================================================
     * PHASE 2: PUSH & DELETE TRANSACTIONS (API Callers)
     * =========================================================================
     */
    public function push_transaction( $order_data ) {
        $url = $this->api_url . 'transactions/orders';
        $response = wp_remote_post( $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json'
            ],
            'body'    => json_encode( $order_data ),
            'timeout' => 15
        ]);

        if ( is_wp_error( $response ) || ! in_array( wp_remote_retrieve_response_code( $response ), [ 200, 201 ] ) ) {
            $error_code = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response );
            return false;
        }

        return true;
    }

    public function delete_transaction( $transaction_id ) {
        $url = $this->api_url . 'transactions/orders/' . urlencode($transaction_id);
        
        $response = wp_remote_request( $url, [
            'method'  => 'DELETE',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
            ],
            'timeout' => 15
        ]);

        return ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 );
    }

    /**
     * =========================================================================
     * AUTOMATED SYNC ENGINE (Hooked into WooCommerce)
     * =========================================================================
     */
    
    public static function init() {
        // THE FIX: We use the master status change hook which passes 4 variables, 
        // including the OLD status so we can catch manual admin overrides.
        //add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'sync_order_to_taxjar' ], 10, 4 ); // (100% Ready To Run) This is only turned off for now since Zac doesn't actually use the Transactions from TaxJar as part of their workflow yet
        add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'add_taxjar_fallback_order_note' ], 10, 1 );
    }

    // NEW: The function that adds the admin note if the API failed
    public static function add_taxjar_fallback_order_note( $order ) {
        if ( isset( WC()->session ) && WC()->session->get( 'taxjar_api_tax_rate_request_failed' ) ) {
            // The Store API hook passes the $order object directly, so no need to fetch it via ID
            if ( $order && is_a( $order, 'WC_Order' ) ) {
                $note = 'Notice: The TaxJar API failed or timed out during checkout. This order was calculated using the standard WooCommerce fallback tax rates.';
                $order->add_order_note( $note );
                
                // Unset the session variable so it doesn't bleed into future checkouts
                WC()->session->__unset( 'taxjar_api_tax_rate_request_failed' );
            }
        }
    }

    public static function sync_order_to_taxjar( $order_id, $old_status, $new_status, $order ) {
        // --- STRICT GUARDRAIL 1: Only run for our 3 target finalized statuses ---
        if ( ! in_array( $new_status, ['processing', 'completed', 'on-hold'] ) ) {
            return;
        }

        // --- STRICT GUARDRAIL 2: Duplicate Prevention ---
        if ( $order->get_meta( '_taxjar_synced', true ) === 'yes' ) {
            return;
        }

        // --- STRICT GUARDRAIL 3 & SPECIAL CASE: Balance Invoice Protection ---
        // For balance invoices, the original order already reported 100% of product tax.
        // If paid with a card (stripe), the 4% convenience fee is the ONLY new taxable revenue.
        if ( $order->get_meta( '_starke_is_balance_invoice', true ) === 'yes' ) {
            if ( $order->get_payment_method() === 'stripe' ) {
                $taxjar = new self();
                $sync_success = self::sync_balance_invoice_fee_to_taxjar( $order, $taxjar );
                if ( $sync_success ) {
                    $order->update_meta_data( '_taxjar_synced', 'yes' );
                    $order->save_meta_data();
                }
            }
            return; // Always return here so we don't run the standard accrual sync below
        }

        // --- STRICT GUARDRAIL 4: Quote Protection (THE FIX) ---
        // Because we now have the $old_status, if an admin manually drags a Quote 
        // into 'on-hold', we see that it came from a quote status and block it!
        $quote_statuses = ['active-quote', 'expired-quote', 'pending-quote', 'freight-quote', 'deleted-quote', 'ordered-quote', 'profiles-ready', 'profiles-needed'];
        
        if ( in_array( $old_status, $quote_statuses ) || in_array( $new_status, $quote_statuses ) ) {
            return;
        }

        // --- Execute Sync Math & Payload Mapping ---
        $taxjar = new self();
        
        $standard_items = [];
        $sample_items   = [];
        
        foreach ( $order->get_items('line_item') as $item ) {
            if ( function_exists('is_order_item_a_sample') && is_order_item_a_sample($item) ) {
                $sample_items[] = $item;
            } else {
                $standard_items[] = $item;
            }
        }

        $sample_shipping_rate_id = function_exists('get_samples_shipping_method') ? get_samples_shipping_method($order) : '';
        $standard_shipping_items = [];
        $sample_shipping_items   = [];
        
        $standard_pickup_method_id = ''; // NEW: Catch the dynamic pickup ID

        foreach ( $order->get_items('shipping') as $shipping_item ) {
            $method_id_in_order = $shipping_item->get_method_id() . ':' . $shipping_item->get_instance_id();
            
            if ( $sample_shipping_rate_id && $method_id_in_order === $sample_shipping_rate_id ) {
                $sample_shipping_items[] = $shipping_item;
            } else {
                $standard_shipping_items[] = $shipping_item;
                
                // NEW: If this is a pickup, save the exact method ID so we can look up the address
                if ( strpos( $method_id_in_order, 'pickup_location:' ) === 0 ) {
                    $standard_pickup_method_id = $method_id_in_order;
                }
            }
        }

        $fees = $order->get_items('fee');

        $standard_dest = [
            'country'  => $order->get_shipping_country() ?: 'US',
            'state'    => $order->get_shipping_state(),
            'postcode' => $order->get_shipping_postcode(),
            'city'     => $order->get_shipping_city(),
            'street'   => $order->get_shipping_address_1()
        ];

        // NEW: Dynamically override destination if a local pickup location was chosen
        if ( ! empty( $standard_pickup_method_id ) && function_exists( 'starke_get_pickup_location_address' ) ) {
            $standard_dest = starke_get_pickup_location_address( $standard_pickup_method_id, $standard_dest );
        }

        $sample_dest_raw = function_exists('get_samples_destination') ? get_samples_destination($order) : null;
        $sample_dest = [];
        if ( $sample_dest_raw ) {
            $sample_dest = [
                'country'  => $sample_dest_raw['country'] ?? 'US',
                'state'    => $sample_dest_raw['state'],
                'postcode' => $sample_dest_raw['postcode'],
                'city'     => $sample_dest_raw['city'] ?? '',
                'street'   => $sample_dest_raw['address'] ?? ''
            ];
        }

        $destinations_differ = false;
        if ( $sample_dest && ( $sample_dest['state'] !== $standard_dest['state'] || $sample_dest['postcode'] !== $standard_dest['postcode'] || $sample_dest['city'] !== $standard_dest['city'] ) ) {
            $destinations_differ = true;
        }

        $sync_success = true;

        if ( ! empty($sample_items) && ! empty($standard_items) && $destinations_differ ) {
            $std_payload = self::build_taxjar_transaction_payload( $order, $standard_items, $standard_shipping_items, $fees, $standard_dest, '-STD-TEST' );
            $smp_payload = self::build_taxjar_transaction_payload( $order, $sample_items, $sample_shipping_items, $fees, $sample_dest, '-SMP-TEST' );

            if ( $std_payload && ! $taxjar->push_transaction( $std_payload ) ) $sync_success = false;
            if ( $smp_payload && ! $taxjar->push_transaction( $smp_payload ) ) $sync_success = false;
        } else {
            $all_shipping   = array_merge($standard_shipping_items, $sample_shipping_items);
            $all_items      = array_merge($standard_items, $sample_items);
            $merged_payload = self::build_taxjar_transaction_payload( $order, $all_items, $all_shipping, $fees, $standard_dest, '-TEST' );
            
            if ( $merged_payload && ! $taxjar->push_transaction( $merged_payload ) ) $sync_success = false;
        }

        if ( $sync_success ) {
            $order->update_meta_data( '_taxjar_synced', 'yes' );
            $order->save_meta_data();
            
            // --- ADDED: Final Success Logger ---
            wc_get_logger()->debug( "TaxJar Sync Engine FULLY COMPLETED for Order ID: {$order_id}. Meta flag '_taxjar_synced' applied.", ['source' => 'taxjar_sync_debug'] );
        } else {
            // --- ADDED: Final Error Logger ---
            wc_get_logger()->error( "TaxJar Sync Engine FAILED for Order ID: {$order_id}. Check previous payload logs for API rejection details.", ['source' => 'taxjar_sync_debug'] );
        }
    }

    private static function build_taxjar_transaction_payload( $order, $line_items, $shipping_items, $fee_items, $dest, $suffix ) {
        // Get dynamic store origin from WooCommerce settings
        $origin = self::get_store_origin_address();

        // --- NEW: If no items, we are building a skeleton payload for the balance fee special function ---
        if ( empty($line_items) ) {
            return [
                'transaction_id'   => ($order->get_meta('_starke_order_number', true) ?: $order->get_order_number()) . $suffix,
                'transaction_date' => gmdate( 'Y/m/d', $order->get_date_created() ? $order->get_date_created()->getOffsetTimestamp() : time() ),
                'from_country'     => $origin['country'],
                'from_zip'         => $origin['zip'],
                'from_state'       => $origin['state'],
                'from_city'        => $origin['city'],
                'to_country'       => $dest['country'] ?: 'US',
                'to_zip'           => $dest['postcode'],
                'to_state'         => $dest['state'],
                'to_city'          => $dest['city'],
                'to_street'        => $dest['street'],
                'amount'           => 0, 
                'shipping'         => 0,
                'sales_tax'        => 0, 
                'line_items'       => [] 
            ];
        }

        $starke_num = $order->get_meta('_starke_order_number', true) ?: $order->get_order_number();
        $transaction_id = $starke_num . $suffix;
        
        $transaction_date = gmdate( 'Y/m/d', $order->get_date_created() ? $order->get_date_created()->getOffsetTimestamp() : time() );

        $payload = [
            'transaction_id'   => $transaction_id,
            'transaction_date' => $transaction_date,
            'from_country'     => $origin['country'],
            'from_zip'         => $origin['zip'],
            'from_state'       => $origin['state'],
            'from_city'        => $origin['city'],
            'to_country'       => $dest['country'] ?: 'US',
            'to_zip'           => $dest['postcode'],
            'to_state'         => $dest['state'],
            'to_city'          => $dest['city'],
            'to_street'        => $dest['street'],
        ];

        $total_amount = 0;
        $total_shipping = 0;
        $total_sales_tax = 0;
        $formatted_line_items = [];

        // Map Line Items
        foreach ( $line_items as $item ) {
            $item_total = (float) $item->get_total();
            $item_tax   = (float) $item->get_total_tax(); // This already includes your custom 4% fee tax
            
            $formatted_line_items[] = [
                'id'                 => $item->get_id(),
                'quantity'           => 1, // Hardcoded for custom configurator items
                'product_identifier' => $item->get_product_id() ? (string)$item->get_product_id() : 'item',
                'description'        => $item->get_name(),
                'unit_price'         => round( $item_total, 2 ), // Simplified math
                'sales_tax'          => $item_tax
            ];

            $total_amount += $item_total;
            $total_sales_tax += $item_tax;
        }

        // Map Shipping
        foreach ( $shipping_items as $ship ) {
            $ship_total = (float) $ship->get_total();
            $ship_tax   = (float) $ship->get_total_tax(); // This already includes your custom 4% fee tax
            
            $total_shipping += $ship_total;
            $total_amount += $ship_total;
            $total_sales_tax += $ship_tax;
        }

        // Map Fees Proportionally
        $total_fee_on_order = 0;
        foreach ( $fee_items as $fee ) {
            $total_fee_on_order += (float) $fee->get_total();
        }

        if ( $total_fee_on_order > 0 ) {
            $payload_fee = 0;
            $payload_base = $total_amount; // FIX: $total_amount already includes shipping
            
            // If this is a split payload, allocate the fee proportionally based on the items/shipping inside THIS payload
            if ( strpos( $suffix, '-STD' ) !== false || strpos( $suffix, '-SMP' ) !== false ) {
                $order_base = (float) $order->get_subtotal() + (float) $order->get_shipping_total();
                if ( $order_base > 0 ) {
                    $payload_fee = round( $total_fee_on_order * ( $payload_base / $order_base ), 2 );
                }
            } else {
                // If not split, take the whole fee
                $payload_fee = round( $total_fee_on_order, 2 );
            }

            if ( $payload_fee > 0 ) {
                // Push fees as separate line items, but with ZERO tax 
                // because your custom code already baked the fee's tax into the product/shipping line items!
                $formatted_line_items[] = [
                    'id'                 => 'fee_portion',
                    'quantity'           => 1,
                    'product_identifier' => 'FEE',
                    'description'        => 'Card Convenience Fee',
                    'unit_price'         => $payload_fee,
                    'sales_tax'          => 0 // CRITICAL FIX: Tax is 0 here
                ];

                $total_amount += $payload_fee;
            }
        }

        $payload['amount']     = round( $total_amount, 2 ); // FIX: Removed + $total_shipping
        $payload['shipping']   = round( $total_shipping, 2 );
        $payload['sales_tax']  = round( $total_sales_tax, 2 );
        $payload['line_items'] = $formatted_line_items;

        return $payload;
    }

    /**
     * SPECIAL HELPER: Syncs ONLY the taxable credit card fee for balance invoices.
     */
    private static function sync_balance_invoice_fee_to_taxjar( $order, $taxjar ) {
        // 1. Find the parent order to get its address data
        $parent_order_id = $order->get_parent_id();
        $parent_order = $parent_order_id ? wc_get_order($parent_order_id) : null;
        if (!$parent_order) return false;

        $fees = $order->get_items('fee');
        if (empty($fees)) return true;

        $fee_item = null;
        $convenience_fee_total = 0;
        foreach ($fees as $fee) {
            if (strpos(strtolower($fee->get_name()), 'convenience') !== false) {
                $fee_item = $fee;
                $convenience_fee_total = (float)$fee->get_total();
                break;
            }
        }

        if ($convenience_fee_total <= 0 || !$fee_item) return true;

        // 2. Identify destinations from parent order
        $sample_dest_raw = function_exists('get_samples_destination') ? get_samples_destination($parent_order) : null;
        $standard_dest = [
            'country'  => $parent_order->get_shipping_country() ?: 'US',
            'state'    => $parent_order->get_shipping_state(),
            'postcode' => $parent_order->get_shipping_postcode(),
            'city'     => $parent_order->get_shipping_city(),
            'street'   => $parent_order->get_shipping_address_1()
        ];

        $sample_dest = [];
        if ( $sample_dest_raw ) {
            $sample_dest = [
                'country'  => $sample_dest_raw['country'] ?? 'US',
                'state'    => $sample_dest_raw['state'],
                'postcode' => $sample_dest_raw['postcode'],
                'city'     => $sample_dest_raw['city'] ?? '',
                'street'   => $sample_dest_raw['address'] ?? ''
            ];
        }

        $destinations_differ = false;
        if ( $sample_dest && ( $sample_dest['state'] !== $standard_dest['state'] || $sample_dest['postcode'] !== $standard_dest['postcode'] || $sample_dest['city'] !== $standard_dest['city'] ) ) {
            $destinations_differ = true;
        }

        $sync_success = true;

        if ($destinations_differ) {
            // SPLIT ROUTE: We need two payloads, appending -STD and -SMP
            $std_payload = self::build_taxjar_transaction_payload( $order, [], [], [], $standard_dest, '-STD-TEST' );
            $smp_payload = self::build_taxjar_transaction_payload( $order, [], [], [], $sample_dest, '-SMP-TEST' );

            // Allocate the base fee proportionately (TaxJar requires the unit_price of the fee per state)
            $order_base = (float)$parent_order->get_subtotal() + (float)$parent_order->get_shipping_total();
            $pa_items_base = 0;
            
            foreach ($parent_order->get_items('line_item') as $p_item) {
                if (!(function_exists('is_order_item_a_sample') && is_order_item_a_sample($p_item))) {
                    $pa_items_base += (float)$p_item->get_total();
                }
            }
            foreach ($parent_order->get_items('shipping') as $p_ship) {
                $p_method_id = $p_ship->get_method_id() . ':' . $p_ship->get_instance_id();
                if ($p_method_id !== (function_exists('get_samples_shipping_method') ? get_samples_shipping_method($parent_order) : '')) {
                     $pa_items_base += (float)$p_ship->get_total();
                }
            }

            $pa_portion_fee_amount = ($order_base > 0) ? round($convenience_fee_total * ($pa_items_base / $order_base), 2) : 0;
            $nj_portion_fee_amount = $convenience_fee_total - $pa_portion_fee_amount;

            // --- THE FIX: Pull pre-calculated taxes directly from the fee item ---
            $fee_taxes = $fee_item->get_taxes()['total'] ?? [];
            
            // Get local rate IDs for the destinations (e.g., ID 3 for PA, ID 2 for NJ)
            $std_rates = WC_Tax::find_rates($standard_dest);
            $smp_rates = WC_Tax::find_rates($sample_dest);
            
            $std_rate_id = !empty($std_rates) ? key($std_rates) : null;
            $smp_rate_id = !empty($smp_rates) ? key($smp_rates) : null;

            // Extract the exact tax dollar amounts that your file already calculated
            $std_tax_line = isset($fee_taxes[$std_rate_id]) ? (float)$fee_taxes[$std_rate_id] : 0;
            $smp_tax_line = isset($fee_taxes[$smp_rate_id]) ? (float)$fee_taxes[$smp_rate_id] : 0;
            // ---------------------------------------------------------------------

            $fee_item_id = 'balance_fee_reverse_' . $order->get_id();
            
            $std_payload['line_items'] = [[
                'id'                 => $fee_item_id,
                'quantity'           => 1,
                'product_identifier' => 'FEE',
                'description'        => 'Card Convenience Fee (Standard Portion)',
                'unit_price'         => $pa_portion_fee_amount,
                'sales_tax'          => $std_tax_line 
            ]];
            $std_payload['amount']    = round($pa_portion_fee_amount, 2);
            $std_payload['sales_tax'] = $std_tax_line;

            $smp_payload['line_items'] = [[
                'id'                 => $fee_item_id,
                'quantity'           => 1,
                'product_identifier' => 'FEE',
                'description'        => 'Card Convenience Fee (Samples Portion)',
                'unit_price'         => $nj_portion_fee_amount,
                'sales_tax'          => $smp_tax_line 
            ]];
            $smp_payload['amount']    = round($nj_portion_fee_amount, 2);
            $smp_payload['sales_tax'] = $smp_tax_line;

            if ( $std_payload && ! $taxjar->push_transaction( $std_payload ) ) $sync_success = false;
            if ( $smp_payload && ! $taxjar->push_transaction( $smp_payload ) ) $sync_success = false;

        } else {
            // MERGE ROUTE: Single destination
            $merged_payload = self::build_taxjar_transaction_payload( $order, [], [], [], $standard_dest, '-TEST' );
            
            $merged_payload['line_items'] = [[
                'id'                 => 'balance_fee_single_' . $order->get_id(),
                'quantity'           => 1,
                'product_identifier' => 'FEE',
                'description'        => 'Card Convenience Fee',
                'unit_price'         => $convenience_fee_total,
                'sales_tax'          => (float)$fee_item->get_total_tax()
            ]];
            
            $merged_payload['amount']    = round($convenience_fee_total, 2);
            $merged_payload['sales_tax'] = (float)$fee_item->get_total_tax();

            if ( $merged_payload && ! $taxjar->push_transaction( $merged_payload ) ) $sync_success = false;
        }

        return $sync_success;
    }
}

Starke_TaxJar_API::init();