<?php
/**
 * Register custom email classes for WooCommerce.
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

// Registers new custom email classes
add_filter( 'woocommerce_email_classes', 'register_customer_quote_sending_email' );
function register_customer_quote_sending_email( $email_classes ) {
    $email_classes['WC_Email_Customer_Quote_Sending'] = include_once( get_stylesheet_directory() . '/admin/classes/emails/class-wc-email-customer-quote-sending.php' );
    return $email_classes;
}

/**
 * Remove the default automatic trigger for the WooCommerce "Customer On Hold Order" email.
 * This ensures the email is only sent when you programmatically trigger it.
 */
add_action( 'woocommerce_email_actions', 'remove_email_auto_triggers', 5 ); // Use priority 5 to ensure it runs before the email's trigger is set up at priority 10.
function remove_email_auto_triggers( $emails_array ) {
    $mailer = WC()->mailer();
    $emails = $mailer->get_emails();

    // Check if the specific email exists in the array first
    if ( isset( $emails['WC_Email_Customer_On_Hold_Order'] ) ) {
        $on_hold_email = $emails['WC_Email_Customer_On_Hold_Order'];
        remove_action( 'woocommerce_order_status_pending_to_on-hold_notification', array( $on_hold_email, 'trigger' ), 10 );
    }
    if ( isset( $emails['WC_Email_Customer_Processing_Order'] ) ) {
        $processing_email = $emails['WC_Email_Customer_Processing_Order'];
        // This is the one usually triggered by Stripe/Credit Card payments
        remove_action( 'woocommerce_order_status_pending_to_processing_notification', array( $processing_email, 'trigger' ), 10 );
        // This handles manual moves from On-Hold to Processing
        remove_action( 'woocommerce_order_status_on-hold_to_processing_notification', array( $processing_email, 'trigger' ), 10 );
        // Safety: catch transitions from 'Completed' just in case
        remove_action( 'woocommerce_order_status_completed_to_processing_notification', array( $processing_email, 'trigger' ), 10 );
    }

    // --- Stop Admin email from firing before PDF is ready ---
    if ( isset( $emails['WC_Email_New_Order'] ) ) {
        $new_order_email = $emails['WC_Email_New_Order'];
        remove_action( 'woocommerce_order_status_pending_to_processing_notification', array( $new_order_email, 'trigger' ), 10 );
        remove_action( 'woocommerce_order_status_pending_to_on-hold_notification', array( $new_order_email, 'trigger' ), 10 );
        remove_action( 'woocommerce_order_status_failed_to_processing_notification', array( $new_order_email, 'trigger' ), 10 );
        remove_action( 'woocommerce_order_status_failed_to_on-hold_notification', array( $new_order_email, 'trigger' ), 10 );
    }

    // --- NEW: Stop Customer Failed Email from firing synchronously ---
    if ( isset( $emails['WC_Email_Customer_Failed_Order'] ) ) {
        $failed_email = $emails['WC_Email_Customer_Failed_Order'];
        remove_action( 'woocommerce_order_status_pending_to_failed_notification', array( $failed_email, 'trigger' ), 10 );
        remove_action( 'woocommerce_order_status_on-hold_to_failed_notification', array( $failed_email, 'trigger' ), 10 );
    }

    // --- NEW: Stop Customer Cancelled Email from firing synchronously ---
    if ( isset( $emails['WC_Email_Customer_Cancelled_Order'] ) ) {
        $cancelled_email = $emails['WC_Email_Customer_Cancelled_Order'];
        remove_action( 'woocommerce_order_status_pending_to_cancelled_notification', array( $cancelled_email, 'trigger' ), 10 );
        remove_action( 'woocommerce_order_status_on-hold_to_cancelled_notification', array( $cancelled_email, 'trigger' ), 10 );
        remove_action( 'woocommerce_order_status_processing_to_cancelled_notification', array( $cancelled_email, 'trigger' ), 10 );
    }
    return $emails_array;
}

// Ensure your Composer autoloader is already included at the top of your file:
// require_once ABSPATH . 'vendor/autoload.php';

/**
 * Fetches a PDF from S3 and saves it to a temporary local file
 * within a unique temporary directory, ensuring a clean filename for email attachments.
 * This temporary directory and its contents will be automatically deleted on script shutdown.
 *
 * @param int $order_id The WooCommerce Order ID.
 * @return string|false The path to the temporary file on success (with clean basename), or false on failure.
 */
function get_order_quote_pdf_from_s3_as_temp_file($order_id) {
    if ( ! function_exists( 'wc_get_order' ) ) {
        error_log(__FUNCTION__ . ': WooCommerce is not active.');
        return false;
    }

    $order = wc_get_order($order_id); // HPOS compatible

    if (!$order) {
        error_log(__FUNCTION__ . ': Order not found for ID: ' . $order_id);
        return false;
    }

    // Retrieve S3 Object Key ---
    $s3ObjectKey = $order->get_meta('_pdf_s3ObjectKey');

    if (empty($s3ObjectKey)) {
        error_log(__FUNCTION__ . ': S3 object key missing or empty for order ID: ' . $order_id);
        return false;
    }

    // --- S3 Client Initialization ---
    // Was relying on getenv('AWS_REGION')/getenv('S3_PDF_BUCKET_NAME') plus the AWS
    // SDK's default credential chain (an IAM instance role or real env vars), which
    // only ever worked on Vern's EB server. Cloudways has neither: no IAM role, and
    // putenv()/real env vars aren't reliably usable here (see the TAXJAR_API_KEY
    // putenv() outage, 2026-08-27). Prefer defined() wp-config.php constants first,
    // matching that same established pattern, falling back to getenv() for any
    // other environment where it still works.
    $bucketName = defined('STARKE_S3_PDF_BUCKET_NAME') ? STARKE_S3_PDF_BUCKET_NAME : getenv('S3_PDF_BUCKET_NAME');
    $s3Region   = defined('STARKE_S3_PDF_REGION') ? STARKE_S3_PDF_REGION : (getenv('AWS_REGION') ?: 'us-east-1');

    if (!$bucketName) {
        error_log(__FUNCTION__ . ': no S3 PDF bucket configured (STARKE_S3_PDF_BUCKET_NAME not defined and S3_PDF_BUCKET_NAME env var not set).');
        return false;
    }

    $s3ClientArgs = [
        'region'  => $s3Region,
        'version' => 'latest',
    ];
    // Only pass explicit credentials if configured (defined() constants below),
    // otherwise fall through to the SDK's default chain (IAM role/env vars), which
    // is what actually worked on the original EB server.
    if ( defined('STARKE_S3_PDF_ACCESS_KEY_ID') && defined('STARKE_S3_PDF_SECRET_ACCESS_KEY') ) {
        $s3ClientArgs['credentials'] = [
            'key'    => STARKE_S3_PDF_ACCESS_KEY_ID,
            'secret' => STARKE_S3_PDF_SECRET_ACCESS_KEY,
        ];
    }
    $s3Client = new S3Client($s3ClientArgs);

    // Step 1: Determine the desired display filename for the email attachment.
    // This will typically be like '12345.pdf' or 'quote_67890.pdf'.
    $desired_display_filename = basename($s3ObjectKey);
    
    // Step 2: Create a unique temporary directory for this specific PDF download.
    // This ensures no filename collisions within the system's shared temporary directory.
    $unique_temp_dir_for_pdf = sys_get_temp_dir() . '/' . uniqid('email_pdf_') . mt_rand(1000, 9999);
    
    // Create the directory. The 0755 permissions are standard, true enables recursive creation.
    if (!mkdir($unique_temp_dir_for_pdf, 0755, true)) {
        error_log(__FUNCTION__ . ': Failed to create unique temporary directory: ' . $unique_temp_dir_for_pdf);
        return false;
    }

    // Step 3: Define the full path to the final temporary file within this unique directory.
    // The basename of this path ($desired_display_filename) will be what the email client shows.
    $final_attachment_path = $unique_temp_dir_for_pdf . '/' . $desired_display_filename;

    try {
        // Get the object from S3
        $result = $s3Client->getObject([
            'Bucket' => $bucketName,
            'Key'    => $s3ObjectKey,
        ]);

        // Get the PDF content as a string
        $pdf_content = (string) $result['Body'];

        // Save the PDF content to the temporary file within the unique directory
        if ( ! file_put_contents($final_attachment_path, $pdf_content) ) {
            error_log(__FUNCTION__ . ': Failed to write PDF content to temporary file: ' . $final_attachment_path);
            
            // Clean up the created directory if file writing fails
            if (is_dir($unique_temp_dir_for_pdf)) {
                @rmdir($unique_temp_dir_for_pdf); // Try to remove empty dir
            }
            return false;
        }

        // wc_get_logger()->info('$final_attachment_path: ' . $final_attachment_path, ['source' => 'pdf_debug2']);

        // Step 4: Register a shutdown function to delete the unique temporary directory and its contents.
        // This ensures cleanup even if the script terminates unexpectedly.
        register_shutdown_function(function($dir_path) {
            if (is_dir($dir_path)) {
                // Use RecursiveDirectoryIterator for robust deletion of directory and its contents
                $it = new RecursiveDirectoryIterator($dir_path, RecursiveDirectoryIterator::SKIP_DOTS);
                $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($files as $file) {
                    if ($file->isDir()) {
                        @rmdir($file->getRealPath()); // Use @ to suppress errors if dir is already gone
                    } else {
                        @unlink($file->getRealPath()); // Use @ to suppress errors if file is already gone
                    }
                }
                @rmdir($dir_path); // Remove the top-level directory
                // wc_get_logger()->warning('Deleted temporary PDF directory: ' . $dir_path, ['source' => 'pdf_debug']); // Uncomment for debugging
            }
        }, $unique_temp_dir_for_pdf); // Pass the directory path to the closure

        return $final_attachment_path; // Return the path to the temporary file (with clean basename)

    } catch (AwsException $e) {
        error_log(__FUNCTION__ . ': S3 PDF retrieval error for order ' . $order_id . ' (Key: ' . $s3ObjectKey . '): ' . $e->getMessage());
        
        // Clean up the created directory if S3 download fails
        if (is_dir($unique_temp_dir_for_pdf)) {
             $it = new RecursiveDirectoryIterator($unique_temp_dir_for_pdf, RecursiveDirectoryIterator::SKIP_DOTS);
             $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
             foreach ($files as $file) {
                 if ($file->isDir()) {
                     @rmdir($file->getRealPath());
                 } else {
                     @unlink($file->getRealPath());
                 }
             }
             @rmdir($unique_temp_dir_for_pdf);
        }
        return false;
    } catch (Exception $e) {
        error_log(__FUNCTION__ . ': General error during PDF fetching for order ' . $order_id . ': ' . $e->getMessage());

        // Clean up the created directory on general exception
        if (is_dir($unique_temp_dir_for_pdf)) {
             $it = new RecursiveDirectoryIterator($unique_temp_dir_for_pdf, RecursiveDirectoryIterator::SKIP_DOTS);
             $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
             foreach ($files as $file) {
                 if ($file->isDir()) {
                     @rmdir($file->getRealPath());
                 } else {
                     @unlink($file->getRealPath());
                 }
             }
             @rmdir($unique_temp_dir_for_pdf);
        }
        return false;
    }
}

/**
 * Adds the order PDF from S3 as an attachment to specific WooCommerce emails.
 *
 * @param array    $attachments     Current array of email attachments.
 * @param string   $email_id        The ID of the email being sent (e.g., 'customer_quote_sending').
 * @param WC_Order $order           The order object (this is the $this->object from the filter).
 * @param WC_Email $email_instance  The email object instance itself (this is the $this from the filter).
 * @return array Modified array of email attachments.
 */
function add_order_quote_pdf_to_email_attachments($attachments, $email_id, $order, $email_instance) {
    // --- 1. Define allowed emails (Standard + Balance Invoice types) ---
    $allowed_emails = [
        'new_order',
        'customer_quote_sending', 
        'customer_on_hold_order', 
        'customer_processing_order',
        'customer_invoice',         // Balance Invoice: Pending
        'customer_completed_order'  // Balance Invoice: Paid
    ];

    if ( in_array( $email_id, $allowed_emails ) && $order instanceof WC_Order ) {
        
        // --- 2. Determine Target Order (Always the Parent/Project Order) ---
        $target_order_id = $order->get_id();

        // If this is a Balance Invoice, we want the PDF from the Parent Order
        if ( 'yes' === $order->get_meta( '_starke_is_balance_invoice', true ) ) {
            $parent_id = $order->get_parent_id();
            if ( $parent_id ) {
                $target_order_id = $parent_id;
            }
        }

        // --- 3. Fetch PDF using the Target ID ---
        $pdf_file_path = get_order_quote_pdf_from_s3_as_temp_file( $target_order_id );

        if ( $pdf_file_path ) {
            $attachments[] = $pdf_file_path;

            // --- Crucial: Register a shutdown function to delete the temporary file ---
            register_shutdown_function(function($path) {
                if (file_exists($path)) {
                    unlink($path);
                }
            }, $pdf_file_path); 
        } else {
            error_log('Failed to retrieve or generate PDF attachment for email ID: ' . $email_id . ' for order ' . $order->get_id());
        }
    }

    return $attachments;
}
add_filter('woocommerce_email_attachments', 'add_order_quote_pdf_to_email_attachments', 10, 4);

/**
 * @snippet       Set Product Image & Name Links Based on WooCommerce Email Type (with Exceptions)
 * @author        Gemini
 * @description   Links images and names conditionally, but hides the image and/or link for specific product IDs. Ensures consistent styling for all product names.
 * @testedwith    WooCommerce 8.0
 */

// Define product IDs for special handling to make them easy to manage.
$product_exceptions = [
    // For these IDs, the image will be hidden AND the name/image will not be linked.
    'hide_image_and_link' => [444, 2843],
    // For these IDs, the image will be shown, but the name/image will NOT be linked.
    'remove_link_only'    => custom_profile_ids(),
];

// Step 1: Before the order table, decide which link functions to use for both image and name.
add_action( 'woocommerce_email_before_order_table', 'setup_email_specific_links', 10, 4 );
function setup_email_specific_links( $order, $sent_to_admin, $plain_text, $email ) {
    
    // Check the ID of the email being sent.
    if ( 'customer_quote_sending' === $email->id ) {
        // If it's the custom quote email, add filters that link to the quote page.
        add_filter( 'woocommerce_order_item_thumbnail', 'wc_email_image_link_to_quote', 10, 2 );
        add_filter( 'woocommerce_order_item_name', 'wc_email_name_link_to_quote', 10, 2 );
    } 
    else {
        // For ALL other emails, add filters that link to the product page.
        add_filter( 'woocommerce_order_item_thumbnail', 'wc_email_image_link_to_product', 10, 2 );
        add_filter( 'woocommerce_order_item_name', 'wc_email_name_link_to_product', 10, 2 );
    }
}

// --- Linking Functions to Product Page --- //

function wc_email_image_link_to_product( $image_html, $item ) {
    $product_exceptions = [
        // For these IDs, the image will be hidden AND the name/image will not be linked.
        'hide_image_and_link' => [444, 2843],
        // For these IDs, the image will be shown, but the name/image will NOT be linked.
        'remove_link_only'    => custom_profile_ids(),
    ];
    
    $product = $item->get_product();

    if ( $product ) {
        if ( in_array( $product->get_id(), $product_exceptions['hide_image_and_link'], true ) ) {
            return ''; // Hide image completely.
        }
        if ( in_array( $product->get_id(), $product_exceptions['remove_link_only'], true ) ) {
            return $image_html; // Show image, but don't link it.
        }
        if ( $product->get_permalink() ) {
            return '<a href="' . esc_url( $product->get_permalink() ) . '">' . $image_html . '</a>';
        }
    }
    return $image_html;
}

function wc_email_name_link_to_product( $name, $item ) {
    $product_exceptions = [
        'hide_image_and_link' => [444, 2843],
        'remove_link_only'    => custom_profile_ids(),
    ];
    $product = $item->get_product();
    
    // FIX: Removed !important to ensure Gmail reads the inline style
    $styled_name = '<span style="font-size: 1.4em; font-weight: 700; color: #6431f6 !important;">' . $name . '</span>';
    
    if ( $product ) {
        if ( in_array( $product->get_id(), $product_exceptions['hide_image_and_link'], true ) || in_array( $product->get_id(), $product_exceptions['remove_link_only'], true ) ) {
            return $styled_name;
        }
        if ( $product->get_permalink() ) {
            // FIX: Removed !important
            return '<a href="' . esc_url( $product->get_permalink() ) . '" style="color: #6431f6 !important; text-decoration: none;">' . $styled_name . '</a>';
        }
    }
    return $styled_name;
}


// --- Linking Functions to Quote Page --- //

function wc_email_image_link_to_quote( $image_html, $item ) {
    $product_exceptions = [
        // For these IDs, the image will be hidden AND the name/image will not be linked.
        'hide_image_and_link' => [444, 2843],
        // For these IDs, the image will be shown, but the name/image will NOT be linked.
        'remove_link_only'    => custom_profile_ids(),
    ];
    
    $product = $item->get_product();

    if ( $product ) {
        if ( in_array( $product->get_id(), $product_exceptions['hide_image_and_link'], true ) ) {
            return ''; // Hide image completely.
        }
        if ( in_array( $product->get_id(), $product_exceptions['remove_link_only'], true ) ) {
            return $image_html; // Show image, but don't link it.
        }
    }

    $order = $item->get_order();
    if ( $order ) {
        // Assuming generate_link_for_quote() is your custom function.
        return '<a href="' . esc_url( generate_link_for_quote($order) ) . '">' . $image_html . '</a>';
    }
    return $image_html;
}

function wc_email_name_link_to_quote( $name, $item ) {
    $product_exceptions = [
        // For these IDs, the image will be hidden AND the name/image will not be linked.
        'hide_image_and_link' => [444, 2843],
        // For these IDs, the image will be shown, but the name/image will NOT be linked.
        'remove_link_only'    => custom_profile_ids(),
    ];
    
    $product = $item->get_product();

    // Always apply the styling first, using a <span> instead of a <div>.
    $styled_name = '<span style="font-size: 1.4em; font-weight: 700;">' . $name . '</span>';

    if ( $product ) {
        // If the ID is in an exception array, return the styled name without a link.
        if ( in_array( $product->get_id(), $product_exceptions['hide_image_and_link'], true ) || in_array( $product->get_id(), $product_exceptions['remove_link_only'], true ) ) {
            return $styled_name;
        }
    }
    
    $order = $item->get_order();
    if ( $order ) {
        // Assuming generate_link_for_quote() is your custom function.
        // Wrap the styled name in a link.
        return '<a href="' . esc_url( generate_link_for_quote($order) ) . '" style="color: #6431f6; text-decoration: none;">' . $styled_name . '</a>';
    }
    // Fallback to just the styled name if something goes wrong.
    return $styled_name;
}


// Step 2: After the order table, remove all the filters to clean up.
add_action( 'woocommerce_email_after_order_table', 'cleanup_email_specific_links', 10 );
function cleanup_email_specific_links() {
    // Remove all possible filters. It's safe to remove a filter that wasn't added.
    remove_filter( 'woocommerce_order_item_thumbnail', 'wc_email_image_link_to_product', 10 );
    remove_filter( 'woocommerce_order_item_thumbnail', 'wc_email_image_link_to_quote', 10 );
    remove_filter( 'woocommerce_order_item_name', 'wc_email_name_link_to_product', 10 );
    remove_filter( 'woocommerce_order_item_name', 'wc_email_name_link_to_quote', 10 );
}

/**
 * @snippet       Display Custom "Samples Shipping Address" in WooCommerce Emails
 * @author        Gemini
 * @description   Checks for a samples shipping address array and displays it using a stable table layout to ensure perfect alignment.
 */
add_action( 'woocommerce_email_customer_details', 'display_samples_shipping_address_in_emails', 25, 4 );
function display_samples_shipping_address_in_emails( $order, $sent_to_admin, $plain_text, $email ) {
    
    // Get the entire address array from the single meta key.
    $samples_address = $order->get_meta( '_samples_full_shipping_address' );

    // Only display the address block if the array exists and a required field (like address_1) is not empty.
    if ( ! empty( $samples_address ) && ! empty( $samples_address['address_1'] ) ) {

        // Get the beautifully formatted address from WooCommerce.
        $formatted_address = WC()->countries->get_formatted_address( $samples_address );

        if ( $formatted_address ) {
            ?>
            <div style="margin-bottom: 40px;">
                <table cellspacing="0" cellpadding="0" style="width: 49%; vertical-align: top; margin-bottom: 40px;" border="0">
                    <tr>
                        <td style="text-align:left; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; border:0; padding:0;" valign="top">
                            <h2 style="color: #6431f6; display: block; font-family: &quot;Helvetica Neue&quot;, Helvetica, Roboto, Arial, sans-serif; font-size: 18px; font-weight: bold; line-height: 130%; margin: 0 0 18px; text-align: left;"><?php esc_html_e( 'Samples Shipping Address', 'woocommerce' ); ?></h2>
                            <address style="padding: 12px; color: #636363; border: 1px solid #e5e5e5;">
                                <?php echo $formatted_address; ?>
                                <?php if ( ! empty( $samples_address['phone'] ) ) : ?>
                                    <br/><?php echo esc_html( $samples_address['phone'] ); ?>
                                <?php endif; ?>
                            </address>
                        </td>
                    </tr>
                </table>
            </div>
            <?php
        }
    }
}

/**
 * Custom Email Styles: Mobile Inversion Fixes & Color Enforcement
 */
add_filter( 'woocommerce_email_styles', 'starke_custom_email_styles', 999 );
function starke_custom_email_styles( $css ) {
    $theme_purple = '#6431f6';

    // 1. NUCLEAR REPLACEMENT (Case Insensitive)
    // We use str_ireplace to catch #7F54B3, #7f54b3, etc.
    $css = str_ireplace( '#7f54b3', $theme_purple, $css ); 
    $css = str_ireplace( '#96588a', $theme_purple, $css ); 
    $css = str_ireplace( '#a46497', $theme_purple, $css ); 

    // 2. Custom CSS Overrides
    $css .= "
        /* FORCE LIGHT MODE */
        :root {
            color-scheme: light;
            supported-color-schemes: light;
        }

        /* --- HEADER FIXES --- */
        
        /* 1. The Outer Wrapper (The Main Gradient) */
        #header_wrapper { 
            background-color: $theme_purple !important;
            background-image: linear-gradient($theme_purple, $theme_purple) !important;
            border: none !important;
        }

        /* 2. The Inner Container (Make SOLID Purple to stop Text Inversion) */
        #template_header {
            background-color: #6431f6 !important; /* Changed from transparent to solid */
            border-bottom: 0 !important;
        }

        /* 3. The Heading Text (Force White & Stop Inversion) */
        #template_header h1,
        #template_header h1 a,
        #template_header h1 span { 
            /* FORCE WHITE: We use the webkit override which iOS/Apple Mail respects */
            color: #ffffff !important;
            -webkit-text-fill-color: #ffffff !important;
            background-color: transparent !important;
            text-shadow: none !important; /* Removing shadow helps prevent inversion triggers */
            font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif !important;
            font-weight: bold !important;
            line-height: 150% !important;
            text-align: center !important;
            text-decoration: none !important;
        }

        /* --- BODY TEXT FIXES --- */

        /* Force Theme Purple on Content Headings */
        #body_content h2, 
        #body_content h3 { 
            color: #6431f6 !important; 
            font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif !important;
        }

        /* Force Theme Purple on Links */
        a { 
            color: #6431f6 !important; 
            text-decoration: none !important;
            font-weight: normal !important;
        }

        /* --- LAYOUT FIXES --- */

        /* Logo Spacing */
        #template_header_image { 
            padding-bottom: 30px !important; 
        }

        /* Consistent Font Sizes for Intro Paragraphs */
        #body_content_inner > p {
            font-size: 1.25em !important;
            line-height: 1.5em !important;
            color: #636363;
            margin-bottom: 16px !important;
        }
    ";
    return $css;
}

/**
 * Force the "Base Color" setting in WooCommerce to be your purple.
 * This ensures inline styles generated by WC use the right hex code.
 */
add_filter( 'woocommerce_email_get_option', 'starke_force_email_color_setting', 10, 4 );
function starke_force_email_color_setting( $value, $option, $original_value, $email ) {
    if ( 'woocommerce_email_base_color' === $option ) {
        return '#6431f6'; // Your Starke Theme Purple
    }
    return $value;
}

/**
 * FORCE DB OPTION for Base Color.
 * This ensures the HTML generator (which calls get_option directly) sees the correct color.
 */
add_filter( 'pre_option_woocommerce_email_base_color', 'starke_force_db_email_color' );
function starke_force_db_email_color() {
    return '#6431f6'; 
}

/**
 * Custom Subject Line for On-Hold Email using PO Number / Starke ID
 */
add_filter( 'woocommerce_email_subject_customer_on_hold_order', 'starke_custom_on_hold_subject', 10, 2 );
function starke_custom_on_hold_subject( $subject, $order ) {
    $po_number = $order->get_meta( '_po_number_job_name', true );
    $display_id = ! empty( $po_number ) ? 'PO ' . $po_number : 'Order ' . ( $order->get_meta( '_starke_order_number', true ) ?: $order->get_order_number() );

    return sprintf( 'Order Acknowledgement: %s %s', get_bloginfo( 'name' ), $display_id );
}

/**
 * Custom Email Heading for On-Hold Email
 */
add_filter( 'woocommerce_email_heading_customer_on_hold_order', 'starke_custom_on_hold_heading', 10, 2 );
function starke_custom_on_hold_heading( $heading, $order ) {
    return 'Order Confirmation & Next Steps';
}

/**
 * Custom Subject Line for Processing Orders (Paid/Active)
 */
add_filter( 'woocommerce_email_subject_customer_processing_order', 'starke_custom_processing_subject', 10, 2 );
function starke_custom_processing_subject( $subject, $order ) {
    $po_number = $order->get_meta( '_po_number_job_name', true );
    $display_id = ! empty( $po_number ) ? 'PO ' . $po_number : 'Order ' . ( $order->get_meta( '_starke_order_number', true ) ?: $order->get_order_number() );

    return sprintf( 'Order Acknowledgement: %s %s', get_bloginfo( 'name' ), $display_id );
}

/**
 * Custom Heading for Processing Orders
 * Replaces "Thank you for your order" with something more industrial/professional.
 */
add_filter( 'woocommerce_email_heading_customer_processing_order', 'starke_custom_processing_heading', 10, 2 );
function starke_custom_processing_heading( $heading, $order ) {
    return 'Production Scheduled & Confirmed';
}

/**
 * Clear default footer text so our custom email-footer.php template
 * has full control over the layout.
 */
add_filter( 'woocommerce_email_footer_text', 'starke_clear_default_footer_text' );
function starke_clear_default_footer_text( $text ) {
    return ''; 
}

/**
 * NUCLEAR OPTION: Force Swap Colors in Final HTML.
 * This runs AFTER all templates are generated and physically replaces the Hex codes
 * in the HTML string.
 */
add_filter( 'woocommerce_mail_content', 'starke_nuclear_color_swap' );
function starke_nuclear_color_swap( $content ) {
    $theme_purple = '#6431f6'; // Your Starke Theme Purple
    
    // 1. Replace the "Old Blue" with Starke Purple
    $content = str_ireplace( '#7f54b3', $theme_purple, $content );
    
    // 2. Replace Default WooCommerce Purples
    $content = str_ireplace( '#96588a', $theme_purple, $content );
    $content = str_ireplace( '#a46497', $theme_purple, $content );

    // 3. FORCE HEADER TEXT TO WHITE (Dark Mode Fix)
    // We target the unique "text-shadow" that only exists in the header.
    // REMOVED: mix-blend-mode (caused washed out look).
    // ADDED: -webkit-text-fill-color (Forces iOS to respect the color without dimming).
    
    // Search for the unique part of the header style
    $header_signature = 'text-shadow: 0 1px 0 #6431f6;';
    
    // The replacement forces the off-white color and uses the webkit override for mobile
    $header_fix = 'color: #fffffe !important; -webkit-text-fill-color: #fffffe !important; text-shadow: 0 1px 0 #6431f6;';
    
    $content = str_replace( $header_signature, $header_fix, $content );
    
    return $content;
}

/**
 * ==============================================================================
 * BALANCE INVOICE EMAIL CUSTOMIZATIONS
 * ==============================================================================
 */

/**
 * Helper function to check if an order is a Starke Balance Invoice.
 */
function is_starke_balance_invoice( $order ) {
    // Ensure $order is actually an order object before checking meta
    if ( ! is_a( $order, 'WC_Order' ) ) {
        return false;
    }
    return 'yes' === $order->get_meta( '_starke_is_balance_invoice', true );
}

/**
 * Helper to get the display ID (PO Number, Parent Starke ID, or Parent Order ID)
 */
function get_starke_balance_display_id( $order ) {
    $parent_id = $order->get_parent_id();
    $parent_order = wc_get_order( $parent_id );
    
    // 1. Try PO Number first
    $po_number = ($parent_order) ? $parent_order->get_meta( '_po_number_job_name', true ) : '';
    if ( ! empty( $po_number ) ) {
        return 'PO ' . $po_number;
    }

    // 2. Fallback to Starke ID
    $starke_id = ($parent_order) ? $parent_order->get_meta( '_starke_order_number', true ) : '';
    if ( empty( $starke_id ) && $parent_order ) {
        $starke_id = $parent_order->get_order_number();
    }
    // Fallback to current order ID if parent fails for some reason
    $final_id = empty( $starke_id ) ? $order->get_order_number() : $starke_id;

    return 'Order ' . $final_id;
}


// ==============================================================================
// 1. THE "PAYMENT PENDING" INVOICE (sent when balance invoice is created)
// ==============================================================================

add_filter( 'woocommerce_email_subject_customer_invoice', 'starke_balance_invoice_subject', 10, 2 );
function starke_balance_invoice_subject( $subject, $order ) {
    if ( is_starke_balance_invoice( $order ) ) {
        return sprintf( 'Balance Invoice: %s', get_starke_balance_display_id( $order ) );
    }
    return $subject;
}

add_filter( 'woocommerce_email_heading_customer_invoice', 'starke_balance_invoice_heading', 10, 2 );
function starke_balance_invoice_heading( $heading, $order ) {
    if ( is_starke_balance_invoice( $order ) ) {
        return 'Balance Due & Payment Instructions';
    }
    return $heading;
}

// NOTE: Body text for the pending invoice is handled in the 
// 'customer-invoice.php' template override.


// ==============================================================================
// 2. THE "COMPLETED" EMAIL (Sent when this specific balance invoice is PAID)
// ==============================================================================

/**
 * A. Custom Subject Line for Completed Orders (Balance & Standard)
 */
add_filter( 'woocommerce_email_subject_customer_completed_order', 'starke_completed_balance_subject', 10, 2 );
function starke_completed_balance_subject( $subject, $order ) {
    if ( is_starke_balance_invoice( $order ) ) {
        return sprintf( 'Payment Received: Balance Invoice for %s', get_starke_balance_display_id( $order ) );
    } else {
        // STANDARD ORDER SUBJECT
        $po_number = $order->get_meta( '_po_number_job_name', true );
        $display_id = ! empty( $po_number ) ? 'PO ' . $po_number : 'Order ' . ( $order->get_meta( '_starke_order_number', true ) ?: $order->get_order_number() );
        
        return sprintf( '%s: Payment Verified & Ready for Shipment', $display_id );
    }
}

/**
 * B. Custom Heading for Completed Orders (Balance & Standard)
 */
add_filter( 'woocommerce_email_heading_customer_completed_order', 'starke_completed_balance_heading', 10, 2 );
function starke_completed_balance_heading( $heading, $order ) {
    if ( is_starke_balance_invoice( $order ) ) {
        // Balance Invoice Heading
        return 'Balance Payment Receipt';
    } else {
        // STANDARD ORDER HEADING: Signifies completion
        return 'Payment Verified & Order Complete';
    }
}

// NOTE: Body text for the completed balance email is now handled 
// directly in the new 'customer-completed-order.php' template override.

// ==============================================================================
// 3. THE "ON HOLD" EMAIL (Sent when Check Payment is Submitted)
// ==============================================================================

/**
 * A. Custom Subject Line for On Hold (Invoice Submitted)
 */
add_filter( 'woocommerce_email_subject_customer_on_hold_order', 'starke_on_hold_balance_subject', 10, 2 );
function starke_on_hold_balance_subject( $subject, $order ) {
    if ( is_starke_balance_invoice( $order ) ) {
        return sprintf( 'Invoice Submitted: Balance Payment for %s', get_starke_balance_display_id( $order ) );
    }
    return $subject;
}

/**
 * B. Custom Heading for On Hold (Invoice Submitted)
 */
add_filter( 'woocommerce_email_heading_customer_on_hold_order', 'starke_on_hold_balance_heading', 10, 2 );
function starke_on_hold_balance_heading( $heading, $order ) {
    if ( is_starke_balance_invoice( $order ) ) {
        // Custom Heading
        return 'Balance Invoice Submitted';
    }
    return $heading;
}

/**
 * CUSTOMIZE Admin Failed Order Email
 */
add_filter( 'woocommerce_email_subject_failed_order', 'starke_admin_failed_subject', 10, 2 );
function starke_admin_failed_subject( $subject, $order ) {
    $display_id = 'Order ' . $order->get_order_number();
    
    if ( 'yes' === $order->get_meta( '_starke_is_balance_invoice', true ) ) {
        $display_id = get_starke_balance_display_id( $order );
    } else {
        $po_number = $order->get_meta( '_po_number_job_name', true );
        if ( ! empty( $po_number ) ) {
            $display_id = 'PO ' . $po_number;
        } else {
            $starke_id = $order->get_meta( '_starke_order_number', true );
            if ( ! empty( $starke_id ) ) $display_id = 'Order ' . $starke_id;
        }
    }

    $name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
    return sprintf( 'Alert: Transaction Declined - %s - %s', $display_id, $name );
}

add_filter( 'woocommerce_email_heading_failed_order', 'starke_admin_failed_heading', 10, 2 );
function starke_admin_failed_heading( $heading, $order ) {
    return 'Payment Processing Error';
}

/**
 * CUSTOMIZE Admin Cancelled Order Email
 */
add_filter( 'woocommerce_email_subject_cancelled_order', 'starke_admin_cancelled_subject', 10, 2 );
function starke_admin_cancelled_subject( $subject, $order ) {
    $display_id = 'Order ' . $order->get_order_number();
    
    if ( 'yes' === $order->get_meta( '_starke_is_balance_invoice', true ) ) {
        $display_id = get_starke_balance_display_id( $order );
    } else {
        $po_number = $order->get_meta( '_po_number_job_name', true );
        if ( ! empty( $po_number ) ) {
            $display_id = 'PO ' . $po_number;
        } else {
            $starke_id = $order->get_meta( '_starke_order_number', true );
            if ( ! empty( $starke_id ) ) $display_id = 'Order ' . $starke_id;
        }
    }

    $name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
    return sprintf( 'STOP: %s Cancelled - %s', $display_id, $name );
}

add_filter( 'woocommerce_email_heading_cancelled_order', 'starke_admin_cancelled_heading', 10, 2 );
function starke_admin_cancelled_heading( $heading, $order ) {
    return 'Order Cancelled - Stop Production';
}

/**
 * CUSTOMIZE Password Reset Email
 * Goal: Professional security tone.
 */
add_filter( 'woocommerce_email_subject_customer_reset_password', 'starke_reset_password_subject', 10, 2 );
function starke_reset_password_subject( $subject, $user_login ) {
    return 'Security Update: Password Reset Request';
}

add_filter( 'woocommerce_email_heading_customer_reset_password', 'starke_reset_password_heading', 10, 2 );
function starke_reset_password_heading( $heading, $user_login ) {
    return 'Password Reset Request';
}

/**
 * CUSTOMIZE Customer Refunded Email
 */
add_filter( 'woocommerce_email_subject_customer_refunded_order', 'starke_refunded_subject', 10, 2 );
function starke_refunded_subject( $subject, $order ) {
    $display_id = 'Order ' . $order->get_order_number();
    
    if ( 'yes' === $order->get_meta( '_starke_is_balance_invoice', true ) ) {
        $display_id = get_starke_balance_display_id( $order );
    } else {
        $po_number = $order->get_meta( '_po_number_job_name', true );
        if ( ! empty( $po_number ) ) {
            $display_id = 'PO ' . $po_number;
        } else {
            $starke_id = $order->get_meta( '_starke_order_number', true );
            if ( ! empty( $starke_id ) ) $display_id = 'Order ' . $starke_id;
        }
    }

    return sprintf( 'Transaction Receipt: Refund Processed - %s', $display_id );
}

add_filter( 'woocommerce_email_heading_customer_refunded_order', 'starke_refunded_heading', 10, 2 );
function starke_refunded_heading( $heading, $order ) {
    return 'Refund Notification';
}

/**
 * CUSTOMIZE Customer New Account Email
 * Goal: Professional welcome tone for both Admin-created and Self-registered accounts.
 */
add_filter( 'woocommerce_email_subject_customer_new_account', 'starke_new_account_subject', 10, 2 );
function starke_new_account_subject( $subject, $user_object ) {
    return 'Welcome to Starke Millwork: Account Confirmation';
}

add_filter( 'woocommerce_email_heading_customer_new_account', 'starke_new_account_heading', 10, 2 );
function starke_new_account_heading( $heading, $user_object ) {
    return 'Account Details & Access';
}

/**
 * CUSTOMIZE Admin-Created User Email (Action Scheduler Strategy)
 * PROBLEM: "Race Conditions" in the main process invalidate the key.
 * SOLUTION: We move the email generation to a background worker process. 
 * This guarantees the database is settled before we generate the key.
 */

// 1. SAFETY: Nuclear cleanup of any old/duplicate hooks to stop "Double Emails"
remove_filter( 'wp_new_user_notification_email', 'starke_custom_wp_new_user_email', 10 );
remove_filter( 'wp_new_user_notification_email', 'starke_custom_wp_new_user_email', 9999 );
remove_filter( 'wp_new_user_notification_email', 'starke_intercept_and_schedule_email', 9999 );
remove_filter( 'wp_new_user_notification_email', 'starke_queue_user_email_for_shutdown', 9999 );

// 2. INTERCEPT: Block the native email & Queue the Async Job
// We use 'wp_send_new_user_notification_to_user' to abort the email BEFORE wp_mail() is called.
add_filter( 'wp_send_new_user_notification_to_user', 'starke_async_scheduler_intercept', 9999, 2 );
function starke_async_scheduler_intercept( $send, $user ) {
    
    // Capture the Admin UI checkboxes from the Add New User screen
    $notify_arch  = isset( $_POST['starke_notify_user'] ) ? true : false;
    $notify_terms = isset( $_POST['starke_notify_terms_change'] ) ? true : false;

    // A. Schedule the job to run "Immediately" in the background.
    /* TEMPORARILY DISABLE THIS 'IF' BLOCK WHEN IMPORTING/MIGRATING LEGACY USERS VIA CSV IMPORT */
    if ( function_exists( 'as_schedule_single_action' ) ) {
        // Check if pending action exists to prevent duplicates (Pass the checkbox flags too)
        if ( ! as_has_scheduled_action( 'starke_send_async_activation_email', array( $user->ID, $notify_arch, $notify_terms ) ) ) {
            as_schedule_single_action( 
                time(), 
                'starke_send_async_activation_email', 
                array( $user->ID, $notify_arch, $notify_terms ), 
                'starke_email_worker' 
            );
        }
    }

    // B. BLOCK THE IMMEDIATE EMAIL
    // Returning false completely short-circuits the native notification, 
    // preventing wp_mail() from firing empty variables and triggering an SES failure.
    return false;
}

// 3. WORKER: The background process that generates the valid key and sends the email
// NOTE: Now accepts 3 arguments to bring in the checkbox flags
add_action( 'starke_send_async_activation_email', 'starke_process_async_email', 10, 3 );
function starke_process_async_email( $user_id, $notify_arch = false, $notify_terms = false ) {
    
    // Clear cache to ensure we see the fresh user state
    clean_user_cache( $user_id );
    
    $user = get_userdata( $user_id );
    if ( ! $user ) return;

    // A. Generate a FRESH Key
    $key = get_password_reset_key( $user );
    
    if ( is_wp_error( $key ) ) return;

    // B. Build the Frontend Link (WooCommerce Endpoint)
    $action_url = add_query_arg( 
        array( 
            'key'   => $key, 
            'login' => $user->user_login 
        ), 
        wc_get_endpoint_url( 'lost-password', '', wc_get_page_permalink( 'myaccount' ) ) 
    );

    // C. Force HTML
    add_filter( 'wp_mail_content_type', 'starke_async_html_type' );

    // D. Build the Email
    $subject = 'Welcome to Starke Millwork: Account Activation';
    
    // Check if they requested architect access
    $arch_status = get_user_meta( $user_id, '_starke_architect_status', true );
    $requested_architect = ( $arch_status === 'pending' );

    // --- DETERMINE FIRST NAME OR FALLBACK TO EMAIL PREFIX ---
    $customer_first_name = $user->first_name;
    if ( empty( trim( $customer_first_name ) ) ) {
        $customer_first_name = get_user_meta( $user_id, 'billing_first_name', true );
    }
    if ( empty( trim( $customer_first_name ) ) && ! empty( $user->display_name ) && $user->display_name !== $user->user_email ) {
        $customer_first_name = $user->display_name;
    }
    if ( empty( trim( $customer_first_name ) ) ) {
        $email_parts = explode( '@', $user->user_email );
        $customer_first_name = ucfirst( $email_parts[0] );
    }
    
    // Shared style for the 16px larger text
    $p_style = "font-size: 16px; line-height: 1.5em; color: #333; margin-bottom: 20px;";

    ob_start();
    do_action( 'woocommerce_email_header', 'Account Details & Access', null );
    ?>
    
    <p style="<?php echo $p_style; ?>">
        Hi <?php echo esc_html( $customer_first_name ); ?>,
    </p>

    <p style="<?php echo $p_style; ?>">
        An account has been established for you at Starke Millwork to facilitate your project coordination.
    </p>

    <p style="font-size: 16px; line-height: 1.5em; color: #333; margin-bottom: 15px;">
        To activate your account and access our digital platform, please set your secure password using the link below. You will then have immediate access to:
    </p>

    <ul style="font-size: 16px; line-height: 1.5em; margin-bottom: 30px; color: #333;">
        <li style="margin-bottom: 8px;"><strong>View Pricing & Downloads:</strong> Access trade pricing and download technical profile drawings.</li>
        <li style="margin-bottom: 8px;"><strong>Generate Project Quotes:</strong> Build custom quotes for your architectural molding projects.</li>
        <li style="margin-bottom: 8px;"><strong>Order Samples:</strong> Request molding profile samples for client approval.</li>
        <li style="margin-bottom: 8px;"><strong>Purchase Linear Moldings:</strong> Directly order linear molding profiles for production.</li>
    </ul>

    <?php if ( $requested_architect && !$notify_arch ) : ?>
    <div style="margin-bottom: 30px; padding: 15px; background-color: #f9f9f9; border-left: 4px solid #6431f6; font-size: 16px; line-height: 1.5em; color: #333;">
        <strong>Architect Access Request:</strong> We have received your request for Architect Access. Our team is currently reviewing your account, and you will receive a separate email notification regarding your approval status shortly.
    </div>
    <?php endif; ?>

    <?php 
    // --- NEW: BUNDLED ADMIN ARCHITECT ACCESS INJECTION ---
    if ( $notify_arch && $arch_status ) : 
        if ( $arch_status === 'approved' ) : ?>
            <div style="margin-bottom: 30px; padding: 15px; background-color: #f9f9f9; border-left: 4px solid #6431f6; font-size: 16px; line-height: 1.5em; color: #333;">
                <strong>Architect Access Approved:</strong> Good news! Your account has been granted Architect Access. You can now log in to download DXF drawings for our profiles.
            </div>
        <?php elseif ( $arch_status === 'pending' && !$requested_architect ) : ?>
            <div style="margin-bottom: 30px; padding: 15px; background-color: #f9f9f9; border-left: 4px solid #6431f6; font-size: 16px; line-height: 1.5em; color: #333;">
                <strong>Architect Access Under Review:</strong> Your Architect Access status is currently under review. We will notify you via email once a final decision has been made.
            </div>
        <?php elseif ( $arch_status === 'denied' ) : ?>
             <div style="margin-bottom: 30px; padding: 15px; background-color: #f9f9f9; border-left: 4px solid #cc1818; font-size: 16px; line-height: 1.5em; color: #333;">
                <strong>Architect Access Update:</strong> Unfortunately, we are unable to grant access to DXF downloads at this time. If you have questions regarding this decision, please contact us.
            </div>
        <?php endif; 
    endif; ?>

    <?php
    // --- NEW: BUNDLED ADMIN PAYMENT TERMS INJECTION ---
    if ( $notify_terms ) : 
        $new_term = get_user_meta( $user_id, '_starke_assigned_payment_term', true );
        if ( empty($new_term) ) $new_term = 'no_terms';
        
        $term_definitions = [
            'no_terms' => [
                'label' => 'No Terms (Pay in Full)',
                'desc'  => 'Full payment needed at time of order.'
            ],
            '50_50'    => [
                'label' => '50% Down / 50% on Delivery',
                'desc'  => 'Pay 50% at checkout to secure order, and remaining 50% prior to delivery.'
            ],
            'net_30'   => [
                'label' => 'Net 30 Days',
                'desc'  => 'Full payment due within 30 days of invoice.'
            ]
        ];
        $selected_info = isset($term_definitions[$new_term]) ? $term_definitions[$new_term] : $term_definitions['no_terms'];
        ?>
        <div style="margin-bottom: 30px; padding: 15px; background-color: #f9f9f9; border-left: 4px solid #fab83e; font-size: 16px; line-height: 1.5em; color: #333;">
            <strong style="font-size: 1.1em; display:inline-block; margin-bottom: 4px; color: #000;">Payment Terms Assigned: <?php echo esc_html($selected_info['label']); ?></strong><br>
            <span style="color: #555; font-style: italic; font-size: 0.95em;"><?php echo esc_html($selected_info['desc']); ?></span>
            
            <?php if ( $new_term === 'no_terms' ) : ?>
                <p style="margin-top: 10px; margin-bottom: 0;">Orders will now require full payment at the time of purchase.</p>
            <?php else : ?>
                <p style="margin-top: 10px; margin-bottom: 0;">This option will now be available to you during checkout.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <p style="margin-bottom: 40px;">
        <a href="<?php echo esc_url( $action_url ); ?>" style="color: #6431f6; font-weight: bold; font-size: 18px; text-decoration: none;">
            Click here to Set Your Password & Access Account &rsaquo;
        </a>
    </p>

    <p style="margin-bottom: 20px; font-size: 14px; color: #666; font-style: italic;">
        Note: This activation link is valid for 24 hours.
    </p>

    <?php
    do_action( 'woocommerce_email_footer', null );
    $message = ob_get_clean();

    // E. Send It
    wp_mail( $user->user_email, $subject, $message );

    // Clean up
    remove_filter( 'wp_mail_content_type', 'starke_async_html_type' );
}

if ( ! function_exists( 'starke_async_html_type' ) ) {
    function starke_async_html_type() {
        return 'text/html';
    }
}

/**
 * ==============================================================================
 * ASYNC REFUND EMAILS (Action Scheduler)
 * Offloads the heavy refund email generation to WP-CLI for faster UI.
 * ==============================================================================
 */

// Global flag to bypass the block when the worker is running
global $starke_is_sending_async_refund;
$starke_is_sending_async_refund = false;

// 1. INTERCEPT: Block the native email from sending synchronously
add_filter( 'woocommerce_email_recipient_customer_refunded_order', 'starke_defer_refund_email_recipient', 99, 2 );
add_filter( 'woocommerce_email_recipient_customer_partially_refunded_order', 'starke_defer_refund_email_recipient', 99, 2 ); // <-- NEW: Blocks native partial refunds too
function starke_defer_refund_email_recipient( $recipient, $order ) {
    global $starke_is_sending_async_refund;
    
    if ( $starke_is_sending_async_refund ) {
        return $recipient; // Allow it through if our WP-CLI async worker is running
    }
    
    return ''; // Block synchronous send to keep the UI fast
}

// 2. SCHEDULE: Queue the email to Action Scheduler on refund
add_action( 'woocommerce_order_fully_refunded', 'starke_schedule_async_refund_email', 10, 2 );
add_action( 'woocommerce_order_partially_refunded', 'starke_schedule_async_refund_email', 10, 2 );
function starke_schedule_async_refund_email( $order_id, $refund_id = null ) {
    if ( function_exists( 'as_schedule_single_action' ) ) {
        as_schedule_single_action(
            time(),
            'starke_async_send_refund_email',
            array( $order_id, $refund_id ),
            'starke_email_worker' // Matches your other email workers
        );
    }
}

// 3. WORKER: Send the email in the background
add_action( 'starke_async_send_refund_email', 'starke_send_refund_email_worker', 10, 2 );
function starke_send_refund_email_worker( $order_id, $refund_id = null ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    global $starke_is_sending_async_refund;
    
    // Open the gate so the recipient filter allows it to send
    $starke_is_sending_async_refund = true;

    // Fetch the WooCommerce Mailer
    $mailer = WC()->mailer();
    $emails = $mailer->get_emails();
    
    // Trigger the Refund Email
    if ( isset( $emails['WC_Email_Customer_Refunded_Order'] ) ) {
        $email = $emails['WC_Email_Customer_Refunded_Order'];
        
        // Determine if it was a partial or full refund
        $is_partial = ! empty( $refund_id );
        
        // Trigger it natively (passes order_id, is_partial, refund_id)
        $email->trigger( $order_id, $is_partial, $refund_id );
        
        // Log it to the order notes so you know WP-CLI processed it
        $order->add_order_note( 'Refund Email successfully sent via Action Scheduler (Async).' );
    }

    // Close the gate
    $starke_is_sending_async_refund = false;
}

/**
 * CUSTOMIZE Admin New Order Email Subject & Heading
 */
add_filter( 'woocommerce_email_subject_new_order', 'starke_admin_new_order_subject', 10, 2 );
function starke_admin_new_order_subject( $subject, $order ) {
    $po_number = $order->get_meta( '_po_number_job_name', true );
    $display_id = ! empty( $po_number ) ? 'PO ' . $po_number : 'Order ' . ( $order->get_meta( '_starke_order_number', true ) ?: $order->get_order_number() );

    $name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
    return sprintf( '[%s] New Order: %s - %s', get_bloginfo( 'name' ), $display_id, $name );
}

/**
 * CUSTOMIZE Admin New Order Email Heading
 */
add_filter( 'woocommerce_email_heading_new_order', 'starke_admin_new_order_heading', 10, 2 );
function starke_admin_new_order_heading( $heading, $order ) {
    $po_number = $order->get_meta( '_po_number_job_name', true );
    $display_id = ! empty( $po_number ) ? 'PO ' . $po_number : 'Order ' . ( $order->get_meta( '_starke_order_number', true ) ?: $order->get_order_number() );

    return 'New Order: ' . $display_id;
}

/**
 * ==============================================================================
 * STARKE CUSTOMER NAME LOGIC FOR QUOTE & ORDER EMAILS
 * Priority: 1. Account Name -> 2. Billing Name -> 3. Display Name -> 4. Email
 * ==============================================================================
 */
add_filter( 'woocommerce_order_get_billing_first_name', 'starke_prioritize_account_first_name', 10, 2 );
function starke_prioritize_account_first_name( $billing_first_name, $order ) {
    // --- NEW SAFETY CHECK: ONLY run this during an Email context ---
    $is_email_context = false;
    // Look at the last 15 actions to see if a WooCommerce Email class is running
    $backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 15 );
    foreach ( $backtrace as $call ) {
        if ( isset( $call['class'] ) && strpos( $call['class'], 'WC_Email' ) !== false ) {
            $is_email_context = true;
            break;
        }
    }

    // If we are checking out, saving data, or in the Admin dashboard, use the true Billing Name!
    if ( ! $is_email_context ) {
        return $billing_first_name;
    }
    // ---------------------------------------------------------------

    if ( ! is_a( $order, 'WC_Order' ) ) {
        return $billing_first_name;
    }

    $user_id = $order->get_customer_id();
    $user    = $user_id ? get_userdata( $user_id ) : false;

    // 1. PRIORITY: Account Details First Name
    if ( $user && ! empty( trim( $user->first_name ) ) ) {
        return $user->first_name;
    }

    // 2. FALLBACK: WooCommerce Billing First Name (from checkout or admin entry)
    if ( ! empty( trim( $billing_first_name ) ) ) {
        return $billing_first_name;
    }

    // 3. FALLBACK: Account Display Name
    if ( $user && ! empty( trim( $user->display_name ) ) ) {
        return $user->display_name;
    }

    // 4. FALLBACK: First half of the billing email address
    $billing_email = $order->get_billing_email();
    if ( ! empty( $billing_email ) ) {
        $email_parts = explode( '@', $billing_email );
        if ( ! empty( $email_parts[0] ) ) {
            // Capitalizes the first letter (e.g., "john.doe@..." becomes "John.doe")
            return ucfirst( strtolower( trim( $email_parts[0] ) ) );
        }
    }

    // 5. FINAL SAFETY FALLBACK
    return 'Customer';
}

/**
 * ==============================================================================
 * STARKE CUSTOMER NAME LOGIC (LAST NAME)
 * Priority: 1. Account Last Name -> 2. Billing Last Name
 * ==============================================================================
 */
add_filter( 'woocommerce_order_get_billing_last_name', 'starke_prioritize_account_last_name', 10, 2 );
function starke_prioritize_account_last_name( $billing_last_name, $order ) {
    // --- SAFETY CHECK: ONLY run this during an Email context ---
    $is_email_context = false;
    $backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 15 );
    foreach ( $backtrace as $call ) {
        if ( isset( $call['class'] ) && strpos( $call['class'], 'WC_Email' ) !== false ) {
            $is_email_context = true;
            break;
        }
    }

    // If we are checking out, saving data, or in the Admin dashboard, use the true Billing Name!
    if ( ! $is_email_context ) {
        return $billing_last_name;
    }
    // ---------------------------------------------------------------

    if ( ! is_a( $order, 'WC_Order' ) ) {
        return $billing_last_name;
    }

    $user_id = $order->get_customer_id();
    $user    = $user_id ? get_userdata( $user_id ) : false;

    // 1. PRIORITY: Account Details Last Name
    if ( $user && ! empty( trim( $user->last_name ) ) ) {
        return $user->last_name;
    }

    // 2. FALLBACK: WooCommerce Billing Last Name (from checkout or admin entry)
    return $billing_last_name;
}

/**
 * ==============================================================================
 * ASYNC PASSWORD CHANGED EMAIL (Customer Notification)
 * Replaces the native WordPress text email with a branded WooCommerce HTML email.
 * ==============================================================================
 */

// 1. INTERCEPT: Block native WP email and schedule our custom one
add_filter( 'send_password_change_email', 'starke_intercept_password_change_email', 999, 3 );

function starke_intercept_password_change_email( $send, $user, $userdata ) {
    // Extract the email address from the core WP array
    $user_email = isset( $user['user_email'] ) ? $user['user_email'] : '';
    $user_obj   = get_user_by( 'email', $user_email );

    if ( $user_obj && function_exists( 'as_schedule_single_action' ) ) {
        
        // Schedule the background job using your existing Starke email group
        as_schedule_single_action( 
            time(), 
            'starke_async_send_password_changed_email', 
            array( 'user_id' => $user_obj->ID ), 
            'starke-emails' 
        );
    }

    // CRITICAL: Return false to permanently block the default WordPress text email
    return false;
}

// 2. WORKER: The background process that builds and sends the HTML email
add_action( 'starke_async_send_password_changed_email', 'starke_process_password_changed_email_async' );

function starke_process_password_changed_email_async( $user_id ) {
    $user_info = get_userdata( $user_id );
    if ( ! $user_info ) return;

    $site_name = get_bloginfo( 'name' );
    $mailer    = WC()->mailer();
    
    // Borrow "Customer Note" to get the Starke header/footer styling
    $email_obj = $mailer->get_emails()['WC_Email_Customer_Note'] ?? null; 

    $subject = 'Account Updated: Password Changed - ' . $site_name;
    $heading = 'Password Successfully Updated';

    // --- DETERMINE THE BEST FIRST NAME (using Starke's established logic) ---
    $customer_first_name = $user_info->first_name;
    if ( empty( trim( $customer_first_name ) ) ) {
        $customer_first_name = get_user_meta( $user_id, 'billing_first_name', true );
    }
    if ( empty( trim( $customer_first_name ) ) && ! empty( $user_info->display_name ) && $user_info->display_name !== $user_info->user_email ) {
        $customer_first_name = $user_info->display_name;
    }
    if ( empty( trim( $customer_first_name ) ) ) {
        $email_parts = explode( '@', $user_info->user_email );
        $customer_first_name = ucfirst( $email_parts[0] );
    }
    // ------------------------------------------------------------------------

    // --- TEXT SIZE STYLING ---
    $p_style = "font-size: 16px; line-height: 1.5em; color: #333; margin-bottom: 16px;";

    // --- CONSTRUCT HTML BODY ---
    ob_start();
    
    if ( $email_obj ) {
        echo wc_get_template_html( 'emails/email-header.php', array( 'email_heading' => $heading, 'email' => $email_obj ) );
    }
    ?>
    <p style="<?php echo $p_style; ?>">
        Hello <?php echo esc_html( $customer_first_name ); ?>,
    </p>

    <p style="<?php echo $p_style; ?>">
        This is an automated security notification to confirm that the password for your Starke Millwork account has been successfully updated.
    </p>

    <p style="<?php echo $p_style; ?>">
        If you initiated this change, no further action is required. You can continue to access your account, manage your architectural projects, and generate quotes using your new credentials.
    </p>
    
    <div style="margin-top: 30px; margin-bottom: 30px; padding: 15px; background-color: #f9f9f9; border-left: 4px solid #6431f6;">
        <strong>Security Alert:</strong> If you did <strong>not</strong> authorize this password change, please contact our support team immediately at <a href="mailto:info@starkemillwork.com" style="color: #6431f6; text-decoration: underline;">info@starkemillwork.com</a> to secure your account.
    </div>

    <p style="margin-bottom: 40px;">
        <a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" style="color: #6431f6; font-weight: bold; font-size: 1.1em; text-decoration: none;">
            Access Your Account &rsaquo;
        </a>
    </p>

    <?php
    if ( $email_obj ) {
        echo wc_get_template_html( 'emails/email-footer.php', array( 'email' => $email_obj ) );
    }
    
    $content = ob_get_clean();

    // Apply inline styles if mailer is active
    if ( $email_obj && method_exists( $email_obj, 'style_inline' ) ) {
        $final_message = $email_obj->style_inline( $content );
    } else {
        $final_message = $mailer->wrap_message( $heading, $content );
    }

    // Send Email
    $headers = array('Content-Type: text/html; charset=UTF-8');
    wp_mail( $user_info->user_email, $subject, $final_message, $headers );
}

/**
 * ==============================================================================
 * INTERCEPT ADMIN-INITIATED PASSWORD RESET & ROUTE THROUGH WOOCOMMERCE
 * ==============================================================================
 * Catches the plain text core WordPress reset emails triggered via the Admin 
 * Users List ("Send reset password") and upgrades them to your branded WC template.
 * Dynamically adjusts the verbiage to reflect an admin-initiated reset action,
 * and completely drops the core text email from the WP Offload SES pipeline.
 */

// 1. INTERCEPT: Intercept the core dispatch process immediately when the admin clicks the button.
add_filter( 'send_retrieve_password_email', 'starke_intercept_admin_reset_pipeline', 999, 3 );

function starke_intercept_admin_reset_pipeline( $send, $user_login, $user_data ) {
    // Only intercept if this action is manually fired from the backend Admin Dashboard area
    if ( is_admin() && function_exists( 'as_schedule_single_action' ) ) {
        
        // Generate a valid unique cryptographic reset key matching core WP mechanics
        $key = get_password_reset_key( $user_data );
        
        if ( ! is_wp_error( $key ) ) {
            // Schedule your background job to run immediately via Action Scheduler
            as_schedule_single_action(
                time(),
                'starke_async_send_admin_triggered_reset_email',
                array(
                    'user_id'    => $user_data->ID,
                    'reset_key'  => $key,
                    'user_login' => $user_login
                ),
                'starke-emails' // Routes seamlessly into your background worker group
            );
        }

        // IRONCLAD BLOCK: Returning false tells WordPress that an external mechanism 
        // has explicitly handled this notification. It halts the entire native mailing
        // thread instantly, ensuring WP Offload SES never registers a duplicate or failed email.
        return false; 
    }

    return $send;
}

// 2. WORKER: Background handler that builds and dispatches the WooCommerce-styled template
add_action( 'starke_async_send_admin_triggered_reset_email', 'starke_process_admin_reset_email_async', 10, 3 );

function starke_process_admin_reset_email_async( $user_id, $reset_key, $user_login ) {
    $user = get_userdata( $user_id );
    if ( ! $user ) {
        return;
    }

    // Initialize the WooCommerce Mailer framework class
    $mailer = WC()->mailer();
    
    // Retrieve the exact email object instance for the Reset Password email
    $email_obj = $mailer->get_emails()['WC_Email_Customer_Reset_Password'] ?? null;

    if ( ! $email_obj ) {
        return; // Safety fallback if WooCommerce fails to load the email subsystem
    }

    // Configure the specific email runtime variables to mirror a customer-initiated flow
    $email_obj->object     = $user;
    $email_obj->user_id    = $user_id;
    $email_obj->reset_key  = $reset_key;
    $email_obj->user_login = $user_login;
    $email_obj->recipient  = $user->user_email;

    // Define the structural heading title displayed at the top of the template banner
    $email_heading = 'Password Reset Assistance';

    // --- DYNAMIC VERBIAGE SWAP FOR MANUALLY RESET CONTEXT (OPTION B) ---
    // Intercepts the hardcoded placeholder string inside your customer-reset-password.php template
    $verbiage_callback = function( $translated_text, $text, $domain ) {
        if ( 'woocommerce' === $domain ) {
            $target_string = 'We received a request to reset the password for your Starke Millwork account. To proceed with creating a new password, please use the secure link below:';
            if ( $text === $target_string ) {
                return 'An administrator has issued a secure password update link for your Starke Millwork account. This manual reset allows you to safely clear your previous login credentials and configure a new password to regain immediate access to your project coordination tools:';
            }
        }
        return $translated_text;
    };
    add_filter( 'gettext', $verbiage_callback, 20, 3 );

    // Fetch your custom overridden template file ('customer-reset-password.php') with context variables
    $message = wc_get_template_html(
        'emails/customer-reset-password.php',
        array(
            'user_login'    => $user_login,
            'reset_key'     => $reset_key,
            'user_id'       => $user_id,
            'email_heading' => $email_heading,
            'email'         => $email_obj,
            'sent_to_admin' => false,
            'plain_text'    => false,
        )
    );

    // Remove the translation hook immediately after parsing to keep the environment clean
    remove_filter( 'gettext', $verbiage_callback, 20 );

    // Run the compiled message content through your inline CSS style wrapper engine
    if ( method_exists( $email_obj, 'style_inline' ) ) {
        $final_message = $email_obj->style_inline( $message );
    } else {
        $final_message = $mailer->wrap_message( $email_heading, $message );
    }

    // Subject Line configuration matching your professional security tone
    $subject = 'Security Update: Password Reset Assistance Link';

    // Set header requirements to ensure proper HTML interpretation by all email clients
    $headers = array( 'Content-Type: text/html; charset=UTF-8' );

    // Deliver the fully compiled, branded email out via wp_mail()
    wp_mail( $user->user_email, $subject, $final_message, $headers );
}

/**
 * ==============================================================================
 * CUSTOMER FAILED & CANCELLED EMAIL CUSTOMIZATIONS (Action Scheduler Async)
 * ==============================================================================
 */

// Global gatekeeper flags to prevent duplicate execution loops during worker instances
global $starke_is_sending_async_failed;
global $starke_is_sending_async_cancelled;
$starke_is_sending_async_failed    = false;
$starke_is_sending_async_cancelled = false;

/**
 * GATEKEEPER FILTERS: Blocks native triggers unless explicitly permitted by the worker.
 */
add_filter( 'woocommerce_email_recipient_customer_failed_order', 'starke_defer_failed_email_recipient', 99, 2 );
function starke_defer_failed_email_recipient( $recipient, $order ) {
    global $starke_is_sending_async_failed;
    // Only allow the recipient through if our Action Scheduler worker opened the gate
    return $starke_is_sending_async_failed ? $recipient : '';
}

add_filter( 'woocommerce_email_recipient_customer_cancelled_order', 'starke_defer_cancelled_email_recipient', 99, 2 );
function starke_defer_cancelled_email_recipient( $recipient, $order ) {
    global $starke_is_sending_async_cancelled;
    // Only allow the recipient through if our Action Scheduler worker opened the gate
    return $starke_is_sending_async_cancelled ? $recipient : '';
}

// --- Text Filters for Subjects and Headings ---

add_filter( 'woocommerce_email_subject_customer_failed_order', 'starke_customer_failed_subject', 10, 2 );
function starke_customer_failed_subject( $subject, $order ) {
    $po_number = $order->get_meta( '_po_number_job_name', true );
    $display_id = ! empty( $po_number ) ? 'PO ' . $po_number : 'Order ' . ( $order->get_meta( '_starke_order_number', true ) ?: $order->get_order_number() );
    return sprintf( 'Action Required: Payment Processing Exception - %s', $display_id );
}

add_filter( 'woocommerce_email_heading_customer_failed_order', 'starke_customer_failed_heading', 10, 2 );
function starke_customer_failed_heading( $heading, $order ) {
    return 'Transaction Authorization Deferred';
}

add_filter( 'woocommerce_email_subject_customer_cancelled_order', 'starke_customer_cancelled_subject', 10, 2 );
function starke_customer_cancelled_subject( $subject, $order ) {
    $po_number = $order->get_meta( '_po_number_job_name', true );
    $display_id = ! empty( $po_number ) ? 'PO ' . $po_number : 'Order ' . ( $order->get_meta( '_starke_order_number', true ) ?: $order->get_order_number() );
    return sprintf( 'Update Notice: Cancellation Processing Confirmed - %s', $display_id );
}

add_filter( 'woocommerce_email_heading_customer_cancelled_order', 'starke_customer_cancelled_heading', 10, 2 );
function starke_customer_cancelled_heading( $heading, $order ) {
    return 'Order File Deactivated';
}


// --- 2. Action Scheduler Routing Infrastructure ---

add_action( 'woocommerce_order_status_changed', 'starke_schedule_customer_failed_and_cancelled_emails', 10, 4 );
function starke_schedule_customer_failed_and_cancelled_emails( $order_id, $old_status, $new_status, $order ) {
    if ( ! function_exists( 'as_schedule_single_action' ) ) {
        return;
    }

    // Schedule Failed Notification Background Task
    if ( 'failed' === $new_status ) {
        if ( ! as_has_scheduled_action( 'starke_async_send_customer_failed_email', array( $order_id ) ) ) {
            as_schedule_single_action(
                time(),
                'starke_async_send_customer_failed_email',
                array( $order_id ),
                'starke_email_worker'
            );
        }
    }

    // Schedule Cancelled Notification Background Task
    if ( 'cancelled' === $new_status ) {
        if ( ! as_has_scheduled_action( 'starke_async_send_customer_cancelled_email', array( $order_id ) ) ) {
            as_schedule_single_action(
                time(),
                'starke_async_send_customer_cancelled_email',
                array( $order_id ),
                'starke_email_worker'
            );
        }
    }
}


// --- 3. Async Background Workers ---

add_action( 'starke_async_send_customer_failed_email', 'starke_send_customer_failed_email_worker' );
function starke_send_customer_failed_email_worker( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    global $starke_is_sending_async_failed;
    
    // Open the gate so the recipient filter lets exactly this execution thread pass through
    $starke_is_sending_async_failed = true;

    $emails = WC()->mailer()->get_emails();
    if ( isset( $emails['WC_Email_Customer_Failed_Order'] ) ) {
        // Run the native class trigger cleanly inside our isolated background runtime
        $emails['WC_Email_Customer_Failed_Order']->trigger( $order_id );
        $order->add_order_note( 'Customer Failed Order Email successfully processed via Action Scheduler (Async).' );
    }

    // Close the gate immediately to secure the pipeline state
    $starke_is_sending_async_failed = false;
}

add_action( 'starke_async_send_customer_cancelled_email', 'starke_send_customer_cancelled_email_worker' );
function starke_send_customer_cancelled_email_worker( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    global $starke_is_sending_async_cancelled;
    
    // Open the gate so the recipient filter lets exactly this execution thread pass through
    $starke_is_sending_async_cancelled = true;

    $emails = WC()->mailer()->get_emails();
    if ( isset( $emails['WC_Email_Customer_Cancelled_Order'] ) ) {
        // Run the native class trigger cleanly inside our isolated background runtime
        $emails['WC_Email_Customer_Cancelled_Order']->trigger( $order_id );
        $order->add_order_note( 'Customer Cancelled Order Email successfully processed via Action Scheduler (Async).' );
    }

    // Close the gate immediately to secure the pipeline state
    $starke_is_sending_async_cancelled = false;
}