<?php
/**
 * Email Order Details
 *
 * This template displays the main order table in emails.
 *
 * MODIFIED FOR STARKE:
 * 1. FIXED: Removed duplicate 'woocommerce_email_before_order_table' hook.
 * 2. FIXED: Displays Starke ID for ALL orders (Normal & Balance Invoices).
 * 3. LOGIC: Swaps to Parent Data if it is a Balance Invoice.
 * 4. LAYOUT: Custom font sizes and styling for totals.
 */

defined( 'ABSPATH' ) || exit;

$text_align = is_rtl() ? 'right' : 'left';

// --- STARKE MODIFICATION START: Detect Balance Invoice & Display IDs ---
$is_balance_invoice = false;
$parent_order       = false;

// 1. DEFAULT: Try to use the Starke ID for this order first. 
// If not found, fallback to standard WC order number.
$starke_id_check  = $order->get_meta( '_starke_order_number', true );
$display_order_id = ! empty( $starke_id_check ) ? $starke_id_check : $order->get_order_number();

$display_date     = wc_format_datetime( $order->get_date_created() ); 

// 2. CHECK: Is this a Balance Invoice?
// If yes, we overwrite the variables above with the Parent's data.
if ( 'yes' === $order->get_meta( '_starke_is_balance_invoice', true ) ) {
    $is_balance_invoice = true;
    
    // Use standard WC method to get parent
    $parent_order_id = $order->get_parent_id();
    $parent_order    = wc_get_order( $parent_order_id );

    if ( $parent_order ) {
        // Use Parent Starke ID
        $starke_id = $parent_order->get_meta( '_starke_order_number' );
        $display_order_id = ! empty( $starke_id ) ? $starke_id : $parent_order->get_order_number();
        
        // Use Parent Original Date
        $display_date = wc_format_datetime( $parent_order->get_date_created() );
    }
}

// Determine which order object to use for data display (Items & Totals)
$order_to_use = ( $is_balance_invoice && $parent_order ) ? $parent_order : $order;
// --- STARKE MODIFICATION END ---
?>

<div style="font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; margin-bottom: 40px;">
	<h2 class="starke-force-white" style="color: #6431f6; display: block; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; font-size: 18px; font-weight: bold; line-height: 130%; margin: 0 0 18px;">
		<?php
		if ( $sent_to_admin ) {
			$before = '<a class="link" href="' . esc_url( $order->get_edit_order_url() ) . '">';
			$after  = '</a>';
		} else {
			$before = '';
			$after  = '';
		}
		// Default to "Order", but switch to "Quote" if it's the quote email
        $heading_text = __( 'Order #%s', 'woocommerce' );
        if ( isset( $email ) && 'customer_quote_sending' === $email->id ) {
            $heading_text = __( 'Quote #Q%s', 'woocommerce' );
        }

		/* translators: %s: Order ID or Quote ID. */
		echo wp_kses_post( $before . sprintf( $heading_text . $after . ' (<time datetime="%s">%s</time>)', $display_order_id, esc_attr( $order->get_date_created()->format( 'c' ) ), $display_date ) );
		?>
	</h2>
</div>

<div style="margin-bottom: 40px;">
	<table class="td" cellspacing="0" cellpadding="6" style="width: 100%; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif;" border="1">
		<thead>
			<tr>
				<th class="td starke-force-white" scope="col" style="text-align:<?php echo esc_attr( $text_align ); ?>;" width="75%"><?php esc_html_e( 'Product', 'woocommerce' ); ?></th>
				<th class="td starke-force-white" scope="col" style="text-align:<?php echo esc_attr( $text_align ); ?>;" width="25%"><?php esc_html_e( 'Price', 'woocommerce' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			// --- STARKE: Use the determined order (Parent or Current) for items list ---
			echo wp_kses_post(
				wc_get_email_order_items( 
                    $order_to_use, 
					array(
						'shown_to_customer' => ! $sent_to_admin,
						'full_price'        => true,
						'show_sku'          => false,
						'show_image'        => true,
						'image_size'        => array( 60, 60 ), 
						'plain_text'        => $plain_text,
						'sent_to_admin'     => $sent_to_admin,
					)
				)
			);
			?>
		</tbody>
		<tfoot>
			<?php
            // --- STARKE: Use the determined order (Parent or Current) for totals as well ---
			$totals = $order_to_use->get_order_item_totals();

			if ( $totals ) {
				$i = 0;
				foreach ( $totals as $key => $total ) {
				// --- STARKE UPDATE: Hide 'Amount Paid' & 'Balance Due' for Quote Emails ---
				if ( isset( $email ) && 'customer_quote_sending' === $email->id ) {
					// We check the label text to catch any variation (like "Total Paid" or "Balance Due")
					$label_check = strip_tags( $total['label'] );
					if ( stripos( $label_check, 'Amount Paid' ) !== false || stripos( $label_check, 'Balance Due' ) !== false ) {
						continue;
					}
				}
				// --------------------------------------------------------------------------

				$i++;
                    
                    // --- FIX: Dynamic Font Size Logic ---
                    // Standard rows get 1.1em
                    $font_size = '1.1em';
                    
                    // Subtotal gets 1.5em (Bigger) to match your other emails
                    if ( 'cart_subtotal' === $key ) {
                        $font_size = '1.5em';
                    }
                    
					?>
					<tr>
                        <th class="td starke-force-white" scope="row" style="text-align:<?php echo esc_attr( $text_align ); ?>; font-size: <?php echo esc_attr( $font_size ); ?>; font-weight: bold; <?php echo ( 1 === $i ) ? 'border-top-width: 4px;' : ''; ?>"><?php echo wp_kses_post( $total['label'] ); ?></th>
						<td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>; <?php echo ( 1 === $i ) ? 'border-top-width: 4px;' : ''; ?>"><?php echo wp_kses_post( $total['value'] ); ?></td>
					</tr>
					<?php
				}
			}
			if ( $order->get_customer_note() ) {
				?>
				<tr>
                    <th class="td starke-force-white" scope="row" style="text-align:<?php echo esc_attr( $text_align ); ?>; font-size: 1.1em; font-weight: bold;"><?php esc_html_e( 'Note:', 'woocommerce' ); ?></th>
					<td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>;"><?php echo wp_kses_post( nl2br( wptexturize( $order->get_customer_note() ) ) ); ?></td>
				</tr>
				<?php
			}
			?>
		</tfoot>
	</table>
</div>

<?php do_action( 'woocommerce_email_after_order_table', $order, $sent_to_admin, $plain_text, $email ); ?>