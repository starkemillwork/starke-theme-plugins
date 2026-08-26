<?php
/**
 * Email Addresses
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/email-addresses.php.
 *
 * This version has been modified to:
 * 1. Display the Shipping Address column before the Billing Address column.
 * 2. Vertically align the address blocks to the bottom for a cleaner look.
 * 3. Ensure a gap between addresses using a spacer cell.
 * 4. Box the addresses for a better visual appearance.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates\Emails
 * @version 8.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$text_align = is_rtl() ? 'right' : 'left';
$address    = $order->get_formatted_billing_address();
$shipping   = $order->get_formatted_shipping_address();

// --- 1. CHECK FOR PICKUP LOCATION ON PRIMARY METHOD ---
$sample_shipping_rate_id = function_exists('get_samples_shipping_method') ? get_samples_shipping_method($order) : '';
$is_pickup = false;
$pickup_address_html = '';

foreach ( $order->get_shipping_methods() as $method ) {
	$method_id_in_order = $method->get_method_id() . ':' . $method->get_instance_id();
	
	// Target the standard method (skip the samples method)
	if ( $method_id_in_order !== $sample_shipping_rate_id ) {
		if ( strpos( $method->get_method_id(), 'pickup_location' ) !== false || strpos( $method_id_in_order, 'pickup_location' ) !== false ) {
			$is_pickup = true;
			
			// Grab the saved pickup address from order meta
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
				// Format the array exactly how WooCommerce expects it for the email
				$formatted_args = [
					'first_name' => '', 
					'last_name'  => '',
					'company'    => 'Starke Millwork Inc.',
					'address_1'  => $addr_array['street'] ?? $addr_array['address_1'] ?? '', 
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
// --------------------------------------------------------

?><table id="addresses" cellspacing="0" cellpadding="0" style="width: 100%; vertical-align: top; margin-bottom: 40px; padding:0;" border="0">
	<tr>
		<?php // --- SHIPPING ADDRESS FIRST --- ?>
		<?php if ( ! wc_ship_to_billing_address_only() && ( ( $order->needs_shipping_address() && $shipping ) || $is_pickup ) ) : ?>
			<td style="text-align:<?php echo esc_attr( $text_align ); ?>; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; padding:0;" valign="bottom" width="48%">
				<?php
				// --- 2. CONDITIONALLY CHANGE TITLE & ADDRESS CONTENT ---
				$shipping_title  = __( 'Shipping Address', 'woocommerce' );
				$samples_address = $order->get_meta( '_samples_full_shipping_address' );
				$has_samples     = ( ! empty( $samples_address ) && ! empty( $samples_address['address_1'] ) );

				if ( $is_pickup ) {
					// It's a pickup. Change title based on if it's a mixed cart.
					$shipping_title = $has_samples ? __( 'Linear Feet Profiles Pickup Location', 'woocommerce' ) : __( 'Pickup Location', 'woocommerce' );
					
					// Override the default shipping address with the Starke Warehouse address
					if ( ! empty( $pickup_address_html ) ) {
						$shipping = $pickup_address_html;
					}
				} elseif ( $has_samples ) {
					// It's a standard delivery, but a mixed cart
					$shipping_title = __( 'Linear Feet Profiles Shipping Address', 'woocommerce' );
				}
				?>
				<h2 style="color: #6431f6; display: block; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; font-size: 18px; font-weight: bold; line-height: 130%; margin: 0 0 18px;"><?php echo esc_html( $shipping_title ); ?></h2>

				<address class="address" style="padding: 12px; border: 1px solid #e5e5e5; color: #636363;">
					<?php echo wp_kses_post( $shipping ); ?>
					
					<?php // Don't show the customer's phone number if it's shipping to the Starke warehouse ?>
					<?php if ( ! $is_pickup && $order->get_shipping_phone() ) : ?>
						<br /><?php echo wc_make_phone_clickable( $order->get_shipping_phone() ); ?>
					<?php endif; ?>
					
					<?php
					/**
					 * Fires after the core address fields in emails.
					 */
					do_action( 'woocommerce_email_customer_address_section', 'shipping', $order, $sent_to_admin, false );
					?>
				</address>
			</td>
            
            <?php // --- SPACER CELL (Prevents Touching) --- ?>
            <td width="4%">&nbsp;</td>
            
		<?php endif; ?>

		<?php // --- BILLING ADDRESS SECOND --- ?>
		<td style="text-align:<?php echo esc_attr( $text_align ); ?>; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; border:0; padding:0;" valign="bottom" width="48%">
			<h2 style="color: #6431f6; display: block; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; font-size: 18px; font-weight: bold; line-height: 130%; margin: 0 0 18px;"><?php esc_html_e( 'Billing Address', 'woocommerce' ); ?></h2>

			<address class="address" style="padding: 12px; border: 1px solid #e5e5e5; color: #636363;">
				<?php echo wp_kses_post( $address ? $address : esc_html__( 'N/A', 'woocommerce' ) ); ?>
				<?php if ( $order->get_billing_phone() ) : ?>
					<br/><?php echo wc_make_phone_clickable( $order->get_billing_phone() ); ?>
				<?php endif; ?>
				<?php if ( $order->get_billing_email() ) : ?>
					<br/><?php echo esc_html( $order->get_billing_email() ); ?>
				<?php endif; ?>
				<?php
				/**
				 * Fires after the core address fields in emails.
				 */
				do_action( 'woocommerce_email_customer_address_section', 'billing', $order, $sent_to_admin, false );
				?>
			</address>
		</td>
	</tr>
</table>