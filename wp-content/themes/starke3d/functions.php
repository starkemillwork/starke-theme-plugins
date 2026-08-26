<?php
/**
 * Functions and definitions
 *
 * 
 */
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
require_once ABSPATH . 'vendor/autoload.php';
require_once get_stylesheet_directory() . '/public/product/molding/molding.php';
require_once get_stylesheet_directory() . '/public/product/molding/custom-molding-profile.php';
require_once get_stylesheet_directory() . '/admin/classes/class-starke-taxjar-api.php';
require_once get_stylesheet_directory() . '/admin/classes/class-wc-quote-lock-controller.php';
require_once get_stylesheet_directory() . '/admin/login-as-user.php';
require_once get_stylesheet_directory() . '/admin/classes/class-wc-session-handler-extended.php';
require_once get_stylesheet_directory() . '/admin/quote-order.php'; // Add needed optimizations to remove the 3s delay on cart calculations for a 30 cart items cart.
require_once get_stylesheet_directory() . '/admin/classes/class-wc-additional-order-quote-meta-creator.php';
require_once get_stylesheet_directory() . '/admin/classes/class-wc-starke-commerce.php';
require_once get_stylesheet_directory() . '/public/classes/price-engine.php';
require_once get_stylesheet_directory() . '/admin/import.php';
require_once get_stylesheet_directory() . '/admin/email.php';
require_once get_stylesheet_directory() . '/post-type/doors.php';
require_once get_stylesheet_directory() . '/post-type/saddles.php';
require_once get_stylesheet_directory() . '/post-type/species.php';
require_once get_stylesheet_directory() . '/post-type/finish.php';
require_once get_stylesheet_directory() . '/post-type/stain.php';
require_once get_stylesheet_directory() . '/post-type/sheen.php';
require_once get_stylesheet_directory() . '/post-type/lengths.php';
require_once get_stylesheet_directory() . '/taxonomy/door-types.php';
require_once get_stylesheet_directory() . '/admin/reorganizemenus.php';
require_once get_stylesheet_directory() . '/public/layout/header-functionality.php';
require_once get_stylesheet_directory() . '/woocommerce/single-product/add-to-cart/ajax-add-to-cart.php';
require_once get_stylesheet_directory() . '/public/product/molding/dxf.php';
require_once get_stylesheet_directory() . '/woocommerce/cart/cart-line-item-pricer.php';
require_once get_stylesheet_directory() . '/woocommerce/single-product/live-pricer.php';
require_once get_stylesheet_directory() . '/woocommerce/single-product/related-products.php';
require_once get_stylesheet_directory() . '/admin/block-editor/block-settings.php';
require_once get_stylesheet_directory() . '/woocommerce/checkout/taxes-costs-shipping.php';
require_once get_stylesheet_directory() . '/public/my-account/my-account.php';
require_once get_stylesheet_directory() . '/public/product/download.php';
require_once get_stylesheet_directory() . '/admin/classes/class-starke-payment-manager.php';

add_action('wp', function() {
    if (is_page(9106)) {
        require_once get_stylesheet_directory() . '/public/product/doors/door-details-layout.php';
    }
});

/**
 * Enqueue the child theme's stylesheet for a block theme.
 */
function starke_child_theme_enqueue_styles() {
    // Enqueue the child theme's main stylesheet.
    // The version number is pulled from style.css, which helps with cache-busting.
    wp_enqueue_style(
        'starke-child-style', // A unique name for your stylesheet
        get_stylesheet_uri(), // This automatically gets the URL of the current theme's style.css
        array(),              // No dependencies since we aren't loading the parent's file
        wp_get_theme()->get('Version') // Appends the version number to the file URL
    );
}
add_action( 'wp_enqueue_scripts', 'starke_child_theme_enqueue_styles' );

add_action( 'wp_enqueue_scripts', 'starke_enqueue_portfolio_scripts' );
function starke_enqueue_portfolio_scripts() {
    // Only load these scripts on our specific portfolio page.
    if ( is_page( 8397 ) ) { // <--- CHANGE 15 TO YOUR PORTFOLIO PAGE ID
        wp_enqueue_script(
            'photo-gallery-tabs',
            get_stylesheet_directory_uri() . '/assets/js/photo-gallery-tabs.js',
            array(),
            wp_get_theme()->get('Version'),
            true     // Load in the footer
        );
    }
}

/**
 * Register Swiper JS Locally for Global Use
 */
add_action( 'wp_enqueue_scripts', 'starke_register_local_assets' );
function starke_register_local_assets() {
    $version = '11.0.0'; // Easy to update later

    // 1. Register CSS (Point to your local folder)
    wp_register_style( 
        'swiper-local-css', 
        get_stylesheet_directory_uri() . '/assets/css/swiper-bundle.min.css', 
        array(), 
        $version 
    );

    // 2. Register JS (Point to your local folder)
    wp_register_script( 
        'swiper-local-js', 
        get_stylesheet_directory_uri() . '/assets/js/swiper-bundle.min.js', 
        array(), 
        $version, 
        true // Load in footer
    );

    // 3. Enqueue it ONLY where needed (Homepage OR pages with the gallery shortcode)
    // Note: We check for is_front_page() OR if the content has your shortcode.
    global $post;
    if ( is_a( $post, 'WP_Post' ) ) {
        if ( is_front_page() || has_shortcode( $post->post_content, 'door_profiles_layout' ) ) {
            wp_enqueue_style( 'swiper-local-css' );
            wp_enqueue_script( 'swiper-local-js' );
        }
    }
}

// Load Font Awesome 5 Locally
add_action( 'wp_enqueue_scripts', 'starke_load_local_font_awesome' );
function starke_load_local_font_awesome() {
    // Enqueue the local CSS file
    // This assumes you placed the files in: /assets/fontawesome/css/all.min.css
    wp_enqueue_style( 
        'font-awesome-5-local', 
        get_stylesheet_directory_uri() . '/assets/fontawesome/css/all.min.css', 
        array(), 
        '5.15.4' 
    );
}

/**
 * Remove 'starke-startup' class after 2 seconds
 * Only runs on the Homepage.
 */
add_action( 'wp_footer', 'starke_remove_startup_class_script', 99 );
function starke_remove_startup_class_script() {
    // 1. Bail if we are not on the front page
    if ( ! is_front_page() ) {
        return;
    }
    ?>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // 2. Wait 2 seconds
            setTimeout(function() {
                var slider = document.querySelector('.starke-hero-slider.starke-startup');
                if (slider) {
                    slider.classList.remove('starke-startup');
                    
                    // Optional: If you need to Trigger the "Normal" animation explicitly, 
                    // you can add your animation class here:
                    // slider.classList.add('starke-animate'); 
                }
            }, 2000); 
        });
    </script>
    <?php
}

// 1. Intercept Block Rendering on Page Load
$GLOBALS['starke_gal_index'] = 0;
add_filter('pre_render_block', function($pre_render, $parsed_block) {
    if (is_admin() || wp_is_json_request() || isset($_POST['starke_ajax_load'])) return $pre_render;

    $name = isset($parsed_block['blockName']) ? (string)$parsed_block['blockName'] : '';
    
    if ($name === 'core/gallery' || strpos($name, 'gallery') !== false || strpos($name, 'rml') !== false || strpos($name, 'real-media') !== false) {
        $index = $GLOBALS['starke_gal_index']++;
        $post_id = get_the_ID();
        return sprintf(
            '<div class="starke-ajax-placeholder" data-post-id="%d" data-gallery-index="%d" style="width:100%%; min-height:200px; display:flex; justify-content:center; align-items:center;">
                <div style="width:40px; height:40px; border:4px solid #eee; border-top:4px solid var(--wp--preset--color--primary, #6431F6); border-radius:50%%; animation:starke-spin 1s linear infinite;"></div>
            </div>', $post_id, $index
        );
    }
    return $pre_render;
}, 10, 2);

// 2. The Pure AJAX Endpoint for ALL Tabs (Loads all HTML instantly!)
add_action('template_redirect', function() {
    if (isset($_POST['starke_ajax_load'])) {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        
        $post_id = intval($_POST['post_id']);
        $gallery_index = intval($_POST['gallery_index']);
        
        $post = get_post($post_id);
        if (!$post) { wp_send_json(['success' => false]); exit; }
        
        $blocks = parse_blocks($post->post_content);
        $galleries = [];
        
        $extract = function($blocks) use (&$extract, &$galleries) {
            foreach ($blocks as $block) {
                $name = isset($block['blockName']) ? (string)$block['blockName'] : '';
                if ($name === 'core/gallery' || strpos($name, 'gallery') !== false || strpos($name, 'rml') !== false || strpos($name, 'real-media') !== false) {
                    $galleries[] = $block;
                }
                if (!empty($block['innerBlocks'])) $extract($block['innerBlocks']);
            }
        };
        $extract($blocks);
        
        if (isset($galleries[$gallery_index])) {
            $raw_html = render_block($galleries[$gallery_index]);
            $html = apply_filters('the_content', $raw_html); // Get native wrappers
            wp_send_json(['success' => true, 'html' => $html]); // Send all HTML at once!
        } else {
            wp_send_json(['success' => false]);
        }
        exit;
    }
});

/**
 * 1. STARKE REACT CART SYNC - BACKEND TRIGGER
 * Sets a temporary cookie when a user successfully authenticates.
 * This completely avoids frontend JavaScript race conditions during redirects.
 */
add_action('wp_login', 'starke_trigger_cart_sync_on_login', 10, 2);
function starke_trigger_cart_sync_on_login($user_login, $user) {
    // Set a cookie that lasts for exactly 60 seconds
    setcookie('starke_login_sync_flag', 'true', time() + 60, COOKIEPATH, COOKIE_DOMAIN);
}

/**
 * 2. STARKE GLOBAL REACT CART SYNC - FRONTEND EXECUTION
 * Extremely efficient: Costs 0 performance unless the cookie is present.
 */
add_action('wp_footer', 'starke_global_mini_cart_sync_fix', 999);
function starke_global_mini_cart_sync_fix() {
    if ( function_exists('is_checkout') && is_checkout() ) {
        return;
    }
    ?>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // Check if the backend set the login flag
            if (document.cookie.indexOf('starke_login_sync_flag=true') !== -1) {
                
                // Instantly delete the cookie so this NEVER runs on subsequent page loads
                document.cookie = "starke_login_sync_flag=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";

                // The exact polling function you confirmed works perfectly
                function starkeSyncReactCart(attempts = 0) {
                    // Failsafe to prevent infinite loops (stops after 5 seconds)
                    if (attempts > 50) return;
                    if (typeof wp !== 'undefined' && wp.data && wp.data.select && wp.data.dispatch) {
                        const cartStore = wp.data.select('wc/store/cart');
                        
                        // Wait for the store to finish its initial local hydration
                        if (cartStore && cartStore.hasFinishedResolution('getCartData')) {
                            wp.data.dispatch('wc/store/cart').invalidateResolutionForStoreSelector('getCartData');
                            wp.data.dispatch('wc/store/cart').invalidateResolutionForStoreSelector('getCartTotals');
                            return; 
                        }
                    }
                    
                    // If it's not ready yet, loop and check again in 100ms
                    setTimeout(() => starkeSyncReactCart(attempts + 1), 100);
                }

                // Fire the sync sequence
                starkeSyncReactCart();
            }
        });
    </script>
    <?php
}
