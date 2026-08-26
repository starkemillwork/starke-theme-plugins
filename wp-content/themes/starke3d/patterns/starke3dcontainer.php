<?php
/**
 * Title: starke3dcontainer
 * Slug: starke3d/starke3dcontainer
 * Categories: 
 * Inserter: false
 */
//
if (!is_admin()) {
global $product;
$product_name = isset($product) ? $product->get_name() : '';
$product_id = isset($product) ? $product->get_id() : '';
$is_custom_profile = is_custom_profile($product_id);
$is_custom_profile_allowed = $is_custom_profile && (current_user_can('manage_woocommerce') || impersonation_is_active());

if (is_null(WC()->session)) {
    WC()->initialize_session();
}

// --- Check if sample is currently in cart ---
$is_sample_in_cart = false;
if ( WC()->cart ) {
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        if ( isset( $cart_item['product_id'] ) && $cart_item['product_id'] == $product_id && isset( $cart_item['sample'] ) && $cart_item['sample'] ) {
            $is_sample_in_cart = true;
            break;
        }
    }
}

// --- START: PROFILE LIST CACHE RETRIEVAL ---
$requested_samples = WC()->session->get('sample_requests', []);
if (!is_array($requested_samples)) {
    $requested_samples = [];
}

// 1. Grab the list from the permanent cache
// If missing, molding.php globally handles the background AJAX rebuild
$valid_profiles = get_transient('starke_cached_profile_list');

if ( false === $valid_profiles ) {
    $valid_profiles = []; // Send empty array so the page loads instantly
}

$current_product_slug = isset($product) ? $product->get_slug() : '';
// --- END PROFILE LIST CACHE RETRIEVAL ---

?>
<script>
    window.starke3d_data = {
        productId: <?php echo json_encode($product_id); ?>,
        productSlug: <?php echo json_encode($current_product_slug); ?>, // NEW: Added current slug
        productModified: <?php echo json_encode(isset($product) && is_object($product) ? $product->get_date_modified()->getOffsetTimestamp() : time()); ?>, // cache busting timestamp based on product modification time
        allAvailableProfiles: <?php echo json_encode($valid_profiles); ?>, // NEW: Added array of valid slugs
        requestedSamples: <?php echo json_encode(array_values($requested_samples)); ?>,
        isSampleInCart: <?php echo json_encode($is_sample_in_cart); ?>,
        rest_url: '<?php echo esc_url_raw( rest_url() ); ?>',
        nonce: '<?php echo wp_create_nonce( 'wp_rest' ); ?>',
        isAccountLimited: <?php echo json_encode( function_exists('starke_is_account_limited') && starke_is_account_limited() ); ?>,
        defaults: {
            width: <?php echo json_encode(get_field('width', $product_id)); ?>,
            thickness: <?php echo json_encode(get_field('thickness', $product_id)); ?>,
            min_width: <?php echo json_encode(get_field('min_width', $product_id)); ?>,
            max_width: <?php echo json_encode(get_field('max_width', $product_id)); ?>,
            min_thickness: <?php echo json_encode(get_field('min_thickness', $product_id)); ?>,
            max_thickness: <?php echo json_encode(get_field('max_thickness', $product_id)); ?>,
            relief_angle: <?php echo json_encode(get_field('relief_angle', $product_id)); ?>,
            back_relief: <?php echo json_encode(get_field('back_relief', $product_id)); ?>,
            rabbet_position: <?php echo json_encode(get_field('1strabbetnotch', $product_id)); ?>,
            rabbet_thickness: <?php echo json_encode(get_field('1strabbetnotch_thickness', $product_id)); ?>,
            rabbet_width: <?php echo json_encode(get_field('1strabbetnotch_width', $product_id)); ?>,
            rabbet_maxwidth: <?php echo json_encode(get_field('1strabbetnotch_maxwidth', $product_id)); ?>,
            rabbet_pos1: <?php echo json_encode(get_field('1strabbetnotch_posno1_minallowedleftovermaterialthickness', $product_id)); ?>,
            rabbet_pos2: <?php echo json_encode(get_field('1strabbetnotch_posno2_minallowedleftovermaterialthickness', $product_id)); ?>,
            rabbet_pos3: <?php echo json_encode(get_field('1strabbetnotch_posno3_minallowedleftovermaterialthickness', $product_id)); ?>,
            rabbet_pos4: <?php echo json_encode(get_field('1strabbetnotch_posno4_minallowedleftovermaterialthickness', $product_id)); ?>
        }
    };
</script>
<?php

function enqueue_starke3d_configurator() {
    // NEW: Dynamically fetch actual file modification times to break the cache automatically
    $js_ver = filemtime(get_stylesheet_directory() . '/dist/main.js');
    $css_ver = filemtime(get_stylesheet_directory() . '/dist/assets/styles3d.css');

    // Replace 'null' with the dynamic version variables
    wp_enqueue_script('starke3d-configurator-bundle', get_stylesheet_directory_uri() . '/dist/main.js', [], $js_ver, true);
		
    // Add the `type="module"` attribute to the script tag
    add_filter('script_loader_tag', function($tag, $handle) {
        if ($handle === 'starke3d-configurator-bundle') {
            return str_replace('<script ', '<script type="module" ', $tag);
        }
        return $tag;
    }, 10, 2);
		
    // Replace 'null' here as well
    wp_enqueue_style('starke3d-configurator-css', get_stylesheet_directory_uri() . '/dist/assets/styles3d.css', [], $css_ver);
}
add_action('wp_enqueue_scripts', 'enqueue_starke3d_configurator');




// --- Functions --- START

function get_cart_item_data_fields() {
    if (isset($_GET['cikey']) && !empty($_GET['cikey'])) { 
        if (WC()->cart ) {
            $cart_item_key = sanitize_text_field($_GET['cikey']);
            $cart_contents = WC()->cart->get_cart();

            if ( isset($cart_contents[$cart_item_key]) ) {
                $cart_item = $cart_contents[$cart_item_key];

                return [
                    'knifecost' => isset($cart_item['knifecost']) ? floatval($cart_item['knifecost']) : '',
                    'markup' => isset($cart_item['markup']) ? floatval($cart_item['markup']) : '',
                    'waste'  => isset($cart_item['waste']) ? floatval($cart_item['waste']) : '',
                    'custom_name'  => isset($cart_item['custom_name']) ? strval($cart_item['custom_name']) : '',
                    'is_edit_mode' => true,
                ];
            }
        }
    }

    return [
        'markup' => '',
        'waste'  => '',
        'is_edit_mode' => false,
    ];
}

function populate_species_dropdown() {
    // Fetch all species posts
    $species_posts = get_posts(array(
        'post_type'   => 'species',
        'numberposts' => -1,
        'orderby'     => 'menu_order',
        'order'       => 'ASC',
    ));

    // Output options
    foreach ($species_posts as $post) {
        printf(
            '<option value="%s">%s</option>',
            esc_attr($post->ID),
            esc_html(get_the_title($post))
        );
    }
}

function populate_finish_dropdown() {
    // Fetch all finish posts
    $finish_posts = get_posts(array(
        'post_type'   => 'finish',
        'numberposts' => -1,
        'orderby'     => 'menu_order',
        'order'       => 'ASC',
    ));

    // Output options
    foreach ($finish_posts as $post) {
        printf(
            '<option value="%s">%s</option>',
            esc_attr($post->ID),
            esc_html(get_the_title($post))
        );
    }
}

function populate_stain_dropdown() {
    // Fetch all stain posts
    $stain_posts = get_posts(array(
        'post_type'   => 'stain',
        'numberposts' => -1,
        'orderby'     => 'menu_order',
        'order'       => 'ASC',
    ));

    // Output options
    foreach ($stain_posts as $post) {
        printf(
            '<option value="%s">%s</option>',
            esc_attr($post->ID),
            esc_html(get_the_title($post))
        );
    }
}

function populate_sheen_dropdown() {
    // Fetch all sheen posts
    $sheen_posts = get_posts(array(
        'post_type'   => 'sheen',
        'numberposts' => -1,
        'orderby'     => 'menu_order',
        'order'       => 'ASC',
    ));

    // Output options
    foreach ($sheen_posts as $post) {
        printf(
            '<option value="%s">%s</option>',
            esc_attr($post->ID),
            esc_html(get_the_title($post))
        );
    }
}

function populate_lengths_dropdown() {
    // Fetch all lengths posts
    $lengths_posts = get_posts(array(
        'post_type'   => 'lengths',
        'numberposts' => -1,
        'orderby'     => 'menu_order',
        'order'       => 'ASC',
    ));

    // --- NEW: Add Default Placeholder Option ---
    echo '<option value="" selected disabled>Select...</option>';

    // Output options
    foreach ($lengths_posts as $post) {
        printf(
            '<option value="%s">%s</option>',
            esc_attr($post->ID), // Or get_the_title($post) depending on how your value logic works
            esc_html(get_the_title($post))
        );
    }
}
	
function set_dxf_download_url($product_id) {
	$nonce = wp_create_nonce('dxf_download_nonce_' . $product_id);
	$download_url = home_url('/download/' . $product_id . '/' . $nonce . '/');
	return $download_url;
}
	
function set_add_sample_to_cart_title() {
	if ( function_exists( 'get_field' ) ) {
		// Example: Display a custom field
		$sample_inventory = get_field( 'sample_inventory' );
		if ( $sample_inventory == 0 || $sample_inventory == '') {
			// UPDATED: Added the span for the subtext to match shop page
			return 'REQUEST SAMPLE <span class="starke-oos-subtext">(Sample Out of Stock)</span>';
		}
		else {
			return 'ADD SAMPLE';
		}
	}
}

// --- Functions --- END

//echo '<div>' . wc_get_product($product->id) . '</div>';
?>
    <meta name="viewport" content="width=device-width, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0">
    
    <style id="gwd-text-style">
        p {
            margin: 0px;
        }

        h1 {
            margin: 0px;
        }

        h2 {
            margin: 0px;
        }

        h3 {
            margin: 0px;
        }
    </style>
	<style>
        #starke3d-configurator {
            transform-style: preserve-3d;
            background-color: transparent;
			max-width: 100%;
			width: 100%;
            height: 800px;
			margin: 0px;
			color: black;
        }
        
		#starke3d-configurator * {
            transform-style: preserve-3d;
			font-family: Muli,Arial,sans-serif;
			line-height: normal;
			box-sizing: content-box;
        }
		#starke3d-configurator input[type="range"] {
			margin-bottom: 2px;
		}
		#starke3d-configurator select {
			font-size: 16px;
			box-sizing: border-box;
		}
		#starke3d-configurator label {
			font-size: 16px;
			margin-bottom: 0px;
			color: black;
		}
		#starke3d-configurator button {
			font-size: 14px;
			box-sizing: border-box;
		}
		#starke3d-configurator .gwd-input-1nmq {
			font-size: 16px;
		}
		#starke3d-configurator .gwd-select-xvrs {
			font-size: 16px;
			color: black;
		}
		#starke3d-configurator .pricing {
			font-size: 16px;
		}
		#starke3d-configurator .v3d-annotation {
			color: white;
		}
		#starke3d-configurator input[type=checkbox] {
			box-sizing: border-box;
		}
		#starke3d-configurator .linearFootageControls {
			margin-right: 0px;
		}
		#starke3d-configurator .viewControls {
			margin-right: 2px;
		}
    </style>
    <link href="https://fonts.googleapis.com/css?family=Lora:regular,500,600,700,italic,500italic,600italic,700italic" rel="stylesheet" type="text/css">
<div id="starke3d-configurator">
    <?php if ( $is_custom_profile_allowed ) { 
        $edit_mode_cart_data = get_cart_item_data_fields();
        $knifecost_price = $edit_mode_cart_data['knifecost'];
        $markup_percent = $edit_mode_cart_data['markup'];
        $waste_percent = $edit_mode_cart_data['waste'];
        $custom_name = $edit_mode_cart_data['custom_name'];
        $is_edit_mode = $edit_mode_cart_data['is_edit_mode'];
        if ($is_edit_mode) {
            $temp_custom_profile_number = $custom_name;
        } else {
            $temp_custom_profile_number = get_custom_profile_number();
        } 
    } ?>
	<div id="v3d-container">
        <div id="infoPopup_overlay"></div>
        <div id="fullscreen_button" class="fullscreen-button fullscreen-open" title="Toggle fullscreen mode"></div>
        <div class="profileInfo" id="profileInfo_div">
            <label id="pricePerFoot_label" class="allelements-visibility gwd-label-1uf9 gwd-label-1hcq" data-gwd-name="PricePerFootLabel">Price-Per-Foot $0.00</label>
            <label id="profileSize_label" class="allelements-visibility profileSize_label gwd-label-1uf9" data-gwd-name="ProfileSizeLabel">1-3/4" x 5-1/2"</label>
            <label id="profileNumber_label" class="allelements-visibility profileNumber_label gwd-label-1nzb" data-gwd-name="ProfileNumberLabel"><?php echo esc_html( $product_name ); ?></label>
        </div>
        <button id="fitToView_button" class="allelements-visibility fitToViewButton" data-gwd-name="FitToViewButton">FIT TO VIEW</button>
        <button id="dimensions_button" class="allelements-visibility dimensionsButton" data-gwd-name="DimensionsButton">DIMENSIONS</button>
        <button id="resetValues_button" class="allelements-visibility resetValuesButton">RESET VALUES</button>
        <div id="infoPopUp_div" class="allelements-visibility gwd-new-class-1n8v infoPopUp" data-gwd-name="InfoPopUp">
            <div class="gwd-new-class-13w2" id="infoContent_div">
                <div class="gwd-new-class-994z" id="infoTextContent_p">This is just placeholder text. This is just placeholder text.<br></div>
                <a id="viewCart_button" class="allelements-visibility gwd-a-db45 gwd-a-p9ju" data-gwd-name="ViewCartButton">VIEW CART</a>
            </div>
            <div class="gwd-div-2hqw" id="infoHeader_div">
                <label class="gwd-span-s8tx gwd-span-1mwu" id="infoPopUpTitle_label">Note:</label>
                <button id="infoClose_button" class="allelements-visibility gwd-button-1yxz" data-gwd-name="InfoCloseButton">X</button>
            </div>
        </div>
        <?php if ( current_user_can('manage_woocommerce') || ( function_exists('impersonation_is_active') && impersonation_is_active() ) ) { ?>
            <div class="profileSelectorControls" id="profileSelectorControls_div">
                <div class="gwd-div-vfso" id="profileArrowButtons_div">
                    <button id="downProfile_button" class="allelements-visibility" data-gwd-name="DownProfileButton">▼</button>
                    <button id="upProfile_button" class="allelements-visibility" data-gwd-name="UpProfileButton"></button>
                </div>
            </div>
        <?php } ?>
    </div>
    <div id="controls_div">
        <div id="sub_controls_div">



    <div id="verify_controls">
        <div class="verificationButtons" id="verificationButtons_div" <?php if ( !$is_custom_profile_allowed && !is_user_logged_in() ) { echo 'style="gap: 20px;"'; } ?>>
            <?php if ( !$is_custom_profile_allowed ) { ?> 
                <?php if ( is_user_logged_in() ) { 
                    // --- NEW LOGIC: Check Access Permissions ---
                    $can_download_dxf = false;
                    if ( function_exists('starke_has_architect_access') && starke_has_architect_access() ) {
                        $can_download_dxf = true;
                    }
                    if ( function_exists('impersonation_is_active') && impersonation_is_active() ) {
                        $can_download_dxf = true;
                    }
                    ?>
                    <button id="addSampleToCart_button" class="allelements-visibility addSampleToCartButton" data-gwd-name="AddSampleToCartButton"><?php echo set_add_sample_to_cart_title(); ?></button>
                    
                    <?php if ( $can_download_dxf ) : ?>
                        <a id="dxfDownload_button" class="allelements-visibility dxfDownloadButton" data-gwd-name="DXFDownloadButton" href="<?php echo set_dxf_download_url($product_id); ?>">DXF</a>
                    <?php else : ?>
                        <a id="dxfDownload_button" class="allelements-visibility dxfDownloadButton starke-trigger-login-drawer" data-gwd-name="DXFDownloadButton" href="#">DXF</a>
                    <?php endif; ?>

                <?php } else { ?>
                    <button id="compare_button" class="allelements-visibility compareButton" style="margin-bottom: 2px;" data-gwd-name="CompareButton">
                        <span id="compareText">COMPARE</span>
                        <input type="checkbox" id="compare_checkbox" class="gwd-input-1e0g">
                    </button>                    
                <?php } ?>
            <?php } ?>
            <button id="pdfDownload_button" class="allelements-visibility pdfDownloadButton" data-gwd-name="PDFDownloadButton">PDF</button>
        </div>
    </div>

    <div id="customization_controls">
        <div class="tabs" id="tabButtons">
            <div class="tab" data-tab="tab1">Species</div>
            <div class="tab active" data-tab="tab2">Size</div>
            <div class="tab" style="display: none;" data-tab="tab3">Features</div>
            <?php if ( $is_custom_profile_allowed ) { ?> 
                <div class="tab" style="display: none;" data-tab="tab4">Custom</div> 
            <?php } ?>
        </div>
        <div id="tabContents">
            <div class="tab-content" id="tab1">
            <div class="speciesAndFinish" id="speciesAndFinish_div">
            <div class="speciesShowHide" id="speciesShowHide_div">
                <label id="speciesShowHide_label" class="forShowHideLabels" data-gwd-name="SpeciesShowHideLabel">Species</label>
                <label id="speciesTri_label" class="speciesTri">▼</label>
            </div>

            <div class="allelements-visibility sheen-div" id="sheenLevels_div">
                <select id="sheenLevels_dropdown" class="gwd-input-1nmq gwd-dropdown-1aad sheen-dropdown">
                    <?php populate_sheen_dropdown(); ?>
                </select>
                <label id="sheenLevels_label" class="gwd-label-ytt8 gwd-label-18c5 sheen-label">Sheen Level</label>
            </div>

            <div class="allelements-visibility stain-div gwd-new-class-1trp" id="stainColor_div">
                <select id="stainColor_dropdown" class="gwd-input-1nmq stain-dropdown" data-gwd-name="StainColorDropdown">
                    <?php populate_stain_dropdown(); ?>
                </select>
                <label id="stainColor_label" class="gwd-label-ytt8 gwd-label-18c5 stain-label" data-gwd-name="StainColorLabel">Stain Color</label>
            </div>
            <div class="allelements-visibility gwd-div-8mfr gwd-new-class-ygby" id="finishOptions_div">
                <select id="finishOptions_dropdown" class="gwd-input-1nmq gwd-dropdown-1aad gwd-select-1gm3" data-gwd-name="FinishOptionsDropdown">
                    <?php populate_finish_dropdown(); ?>
                </select>
                <label id="finishOptions_label" class="gwd-label-ytt8 gwd-label-18c5 gwd-label-1tsd" data-gwd-name="FinishOptionsLabel">Finish Options</label>
            </div>
            <div class="allelements-visibility gwd-div-gt3r gwd-new-class-1ink" id="species_div">
                <select id="species_dropdown" class="gwd-input-1nmq gwd-dropdown-1aad gwd-select-170x" data-gwd-name="SpeciesDropdown">
				    <?php populate_species_dropdown(); ?>
				</select>
                <label id="species_label" class="gwd-label-ytt8 gwd-label-18c5 gwd-label-1l0d" data-gwd-name="SpeciesLabel">Species</label>
            </div>
        </div>
            </div>


            <div class="tab-content active" id="tab2">
            <div class="gwd-div-1p56 gwd-new-class-aqaw profileSizingControls" id="profileSizingControls_div">
            <div class="sizingShowHide" id="sizingShowHide_div">
                <label id="sizingShowHide_label" class="forShowHideLabels" data-gwd-name="SizingShowHideLabel">Size</label>
                <label id="sizingTri_label" class="sizingTri">▼</label>
            </div>
            <div class="linearFootageControls" id="linearFootageControls_div">
                
            </div>
            <div class="allelements-visibility" id="linearFeet_div">
                    <label id="linearFeet_label" class="gwd-label-ytt8 gwd-label-18c5 gwd-label-1y1b" data-gwd-name="LinearFeetLabel">Linear Feet</label>
                    <input type="search" inputmode="decimal" id="linearFeet_number" value="" maxlength="12" class="gwd-input-i0vj" data-gwd-name="LinearFeetNumberInputOutput" autocomplete="nope">
                    <label id="feetSymbol_label" class="gwd-label-5wfq" data-gwd-name="FeetSymbolLabel">ft</label>
                </div>
                <label id="discountOptions_label" class="allelements-visibility discountOptions_label" data-gwd-name="DiscountOptionsLabel">Discount Options</label>
                <div class="allelements-visibility" id="lengths_div">
                    <select id="lengthsDropdown_dropdown" class="gwd-input-1nmq gwd-select-bofr" data-gwd-name="LengthsDropdown">
                        <?php populate_lengths_dropdown(); ?>
                    </select>
                    <label id="lengths_label" class="gwd-label-ytt8 gwd-label-18c5" data-gwd-name="LengthsLabel">Length</label>
                </div>
            <div class="allelements-visibility profileWidth" id="profileWidth_div">
                <select id="profileWidth_dropdown" class="gwd-select-xvrs" data-gwd-name="ProfileWidthDropdown">
                    <option value="4-1/4" selected="">99-15/16"</option>
                    <option value="4-5/16">4-5/16"</option>
                    <option value="4-3/8">4-3/8"</option>
                    <option value="4-7/16">4-7/16"</option>
                    <option value="4-1/2">4-1/2"</option>
                </select>
                <label id="profileWidth_label" class="gwd-label-ytt8 slider-control-label" data-gwd-name="ProfileWidthLabel">Width</label>
                <input type="range" id="profileWidth_slider" value="0" min="0" max="10" step="0.0625" class="widthSlider" data-gwd-name="ProfileWidthSlider" autocomplete="one-time-code">
            </div>
            <div class="allelements-visibility profileThickness" id="profileThickness_div">
                <select id="profileThickness_dropdown" class="gwd-select-xvrs" data-gwd-name="ProfileThicknessDropdown">
                    <option value="3/4" selected="">99-15/16"</option>
                    <option value="13/16">13/16"</option>
                    <option value="7/8">7/8"</option>
                    <option value="15/16">.15/16"</option>
                    <option value="1">1"</option>
                </select>
                <label id="profileThickness_label" class="gwd-label-ytt8 slider-control-label" data-gwd-name="ProfileThicknessLabel">Thickness</label>
                <input type="range" id="profileThickness_slider" value="1" min="0" max="2" step="0.0625" class="thicknessSlider" data-gwd-name="ProfileThicknessSlider" autocomplete="one-time-code">
            </div>
        </div>
    </div>


            <div class="tab-content" id="tab3">
                <div class="topRightControls" id="topRightControls_div">

                    <div class="otherFeaturesOnOff gwd-new-class-1cuu" id="otherFeaturesOnOff_div">
                        <div class="allelements-visibility gwd-div-hvec" id="reliefAngleSwitch_div">
                            <label id="reliefAngleSwitch_label" class="gwd-label-ytt8 gwd-label-re52 gwd-label-1ahf checkbox" data-gwd-name="ReliefAngleSwitchLabel">
                                15° Relief Angle
                                <input type="checkbox" id="reliefAngleSwitch_checkbox" class="gwd-input-12fp" data-gwd-name="ReliefAngleSwitchCheckbox">
                            </label>
                        </div>
                        <div class="allelements-visibility gwd-div-2xwd backReliefSwitch" id="backReliefSwitch_div">
                            <label id="backReliefSwitch_label" class="gwd-label-ytt8 gwd-label-re52 gwd-label-pnhm" data-gwd-name="BackReliefSwitchLabel">
                                Back Relief
                                <input type="checkbox" id="backReliefSwitch_checkbox" class="gwd-input-1ihg" data-gwd-name="BackReliefSwitchCheckbox">
                            </label>
                        </div>
                    </div>

                    <div class="rabbetFeatureControls" id="rabbetFeatureControls_div">
                        <div class="gwd-div-gx54" id="featuresShowHide_div">
                            <label id="featuresShowHide_label" class="forShowHideLabels" data-gwd-name="FeaturesShowHideLabel">Features</label>
                            <label id="featuresTri_label" class="featuresTri">▼</label>
                        </div>
                        <div class="allelements-visibility rabbetPosition" id="rabbetPosition_div">
                            <select id="rabbetPosition_dropdown" class="gwd-select-xvrs gwd-new-select-1kn3" data-gwd-name="RabbetPositionDropdown">
                                <option value="0" selected="" class="gwd-option-16wq">OFF</option>
                                <option value="1" class="gwd-option-vdym">1</option>
                                <option value="2" class="gwd-option-maqh">2</option>
                                <option value="3" class="gwd-option-1ctn">3</option>
                                <option value="4" class="gwd-option-wlj1">4</option>
                            </select>
                            <label id="rabbetPosition_label" class="gwd-label-ytt8 gwd-label-re52 gwd-label-5v6a slider-control-label" data-gwd-name="RabbetPositionLabel">Rabbet Position</label>
                            <input type="range" id="rabbetPosition_slider" value="0" min="0" max="4" step="1" class="rabbetPositionSlider gwd-input-1vcq" data-gwd-name="RabbetPositionSlider">
                        </div>
                        <div class="allelements-visibility rabbetWidth" id="rabbetWidth_div">
                            <select id="rabbetWidth_dropdown" class="gwd-select-xvrs gwd-select-1jdq" data-gwd-name="RabbetWidthDropdown">
                                <option value="3/4" selected="">99-15/16"</option>
                                <option value="13/16">13/16"</option>
                                <option value="7/8">7/8"</option>
                                <option value="15/16">15/16"</option>
                                <option value="1">1"</option>
                                <option value="50-15/16">50-15/16"</option>
                            </select>
                            <label id="rabbetWidth_label" class="gwd-label-ytt8 gwd-label-re52 gwd-label-10hk slider-control-label" data-gwd-name="RabbetWidthLabel">Rabbet Width</label>
                            <input type="range" id="rabbetWidth_slider" value="5" min="0" max="10" step="0.0625" class="rabbetWidthSlider gwd-input-y5zf" data-gwd-name="RabbetWidthSlider">
                        </div>
                        <div class="allelements-visibility rabbetThickness" id="rabbetThickness_div">
                            <select id="rabbetThickness_dropdown" class="gwd-select-xvrs gwd-select-1808" data-gwd-name="RabbetThicknessDropdown">
                                <option value="3/4" selected="">99-15/16"</option>
                                <option value="13/16">13/16"</option>
                                <option value="7/8">7/8"</option>
                                <option value="15/16">15/16"</option>
                                <option value="50-15/16">50-15/16"</option>
                            </select>
                            <label id="rabbetThickness_label" class="gwd-label-ytt8 gwd-label-re52 gwd-label-j0cn slider-control-label" data-gwd-name="RabbetThicknessLabel">Rabbet Thickness</label>
                            <label id="rabbetThicknessInfo_label" class="rabbetThicknessInfo" data-gwd-name="RabbetThicknessLabel_Info">!</label>
                            <input type="range" id="rabbetThickness_slider" value="5" min="0" max="10" step="0.0625" class="rabbetThicknessSlider gwd-input-19bk" data-gwd-name="RabbetThicknessSlider">
                        </div>
                    </div>
                </div>
            </div>

        <?php if ( $is_custom_profile_allowed ) { ?>
            <div class="tab-content" id="tab4">
                <div class="custom-profile-controls" id="customProfileControls_div">
                    <div class="gwd-div-1p56 gwd-new-class-aqaw primaryCustomControls" id="primaryCustomControls_div">
                        <div class="allelements-visibility gwd-div-z4lq" id="tempProfileNumber_div">
                            <label id="tempProfileNumber_label" class="gwd-label-ytt8 gwd-label-18c5 gwd-label-1y1b">Temp#</label>
                            <label id="tempProfileNumberValue"><?php echo esc_attr($temp_custom_profile_number); ?></label>
                        </div>
                        <div class="allelements-visibility gwd-div-z4lq" id="knifeCost_div">
                            <label id="knifeCost_label" class="gwd-label-ytt8 gwd-label-18c5 gwd-label-1y1b">Knife Cost</label>
                            <input type="search" id="knifeCostAmount" value="<?php echo esc_attr($knifecost_price); ?>" min="0" max="10000000" step=".01" class="gwd-input-i0vj" autocomplete="nope">
                            <label id="knifeCostSymbol_label" class="gwd-label-5wfq">$</label>
                        </div>
                        <div class="allelements-visibility gwd-div-z4lq" id="markup_div">
                            <label id="markup_label" class="gwd-label-ytt8 gwd-label-18c5 gwd-label-1y1b">Markup</label>
                            <input type="search" id="markup_percentage" value="<?php echo esc_attr($markup_percent); ?>" min="0" max="10000000" step="1" class="gwd-input-i0vj" autocomplete="nope">
                            <label id="markup_percentage_label" class="gwd-label-5wfq"> %</label>
                        </div>
                        <div class="allelements-visibility gwd-div-z4lq" id="waste_div">
                            <label id="waste_label" class="gwd-label-ytt8 gwd-label-18c5 gwd-label-1y1b">Waste</label>
                            <input type="search" id="waste_percentage" value="<?php echo esc_attr($waste_percent); ?>" min="0" max="10000000" step="1" class="gwd-input-i0vj" autocomplete="nope">
                            <label id="waste_percentage_label" class="gwd-label-5wfq"> %</label>
                        </div>
                        <div class="allelements-visibility gwd-div-z4lq" id="similarProfiles_div">
                            <label id="similarProfiles_label" class="gwd-label-ytt8 gwd-label-18c5">Similar #'s</label>
                            <textarea id="similarProfilesValue_textarea"></textarea>
                        </div>
                        <div class="allelements-visibility gwd-div-z4lq" id="profileDescription_div">
                            <label id="profileDescription_label" class="gwd-label-ytt8 gwd-label-18c5">Description</label>
                            <textarea id="profileDescriptionValue_textarea"></textarea>
                        </div>
                    </div>
                </div>
            </div>
        <?php } ?>


        </div>
    </div>





    <div id="price_and_buy_controls">
        <div class="buyButtons gwd-new-class-drq3 gwd-new-class-v3cg gwd-new-class-gaug" id="buyButtons_div">
            <?php if ( is_user_logged_in() ) : ?>
                <form class="cart" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="thickness" id="hidden-thickness" value="" data-actual-value=""/>
                    <input type="hidden" name="width" id="hidden-width" value="" data-actual-value=""/>
                    <input type="hidden" name="length" id="hidden-length" value="" />
                    <input type="hidden" name="linear_feet" id="hidden-linearfeet" value="" data-actual-value=""/>
                    <input type="hidden" name="first_rabbet" id="hidden-firstrabbet" value="" data-actual-value=""/>
                    <input type="hidden" name="first_rabbet_thickness" id="hidden-firstrabbetthickness" value="" data-actual-value=""/>
                    <input type="hidden" name="first_rabbet_width" id="hidden-firstrabbetwidth" value="" data-actual-value=""/>
                    <input type="hidden" name="reliefangle" id="hidden-reliefangle" value="" data-actual-value=""/>
                    <input type="hidden" name="backrelief" id="hidden-backrelief" value="" data-actual-value=""/>
                    <input type="hidden" name="species" id="hidden-species" value="" data-actual-value=""/>
                    <input type="hidden" name="finish" id="hidden-finish" value="" data-actual-value=""/>
                    <input type="hidden" name="stain" id="hidden-stain" value="" data-actual-value=""/>
                    <input type="hidden" name="sheen" id="hidden-sheen" value="" data-actual-value=""/>
                    <?php if ( $is_custom_profile_allowed ) { ?>
                        <input type="hidden" name="knifecost" id="hidden-knifecost" value=""/>
                        <input type="hidden" name="markup" id="hidden-markup" value=""/>
                        <input type="hidden" name="waste" id="hidden-waste" value=""/>
                        <input type="hidden" name="similar_profiles" id="hidden-similar-profiles" value="" />
                        <input type="hidden" name="custom_description" id="hidden-custom-description" value="" />
                        <?php if ( $is_edit_mode ) { ?>
                            <input type="hidden" name="add_same_custom_profile" id="hidden-add-same-custom-profile" value=""/>
                        <?php } 
                    } ?>
                    <input type="hidden" name="sample" id="hidden-sample" value="" />
                    <input type="hidden" name="cikey" id="hidden-cikey" value="" />
                    <?php if (!is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) { ?>
                        <input type="hidden" name="add-to-cart" value="<?php echo esc_attr($product ? $product_id : ''); ?>" />
                    <?php } ?>
                    <button id="addToCart_button" class="allelements-visibility addToCartButton" data-gwd-name="AddToCartButton">ADD TO CART</button>
                </form>
            <?php endif; ?>
            <?php if ( !$is_custom_profile_allowed ) { 
                if ( is_user_logged_in() ) { ?>
                <button id="compare_button" class="allelements-visibility compareButton" data-gwd-name="CompareButton">
                    <span id="compareText">COMPARE</span>
                    <input type="checkbox" id="compare_checkbox" class="gwd-input-1e0g">
                </button>
            <?php }
            } else { 
                if ($is_edit_mode && is_user_logged_in()) { ?>
                    <button id="addSameCustomProfileToCart_button" class="allelements-visibility addToCartButton addSameCustomProfile"><?php echo esc_attr('ADD NEW ' . $custom_name); ?></button>
                <?php }
            } ?>
        </div>
        <?php 
        // --- NEW: Check if account is limited ---
        $is_limited = function_exists('starke_is_account_limited') && starke_is_account_limited();
        
        if ( is_user_logged_in() && ! $is_limited ) : ?>
            <div class="allelements-visibility gwd-new-class-owzz pricing" id="pricing_div">
                <div class="pricing-labels gwd-div-15i2 gwd-multi-r2b7 gwd-multi-1jcw" id="quantityDiscount_div">
                    <p class="pricing-labels gwd-p-105l">
                        Quantity Discount<br>
                    </p>
                    <p class="gwd-p-cguf">
                        <span class="gwd-span-s8tx" id="quantityDiscountValue_span">0</span>%
                    </p>
                </div>
                <div class="pricing-labels gwd-div-6t5v gwd-multi-r2b7 gwd-multi-1jcw" id="price_PerFoot_div">
                    <p class="pricing-labels gwd-p-1qcy">
                        Price Per Foot<br>
                    </p>
                    <p class="gwd-p-cguf">
                        $<span class="gwd-span-s8tx" id="pricePerFootValue_span">0.00</span>
                    </p>
                </div>
                <div class="pricing-labels gwd-div-1ob9 gwd-multi-r2b7 gwd-multi-1jcw" id="setupCharge_div">
                    <p class="pricing-labels gwd-p-1dzo">
                        Setup Charge<br>
                    </p>
                    <p class="gwd-p-cguf">
                        $<span class="gwd-span-s8tx" id="setupChargeValue_span">0.00</span>
                    </p>
                </div>
                <div class="pricing-labels gwd-div-m42w gwd-multi-r2b7 gwd-multi-1jcw" id="subtotal_div">
                    <p class="pricing-labels gwd-p-1ri1">
                        Subtotal<br>
                    </p>
                    <p class="gwd-p-cguf">
                        $<span class="gwd-span-s8tx" id="subtotalValue_span">0.00</span>
                    </p>
                </div>
            </div>
        <?php elseif ( $is_limited ) : ?>
            <a href="#" class="allelements-visibility login-prompt-button starke-trigger-login-drawer starke-limited-trigger" style="margin-bottom: 7px;">
                PRICING CURRENTLY HIDDEN: <br>VIEW ACCOUNT STATUS
            </a>
        <?php else : ?>
            <a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" class="allelements-visibility login-prompt-button starke-trigger-login-drawer">
                LOGIN/REGISTER TO SEE PRICING, ORDER SAMPLES, DOWNLOAD DRAWINGS, <br>AND MAKE PURCHASES
            </a>
        <?php endif; ?>
    </div>


    <div class="mainActionsControls" id="mainActionsControls_div">
    </div>
    <div class="allInputsControls" id="allInputsControls_div">
        <div class="pricingControls" id="pricingControls_div"></div>
        <div class="customizeShowHide" id="customizeShowHide_div">
            <label id="customizeShowHide_label" class="forShowHideLabels" data-gwd-name="CustomizeShowHideLabel">Customize</label>
            <label id="customizeTri_label" class="customizeTri">▼</label>
        </div>
    </div>
    
    <script gwd-served-sizes="" type="application/json">["120x60", "1000x100"]</script>
    <div class="bottomRightControls" id="bottomRightControls_div">
        <div class="viewControls" id="viewControls_div">
        </div>
    </div>
    
    <div class="bottomLeftControls" id="bottomLeftControls_div">
        <div class="pricingShowHide" id="pricingShowHide_div">
            <label id="pricingShowHide_label" class="forShowHideLabels" data-gwd-name="PricingShowHideLabel">Pricing</label>
            <label id="pricingTri_label" class="speciesTri">▼</label>
        </div>
        <a class="allelements-visibility loginOrRegister" id="loginOrRegister_button">
            LOGIN/REGISTER TO ORDER SAMPLES, DOWNLOAD DRAWINGS, AND MAKE PURCHASES
        </a>
        
        <div class="profileInfoAndbuyButtons" id="profileInfoAndbuyButtons_div">
        </div>
        
        
    </div>
    <div class="topLeftControls" id="topLeftControls_div">
        
        
    </div>
    <div class="cover" id="cover_div"></div>
    
        </div>
            </div>
</div>

<script>
	const header = document.querySelector('header');
	console.log('Header Height', header?.offsetHeight);
	const wpAdminBar = document.getElementById('wpadminbar');
	console.log('WP Admin Bar Height', wpAdminBar?.offsetHeight);
	const starke3dContainer = document.getElementById('starke3d-configurator');
	starke3dContainer.parentElement.style.marginTop = '0px';
	console.log('Parent', starke3dContainer?.parentElement.style);
	
	var relatedSection;
	function checkElementAvailable() {
    relatedSection = document.querySelector('.wp-block-group.woocommerce.product.is-layout-flow.wp-block-group-is-layout-flow');
		if (relatedSection) {
			// Element is available, log 'Rannn' to the console
			console.log('Rannn5');
			relatedSection.style.zIndex = '5';
			relatedSection.style.paddingTop = '16px';
			relatedSection.style.marginTop = '2px';
			relatedSection.style.backgroundColor = 'white';
		} else {
			// If the element isn't found, check again on the next frame
			requestAnimationFrame(checkElementAvailable);
		}
	}
	requestAnimationFrame(checkElementAvailable);

    // --- NEW: Trigger Login/Account Drawer for DXF & Limited Buttons ---
    document.addEventListener('click', function(e) {
        // Check if the clicked element has our specific trigger class
        if (e.target.matches('.starke-trigger-login-drawer') || e.target.closest('.starke-trigger-login-drawer')) {
            e.preventDefault();
            
            var triggerEl = e.target.matches('.starke-trigger-login-drawer') ? e.target : e.target.closest('.starke-trigger-login-drawer');
            
            const dxfMsg = document.getElementById('starke-dxf-denial-msg');
            const limitedMsg = document.getElementById('starke-limited-access-msg');
            
            // Grab the global limited status we passed from PHP
            const isAccountLimited = window.starke3d_data && window.starke3d_data.isAccountLimited;

            // SCENARIO 1: They clicked the "Account Limited" Pricing Button
            if (triggerEl.classList.contains('starke-limited-trigger')) {
                if (dxfMsg) dxfMsg.style.display = 'none'; 
                if (limitedMsg) limitedMsg.style.display = 'block'; 
            } 
            // SCENARIO 2: They clicked the "DXF" Download Button
            else if (triggerEl.classList.contains('dxfDownloadButton')) {
                // --- NEW LOGIC: Limited Access Overrides Architect Access ---
                if (isAccountLimited) {
                    if (dxfMsg) dxfMsg.style.display = 'none'; 
                    if (limitedMsg) limitedMsg.style.display = 'block'; // Show Limited instead!
                } else {
                    if (limitedMsg) limitedMsg.style.display = 'none'; 
                    if (dxfMsg) dxfMsg.style.display = 'block'; // Normal Architect Message
                }
            }
            
            // Find the Header "My Account" / Login button
            const headerLoginBtn = document.querySelector('header a[href*="my-account"]');

            if (headerLoginBtn) {
                // Click it programmatically to open the drawer
                headerLoginBtn.click();
            } else {
                // Fallback: If drawer trigger isn't found, go to account page
                window.location.href = '/my-account/';
            }
        }
    });
</script>
<?php
}