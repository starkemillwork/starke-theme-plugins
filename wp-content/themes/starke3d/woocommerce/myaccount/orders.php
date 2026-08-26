<?php
/**
 * Orders
 *
 * Shows orders on the account page.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/myaccount/orders.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 9.2.0
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_account_orders', $has_orders ); ?>

<?php if ( $has_orders ) : ?>

	<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table">
		<thead>
			<tr>
				<?php foreach ( wc_get_account_orders_columns() as $column_id => $column_name ) : ?>
					<th scope="col" class="woocommerce-orders-table__header woocommerce-orders-table__header-<?php echo esc_attr( $column_id ); ?>"><span class="nobr"><?php echo esc_html( $column_name ); ?></span></th>
				<?php endforeach; ?>
			</tr>
		</thead>

		<tbody>
			<?php
			foreach ( $customer_orders->orders as $customer_order ) {
				$order      = wc_get_order( $customer_order ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				
				// --- STARKE MODIFICATION: Calculate item count (Counts rows for legacy, quantities for new) ---
				$item_count = 0;
				$excluded_product_ids = array( 444, 2843 ); 
				$is_legacy = ! empty( $order->get_meta( '_legacy_order_id', true ) );

				foreach ( $order->get_items() as $item_id => $item ) {
					if ( in_array( $item->get_product_id(), $excluded_product_ids, true ) ) {
						continue;
					}
					
					$net_qty = $item->get_quantity() + $order->get_qty_refunded_for_item( $item_id );
					if ( $net_qty > 0 ) {
						if ( $is_legacy ) {
							$item_count++;
						} else {
							$item_count += $net_qty;
						}
					}
				}
				// -----------------------------------------------------------------------------
				?>
				<tr class="woocommerce-orders-table__row woocommerce-orders-table__row--status-<?php echo esc_attr( $order->get_status() ); ?> order">
					<?php foreach ( wc_get_account_orders_columns() as $column_id => $column_name ) : ?>
                        <?php 
                        // If it is a legacy document, completely skip rendering the status cell HTML to preserve zebra striping
                        if ( 'order-status' === $column_id && ! empty( $order->get_meta( '_legacy_order_id', true ) ) ) {
                            continue;
                        }
                        ?>
                        <td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-<?php echo esc_attr( $column_id ); ?>" data-title="<?php echo esc_attr( ('order-number' === $column_id && ! empty( $order->get_meta( '_legacy_order_id', true ) )) ? __( 'Legacy Document', 'woocommerce' ) : $column_name ); ?>">
						<?php
						// ---------------------------------------------------------
						// 1. CUSTOM PRIORITY CHECKS
						// ---------------------------------------------------------

						// A. Order Total (Updated: Includes Fees/Taxes from Balance Invoice)
						if ( 'order-total' === $column_id ) :
							
							// --- CUSTOM: Use Natural Total (Project Value) if available ---
							$natural_total = (float) $order->get_meta( '_starke_natural_total', true );
							
							// If natural total exists, format it. Otherwise use standard WC total.
							$display_total = ( $natural_total > 0.01 ) ? wc_price( $natural_total ) : $order->get_formatted_order_total();

							/* translators: 1: formatted order total 2: total order items */
							echo wp_kses_post( sprintf( _n( '%1$s for %2$s item', '%1$s for %2$s items', $item_count, 'woocommerce' ), $display_total, $item_count ) );

						// B. Order Actions (Updated: Conditional Logic for Legacy Orders)
						elseif ( 'order-actions' === $column_id ) :
							$wp_button_class = wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '';
							echo '<div class="order-actions" style="display: flex; gap: 10px; justify-content: flex-start;">';

							$order_id = $order->get_id(); 
							
							// --- LEGACY ORDER CHECK ---
							// Check if this order contains the legacy import identifier meta key
							$legacy_order_id = $order->get_meta( '_legacy_order_id', true );

							if ( ! empty( $legacy_order_id ) ) {
								// 1. LEGACY ORDERS ONLY: Show standard native WooCommerce View button
								echo '<a href="' . esc_url( $order->get_view_order_url() ) . '" class="woocommerce-button button view' . esc_attr( $wp_button_class ) . '">' . esc_html__( 'View Legacy Document', 'woocommerce' ) . '</a>';
							} else {
								// 2. MODERN ORDERS ONLY: Run your custom Open & PDF engine
								
								// Open Button (Smart Check)
								$is_profiles_needed = $order->get_status() === 'profiles-needed';
								$is_impersonating   = function_exists('impersonation_is_active') && impersonation_is_active();

								if ( ! $is_profiles_needed || $is_impersonating ) {
									$load_order_url = add_query_arg([
										'action'    => 'load_quote_to_cart',
										'quote_id'  => $order_id,
										'_wpnonce'  => wp_create_nonce('load_quote_to_cart')
									], wc_get_checkout_url());
									
									echo '<a href="' . esc_url($load_order_url) . '" data-url="' . esc_url($load_order_url) . '" class="woocommerce-button button open starke-open-order-trigger '  . esc_attr( $wp_button_class ) . '">' . __('Open', 'woocommerce') . '</a>';
								}
								
								// PDF Button
								$download_pdf_order_quote_nonce = wp_create_nonce( 'download_pdf_order_quote_nonce' );
								echo '<a href="#" class="woocommerce-button button ' . esc_attr( $wp_button_class ) . ' download-pdf-button" ' . 
									 'data-order-id="' . esc_attr( $order_id ) . '" ' . 
									 'data-nonce="' . esc_attr( $download_pdf_order_quote_nonce ) . '" ' . 
									 'data-pdf-url="' . esc_url(set_order_pdf_url($order_id, $download_pdf_order_quote_nonce)) . '">' . 
									 esc_html__('PDF', 'woocommerce') . '</a>';
							}
						
							// 3. PAY BALANCE BUTTON (Applies to both if needed, or falls back safely)
							$balance_invoice_id = $order->get_meta( '_starke_balance_order_id', true );
							if ( $balance_invoice_id ) {
								$invoice = wc_get_order( $balance_invoice_id );
								if ( $invoice && $invoice->needs_payment() ) {
									echo '<a href="' . esc_url( $invoice->get_checkout_payment_url() ) . '" class="woocommerce-button button pay ' . esc_attr( $wp_button_class ) . '">' . esc_html__( 'Pay Balance', 'woocommerce' ) . '</a>';
								}
							}

							echo '</div>';

						// ---------------------------------------------------------
						// 2. STANDARD ACTIONS (Run these LAST)
						// ---------------------------------------------------------
						
						// C. If a plugin wants to control a column
						elseif ( has_action( 'woocommerce_my_account_my_orders_column_' . $column_id ) ) :
							do_action( 'woocommerce_my_account_my_orders_column_' . $column_id, $order );

						// D. Standard Order Number (Updated for Legacy Order IDs)
						elseif ( 'order-number' === $column_id ) :
							$legacy_id = $order->get_meta( '_legacy_order_id', true );
							$display_id = ! empty( $legacy_id ) ? $legacy_id : $order->get_order_number();
							?>
							<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>">
								<?php echo esc_html( _x( '#', 'hash before order number', 'woocommerce' ) . $display_id ); ?>
							</a>
							<?php

						// E. Standard Order Date
						elseif ( 'order-date' === $column_id ) :
							?>
							<time datetime="<?php echo esc_attr( $order->get_date_created()->date( 'c' ) ); ?>"><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></time>
							<?php

						// F. Standard Order Status
						elseif ( 'order-status' === $column_id ) :
							$display_status = wc_get_order_status_name( $order->get_status() );

							// VISUAL OVERRIDE: Show "Processing" instead of "Profiles Needed" ONLY in this list.
							// Logic: If status is 'profiles-needed' AND the user is NOT an admin impersonating a customer.
							if ( $order->get_status() === 'profiles-needed' ) {
								if ( ! ( function_exists('impersonation_is_active') && impersonation_is_active() ) ) {
									$display_status = _x( 'Processing', 'Order status', 'woocommerce' );
								}
							}

							echo esc_html( $display_status );

						endif;
						?>
					</td>
				<?php endforeach; ?>
				</tr>
				<?php
			}
			?>
		</tbody>
	</table>

	<div id="starke-popup-overlay" class="starke-popup-overlay"></div>

	<div id="starke-confirm-popup" class="infoPopUp2">
		
		<div id="infoHeader_div">
			<label id="infoPopUpTitle_label">Save Cart First?</label>
			<button type="button" id="starke-popup-close">X</button>
		</div>

		<div id="infoContent_div">
			<div id="starke-popup-text">
				You currently have items in your cart. Loading this order will replace your current cart items.<br><br>
				Would you like to save your current cart first?
			</div>
				<div class="popup-actions">
				<button type="button" id="starke-popup-save-btn">Save Cart & Open</button>
				
				<a id="starke-popup-confirm-btn" href="#">No, Overwrite My Cart</a>
				
				<button type="button" id="starke-popup-cancel-btn">Cancel</button>
			</div>
		</div>

	</div>

	<?php do_action( 'woocommerce_before_account_orders_pagination' ); ?>

	<?php
		// Custom Starke Pagination
		if ( function_exists( 'starke_render_custom_pagination' ) ) {
			starke_render_custom_pagination( $current_page, $customer_orders->max_num_pages, 'orders' );
		}
	?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Select all buttons with the class 'download-pdf-button'
    const downloadPdfButtons = document.querySelectorAll('.download-pdf-button');

    // Add a click event listener to each button
    downloadPdfButtons.forEach(button => {
        button.addEventListener('click', function(event) {
            event.preventDefault(); // Prevent the default button action

            // Get the order ID and nonce from the data attributes of the clicked button
            const orderId = this.dataset.orderId;
            const nonce = this.dataset.nonce;

            // Get the full pretty URL from the button's data attribute
            const downloadUrl = this.dataset.pdfUrl;

            // Open the URL in a new tab/window, which typically triggers the download
            window.open(downloadUrl, '_blank');
        });
    });

	const popup = document.getElementById('starke-confirm-popup');
    const overlay = document.getElementById('starke-popup-overlay');
    const saveBtn = document.getElementById('starke-popup-save-btn');
    const overwriteBtn = document.getElementById('starke-popup-confirm-btn');
    const cancelBtn = document.getElementById('starke-popup-cancel-btn');
    const closeBtn = document.getElementById('starke-popup-close');
    
    let pendingTargetUrl = '';
    const saveQuoteNonce = '<?php echo wp_create_nonce("wp_rest"); ?>'; 

    // --- NEW: Check if Cart matches the Loaded Quote (Server Side Check) ---
    <?php
    $is_cart_clean = false;
    if ( WC()->session ) {
        $editing_id = WC()->session->get('editing_original_order_id');
        if ( $editing_id ) {
            $original_order = wc_get_order($editing_id);
            if ( $original_order && class_exists('VernShippingBlock_Extend_Woo_Core') ) {
                $core_instance = VernShippingBlock_Extend_Woo_Core::get_instance();
                $status = $original_order->get_status();

                // 1. PROFILES NEEDED EXCEPTION:
                // Always treat as "Clean" (Skip Popup) regardless of changes.
                // We never want to save these as quotes from this page.
                if ( $status === 'profiles-needed' ) {
                    $is_cart_clean = true; 
                } 
                else {
                    // 2. STANDARD CHECK: Are they identical?
                    if ( $core_instance->are_cart_and_order_identical( WC()->cart, $original_order ) ) {
                        $is_cart_clean = true; // Default: Skip Popup

                        // 3. IMPERSONATION OVERRIDE:
                        // If Impersonating a "Real Order" (e.g. Completed), FORCE the popup so we can save it.
                        if ( function_exists('impersonation_is_active') && impersonation_is_active() ) {
                            $exempt_statuses = ['active-quote', 'expired-quote', 'pending-quote', 'freight-quote', 'deleted-quote', 'ordered-quote', 'profiles-needed', 'profiles-ready'];
                            
                            if ( ! in_array( $status, $exempt_statuses ) ) {
                                $is_cart_clean = false; // Force Popup
                            }
                        }
                    }
                }
            }
        }
    }
    ?>
    const isCartClean = <?php echo json_encode($is_cart_clean); ?>;

    function hasItemsInCart() {
        return document.cookie.match(/woocommerce_items_in_cart=1/);
    }

    // 1. TRIGGER: Handle "Open" Clicks
    document.querySelectorAll('.starke-open-order-trigger').forEach(button => {
        button.addEventListener('click', function(e) {
            
            // LOGIC FIX: Only show popup if items exist AND the cart has changes (is NOT clean).
            if ( hasItemsInCart() && !isCartClean ) {
                e.preventDefault();
                pendingTargetUrl = this.getAttribute('data-url');
                
                overwriteBtn.setAttribute('href', pendingTargetUrl);
                popup.style.display = 'flex';
                overlay.style.display = 'block';
                
                // NEW: Lock the background scroll AND prevent page shift
                const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
                document.body.style.paddingRight = scrollbarWidth + 'px';
                document.body.style.overflow = 'hidden';
            }
            // Else: Browser follows the link immediately
        });
    });

    // 2. ACTION: Save & Open (Using EXISTING REST ENDPOINT)
    saveBtn.addEventListener('click', function(e) {
        e.preventDefault(); 
        e.stopPropagation(); 
        
        const originalText = saveBtn.innerText;
        
        // Spinner HTML matching your style.scss structure
        const spinnerHtml = '<span class="custom-spinner-wrapper"><span class="wc-block-components-spinner"></span></span>';
        
        // UPDATE: Use innerHTML to add Text + Spinner
        saveBtn.innerHTML = 'SAVING CART... ' + spinnerHtml;
        
        // --- LOCKDOWN START: Disable ALL interactions ---
        saveBtn.disabled = true;       // Disable Save button
        cancelBtn.disabled = true;     // Disable Cancel button
        closeBtn.disabled = true;      // Disable X button
        
        // Disable "Overwrite" link (visually and functionally)
        overwriteBtn.style.pointerEvents = 'none';
        overwriteBtn.style.opacity = '0.5'; 
        // --- LOCKDOWN END ---

        jQuery.ajax({
            url: '/wp-json/vern-shipping-block/v1/save-cart-as-quote',
            method: 'POST',
            beforeSend: function ( xhr ) {
                xhr.setRequestHeader( 'X-WP-Nonce', saveQuoteNonce );
            },
            data: {
                id: 'save_cart_quote_popup'
            },
            success: function(response) {
                if (response.success) {
                    // UPDATE: Text + Spinner
                    saveBtn.innerHTML = 'LOADING NEW CART... ' + spinnerHtml;
                    
                    window.location.href = pendingTargetUrl;
                } else {
                    alert('Error: ' + (response.message || 'Could not save quote.'));
                    
                    // --- RE-ENABLE ON ERROR ---
                    saveBtn.innerText = originalText;
                    saveBtn.disabled = false;
                    cancelBtn.disabled = false;
                    closeBtn.disabled = false;
                    overwriteBtn.style.pointerEvents = 'auto';
                    overwriteBtn.style.opacity = '1';
                }
            },
            error: function(xhr) {
                let errorMsg = 'Connection error.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                }
                alert(errorMsg);
                
                // --- RE-ENABLE ON ERROR ---
                saveBtn.innerText = originalText;
                saveBtn.disabled = false;
                cancelBtn.disabled = false;
                closeBtn.disabled = false;
                overwriteBtn.style.pointerEvents = 'auto';
                overwriteBtn.style.opacity = '1';
            }
        });
    });

    // 3. ACTION: Close/Cancel
    function closePopup() {
        // SECURITY: If the Save button is disabled, it means a save is in progress.
        if (saveBtn.disabled) return;

        popup.style.display = 'none';
        overlay.style.display = 'none';
        
        // NEW: Unlock the background scroll and remove padding compensation
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
    }

    closeBtn.addEventListener('click', closePopup);
    cancelBtn.addEventListener('click', closePopup);
    overlay.addEventListener('click', closePopup);
});
</script>

<?php else : ?>

	<?php wc_print_notice( esc_html__( 'No order has been made yet.', 'woocommerce' ) . ' <a class="woocommerce-Button wc-forward button' . esc_attr( $wp_button_class ) . '" href="' . esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ) . '">' . esc_html__( 'Browse products', 'woocommerce' ) . '</a>', 'notice' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment ?>

<?php endif; ?>

<?php do_action( 'woocommerce_after_account_orders', $has_orders ); ?>
