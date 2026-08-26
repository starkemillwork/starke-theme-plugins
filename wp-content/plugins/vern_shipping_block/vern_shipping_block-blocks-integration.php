<?php
use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

define ( 'VernShippingBlock_VERSION', '0.1.1' );

/**
 * Class for integrating with WooCommerce Blocks
 */
class VernShippingBlock_Blocks_Integration implements IntegrationInterface {

	/**
	 * The name of the integration.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'vern_shipping_block';
	}

	/**
	 * When called invokes any initialization/setup for the integration.
	 */
	public function initialize() {
		$this->register_newsletter_block_frontend_scripts();
		$this->register_newsletter_block_editor_scripts();
        $this->register_newsletter_block_editor_styles();
        $this->register_samples_shipping_methods_frontend_scripts();
        $this->register_samples_shipping_methods_editor_scripts();
		$this->register_samples_shipping_methods_editor_styles();
        $this->register_shipping_address_selector_frontend_scripts();
        $this->register_shipping_address_selector_editor_scripts();
		$this->register_shipping_address_selector_editor_styles();
        $this->register_cart_items_features_frontend_scripts();
        $this->register_cart_items_features_editor_scripts();
		$this->register_cart_items_features_editor_styles();
        $this->register_contact_features_frontend_scripts();
		$this->register_contact_features_editor_scripts();
        $this->register_contact_features_editor_styles();
        $this->register_payment_terms_block_frontend_scripts();
        $this->register_payment_terms_block_editor_scripts();
        $this->register_payment_terms_block_editor_styles();
        $this->register_main_integration();
	}

	/**
	 * Registers the main JS file required to add filters and Slot/Fills.
	 */
	public function register_main_integration() {
		$script_path = '/build/index.js';
		$style_path  = '/build/style-index.css';

		$script_url = plugins_url( $script_path, __FILE__ );
		$style_url  = plugins_url( $style_path, __FILE__ );

		$script_asset_path = dirname( __FILE__ ) . '/build/index.asset.php';
		$script_asset      = file_exists( $script_asset_path )
			? require $script_asset_path
			: array(
				'dependencies' => array(),
				'version'      => $this->get_file_version( $script_path ),
			);

		wp_enqueue_style(
			'vern_shipping_block-blocks-integration',
			$style_url,
			[],
			$this->get_file_version( $style_path )
		);

		wp_register_script(
			'vern_shipping_block-blocks-integration',
			$script_url,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);
		wp_set_script_translations(
			'vern_shipping_block-blocks-integration',
			'vern_shipping_block',
			dirname( __FILE__ ) . '/languages'
		);
	}

	/**
	 * Returns an array of script handles to enqueue in the frontend context.
	 *
	 * @return string[]
	 */
	public function get_script_handles() {
		return array( 'vern_shipping_block-blocks-integration', 'vern_shipping_block-checkout-newsletter-subscription-block-frontend', 'vern_shipping_block-samples-shipping-methods-block-frontend', 'vern_shipping_block-shipping-address-selector-block-frontend', 'vern_shipping_block-cart-items-features-block-frontend', 'vern_shipping_block-contact-features-block-frontend', 'vern_shipping_block-payment-terms-block-frontend' );
	}

	/**
	 * Returns an array of script handles to enqueue in the editor context.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array( 'vern_shipping_block-blocks-integration', 'vern_shipping_block-checkout-newsletter-subscription-block-editor', 'vern_shipping_block-samples-shipping-methods-block-editor', 'vern_shipping_block-shipping-address-selector-block-editor', 'vern_shipping_block-cart-items-features-block-editor', 'vern_shipping_block-contact-features-block-editor', 'vern_shipping_block-payment-terms-block-editor' );
	}

	/**
	 * An array of key, value pairs of data made available to the block on the client side.
	 *
	 * @return array
	 */
	public function get_script_data() {
		$data = array(
			'vern_shipping_block-active' => true,
			'example-data' => __( 'This is some example data from the server', 'vern_shipping_block' ),
            'optInDefaultText' => __( 'I want to receive updates about products and promotions.', 'vern_shipping_block' ),
            'defaultPaymentTerms' => is_user_logged_in() ? get_user_meta( get_current_user_id(), '_starke_payment_terms', true ) : 'no_terms',
		);

		return $data;

	}

	// checkout-newsletter-subscription registration - START
    public function register_newsletter_block_editor_styles() {
        $style_path  = '/build/style-vern_shipping_block-checkout-newsletter-subscription-block.css';

        $style_url  = plugins_url( $style_path, __FILE__ );
        wp_enqueue_style(
            'vern_shipping_block-checkout-newsletter-subscription-block',
            $style_url,
            [],
            $this->get_file_version( $style_path )
        );
    }

    public function register_newsletter_block_editor_scripts() {
        $script_path       = '/build/vern_shipping_block-checkout-newsletter-subscription-block.js';
        $script_url        = plugins_url( $script_path, __FILE__ );
        $script_asset_path = dirname( __FILE__ ) . '/build/vern_shipping_block-checkout-newsletter-subscription-block.asset.php';
        $script_asset      = file_exists( $script_asset_path )
            ? require $script_asset_path
            : array(
                'dependencies' => array(),
                'version'      => $this->get_file_version( $script_asset_path ),
            );

        wp_register_script(
            'vern_shipping_block-checkout-newsletter-subscription-block-editor',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        wp_set_script_translations(
            'vern_shipping_block-newsletter-block-editor', // script handle
            'vern_shipping_block', // text domain
            dirname( __FILE__ ) . '/languages'
        );

		wp_enqueue_script( 'vern_shipping_block-checkout-newsletter-subscription-block-editor' );

        // AJAX scripts
        wp_localize_script('vern_shipping_block-checkout-newsletter-subscription-block-editor', 'secondaryShippingData', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('secondary_shipping_nonce'),
        ]);
    }

    public function register_newsletter_block_frontend_scripts() {
        $script_path       = '/build/vern_shipping_block-checkout-newsletter-subscription-block-frontend.js';
        $script_url        = plugins_url( $script_path, __FILE__ );
        $script_asset_path = dirname( __FILE__ ) . '/build/vern_shipping_block-checkout-newsletter-subscription-block-frontend.asset.php';
        // Use a safe if/else block to load the asset file. This prevents fatal errors.
        if ( file_exists( $script_asset_path ) ) {
            $script_asset = require $script_asset_path;
        } else {
            // Fallback if the asset file is missing for any reason.
            $script_asset = array(
                'dependencies' => array(),
                'version'      => $this->get_file_version( $script_path ),
            );
        }

        wp_register_script(
            'vern_shipping_block-checkout-newsletter-subscription-block-frontend',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );
        wp_set_script_translations(
            'vern_shipping_block-checkout-newsletter-subscription-block-frontend', // script handle
            'vern_shipping_block', // text domain
            dirname( __FILE__ ) . '/languages'
        );
    }
	// checkout-newsletter-subscription registration - END

	// samples-shipping-methods registration - START
    public function register_samples_shipping_methods_editor_styles() {
        $style_path  = '/build/style-vern_shipping_block-samples-shipping-methods-block.css';

        $style_url  = plugins_url( $style_path, __FILE__ );
        wp_enqueue_style(
            'vern_shipping_block-samples-shipping-methods-block',
            $style_url,
            [],
            $this->get_file_version( $style_path )
        );
    }

    public function register_samples_shipping_methods_editor_scripts() {
        $script_path       = '/build/vern_shipping_block-samples-shipping-methods-block.js';
        $script_url        = plugins_url( $script_path, __FILE__ );
        $script_asset_path = dirname( __FILE__ ) . '/build/vern_shipping_block-samples-shipping-methods-block.asset.php';
        $script_asset      = file_exists( $script_asset_path )
            ? require $script_asset_path
            : array(
                'dependencies' => array(),
                'version'      => $this->get_file_version( $script_asset_path ),
            );

        wp_register_script(
            'vern_shipping_block-samples-shipping-methods-block-editor',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        wp_set_script_translations(
            'vern_shipping_block-samples-shipmethods-block-editor', // script handle
            'vern_shipping_block', // text domain
            dirname( __FILE__ ) . '/languages'
        );

		wp_enqueue_script( 'vern_shipping_block-samples-shipping-methods-block-editor' );
    }

    public function register_samples_shipping_methods_frontend_scripts() {
        $script_path       = '/build/vern_shipping_block-samples-shipping-methods-block-frontend.js';
        $script_url        = plugins_url( $script_path, __FILE__ );
        $script_asset_path = dirname( __FILE__ ) . '/build/vern_shipping_block-samples-shipping-methods-block-frontend.asset.php';
        // Use a safe if/else block to load the asset file. This prevents fatal errors.
        if ( file_exists( $script_asset_path ) ) {
            $script_asset = require $script_asset_path;
        } else {
            // Fallback if the asset file is missing for any reason.
            $script_asset = array(
                'dependencies' => array(),
                'version'      => $this->get_file_version( $script_path ),
            );
        }

        wp_register_script(
            'vern_shipping_block-samples-shipping-methods-block-frontend',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );
        wp_set_script_translations(
            'vern_shipping_block-samples-shipping-methods-block-frontend', // script handle
            'vern_shipping_block', // text domain
            dirname( __FILE__ ) . '/languages'
        );

		wp_enqueue_script( 'vern_shipping_block-samples-shipping-methods-block-frontend' );
    }
	// samples-shipping-methods registration - END

    // shipping-address-selector registration - START
    public function register_shipping_address_selector_editor_styles() {
        $style_path  = '/build/style-vern_shipping_block-shipping-address-selector-block.css';

        $style_url  = plugins_url( $style_path, __FILE__ );
        wp_enqueue_style(
            'vern_shipping_block-shipping-address-selector-block',
            $style_url,
            [],
            $this->get_file_version( $style_path )
        );
    }

    public function register_shipping_address_selector_editor_scripts() {
        $script_path       = '/build/vern_shipping_block-shipping-address-selector-block.js';
        $script_url        = plugins_url( $script_path, __FILE__ );
        $script_asset_path = dirname( __FILE__ ) . '/build/vern_shipping_block-shipping-address-selector-block.asset.php';
        $script_asset      = file_exists( $script_asset_path )
            ? require $script_asset_path
            : array(
                'dependencies' => array(),
                'version'      => $this->get_file_version( $script_asset_path ),
            );

        wp_register_script(
            'vern_shipping_block-shipping-address-selector-block-editor',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        wp_set_script_translations(
            'vern_shipping_block-shipping-addyselector-block-editor', // script handle
            'vern_shipping_block', // text domain
            dirname( __FILE__ ) . '/languages'
        );

		wp_enqueue_script( 'vern_shipping_block-shipping-address-selector-block-editor' );
    }

    public function register_shipping_address_selector_frontend_scripts() {
        $script_path       = '/build/vern_shipping_block-shipping-address-selector-block-frontend.js';
        $script_url        = plugins_url( $script_path, __FILE__ );
        $script_asset_path = dirname( __FILE__ ) . '/build/vern_shipping_block-shipping-address-selector-block-frontend.asset.php';
        // Use a safe if/else block to load the asset file. This prevents fatal errors.
        if ( file_exists( $script_asset_path ) ) {
            $script_asset = require $script_asset_path;
        } else {
            // Fallback if the asset file is missing for any reason.
            $script_asset = array(
                'dependencies' => array(),
                'version'      => $this->get_file_version( $script_path ),
            );
        }

        wp_register_script(
            'vern_shipping_block-shipping-address-selector-block-frontend',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );
        wp_set_script_translations(
            'vern_shipping_block-shipping-address-selector-block-frontend', // script handle
            'vern_shipping_block', // text domain
            dirname( __FILE__ ) . '/languages'
        );

		wp_enqueue_script( 'vern_shipping_block-shipping-address-selector-block-frontend' );
    }
	// shipping-address-selector registration - END

    // cart-items-features registration - START
    public function register_cart_items_features_editor_styles() {
        $style_path  = '/build/style-vern_shipping_block-cart-items-features-block.css';

        $style_url  = plugins_url( $style_path, __FILE__ );
        wp_enqueue_style(
            'vern_shipping_block-cart-items-features-block',
            $style_url,
            [],
            $this->get_file_version( $style_path )
        );
    }

    public function register_cart_items_features_editor_scripts() {
        $script_path       = '/build/vern_shipping_block-cart-items-features-block.js';
        $script_url        = plugins_url( $script_path, __FILE__ );
        $script_asset_path = dirname( __FILE__ ) . '/build/vern_shipping_block-cart-items-features-block.asset.php';
        $script_asset      = file_exists( $script_asset_path )
            ? require $script_asset_path
            : array(
                'dependencies' => array(),
                'version'      => $this->get_file_version( $script_asset_path ),
            );

        wp_register_script(
            'vern_shipping_block-cart-items-features-block-editor',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        wp_set_script_translations(
            'vern_shipping_block-cartitems-features-block-editor', // script handle
            'vern_shipping_block', // text domain
            dirname( __FILE__ ) . '/languages'
        );

		wp_enqueue_script( 'vern_shipping_block-cart-items-features-block-editor' );

        // AJAX scripts
        wp_localize_script('vern_shipping_block-checkout-newsletter-subscription-block-editor', 'cart_items_features', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cart_items_features_nonce'),
        ]);
    }

    public function register_cart_items_features_frontend_scripts() {
        $script_path       = '/build/vern_shipping_block-cart-items-features-block-frontend.js';
        $script_url        = plugins_url( $script_path, __FILE__ );
        $script_asset_path = dirname( __FILE__ ) . '/build/vern_shipping_block-cart-items-features-block-frontend.asset.php';
        // Use a safe if/else block to load the asset file. This prevents fatal errors.
        if ( file_exists( $script_asset_path ) ) {
            $script_asset = require $script_asset_path;
        } else {
            // Fallback if the asset file is missing for any reason.
            $script_asset = array(
                'dependencies' => array(),
                'version'      => $this->get_file_version( $script_path ),
            );
        }

        wp_register_script(
            'vern_shipping_block-cart-items-features-block-frontend',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );
        wp_set_script_translations(
            'vern_shipping_block-cart-items-features-block-frontend', // script handle
            'vern_shipping_block', // text domain
            dirname( __FILE__ ) . '/languages'
        );

		wp_enqueue_script( 'vern_shipping_block-cart-items-features-block-frontend' );
    }
	// cart-items-features registration - END

    // contact-features-block registration - START
    public function register_contact_features_editor_styles() {
        $style_path  = '/build/style-vern_shipping_block-contact-features-block.css';

        $style_url  = plugins_url( $style_path, __FILE__ );
        wp_enqueue_style(
            'vern_shipping_block-contact-features-block',
            $style_url,
            [],
            $this->get_file_version( $style_path )
        );
    }

    public function register_contact_features_editor_scripts() {
        $script_path       = '/build/vern_shipping_block-contact-features-block.js';
        $script_url        = plugins_url( $script_path, __FILE__ );
        $script_asset_path = dirname( __FILE__ ) . '/build/vern_shipping_block-contact-features-block.asset.php';
        $script_asset      = file_exists( $script_asset_path )
            ? require $script_asset_path
            : array(
                'dependencies' => array(),
                'version'      => $this->get_file_version( $script_asset_path ),
            );

        wp_register_script(
            'vern_shipping_block-contact-features-block-editor',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        wp_set_script_translations(
            'vern_shipping_block-cartitems-features-block-editor', // script handle
            'vern_shipping_block', // text domain
            dirname( __FILE__ ) . '/languages'
        );

		wp_enqueue_script( 'vern_shipping_block-contact-features-block-editor' );
    }

    public function register_contact_features_frontend_scripts() {
        $script_path       = '/build/vern_shipping_block-contact-features-block-frontend.js';
        $script_url        = plugins_url( $script_path, __FILE__ );
        $script_asset_path = dirname( __FILE__ ) . '/build/vern_shipping_block-contact-features-block-frontend.asset.php';
        // Use a safe if/else block to load the asset file. This prevents fatal errors.
        if ( file_exists( $script_asset_path ) ) {
            $script_asset = require $script_asset_path;
        } else {
            // Fallback if the asset file is missing for any reason.
            $script_asset = array(
                'dependencies' => array(),
                'version'      => $this->get_file_version( $script_path ),
            );
        }

        wp_register_script(
            'vern_shipping_block-contact-features-block-frontend',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );
        wp_set_script_translations(
            'vern_shipping_block-contact-features-block-frontend', // script handle
            'vern_shipping_block', // text domain
            dirname( __FILE__ ) . '/languages'
        );
    }
	// contact-features-block registration - END

    // payment-terms-block registration - START
    public function register_payment_terms_block_editor_styles() {
        $style_path = '/build/style-vern_shipping_block-payment-terms-block.css';
        $style_url  = plugins_url( $style_path, __FILE__ );
        wp_enqueue_style( 'vern_shipping_block-payment-terms-block', $style_url, [], $this->get_file_version( $style_path ) );
    }

    public function register_payment_terms_block_editor_scripts() {
        $script_path       = '/build/vern_shipping_block-payment-terms-block.js';
        $script_url        = plugins_url( $script_path, __FILE__ );
        $script_asset_path = dirname( __FILE__ ) . '/build/vern_shipping_block-payment-terms-block.asset.php';
        $script_asset      = file_exists( $script_asset_path ) ? require $script_asset_path : array( 'dependencies' => array(), 'version' => $this->get_file_version( $script_asset_path ) );

        wp_register_script( 'vern_shipping_block-payment-terms-block-editor', $script_url, $script_asset['dependencies'], $script_asset['version'], true );
        wp_set_script_translations( 'vern_shipping_block-payment-terms-block-editor', 'vern_shipping_block', dirname( __FILE__ ) . '/languages' );
        wp_enqueue_script( 'vern_shipping_block-payment-terms-block-editor' );
    }

    public function register_payment_terms_block_frontend_scripts() {
        $script_path       = '/build/vern_shipping_block-payment-terms-block-frontend.js';
        $script_url        = plugins_url( $script_path, __FILE__ );
        $script_asset_path = dirname( __FILE__ ) . '/build/vern_shipping_block-payment-terms-block-frontend.asset.php';
        $script_asset      = file_exists( $script_asset_path ) ? require $script_asset_path : array( 'dependencies' => array(), 'version' => $this->get_file_version( $script_path ) );

        wp_register_script( 'vern_shipping_block-payment-terms-block-frontend', $script_url, $script_asset['dependencies'], $script_asset['version'], true );
        wp_set_script_translations( 'vern_shipping_block-payment-terms-block-frontend', 'vern_shipping_block', dirname( __FILE__ ) . '/languages' );
    }
    // payment-terms-block registration - END

	/**
	 * Get the file modified time as a cache buster if we're in dev mode.
	 *
	 * @param string $file Local path to the file.
	 * @return string The cache buster value to use for the given file.
	 */
	protected function get_file_version( $file ) {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG && file_exists( $file ) ) {
			return filemtime( $file );
		}
		return VernShippingBlock_VERSION;
	}
}
