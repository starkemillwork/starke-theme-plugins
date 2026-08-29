<?php
use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\Blocks\StoreApi\Schemas\CartSchema;
use Automattic\WooCommerce\Blocks\StoreApi\Schemas\CheckoutSchema;

/**
 * Pickup date/time Extend WC Core.
 */
class VernShippingBlock_Extend_Woo_Core
{

	/**
	 * Plugin Identifier, unique to each plugin.
	 *
	 * @var string
	 */
	private $name = 'vern_shipping_block';
	//private $textDomain = $this->name;

	/**
     * The single instance of the class.
     * @var VernShippingBlock_Extend_Woo_Core|null
     */
    protected static $_instance = null; // Changed to protected static

    /**
     * Main VernShippingBlock_Extend_Woo_Core Instance.
     * Ensures only one instance is loaded or can be loaded.
     *
     * @return VernShippingBlock_Extend_Woo_Core - Main instance.
     */
    public static function get_instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor.
     * Made private to enforce the Singleton pattern.
     *
     * Any initialization code that *should* run when the plugin instance is first created
     * (e.g., registering actions/filters) would go here.
     */
    private function __construct() {
		$this->save_data_to_checkout_order_meta();
		$this->show_samples_shipping_address_in_order_confirmation();
		$this->add_saved_shipping_addresses_to_customer_meta();
    	$this->register_sample_request_endpoint();		
		$this->setup_generate_3d_pdf_and_send_email_for_order_quote();
		$this->setup_email_order_quote_to_customer_async();
		$this->setup_send_order_email_and_pdf();
		$this->save_cart_as_quote();
		$this->save_cc_emails_for_checkout_order();
		$this->maybe_add_cc_headers_for_order_quote();
		$this->fix_order_timestamp_on_checkout();
		$this->prevent_checkout_caching();

		add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
		//add_action('wp_enqueue_scripts', array($this, 'enable_redux_dev_tools'), 1000); Turn this on for debugging only
		add_action('send_sample_restock_email_async', [$this, 'send_sample_restock_email_async'], 10, 2);
		add_action('send_freight_quote_admin_notification_async', [$this, 'process_freight_quote_admin_notification_async'], 10, 1);

		// --- STARKE: OS-Level Atomic API Mutex Lock (Early Init) ---
		add_action( 'init', array( $this, 'starke_early_mutex_lock' ), -9999 );

		// NEW HOOK: Force the Shipping row in totals to ONLY show the price
		add_filter( 'woocommerce_order_shipping_to_display', array( $this, 'force_shipping_price_only_display' ), 99, 3 );

		// --- NEW HOOKS: Format phone numbers dynamically for Order Confirmation, Admin, and Emails ---
        add_filter( 'woocommerce_order_get_billing_phone', array( $this, 'format_order_phone' ), 10, 2 );
        add_filter( 'woocommerce_order_get_shipping_phone', array( $this, 'format_order_phone' ), 10, 2 );

		// --- NEW: Sort the cart alphanumerically so standard orders are built in the correct sequence natively ---
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'sort_cart_session_alphanumerically' ), 99, 1 );
    }

    /**
     * Prevent cloning of the instance.
     *
     * @return void
     */
    private function __clone() {
        // Prevent cloning to ensure only one instance
    }

    /**
     * Prevent unserialization of the instance.
     *
     * @return void
     */
    public function __wakeup() {
        // Prevent unserialization to ensure only one instance
    }

	/**
	 * Enable Redux DevTools for WordPress data stores.
	 * This is for debugging purposes only.
	 */
	public function enable_redux_dev_tools() {
		// Only enable this for logged-in administrators.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		
		wp_add_inline_script(
			'wp-blocks',
			'window.WP_DATA_DEV = true;',
			'before'
		);
	}

	public function enqueue_frontend_scripts() {
		// Only run this on the frontend, not in the admin area.
		if ( is_admin() ) {
			return;
		}

		// --- NEW: Enqueue Google Places API for Address Autocomplete ---
		if ( is_checkout() || is_account_page() ) {
			wp_enqueue_script(
				'google-places-autocomplete',
				'https://maps.googleapis.com/maps/api/js?key=AIzaSyB6T5AUQ9bnPIoy1aN8sPAQwjpiUKVfznw&libraries=places&loading=async',
				[],
				null,
				true
			);
		}

		// Combine all the data you need into a single array.
		$data_to_pass = [
			'isCart'         => is_cart(),
			'isCheckout'     => is_checkout(),
			'saveQuoteNonce' => wp_create_nonce('wp_rest'),
		];

		// Use wp_localize_script to make this data available to JavaScript.
		// It will be available under a global object named 'starkeData'.
		wp_localize_script(
			'vern_shipping_block-cart-items-features-block-frontend', // The handle of YOUR block's script.
			'starkeData',
			$data_to_pass
		);
	}

	// Stores/Updates the full Samples/Second Shipping Address to the session (an extensionCartUpate function)
	public function update_samples_full_shipping_address( $data ) {
		if ( ! class_exists( 'WC_Session' ) || ! WC()->session ) {
			WC()->initialize_session(); // Manually initialize WooCommerce session
		}

		if ( ! WC()->session ) {
			return;
		}

		if ( isset( $data['fields'] ) && is_array( $data['fields'] ) ) {
			if ($data['fields']['country'] !== 'US') {
				$data['fields']['country'] = 'US';
			}
			// Save the entire address object into a single session key
			WC()->session->set( 'samples_full_shipping_address', $data['fields'] );
		}
	}

	/**
	 * Saves the data to the order's metadata.
	 *
	 * @return void
	 */
	private function save_data_to_checkout_order_meta()
	{
		add_action(
			'woocommerce_store_api_checkout_update_order_from_request',
			function (\WC_Order $order, \WP_REST_Request $request) {
				
				// ---> Only block the final 'POST' request <---
				// This allows the checkout page to load and calculate totals via GET requests,
				// but stops the user dead in their tracks if they try to actually place the order.
				if ( $request->get_method() === 'POST' ) {
					// STARKE SECURITY: Strictly Block Guests from Placing Orders
					if ( ! is_user_logged_in() ) {
						throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
							'woocommerce_rest_not_logged_in',
							__( 'You must be logged in to place an order.', 'vern_shipping_block' ),
							401
						);
					}
					
					// --- STRICT BLOCK: Prevent Limited Accounts from placing block-based orders ---
					if ( function_exists( 'starke_is_account_limited' ) && starke_is_account_limited() ) {
						throw new \Exception( 'Your account currently has limited access. Purchasing is disabled. Please contact support.' );
					}
				}
				
				$extensions_request_data = isset($request['extensions'][$this->name]) ? $request['extensions'][$this->name] : [];

				// Samples full shipping address - Try Request first, then Fallback to Session
				$samples_full_shipping_address = !empty($extensions_request_data['samples_full_shipping_address']) ? $extensions_request_data['samples_full_shipping_address'] : WC()->session->get('samples_full_shipping_address');
				
				if ($samples_full_shipping_address && is_array($samples_full_shipping_address) && isset($samples_full_shipping_address['country']) && $samples_full_shipping_address['country'] !== 'US') {
					$samples_full_shipping_address['country'] = 'US';
				}

				// --- START FIX: Check for Mixed Cart (Samples + Standard) ---
                $has_samples = false;
                $has_standard = false;

                if ( WC()->cart ) {
                    foreach ( WC()->cart->get_cart() as $cart_item ) {
                        if ( ( function_exists('is_cart_item_a_sample') && is_cart_item_a_sample($cart_item) ) || ( isset($cart_item['sample']) && $cart_item['sample'] ) ) {
                            $has_samples = true;
                        } else {
                            $has_standard = true;
                        }
                    }
                }

                // The Secondary "Samples Address" is ONLY valid if we have BOTH types (Mixed Cart).
				// If it is Samples Only ($has_samples = true, $has_standard = false), the customer uses the Primary address.
				$is_mixed_cart = $has_samples && $has_standard;
				// NEW: Flag for Samples Only
				$is_samples_only = $has_samples && ! $has_standard;

				// If NOT a mixed cart, force clear the secondary address data so it doesn't save to the order.
				if ( ! $is_mixed_cart ) {
					$samples_full_shipping_address = '';
					if ( WC()->session ) {
						WC()->session->set('samples_full_shipping_address', '');
					}
				}
				// --- END FIX ---

				$order->update_meta_data('_samples_full_shipping_address', $samples_full_shipping_address);

				// Job info data - Try Request first, then Fallback to Session
				$po_number_job_name = !empty($extensions_request_data['po_number_job_name']) ? $extensions_request_data['po_number_job_name'] : WC()->session->get('po_number_job_name');
				$order->update_meta_data('_po_number_job_name', $po_number_job_name);

				$jobsite_contact = !empty($extensions_request_data['jobsite_contact']) ? $extensions_request_data['jobsite_contact'] : WC()->session->get('jobsite_contact');
				
				// --- START FIX: Clear Jobsite Contact if Samples Only ---
				if ( $is_samples_only ) {
					$jobsite_contact = '';
					if ( WC()->session ) {
						WC()->session->set('jobsite_contact', '');
					}
				}
				// --- END FIX ---

				$order->update_meta_data('_jobsite_contact', $jobsite_contact);

				$jobsite_contact_cell_number = !empty($extensions_request_data['jobsite_contact_cell_number']) ? $extensions_request_data['jobsite_contact_cell_number'] : WC()->session->get('jobsite_contact_cell_number');
				
				// --- START FIX: Clear Jobsite Cell if Samples Only ---
				if ( $is_samples_only ) {
					$jobsite_contact_cell_number = '';
					if ( WC()->session ) {
						WC()->session->set('jobsite_contact_cell_number', '');
					}
				}
				// --- END FIX ---

				$order->update_meta_data('_jobsite_contact_cell_number', $jobsite_contact_cell_number);

				// Samples Job info data - Try Request first, then Fallback to Session
				$samples_address_po_number_job_name = !empty($extensions_request_data['samples_address_po_number_job_name']) ? $extensions_request_data['samples_address_po_number_job_name'] : WC()->session->get('samples_address_po_number_job_name');
				
				// --- START FIX: Clear samples PO/Job Name if not mixed cart ---
				if ( ! $is_mixed_cart ) {
					$samples_address_po_number_job_name = '';
                    if ( WC()->session ) {
                         WC()->session->set('samples_address_po_number_job_name', '');
                    }
				}
				// --- END FIX ---
				
				$order->update_meta_data('_samples_address_po_number_job_name', $samples_address_po_number_job_name);

				// Samples shipping method - Try Request first, then Fallback to Session
				$samples_shipping_method = !empty($extensions_request_data['samples_shipping_method']) ? $extensions_request_data['samples_shipping_method'] : WC()->session->get('samples_shipping_method');
				
				// --- START FIX: Clear samples shipping method if not mixed cart ---
				if ( ! $is_mixed_cart ) {
					$samples_shipping_method = '';
                    if ( WC()->session ) {
                         WC()->session->set('samples_shipping_method', '');
                    }
				}
				// --- END FIX ---
				
				$order->update_meta_data('_samples_shipping_method', $samples_shipping_method);

				// --- Secure server-side logic from session ---

				if ( WC()->session ) {
					$chosen_payment_method = $request['payment_method'] ?? WC()->session->get('chosen_payment_method');
					if ( $chosen_payment_method ) {
						$order->set_payment_method( $chosen_payment_method );
					}

					$original_order_id = WC()->session->get( 'editing_original_order_id' );
					if ( ! empty( $original_order_id ) ) {
						$order->update_meta_data( '_editing_original_order_id', absint( $original_order_id ) );
					}
				}

				// Payment Terms - Try Request first, then Fallback to Session
				$payment_terms = !empty($extensions_request_data['starke_payment_terms']) ? $extensions_request_data['starke_payment_terms'] : WC()->session->get('starke_payment_terms');
				if ( $payment_terms ) {
					$order->update_meta_data('_starke_payment_terms', $payment_terms);
				}

				// - NEW: Save the precise Pickup Location Address to Order Meta ---
				// We do this here because the session is perfectly intact during this exact request!
				if ( WC()->session ) {
					$chosen_shipping_methods = WC()->session->get('chosen_shipping_methods', []);
					
					// Package 0 is ALWAYS the Standard/Linear package based on your split function
					$primary_rate_id = $chosen_shipping_methods[0] ?? ''; 

					if ( strpos( $primary_rate_id, 'pickup_location:' ) === 0 && function_exists('starke_get_pickup_location_address') ) {
						// FIX: Added 'street' to the fallback destination array
						$standard_dest = [
							'country'  => $order->get_shipping_country() ?: 'US',
							'state'    => $order->get_shipping_state(),
							'postcode' => $order->get_shipping_postcode(),
							'city'     => $order->get_shipping_city(),
							'street'   => $order->get_shipping_address_1(), 
						];
						
						// Run your helper function to get the actual NJ/PA address array
						$pickup_address = starke_get_pickup_location_address( $primary_rate_id, $standard_dest );
						
						// Save it permanently to the order for your Tax function to use
						$order->update_meta_data( '_standard_pickup_address', $pickup_address );
					}
				}
			}, 10, 2
		);
	}

	/**
	 * Adds the samples shipping address to the order confirmation page,
	 * AND intelligently modifies the primary shipping heading/address based on 
     * mixed carts and pickup locations.
	 */
	private function show_samples_shipping_address_in_order_confirmation() {
		add_action( 'woocommerce_thankyou', function( int $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}

            // 1. Analyze the Order Contents (Is it a Mixed Cart?)
            $has_samples = false;
            $has_standard = false;

            foreach ( $order->get_items() as $item ) {
                $name_lower = strtolower( $item->get_name() );
                $is_charge = strpos( $name_lower, 'tooling charge' ) !== false || strpos( $name_lower, 'setup charge' ) !== false;
                
                if ( function_exists('is_order_item_a_sample') && is_order_item_a_sample($item) ) {
                    $has_samples = true;
                } elseif ( ! $is_charge && $item->is_type('line_item') ) {
                    $has_standard = true;
                }
            }
            
            $is_mixed_cart = $has_samples && $has_standard;

            // 2. Check for Pickup Location on the Standard Method
            $sample_shipping_rate_id = function_exists('get_samples_shipping_method') ? get_samples_shipping_method($order) : '';
            $is_pickup = false;
            $pickup_address_html = '';

            foreach ( $order->get_shipping_methods() as $method ) {
                $method_id_in_order = $method->get_method_id() . ':' . $method->get_instance_id();
                
                // Target the standard method (skip the samples method)
                if ( $method_id_in_order !== $sample_shipping_rate_id ) {
                    if ( strpos( $method->get_method_id(), 'pickup_location' ) !== false || strpos( $method_id_in_order, 'pickup_location' ) !== false ) {
                        $is_pickup = true;
                        
                        // FIX: Use the reliable metadata we saved during checkout!
                        $saved_pickup_address = $order->get_meta('_standard_pickup_address', true);
                        $addr_array = [];

                        if ( ! empty( $saved_pickup_address ) && is_array( $saved_pickup_address ) ) {
                            $addr_array = $saved_pickup_address;
                        } elseif ( function_exists('starke_get_pickup_location_address') ) {
                            // Fallback
                            $fallback_dest = [
                                'country'  => $order->get_shipping_country() ?: 'US',
                                'state'    => $order->get_shipping_state(),
                                'postcode' => $order->get_shipping_postcode(),
                                'city'     => $order->get_shipping_city(),
								'company'  => 'Starke Millwork Inc.',
                                'address_1'=> $order->get_shipping_address_1(),
                                'address_2'=> $order->get_shipping_address_2(),
                            ];
                            $addr_array = starke_get_pickup_location_address( $method_id_in_order, $fallback_dest );
                        }

                        if ( ! empty( $addr_array ) ) {
                            // FIX: Force map the array EXACTLY how WooCommerce expects it
                            $formatted_args = [
                                'first_name' => '', // Leave blank so it doesn't print the customer's name on the warehouse address
                                'last_name'  => '',
                                'company'    => 'Starke Millwork Inc.',
                                'address_1'  => $addr_array['street'] ?? $addr_array['address_1'] ?? '', // Map 'street' to 'address_1'
                                'address_2'  => $addr_array['address_2'] ?? '',
                                'city'       => $addr_array['city'] ?? '',
                                'state'      => $addr_array['state'] ?? '',
                                'postcode'   => $addr_array['postcode'] ?? '',
                                'country'    => $addr_array['country'] ?? 'US',
                            ];
                            $pickup_address_html = WC()->countries->get_formatted_address( $formatted_args );
                        }
                    }
                    break;
                }
            }

            // 3. Determine the New Primary Heading
            $primary_heading = '';
            if ( $is_pickup ) {
                $primary_heading = $is_mixed_cart ? __( 'Linear Feet Profiles Pickup Location', 'vern_shipping_block' ) : __( 'Pickup Location', 'vern_shipping_block' );
            } elseif ( $is_mixed_cart ) {
                $primary_heading = __( 'Linear Feet Profiles Shipping Address', 'vern_shipping_block' );
            }

            // 4. Generate Samples Address HTML (Only if mixed cart)
			$samples_address = $order->get_meta( '_samples_full_shipping_address' );
            $formatted_samples_address = '';
            $samples_phone = '';
            
			if ( $is_mixed_cart && ! empty( $samples_address ) && is_array( $samples_address ) ) {
				$formatted_samples_address = WC()->countries->get_formatted_address( $samples_address );
                $samples_phone = $samples_address['phone'] ?? '';
            }

            // 5. Output HTML & DOM Modification Script
            ?>
            <?php if ( $is_mixed_cart && ! empty( $formatted_samples_address ) && trim(str_replace($samples_address['country'] ?? '', '', $formatted_samples_address)) !== '' ) : ?>
                <div id="wc-order-confirmation-samples-address-block" style="display: none; width: 100%; padding: 0; max-width: var(--wp--style--global--wide-size);">
					<div class="wp-block-columns is-layout-flex wp-container-core-columns-is-layout-1 wp-block-columns-is-layout-flex">
						<div class="wp-block-column is-layout-flow wp-block-column-is-layout-flow">
							<div class="wp-block-woocommerce-order-confirmation-shipping-wrapper wc-block-order-confirmation-shipping-wrapper alignwide">
								<h2 class="wp-block-heading" style="font-size:24px"><?php esc_html_e( 'Samples Shipping Address', 'vern_shipping_block' ); ?></h2>
								<div class="wp-block-woocommerce-order-confirmation-shipping-address wc-block-order-confirmation-shipping-address alignwide">
									<address><?php echo $formatted_samples_address; ?></address>
									<?php if ( $samples_phone ) : ?>
										<p class="woocommerce-customer-details--phone"><?php echo esc_html( $samples_phone ); ?></p>
									<?php endif; ?>
								</div>
							</div>
						</div>
						<div class="wp-block-column is-layout-flow wp-block-column-is-layout-flow"></div>
					</div>
				</div>
            <?php endif; ?>

            <?php if ( $is_pickup && ! $is_mixed_cart && ! empty( $pickup_address_html ) ) : ?>
                <div id="starke-standalone-pickup-block" style="display: none; width: 100%; padding: 0; max-width: var(--wp--style--global--wide-size); margin-top: 2em;">
					<div class="wp-block-columns is-layout-flex wp-container-core-columns-is-layout-1 wp-block-columns-is-layout-flex">
						<div class="wp-block-column is-layout-flow wp-block-column-is-layout-flow">
                            <div class="wp-block-woocommerce-order-confirmation-billing-wrapper wc-block-order-confirmation-billing-wrapper alignwide starke-injected-pickup">
								<h2 class="wp-block-heading" style="font-size:24px; color: var(--wp--preset--color--primary, #6431F6); text-transform: uppercase;"><?php echo esc_html( $primary_heading ); ?></h2>
								<div class="wp-block-woocommerce-order-confirmation-billing-address wc-block-order-confirmation-billing-address alignwide" style="border: 1px solid hsla(0,0%,7%,.11); border-radius: 4px; padding: 16px;">
									<address><?php echo $pickup_address_html; ?></address>
								</div>
							</div>
						</div>
						<div class="wp-block-column is-layout-flow wp-block-column-is-layout-flow"></div>
					</div>
				</div>
            <?php endif; ?>

            <script type="text/javascript">
                document.addEventListener('DOMContentLoaded', function() {
                    const primaryHeadingText = <?php echo json_encode( $primary_heading ); ?>;
                    const isPickup = <?php echo json_encode( $is_pickup ); ?>;
                    const pickupAddressHtml = <?php echo json_encode( $pickup_address_html ); ?>;
                    const isAchPayment = <?php echo ( 'stripe_ach' === $order->get_payment_method() ) ? 'true' : 'false'; ?>;
                    
                    // --- NEW: Check for Net 30 and adjust logic ---
                    const isNet30 = <?php echo ( 'net_30' === $order->get_meta( '_starke_payment_terms', true ) ) ? 'true' : 'false'; ?>;
                    // Net 30 orders do NOT need verification text. Only standard ACH needs it.
                    const needsVerificationText = isAchPayment && !isNet30;

                    function customizeOrderConfirmationAddresses() {
                        const shippingWrappers = document.querySelectorAll('.wc-block-order-confirmation-shipping-wrapper:not(.starke-injected-pickup)');
                        
                        if (shippingWrappers.length > 0) {
                            // Target the main native address wrapper
                            const primaryWrapper = shippingWrappers[0];
                            
                            // A. Change Heading
                            if (primaryHeadingText) {
                                const heading = primaryWrapper.querySelector('h2, h3');
                                if (heading && !heading.classList.contains('starke-modified')) {
                                    heading.textContent = primaryHeadingText;
                                    heading.classList.add('starke-modified');
                                }
                            }

                            // B. Apply Pickup Location Override
                            if (isPickup && pickupAddressHtml) {
                                const addressTag = primaryWrapper.querySelector('address');
                                if (addressTag && !addressTag.classList.contains('starke-modified')) {
                                    addressTag.innerHTML = pickupAddressHtml;
                                    addressTag.classList.add('starke-modified');
                                    
                                    // Hide the customer phone number from the pickup block
                                    const phoneTag = primaryWrapper.querySelector('.woocommerce-customer-details--phone');
                                    if (phoneTag) phoneTag.style.display = 'none';
                                }
                            }

                            // C. Inject Samples Address Block (If Applicable)
                            const samplesBlock = document.getElementById('wc-order-confirmation-samples-address-block');
                            if (samplesBlock && !samplesBlock.classList.contains('starke-injected')) {
                                const mainContainer = primaryWrapper.closest('.wp-block-columns'); 
                                
                                if (mainContainer) {
                                    mainContainer.insertAdjacentElement('afterend', samplesBlock);
                                } else {
                                    primaryWrapper.insertAdjacentElement('afterend', samplesBlock);
                                }
                                
                                samplesBlock.style.display = 'block';
                                samplesBlock.classList.add('starke-injected');
                            }
                        } else if (isPickup && pickupAddressHtml) {
                            // D. Inject Standalone Pickup Block (WooCommerce natively hides shipping for local pickup)
                            const standalonePickupBlock = document.getElementById('starke-standalone-pickup-block');
                            if (standalonePickupBlock && !standalonePickupBlock.classList.contains('starke-injected')) {
                                const billingWrapper = document.querySelector('.wc-block-order-confirmation-billing-wrapper');
                                if (billingWrapper) {
                                    const mainContainer = billingWrapper.closest('.wp-block-columns');
                                    if (mainContainer) {
                                        mainContainer.insertAdjacentElement('afterend', standalonePickupBlock);
                                    } else {
                                        billingWrapper.insertAdjacentElement('afterend', standalonePickupBlock);
                                    }
                                    standalonePickupBlock.style.display = 'block';
                                    standalonePickupBlock.classList.add('starke-injected');
                                }
                            }
                        }

						// --- NEW: E. Move Payment Instructions (Check/BACS) to the Top ---
                        const summaryBlock = document.querySelector('.wc-block-order-confirmation-summary');
                        let paymentInstructions = document.querySelector('.wc-block-order-confirmation-additional-information, .wc-block-order-confirmation-payment-instructions, .wc-block-components-order-confirmation-payment-instructions');

                        // --- HANDLE VERIFICATION / PAYMENT TEXT VIA JAVASCRIPT (LOOP PROTECTED) ---
                        if (isNet30) {
                            // Net 30 trusted customers go straight to processing. Erase any lingering payment instructions!
                            if (paymentInstructions && !paymentInstructions.classList.contains('starke-ach-added')) {
                                paymentInstructions.innerHTML = '';
                                paymentInstructions.style.setProperty('display', 'none', 'important');
                                paymentInstructions.classList.add('starke-ach-added'); 
                            }
                        } else if (needsVerificationText) {
                            // Standard ACH needs verification text
                            const verifyText = 'We have received your order details and are preparing for production. Your payment is currently waiting to be verified. Once cleared, we will proceed with the next steps for your project.';
                            
                            if (paymentInstructions && !paymentInstructions.classList.contains('starke-ach-added')) {
                                paymentInstructions.innerHTML = '<p>' + verifyText + '</p>';
                                paymentInstructions.classList.add('starke-ach-added');
                            } else if (summaryBlock && !paymentInstructions) {
                                paymentInstructions = document.createElement('div');
                                paymentInstructions.className = 'wc-block-order-confirmation-payment-instructions starke-ach-added';
                                paymentInstructions.innerHTML = '<p>' + verifyText + '</p>';
                            }
                        }

                        if (summaryBlock && paymentInstructions) {
                            
                            // 1. Create a temporary clone to safely check for real content
                            let clone = paymentInstructions.cloneNode(true);
                            
                            // 2. Strip out stranded headings AND script/style tags that count as false text!
                            let junkElements = clone.querySelectorAll('h1, h2, h3, h4, h5, h6, script, style');
                            for (let i = 0; i < junkElements.length; i++) {
                                junkElements[i].remove();
                            }
                            
                            // 3. Check if there are ACTUAL instructions left
                            if (clone.textContent.trim().length > 0) {
                                
                                // Move it only once
                                if (!paymentInstructions.classList.contains('starke-moved')) {
                                    summaryBlock.insertAdjacentElement('afterend', paymentInstructions);
                                    paymentInstructions.classList.add('starke-moved');
                                }
                                
                                // Re-apply styles
                                paymentInstructions.style.setProperty('display', 'block', 'important');
                                paymentInstructions.style.marginTop = '24px';
                                paymentInstructions.style.marginBottom = '24px';
                                paymentInstructions.style.padding = '20px';
                                paymentInstructions.style.backgroundColor = '#f9f9f9';
                                paymentInstructions.style.border = '1px solid hsla(0,0%,7%,.11)';
                                paymentInstructions.style.borderLeft = '4px solid var(--wp--preset--color--primary, #6431F6)';
                                paymentInstructions.style.borderRadius = '4px';
                                
                            } else {
                                // It is completely empty. Forcefully hide it and strip all padding/borders!
                                paymentInstructions.style.setProperty('display', 'none', 'important');
                                paymentInstructions.style.padding = '0';
                                paymentInstructions.style.border = 'none';
                                paymentInstructions.style.margin = '0';
                            }
                        }
                    }

                    // Run immediately on DOM Load
                    customizeOrderConfirmationAddresses();

                    // Watch the DOM in case React re-renders the block late
                    const observer = new MutationObserver(function(mutations) {
                        customizeOrderConfirmationAddresses();
                    });
                    
                    const orderConfirmationBlock = document.querySelector('.wc-block-order-confirmation');
                    if (orderConfirmationBlock) {
                        observer.observe(orderConfirmationBlock, { childList: true, subtree: true });
                    } else {
                        observer.observe(document.body, { childList: true, subtree: true });
                    }
                });
            </script>
            <?php
		}, 30, 1 );
	}

	/**
	 * Save a unique shipping address in customer meta after an order is placed.
	 *
	 * This function checks the order's shipping address and compares it to the customer's
	 * previously saved shipping addresses. If the shipping address is unique (i.e. its 
	 * address_1, address_2, city, state, and postcode combination does not already exist),
	 * it is added to the array of saved shipping addresses stored in user meta.
	 *
	 * @param int $order_id The ID of the order.
	 */
	private function add_saved_shipping_addresses_to_customer_meta() {
		function save_unique_shipping_address( $order_id ) {
			if ( ! $order_id ) {
				return;
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}

			$customer_id = $order->get_customer_id();
			if ( ! $customer_id ) {
				return;
			}

			$saved_addresses = get_user_meta( $customer_id, 'saved_shipping_addresses', true );
			if ( ! is_array( $saved_addresses ) ) {
				$saved_addresses = [];
			}

			/**
			 * Save a given address array to saved shipping addresses, if it's unique.
			 */
			$maybe_save_address = function( $new_address ) use ( &$saved_addresses ) {
				if ( empty( $new_address['address_1'] ) || empty( $new_address['city'] ) || empty( $new_address['state'] ) || empty( $new_address['postcode'] ) ) {
					return;
				}

				$is_unique = true;
				foreach ( $saved_addresses as $key => $existing_address ) {
					if (
						strtolower( trim( $existing_address['address_1'] ) ) === strtolower( trim( $new_address['address_1'] ) ) &&
						strtolower( trim( $existing_address['address_2'] ) ) === strtolower( trim( $new_address['address_2'] ) ) &&
						strtolower( trim( $existing_address['city'] ) )      === strtolower( trim( $new_address['city'] ) ) &&
						strtolower( trim( $existing_address['state'] ) )     === strtolower( trim( $new_address['state'] ) ) &&
						strtolower( trim( $existing_address['postcode'] ) )  === strtolower( trim( $new_address['postcode'] ) )
					) {
						$saved_addresses[ $key ] = $new_address; // update existing
						$is_unique = false;
						break;
					}
				}

				if ( $is_unique ) {
					$saved_addresses[] = $new_address;
				}
			};

			// Save standard shipping address.
			$standard_address = [
				'address_1'   => $order->get_shipping_address_1(),
				'address_2'   => $order->get_shipping_address_2(),
				'city'        => $order->get_shipping_city(),
				'state'       => $order->get_shipping_state(),
				'postcode'    => $order->get_shipping_postcode(),
				'country'     => $order->get_shipping_country(),
				'first_name'  => $order->get_shipping_first_name(),
				'last_name'   => $order->get_shipping_last_name(),
				'company'     => $order->get_shipping_company(),
				'phone'       => $order->get_shipping_phone(),
			];
			$maybe_save_address( $standard_address );

			// Save samples shipping address if it exists. Also save it to user meta as well.
			$samples_address = $order->get_meta( '_samples_full_shipping_address' );
			if (!empty($samples_address) && is_array($samples_address)) {
				$mapped_samples_address = [
					'address_1'   => $samples_address['address_1'] ?? '',
					'address_2'   => $samples_address['address_2'] ?? '',
					'city'        => $samples_address['city'] ?? '',
					'state'       => $samples_address['state'] ?? '',
					'postcode'    => $samples_address['postcode'] ?? '',
					'country'     => $samples_address['country'] ?? '',
					'first_name'  => $samples_address['first_name'] ?? '',
					'last_name'   => $samples_address['last_name'] ?? '',
					'company'     => $samples_address['company'] ?? '',
					'phone'       => $samples_address['phone'] ?? '',
				];
				$maybe_save_address( $mapped_samples_address );
				update_user_meta($customer_id, 'samples_full_shipping_address', $mapped_samples_address);
			}

			update_user_meta( $customer_id, 'saved_shipping_addresses', $saved_addresses );
		}

		add_action( 'woocommerce_thankyou', 'save_unique_shipping_address', 15, 1 );
	}

	// Stores the Job Info (PO Number/Job Name, Jobsite Contact, Jobsite Contact Number) to the session (an extensionCartUpate function)
	public function update_job_info_in_session( $data ) {
		if ( ! class_exists( 'WC_Session' ) || ! WC()->session ) {
			WC()->initialize_session(); // Manually initialize WooCommerce session
		}
		if ( ! WC()->session ) {
			return;
		}

		$po_number_job_name = $data['po_number_job_name'];
		if ( isset( $po_number_job_name ) ) {
			WC()->session->set( 'po_number_job_name', $po_number_job_name );
		}
		$jobsite_contact = $data['jobsite_contact'];
		if ( isset( $jobsite_contact ) ) {
			WC()->session->set( 'jobsite_contact', $jobsite_contact );
		}
		$jobsite_contact_cell_number = $data['jobsite_contact_cell_number'];
		if ( isset( $jobsite_contact_cell_number ) ) {
			WC()->session->set( 'jobsite_contact_cell_number', $jobsite_contact_cell_number );
		}
		$samples_address_po_number_job_name = $data['samples_address_po_number_job_name'];
		if ( isset( $samples_address_po_number_job_name ) ) {
			WC()->session->set( 'samples_address_po_number_job_name', $samples_address_po_number_job_name );
		}
	}

	// Stores/Updates the Country field for the Shipping Address and Billing Address to the session (an extensionCartUpate function)
	public function set_shipping_and_billing_address_country( $data ) {
		if ( ! class_exists( 'WC_Session' ) || ! WC()->session ) {
			WC()->initialize_session(); // Manually initialize WooCommerce session
		}
		if ( ! WC()->session ) {
			return;
		}

		WC()->session->set( 'shipping_country', 'US' );
		//WC()->session->save_data(); // Force session data to be written
		
		if ( is_user_logged_in() ) {
			$customer = new WC_Customer( get_current_user_id() );
			$customer->set_shipping_country( 'US' );
			$customer->save();
		}		
	}


	// Saves the cart as a quote
	private function save_cart_as_quote() {
		add_action( 'rest_api_init', function() {
			register_rest_route( 'vern-shipping-block/v1', '/save-cart-as-quote', array(
				'methods'  => 'POST',
				'callback' => array($this, 'save_cart_as_quote_endpoint_callback'),
				'permission_callback' => function( \WP_REST_Request $request ) {
					// 1. First, check if the user is logged in.
					if ( ! is_user_logged_in() ) {
						return new \WP_Error(
							'rest_not_logged_in',
							'You must be logged in to save a quote.',
							[
								'status' => 401, // Unauthorized
								'button_text' => 'PLEASE LOG IN!'
							]
						);
					}

					// 2. If the user is logged in, now we manually verify the nonce.
					$nonce = $request->get_header( 'x_wp_nonce' );

					if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
						return new \WP_Error(
							'rest_invalid_nonce',
							'Nonce is invalid.',
							[
								'status' => 403, // Forbidden
								'button_text' => 'SESSION EXPIRED!'
							]
						);
					}

					// 3. If both checks pass, grant permission for the main callback to run.
					return true;
				},
			));
		});
	}

	/**
     * REST API endpoint callback to save the current cart as a new quote/order.
     * Includes logic to prevent creating a revision if the cart hasn't changed from the original order.
     *
     * @param WP_REST_Request $request The incoming request object.
     * @return WP_REST_Response|WP_Error Response object or error.
     */
	public function save_cart_as_quote_endpoint_callback(WP_REST_Request $request) {
		// This ensures WC()->cart is not null when the validation logic runs.
		if ( ! did_action( 'woocommerce_cart_loaded_from_session' ) ) {
			wc_load_cart();
		}
		// Ensure WooCommerce session is initialized
		if (!WC()->session) {
			WC()->initialize_session();
		}

		wc_get_logger()->warning('WC()->session->get(chosen_shipping_methods) 0: ' . print_r(WC()->session->get('chosen_shipping_methods'), true), ['source' => 'send_quote_debug1']);

		$order_id_saved = '';
		$email_class = 'WC_Email_Customer_Quote_Sending';
		$button_id = $request->get_param('id'); // Gets the ID of the button that was clicked.
		$chosen_shipping_methods = WC()->session->get('chosen_shipping_methods', []);

		// --- NEW: Identify what method the user actually selected ---
		$primary_chosen_method = $chosen_shipping_methods[0] ?? '';

		if ($button_id === 'send_quote' && (impersonation_is_active() || current_user_can('manage_woocommerce'))) {
			// Billing Email Validation
			$billing_email = sanitize_email($request->get_param('billing_email'));

			wc_get_logger()->warning('$billing_email: ' . print_r($billing_email, true), ['source' => 'send_quote_debug']);

			if (empty($billing_email)) {
				 // Re-enable the button
				return new WP_Error('billing_email_required', 'Billing email is required to send a quote.', ['status' => 400, 'button_text' => 'EMAIL REQUIRED!']);
			}
			// LTL Shipping Cost Validation
			$packages = WC()->cart->get_shipping_packages();
			$primary_package_index = !empty($packages) ? key($packages) : 0;

			wc_get_logger()->warning('$chosen_shipping_methods: ' . print_r($chosen_shipping_methods, true), ['source' => 'send_quote_debug']);

			if (isset($chosen_shipping_methods[$primary_package_index])) {
				$chosen_method_id = $chosen_shipping_methods[$primary_package_index];
				$available_rates = WC()->session->get('shipping_for_package_' . $primary_package_index)['rates'] ?? [];
				
				if (isset($available_rates[$chosen_method_id])) {
					$selected_rate = $available_rates[$chosen_method_id];
					if ($selected_rate->get_label() === 'LTL Shipping') {
						$ltl_cost = WC()->session->get('ltl_freight_cost');
						
						wc_get_logger()->warning('$ltl_cost: ' . print_r($ltl_cost, true), ['source' => 'send_quote_debug']);
						
						// This new condition checks if the value is not numeric (which catches null/empty)
						// OR if it's a negative number. It explicitly allows 0.
						if ( ! is_numeric( $ltl_cost ) || floatval( $ltl_cost ) < 0 ) {
							 // Re-enable button on the frontend
							return new WP_Error(
								'ltl_cost_required',
								'A cost is required for LTL Shipping.',
								['status' => 400, 'button_text' => 'LTL COST NEEDED!']
							);
						}
					}
				}
			}
			if (WC()->session->get('cart_is_profiles_needed')) {
				
				return new WP_Error('admin_attempting_profiles_needed_email', 'A "Profiles Needed" order does not get emailed to the customer.', ['status' => 403, 'button_text' => 'NOT ALLOWED!']);
			}
			$email_quote_to_customer = true;
		} elseif ($button_id === 'request_freight_quote' && (impersonation_is_active() || current_user_can('manage_woocommerce'))) {
			
			return new WP_Error('admin_requesting_freight', 'Admins are not allowed to request a freight quote.', ['status' => 403, 'button_text' => 'NOT ALLOWED!']);
		} else {
			$email_quote_to_customer = false;
		}

		// Force-load the cart if it's null
		if (!WC()->cart) {
			wc_load_cart();
		}
		$cart = WC()->cart;

		if (!$cart || $cart->is_empty()) {
			return new WP_Error('empty_cart', 'Cart is empty or not initialized.', ['status' => 400, 'button_text' => 'CART IS EMPTY!']);
		}

		// --- NEW CODE START: Normalize Official Profile Numbers ---
		// This ensures that if multiple items share the same 'custom_name', 
		// they effectively share the 'official_profile_number' of the first one entered.
		$cart_contents = $cart->get_cart_contents();
		$custom_profile_map = [];
		$has_updates = false;

		// Pass 1: Map 'custom_name' to the first found 'official_profile_number'
		foreach ($cart_contents as $key => $item) {
			if ( !empty($item['custom_name']) && !empty($item['official_profile_number']) ) {
				// Only set if not already set (this prioritizes the first one found)
				if ( !isset($custom_profile_map[$item['custom_name']]) ) {
					$custom_profile_map[$item['custom_name']] = $item['official_profile_number'];
				}
			}
		}

		// Pass 2: Apply the map to all items with that custom_name
		if ( !empty($custom_profile_map) ) {
			foreach ($cart_contents as $key => &$item) {
				if ( !empty($item['custom_name']) && isset($custom_profile_map[$item['custom_name']]) ) {
					// If number is missing or different, update it
					if ( !isset($item['official_profile_number']) || $item['official_profile_number'] !== $custom_profile_map[$item['custom_name']] ) {
						$item['official_profile_number'] = $custom_profile_map[$item['custom_name']];
						$has_updates = true;
					}
				}
			}
			unset($item); // Break reference

			if ($has_updates) {
				$cart->set_cart_contents($cart_contents);
				// $cart->set_session(); // Uncomment if you want to persist this normalization to the user's session immediately
			}
		}
		
		// Get logged-in user
		$user_id = get_current_user_id();

		wc_get_logger()->warning('WC()->session->get(chosen_shipping_methods) 1: ' . print_r(WC()->session->get('chosen_shipping_methods'), true), ['source' => 'send_quote_debug1']);

        // --- Check if in edit mode and if cart has changed ---
        $original_order_id = WC()->session->get('editing_original_order_id') ?? 0;
		$cart->calculate_totals(); // Ensure totals are calculated before saving
		WC()->session->set('chosen_shipping_methods', $chosen_shipping_methods);
		wc_get_logger()->warning('WC()->session->get(chosen_shipping_methods) 2: ' . print_r(WC()->session->get('chosen_shipping_methods'), true), ['source' => 'send_quote_debug1']);

		$response_message = '';
		// Check if we are editing an existing quote/order
        if ( $original_order_id > 0 ) {
            $original_order = wc_get_order( $original_order_id );

            // --- 1. DETERMINE IF WE ALLOW SAVING AN IDENTICAL CART ---
			$allow_identical_save = false;

			// EXCEPTION: Always allow "Ordered Quotes" and "Profiles Needed" to proceed,
            // even if the core product data is identical, so they hit their specific validation logic.
            if ( $original_order && $original_order->has_status( array( 'ordered-quote', 'profiles-needed' ) ) ) {
                $allow_identical_save = true;
            }

			if ( function_exists('impersonation_is_active') && impersonation_is_active() && $original_order ) {
                // Do NOT allow bypass for Quotes or Profiles-Needed (they must follow standard logic)
                $exempt_statuses = ['active-quote', 'expired-quote', 'pending-quote', 'freight-quote', 'deleted-quote', 'ordered-quote', 'profiles-needed'];

                // If "Real Order" (e.g. Completed), ALLOW saving even if identical.
                if ( ! in_array( $original_order->get_status(), $exempt_statuses ) ) {
                    $allow_identical_save = true;
                }
            }
            
            // --- 2. PERFORM IDENTICAL CHECK ---
            // Run check only if we are NOT allowing the save, OR if it's the 'send_quote' action.
            if ( ! $allow_identical_save && $button_id !== 'send_quote' && $original_order && method_exists($this, 'are_cart_and_order_identical') && $this->are_cart_and_order_identical( $cart, $original_order ) ) {
                
                // --- PRESERVED LOGIC: Check for Profiles Needed Status ---
                $is_profiles_needed = $button_id === 'save_cart_quote' && function_exists('impersonation_is_active') && impersonation_is_active() && WC()->session->get('cart_is_profiles_needed') && $original_order && $original_order->get_status() === 'profiles-needed';
                
                // Return response with YOUR specific messages
                return new WP_REST_Response([
                    'success'  => true,
                    'message'  => $is_profiles_needed ? 'ENTER  A PROFILE!' : 'UNCHANGED CART!',
                    'order_id' => $original_order_id, 
                    'redirect' => false, 
                ], 200);

            } else {
				// Carts are not identical, proceed to update or create a new quote
				// Include 'send_quote' so it updates the exact same object when making a pending quote active.
				$is_pending_quote = ($button_id === 'send_quote' || $button_id === 'save_cart_quote' || $button_id === 'save_cart_quote_popup') && function_exists('impersonation_is_active') && impersonation_is_active() &&  WC()->session->get('cart_is_pending_quote') && $original_order && $original_order->get_status() === 'pending-quote';
				if ($is_pending_quote) {
					// If they clicked "Send Quote", change the status to active-quote on the existing object
					if ($button_id === 'send_quote') {
						$original_order->set_status('active-quote');
					}

					$order_id_saved = $this->update_existing_quote($original_order, $cart);
					$this->handle_generate_3d_pdf_and_send_email_for_order_quote($order_id_saved, $email_quote_to_customer, $email_class);
					
					// Set the appropriate success message
					if ($button_id === 'send_quote') {
						$response_message = 'SAVED & EMAILED!';
					} else {
						$response_message = 'QUOTE UPDATED!';
					}

					// --- Unset edit session variables AFTER successful order update ---
					WC()->session->set('edit_mode_order_quote_number', null);
					WC()->session->set('editing_original_order_id', null);
					WC()->session->set('cart_is_active_quote', null);
					WC()->session->set('cart_is_pending_quote', null);
					WC()->session->set('cart_is_freight_quote', null);
					WC()->session->set('cart_is_profiles_needed', null);
				} else {
					$is_profiles_needed = $button_id === 'save_cart_quote' && function_exists('impersonation_is_active') && impersonation_is_active() && WC()->session->get('cart_is_profiles_needed') && $original_order && $original_order->get_status() === 'profiles-needed';
					if ($is_profiles_needed) {
						$cart_items = $cart->get_cart();
						$custom_profile_skus = ['XBASEBOARD', 'XCASING', 'XCROWN', 'XMISCELLANEOUS'];
						
						$has_custom_profiles_in_cart = false;
						$has_at_least_one_filled = false;
						$invalid_profile_found = false; // Track if a non-existent profile was entered
						$profiles_added_list = [];

						foreach ($cart_items as $item) {
							$_product = wc_get_product($item['product_id']);
							if ($_product && in_array($_product->get_sku(), $custom_profile_skus, true)) {
								$has_custom_profiles_in_cart = true;

								$entered_number = isset($item['official_profile_number']) ? trim($item['official_profile_number']) : '';
								
								// Check if this specific item has a number filled in
								if (!empty($entered_number)) {
									$has_at_least_one_filled = true;
									
									// --- Verify that this official profile actually exists ---
									$official_product_id = wc_get_product_id_by_sku($entered_number);
									if (!$official_product_id) {
										$invalid_profile_found = true;
									} else {
										// Only add to the list if it's a valid product
										$profiles_added_list[] = $entered_number;
									}
								}
							}
						}

						// --- Check if any entered profile was invalid BEFORE updating the order ---
						if ($invalid_profile_found) {
							return new WP_REST_Response([
								'success'  => true,
								'message'  => 'CHECK NUMBERS!', // Required button text
								'order_id' => $original_order_id, 
								'redirect' => false, // Prevent redirection
							], 200);
						}

						// Only return error if custom profiles exist BUT NOT A SINGLE ONE is filled in.
						if ($has_custom_profiles_in_cart && !$has_at_least_one_filled) {
							return new WP_REST_Response([
								'success'  => true,
								'message'  => 'ENTER  A PROFILE!',
								'order_id' => $original_order_id, 
								'redirect' => false, 
							], 200);
						}

						$order_id_saved = $this->create_new_quote($user_id, $cart, $button_id);
						$this->handle_generate_3d_pdf_and_send_email_for_order_quote($order_id_saved, $email_quote_to_customer, $email_class);
						$response_message = 'ORDER UPDATED!';
						
						// Update the original order's status to "Profiles Added".
						if ($order_id_saved && !is_wp_error($order_id_saved)) {
							$saved_order = wc_get_order($order_id_saved);
							$starke_order_number = $saved_order->get_meta('_starke_order_number');
							
							// CHANGED: Note now includes the specific profiles that were added
							$profiles_str = !empty($profiles_added_list) ? implode(', ', $profiles_added_list) : 'None';
							$original_order->add_order_note('New order #' . $starke_order_number . ' was created from this one. It has an updated PDF with these official profile numbers added: ' . $profiles_str . '.');
						}
					} else {
						$order_id_saved = $this->create_new_quote($user_id, $cart, $button_id);
						$this->handle_generate_3d_pdf_and_send_email_for_order_quote($order_id_saved, $email_quote_to_customer, $email_class);
						if ($button_id === 'request_freight_quote') {
							$response_message = 'FREIGHT REQUEST SENT!';
						} else {
							$response_message = $email_quote_to_customer ? 'SAVED & EMAILED!' : 'CART / QUOTE SAVED!';
						}
					}
				}
			}
		} else {
			$order_id_saved = $this->create_new_quote($user_id, $cart, $button_id);
			$this->handle_generate_3d_pdf_and_send_email_for_order_quote($order_id_saved, $email_quote_to_customer, $email_class);
			if ($button_id === 'request_freight_quote') {
				$response_message = 'FREIGHT REQUEST SENT!';
			} else {
				$response_message = $email_quote_to_customer ? 'SAVED & EMAILED!' : 'CART / QUOTE SAVED!';
			}
		}

        // --- End cart comparison check ---
		$this->empty_the_cart($cart); // Turn on after testing is done for quote/order creation

		return new WP_REST_Response([
			'success' => true,
			'message' => $response_message,
			'order_id' => $order_id_saved, // Return the actual saved order ID
			'redirect' => true, // Indicate redirect needed to view the quote
		], 200);


	}

	private function create_new_quote($user_id, $cart, $button_id = null) {
		$order = new WC_Order();
		$order->set_customer_id($user_id);
		if (is_wp_error($order)) {
			WC()->session->set('edit_mode_order_quote_number', null);
			WC()->session->set('editing_original_order_id', null);
			return new WP_Error('order_creation_failed', 'Failed to create order: ' . $order->get_error_message(), ['status' => 500]);
		}

		$this->populate_quote_from_cart($order, $cart);

		// Check if we are in "Profiles Needed" mode. (Checking if the cart is a 'Profiles Needed' order)
		$cart_is_profiles_needed = WC()->session->get('cart_is_profiles_needed');
		$is_admin_or_impersonating = (function_exists('impersonation_is_active') && impersonation_is_active()) || current_user_can('manage_woocommerce');
		$edit_mode_order_quote_number = WC()->session->get('edit_mode_order_quote_number');

		if ($button_id === 'request_freight_quote') {
			$order_status = 'freight-quote';
		} elseif ($button_id === 'send_quote') {
			$order_status = 'active-quote';
		} else {
			if ($is_admin_or_impersonating) {
				// MODIFIED: If Save & Open Popup, force pending-quote (bypassing profiles-ready)
				if ($button_id === 'save_cart_quote_popup') {
					$order_status = 'pending-quote';
				} elseif ($cart_is_profiles_needed) {
					$order_status = 'profiles-ready';
				} else {
					$order_status = 'pending-quote';
				}
			} else {
				$order_status = 'active-quote';
			}
		}

		$order->set_status($order_status);
		$order->update_taxes();
		$order->calculate_totals(false);
		$order_id_saved = $order->save();

		if (!$order_id_saved) {
			WC()->session->set('edit_mode_order_quote_number', null);
			WC()->session->set('editing_original_order_id', null);
			return new WP_Error('order_save_failed', 'Failed to save the new order.', ['status' => 500]);
		}

		if ($cart_is_profiles_needed && $edit_mode_order_quote_number) {
			$note = 'This order with updated PDF for the Custom Profiles was created from order #' . $edit_mode_order_quote_number . '.';
			$order->add_order_note($note);
		}
		if ($button_id === 'request_freight_quote' && $order_id_saved) {
			$saved_order = wc_get_order($order_id_saved);
			$this->send_freight_quote_admin_notification($saved_order);
		}
		if ($order_status === 'active-quote') {
			$editing_original_order_id = WC()->session->get('editing_original_order_id');
			if ($editing_original_order_id) {
				$original_order = wc_get_order($editing_original_order_id);
				if ($original_order) {
					// Delete the original quote if it is a temporary quote type
					$original_order_status = $original_order->get_status();
					if ($original_order_status === 'pending-quote' || ($original_order_status === 'freight-quote' && $is_admin_or_impersonating)) {
						$total_paid = $original_order->get_meta('_total_paid', true);
						if (empty($total_paid) || (float) $total_paid <= 0) {
							$original_order->delete(true);
						}
					}
				}
			}
		}
		return $order_id_saved;
	}

	private function update_existing_quote($order, $cart) {
		// 1. Clear existing items from the order
		$order->remove_order_items('line_item');
		$order->remove_order_items('fee');
		$order->remove_order_items('shipping');
	
		// 2. Repopulate with current cart data using the helper function
		$this->populate_quote_from_cart($order, $cart);
	
		// 3. Finalize and save
		$order->update_taxes();
		$order->calculate_totals(false);
		$order_id_saved = $order->save();
	
		if (!$order_id_saved) {
			return new WP_Error('order_save_failed', 'Failed to save the overwritten order.', ['status' => 500]);
		}
	
		if ( class_exists( 'Additional_Order_Quote_Meta_Creator' ) ) {
			Additional_Order_Quote_Meta_Creator::set_quote_link_id( $order_id_saved, $order->get_status(), $order->get_status() );
		}
		return $order_id_saved;
	}

	private function populate_quote_from_cart($order, $cart) {
		wc_get_logger()->warning('WC()->session->get(chosen_shipping_methods) A: ' . print_r(WC()->session->get('chosen_shipping_methods'), true), ['source' => 'send_quote_debug1']);
		// Set billing and shipping details
		$shipping_address = [];
		$billing_address = [];
		$address_fields = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone'];
	
		foreach ($address_fields as $field) {
			$shipping_address[$field] = WC()->checkout->get_value('shipping_' . $field);
		}
	
		$billing_same_as_shipping = WC()->checkout->get_value('billing_same_as_shipping');
		if ($billing_same_as_shipping) {
			$billing_address = $shipping_address;
		} else {
			foreach ($address_fields as $field) {
				$billing_address[$field] = WC()->checkout->get_value('billing_' . $field);
			}
		}
		$billing_address['email'] = WC()->checkout->get_value('billing_email');
	
		$order->set_address($shipping_address, 'shipping');
		$order->set_address($billing_address, 'billing');

		// Check if we are in "Profiles Needed" mode. (Checking if the cart is a 'Profiles Needed' order)
		$cart_is_profiles_needed = WC()->session->get('cart_is_profiles_needed');
		
		// Determine if convenience fee tax should be applied to this quote
		$apply_fee_tax = false;
		$fee_percentage = 0;
		$chosen_payment_method = WC()->session->get('chosen_payment_method');
		// The fee and its tax only apply if the cart is "Profiles Needed" AND Stripe is selected.
		if ($cart_is_profiles_needed && 'stripe_cc' === $chosen_payment_method) {
			$apply_fee_tax = true;
			$options = get_option('starke_commerce_options');
			$fee_percentage = isset($options['card_convenience_fee']) ? floatval($options['card_convenience_fee']) / 100 : 0;
		}
	
		// Tax rates for sample items
		$has_samples = false;
		$has_standard = false;
		foreach ($cart->get_cart() as $cart_item) {
			if ( ( function_exists('is_cart_item_a_sample') && is_cart_item_a_sample($cart_item) ) || ( isset($cart_item['sample']) && $cart_item['sample'] ) ) {
				$has_samples = true;
			} else {
				$has_standard = true;
			}
		}

		// Logic: We only treat it as a "Samples Order" (using secondary address) if it is MIXED.
		// If it is Samples Only, we use Primary.
		$is_mixed_cart = $has_samples && $has_standard;

		// Retrieve from session, but conditionally clear if not mixed
		$samples_full_shipping_address = WC()->session->get('samples_full_shipping_address');
		$samples_address_po_number_job_name = WC()->session->get('samples_address_po_number_job_name');

		if ( ! $is_mixed_cart ) {
			$samples_full_shipping_address = '';
			$samples_address_po_number_job_name = '';
		}

		$sample_tax_rates = [];
        if (is_array($samples_full_shipping_address) && !empty($samples_full_shipping_address['state']) && !empty($samples_full_shipping_address['postcode'])) {
            $sample_destination = [
                'country'   => $samples_full_shipping_address['country'] ?? 'US',
                'state'     => $samples_full_shipping_address['state'],
                'postcode'  => $samples_full_shipping_address['postcode'],
                'city'      => $samples_full_shipping_address['city'] ?? '',
            ];
            
            // USE TAXJAR API
            if ( class_exists( 'Starke_TaxJar_API' ) ) {
                $taxjar = new Starke_TaxJar_API();
                $sample_tax_rates = $taxjar->get_formatted_rate_array( $sample_destination );
            } else {
                $sample_tax_rates = WC_Tax::find_rates($sample_destination);
            }
        }
	
		// --- START: Logic to rename associated custom profile charge items when swapping profiles ---
		$setup_charge_sku = 'SETUPCHARGE';
		$knife_cost_sku   = 'KNIFECOST';

		if ($cart_is_profiles_needed) {
			$name_swaps = [];
			$cart_contents = $cart->get_cart();

			// First Pass: Build a map of old custom names to new official profile numbers.
			foreach ($cart_contents as $cart_item) {
				if (
					isset($cart_item['custom_name']) && !empty($cart_item['custom_name']) &&
					isset($cart_item['official_profile_number']) && !empty($cart_item['official_profile_number'])
				) {
					$product = wc_get_product($cart_item['product_id']);
					$custom_profile_skus = ['XBASEBOARD', 'XCASING', 'XCROWN', 'XMISCELLANEOUS'];
					if ($product && in_array($product->get_sku(), $custom_profile_skus, true)) {
						$name_swaps[$cart_item['custom_name']] = $cart_item['official_profile_number'];
					}
				}
			}

			// Second Pass: If we have swaps, find and update the names of associated charge items.
			if (!empty($name_swaps)) {
				// We get a modifiable copy of the cart contents.
				$modifiable_cart_contents = $cart->get_cart_contents();

				foreach ($modifiable_cart_contents as $cart_item_key => &$cart_item_data) {
					$product = wc_get_product($cart_item_data['product_id']);
					if (!$product) continue;

					$product_sku = $product->get_sku();

					if ($product_sku === $setup_charge_sku || $product_sku === $knife_cost_sku) {
						// This is a charge item. Check if its name needs updating.
						$current_charge_name = $cart_item_data['custom_name'] ?? $product->get_name();

						foreach ($name_swaps as $old_custom_name => $new_official_name) {
							if (strpos($current_charge_name, $old_custom_name) !== false) {
								// The old name was found. Create the new name.
								$new_charge_name = str_replace($old_custom_name, $new_official_name, $current_charge_name);
								
								// Update the 'custom_name' in the cart data. This will be used to set the order item name.
								$cart_item_data['custom_name'] = $new_charge_name;
								
								// Break the inner loop since we found our match and updated the name.
								break;
							}
						}
					}
				}
				// Unset the variable to break the reference from the last element of the array.
				unset($cart_item_data); 

				// VERY IMPORTANT: We now update the main cart object with our modified contents.
				$cart->set_cart_contents($modifiable_cart_contents);
			}
		}
		// --- END: Logic to rename associated custom profile charge items ---

		// --- NEW: Temporarily disable our custom fee tax filter to prevent it from running here ---
		$fee_tax_filter_hooked = has_filter('woocommerce_shipping_rate_taxes', 'sm_adjust_shipping_item_tax');
		if ($fee_tax_filter_hooked) {
			remove_filter('woocommerce_shipping_rate_taxes', 'sm_adjust_shipping_item_tax', 10);
		}

		// --- ALPHANUMERIC SORTING FOR QUOTE ITEMS ---
		$cart_contents = $cart->get_cart();
		uasort($cart_contents, function($a, $b) {
			$get_name = function($item) {
				if (!empty($item['official_profile_number'])) return (string) $item['official_profile_number'];
				if (!empty($item['custom_name'])) return (string) $item['custom_name'];
				if (isset($item['data']) && is_object($item['data'])) return (string) $item['data']->get_name();
				return '';
			};
			// strnatcasecmp handles natural alphanumeric sorting (e.g., 1001 before 1002, X2 before X10)
			return strnatcasecmp($get_name($a), $get_name($b));
		});

		// Add cart items
		foreach ($cart_contents as $cart_item_key => $cart_item_data) {
			$product_to_add = null;
			// If in "Profiles Needed" mode, check if the item is a custom profile that needs swapping.
			if ($cart_is_profiles_needed && isset($cart_item_data['official_profile_number']) && !empty($cart_item_data['official_profile_number'])) {
				// Check if the current item is one of the custom profile products.
				$custom_profile_skus = ['XBASEBOARD', 'XCASING', 'XCROWN', 'XMISCELLANEOUS'];
				$_product = wc_get_product($cart_item_data['product_id']);
				if ($_product && in_array($_product->get_sku(), $custom_profile_skus)) {
					// Look up the official product by its name (which is the official_profile_number).
					$official_product_id = wc_get_product_id_by_sku($cart_item_data['official_profile_number']);
					if ($official_product_id) {
						$product_to_add = wc_get_product($official_product_id);
					}
				}
			}

			// If no swap occurred, use the original product from the cart.
			if (!$product_to_add) {
				$swap_custom_profile_for_official_profile = false;
				$product_id = $cart_item_data['product_id'];
				$variation_id = $cart_item_data['variation_id'];
				$product_to_add = wc_get_product($variation_id ?: $product_id);
			} else {
				$swap_custom_profile_for_official_profile = true;
			}
	
			if (!$product_to_add) continue;

			$order_item = new WC_Order_Item_Product();
			$order_item->set_product($product_to_add);
			$order_item->set_quantity($cart_item_data['quantity']);
			$order_item->set_subtotal($cart_item_data['line_subtotal']);
			$order_item->set_total($cart_item_data['line_total']);
	
			// If in "Profiles Needed" mode, use the exact tax data from the locked cart.
			if ($cart_is_profiles_needed) {
				// For "Profiles Needed" orders, trust the cart's tax data completely.
				// This preserves the locked-in totals, including the convenience fee tax.
				$order_item->set_taxes($cart_item_data['line_tax_data']);
			} else {
				// For all other quotes, recalculate the tax from scratch to ensure the fee tax is removed.
				$tax_rates_for_item = [];
				$base_amount_for_tax = $cart_item_data['line_total'];

				if ($is_mixed_cart && function_exists('is_cart_item_a_sample') && is_cart_item_a_sample($cart_item_data)) {
                    $tax_rates_for_item = $sample_tax_rates;
                } else {
                    // USE TAXJAR API FOR STANDARD ITEMS
                    if ( class_exists( 'Starke_TaxJar_API' ) ) {
                        $taxjar = new Starke_TaxJar_API();
                        $standard_destination = [
                            'country'  => WC()->checkout->get_value('shipping_country') ?: 'US',
                            'state'    => WC()->checkout->get_value('shipping_state'),
                            'postcode' => WC()->checkout->get_value('shipping_postcode'),
                            'city'     => WC()->checkout->get_value('shipping_city'),
                        ];

						// --- Use Pickup Address for Taxes if Selected ---
						$chosen_methods = WC()->session->get('chosen_shipping_methods', []);
						$primary_method = $chosen_methods[0] ?? '';
						if ( strpos( $primary_method, 'pickup_location:' ) === 0 && function_exists('starke_get_pickup_location_address') ) {
							$standard_destination = starke_get_pickup_location_address( $primary_method, $standard_destination );
						}

                        $tax_rates_for_item = $taxjar->get_formatted_rate_array( $standard_destination );
                    } else {
                        $tax_rates_for_item = WC_Tax::get_rates($product_to_add->get_tax_class());
                    }
                }

				// NOTE: $apply_fee_tax will be false here (as set in Step 1), so the fee is correctly excluded.
				if ($apply_fee_tax && $fee_percentage > 0) {
					$base_amount_for_tax += $cart_item_data['line_total'] * $fee_percentage;
				}
				
				$correct_taxes = WC_Tax::calc_tax($base_amount_for_tax, $tax_rates_for_item, false);
				$order_item->set_taxes(['total' => $correct_taxes, 'subtotal' => $correct_taxes]);
			}
	
			if ($swap_custom_profile_for_official_profile && isset($cart_item_data['official_profile_number'])) {
				$order_item->set_name($cart_item_data['official_profile_number']);
				if (isset($cart_item_data['custom_name'])) {
					$order_item->add_meta_data('custom_profile_number', $cart_item_data['custom_name'], true);
				}
			} elseif (isset($cart_item_data['custom_name'])) {
				$order_item->set_name($cart_item_data['custom_name']);
			}
	
			foreach ($cart_item_data as $meta_key => $meta_value) {
				$skip_keys = ['key', 'product_id', 'variation_id', 'variation', 'quantity', 'data', 'data_hash', 'line_tax_data', 'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_tax'];
				if ($swap_custom_profile_for_official_profile) {
					array_push($skip_keys, 'official_profile_number', 'knifecost', 'markup', 'waste', 'similar_profiles', 'custom_description');
				}
				if (in_array($meta_key, $skip_keys, true)) continue;
				$order_item->add_meta_data($meta_key, $meta_value, true);
			}
			$order->add_item($order_item);
		}
	
		// Add cart fees
		if ($cart_is_profiles_needed) {
			foreach ($cart->get_fees() as $fee) {
				$item_fee = new WC_Order_Item_Fee();
				$item_fee->set_name($fee->name);
				$item_fee->set_amount($fee->amount);
				$item_fee->set_total($fee->amount);
				
				// Per your request, set fees as non-taxable for now.
				$item_fee->set_tax_status('none'); 
				$item_fee->set_taxes(['total' => []]);

				$order->add_item($item_fee);
			}
		}

		wc_get_logger()->warning('WC()->session->get(chosen_shipping_methods) B: ' . print_r(WC()->session->get('chosen_shipping_methods'), true), ['source' => 'send_quote_debug1']);

		// Add shipping
		foreach (WC()->cart->get_shipping_packages() as $package_index => $package) {
			foreach (WC()->session->get('shipping_for_package_' . $package_index)['rates'] ?? [] as $rate_id => $rate) {
				if (WC()->session->get('chosen_shipping_methods')[$package_index] === $rate_id) {
					$item = new WC_Order_Item_Shipping();
					$item->set_method_title($rate->get_label());
					$item->set_method_id($rate_id);
					$item->set_total($rate->get_cost());

					wc_get_logger()->warning('$rate->get_label(): ' . print_r($rate->get_label(), true), ['source' => 'send_quote_debug1']);
	
					// If in "Profiles Needed" mode, use the exact tax data from the locked cart.
					if ($cart_is_profiles_needed) {
						// For "Profiles Needed" orders, trust the shipping rate's tax data completely.
						$item->set_taxes(['total' => $rate->get_taxes()]);
					} else {
						// For all other quotes, recalculate the tax from scratch to ensure the fee tax is removed.
						$tax_rates_for_shipping = [];
						$base_amount_for_tax = $rate->get_cost();

						if ($is_mixed_cart && stripos($rate->get_label(), 'Samples') !== false) {
                            $tax_rates_for_shipping = $sample_tax_rates;
                        } else {
                            // USE TAXJAR API FOR STANDARD SHIPPING
                            if ( class_exists( 'Starke_TaxJar_API' ) ) {
                                $taxjar = new Starke_TaxJar_API();
                                $standard_destination = [
                                    'country'  => WC()->checkout->get_value('shipping_country') ?: 'US',
                                    'state'    => WC()->checkout->get_value('shipping_state'),
                                    'postcode' => WC()->checkout->get_value('shipping_postcode'),
                                    'city'     => WC()->checkout->get_value('shipping_city'),
                                ];

								// --- Use Pickup Address for Taxes if Selected ---
								$chosen_methods = WC()->session->get('chosen_shipping_methods', []);
								$primary_method = $chosen_methods[0] ?? '';
								if ( strpos( $primary_method, 'pickup_location:' ) === 0 && function_exists('starke_get_pickup_location_address') ) {
									$standard_destination = starke_get_pickup_location_address( $primary_method, $standard_destination );
								}

                                $tax_rates_for_shipping = $taxjar->get_formatted_rate_array( $standard_destination );
                            } else {
                                $tax_rates_for_shipping = WC_Tax::get_shipping_tax_rates();
                            }
                        }
	
						// NOTE: $apply_fee_tax will be false here, so the fee is correctly excluded.
						if ($apply_fee_tax && $fee_percentage > 0) {
							$base_amount_for_tax += $rate->get_cost() * $fee_percentage;
						}
	
						$correct_shipping_taxes = WC_Tax::calc_shipping_tax($base_amount_for_tax, $tax_rates_for_shipping);
						$item->set_taxes(['total' => $correct_shipping_taxes]);
					}
	
					if ('pickup_location' === $rate->get_method_id()) {
						$meta_data = $rate->get_meta_data();
						if (!empty($meta_data['pickup_address'])) {
							$item->add_meta_data('pickup_address', $meta_data['pickup_address'], true);
						}
					}
					$order->add_item($item);
				}
			}
		}

		// Re-enable our custom fee tax filter now that quote population is done ---
		if ($fee_tax_filter_hooked) {
			add_filter('woocommerce_shipping_rate_taxes', 'sm_adjust_shipping_item_tax', 10, 2);
		}

		// Add payment method
		$order->set_payment_method( WC()->session->get('chosen_payment_method') );
		
		// Save Meta from Session
		$this->save_cc_emails_to_order_quote_meta($order);
		$order->update_meta_data('_jobsite_contact', WC()->session->get('jobsite_contact'));
		$order->update_meta_data('_jobsite_contact_cell_number', WC()->session->get('jobsite_contact_cell_number'));
		$order->update_meta_data('_po_number_job_name', WC()->session->get('po_number_job_name'));
		$order->update_meta_data('_samples_address_po_number_job_name', $samples_address_po_number_job_name);
		
		if ($samples_full_shipping_address && $samples_full_shipping_address['country'] !== 'US') {
			$samples_full_shipping_address['country'] = 'US';
		}
		$order->update_meta_data('_samples_full_shipping_address', $samples_full_shipping_address);
	}


	// Fully empty the cart
	private function empty_the_cart($cart) {
		$cart->empty_cart(); // Clear cart after successful save

		// If impersonation is active, update the session cart and persistent cart meta to be empty.
		if (isset($_COOKIE['original_admin_id'], $_COOKIE['impersonated_user_id']) &&
			!empty($_COOKIE['original_admin_id']) && !empty($_COOKIE['impersonated_user_id']) ) {

			if (WC()->cart) WC()->cart->set_cart_contents([]); // Ensure cart object is empty
			if (WC()->session) {
				WC()->session->set('cart', []); // Ensure WC session cart is empty
				WC()->session->save_data();
			}

			$admin_id = intval($_COOKIE['original_admin_id']);
			//$customer_id = intval($_COOKIE['impersonated_user_id']); // Not needed for key
			$cart_key = 'admin_' . $admin_id . '_user_' . strval($_COOKIE['impersonated_user_id']); // Rebuild key just in case
			$persistent_carts = get_user_meta( $admin_id, '_admin_persistent_carts', true );
			if ( ! is_array( $persistent_carts ) ) { $persistent_carts = []; }
			$persistent_carts[ $cart_key ] = array( 'cart' => [] ); // Store empty cart array
			update_user_meta( $admin_id, '_admin_persistent_carts', $persistent_carts );
		}
	}
	

    /**
     * Helper function to compare current cart contents with an existing quote/order.
     *
     * @param WC_Cart  $cart  The current WooCommerce cart object.
     * @param WC_Order $order The original order object to compare against.
     * @return bool True if identical, False otherwise.
     */
    public function are_cart_and_order_identical( WC_Cart $cart, WC_Order $order ) {
        // Ensure objects are valid
        if ( !$cart || !$order || !$order instanceof WC_Order ) {
            if (function_exists('wc_get_logger')) wc_get_logger()->warning('Cart Compare: Invalid cart or order object passed.', ['source' => 'cart-compare']);
            return false;
        }

		// Treat Expired Quotes as "Different" so the Save Popup on the My Account Quotes page gets triggered even if the cart is unchanged
        if ( $order->has_status('expired-quote') ) {
            return false;
        }

        // 1. Compare Line Items (Products, Quantities, Key Meta)
		// Cart items
        $cart_items_simple = [];
        $comparable_meta_keys = [ // ** IMPORTANT: Customize this list! **
             'linear_feet',
			 'thickness',
			 'width',
			 'length',
			 'first_rabbet',
             'first_rabbet_thickness',
			 'first_rabbet_width',
			 'reliefangle',
             'backrelief',
			 'species',
			 'finish',
			 'stain',
			 'sheen',
			 'rabbet_setup_charge',
			 'relief_angle_setup_charge',
			 'custom_name',
			 'quantity_discount',
			 'knifecost',
			 'markup',
			 'waste',
			 'similar_profiles',
			 'custom_description'
			 //'official_profile_number'
             // Add any other custom keys from the cart/order item meta that define the product's unique configuration
        ];

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            // Basic validation of cart item structure
            if (!isset($cart_item['product_id']) || !isset($cart_item['quantity'])) continue;

            $product_id = $cart_item['product_id'];
            $variation_id = $cart_item['variation_id'] ?? 0; // Ensure variation_id exists
            
			// --- KEY GENERATION START ---
            // Default key is Product ID
            $key = $variation_id > 0 ? $product_id . '_' . $variation_id : (string)$product_id;
            
            // If it is a custom profile (has custom_name), use that as the Key instead.
            // This ensures X1 and X2 are treated as completely different items.
            if ( !empty($cart_item['custom_name']) ) {
                $key = $cart_item['custom_name'];
            }
            // --- KEY GENERATION END ---

            $relevant_meta = [];
            foreach ($comparable_meta_keys as $meta_key) {
                if (isset($cart_item[$meta_key])) {
                    $value = $cart_item[$meta_key];
                    // Only include the meta key if it is not an empty string, 
                    // perfectly mirroring the Order Item logic below it.
                    if ( (string) $value !== '' ) {
                        if (is_bool($value)) $value = $value ? '1' : '0'; // Normalize boolean
                        $relevant_meta[$meta_key] = (string) $value; // Cast to string
                    }
                }
            }
            ksort($relevant_meta); // Sort meta keys

            // Use cart_item_key to handle multiple instances of the same product if needed,
            // but comparing by product/variation ID sums quantities which might be simpler.
            // Let's sum quantities for the same item config.
            if (!isset($cart_items_simple[$key])) {
                 $cart_items_simple[$key] = ['qty' => 0, 'meta_json' => json_encode($relevant_meta)]; // Store meta hash/json
            }
            $cart_items_simple[$key]['qty'] += $cart_item['quantity'];

        }
        // No need to sort $cart_items_simple by key here if we check key existence in the next loop

		// Quote/Order items
        $order_items_simple = [];
        foreach ( $order->get_items() as $item_id => $item ) {
             if (!$item instanceof WC_Order_Item_Product) continue; // Ensure it's a product item

            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            
			// --- KEY GENERATION START ---
            $key = $variation_id > 0 ? $product_id . '_' . $variation_id : (string)$product_id;
            
            // Check order item meta for custom_name
            $custom_name_meta = $item->get_meta('custom_name', true);
            if ( !empty($custom_name_meta) ) {
                $key = $custom_name_meta;
            }
            // --- KEY GENERATION END ---

            $relevant_meta = [];
            foreach ($comparable_meta_keys as $meta_key) {
                 $value = $item->get_meta($meta_key, true); // Single = true
                 if ($value !== '') { // Check if meta exists
                      if (is_bool($value)) $value = $value ? '1' : '0';
                      $relevant_meta[$meta_key] = (string) $value;
                 }
            }
            ksort($relevant_meta);

             if (!isset($order_items_simple[$key])) {
                 $order_items_simple[$key] = ['qty' => 0, 'meta_json' => json_encode($relevant_meta)];
             }
            $order_items_simple[$key]['qty'] += $item->get_quantity();

        }

        // Now compare the two simplified arrays
        if (count($cart_items_simple) !== count($order_items_simple)) {
            if (function_exists('wc_get_logger')) wc_get_logger()->debug('Cart Compare: Item count mismatch. Cart=' . count($cart_items_simple) . ' Order=' . count($order_items_simple), ['source' => 'cart-compare']);
            return false; // Different number of unique items
        }

        foreach($cart_items_simple as $key => $cart_data) {
            if (!isset($order_items_simple[$key])) {
                 if (function_exists('wc_get_logger')) wc_get_logger()->debug("Cart Compare: Item key {$key} not found in order items.", ['source' => 'cart-compare']);
                return false; // Item from cart not found in order
            }
            if ($cart_data['qty'] !== $order_items_simple[$key]['qty']) {
                 if (function_exists('wc_get_logger')) wc_get_logger()->debug("Cart Compare: Quantity mismatch for item key {$key}. Cart={$cart_data['qty']} Order={$order_items_simple[$key]['qty']}", ['source' => 'cart-compare']);
                return false; // Quantity differs
            }
            if ($cart_data['meta_json'] !== $order_items_simple[$key]['meta_json']) {
                 if (function_exists('wc_get_logger')) wc_get_logger()->debug("Cart Compare: Meta mismatch for item key {$key}. Cart={$cart_data['meta_json']} Order={$order_items_simple[$key]['meta_json']}", ['source' => 'cart-compare']);
                return false; // Relevant meta differs
            }
            // Remove matched item to ensure no extra items in order
             unset($order_items_simple[$key]);
        }

         // If $order_items_simple is not empty here, it means the order had items not in the cart
         if (!empty($order_items_simple)) {
             if (function_exists('wc_get_logger')) wc_get_logger()->debug("Cart Compare: Order contains items not found in cart. Remaining keys: " . implode(', ', array_keys($order_items_simple)), ['source' => 'cart-compare']);
             return false;
         }


        // 2. Compare Fees (Using names as keys for comparison)
        /*$cart_fees_simple = [];
        foreach( $cart->get_fees() as $fee_key => $fee ) {
            $cart_fees_simple[$fee->name] = round($fee->amount, wc_get_price_decimals());
        }
        ksort($cart_fees_simple);

        $order_fees_simple = [];
        foreach( $order->get_fees() as $item_id => $fee ) {
            $order_fees_simple[$fee->get_name()] = round($fee->get_total(), wc_get_price_decimals());
        }
        ksort($order_fees_simple);

        if ( json_encode($cart_fees_simple) !== json_encode($order_fees_simple) ) {
             if (function_exists('wc_get_logger')) wc_get_logger()->debug('Cart Compare: Fee mismatch. Cart=' . json_encode($cart_fees_simple) . ' Order=' . json_encode($order_fees_simple), ['source' => 'cart-compare']);
            return false; // Fees differ
        }*/

        // 3. Compare Shipping Method IDs
        $cart_shipping_methods = (array) WC()->session->get( 'chosen_shipping_methods', [] );
        // Normalize: remove empty values that might be left in session
        $cart_shipping_methods = array_filter($cart_shipping_methods);
        sort($cart_shipping_methods);

        $order_shipping_methods = [];
        foreach ( $order->get_items('shipping') as $item_id => $item ) {
            $order_shipping_methods[] = $item->get_method_id() . ':' . $item->get_instance_id(); // e.g., flat_rate:1
        }

		wc_get_logger()->warning('$order->get_items(shipping): ' . print_r($order->get_items('shipping'), true), ['source' => 'cart_fees4']);

        sort($order_shipping_methods);

        if ( json_encode($cart_shipping_methods) !== json_encode($order_shipping_methods) ) {
            if (function_exists('wc_get_logger')) wc_get_logger()->debug('Cart Compare: Shipping Method mismatch. Cart=' . json_encode($cart_shipping_methods) . ' Order=' . json_encode($order_shipping_methods), ['source' => 'cart-compare']);
            return false; // Shipping method choices differ
        }

		// --- NEW: Compare Destination Region (City/State/Zip) ---
        // Even if taxes aren't locked, we MUST check this because:
        // 1. Shipping Costs often change based on destination (even if Method ID stays the same).
        // 2. The PDF "Ship To" address needs to match what the customer just typed.
        if ( WC()->customer ) {
            // We intentionally IGNORE 'shipping_address_1' to avoid forcing saves on minor typo fixes.
            $address_props = ['shipping_city', 'shipping_state', 'shipping_postcode', 'shipping_country'];
            
            foreach ($address_props as $prop) {
                $getter = 'get_' . $prop; 
                
                // Compare Session Value (Current) vs Order Value (Saved)
                $cart_val  = WC()->customer->$getter(); 
                $order_val = $order->$getter();

                // Simple loose comparison after trimming
                if ( strtolower(trim($cart_val)) !== strtolower(trim($order_val)) ) {
                    if (function_exists('wc_get_logger')) wc_get_logger()->debug('Cart Compare: Address mismatch on ' . $prop, ['source' => 'cart-compare']);
                    return false; // Region changed, so allow save to update Shipping Cost & PDF
                }
            }
        }

        // If all checks passed
        if (function_exists('wc_get_logger')) wc_get_logger()->info('Cart Compare: Cart deemed IDENTICAL to original order ' . $order->get_id(), ['source' => 'cart-compare']);
        return true;
    }

	// Email the order/quote to the customer
	public function email_order_quote_to_customer($order_id, $email_class) {
		$email = WC()->mailer()->emails[$email_class] ?? null; // $email_class would be 'WC_Email_Customer_Quote_Sending' for example
		if (! $email) {
			return;
		}
    	$email->trigger( $order_id );		
	}

	private function setup_email_order_quote_to_customer_async() {
		add_action('email_order_quote_to_customer_async', [$this, 'email_order_quote_to_customer_async'], 10, 2);
	}
	public function email_order_quote_to_customer_async($order_id, $email_class) {
		$order_id = strval($order_id);
		$email_class = strval($email_class);
		$this->email_order_quote_to_customer($order_id, $email_class);
	}

	/**
	 * Trigger PDF Generation on New WooCommerce Order
	*/
	//add_action('woocommerce_new_order', 'trigger_pdf_generator_on_new_order', 10, 1);
	public function generate_order_quote_3d_pdf($order_id, $email_customer = false, $email_class = null) {
		// This runs inside an admin-ajax request the checkout flow fires and
		// forgets (see handle_generate_3d_pdf_and_send_email_for_order_quote), so
		// it's not bound by a normal page-load's time budget, but PHP's own
		// max_execution_time still applies and can kill this script before the
		// wp_remote_post call below (170s timeout) ever gets a chance to time out
		// itself. Override explicitly rather than assume the host's default is
		// high enough, safe here since this is a background job, not a request a
		// browser is blocking on.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 );
		}

		// --- FIX: Force clear cache to ensure we get the absolute latest Order Status ---
        clean_post_cache( $order_id );
		
		// Get the WC_Order object
		$order = wc_get_order($order_id);

		if (!$order) {
			// Log an error if the order object couldn't be retrieved
			error_log("PDF Generator: Could not retrieve order object for order ID " . $order_id);
			return;
		}

		/// --- Gather Order Data ---
		$order_data = [];

		// Cart Items
		$order_data['cart_items'] = [];
		$has_standard_product = false;
		foreach ($order->get_items() as $item_id => $item) {
			//$product = $item->get_product();
			$product_id = $item->get_product_id();

			// --- NEW: Identify Item Type Early ---
			$name_lower = strtolower($item->get_name());
			$is_custom = function_exists('is_custom_profile') && is_custom_profile($product_id);
			$is_sample = !empty($item->get_meta('sample')) || strpos($name_lower, '(sample)') !== false;
			$is_charge = strpos($name_lower, 'tooling charge') !== false || strpos($name_lower, 'setup charge') !== false;

			$is_modified = false; // Default to false

			// --- NEW: Skip the modification check for Custom Profiles, Samples, and Charges ---
			if ( ! $is_custom && ! $is_sample && ! $is_charge ) {

				// --- NEW: Check for Modifications (Metadata vs ACF Defaults) ---
				// --- 1. Get Server Defaults (ACF) ---
				$default_width = get_field('width', $product_id);
				$default_thickness = get_field('thickness', $product_id);
				$default_rabbet_thickness = get_field('1strabbetnotch_thickness', $product_id);
				$default_rabbet_width = get_field('1strabbetnotch_width', $product_id);
				$default_rabbet_pos = get_field('1strabbetnotch', $product_id); 
				$default_back_relief = get_field('back_relief', $product_id);
				$default_relief_angle = get_field('relief_angle', $product_id);

				// --- 2. Get Raw Item Metadata ---
				$raw_width = $item->get_meta('width_actual');
				$raw_thickness = $item->get_meta('thickness_actual');
				$raw_rabbet_pos = $item->get_meta('first_rabbet');
				$raw_rabbet_thick = $item->get_meta('first_rabbet_thickness_actual'); 
				$raw_rabbet_width = $item->get_meta('first_rabbet_width_actual');
				$raw_relief_angle = $item->get_meta('reliefangle');
				$raw_back_relief = $item->get_meta('backrelief');

				// --- 3. NORMALIZE METADATA ---
				// FIX: We do NOT force empty values to '0' or 'OFF'. We preserve them as empty strings.

				// A. Rabbet Position (first_rabbet)
				$meta_rabbet_pos = $raw_rabbet_pos;
				if ($raw_rabbet_pos === 'OFF') {
					$meta_rabbet_pos = '0'; 
				} elseif (!empty($raw_rabbet_pos)) {
					$meta_rabbet_pos = str_replace('#', '', $raw_rabbet_pos);
				}

				// B. Relief Angle (reliefangle)
				$meta_relief_angle = $raw_relief_angle;
				if ($raw_relief_angle === 'ON') {
					$meta_relief_angle = '1';
				} elseif ($raw_relief_angle === 'OFF') {
					$meta_relief_angle = '0';
				}
				// Note: If it was '', it STAYS ''.

				// C. Back Relief (backrelief)
				$meta_back_relief = $raw_back_relief;
				switch ($raw_back_relief) {
					case 'Rectangular Shape':
						$meta_back_relief = '2'; 
						break;
					case 'Trapezoidal Shape':
						$meta_back_relief = '4';
						break;
					case 'OFF':
						$meta_back_relief = '0';
						break;
					default:
						// Keep original (includes empty)
						$meta_back_relief = $raw_back_relief;
						break;
				}


				// --- 4. Comparison Logic ---
				$is_modified = false;

				// Updated comparison helper:
				// 1. Treats null and empty string as identical ('').
				// 2. NEW: If EITHER value is empty, return FALSE (No Difference).
				$check_diff = function($val1, $val2) {
					$v1 = ($val1 === null) ? '' : $val1;
					$v2 = ($val2 === null) ? '' : $val2;
					
					// If either is empty/blank, assume they match (feature disabled or not applicable)
					if ($v1 === '' || $v2 === '') return false;

					// Numeric comparison (only if both are actual numbers)
					if (is_numeric($v1) && is_numeric($v2)) {
						$diff = abs((float)$v1 - (float)$v2) > 0.001;
						return $diff;
					}

					// String comparison
					$diff = (string)$v1 !== (string)$v2;
					return $diff;
				};

				// Dimensions
				if ($check_diff($raw_width, $default_width)) $is_modified = true;
				if ($check_diff($raw_thickness, $default_thickness)) $is_modified = true;
				
				// Rabbet Dims - Only check if Rabbet is actually ON/Set
				if ($raw_rabbet_pos !== 'OFF' && $raw_rabbet_pos !== '') {
					if ($check_diff($raw_rabbet_thick, $default_rabbet_thickness)) $is_modified = true;
					if ($check_diff($raw_rabbet_width, $default_rabbet_width)) $is_modified = true;
				}

				// Rabbet Position Comparison
				if ($check_diff($meta_rabbet_pos, $default_rabbet_pos)) $is_modified = true;

				// Relief Angle Comparison
				if ($check_diff($meta_relief_angle, $default_relief_angle)) $is_modified = true;

				// Back Relief Comparison
				if ($check_diff($meta_back_relief, $default_back_relief)) $is_modified = true;
			} // <--- NEW: Closes the skip check for Custom/Samples/Charges

			$item_data = [
				'product_id'    => $product_id,
				//'variation_id'  => $item->get_variation_id(),
				'name'          => $item->get_name(),
				'quantity'      => $item->get_quantity(),
				'subtotal'      => $order->get_item_subtotal($item, false, true), // Price per item, excluding tax, with discounts
				'subtotal_tax'  => $item->get_subtotal_tax(),
				'total'         => $order->get_item_total($item, false, true),    // Price per item, excluding tax, after discounts
				'total_tax'     => $item->get_total_tax(),
				'price'         => $order->get_item_total($item, true, true),     // Price per item, including tax, after discounts
				'meta_data'     => $item->get_meta_data(), // Get all meta data associated with the item (including cart item data)
				'is_modified' => $is_modified,
			];

			// Extract relevant meta_data if needed, or send all and process on Node.js side
			$formatted_meta_data = [];
			foreach ($item_data['meta_data'] as $meta) {
				$formatted_meta_data[$meta->key] = $meta->value;
			}

			// --- FIX: Ensure similar_profiles_data exists for the PDF ---
			if ( ! empty( $formatted_meta_data['similar_profiles'] ) && empty( $formatted_meta_data['similar_profiles_data'] ) ) {
				$profile_ids = explode( ' ', $formatted_meta_data['similar_profiles'] );
				$sim_data = [];

				foreach ( $profile_ids as $p_id ) {
					$p_id = trim( $p_id );
					if ( empty( $p_id ) ) continue;

					// Try to find product ID by SKU (which matches the profile number)
					$sim_product_id = wc_get_product_id_by_sku( $p_id );
					if ( $sim_product_id ) {
						$sim_product_obj = wc_get_product( $sim_product_id );
						if ( $sim_product_obj ) {
							$img_url = '';
							$img_id = $sim_product_obj->get_image_id();
							if ( $img_id ) {
								$img_src = wp_get_attachment_image_src( $img_id, 'medium' ); 
								$img_url = $img_src ? $img_src[0] : '';
							}
							
							// Only add if we found an image/valid product
							$sim_data[] = [
								'name'        => $sim_product_obj->get_name(),
								'product_url' => $sim_product_obj->get_permalink(),
								'image_url'   => $img_url
							];
						}
					}
				}

				if ( ! empty( $sim_data ) ) {
					$formatted_meta_data['similar_profiles_data'] = $sim_data;
				}
			}
			// --- END FIX ---

			// Logic: If it's NOT a sample and NOT a fee/charge, it's a standard product.
			if ( ! $is_sample && ! $is_charge ) {
				$has_standard_product = true;
			}

			/*if (isset($formatted_meta_data['sample']) && $formatted_meta_data['sample'] == true) {
				$formatted_meta_data['first_rabbet'] = get_field('1strabbetnotch', $product_id);
				$formatted_meta_data['first_rabbet_thickness_actual'] = get_field('1strabbetnotch_thickness', $product_id);
				$formatted_meta_data['first_rabbet_width_actual'] = get_field('1strabbetnotch_width', $product_id);
				$formatted_meta_data['reliefangle'] = get_field('relief_angle', $product_id);
				$formatted_meta_data['backrelief'] = get_field('back_relief', $product_id);
			}*/

			if (isset($formatted_meta_data['sample']) && $formatted_meta_data['sample'] == true) {
				// Fetch raw ACF values
				$raw_rabbet_pos   = get_field('1strabbetnotch', $product_id);
				$raw_relief_angle = get_field('relief_angle', $product_id);
				$raw_back_relief  = get_field('back_relief', $product_id);

				// 1. Format First Rabbet Position
				if ( empty($raw_rabbet_pos) || $raw_rabbet_pos === '0' ) {
					$formatted_meta_data['first_rabbet'] = 'OFF';
				} else {
					$formatted_meta_data['first_rabbet'] = '#' . $raw_rabbet_pos;
				}

				// Keep the actual thickness and width as they are fetched from ACF (already correct decimals)
				$formatted_meta_data['first_rabbet_thickness_actual'] = get_field('1strabbetnotch_thickness', $product_id);
				$formatted_meta_data['first_rabbet_width_actual'] = get_field('1strabbetnotch_width', $product_id);

				// 2. Format Relief Angle
				if ( $raw_relief_angle === '1' ) {
					$formatted_meta_data['reliefangle'] = 'ON';
				} else {
					$formatted_meta_data['reliefangle'] = 'OFF';
				}

				// 3. Format Back Relief
				if ( $raw_back_relief === '2' ) {
					$formatted_meta_data['backrelief'] = 'Rectangular Shape';
				} elseif ( $raw_back_relief === '4' ) {
					$formatted_meta_data['backrelief'] = 'Trapezoidal Shape';
				} else {
					$formatted_meta_data['backrelief'] = 'OFF';
				}
			}

			$item_data['meta_data'] = $formatted_meta_data;
			$order_data['cart_items'][] = $item_data;
		}

		// If we found NO standard products, then it is a "Samples Only" order.
        $order_data['is_samples_only'] = ! $has_standard_product;

		// Shipping and Billing Info
		$order_data['shipping_address'] = $order->get_address('shipping');
		$order_data['billing_address'] = $order->get_address('billing');
		$order_data['samples_shipping_address'] = $order->get_meta('_samples_full_shipping_address');

		// Shipping Method
		$shipping_methods = $order->get_shipping_methods();
		$order_data['shipping_method'] = [];
		foreach ($shipping_methods as $shipping_method) {
			// Start building the data array for the current shipping method
			$method_data = array(
				'method_id'    => $shipping_method->get_method_id(),
				'instance_id'  => $shipping_method->get_instance_id(),
				'method_title' => $shipping_method->get_method_title(),
				'total'        => $shipping_method->get_total(),
				'total_tax'    => $shipping_method->get_total_tax(),
			);

			// Check for and add the pickup_address meta data if it exists
			$pickup_address = $shipping_method->get_meta('pickup_address');
			if ( ! empty( $pickup_address ) ) {
				$method_data['pickup_address'] = $pickup_address;
			}

			// Add the complete method data to the order_data array
			$order_data['shipping_method'][] = $method_data;
		}

		// Customer Info
		$order_data['customer'] = array(
			'first_name' => $order->get_billing_first_name(),
			'last_name'  => $order->get_billing_last_name(),
			'email'      => $order->get_billing_email(), // Billing email is usually the primary contact email
			'user_id'    => $order->get_user_id(), // Get WordPress user ID if customer was logged in
		);

		// Costs
		$order_data['costs'] = array(
			//'subtotal'      => wc_get_order_item_totals('cart_subtotal', $order), // Subtotal before discounts and taxes
			'item_subtotal' => $order->get_subtotal(), // Subtotal after discounts, before taxes
			//'cart_tax'      => wc_get_order_item_totals('cart_tax', $order),
			'shipping_total' => $order->get_shipping_total(),
			'shipping_tax'  => $order->get_shipping_tax(),
			'fee_total'     => $order->get_total_fees(),
			//'fee_tax'       => $order->get_fee_tax(),
			'total_tax'     => $order->get_total_tax(), // Total tax for the order
			'grand_total'   => $order->get_total(),
			'discount_total' => $order->get_discount_total(),
			'discount_tax'  => $order->get_discount_tax(),
		);

		// Add Order ID for reference
		$order_data['order_id'] = $order_id;

		// Add Order Status
		$status = $order->get_status();
		$order_data['order_status'] = $status;

		// Add Starke Order Number
		$quote_statuses = ['active-quote', 'expired-quote', 'pending-quote', 'freight-quote', 'deleted-quote', 'ordered-quote']; // Define your quote statuses
		if (in_array($status, $quote_statuses)) {
			$prefix = 'Q'; // Prefix for quote statuses
			$order_data['is_quote'] = true;

			if ($status === 'active-quote') {
				$order_data['is_active_quote'] = true;
				$expiration_timestamp = $order->get_meta('_quote_expiration_date');
				if ($expiration_timestamp) {
					$order_data['quote_expiration_date'] = intval($expiration_timestamp);
				}
			} else {
				$order_data['is_active_quote'] = false;
			}
		} else {
			$prefix = ''; // No prefix for order statuses
			$order_data['is_quote'] = false;
			$order_data['is_active_quote'] = false;
		}

		$order_data['starke_order_number'] = $prefix . $order->get_meta('_starke_order_number', true);
		$order_data['cc_emails'] = $order->get_meta('_cc_emails');

		// --- START FIX: Conditional Jobsite & Secondary Info for Samples Only ---
		if ( isset($order_data['is_samples_only']) && $order_data['is_samples_only'] ) {
			// If Samples Only, these fields must be empty
			$order_data['jobsite_contact'] = '';
			$order_data['jobsite_contact_cell_number'] = '';
			$order_data['samples_address_po_number_job_name'] = '';
		} else {
			// Standard or Mixed Order behavior
			$order_data['jobsite_contact'] = $order->get_meta('_jobsite_contact');
			$order_data['jobsite_contact_cell_number'] = $order->get_meta('_jobsite_contact_cell_number');
			$order_data['samples_address_po_number_job_name'] = $order->get_meta('_samples_address_po_number_job_name');
		}
		// Primary PO/Job Name is always used
		$order_data['po_number_job_name'] = $order->get_meta('_po_number_job_name');
		// --- END FIX ---
		$order_data['quote_link'] = generate_link_for_quote($order);
		$order_data['quote_expiration_date'] = $order->get_meta('_quote_expiration_date');
		$order_data['order_date'] = $order->get_date_created() ? wc_format_datetime( $order->get_date_created(), 'm/d/Y' ) : '';
		$order_data['starke_natural_total'] = $order->get_meta('_starke_natural_total', true);
		$order_data['starke_deferred_balance']= $order->get_meta('_starke_deferred_balance', true);
		$order_data['starke_payment_terms'] = $order->get_meta('_starke_payment_terms', true);

		// --- UPDATED: Calculate Initial Balance Due (Context Aware) ---
		$initial_balance_val = 0.0;
		
		// 1. Start with Payment Method Check (Default assumption)
		$payment_method = $order->get_payment_method();
		$offline_methods = array( 'check', 'bacs', 'cheque' );
		$is_paid_upfront = ! in_array( $payment_method, $offline_methods );

		// 2. CHECK STATUS: Is the Original Order Paid?
        // This is the key fix. If you manually set it to Processing/Completed, 
        // we override the "Check" logic and treat it as paid (Initial Due = $0).
		if ( $order->is_paid() ) {
			$is_paid_upfront = true;
		}

		// 3. Calculate Initial Due (Strictly for the Original Order)
		if ( ('50_50' === $order_data['starke_payment_terms'] || 'terms_50_50' === $order_data['starke_payment_terms']) ) {
			
			// If paid upfront (Credit Card OR Status=Paid), Initial Due is 0. 
			// If NOT paid upfront (Status=On Hold + Check), Initial Due is 50%.
			if ( ! $is_paid_upfront ) {
                
                // FIX: Use the Centralized Helper to get the exact rounded values
                if ( class_exists( 'Starke_Payment_Manager' ) ) {
                    $splits = Starke_Payment_Manager::get_payment_splits( $order );
                    $initial_balance_val = $splits['required_deposit'];
                } else {
                    // Fallback: Replicate the rounding logic manually if class is missing
                    $nat_total = (float) $order_data['starke_natural_total'];
                    if ( $nat_total <= 0 ) {
                        $nat_total = (float) $order->get_total();
                    }
                    // Round up to 2 decimals
                    $initial_balance_val = round( $nat_total / 2, 2 );
                }
			}
		}
		$order_data['initial_balance_due'] = ( $initial_balance_val > 0.01 ) ? $initial_balance_val : '';
		$order_data['financials'] = $this->get_pdf_financial_data( $order_id );

		// --- FIX: STRICT BALANCE SEPARATION (HIDE IF PAID) ---
		
		// 1. Get Balance Invoice ID
		$balance_invoice_id = $order->get_meta( '_starke_balance_order_id', true );
		$balance_is_paid = false;

		if ( $balance_invoice_id ) {
			clean_post_cache( $balance_invoice_id );
			$balance_invoice = wc_get_order( $balance_invoice_id );
			if ( $balance_invoice && $balance_invoice->is_paid() ) {
				$balance_is_paid = true;
			}
		}

		// 2. Logic: If Paid OR if value is 0, set to Empty String ('') to hide row.
		if ( $balance_is_paid ) {
			$order_data['starke_deferred_balance'] = '';
		} elseif ( isset($order_data['starke_deferred_balance']) && (float)$order_data['starke_deferred_balance'] <= 0.01 ) {
			$order_data['starke_deferred_balance'] = '';
		}

		wc_get_logger()->warning('$order_data: ' . print_r($order_data, true), ['source' => 'pdf_debug23']);

		// --- Prepare and Send HTTP Request to Generate PDF---

		// Was hardcoded to Vern's own internal AWS load balancer address, only reachable
		// from inside his AWS VPC, could never work from any other environment. Now
		// configurable per-environment via wp-config.php; defaults to a local dev
		// instance of Starke3DPDFGenerator if not otherwise defined.
		$pdf_generator_url = defined( 'STARKE_PDF_GENERATOR_URL' )
			? STARKE_PDF_GENERATOR_URL
			: 'http://localhost:8081/generate-pdf';

		$args = array(
			'body'    => json_encode($order_data),
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			// This call BLOCKS waiting for the droplet to finish rendering and
			// uploading before it returns, and _pdf_s3ObjectKey is only saved to
			// the order below if this call succeeds. Real generation time on the
			// current DigitalOcean droplet (software-rendered WebGL, no real GPU,
			// occasional memory swapping) has been observed at 30-90+ seconds.
			// The old 60s value meant WordPress itself would give up and error
			// out before slower renders finished, even though the droplet kept
			// working and uploaded the PDF successfully moments later, the order
			// meta was just never updated to point at it. Raised 2026-08-29 to
			// stay safely above the quote-pdf/order-pdf download page's own
			// 150-second poll window (see quote-order.php), so that page's wait
			// is always the shorter, blocking one, not the reverse.
			'timeout' => 170,
			'method'  => 'POST',
			'data_format' => 'body',
		);
		$response = wp_remote_post($pdf_generator_url, $args);

		// --- Handle the Response ---
		if (is_wp_error($response)) {
			$error_message = $response->get_error_message();
			// Log the error if the request failed
			//error_log("PDF Generator HTTP Request Error: " . $error_message . " for order ID " . $order_id);
		} else {
			$body = wp_remote_retrieve_body($response);
			$http_code = wp_remote_retrieve_response_code($response);

			// You might want to check the HTTP status code (e.g., 200 for success)
			if ($http_code === 200 || $http_code === 201) {
				// Request was successful
				// If Node.js app returns the S3 Object Key, save it to the order meta data here
				$response_data = json_decode($body, true);
				if (isset($response_data['s3ObjectKey'])) {
				    $order->update_meta_data('_pdf_s3ObjectKey', $response_data['s3ObjectKey']);
				    $order->save();
					
					// Conditionally email the customer
					if ( $email_customer && $email_class ) {
						as_enqueue_async_action(
							//time(), // Schedule to run immediately (Action Scheduler's perspective)
							'email_order_quote_to_customer_async', // Your custom hook for email sending
							[
								'order_id'    => $order_id,
								'email_class' => $email_class
							],
							'quote_email_group', // Optional: Group for email actions
							//1 // Default priority is fine for email
						);
                        
                        // --- NEW: Also trigger the Admin New Order Email now that the PDF is ready ---
                        // Only send the Admin New Order email for actual orders (Processing/On-Hold), not quotes.
                        if ( $email_class === 'WC_Email_Customer_Processing_Order' || $email_class === 'WC_Email_Customer_On_Hold_Order' ) {
                            as_enqueue_async_action(
                                'email_order_quote_to_customer_async', // We reuse your custom Action Scheduler hook
                                [
                                    'order_id'    => $order_id,
                                    'email_class' => 'WC_Email_New_Order' // Triggers the WooCommerce Admin email!
                                ],
                                'quote_email_group'
                            );
                        }
					}
				}
			} else {
				// Request failed with a non-200 status code
				wc_get_logger()->warning("PDF Generator HTTP Request Failed: Status Code " . print_r($http_code, true) . ", Response Body: " . print_r($body, true) . " for order ID " . print_r($order_id, true), ['source' => 'pdf_debug1']);
			}
		}
	}

	public function handle_generate_3d_pdf_and_send_email_for_order_quote( $order_id, $send_email_to_customer, $email_class ) {
		$ajax_url = admin_url('admin-ajax.php');
		$nonce = wp_create_nonce('3d_pdf_and_email_generation_nonce'); // Create a nonce for security

		// Prepare data for the background process
		$post_data = [
			'action'         => 'generate_3d_pdf_and_send_email_for_order_quote', // The AJAX hook name
			'order_id'       => $order_id,
			'email_customer' => $send_email_to_customer,
			'email_class'    => $email_class,
			'_wpnonce'       => $nonce
		];

		// Initiate the non-blocking background request
		$response = wp_remote_post( $ajax_url, [
			'method'      => 'POST',
			'timeout'     => 0.01, // Very short timeout to ensure non-blocking
			'blocking'    => false, // Crucial: Do not wait for the response
			//'sslverify'   => apply_filters('https_local_ssl_verify', false), // Adjust for your SSL setup (Change this if  internal Node.js 3D PDF app internal Beanstalk environment adds HTTPS connections allowed)
			'body'        => $post_data,
			'headers' => [
				'Connection' => 'close', // Tell the server to close connection immediately after receiving headers
			],
			'cookies' => $_COOKIE
		]);

		// Error logging for initiation (if the request couldn't even be sent)
		if ( is_wp_error( $response ) ) {
			wc_get_logger()->warning('$Failed1 : ' . print_r($response->get_error_message(), true), ['source' => 'quote_debug11']);
		}
	}

	private function setup_generate_3d_pdf_and_send_email_for_order_quote() {
		add_action('wp_ajax_generate_3d_pdf_and_send_email_for_order_quote', [$this, 'generate_3d_pdf_and_send_email_for_order_quote']);
		add_action('wp_ajax_nopriv_generate_3d_pdf_and_send_email_for_order_quote', [$this, 'generate_3d_pdf_and_send_email_for_order_quote']);
	}
	public function generate_3d_pdf_and_send_email_for_order_quote() {
		// Log the received nonce
		$received_nonce = isset($_POST['_wpnonce']) ? $_POST['_wpnonce'] : 'NONCE_NOT_SET';

		// Explicitly call wp_verify_nonce and log its result
		$nonce_verified = wp_verify_nonce($received_nonce, '3d_pdf_and_email_generation_nonce');

			// --- IMPORTANT: Security Checks ---
		// 1. Verify Nonce (sent from wp_remote_post)
		if ( !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], '3d_pdf_and_email_generation_nonce') ) {
			error_log('VernShippingBlock: Security check failed for PDF generation AJAX. IP: ' . $_SERVER['REMOTE_ADDR']);
			wp_die(); // Just die, no JSON response needed as caller is not waiting
		}

		// 2. Validate Order ID and other data
		if ( !isset($_POST['order_id']) ) {
			error_log('VernShippingBlock: Missing order ID for PDF generation AJAX.');
			wp_die();
		}

		$order_id = strval($_POST['order_id']);
		$email_customer = isset($_POST['email_customer']) ? filter_var($_POST['email_customer'], FILTER_VALIDATE_BOOLEAN) : false;
		$email_class = strval($_POST['email_class']);

		$order = wc_get_order($order_id);
		if (!$order) {
			error_log('VernShippingBlock: Could not find order with ID: ' . $order_id . ' for PDF generation AJAX.');
			wp_die();
		}

		// --- Your Actual PDF Generation and S3 Upload Logic ---
		// This is the same code you had inside your Action Scheduler function.
		// Ensure the methods are public now if they weren't already.

		if ( class_exists('VernShippingBlock_Extend_Woo_Core') && method_exists('VernShippingBlock_Extend_Woo_Core', 'get_instance') ) {
			$extend_core = VernShippingBlock_Extend_Woo_Core::get_instance(); // Get your plugin's main instance
			// Call the PDF generation method
			if ( method_exists($extend_core, 'generate_order_quote_3d_pdf') ) {
				$extend_core->generate_order_quote_3d_pdf( $order_id, $email_customer, $email_class );
			} else {
				error_log(__FUNCTION__ . ': Method generate_order_quote_3d_pdf does not exist in VernShippingBlock_Extend_Woo_Core.');
			}
		} else {
			error_log(__FUNCTION__ . ': VernShippingBlock_Extend_Woo_Core or get_instance method not found for async PDF/Email processing. Check class name and instantiation.');
		}

		// Important: Just wp_die() here. No JSON response needed as the caller isn't waiting.
		wp_die();
	}

	// Initiates sending the email and 3D PDF specifically for orders
	private function setup_send_order_email_and_pdf() {
		add_action( 'woocommerce_order_status_on-hold', [$this, 'send_order_email_and_pdf'], 10, 1 );
		add_action( 'woocommerce_order_status_processing', [$this, 'send_order_email_and_pdf'], 10, 1 );
	}

	public function send_order_email_and_pdf($order_id) {
		$order = wc_get_order($order_id);
		if (!$order) return;

        // --- NEW: BLOCK ON-HOLD EMAIL FOR NET 30 ---
        // Since Net 30 orders are instantly moved to 'Processing', we intercept 
        // and kill the momentary 'On-Hold' trigger so the customer doesn't get duplicate emails.
        $status = $order->get_status();
        $term   = $order->get_meta( '_starke_payment_terms', true );
        
        if ( $status === 'on-hold' && 'net_30' === $term ) {
            return; // Abort completely!
        }

		$email_order_to_customer = true;
		
		// Dynamically set the email class based on current status
		$email_class = ($status === 'processing') ? 'WC_Email_Customer_Processing_Order' : 'WC_Email_Customer_On_Hold_Order';
		
		$this->handle_generate_3d_pdf_and_send_email_for_order_quote($order_id, $email_order_to_customer, $email_class);
	}

	// Stores/Updates the array of CC email addresses in session (an extensionCartUpate function)
	public function update_cc_emails_in_session( $data ) {
		if ( ! class_exists( 'WC_Session' ) || ! WC()->session ) {
			WC()->initialize_session(); // Ensure session is available
		}
		if ( ! WC()->session ) {
			return;
		}

		if ( isset( $data['cc_emails'] ) && is_array( $data['cc_emails'] ) ) {
			$filtered_emails = array_values( array_filter( $data['cc_emails'], function( $email ) {
				return is_string( $email ) && ! empty( trim( $email ) );
			} ) );

			// Slice the array to ensure we only keep a maximum of 5 emails.
        	$final_emails = array_slice( $filtered_emails, 0, 5 );
			WC()->session->set( 'cc_emails', $final_emails );
		}
	}

	private function save_cc_emails_for_checkout_order(){
		add_action('woocommerce_store_api_checkout_order_processed', function($order) {
			$this->save_cc_emails_to_order_quote_meta($order);
		}, 10, 1);
	}
	
	private function save_cc_emails_to_order_quote_meta($order){
		if (WC()->session && method_exists(WC()->session, 'get')) {
			$cc_emails = WC()->session->get('cc_emails', []);
			if (is_array($cc_emails)) {
				$valid_ccs = array_filter(array_map('sanitize_email', $cc_emails), 'is_email');
				$order->update_meta_data('_cc_emails', $valid_ccs);
			}
		}
	}

	private function maybe_add_cc_headers_for_order_quote() {
		add_filter('woocommerce_email_headers', function($headers, $email_id, $order) {
			// Whitelist all specific customer-facing email configurations triggered during checkout/generation
			$target_emails = [
				'customer_quote_sending',
				'customer_on_hold_order',
				'customer_processing_order' // <-- FIXED: Added to catch orders skipping straight to processing
			];

			if ( in_array( $email_id, $target_emails, true ) ) {
				if (! $order instanceof WC_Order) {
					return $headers;
				}

				$cc_emails = $order->get_meta('_cc_emails', true);
				if (! is_array($cc_emails)) {
					return $headers;
				}

				$valid_ccs = array_filter(array_map('sanitize_email', $cc_emails), 'is_email');
				if (! empty($valid_ccs)) {
					$headers .= 'Cc: ' . implode(', ', $valid_ccs) . "\r\n";
				}
			}
			return $headers;
		}, 9999, 3);
	}

	// Set the primary shipping method to empty (an extensionCartUpate function)
	//public function trigger_cart_update( $data ) {
	//	// Currently just triggers the cart update
	//}

	/**
	 * Schedules the Freight Quote Admin Notification to be sent asynchronously.
	 */
	public function send_freight_quote_admin_notification( $order ) {
		if ( ! $order ) {
			return;
		}

		// Schedule the action to run immediately (via WP Cron / Action Scheduler)
		as_schedule_single_action(
			time(), 
			'send_freight_quote_admin_notification_async', 
			array( $order->get_id() ), 
			'freight-quote-emails' 
		);
	}

	/**
	 * worker: Actually sends the Freight Quote Admin Notification (Async).
	 */
	public function process_freight_quote_admin_notification_async( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// 1. Get all administrator emails.
		$admins = get_users( 'role=administrator' );
		$admin_emails = array();
		foreach ( $admins as $admin ) {
			$admin_emails[] = $admin->user_email;
		}

		// 2. Define the list of admin emails to exclude.
		$excluded_emails = ['rath7v@gmail.com']; // ['danielle@starkemillwork.com', 'zac@starkemillwork.com', 'gretchen@starkemillwork.com']

		// 3. Remove the excluded emails from the recipient list.
		$recipient_emails = array_diff( $admin_emails, $excluded_emails );

		if ( empty( $recipient_emails ) ) {
			return; // No recipients left, so stop.
		}

		$starke_number = $order->get_meta('_starke_order_number', true);
		$subject = sprintf( 'New Freight Quote Request - Q%s', $starke_number );
		$heading = 'Freight Quote Request';
		
		$customer_name = $order->get_formatted_billing_full_name();
		// Append our custom interceptor flag to the standard HPOS edit order URL
		$order_link = add_query_arg( 'starke_auto_impersonate', '1', $order->get_edit_order_url() );

		// 4. Construct the Body HTML (Updated Layout)
		ob_start();
		?>
		<div style="font-size: 18px !important; line-height: 1.5; color: #636363; margin-bottom: 20px;">
			<?php esc_html_e( 'A new freight quote has been requested by a customer.', 'vern_shipping_block' ); ?>
		</div>

		<div style="font-size: 24px !important; line-height: 1.4; margin-bottom: 25px; text-align: center; color: #636363;">
			<strong><?php esc_html_e( 'Quote:', 'vern_shipping_block' ); ?></strong> 
			<a href="<?php echo esc_url( $order_link ); ?>" style="color: #6431F6; text-decoration: underline; font-weight: bold; margin-left: 5px;">Q<?php echo esc_html( $starke_number ); ?></a>
		</div>

		<div style="text-align: center;">
			<h2 style="color: #6431F6; margin-top: 20px; font-size: 16px; margin-bottom: 10px;"><?php esc_html_e( 'Customer Information', 'vern_shipping_block' ); ?></h2>
			
			<div style="display: inline-block; text-align: left; width: 90%; max-width: 400px; margin-bottom: 20px; padding: 15px; background-color: #f9f9f9; border: 1px solid #e5e5e5; border-radius: 4px;">
				<ul style="list-style: none; padding: 0; margin: 0; font-size: 18px;">
					<li style="margin-bottom: 10px;"><strong><?php esc_html_e( 'Customer:', 'vern_shipping_block' ); ?></strong> <?php echo esc_html( $customer_name ); ?></li>
					<li><strong><?php esc_html_e( 'Quote Number:', 'vern_shipping_block' ); ?></strong> Q<?php echo esc_html( $starke_number ); ?></li>
				</ul>
			</div>
		</div>

		<div style="text-align: center; margin-top: 20px;">
			<a href="<?php echo esc_url( $order_link ); ?>" style="color: #6431F6; font-size: 18px; font-weight: bold; text-decoration: underline;">
				<?php esc_html_e( 'View Quote Details', 'vern_shipping_block' ); ?>
			</a>
		</div>
		<?php
		$content = ob_get_clean();

		// 5. Load Standard WC Templates
		$header = wc_get_template_html( 'emails/email-header.php', array( 'email_heading' => $heading ) );
		$footer = wc_get_template_html( 'emails/email-footer.php' );
		
		$final_message = $header . $content . $footer;

		// 6. Apply Inline Styles
		try {
			$mailer = WC()->mailer();
			$email_object = $mailer->get_emails()['WC_Email_Customer_Note'] ?? null;

			if ( $email_object && method_exists( $email_object, 'style_inline' ) ) {
				$final_message = $email_object->style_inline( $final_message );
			}
		} catch ( Exception $e ) {
			if ( function_exists('wc_get_logger') ) {
				wc_get_logger()->warning('Freight Email Style Error: ' . $e->getMessage());
			}
		}

		// 7. Send the email
		add_filter( 'wp_mail_content_type', array( $this, 'set_html_content_type' ) );
		wp_mail( $recipient_emails, $subject, $final_message );
		remove_filter( 'wp_mail_content_type', array( $this, 'set_html_content_type' ) );
	}

	/**
	 * Stores/Updates the LTL Freight cost to the session (an extensionCartUpdate function)
	 */
	public function update_ltl_freight_cost( $data ) {
		if ( ! class_exists( 'WC_Session' ) || ! WC()->session ) {
			WC()->initialize_session();
		}
		if ( ! WC()->session ) {
			return;
		}

		// Only allow impersonating admins to change the cost.
		if ( (function_exists('impersonation_is_active') && impersonation_is_active()) || current_user_can('manage_woocommerce') ) {
			if ( isset( $data['ltl_freight_cost'] ) ) {
				$cost_value = $data['ltl_freight_cost'];
				
				// Sanitize the input. Remove everything except numbers and a decimal point.
				$sanitized_cost = preg_replace( '/[^0-9.]/', '', $cost_value );
				$validated_cost = filter_var($sanitized_cost, FILTER_VALIDATE_FLOAT);

				if ($validated_cost !== false) {
					// If it's a valid number, save it to the session.
					WC()->session->set( 'ltl_freight_cost', $validated_cost );
				} else {
					// If the input is empty or invalid, remove it from session to revert to default.
					WC()->session->__unset( 'ltl_freight_cost' );
				}
			}
		}
	}

	/**
	 * Sets the active payment method into the built-in WooCommerce session variable. (an extensionCartUpdate function)
	 */
	/*public function update_chosen_payment_method( $data ) {
		if ( isset( $data['payment_method'] ) ) {
			$payment_method = sanitize_text_field( $data['payment_method'] );
			WC()->session->set( 'chosen_payment_method', $payment_method );
		}
	}*/

	/**
	 * Updates the official profile number for a specific cart item in the session. (an extensionCartUpdate function)
	 */
	public function update_official_profile_number( $data ) {
		if ( ! WC()->cart || ! isset( $data['cart_item_key'] ) ) {
			return;
		}

        // Only admins or impersonating users can perform this action.
        if ( ! ( ( function_exists('impersonation_is_active') && impersonation_is_active() ) || current_user_can('manage_woocommerce') ) ) {
            return;
        }

		$cart_item_key = sanitize_text_field( $data['cart_item_key'] );
		$profile_number = isset( $data['official_profile_number'] ) ? sanitize_text_field( $data['official_profile_number'] ) : '';

		$cart_contents = WC()->cart->get_cart_contents();

		if ( isset( $cart_contents[ $cart_item_key ] ) ) {
			$cart_contents[ $cart_item_key ]['official_profile_number'] = $profile_number;
			WC()->cart->set_cart_contents( $cart_contents );
		}
	}

    /**
     * Hooks our timestamp correction function into the checkout process.
     */
    private function fix_order_timestamp_on_checkout() {
        add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'update_order_creation_date' ), 10, 1 );
    }

    /**
     * Updates the order's creation date to the current time upon successful checkout.
     * This corrects the timestamp from the initial draft order creation.
     *
     * @param \WC_Order $order The order object that has just been processed.
     */
    public function update_order_creation_date( $order ) {
        // Ensure we have a valid order object.
        if ( $order && $order instanceof WC_Order ) {
            
            // Create a new WC_DateTime object with the current UTC timestamp.
            // This ensures the time is correct regardless of server or WordPress timezone settings.
            $current_utc_time = new WC_DateTime();
            
            // Update the 'date_created' property on the order object to the actual time of purchase.
            $order->set_date_created( $current_utc_time );
            
            // Save the changes to the database. The save() method will also update the 'date_modified' timestamp.
            $order->save();
        }
    }

    // ADD this entire private function
    private function register_sample_request_endpoint() {
        add_action('rest_api_init', function () {
            register_rest_route('vern-shipping-block/v1', '/request-sample', array(
                'methods'  => 'POST',
                'callback' => array($this, 'handle_sample_restock_request'),
                /**
                 * THIS IS THE CRUCIAL FIX: This permission check explicitly
                 * verifies the nonce from the header, solving the 403 error.
                 */
                'permission_callback' => function( WP_REST_Request $request ) {
                    // Block guests immediately.
                    if ( ! is_user_logged_in() ) {
                        return new WP_Error( 'rest_not_logged_in', 'You must be logged in to request a sample.', array( 'status' => 401 ) );
                    }
                    $nonce = $request->get_header( 'x_wp_nonce' );
                    if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
                        return new WP_Error( 'rest_cookie_invalid_nonce', 'Cookie check failed', array( 'status' => 403 ) );
                    }
                    return true;
                },
            ));
        });
    }

    /**
     * This function will now schedule the email to be sent by your WP-CLI cron job.
     */
    public function handle_sample_restock_request(WP_REST_Request $request) {
        if (is_null(WC()->session)) {
            WC()->initialize_session();
        }

        $params = $request->get_json_params();
        $product_id = isset($params['product_id']) ? absint($params['product_id']) : 0;

        if (empty($product_id)) {
            return new WP_Error('no_product_id', 'Product ID is required.', ['status' => 400]);
        }

        $requested_samples = WC()->session->get('sample_requests', []);
        if (!is_array($requested_samples)) {
            $requested_samples = [];
        }
        
        if (in_array($product_id, $requested_samples)) {
            return new WP_REST_Response(['success' => true, 'message' => 'Already requested.'], 200);
        }

        // --- CAPTURE USER ID (Make sure this runs BEFORE scheduling) ---
        $user_id = get_current_user_id();

        // --- SCHEDULE THE EMAIL FOR THE NEXT CRON RUN ---
        // UPDATED: We pass arguments as a simple indexed array for better stability in background tasks
        as_schedule_single_action(
            time(), // Schedule for right now
            'send_sample_restock_email_async', // The hook our background function will use
            array( $product_id, $user_id ), // Pass as simple, separate arguments
            'sample-restock-notifications' // A group name for organization
        );

        $requested_samples[] = $product_id;
        WC()->session->set('sample_requests', $requested_samples);
        WC()->session->save_data(); 
        WC()->session->set_customer_session_cookie(true);

        return new WP_REST_Response(['success' => true, 'message' => 'Notification scheduled.'], 200);
    }

    /**
     * UPDATED: Sends the Sample Restock Email
     * - Uses wc_get_template_html for consistent header/footer.
     * - FIXES FATAL ERROR: Uses a specific Email Object to call style_inline().
     */
    public function send_sample_restock_email_async( $product_id_arg = 0, $user_id_arg = 0 ) {
        // 1. Sanitize Arguments
        $product_id = absint( $product_id_arg );
        $user_id    = absint( $user_id_arg );
        
        if ( empty( $product_id ) ) {
            return; 
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return;
        }

        // 2. Get Recipients
        $admins = get_users( [ 'role__in' => [ 'administrator' ] ] );
        $recipient_emails = [];
        foreach ( $admins as $admin ) {
            $recipient_emails[] = $admin->user_email;
        }
        $excluded_emails = ['rath7v@gmail.com']; // ['danielle@starkemillwork.com', 'zac@starkemillwork.com']
        $recipient_emails = array_diff( $recipient_emails, $excluded_emails );

        if ( empty( $recipient_emails ) ) {
            return;
        }

        // 3. Prepare Data
        $product_name = $product->get_name();
        $product_link = admin_url( 'post.php?post=' . $product_id . '&action=edit' );
        $subject      = sprintf( 'Restock Request for Sample: %s', $product_name );
        $heading      = 'Sample Restock Request';

        // 4. Prepare Customer Data
        $user_info_html = '<li>Guest / Unknown User</li>';
        if ( $user_id ) {
            $user = get_userdata( $user_id );
            if ( $user ) {
                // Generate the Admin Edit Link
                $edit_user_link = admin_url( 'user-edit.php?user_id=' . $user_id );

                $user_name = !empty( $user->display_name ) ? $user->display_name : $user->user_login;
                $user_info_html  = '<li><strong>Name:</strong> ' . esc_html( $user_name ) . '</li>';
                $user_info_html .= '<li><strong>Email:</strong> <a href="mailto:' . esc_attr( $user->user_email ) . '">' . esc_html( $user->user_email ) . '</a></li>';
                $user_info_html .= '<li><strong>User ID:</strong> ' . esc_html( $user_id ) . '</li>';
                
                // --- UPDATED: Reverted to Text-Only Link (Purple) ---
                $user_info_html .= '<li style="margin-top: 15px; list-style: none;">';
                $user_info_html .= '<a href="' . esc_url( $edit_user_link ) . '" style="color: #6431F6; font-size: 18px; font-weight: bold; text-decoration: underline;">View User Profile</a>';
                $user_info_html .= '</li>';
            }
        }

        // 5. Construct the Body HTML
        ob_start();
        ?>
        <div style="font-size: 18px !important; line-height: 1.5; margin-bottom: 20px; color: #636363;">
            <?php esc_html_e( 'A customer has requested a restock for the following sample profile:', 'woocommerce' ); ?>
        </div>

        <?php 
        // A. Product Image
        $image_id = $product->get_image_id();
        if ( $image_id ) :
            $image_url = wp_get_attachment_url( $image_id );
            ?>
            <div style="margin-bottom: 20px; text-align: center;">
                <a href="<?php echo esc_url( $product_link ); ?>" style="text-decoration:none; display: inline-block;">
                    <img src="<?php echo esc_url( $image_url ); ?>" style="max-width: 100%; width: 250px; height: auto; border: 1px solid #e5e5e5; border-radius: 4px;" alt="Product Image" />
                </a>
            </div>
        <?php endif; ?>

        <div style="font-size: 24px !important; line-height: 1.4; margin-bottom: 25px; text-align: center; color: #636363;">
            <strong>Profile:</strong> <a href="<?php echo esc_url( $product_link ); ?>" style="color: #6431F6; text-decoration: underline; font-weight: bold; margin-left: 5px;"><?php echo esc_html( $product_name ); ?></a>
        </div>

        <div style="text-align: center;">
            <h2 style="color: #6431F6; margin-top: 20px; font-size: 16px; margin-bottom: 10px;">Customer Information</h2>
            
            <div style="display: inline-block; text-align: left; width: 90%; max-width: 400px; margin-bottom: 20px; padding: 15px; background-color: #f9f9f9; border: 1px solid #e5e5e5; border-radius: 4px;">
                <ul style="list-style: none; padding: 0; margin: 0; font-size: 14px;">
                    <?php echo $user_info_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </ul>
            </div>
        </div>

        <?php
        $content = ob_get_clean();

        // 6. Load Standard WC Templates
        $header = wc_get_template_html( 'emails/email-header.php', array( 'email_heading' => $heading ) );
        $footer = wc_get_template_html( 'emails/email-footer.php' );
        
        $final_message = $header . $content . $footer;

        // 7. Apply Inline Styles (FIXED LOGIC)
        try {
            // Get the Mailer instance
            $mailer = WC()->mailer();
            
            // FIX: Grab a specific email object (Customer Note) to handle the styling
            $email_object = $mailer->get_emails()['WC_Email_Customer_Note'] ?? null;

            if ( $email_object && method_exists( $email_object, 'style_inline' ) ) {
                $final_message = $email_object->style_inline( $final_message );
            }
        } catch ( Exception $e ) {
            if ( function_exists('wc_get_logger') ) {
                wc_get_logger()->warning('Sample Email Style Error: ' . $e->getMessage());
            }
        }

        // 8. Send
        add_filter( 'wp_mail_content_type', array( $this, 'set_html_content_type' ) );
        wp_mail( $recipient_emails, $subject, $final_message );
        remove_filter( 'wp_mail_content_type', array( $this, 'set_html_content_type' ) );
    }

    /**
     * NEW: A dedicated, named method to set the email content type to HTML.
     * This is the best practice for filters that need to be removed.
     * @return string
     */
    public function set_html_content_type() {
        return 'text/html';
    }

	/**
	 * Updates the selected payment terms in the session.
	 * Triggered via extensionCartUpdate from the frontend block.
	 */
	public function update_payment_terms( $data ) {
		if ( ! class_exists( 'WC_Session' ) || ! WC()->session ) {
			WC()->initialize_session();
		}
		
		wc_get_logger()->warning('$data[selected_term]: ' . print_r($data['selected_term'], true), ['source' => 'terms_debug1']);

		// Sanitize and save to session
		if ( isset( $data['selected_term'] ) ) {
			$valid_terms = ['no_terms', '50_50', 'net_30'];
			$term = in_array( $data['selected_term'], $valid_terms ) ? $data['selected_term'] : 'no_terms';
			
			WC()->session->set( 'starke_payment_terms', $term );
		}
	}

	/**
	 * HELPER: Get Financial Data for 3D PDF (Statement of Account)
	 * UPDATED: Forces Fresh Cache for both Original & Balance Orders to ensure accuracy.
	 */
	public function get_pdf_financial_data( $order_id ) {
		// 1. FORCE FRESH ORIGINAL ORDER
		clean_post_cache( $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order ) return [];

		// 2. Use the Centralized Helper for Terms/Math
		if ( ! class_exists( 'Starke_Payment_Manager' ) ) {
			return []; // Safety check
		}
		$splits = Starke_Payment_Manager::get_payment_splits( $order );

		$history = [];
		$total_principal_paid = 0;
		
		// ---------------------------------------------------------
		// A. Initial Payment (The Parent Order)
		// ---------------------------------------------------------
		// Check the FRESH status of the original order.
		if ( $order->is_paid() ) {
			$amount = $splits['required_deposit'];

			// Only add history if there was actually a deposit required (skip Net 30)
			if ( $amount > 0.01 ) {
				$date_str = $order->get_date_paid() ? wc_format_datetime( $order->get_date_paid(), 'm/d/y' ) : wp_date('m/d/y');
				$history[] = [
					'date'   => $date_str,
					'desc'   => 'Initial Payment',
					'amount' => $amount,
				];
				$total_principal_paid += $amount;
			}
		}

		// ---------------------------------------------------------
		// B. Balance Payment (The Invoice)
		// ---------------------------------------------------------
		$balance_invoice_id = $order->get_meta( '_starke_balance_order_id', true );
		
        if ( $balance_invoice_id ) {
			
            // --- FIX: Force Clean Cache for Balance Invoice ---
            // This ensures we see the updated status immediately, especially during manual admin updates.
            clean_post_cache( $balance_invoice_id );
            
            $balance_order = wc_get_order( $balance_invoice_id );
			
			// Check if the balance invoice exists and is paid
			if ( $balance_order && $balance_order->is_paid() ) {
				$desc = 'Balance Payment';
				$txn_amount = (float)$balance_order->get_total();
				
				// --- FIX START: Decouple Fee Calculation from Deposit Status ---
				// Instead of checking "Project Total - Amount Paid So Far" (which breaks if Deposit is On Hold),
				// We compare the Transaction Amount strictly against the Deferred Balance (The Principal).
				
				$invoice_principal = $splits['deferred_balance'];
				
				// Safety: If deferred balance is 0 (e.g. Net 30 or Standard), fallback to standard remaining math
				if ( $invoice_principal <= 0.01 ) {
				    $invoice_principal = $splits['project_total'] - $total_principal_paid;
				}

				$fee_amount = $txn_amount - $invoice_principal;
				
				if ( $fee_amount > 0.05 ) {
                    $formatted_fee = html_entity_decode( strip_tags( wc_price( $fee_amount ) ) );
                    $desc .= ' (Incl. ' . $formatted_fee . ' Fee)'; 
                    
                    // Only add the Principal to the Total Paid (Excluding the Fee)
                    $total_principal_paid += $invoice_principal;
				} else {
				    // No fee detected: Add the full transaction amount
                    $total_principal_paid += $txn_amount;
				}
				// --- FIX END ---

				$history[] = [
					'date'   => $balance_order->get_date_paid() ? wc_format_datetime( $balance_order->get_date_paid(), 'm/d/y' ) : wp_date('m/d/y'),
					'desc'   => $desc,
					'amount' => $txn_amount,
				];
			}
		}

		// ---------------------------------------------------------
		// Results Calculation
		// ---------------------------------------------------------
		$remaining_balance = $splits['project_total'] - $total_principal_paid;
		
		// Safety rounding: If balance is negligible, force it to 0 and snap Paid to Total.
		if ( $remaining_balance < 0.05 ) {
			$remaining_balance = 0;
			$total_principal_paid = $splits['project_total'];
		}

		return [
			'history'       => $history,
			'labels'        => [
				'paid_label'    => 'Amount Paid',
				'balance_label' => $splits['balance_label'],
			],
			'totals'        => [
				'amount_paid'     => $total_principal_paid, 
				'balance_due'     => $remaining_balance,
				'project_total'   => $splits['project_total'],
			]
		];
	}

	/**
     * Forces the browser to NOT cache the checkout page.
     * We use a specific "unload" listener hack which forces modern browsers 
     * (Chrome, Safari, Firefox) to disable the Back-Forward Cache (bfcache)
     * entirely for this page.
     */
    private function prevent_checkout_caching() {
        // 1. Standard Server Headers
        add_action( 'template_redirect', function() {
            if ( is_checkout() ) {
                nocache_headers();
                if ( ! headers_sent() ) {
                    header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
                    header( 'Pragma: no-cache' );
                    header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );
                }
            }
        } );

        // 2. The "Unload" Hack (Disables BF Cache)
        add_action( 'wp_footer', function() {
            if ( is_checkout() ) {
                ?>
                <script>
                    // Adding an unload listener (even empty) tells the browser 
                    // NOT to save this page in the Back-Forward Cache.
                    window.addEventListener('unload', function() {});
                </script>
                <?php
            }
        } );
    }

	/**
	 * HELPS PREVENT CONCURRENT REQUESTS THAT CAUSE ORDER CORRUPTION
     * ROOT FIX: Distributed MySQL Atomic API Mutex Lock
     * Broadened to catch all dynamic Store API routes, legacy wc-ajax requests, 
     * and authenticate via Cart-Token headers.
     * Uses MySQL GET_LOCK() to safely block concurrent requests across MULTIPLE EC2 servers.
     */
    public function starke_early_mutex_lock() {
        global $wpdb;
        static $process_locked = false;

        if ( $process_locked ) {
            return;
        }

        $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';

        // --- ENHANCED CONTEXT-AWARE MUTEX PROTECTION ---
		// We only bypass the lock for true Stripe Webhook endpoints or localized balance invoices.
		// If the request is a main checkout submission processing a payment, we MUST enforce the lock.
		$is_checkout_submission = ( strpos( $uri, 'wc/store' ) !== false && strpos( $uri, 'checkout' ) !== false );
		
		if ( ! $is_checkout_submission ) {
			if ( strpos( $uri, 'stripe' ) !== false || strpos( $uri, 'update_order_review' ) !== false ) {
				return; // Safe to bypass: non-checkout related webhook or ledger adjustment
			}
		}

        // BROAD CATCH: Lock ALL Store API endpoints and remaining legacy wc-ajax requests
        $is_store_api = strpos( $uri, 'wc/store' ) !== false;
        $is_wc_ajax   = strpos( $uri, 'wc-ajax' ) !== false;
		$is_starke_api = strpos( $uri, 'vern-shipping-block/v1/save-cart-as-quote' ) !== false;

        if ( $is_store_api || $is_wc_ajax || $is_starke_api ) {
            // 1. Identify the user session securely
            $session_id = '';
            
            if ( isset( $_SERVER['HTTP_CART_TOKEN'] ) ) {
                $session_id = $_SERVER['HTTP_CART_TOKEN'];
            } elseif ( defined( 'COOKIEHASH' ) ) {
                $cookie_name = 'wp_woocommerce_session_' . COOKIEHASH;
                if ( isset( $_COOKIE[ $cookie_name ] ) ) {
                    $session_id = explode( '||', $_COOKIE[ $cookie_name ] )[0];
                }
            }
            
            // --- Impersonation-Aware Fallback ---
            // Securely check if an admin is impersonating using the EXACT cookies from login-as-user.php
            if ( ! $session_id && is_user_logged_in() ) {
                $current_user_id = get_current_user_id();
                $admin_id        = isset( $_COOKIE['original_admin_id'] ) ? $_COOKIE['original_admin_id'] : null;
                $impersonated_id = isset( $_COOKIE['impersonated_user_id'] ) ? $_COOKIE['impersonated_user_id'] : null;

                if ( $admin_id && $impersonated_id && intval($impersonated_id) === $current_user_id ) {
                    // Admin is impersonating: Create a unique lock for the Admin's hijacked session
                    $session_id = 'admin_' . $admin_id . '_user_' . $impersonated_id;
                } else {
                    // Normal user
                    $session_id = $current_user_id;
                }
            }

            // Fallback for missing tokens (accounts for reverse proxies/load balancers)
            if ( ! $session_id ) {
                $ip = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] : ( isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'guest' );
                $session_id = md5( trim($ip) );
            }

            // 2. AWS DISTRIBUTED DB LOCK
            // Because requests may hit different EC2 instances behind a load balancer,
            // local file locks (flock) fail. We MUST use a MySQL Database Lock.
            $lock_name = 'starke_api_lock_' . md5( $session_id );
            
            // Wait up to 15 seconds to acquire the global database lock
            $locked = $wpdb->get_var( $wpdb->prepare( "SELECT GET_LOCK(%s, 15)", $lock_name ) );

            if ( $locked === '1' ) {
                $process_locked = true;

                // 3. Always release the global lock when the exact request finishes
                add_action( 'shutdown', function() use ( $wpdb, $lock_name ) {
                    $wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );
                } );
            } else {
                // 4. STRICT ABORT: The 15 seconds expired and the previous request is STILL running.
                // We MUST safely kill this concurrent request so it doesn't bypass the lock 
                // and corrupt the order. We return a standard 409 Conflict JSON response.
                wp_send_json_error( 
                    array( 'message' => __( 'Your previous request is still processing. Please wait a moment and try again.', 'vern_shipping_block' ) ), 
                    409 
                );
                exit; // Stop all PHP execution for this specific thread immediately
            }
        }
    }

	/**
	 * Force the Shipping row in the totals table to ONLY show the combined price,
	 * rather than the "Collection from..." location string when Local Pickup is used.
	 */
	public function force_shipping_price_only_display( $shipping, $order, $tax_display = '' ) {
		$shipping_total = (float) $order->get_shipping_total();
		$tax_display    = $tax_display ? $tax_display : get_option( 'woocommerce_tax_display_cart' );
		
		// Include tax in the display if the store settings require it
		if ( 'incl' === $tax_display ) {
			$shipping_total += (float) $order->get_shipping_tax();
		}

		// Return strictly the mathematical price
		return wc_price( $shipping_total, array( 'currency' => $order->get_currency() ) );
	}

	/**
	 * Dynamically format phone numbers (XXX-XXX-XXXX) when retrieved from the order.
	 * Fixes the display on the Order Confirmation page, Emails, and Admin for both new and legacy orders.
	 */
	public function format_order_phone( $phone, $order ) {
		if ( empty( $phone ) ) {
			return $phone;
		}
		
		// Strip all non-numeric characters
		$raw = preg_replace( '/[^0-9]/', '', $phone );
		
		// If it's a standard 10-digit number, format it with dashes
		if ( strlen( $raw ) === 10 ) {
			return preg_replace( '/(\d{3})(\d{3})(\d{4})/', '$1-$2-$3', $raw );
		}
		
		// Return original if it's an unusual length (like international)
		return $phone;
	}

	/**
	 * Sorts the cart alphanumerically in memory ONLY during the final checkout request. 
	 * This guarantees 0 performance impact during normal cart browsing and updating.
	 */
	public function sort_cart_session_alphanumerically( $cart ) {
		// Ensure we don't interfere with the admin backend
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		// --- MAXIMUM EFFICIENCY GUARDRAIL ---
		// Detect if this specific server request is the final "Place Order" request.
		// If it is NOT the checkout request, abort immediately to save resources.
		$is_blocks_api_checkout = isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/wc/store/v1/checkout') !== false;

		if ( ! $is_blocks_api_checkout ) {
			return; // Instantly abort. Do nothing during normal cart updates.
		}

		// Check if there are enough items to actually sort
		if ( empty( $cart->cart_contents ) || count( $cart->cart_contents ) < 2 ) {
			return; 
		}

		// Perform the natural sort only because the order is actively being built
		uasort( $cart->cart_contents, function( $a, $b ) {
			$get_name = function( $item ) {
				if ( ! empty( $item['official_profile_number'] ) ) return (string) $item['official_profile_number'];
				if ( ! empty( $item['custom_name'] ) ) return (string) $item['custom_name'];
				if ( isset( $item['data'] ) && is_object( $item['data'] ) ) return (string) $item['data']->get_name();
				return '';
			};

			return strnatcasecmp( $get_name( $a ), $get_name( $b ) );
		});
	}
} // Class End