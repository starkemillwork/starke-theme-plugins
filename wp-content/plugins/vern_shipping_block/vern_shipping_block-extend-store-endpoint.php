<?php
use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;
use Automattic\WooCommerce\StoreApi\StoreApi;
use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;

class VernShippingBlock_Blocks_Extend_Store_Endpoint {
	/**
	 * Stores Rest Extending instance.
	 *
	 * @var ExtendSchema
	 */
	private static $extend;

	/**
	 * Plugin Identifier, unique to each plugin.
	 *
	 * @var string
	 */
	const IDENTIFIER = 'vern_shipping_block';

	/**
	 * Bootstraps the class and hooks required data.
	 */
	public static function init() {
		self::$extend = StoreApi::container()->get( ExtendSchema::class );
		self::extend_store();
	}

	/**
	 * Registers the actual data into each endpoint.
	 */
	public static function extend_store() {
		if ( is_callable( [ self::$extend, 'register_endpoint_data' ] ) ) {
            self::$extend->register_endpoint_data(
                [
                    'endpoint'        => CartSchema::IDENTIFIER,
                    'namespace'       => self::IDENTIFIER,
                    'data_callback'   => [ self::class, 'extend_cart_data' ],
                    'schema_callback' => [ self::class, 'extend_cart_schema' ],
                    'schema_type'       => ARRAY_A,
                ]
            );

            self::$extend->register_endpoint_data(
                [
                    'endpoint'        => CartItemSchema::IDENTIFIER,
                    'namespace'       => self::IDENTIFIER,
                    'data_callback'   => [ self::class, 'extend_cart_item_data' ],
                    'schema_callback' => [ self::class, 'extend_cart_item_schema' ],
                    'schema_type'       => ARRAY_A,
                ]
            );

            self::$extend->register_endpoint_data(
				[
					'endpoint'        => CheckoutSchema::IDENTIFIER,
					'namespace'       => self::IDENTIFIER,
					'schema_callback' => [ self::class, 'extend_checkout_schema' ],
					'schema_type'     => ARRAY_A,
				]
			);
		}
	}

    // Cart data to register with the Store API. --- START
    /**
	 * 
	 *
	 * @return array
	 */
	public static function extend_cart_data() {
        if ( ! class_exists( 'WC_Session' ) || ! WC()->session ) {
			WC()->initialize_session(); // Manually initialize WooCommerce session
		}



        $user_id = get_current_user_id();
        $saved_shipping_addresses = $user_id ? get_user_meta( $user_id, 'saved_shipping_addresses', true ) : [];

		$samples_address = WC()->session->get('samples_full_shipping_address');
		if (empty($samples_address) || !is_array($samples_address)) {
            $samples_address = $user_id ? get_user_meta($user_id, 'samples_full_shipping_address', true) : [];
            if (!empty($samples_address)) {
                WC()->session->set('samples_full_shipping_address', $samples_address);
            }
		}

        $packages = WC()->shipping()->get_packages();
        $package_type_map = [];
        foreach ( $packages as $i => $package ) {
            // The first package might have an index of 0 but no explicit 'package_id'.
            $package_id = isset( $package['package_id'] ) ? $package['package_id'] : $i;
            if ( isset( $package['package_type'] ) ) {
                $package_type_map[ $package_id ] = $package['package_type'];
            }
        }

        $edit_mode_order_quote_number = WC()->session->get('edit_mode_order_quote_number');
        $is_order_quote_edit_mode = isset($edit_mode_order_quote_number);
        if ($is_order_quote_edit_mode && WC()->session->get( 'cart_is_active_quote' )) {
            $active_quote_starke_number = $edit_mode_order_quote_number;
        }
        if ($is_order_quote_edit_mode && WC()->session->get( 'cart_is_freight_quote' )) {
            $freight_quote_starke_number = $edit_mode_order_quote_number;
        }
        if ($is_order_quote_edit_mode && WC()->session->get( 'cart_is_pending_quote' )) {
            $pending_quote_starke_number = $edit_mode_order_quote_number;
        }
        if ($is_order_quote_edit_mode && WC()->session->get( 'cart_is_profiles_needed' )) {
            $profiles_needed_starke_number = $edit_mode_order_quote_number;
        }

        if (WC()->session->get('is_initial_cart_lock')) {
            WC()->session->set('is_initial_cart_lock', null);
        }

        $chosen_methods = WC()->session->get( 'chosen_shipping_methods', [] );
        $correct_primary_flat_rate = WC()->session->get( 'correct_primary_flat_rate');
        if (count($chosen_methods) > 1) {
            $primary_flat_rate = $chosen_methods[0];
            if (strpos( $primary_flat_rate, 'flat_rate:' ) !== false) {
                $correct_primary_flat_rate = $primary_flat_rate;
                WC()->session->set( 'correct_primary_flat_rate', $correct_primary_flat_rate );
            }
        }

        // 1. Calculate Natural Total (Required for Threshold Check)
        // Try getting from session first to avoid re-calc
        $natural_total = WC()->session->get( 'starke_natural_total' );
        if ( empty( $natural_total ) ) {
            $cart = WC()->cart;
            // Sum raw components to get the full value before any deferrals
            $natural_total = 
                $cart->get_cart_contents_total() + 
                $cart->get_shipping_total() + 
                $cart->get_fee_total() + 
                $cart->get_total_tax();
        }

        // --- NEW: Check if Cart is Samples Only ---
        $is_samples_only = true;
        if ( WC()->cart && ! WC()->cart->is_empty() ) {
            foreach ( WC()->cart->get_cart() as $cart_item ) {
                if ( ! function_exists('is_cart_item_a_sample') || ! is_cart_item_a_sample( $cart_item ) ) {
                    $is_samples_only = false;
                    break;
                }
            }
        } else {
            $is_samples_only = false;
        }

        // --- NEW: Retrieve Admin Assigned Term ---
        $starke_assigned_payment_term = 'no_terms'; // Default
        if ( $user_id ) {
            $starke_assigned_payment_term = get_user_meta( $user_id, '_starke_assigned_payment_term', true );
            if ( empty( $starke_assigned_payment_term ) ) {
                $starke_assigned_payment_term = 'no_terms';
            }
        }

        // 2. DETERMINE PAYMENT TERM (Updated Logic)
        
        $current_session_term = WC()->session->get( 'starke_payment_terms' );

        // --- Default to Admin Assigned Term if Session is Empty ---
        // If the user just logged in or hasn't selected anything yet,
        // we force the session to start with the Admin's assigned term.
        if ( empty( $current_session_term ) ) {
            $current_session_term = $starke_assigned_payment_term;
            WC()->session->set( 'starke_payment_terms', $current_session_term );
        }
        
        // --- NEW: Force 'no_terms' if cart is samples only ---
        if ( $is_samples_only ) {
            $current_session_term = 'no_terms';
            WC()->session->set( 'starke_payment_terms', 'no_terms' );
        } else {
            // Ensure the currently selected term in the session is still valid for this user.
            // The user can only select 'no_terms' OR the specific term assigned by the admin.
            // If the session has a term that is NOT 'no_terms' and DOES NOT MATCH the admin assignment,
            // it means the Admin changed permissions (e.g., swapped 50/50 to Net 30).
            // We must auto-update the session to the new Admin setting.
            if ( $current_session_term !== 'no_terms' && $current_session_term !== $starke_assigned_payment_term ) {
                $current_session_term = $starke_assigned_payment_term;
                WC()->session->set( 'starke_payment_terms', $starke_assigned_payment_term );
            }
        }

        

        // Force the session to use the offline gateway ('cheque') to prevent 
        // the Store API "Credit Card not available" error when loading a Net 30 quote.
        if ( 'net_30' === $current_session_term ) {
            $chosen_method = WC()->session->get( 'chosen_payment_method' );
            if ( 'cheque' !== $chosen_method ) {
                WC()->session->set( 'chosen_payment_method', 'cheque' );
            }
        } else {
            // For all standard quotes/terms, if no payment method has been selected yet,
            // default the session to credit card to guarantee instant convenience fee calculations.
            $chosen_method = WC()->session->get( 'chosen_payment_method' );

            wc_get_logger()->warning('$chosen_method1 - extend-store-endpoint.php: ' . var_export($chosen_method, true), ['source' => 'methods_debug29']);

            if ( empty( $chosen_method ) ) {
                WC()->session->set( 'chosen_payment_method', 'stripe_cc' );

                wc_get_logger()->warning('Ran - extend-store-endpoint.php: ' . var_export('Ran', true), ['source' => 'methods_debug29']);
            }
        }

        wc_get_logger()->warning('$chosen_payment_method - extend-store-endpoint.php: ' . var_export(WC()->session->get( 'chosen_payment_method' ), true), ['source' => 'methods_debug29']);

        // --- NEW: Generate Unique Session Key for "Per Session" Popup Logic ---
        $unique_session_key = WC()->session->get( 'starke_unique_session_key' );
        if ( ! $unique_session_key ) {
            // Generate a random unique ID for this specific login session
            $unique_session_key = uniqid( 'sess_' );
            WC()->session->set( 'starke_unique_session_key', $unique_session_key );
        }

        $trigger_popup_flag = WC()->session->get( 'starke_trigger_lost_lock_popup' );
        // --- ADD THIS BLOCK: Self-Cleaning Session Logic ---
        // If the popup flag is stuck on true, but we are no longer in an active quote, wipe it from the session.
        if ( $trigger_popup_flag && empty( $active_quote_starke_number ) ) {
    WC()->session->set( 'starke_trigger_lost_lock_popup', null );
    $trigger_popup_flag = false; // Using false is generally safer than null for boolean flags
}
        // ---------------------------------------------------

        $editing_id = WC()->session->get( 'editing_original_order_id' );

		return [
			'po_number_job_name'                 => WC()->session->get( 'po_number_job_name' ) ?? '',
            'jobsite_contact'                    => WC()->session->get( 'jobsite_contact' ) ?? '',
            'jobsite_contact_cell_number'        => WC()->session->get( 'jobsite_contact_cell_number' ) ?? '',
            'samples_address_po_number_job_name' => WC()->session->get( 'samples_address_po_number_job_name' ) ?? '',
            'samples_full_shipping_address'      => $samples_address,
            'saved_shipping_addresses'           => $saved_shipping_addresses ?? [],
            'usa_states'                         => WC()->countries->get_states( 'US' ) ?? [],
            'cc_emails'                          => WC()->session->get( 'cc_emails' ) ?? [],
            'package_type_map'                   => $package_type_map,
            'freight_quote_starke_number'        => $freight_quote_starke_number ?? null,
            'active_quote_starke_number'         => $active_quote_starke_number ?? null,
            'pending_quote_starke_number'        => $pending_quote_starke_number ?? null,
            'profiles_needed_starke_number'      => $profiles_needed_starke_number ?? null,
            'is_quote_locked'                    => class_exists( 'Quote_Lock_Controller' ) ? Quote_Lock_Controller::get_instance()->is_quote_locked() : false,
            'is_impersonation'                   => function_exists('impersonation_is_active') && impersonation_is_active(),
            'is_admin'                           => current_user_can('manage_woocommerce'),
            'ltl_freight_cost'                   => WC()->session->get( 'ltl_freight_cost' ) ?? null,
            'chosen_payment_method'              => WC()->session->get( 'chosen_payment_method' ) ?? '',
            'correct_primary_flat_rate'          => $correct_primary_flat_rate,
            'starke_natural_total' => round( $natural_total * 100 ),
            'starke_assigned_payment_term' => $starke_assigned_payment_term,
            'starke_payment_terms' => $current_session_term,
            'is_samples_only'      => $is_samples_only,
            'trigger_lost_lock_popup' => $trigger_popup_flag,
            'editing_quote_id'        => $editing_id,
            'unique_session_key'      => $unique_session_key,
		];
	}

	/**
	 * The schema callback for our new signal.
	 *
	 * @return array
	 */
	public static function extend_cart_schema() {
		return [
            'po_number_job_name' => [
                'description' => 'PO Number/Job Name',
                'type'        => ['string', 'null'],
                'context'     => [ 'view', 'edit' ],
                'readonly'    => true,
                'arg_options' => [
                    'validate_callback' => function( $value ) { return is_string( $value ); },
                ],
            ],
            'jobsite_contact' => [
                'description' => 'Jobsite Contact',
                'type'        => ['string', 'null'],
                'context'     => [ 'view', 'edit' ],
                'readonly'    => true,
                'arg_options' => [
                    'validate_callback' => function( $value ) { return is_string( $value ); },
                ],
            ],
            'jobsite_contact_cell_number' => [
                'description' => 'Jobsite Contact Cell Number',
                'type'        => ['string', 'null'],
                'context'     => [ 'view', 'edit' ],
                'readonly'    => true,
                'arg_options' => [
                    'validate_callback' => function( $value ) { return is_string( $value ); },
                ],
            ],
            'samples_address_po_number_job_name' => [
                'description' => 'Samples Address PO Number/Job Name',
                'type'        => ['string', 'null'],
                'context'     => [ 'view', 'edit' ],
                'readonly'    => true,
                'arg_options' => [
                    'validate_callback' => function( $value ) { return is_string( $value ); },
                ],
            ],
            'samples_full_shipping_address' => [
                'description' => 'Samples Full Shipping Address',
                'type'        => [ 'object', 'null' ],
                'context'     => [ 'view', 'edit' ],
                'readonly'    => true,
                'properties'  => [
                    'first_name' => [ 'type' => 'string' ],
                    'last_name'  => [ 'type' => 'string' ],
                    'company'    => [ 'type' => [ 'string', 'null' ] ],
                    'address_1'  => [ 'type' => 'string' ],
                    'address_2'  => [ 'type' => [ 'string', 'null' ] ],
                    'city'       => [ 'type' => 'string' ],
                    'state'      => [ 'type' => 'string' ],
                    'postcode'   => [ 'type' => 'string' ],
                    'country'    => [ 'type' => [ 'string', 'null' ] ],
                    'phone'      => [ 'type' => [ 'string', 'null' ] ],
                ],
                'required' => [
                    'first_name',
                    'last_name',
                    'address_1',
                    'city',
                    'state',
                    'postcode',
                ],
            ],
            'saved_shipping_addresses' => [
                'description' => 'Saved Shipping Addresses',
                'type'        => [ 'array', 'null' ],
                'context'     => [ 'view', 'edit' ],
                'readonly'    => true,
            ],
            'usa_states' => [
                'description' => 'States of the USA',
                'type'        => [ 'array', 'null' ],
                'context'     => [ 'view', 'edit' ],
                'readonly'    => true,
            ],
            'cc_emails' => [
                'description' => 'CC Emails',
                'type'        => [ 'array', 'null' ],
                'context'     => [ 'view', 'edit' ],
                'readonly'    => true,
            ],
            'package_type_map' => [
                'description' => 'A map of shipping package IDs to their custom types.',
                'type'        => [ 'object', 'null' ],
                'context'     => [ 'view', 'edit' ],
                'readonly'    => true,
            ],
            'ltl_freight_cost' => [
                'description' => 'LTL Freight Cost',
                'type'        => ['string', 'number', 'null'],
                'context'     => [ 'view', 'edit' ],
                'readonly'    => true, // Data comes from session, not direct client modification here
            ],
            'freight_quote_starke_number' => [
                'description' => 'The Starke quote number if the cart is loaded from a freight quote.',
                'type'        => ['string', 'null'],
                'context'     => ['view', 'edit'],
                'readonly'    => true,
            ],
            'active_quote_starke_number' => [
                'description' => 'The Starke quote number if the cart is loaded from an active quote.',
                'type'        => ['string', 'null'],
                'context'     => ['view', 'edit'],
                'readonly'    => true,
            ],
            'pending_quote_starke_number' => [
                'description' => 'The Starke quote number if the cart is loaded from a pending quote.',
                'type'        => ['string', 'null'],
                'context'     => ['view', 'edit'],
                'readonly'    => true,
            ],
            'profiles_needed_starke_number' => [
                'description' => "The Starke quote number if the cart is loaded from a 'profiles needed' order.",
                'type'        => ['string', 'null'],
                'context'     => ['view', 'edit'],
                'readonly'    => true,
            ],
            'is_quote_locked' => [
                'description' => 'Flag indicating if the current cart is locked to a specific quote price.',
                'type'        => 'boolean',
                'context'     => ['view', 'edit'],
                'readonly'    => true,
            ],
            'is_impersonation' => [
                'description' => 'Flag indicating if an admin is currently impersonating a customer.',
                'type'        => 'boolean',
                'context'     => ['view', 'edit'],
                'readonly'    => true,
            ],
            'is_admin' => [
                'description' => 'Flag indicating if the current user has admin capabilities.',
                'type'        => 'boolean',
                'context'     => ['view', 'edit'],
                'readonly'    => true,
            ],
            'chosen_payment_method' => [
                'description' => 'The payment method ID that should be pre-selected.',
                'type'        => ['string', 'null'],
                'context'     => ['view', 'edit'],
                'readonly'    => true,
            ],
            'correct_primary_flat_rate' => [
                'description' => 'The primary shipping method ID that should be selected.',
                'type'        => ['string', 'null'],
                'context'     => ['view', 'edit'],
                'readonly'    => true,
            ],
            // 1. The Admin-Assigned Term (Read Only)
            'starke_assigned_payment_term' => [
                'description' => 'The payment term assigned to the user by the admin.',
                'type'        => ['string', 'null'],
                'context'     => ['view', 'edit'],
                'readonly'    => true,
                'arg_options' => [
                    'validate_callback' => function( $value ) {
                        return in_array( $value, ['no_terms', '50_50', 'net_30'] );
                    },
                ],
            ],
            // 2. The Currently Selected Term (Read Only in this context)
            // Note: Even though the user changes this, the block reads the *current state* // from here. The actual update happens via your 'update_payment_terms' action.
            'starke_payment_terms' => [
                'description' => 'Selected Payment Terms',
                'type'        => ['string', 'null'],
                'context'     => [ 'view', 'edit' ],
                'readonly'    => true,
                'arg_options' => [
                    'validate_callback' => function( $value ) {
                        return in_array( $value, ['no_terms', '50_50', 'net_30'] );
                    },
                ],
            ],
            'is_samples_only' => [
                'description' => 'Flag indicating if the cart only contains samples.',
                'type'        => 'boolean',
                'context'     => ['view', 'edit'],
                'readonly'    => true,
            ],
            'trigger_lost_lock_popup' => [
                'description' => 'Flag to trigger the Lost Quote Lock popup.',
                'type'        => ['boolean', 'null'],
                'context'     => [ 'view', 'edit' ],
                'readonly'    => true,
            ],
            'editing_quote_id' => [
                'description' => 'The ID of the quote currently being edited.',
                'type'        => ['integer', 'string', 'null'],
                'context'     => [ 'view', 'edit' ],
                'readonly'    => true,
            ],
            'unique_session_key' => [
                'description' => 'Unique ID for the current user session.',
                'type'        => ['string', 'null'],
                'context'     => [ 'view', 'edit' ],
                'readonly'    => true,
            ],
		];
	}
    // Cart data to register with the Store API. --- END

    // Cart item data to register with the Store API. --- START
    /**
	 * The data callback for our new signal.
	 * It reads directly from the global variable that is set in my shipping.php file
	 *
	 * @return array
	 */
	public static function extend_cart_item_data($cart_item) {
		return [
			'sample' => $cart_item['sample'] ?? false,
            'price_per_foot' => $cart_item['price_per_foot'] ?? null,
            'official_profile_number' => $cart_item['official_profile_number'] ?? '',
		];
	}

	/**
	 * The schema callback for our new signal.
	 *
	 * @return array
	 */
	public static function extend_cart_item_schema() {
		return [
            'sample' => [
                'description' => 'Sample Product Flag',
                'type'        => ['boolean', 'null'],
                'context'     => [ 'view', 'edit' ],
                'readonly'    => true,
                'arg_options' => [
                    'validate_callback' => function( $value ) { return is_bool( $value ); },
                ],
            ],
            'price_per_foot' => [
                'description' => 'Price Per Foot',
                'type'        => ['string', 'null'],
                'context'     => [ 'view', 'edit' ],
                'readonly'    => true,
                'arg_options' => [
                    'validate_callback' => function( $value ) { return is_string( $value ); },
                ],
            ],
            'official_profile_number' => [
				'description' => 'Official Profile Number for custom profiles.',
				'type'        => ['string', 'null'],
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
                'arg_options' => [
                    'validate_callback' => function( $value ) { return is_string( $value ); },
                ],
			],
		];
	}
    // Cart item data to register with the Store API. --- END

    // Checkout data to register with the Store API. --- START
	/**
	 * Register Samples Shipping data schema into the Checkout endpoint.
	 *
	 * @return array Registered schema.
	 */
	public static function extend_checkout_schema() {
		return [
            'po_number_job_name' => [
                'description' => 'PO Number/Job Name',
                'type'        => ['string', 'null'],
                'context'     => [ 'view', 'edit' ],
                'readonly'    => true,
                'arg_options' => [
                    'validate_callback' => function( $value ) { return is_string( $value ); },
                ],
            ],
            'jobsite_contact' => [
                'description' => 'Jobsite Contact',
                'type'        => ['string', 'null'],
                'context'     => [ 'view', 'edit' ],
                'readonly'    => true,
                'arg_options' => [
                    'validate_callback' => function( $value ) { return is_string( $value ); },
                ],
            ],
            'jobsite_contact_cell_number' => [
                'description' => 'Jobsite Contact Cell Number',
                'type'        => ['string', 'null'],
                'context'     => [ 'view', 'edit' ],
                'readonly'    => true,
                'arg_options' => [
                    'validate_callback' => function( $value ) { return is_string( $value ); },
                ],
            ],
            'samples_address_po_number_job_name' => [
                'description' => 'Samples Address PO Number/Job Name',
                'type'        => ['string', 'null'],
                'context'     => [ 'view', 'edit' ],
                'readonly'    => true,
                'arg_options' => [
                    'validate_callback' => function( $value ) { return is_string( $value ); },
                ],
            ],
            'samples_shipping_method' => [
                'description' => 'Samples Shipping Method',
                'type'        => ['string', 'null'],
                'context'     => [ 'view', 'edit' ],
                'readonly'    => true,
                'arg_options' => [
                    'validate_callback' => function( $value ) { return is_string( $value ); },
                ],
            ],
            'samples_full_shipping_address' => [
                'description' => 'Samples Full Shipping Address',
                'type'        => [ 'object', 'null' ],
                'context'     => [ 'view', 'edit' ],
                'properties'  => [
                    'first_name' => [ 'type' => ['string', 'null'] ],
                    'last_name'  => [ 'type' => ['string', 'null'] ],
                    'company'    => [ 'type' => [ 'string', 'null' ] ],
                    'address_1'  => [ 'type' => ['string', 'null'] ],
                    'address_2'  => [ 'type' => [ 'string', 'null' ] ],
                    'city'       => [ 'type' => ['string', 'null'] ],
                    'state'      => [ 'type' => ['string', 'null'] ],
                    'postcode'   => [ 'type' => ['string', 'null'] ],
                    'country'    => [ 'type' => [ 'string', 'null' ] ],
                    'phone'      => [ 'type' => [ 'string', 'null' ] ],
                ],
                // MODIFIED: We are moving the required check into a custom validation callback.
                'arg_options' => [
                    'validate_callback' => function( $value, $request, $param ) {
                        // Only validate if there are both sample and standard products in the cart.
                        $cart = WC()->cart;
                        if ( ! $cart ) {
                            return true; // If cart doesn't exist, we can't validate.
                        }

                        $has_samples   = false;
                        $has_standard  = false;
                        foreach ( $cart->get_cart() as $cart_item ) {
                            if ( function_exists('is_cart_item_a_sample') && is_cart_item_a_sample($cart_item) ) {
                                $has_samples = true;
                            } else {
                                $has_standard = true;
                            }
                        }
                        
                        // If the conditions for the second address block are not met, don't validate.
                        if ( ! ($has_samples && $has_standard) ) {
                            return true;
                        }
                        
                        // If the conditions ARE met, now check if the required fields are empty.
                        $required_fields = ['first_name', 'last_name', 'address_1', 'city', 'state', 'postcode'];
                        foreach ($required_fields as $field) {
                            if ( empty($value[$field]) ) {
                                // This creates the exact error message you were seeing.
                                return new \WP_Error('rest_invalid_param', "$field is a required property.");
                            }
                        }

                        return true;
                    },
                ],
            ],
            'starke_payment_terms' => [
                'description' => 'Selected Payment Terms',
                'type'        => ['string', 'null'],
                'context'     => [ 'view', 'edit' ],
                'readonly'    => true,
                'arg_options' => [
                    'validate_callback' => function( $value ) {
                        return in_array( $value, ['no_terms', '50_50', 'net_30'] );
                    },
                ],
            ],
        ];
	}
    // Checkout data to register with the Store API. --- END
}