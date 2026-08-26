<?php
/**
 * Quotes
 *
 * Shows quotes on the account page.
 *  
**/

// Initialize $has_orders to prevent "Undefined variable" warning
// This variable is typically set by WooCommerce's orders query.
// Setting it to false here ensures it's defined.
if ( ! isset( $has_orders ) ) {
    $has_orders = false;
}

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
				
				// --- STARKE MODIFICATION: Calculate item count excluding specific Charge Product IDs ---
				$item_count = 0;
				$excluded_product_ids = array( 444, 2843 ); // Setup Charge and Knife Cost IDs

				foreach ( $order->get_items() as $item_id => $item ) {
					// Skip calculation if this item is one of our explicit charge products
					if ( in_array( $item->get_product_id(), $excluded_product_ids, true ) ) {
						continue;
					}
					// Calculate net quantity (Original + Refunded, since refunded amounts are negative)
					$net_qty = $item->get_quantity() + $order->get_qty_refunded_for_item( $item_id );
					if ( $net_qty > 0 ) {
						$item_count += $net_qty;
					}
				}
				// -----------------------------------------------------------------------------
				?>
				<tr class="woocommerce-orders-table__row woocommerce-orders-table__row--status-<?php echo esc_attr( $order->get_status() ); ?> order">
					<?php foreach ( wc_get_account_orders_columns() as $column_id => $column_name ) :
						$is_order_number = 'order-number' === $column_id;
					?>
						<?php if ( $is_order_number ) : ?>
							<th class="woocommerce-orders-table__cell woocommerce-orders-table__cell-<?php echo esc_attr( $column_id ); ?>" data-title="<?php echo esc_attr( $column_name ); ?>" scope="row">
						<?php else : ?>
							<td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-<?php echo esc_attr( $column_id ); ?>" data-title="<?php echo esc_attr( $column_name ); ?>">
						<?php endif; ?>

							<?php 
							// ---------------------------------------------------------
							// 1. SPECIFIC COLUMNS (PRIORITY: HIGH)
							// These run first, overriding any plugin hooks.
							// ---------------------------------------------------------
							
							if ( $is_order_number ) : 
								// --- FIXED: Use Starke ID if available, with 'Q' prefix ---
								$starke_id = $order->get_meta( '_starke_order_number', true );
								
								if ( ! empty( $starke_id ) ) {
									// Force 'Q' prefix for consistency
									$display_id = '#Q' . $starke_id;
								} else {
									// Fallback to standard #1234
									$display_id = _x( '#', 'hash before order number', 'woocommerce' ) . $order->get_order_number();
								}
								?>
								<strong>
									<?php echo esc_html( $display_id ); ?>
								</strong>

							<?php elseif ( 'order-date' === $column_id ) : ?>
								<time datetime="<?php echo esc_attr( $order->get_date_created()->date( 'c' ) ); ?>"><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></time>

							<?php elseif ( 'order-status' === $column_id ) : ?>
								<?php 
								$status_label = wc_get_order_status_name( $order->get_status() );
								
								// If it is a Freight Quote, add clarifying text for customers only
								if ( $order->get_status() === 'freight-quote' ) {
									$is_admin_or_impersonator = ( function_exists('impersonation_is_active') && impersonation_is_active() ) || current_user_can('manage_woocommerce');
									
									// Only append if it is a standard customer viewing (Not Admin, Not Impersonating)
									if ( ! $is_admin_or_impersonator ) {
										$status_label .= ' (Pending Review)';
									}
								}
								echo esc_html( $status_label ); 
								?>

							<?php elseif ( 'order-total' === $column_id ) : ?>
								<?php
								/* translators: 1: formatted order total 2: total order items */
								echo wp_kses_post( sprintf( _n( '%1$s for %2$s item', '%1$s for %2$s items', $item_count, 'woocommerce' ), $order->get_formatted_order_total(), $item_count ) );
								?>

							<?php elseif ( 'order-actions' === $column_id ) : ?>
								<?php
								$wp_button_class = wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '';
								
								echo '<div class="quote-actions" style="display: flex; gap: 15px;">';

                                // 1. OPEN BUTTON
                                // Cleaned: Removed "Profiles Needed" check since this file only handles Quotes.
                                $load_order_url = add_query_arg([
                                    'action'    => 'load_quote_to_cart',
                                    'quote_id'  => $order->get_id(),
                                    '_wpnonce'  => wp_create_nonce('load_quote_to_cart')
                                ], wc_get_checkout_url());
                                
                                echo '<a href="' . esc_url($load_order_url) . '" data-id="' . esc_attr($order->get_id()) . '" data-url="' . esc_url($load_order_url) . '" class="woocommerce-button button open starke-open-quote-trigger '  . esc_attr( $wp_button_class ) . '">' . __('Open', 'woocommerce') . '</a>';

								// 2. PDF Button
								$download_pdf_order_quote_nonce = wp_create_nonce( 'download_pdf_order_quote_nonce' );
								$order_id = $order->get_id(); 
								echo '<a href="#" class="woocommerce-button button ' . esc_attr( $wp_button_class ) . ' download-pdf-button" ' . 'data-order-id="' . esc_attr( $order_id ) . '" ' . 'data-nonce="' . esc_attr( $download_pdf_order_quote_nonce ) . '" ' . 'data-pdf-url="' . esc_url(set_quote_pdf_url($order_id, $download_pdf_order_quote_nonce)) . '">' . esc_html__('PDF', 'woocommerce') . '</a>';

								// 3. Delete Button (Active, Expired, or Pending if Impersonating)
								$allowed_statuses = array( 'active-quote', 'expired-quote' );

								// Allow Pending Quotes ONLY if impersonating
								if ( function_exists('impersonation_is_active') && impersonation_is_active() ) {
									$allowed_statuses[] = 'pending-quote';
								}

								if ( in_array( $order->get_status(), $allowed_statuses ) ) {
									$quote_id = $order->get_id(); // Fix: Ensure ID is defined

									// Build query args array
									$delete_args = [
										'delete_quote' => $quote_id,
										'_wpnonce'     => wp_create_nonce('delete_quote_' . $quote_id)
									];
									
									// Pass the current page number if we are not on page 1
									if ( isset( $current_page ) && $current_page > 1 ) {
										$delete_args['paged'] = $current_page;
									}

									$delete_quote_url = add_query_arg( $delete_args, wc_get_account_endpoint_url('quotes') );
									
									// UPDATE: Removed onclick="return confirm(...)" for instant, one-click deletion
									echo '<a href="' . esc_url($delete_quote_url) . '" class="woocommerce-button button delete-quote-button '  . esc_attr( $wp_button_class ) . '">' . __('Delete', 'woocommerce') . '</a>';
								}

								echo '</div>';
								?>

							<?php 
							// ---------------------------------------------------------
							// 2. EXTERNAL PLUGINS (PRIORITY: LOW)
							// These only run if the column was not one of the standard ones above.
							// ---------------------------------------------------------
							elseif ( has_action( 'woocommerce_my_account_my_orders_column_' . $column_id ) ) : ?>
								<?php do_action( 'woocommerce_my_account_my_orders_column_' . $column_id, $order ); ?>

							<?php endif; ?>

						<?php if ( $is_order_number ) : ?>
							</th>
						<?php else : ?>
							</td>
						<?php endif; ?>
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
				You currently have items in your cart. Loading this quote will replace your current cart items.<br><br>
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
		// Custom Starke Pagination for Quotes
		if ( function_exists( 'starke_render_custom_pagination' ) ) {
			starke_render_custom_pagination( $current_page, $max_num_pages, 'quotes' );
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
    document.querySelectorAll('.starke-open-quote-trigger').forEach(button => {
        button.addEventListener('click', function(e) {
            
            // NEW: Reset the "Seen" cookie for this quote.
            // This guarantees the popup WILL show when they arrive at checkout.
            const orderId = this.getAttribute('data-id');
            if (orderId) {
                document.cookie = "starke_seen_expired_" + orderId + "=; Max-Age=-99999999; path=/";
            }

            // Standard "Save & Open" Logic follows...
            if ( hasItemsInCart() && !isCartClean ) {
                e.preventDefault();
                pendingTargetUrl = this.getAttribute('data-url');
                
                overwriteBtn.setAttribute('href', pendingTargetUrl);
                popup.style.display = 'flex';
                overlay.style.display = 'block';

                const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
                document.body.style.paddingRight = scrollbarWidth + 'px';
                document.body.style.overflow = 'hidden';
            }
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

	<?php //wc_print_notice( esc_html__( 'No order has been made yet.', 'woocommerce' ) . ' <a class="woocommerce-Button wc-forward button' . esc_attr( $wp_button_class ) . '" href="' . esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ) . '">' . esc_html__( 'Browse products', 'woocommerce' ) . '</a>', 'notice' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment ?>

<?php endif; ?>

<?php do_action( 'woocommerce_after_account_orders', $has_orders ); ?>