<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// =======================================================================
// 1. AJAX ENDPOINT & RENDER HELPER FUNCTIONS
// =======================================================================

function dps_render_door_type_content($slug, $is_limited) {
    $type = get_term_by('slug', $slug, 'door_type');
    if (!$type) return '<p>Profile category not found.</p>';
    $all_thicknesses = ['1 3/8"', '1 3/4"', '2 1/4"', '3"'];
    ob_start();
    ?>
    <div class="dps-intro-text-container" style="margin-left: clamp(.75rem, 4vmin, 4rem); margin-right: clamp(.75rem, 4vmin, 4rem);">
        <h3 class="wp-block-heading has-lora-font-family" style="margin-top:0; margin-bottom: 1rem; text-align:left; font-size: clamp(1.5rem, 4vmin, 2rem) !important;"><?php echo esc_html($type->name); ?> Profiles</h3>
        <?php if (!empty($type->description)) : ?>
            <p style="border-bottom: 2px solid var(--wp--preset--color--outline); margin-bottom: var(--wp--preset--spacing--large); padding-bottom: var(--wp--preset--spacing--small); font-size: clamp(1.1rem, 4vmin, 1.4rem) !important; color: #555; text-align:left;"><?php echo wp_kses_post($type->description); ?></p>
        <?php endif; ?>
    </div>
    <div class="dps-profile-row row">
        <?php
        $args = array('post_type' => 'door', 'post_status' => 'publish', 'posts_per_page' => -1, 'tax_query' => array(array('taxonomy' => 'door_type', 'field' => 'slug', 'terms' => $type->slug)), 'orderby' => 'title', 'order' => 'ASC');
        $door_query = new WP_Query($args);
        if ($door_query->have_posts()) :
            while ($door_query->have_posts()) : $door_query->the_post();
                $post_id = get_the_ID(); $post_title = get_the_title(); $title_slug = get_post_field('post_name', $post_id); 
                $image_args = array('post_type' => 'attachment', 'post_mime_type' => array('image/jpeg', 'image/png', 'image/gif'), 'posts_per_page' => 3, 'post_status' => 'inherit', 's' => $title_slug, 'orderby' => 'post_name', 'order' => 'ASC');
                $gallery_image_posts = get_posts($image_args);
                $filtered_posts = array_filter($gallery_image_posts, function($post) use ($title_slug) { return $post->post_name === $title_slug || strpos($post->post_name, $title_slug . '-') === 0; });
                if (!empty($filtered_posts)) usort($filtered_posts, function($a, $b) use ($post_title) { $title_a = $a->post_title; $title_b = $b->post_title; if ($title_a === $post_title && $title_b !== $post_title) return -1; if ($title_b === $post_title && $title_a !== $post_title) return 1; return strnatcmp($title_a, $title_b); });
                $gallery_images = array();
                if (!empty($filtered_posts)) { foreach ($filtered_posts as $image_post) { $gallery_images[] = array('url' => wp_get_attachment_url($image_post->ID), 'alt' => get_post_meta($image_post->ID, '_wp_attachment_image_alt', true), 'full_url' => wp_get_attachment_url($image_post->ID), 'thumb_url' => wp_get_attachment_image_src($image_post->ID, 'medium')[0]); } }
                $pdf_file_url = ''; $pdf_filename = $post_title . '.pdf'; $pdf_args = array('post_type' => 'attachment', 'post_mime_type' => 'application/pdf', 'posts_per_page' => 1, 'post_status' => 'inherit', 'meta_query' => array(array('key' => '_wp_attached_file', 'value' => $pdf_filename, 'compare' => 'LIKE')));
                $pdf_file_posts = get_posts($pdf_args); if (!empty($pdf_file_posts)) $pdf_file_url = add_query_arg(['download_file' => $pdf_file_posts[0]->ID, '_wpnonce' => wp_create_nonce('pdf_download_nonce')]);
                $dxf_file_url = ''; $dxf_exists = false; $dxf_filename = $post_title . '.dxf'; $dxf_args = array('post_type' => 'attachment', 'posts_per_page' => 1, 'post_status' => 'inherit', 'meta_query' => array(array('key' => '_wp_attached_file', 'value' => $dxf_filename, 'compare' => 'LIKE')));
                $dxf_file_posts = get_posts($dxf_args); if (!empty($dxf_file_posts)) { $dxf_file_url = add_query_arg(['download_file' => $dxf_file_posts[0]->ID, '_wpnonce' => wp_create_nonce('dxf_download_nonce')]); $dxf_exists = true; }
                $selected_thicknesses = get_field('available_thicknesses', $post_id); if (empty($selected_thicknesses)) $selected_thicknesses = array();
        ?>
        <div class="col-md-4 product door">
            <div class="woocommerce-LoopProduct-link woocommerce-loop-product__link">
                <h2 class="woocommerce-loop-product__title"><?php echo esc_html($post_title); ?></h2>
                <?php if (!empty($gallery_images)) : $carousel_id = 'carousel-' . $post_id; ?>
                    <div id="<?php echo esc_attr($carousel_id); ?>" class="carousel slide" data-interval="false"><div class="carousel-inner"><?php foreach ($gallery_images as $index => $image) : ?><div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>"><a class="dps-lightbox-trigger" href="<?php echo esc_url($image['full_url']); ?>"><img decoding="async" loading="lazy" width="300" height="300" src="<?php echo esc_url($image['thumb_url']); ?>" class="attachment-300x300 size-300x300" alt="<?php echo esc_attr($image['alt']); ?>"></a></div><?php endforeach; ?></div><?php if (count($gallery_images) > 1) : ?><a class="carousel-control-prev" href="#<?php echo esc_attr($carousel_id); ?>" role="button" data-slide="prev"><i class="fa fa-angle-left" aria-hidden="true"></i><span class="sr-only">Previous</span></a><a class="carousel-control-next" href="#<?php echo esc_attr($carousel_id); ?>" role="button" data-slide="next"><i class="fa fa-angle-right" aria-hidden="true"></i><span class="sr-only">Next</span></a><?php endif; ?></div>
                <?php else: ?>
                    <div class="carousel slide"><div class="carousel-inner"><div class="carousel-item active"><img decoding="async" loading="lazy" width="300" height="300" src="https://placehold.co/300x300/f0f0f0/ccc?text=No+Image" class="attachment-300x300 size-300x300" alt="No Image"></div></div></div>
                <?php endif; ?>
            </div>
            <div class="info-container">
                <div class="dxf">
                    <script> var dxfExists = <?php echo $dxf_exists ? 'true' : 'false'; ?>; </script>
                    <?php if (!empty($pdf_file_url)) : ?><a class="btn button swc-pdf-download" href="<?php echo esc_url($pdf_file_url); ?>">PDF DOWNLOAD</a><?php else: ?><a class="btn button swc-pdf-download disabled" href="#" onclick="return false;">PDF N/A</a><?php endif; ?>
                    <?php $dxf_can_download = false; if (is_user_logged_in() && !$is_limited) { if ((function_exists('starke_has_architect_access') && starke_has_architect_access()) || (function_exists('impersonation_is_active') && impersonation_is_active())) $dxf_can_download = true; } if ($dxf_exists) : if ($dxf_can_download) : ?><a class="btn btn-primary button" id="dxfDownload" href="<?php echo esc_url($dxf_file_url); ?>">DXF DOWNLOAD</a><?php else : ?><a class="btn btn-primary button starke-trigger-login-drawer dps-dxf-trigger" href="#" role="button">DXF DOWNLOAD</a><?php endif; else : ?><a class="btn btn-primary button disabled" id="dxfDownload" href="#" onclick="return false;">DXF N/A</a><?php endif; ?>    
                </div>
                <?php if ($type->slug !== 'groove') : ?>
                <div class="info"><h3>Available Door Thicknesses:</h3><table class="table table-bordered"><thead><tr><?php foreach ($all_thicknesses as $thickness) : ?><th><?php echo esc_html($thickness); ?></th><?php endforeach; ?></tr></thead><tbody><tr><?php foreach ($all_thicknesses as $thickness) : ?><td width="25%"><?php if (in_array($thickness, $selected_thicknesses)) : ?><i class="fa fa-check"></i><?php else : ?><i class="fa fa-times"></i><?php endif; ?></td><?php endforeach; ?></tr></tbody></table></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endwhile; else: echo '<p>No doors found in this category.</p>'; endif; wp_reset_postdata(); ?>
    </div>
    <?php return ob_get_clean();
}

function dps_render_saddle_options_content() {
    ob_start();
    ?>
    <div class="dps-intro-text-container" style="margin-left: clamp(.75rem, 4vmin, 4rem); margin-right: clamp(.75rem, 4vmin, 4rem);"><h3 class="wp-block-heading has-lora-font-family" style="margin-top:0; margin-bottom: 1rem; text-align:left; font-size: clamp(1.5rem, 4vmin, 2rem) !important;">Solid Bronze Saddle Options</h3><p style="border-bottom: 2px solid var(--wp--preset--color--outline); margin-bottom: var(--wp--preset--spacing--large); padding-bottom: var(--wp--preset--spacing--small); font-size: clamp(1.1rem, 4vmin, 1.4rem) !important; color: #555; text-align:left;">We offer many different saddle options for many different applications. Here are some of the options we offer. If you don’t see a good fit for your application, let us know! We’ll work out the details for you.</p></div>
    <div class="dps-profile-row row saddles-grid-row">
        <?php
        $saddle_query = new WP_Query(array('post_type' => 'saddle', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'menu_order', 'order' => 'ASC'));
        if ($saddle_query->have_posts()) : while ($saddle_query->have_posts()) : $saddle_query->the_post(); $post_id = get_the_ID(); $post_title = get_the_title(); $image_url = get_the_post_thumbnail_url($post_id, 'medium'); $full_url = get_the_post_thumbnail_url($post_id, 'full'); $alt_text = get_post_meta(get_post_thumbnail_id($post_id), '_wp_attachment_image_alt', true); if (!$image_url) { $image_url = 'https://placehold.co/300x300/f0f0f0/ccc?text=No+Image'; $full_url = 'https://placehold.co/600x600/f0f0f0/ccc?text=No+Image'; }
        ?><div class="col-md-4 product door product-saddle"><div class="woocommerce-LoopProduct-link woocommerce-loop-product__link"><h2 class="woocommerce-loop-product__title"><?php echo esc_html($post_title); ?></h2><div class="carousel slide" data-interval="false"><div class="carousel-inner"><div class="carousel-item active"><a class="dps-lightbox-trigger" href="<?php echo esc_url($full_url); ?>"><img decoding="async" loading="lazy" width="300" height="300" src="<?php echo esc_url($image_url); ?>" class="attachment-300x300 size-300x300" alt="<?php echo esc_attr($alt_text); ?>"></a></div></div></div></div></div><?php endwhile; else: echo '<p>No saddle options found.</p>'; endif; wp_reset_postdata(); ?>
    </div> 
    <div class="dps-intro-text-container" style="margin-left: clamp(.75rem, 4vmin, 4rem); margin-right: clamp(.75rem, 4vmin, 4rem); margin-top: 2rem;"><h3 class="wp-block-heading has-lora-font-family" style="margin-top:0; margin-bottom: 1rem; text-align:left; font-size: clamp(1.5rem, 4vmin, 2rem) !important;">Finish Options</h3><p style="border-bottom: 2px solid var(--wp--preset--color--outline); margin-bottom: var(--wp--preset--spacing--large); padding-bottom: var(--wp--preset--spacing--small); font-size: clamp(1.1rem, 4vmin, 1.4rem) !important; color: #555; text-align:left;">All of our saddles are made from solid bronze except the aluminum profile, and come in a variety of finishes to compliment your hardware.</p></div>
    <div class="dps-profile-row row" style="justify-content: center;"><div class="col-md-4 product door finishes-swiper-wrapper" style="max-width: 1100px; max-height: 697px; flex: 0 0 90%; min-width: 300px; border-radius: 5px; padding-left: 0px; padding-right: 0px; box-shadow: 0px 0px 15px rgba(0, 0, 0, 0.4);"><div class="woocommerce-LoopProduct-link woocommerce-loop-product__link"><h2 class="woocommerce-loop-product__title">Finishes</h2>
        <?php
        $rml_finishes_folder_id = 14; $finish_images_query = new WP_Query(array('post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'post_mime_type' => 'image', 'orderby' => 'menu_order', 'order' => 'ASC', 'rml_folder' => $rml_finishes_folder_id));
        if ($finish_images_query->have_posts()) : ?><div class="swiper finishes-swiper"><div class="swiper-wrapper"><?php while ($finish_images_query->have_posts()) : $finish_images_query->the_post(); $image_id = get_the_ID(); $full_image_url = wp_get_attachment_url($image_id); $medium_large_image_url = wp_get_attachment_image_src($image_id, 'medium_large'); $image_alt = get_post_meta($image_id, '_wp_attachment_image_alt', true); ?><div class="swiper-slide"><a class="dps-lightbox-trigger" href="<?php echo esc_url($full_image_url); ?>"><img decoding="async" loading="lazy" width="600" height="600" src="<?php echo esc_url($medium_large_image_url ? $medium_large_image_url[0] : $full_image_url); ?>" class="attachment-300x300 size-300x300" alt="<?php echo esc_attr($image_alt); ?>"></a></div><?php endwhile; wp_reset_postdata(); ?></div><div class="swiper-pagination"></div></div><?php else : ?><div class="carousel slide"><div class="carousel-inner"><div class="carousel-item active"><img decoding="async" loading="lazy" width="300" height="300" src="https://placehold.co/300x300/f0f0f0/ccc?text=No+Finishes+Found" class="attachment-300x300 size-300x300" alt="No Finishes Found"></div></div></div><?php endif; ?>
    </div></div></div>
    <?php return ob_get_clean();
}

function dps_render_finish_carousel($title, $rml_folder_id, $swiper_class) {
    ?><div class="dps-profile-row row" style="justify-content: center; margin-bottom: 15.5rem;"><div class="wood-finish-swiper-wrapper" style="width: 95%; max-width: 1200px; max-height: 800px; min-width: 300px; border-radius: 5px; padding: 0px; padding-top: 40px; padding-bottom: 40px; margin-left: 30px; margin-right: 30px; box-shadow: 0px 0px 15px rgba(0, 0, 0, 0.4); margin-top: 20px;"><div class="woocommerce-LoopProduct-link woocommerce-loop-product__link"><h2 class="woocommerce-loop-product__title"><?php echo esc_html($title); ?></h2><?php $finish_images_query = new WP_Query(array('post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'post_mime_type' => 'image', 'orderby' => 'menu_order', 'order' => 'ASC', 'rml_folder' => $rml_folder_id)); if ($finish_images_query->have_posts()) : ?><div class="swiper wood-finish-thumbs <?php echo esc_attr($swiper_class); ?>-thumbs"><div class="swiper-wrapper"><?php while ($finish_images_query->have_posts()) : $finish_images_query->the_post(); $image_id = get_the_ID(); $thumb_url = wp_get_attachment_image_src($image_id, 'thumbnail'); $image_alt = get_post_meta($image_id, '_wp_attachment_image_alt', true); ?><div class="swiper-slide"><img decoding="async" loading="lazy" src="<?php echo esc_url($thumb_url[0]); ?>" alt="<?php echo esc_attr($image_alt); ?>"></div><?php endwhile; ?></div></div><?php $finish_images_query->rewind_posts(); ?><div class="swiper wood-finish-swiper <?php echo esc_attr($swiper_class); ?>-main"><div class="swiper-wrapper"><?php while ($finish_images_query->have_posts()) : $finish_images_query->the_post(); $image_id = get_the_ID(); $full_image_url = wp_get_attachment_url($image_id); $medium_large_image_url = wp_get_attachment_image_src($image_id, 'medium_large'); $image_alt = get_post_meta($image_id, '_wp_attachment_image_alt', true); $image_title = get_the_title(); ?><div class="swiper-slide"><a class="dps-lightbox-trigger" href="<?php echo esc_url($full_image_url); ?>"><img decoding="async" loading="lazy" width="600" height="600" src="<?php echo esc_url($medium_large_image_url ? $medium_large_image_url[0] : $full_image_url); ?>" class="attachment-300x300 size-300x300" alt="<?php echo esc_attr($image_alt); ?>"></a><div class="wood-finish-title"><?php echo esc_html($image_title); ?></div></div><?php endwhile; wp_reset_postdata(); ?></div></div><?php else : ?><div class="carousel slide"><div class="carousel-inner"><div class="carousel-item active"><img decoding="async" loading="lazy" width="300" height="300" src="https://placehold.co/300x300/f0f0f0/ccc?text=No+Finishes+Found" class="attachment-300x300 size-300x300" alt="No Finishes Found"></div></div></div><?php endif; ?></div></div></div><?php
}

function dps_render_finish_options_content() {
    ob_start();
    ?><div class="dps-intro-text-container" style="margin-left: clamp(.75rem, 4vmin, 4rem); margin-right: clamp(.75rem, 4vmin, 4rem);"><h1 class="wp-block-heading has-lora-font-family" style="margin-top:0; margin-bottom: 1rem; text-align:left; font-size: clamp(1.5rem, 4vmin, 2rem) !important;">Finish Options</h1><p style="border-bottom: 2px solid var(--wp--preset--color--outline); margin-bottom: var(--wp--preset--spacing--large); padding-bottom: var(--wp--preset--spacing--small); font-size: clamp(1.1rem, 4vmin, 1.4rem) !important; color: #555; text-align:left;">At Starke Millwork Inc. we finish all of our products in house. We would be happy to provide you with actual samples finished on your choice of wood specie – just let us know what you are looking for. For exterior stained units, we apply the stain and then 3 coats of a catalyzed polyurethane. Our interior stained units receive the stain and 3 coats of catalyzed lacquer. For interior and exterior paint-primed applications, we can provide your products with a white primer or we could prime and paint in the color of your choice.</p><p style="font-size: clamp(1.1rem, 4vmin, 1.4rem) !important; color: #555; text-align:left; margin-bottom: 1rem;">We also mix stains in house if you need to match an existing color. We would need a control sample from you as pictures don’t represent the true color.</p><p style="font-size: clamp(1.1rem, 4vmin, 1.4rem) !important; color: #555; text-align:left; margin-bottom: 1rem;">The stain colors below reflect a typical color tone, and may differ slightly due to variations in color, grain and texture of any wood specie. Some variation in color tone is unavoidable and an exact match of the sample finishes is not guaranteed. These Finishes are available for all lumber products.</p><p style="font-size: clamp(1.1rem, 4vmin, 1.4rem) !important; color: #555; text-align:left; margin-bottom: 1rem;">Our standard sheen is a Satin 35 but we can do higher or lower depending on your needs. Please inform us if you would prefer a different option.</p><p style="font-size: clamp(1.1rem, 4vmin, 1.4rem) !important; color: #555; text-align:left; border-top: 2px solid var(--wp--preset--color--outline); padding-top: 1.5rem; margin-top: 1.5rem; margin-bottom: 5.5rem"><strong>The below images are a first step in choosing your finish color. Due to the variation from monitor to monitor or device to device, we strongly suggest that you request actual finished wood samples to&nbsp;get a more&nbsp;realistic idea of what you will be receiving. Please let us know which ones you like and we will ship them to you free of charge. You can also pass by our <em><a href="/about/contact-us">Showroom</a></em> to pick them up if&nbsp;<span style="color: #000000;">you’re in the area.</span></strong></p></div><?php $mahogany_rml_id = 15; $poplar_rml_id = 16; $walnut_rml_id = 17; $white_oak_rml_id = 18; dps_render_finish_carousel('Poplar', $poplar_rml_id, 'poplar-finishes-swiper'); dps_render_finish_carousel('Walnut', $walnut_rml_id, 'walnut-finishes-swiper'); dps_render_finish_carousel('White Oak', $white_oak_rml_id, 'white-oak-finishes-swiper'); dps_render_finish_carousel('Mahogany', $mahogany_rml_id, 'mahogany-finishes-swiper'); ?><div class="dps-intro-text-container" style="margin-left: clamp(.75rem, 4vmin, 4rem); margin-right: clamp(.75rem, 4vmin, 4rem); margin-top: 2rem;"><h3 class="wp-block-heading has-lora-font-family" style="margin-top:0; margin-bottom: 1rem; text-align:left; font-size: 2rem; text-decoration: underline;">Disclaimer</h3><p style="font-size: 1.1rem; color: #555; text-align:left; margin-bottom: 1rem;"><em>Wood, like all natural materials, has inherent dispairites in color and grain pattern. Because of variations caused by nature, over which Starke Millwork Inc. has no control, Starke Millwork Inc. does its best to match the finish samples and grain examples provided. Starke Millwork Inc.&nbsp;does not guarantee an exact match to the sample provided due to the natural variation of the wood. Samples of grain pattern and color are matched to the best of our ability. Due to the natural variation in wood, each piece will have a variation in how it accepts the finishing process.&nbsp;</em></p><p style="font-size: 1.1rem; color: #555; text-align:left; margin-bottom: 1rem;"><em>Finish is applied to the wood surfaces as a protective coat. Variations will occur in color, character, tone and grain from product to product. Slight variations between samples and finished goods should be anticipated. These variations will not be accepted by Starke Millwork Inc. as a reason to reject an order in part or in full.</em></p><p style="font-size: 1.1rem; color: #555; text-align:left; margin-bottom: 1rem;"><em>The above images are a first step in choosing your finish color. Due to the variation from monitor to monitor or device to device, we strongly suggest that you request actual finished wood samples to&nbsp;get a more&nbsp;realistic idea of what you will be receiving. We will ship them to you free of charge.</em></p></div><?php return ob_get_clean();
}

add_action('template_redirect', 'dps_handle_tab_ajax');
function dps_handle_tab_ajax() {
    // Listen for POST requests to bypass all HTML caching plugins
    if (isset($_POST['starke_door_ajax']) && isset($_POST['tab_id'])) {
        
        // Extra safeguard: Tell caching plugins not to cache this specific response
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        $tab_id = sanitize_text_field($_POST['tab_id']);
        $is_limited = function_exists('starke_is_account_limited') && starke_is_account_limited();
        
        if ($tab_id === 'saddle-options') { $html = dps_render_saddle_options_content(); } 
        elseif ($tab_id === 'finish-options') { $html = dps_render_finish_options_content(); } 
        else { $html = dps_render_door_type_content($tab_id, $is_limited); }
        
        wp_send_json_success(['html' => $html]);
        exit;
    }
}

/**
 * This file contains the TWO functions from your original, working file.
 * The fatal errors have been fixed.
 *
 * 1. dps_layout_callback_auto (HTML is updated)
 * 2. dps_enqueue_assets_auto (JS is updated, fatal errors removed)
 *
 * Please replace the ENTIRE contents of your file with this block.
 */

// 1. Register the Shortcode (from your original file)
add_shortcode('door_profiles_layout', 'dps_layout_callback_auto');

/**
 * 1. The Shortcode Callback Function
 * (MODIFIED: HTML classes/attributes changed for new script)
 * (RESTORED: The call to dps_enqueue_assets_auto() is back)
 */
function dps_layout_callback_auto() {
    // Define the exact order of slugs you want.
    // You can easily re-arrange this array to change the tab order.
    $slug_order = array('sticking', 'panel', 'groove');

    // Get all 'door_type' taxonomy terms
    $door_types = get_terms(array(
        'taxonomy'   => 'door_type',
        'hide_empty' => true,
        'slug'       => $slug_order,
        'orderby'    => 'slug__in', // This tells WordPress to use the order from the 'slug' array
    ));

    // If no terms, exit
    if (empty($door_types) || is_wp_error($door_types)) {
        return '<p>No door profiles found.</p>';
    }

    // *** RESTORED: This call is back, just like your original file ***
    // This loads the CSS and JS for the shortcode.
    dps_enqueue_assets_auto();

    // Start output buffering
    ob_start();

    // --- NEW: Check Limited Status and pass to JS ---
    $is_limited = function_exists('starke_is_account_limited') && starke_is_account_limited();
    ?>
    <script>
        window.starke_door_details_data = {
            isAccountLimited: <?php echo json_encode( $is_limited ); ?>
        };
    </script>
    
    <!-- CHANGE: Added 'gallery-tabs-container' class for the new JS to target --><div class="dps-container gallery-tabs-container">
        <!-- A. The Tab Navigation --><!-- CHANGE: HTML structure updated to match photo-gallery-tabs style --><div class="photo-tabs" id="galleryTabButtons" role="tablist">
            
            <?php foreach ($door_types as $index => $type) : ?>
                <!-- CHANGE: Replaced <li> and <button> with a single <div> to match your new style --><div class="photo-tab <?php echo $index === 0 ? 'active' : ''; ?>"
                     id="tab-<?php echo esc_attr($type->slug); ?>"
                     data-tab="<?php echo esc_attr($type->slug); ?>"
                     role="tab"
                     aria-controls="content-for-<?php echo esc_attr($type->slug); ?>"
                     aria-selected="<?php echo $index === 0 ? 'true' : 'false'; ?>"
                     tabindex="0"> <!-- Added tabindex for accessibility --><?php echo esc_html($type->name); ?> Profiles
                </div>
            <?php endforeach; ?>
            
            <!-- STATIC TAB FOR SADDLE OPTIONS (Added after the loop) --><div class="photo-tab"
                 id="tab-saddle-options"
                 data-tab="saddle-options"
                 role="tab"
                 aria-controls="content-for-saddle-options"
                 aria-selected="false"
                 tabindex="0">
                Saddle Options
            </div>

            <div class="photo-tab"
                 id="tab-finish-options"
                 data-tab="finish-options"
                 role="tab"
                 aria-controls="content-for-finish-options"
                 aria-selected="false"
                 tabindex="0">
                Finish Options
            </div>
        </div>

        <!-- B. The Tab Content --><!-- CHANGE: Added 'id' and 'photo-tab-content' class -->
        <div class="dps-tab-content" id="galleryTabContents">
            
            <?php foreach ($door_types as $index => $type) : ?>
                <div class="dps-tab-pane photo-tab-content <?php echo $index === 0 ? 'active' : ''; ?>" 
                     id="content-for-<?php echo esc_attr($type->slug); ?>" 
                     role="tabpanel" 
                     aria-labelledby="tab-<?php echo esc_attr($type->slug); ?>"
                     data-loaded="false">
                    
                    <div class="starke-ajax-placeholder" style="width:100%; min-height:300px; display:flex; justify-content:center; align-items:center;">
                        <div style="width:50px; height:50px; border:4px solid #eee; border-top:4px solid var(--wp--preset--color--primary, #6431F6); border-radius:50%; animation:starke-spin 1s linear infinite;"></div>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="dps-tab-pane photo-tab-content" id="content-for-saddle-options" role="tabpanel" aria-labelledby="tab-saddle-options" data-loaded="false">
                <div class="starke-ajax-placeholder" style="width:100%; min-height:300px; display:flex; justify-content:center; align-items:center;">
                    <div style="width:50px; height:50px; border:4px solid #eee; border-top:4px solid var(--wp--preset--color--primary, #6431F6); border-radius:50%; animation:starke-spin 1s linear infinite;"></div>
                </div>
            </div>
            
            <div class="dps-tab-pane photo-tab-content" id="content-for-finish-options" role="tabpanel" aria-labelledby="tab-finish-options" data-loaded="false">
                <div class="starke-ajax-placeholder" style="width:100%; min-height:300px; display:flex; justify-content:center; align-items:center;">
                    <div style="width:50px; height:50px; border:4px solid #eee; border-top:4px solid var(--wp--preset--color--primary, #6431F6); border-radius:50%; animation:starke-spin 1s linear infinite;"></div>
                </div>
            </div>
        </div>
    </div>
        
    
    <?php
    // Return the buffered content
    return ob_get_clean();
}

/**
 * 2. Enqueue Styles and Scripts
 * (MODIFIED: JS replaced, and fatal error from wp_enqueue_style removed)
 */
function dps_enqueue_assets_auto() {
    // Use a static variable to ensure this only runs ONCE per page load
    static $assets_added = false;
    if ($assets_added) {
        return;
    }
    $assets_added = true;
    // **FIX**: Your theme *already* loads Font Awesome, so we don't need
    // this line. This line was causing a fatal error.
    // if (!wp_style_is('font-awesome', 'enqueued')) { ... }

    // A. Inline CSS
    $css = "
        :root {
            --dps-border: #ddd;
            --dps-text: #333;
            --dps-bg: #fff;
            --dps-red: #d9534f;
            --dps-check: #5a8e3c;
            --dps-grey: #aaa;
        }
        /* --- NEW: Add the missing spin animation --- */
        @keyframes starke-spin { 
            100% { transform: rotate(360deg); } 
        }
        #lightbox-image {
            -webkit-user-select: none; /* Safari/Chrome */
            -moz-user-select: none;    /* Firefox */
            -ms-user-select: none;     /* Edge */
            user-select: none;         /* Standard */
            -webkit-user-drag: none;   /* Prevents the Mac drag file to desktop behavior */
        }
        .dps-container {
            width: 100%;
            margin: 2rem 0;
            font-family: 'Inter', sans-serif;
        }
        .dps-container.gallery-tabs-container {
            max-width: 100%;
        }
        /* Tab Navigation Styles */

        .dps-tab-content {
            padding: 1.5rem 0;
        }
        .dps-tab-pane {
            display: none;
        }
        .dps-tab-pane.active {
            display: block;
        }

        /* Grid and Item Styles */
        .dps-profile-row {
            display: flex;
            flex-wrap: wrap;
            margin-left: -30px;
            margin-right: -30px;
            justify-content: center;
        }
        .product.door {
            box-sizing: border-box;
            width: 100%;
            min-height: 1px;
            flex: 0 0 33.333333%;
            max-width: 428px;
            position: relative;
            padding-left: 30px;
            padding-right: 30px;
            margin: 40px 0 40px;
            display: flex;
            flex-direction: column;
            min-width: 375px;
            
            /* NEW: Smooth Fade-In and Slide-Up Base State */
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.5s ease-out, transform 0.5s ease-out;
        }
        
        /* The class JS will add to trigger the animation */
        .product.door.starke-fade-in {
            opacity: 1;
            transform: translateY(0);
        }
        
        /* Exclude Saddles and Finishes so they remain static as requested */
        #content-for-saddle-options .product.door, 
        #content-for-finish-options .product.door {
            opacity: 1 !important;
            transform: none !important;
            transition: none !important;
        }
        
        .dps-profile-row .product.door {
            max-width: 428px;
        }
        .dps-profile-row .product.door {
            max-width: 428px;
        }
        @media (max-width: 992px) {
            .product.door {
                flex: 0 0 50%;
                max-width: 50%;
            }
        }
        @media (max-width: 768px) {
            .product.door {
                flex: 0 0 90%;
                max-width: 100%;
            }
            .dps-profile-row {
                margin-left: -15px;
                margin-right: -15px;
            }
        }

        /* Carousel Styles */
        .carousel {
            position: relative;
        }
        .carousel-inner {
            position: relative;
            width: 100%;
            overflow: hidden;
            border: 1px solid #eee;
            border-radius: 5px;
            box-shadow: 0px 0px 15px rgba(0, 0, 0, 0.2);
        }
        .carousel-item {
            display: none;
            position: relative;
            width: 100%;
            transition: transform .6s ease-in-out;
        }
        .carousel-item.active {
            display: block;
        }
        .carousel-control-prev,
        .carousel-control-next {
            position: absolute;
            top: 0;
            bottom: 0;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 15%;
            color: #fff;
            text-align: center;
            opacity: .5;
            transition: opacity .15s ease;
        }
        .carousel-control-prev { left: -30px; }
        .carousel-control-next { right: -30px; }
        .carousel-control-prev:hover,
        .carousel-control-next:hover {
            opacity: .9;
        }
        .carousel-control-prev-icon,
        .carousel-control-next-icon {
            display: none;
        }
        .carousel-control-prev .fa,
        .carousel-control-next .fa {
            font-size: 2.5rem;
            color: #333;
            text-shadow: 1px 1px 3px rgba(255,255,255,0.6);
        }
        .dps-container .carousel-control-prev,
        .dps-container .carousel-control-next {
            opacity: 1;
            transition: opacity .15s ease;
        }
        .dps-container .carousel-control-prev:hover,
        .dps-container .carousel-control-next:hover {
            opacity: 0.8; 
        }
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0,0,0,0);
            border: 0;
        }
        .product.door img {
            width: 100%;
            height: auto;
        }
        .woocommerce-LoopProduct-link {
            position: relative;
        }
        .woocommerce-loop-product__title {
            position: absolute;
            top: -27px;
            right: -14px;
            z-index: 1;
            background-color: var(--wp--preset--color--primary);
            color: white;
            font-size: 1.2rem;
            font-weight: 700;
            padding: 5px 10px;
            border-radius: 5px;
            margin: 0;
            text-align: center;
            letter-spacing: 1px;
            box-shadow: 0px 0px 15px rgba(0, 0, 0, 0.2);
        }

        /* Info Container & Buttons */
        .info-container {
            margin-top: auto;
            padding-top: 1rem;
            margin-left: .25em;
            margin-right: .25em;
        }
        .dxf {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 1rem;
        }
        .btn.button, .btn.btn-primary.button {
            display: block;
            text-align: center;
            padding: 0.75rem;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            border-radius: 0.25rem;
            border: 1px solid var(--wp--preset--color--primary);
            background-color: var(--wp--preset--color--primary);
            color: white !important;
            transition: opacity 0.2s;
        }
        .btn.btn-primary.button {
            background-color: var(--wp--preset--color--primary);
            border-color: var(--wp--preset--color--primary);
        }
        .btn.button:hover {
            opacity: 0.85;
        }
        .btn.button.disabled {
            background-color: var(--dps-grey);
            border-color: var(--dps-grey);
            opacity: 0.7;
            cursor: not-allowed;
        }

        /* Thickness Table */
        .info h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            text-align: center;
            margin-top: 1.75rem;
        }
        .table.table-bordered {
            width: 100%;
            border: 1px solid var(--dps-border);
            border-collapse: separate;
            border-spacing: 0;
            border-radius: 5px;
            overflow: hidden;
            text-align: center;
            margin-bottom: 0;
            box-shadow: 0px 0px 15px rgba(0, 0, 0, 0.1);
        }
        .table.table-bordered th,
        .table.table-bordered td {
            border: none;
            padding: 0.5rem;
        }
        .table.table-bordered th {
            background-color: #f8f9fa;
            border-bottom: 1px solid var(--dps-border);
        }
        .table.table-bordered .fa-check {
            color: var(--wp--preset--color--primary);
            font-weight: 900;
        }
        .table.table-bordered .fa-times {
            color: #fab83e;
            font-weight: 900;
        }
        
        .table.table-bordered .fa-times {
            color: #fab83e;
            font-weight: 900;
        }

        /* --- NEW: Saddle Title Override --- */
        .product-saddle .woocommerce-loop-product__title {
            left: 50%;
            right: unset;
            bottom: 100%;
            transform: translateX(-50%) translateY(10px);
            width: 90%;
            box-sizing: border-box;
            height: fit-content;
            min-height: unset !important;
            line-height: 1.4;
            padding: 5px 10px;
            top: unset;
        }
        .col-md-4.product.door.product-saddle {
            margin-bottom: 80px;
        }
        .saddles-grid-row {
            margin-top: 100px; /* Adjust this value as needed */
        }
        
        /* --- NEW: Swiper.js Finishes Carousel Styles (Flow Effect) --- */

        /* 1. This is the /'Window/' */
        /* We are targeting the parent of the swiper div */
        .finishes-swiper-wrapper .woocommerce-LoopProduct-link {
            position: relative !important; /* Contain the slides */
            
            /* Give the window a fixed height so it clips */
            display: block; /* Ensure it's a block-level element */
        }

        /* 2. This is the Swiper container, inside the /'window/' */
        .finishes-swiper {
            overflow: hidden !important; /* This creates the 'window' */
            position: relative !important; /* This contains the slides */
            width: 100%;
            height: auto; /* Fill the /'window/' */
            cursor: grab;
        }
        .finishes-swiper:active {
            cursor: grabbing;
        }

        /* --- THIS IS THE SLIDE SIZING --- */
        .finishes-swiper .swiper-slide {
            width: 95%; 
            max-width: 95%;
        }
        
        .finishes-swiper .swiper-slide a.dps-lightbox-trigger {
            display: block; 
        }
        
        /* 4. This is for the slide images */
        .finishes-swiper .swiper-slide img {
            width: 100%;
            height: auto;
        }

        /* 5. Style the dots and arrows */
        .finishes-swiper .swiper-pagination-bullet-active {
            background-color: var(--wp--preset--color--primary) !important;
        }
        /*.finishes-swiper .swiper-button-prev,
        .finishes-swiper .swiper-button-next {
            color: var(--wp--preset--color--primary) !important;
            font-weight: 900;
        }*/
        .finishes-swiper-wrapper .woocommerce-loop-product__title {
            bottom: 100%;
            top: unset;
            transform: translateY(10px);
            height: fit-content;
            font-size: 3rem;
            padding: 7px 20px;
        }

        /* --- NEW: Wood Finish Carousel Styles --- */

        .wood-finish-swiper-wrapper {
            /* Reverted test constraints to let inline HTML sizing take over for desktop */
        }

        .wood-finish-swiper-wrapper .woocommerce-LoopProduct-link {
            position: relative !important;
            display: block;
        }

        .wood-finish-swiper {
            overflow: hidden !important;
            position: relative !important;
            width: 100%;
            height: auto;
            cursor: grab;
            perspective: 1200px; /* Helps enforce 3D on mobile */
        }
        .wood-finish-swiper:active {
            cursor: grabbing;
        }

        .wood-finish-swiper .swiper-slide {
            width: 80%; 
            max-width: 450px; /* RESTORED: Full size for Desktop */
            position: relative; 
            padding-bottom: 80px; 
        }

        /* --- NEW: Memory Crash Protection for Mobile / Landscape --- */
        /* Triggers if screen is narrower than a tablet OR very short (like a landscape phone) */
        @media (max-width: 950px), (max-height: 500px) {
            .wood-finish-swiper-wrapper {
                max-width: 600px !important; /* Constrain the overall wrapper */
                margin-left: auto !important;
                margin-right: auto !important;
            }
            .wood-finish-swiper .swiper-slide {
                max-width: 250px !important; /* Shrink slide slightly to prevent iPhone GPU crash */
            }
        }

        /* ADDED: This creates the actual reflection on the image's link wrapper */
        .wood-finish-swiper .swiper-slide a.dps-lightbox-trigger {
            display: block; 
            /* REVERTED: Reflection is always on */
            -webkit-box-reflect: below 1px -webkit-gradient(linear, left top, left bottom, from(transparent), color-stop(85%, transparent), to(rgba(255, 255, 255, 0.4)));
        }
        
        .wood-finish-swiper .swiper-slide img {
            width: 100%;
            height: auto;
            aspect-ratio: 1 / 1;
            border-radius: 10px;
        }

        /* Title inside the slide */
        .wood-finish-swiper .wood-finish-title {
            position: absolute;
            bottom: 10px;
            left: 10px;
            background-color: rgba(0, 0, 0, 0.6);
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 1rem;
            font-weight: 600;
            z-index: 10;
            display: none;
        }

        .wood-finish-swiper .swiper-pagination-bullet-active {
            background-color: var(--wp--preset--color--primary) !important;
        }
        
        .wood-finish-swiper-wrapper .woocommerce-loop-product__title {
            bottom: 100%;
            top: unset;
            transform: translateY(-30px);
            height: fit-content;
            font-size: 3rem;
            padding: 7px 20px;
        }
        /* --- UPDATED: 2D Thumbnails Slider Styles --- */
        .wood-finish-thumbs {
            width: 100%;
            height: 160px; /* INCREASED: Makes images much bigger */
            box-sizing: border-box;
            padding: 10px;
            margin-bottom: 55px; /* INCREASED: More space between the two carousels */
        }
        .wood-finish-thumbs.swiper {
            padding-right: 10px;
            padding-top: 10px;
            padding-bottom: 10px;
        }
        .wood-finish-thumbs .swiper-slide {
            width: auto; 
            height: 100%;
            opacity: 1; /* CHANGED: 1 means fully visible (no fade/blur) */
            cursor: pointer;
            position: relative;
            box-sizing: border-box;
        }

        .wood-finish-thumbs .swiper-slide img {
            width: 100% !important; 
            height: 100% !important; 
            object-fit: cover; 
            object-position: bottom center !important; 
            display: block;
            
            /* MOVED: Radius and Shadow are now here */
            border-radius: 4px;
            
            /* VISIBLE SHADOW: Slightly darker so it pops */
            box-shadow: 0px 5px 6px rgba(0,0,0,0.4) !important;
            
            /* Transition for the border effect */
            transition: border 0.2s ease;
            
            /* Ensures border doesn't resize image */
            box-sizing: border-box; 
        }

        /* Active State: Apply border directly to image */
        .wood-finish-thumbs .swiper-slide-thumb-active img {
            border: 2px solid var(--wp--preset--color--primary) !important;
        }
        
        /* Remove old border on the slide div if it existed */
        .wood-finish-thumbs .swiper-slide-thumb-active {
            border: none; 
        }

        /* Remove old border on the slide div if it existed */
        .wood-finish-thumbs .swiper-slide-thumb-active {
            border: none; 
        }

        /* --- THE FIX: Global overrides for the drawer notifications --- */
        body.starke-force-limited #starke-dxf-denial-msg { display: none !important; }
        body.starke-force-limited #starke-limited-access-msg { display: block !important; }
        
        body.starke-force-dxf #starke-limited-access-msg { display: none !important; }
        body.starke-force-dxf #starke-dxf-denial-msg { display: block !important; }
    ";
    
    // **THE FIX**: Removed the 'add_action' wrapper.
    // We echo the CSS directly. Since this function is called from inside
    // the shortcode (which is buffered), the <style> tag will be
    // correctly added to the page content.
    echo '<style type="text/css">' . $css . '</style>';

    // B. Inline JavaScript
    // **FIX**: The entire JS block is wrapped in nowdoc (EOT) to prevent
    // fatal errors from PHP trying to read the '$' characters.
    $js = <<<'EOT'
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DPS Gallery Tabs and Carousel Script Loaded');

        // --- (START) NEW: Open Login Drawer Trigger ---
        // This listens for clicks on our "DXF DOWNLOAD" button when the user is not logged in
        document.body.addEventListener('click', function(e) {
            var triggerEl = e.target.matches('.starke-trigger-login-drawer') ? e.target : e.target.closest('.starke-trigger-login-drawer');
            
            if (triggerEl) {
                e.preventDefault();
                
                // Grab the global limited status we passed from PHP
                const isAccountLimited = window.starke_door_details_data && window.starke_door_details_data.isAccountLimited;

                // Reset our custom classes
                document.body.classList.remove('starke-force-limited', 'starke-force-dxf');

                // Check if they clicked the DXF button using our NEW unique class
                if (triggerEl.classList.contains('dps-dxf-trigger')) {
                    if (isAccountLimited) {
                        document.body.classList.add('starke-force-limited'); // Force Limited via CSS
                    } else {
                        document.body.classList.add('starke-force-dxf'); // Force DXF via CSS
                    }
                } 

                // 1. Attempt to find the Header Login Icon/Link
                const headerLoginBtn = document.querySelector('header a[href*="my-account"]');

                if (headerLoginBtn) {
                    headerLoginBtn.click(); // Click it programmatically!
                } else {
                    window.location.href = '/my-account/'; 
                }
            }
        });
        
        // Clear the forces if the user manually clicks the header icon so it resets natively
        const mainHeaderLoginBtn = document.querySelector('header a[href*="my-account"]');
        if (mainHeaderLoginBtn) {
            mainHeaderLoginBtn.addEventListener('click', function(e) {
                if (e.isTrusted) { // True if it was a real human click, false if it was our script
                    document.body.classList.remove('starke-force-limited', 'starke-force-dxf');
                }
            });
        }
        // --- (END) NEW: Open Login Drawer Trigger ---

        // --- NEW: Dynamic Standard Carousel Initializer ---
        window.initStandardCarousels = function(container = document) {
            const carousels = container.querySelectorAll('.dps-container .carousel.slide:not(.js-initialized)');
            carousels.forEach(carousel => {
                carousel.classList.add('js-initialized');
                let currentIndex = 0;
                const items = carousel.querySelectorAll('.carousel-item');
                const totalItems = items.length;
                if (totalItems <= 1) return;

                const prevButton = carousel.querySelector('.carousel-control-prev');
                const nextButton = carousel.querySelector('.carousel-control-next');

                function showItem(index) {
                    items.forEach((item, i) => {
                        item.classList.remove('active');
                        if (i === index) item.classList.add('active');
                    });
                }

                if(prevButton) {
                    prevButton.addEventListener('click', function(e) {
                        e.preventDefault();
                        currentIndex = (currentIndex > 0) ? currentIndex - 1 : totalItems - 1;
                        showItem(currentIndex);
                    });
                }

                if(nextButton) {
                    nextButton.addEventListener('click', function(e) {
                        e.preventDefault();
                        currentIndex = (currentIndex < totalItems - 1) ? currentIndex + 1 : 0;
                        showItem(currentIndex);
                    });
                }
            });
        };

        // --- NEW: AJAX TAB SWITCHING LOGIC (Stale-While-Revalidate) ---
        const tabsContainer = document.querySelector('.gallery-tabs-container');
        if (tabsContainer) {
            tabsContainer.classList.add('js-loaded');
            const tabs = tabsContainer.querySelectorAll('.photo-tab');
            const contents = tabsContainer.querySelectorAll('.photo-tab-content');
            
            // NEW: Object to store exact scroll depths for each tab
            const tabScrollPositions = {};

            if ('scrollRestoration' in history) {
                history.scrollRestoration = 'manual';
            }

            let savedScrollPosition = sessionStorage.getItem('starkeScrollY');
            sessionStorage.removeItem('starkeScrollY'); 

            // --- THE FIX: Smart Navigation Detection ---
            // If the user arrived by clicking a link (like the main menu), ignore the saved scroll
            // Only keep it if they hit Refresh or used the browser's Back/Forward buttons
            if (window.performance) {
                const navEntries = performance.getEntriesByType('navigation');
                if (navEntries.length > 0 && navEntries[0].type === 'navigate') {
                    savedScrollPosition = null; 
                }
            }

            window.addEventListener('beforeunload', () => {
                sessionStorage.setItem('starkeScrollY', window.scrollY);
            });

            function switchTab(targetTabId, isInitialLoad = false) {
                if (!targetTabId) return;
                
                // 1. Identify current tab and save its scroll position before leaving
                let currentTabId = null;
                const activeTabEl = document.querySelector('.photo-tab.active');
                if (activeTabEl) {
                    currentTabId = activeTabEl.getAttribute('data-tab');
                }
                
                if (!isInitialLoad && currentTabId && currentTabId !== targetTabId) {
                    tabScrollPositions[currentTabId] = window.scrollY;
                }
                
                if (!isInitialLoad) {
                    tabsContainer.style.minHeight = tabsContainer.offsetHeight + 'px';
                }
                
                tabs.forEach(t => {
                    const isActive = t.getAttribute('data-tab') === targetTabId;
                    t.classList.toggle('active', isActive);
                    t.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });
                contents.forEach(c => {
                    c.classList.toggle('active', c.id === `content-for-${targetTabId}`);
                });

                if (window.location.hash !== `#${targetTabId}`) {
                    history.replaceState(null, null, `#${targetTabId}`);
                }

                // 2. Handle deterministic scrolling for the target tab
                if (!isInitialLoad) {
                    const tabsNav = document.getElementById('galleryTabButtons');
                    const tabsNavRect = tabsNav.getBoundingClientRect();
                    
                    const computedTop = window.getComputedStyle(tabsNav).top;
                    const exactStickyOffset = parseInt(computedTop, 10) || 120;
                    
                    const containerRect = tabsContainer.getBoundingClientRect();
                    const absoluteContainerTop = window.scrollY + containerRect.top;
                    
                    const nudge = 0; 
                    const stickySnapPosition = Math.max(0, absoluteContainerTop - exactStickyOffset + nudge);
                    const bottomOffsetTolerance = 75; 

                    if (tabScrollPositions[targetTabId] !== undefined) {
                        // --- SCENARIO 1: Returning to a previously visited tab ---
                        const savedScrollY = tabScrollPositions[targetTabId];
                        
                        // Project where the tabs WOULD be if we jumped to the saved scroll position
                        const projectedTabsTop = Math.max(exactStickyOffset, absoluteContainerTop - savedScrollY);
                        const projectedTabsBottom = projectedTabsTop + tabsNav.offsetHeight;
                        
                        if (projectedTabsBottom >= (window.innerHeight - bottomOffsetTolerance)) {
                            // The saved position is bad (tabs would be near bottom). Override and snap.
                            window.scrollTo({ top: stickySnapPosition, behavior: 'smooth' });
                            tabScrollPositions[targetTabId] = stickySnapPosition; 
                        } else {
                            // The saved position is safe. Instantly restore it.
                            window.scrollTo({ top: savedScrollY, behavior: 'instant' });
                        }
                    } else {
                        // --- SCENARIO 2: First time clicking this tab ---
                        // Check if the page is currently scrolled down enough that the tabs are in their sticky state.
                        // The tabs are sticky if the top of their container has scrolled up past the sticky offset.
                        const isTabsCurrentlySticky = containerRect.top < exactStickyOffset;
                        
                        if (isTabsCurrentlySticky) {
                            // Snap to the exact beginning of the sticky position
                            window.scrollTo({ top: stickySnapPosition, behavior: 'smooth' });
                            tabScrollPositions[targetTabId] = stickySnapPosition; 
                        }
                    }
                }

                const contentPane = document.querySelector(`#content-for-${targetTabId}`);
                if (contentPane) {
                    
                    if (contentPane.getAttribute('data-loaded') === 'false') {
                        contentPane.setAttribute('data-loaded', 'fetching');
                        
                        const cachedHTML = sessionStorage.getItem('dps_cache_' + targetTabId);
                        
                        if (cachedHTML) {
                            contentPane.innerHTML = cachedHTML;
                            tabsContainer.style.minHeight = '';
                            
                            if (isInitialLoad && savedScrollPosition) {
                                requestAnimationFrame(() => {
                                    window.scrollTo({ top: parseInt(savedScrollPosition, 10), behavior: 'instant' });
                                    savedScrollPosition = null; 
                                });
                            }
                            
                            if (targetTabId !== 'saddle-options' && targetTabId !== 'finish-options') {
                                const doorItems = contentPane.querySelectorAll('.product.door');
                                doorItems.forEach(item => {
                                    item.style.transition = 'none'; 
                                    item.classList.add('starke-fade-in'); 
                                });
                            }
                            
                            window.initStandardCarousels(contentPane);
                            
                            // --- THE BULLETPROOF FIX: Wait for the browser's exact paint cycle ---
                            requestAnimationFrame(() => {
                                requestAnimationFrame(() => {
                                    if (targetTabId === 'saddle-options' && typeof window.initSaddleSwiper === 'function') window.initSaddleSwiper();
                                    if (targetTabId === 'finish-options' && typeof window.initWoodSwipers === 'function') window.initWoodSwipers();
                                });
                            });
                        }

                        fetch(window.location.pathname, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                'starke_door_ajax': '1',
                                'tab_id': targetTabId
                            })
                        })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success && data.data.html) {
                                    const freshHTML = data.data.html;

                                    if (!cachedHTML || freshHTML !== cachedHTML) {
                                        contentPane.innerHTML = freshHTML;
                                        tabsContainer.style.minHeight = '';

                                        try { sessionStorage.setItem('dps_cache_' + targetTabId, freshHTML); } catch (e) {}

                                        if (!cachedHTML && isInitialLoad && savedScrollPosition) {
                                            requestAnimationFrame(() => {
                                                window.scrollTo({ top: parseInt(savedScrollPosition, 10), behavior: 'instant' });
                                                savedScrollPosition = null; 
                                            });
                                        }
                                        
                                        if (targetTabId === 'saddle-options') saddleSwiperInitialized = false;
                                        if (targetTabId === 'finish-options') woodSwipersInitialized = false;

                                        window.initStandardCarousels(contentPane);
                                        
                                        // --- THE BULLETPROOF FIX: Wait for the browser's exact paint cycle ---
                                        requestAnimationFrame(() => {
                                            requestAnimationFrame(() => {
                                                if (targetTabId === 'saddle-options' && typeof window.initSaddleSwiper === 'function') window.initSaddleSwiper();
                                                if (targetTabId === 'finish-options' && typeof window.initWoodSwipers === 'function') window.initWoodSwipers();
                                            });
                                        });

                                        if (targetTabId !== 'saddle-options' && targetTabId !== 'finish-options') {
                                            const doorItems = contentPane.querySelectorAll('.product.door');
                                            doorItems.forEach((item, index) => {
                                                item.style.transition = ''; 
                                                setTimeout(() => { item.classList.add('starke-fade-in'); }, index * 75);
                                            });
                                        }
                                    }
                                    
                                    contentPane.setAttribute('data-loaded', 'true');
                                    
                                } else if (!cachedHTML) {
                                    contentPane.innerHTML = '<p>Error loading content.</p>';
                                    tabsContainer.style.minHeight = ''; 
                                }
                            })
                            .catch(err => {
                                console.error('AJAX Error:', err);
                                if (!cachedHTML) {
                                    contentPane.innerHTML = '<p>Error loading content.</p>';
                                    tabsContainer.style.minHeight = ''; 
                                }
                            });

                    } else if (contentPane.getAttribute('data-loaded') === 'true') {
                        tabsContainer.style.minHeight = '';
                        
                        if (isInitialLoad && savedScrollPosition) {
                            requestAnimationFrame(() => {
                                window.scrollTo({ top: parseInt(savedScrollPosition, 10), behavior: 'instant' });
                                savedScrollPosition = null;
                            });
                        }
                        
                        if (targetTabId === 'saddle-options' && typeof window.initSaddleSwiper === 'function') window.initSaddleSwiper();
                        if (targetTabId === 'finish-options' && typeof window.initWoodSwipers === 'function') window.initWoodSwipers();
                    }
                }
            }

            tabs.forEach(tab => {
                function handleTabClick(e) {
                    e.preventDefault();
                    switchTab(tab.getAttribute('data-tab'), false); 
                }
                tab.addEventListener('click', handleTabClick);
                tab.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') handleTabClick(e);
                });
            });

            window.addEventListener('hashchange', function() {
                const newHash = window.location.hash.substring(1);
                const targetTab = document.querySelector(`.photo-tab[data-tab="${newHash}"]`);
                if (newHash && targetTab) switchTab(newHash, false); 
            });

            const currentHash = window.location.hash.substring(1);
            const targetTab = document.querySelector(`.photo-tab[data-tab="${currentHash}"]`);
            if (currentHash && targetTab) {
                switchTab(currentHash, true); 
            } else {
                const defaultActiveTab = document.querySelector('.photo-tab.active');
                if (defaultActiveTab) switchTab(defaultActiveTab.getAttribute('data-tab'), true); 
            }
        }

        // --- (START) NEW LIGHTBOX LOGIC WITH ZOOM & PAN ---
        const lightbox = document.getElementById('starke-lightbox');
        const lightboxImage = document.getElementById('lightbox-image');
        const closeBtn = document.querySelector('.starke-lightbox-close');
        const prevBtn = document.querySelector('.starke-lightbox-prev');
        const nextBtn = document.querySelector('.starke-lightbox-next');
        const mainContainer = document.querySelector('.dps-container');

        let currentGalleryLinks = [];
        let currentImageIndex = 0;

        // --- Zoom & Pan State Variables ---
        let currentScale = 1;
        let isDragging = false;
        let startX = 0, startY = 0;
        let translateX = 0, translateY = 0;
        let initialDistance = null; // For touch pinch
        let lastTap = 0; // For double-tap
        let lastZoomTime = 0; // NEW: Tracks when a zoom last occurred

        if (!lightbox || !lightboxImage || !closeBtn || !prevBtn || !nextBtn || !mainContainer) {
            console.error('DPS Lightbox elements not found. Lightbox will be disabled.');
        } else {

            // --- Zoom Helper Functions ---
            const updateTransform = (smooth = false) => {
                lightboxImage.style.transition = smooth ? 'transform 0.3s ease-out' : 'none';
                lightboxImage.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentScale})`;
                lightboxImage.style.cursor = currentScale > 1 ? (isDragging ? 'grabbing' : 'grab') : 'zoom-in';
            };

            const resetZoom = () => {
                currentScale = 1;
                translateX = 0;
                translateY = 0;
                updateTransform(false);
            };

            const updateLightboxImage = () => {
                if (currentGalleryLinks.length === 0) return;
                const linkElement = currentGalleryLinks[currentImageIndex];
                const fullSizeUrl = linkElement.href;
                if (fullSizeUrl) {
                    resetZoom(); // Reset zoom when changing images
                    lightboxImage.src = fullSizeUrl;
                }
            };

            const openLightbox = (clickedLink) => {
                const carousel = clickedLink.closest('.carousel.slide');
                if (!carousel) return;
                
                currentGalleryLinks = Array.from(carousel.querySelectorAll('.dps-lightbox-trigger'));
                currentImageIndex = currentGalleryLinks.findIndex(a => a === clickedLink);
                
                if (currentImageIndex !== -1) {
                    updateLightboxImage();
                    lightbox.style.display = 'flex';
                    lightboxImage.style.cursor = 'zoom-in';

                    if (currentGalleryLinks.length > 1) {
                        prevBtn.style.display = 'block';
                        nextBtn.style.display = 'block';
                    } else {
                        prevBtn.style.display = 'none';
                        nextBtn.style.display = 'none';
                    }
                }
            };

            const closeLightbox = () => {
                lightbox.style.display = 'none';
                lightboxImage.src = ''; 
                currentGalleryLinks = [];
                currentImageIndex = 0;
                resetZoom();
                prevBtn.style.display = 'block';
                nextBtn.style.display = 'block';
            };
            
            const showNext = () => {
                currentImageIndex = (currentImageIndex + 1) % currentGalleryLinks.length;
                updateLightboxImage();
            };
            
            const showPrev = () => {
                currentImageIndex = (currentImageIndex - 1 + currentGalleryLinks.length) % currentGalleryLinks.length;
                updateLightboxImage();
            };

            mainContainer.addEventListener('click', function(e) {
                const clickedLink = e.target.closest('.dps-lightbox-trigger');
                if (clickedLink) {
                    e.preventDefault(); 
                    openLightbox(clickedLink);
                }
            });

            closeBtn.addEventListener('click', closeLightbox);
            prevBtn.addEventListener('click', showPrev);
            nextBtn.addEventListener('click', showNext);
            
            lightbox.addEventListener('click', (e) => {
                if (e.target === lightbox) closeLightbox();
            });

            // --- Mouse Drag Panning & Double Click Zoom ---
            lightboxImage.addEventListener('click', (e) => {
                const now = Date.now();
                if (now - lastTap < 300) { // Double Tap / Click
                    if (currentScale > 1) {
                        resetZoom();
                    } else {
                        currentScale = 2.5; // Zoom in level
                        updateTransform(true);
                    }
                }
                lastTap = now;
            });

            lightboxImage.addEventListener('mousedown', (e) => {
                if (currentScale > 1) {
                    e.preventDefault();
                    isDragging = true;
                    startX = e.clientX - translateX;
                    startY = e.clientY - translateY;
                    updateTransform();
                }
            });

            window.addEventListener('mousemove', (e) => {
                if (isDragging && currentScale > 1) {
                    translateX = e.clientX - startX;
                    translateY = e.clientY - startY;
                    updateTransform();
                }
            });

            window.addEventListener('mouseup', () => { 
                if (isDragging) {
                    isDragging = false; 
                    updateTransform();
                }
            });
            
            document.addEventListener('keydown', (e) => {
                if (lightbox.style.display === 'flex') {
                    if (e.key === 'ArrowRight') showNext();
                    if (e.key === 'ArrowLeft') showPrev();
                    if (e.key === 'Escape') closeLightbox();
                }
            });

            // --- SCROLL, PINCH, AND PAN LOGIC ---
            let isScrolling = false;
            let scrollTimeout;

            lightbox.addEventListener('wheel', (e) => {
                if (lightbox.style.display === 'flex') {
                    e.preventDefault(); 

                    // Trackpad Pinch-to-Zoom
                    if (e.ctrlKey) {
                        currentScale -= e.deltaY * 0.015;
                        currentScale = Math.min(Math.max(1, currentScale), 5);
                        updateTransform();
                        lastZoomTime = Date.now(); // Log the exact moment of the zoom
                        return;
                    }

                    // If zoomed in, allow panning
                    if (currentScale > 1) {
                        translateX -= e.deltaX;
                        translateY -= e.deltaY;
                        updateTransform();
                        return;
                    }

                    // Standard swipe/scroll to navigate
                    if (currentGalleryLinks.length > 1) {
                        if (Math.abs(e.deltaY) < 15 && Math.abs(e.deltaX) < 15) return;
                        
                        // NEW: Ignore scroll navigation if we zoomed within the last 400ms
                        if (Date.now() - lastZoomTime < 400) return; 

                        if (!isScrolling) {
                            if (e.deltaY > 0 || e.deltaX > 0) showNext();
                            else if (e.deltaY < 0 || e.deltaX < 0) showPrev();
                            isScrolling = true; 
                        }

                        clearTimeout(scrollTimeout);
                        scrollTimeout = setTimeout(() => { isScrolling = false; }, 100); 
                    }
                }
            }, { passive: false });

            // --- TOUCH LOGIC (Pinch, Pan, Swipe) ---
            let touchStartX = 0, touchStartY = 0, touchEndX = 0, touchEndY = 0;

            lightbox.addEventListener('touchstart', (e) => {
                if (lightbox.style.display === 'flex') {
                    if (e.touches.length === 2) {
                        // Pinch Start
                        initialDistance = Math.hypot(
                            e.touches[0].clientX - e.touches[1].clientX,
                            e.touches[0].clientY - e.touches[1].clientY
                        );
                    } else if (e.touches.length === 1) {
                        // Swipe or Pan Start
                        touchStartX = e.touches[0].clientX;
                        touchStartY = e.touches[0].clientY;
                        if (currentScale > 1) {
                            isDragging = true;
                            startX = touchStartX - translateX;
                            startY = touchStartY - translateY;
                        }
                    }
                }
            }, { passive: false });

            lightbox.addEventListener('touchmove', (e) => {
                if (lightbox.style.display === 'flex') {
                    e.preventDefault(); 

                    if (e.touches.length === 2) {
                        // Pinch Move
                        const currentDistance = Math.hypot(
                            e.touches[0].clientX - e.touches[1].clientX,
                            e.touches[0].clientY - e.touches[1].clientY
                        );
                        if (initialDistance) {
                            const scaleChange = currentDistance / initialDistance;
                            currentScale = Math.min(Math.max(1, currentScale * scaleChange), 5);
                            updateTransform();
                            initialDistance = currentDistance; 
                            lastZoomTime = Date.now(); // Log the exact moment of the zoom
                        }
                    } else if (e.touches.length === 1 && currentScale > 1 && isDragging) {
                        // Pan Move
                        translateX = e.touches[0].clientX - startX;
                        translateY = e.touches[0].clientY - startY;
                        updateTransform();
                    }
                }
            }, { passive: false });

            lightbox.addEventListener('touchend', (e) => {
                if (lightbox.style.display === 'flex') {
                    initialDistance = null;
                    isDragging = false;
                    
                    if (currentScale < 1) resetZoom(); 
                    
                    // Swipe to Navigate (Only if NOT zoomed in)
                    if (currentScale === 1 && e.changedTouches.length === 1 && currentGalleryLinks.length > 1) {
                        
                        // NEW: Ignore swipe navigation if we zoomed within the last 400ms
                        if (Date.now() - lastZoomTime < 400) return;

                        touchEndX = e.changedTouches[0].clientX;
                        touchEndY = e.changedTouches[0].clientY;
                        
                        let diffX = touchStartX - touchEndX;
                        let diffY = touchStartY - touchEndY;

                        if (Math.abs(diffX) > Math.abs(diffY)) {
                            if (diffX > 30) showNext(); 
                            else if (diffX < -30) showPrev(); 
                        } else {
                            if (diffY > 30) showNext(); 
                            else if (diffY < -30) showPrev(); 
                        }
                    }
                }
            }, { passive: false });
            
        }
        // --- (END) NEW LIGHTBOX LOGIC WITH ZOOM & PAN ---


        // --- (START) NEW SWIPER.JS LOGIC & ARCHITECTURE ---
        
        // Track if we've already built them so we don't rebuild on every tab click
        let saddleSwiperInitialized = false;
        let woodSwipersInitialized = false;

        // 1. Function strictly for the Saddle Tab's Swiper
        window.initSaddleSwiper = function() {
            if (saddleSwiperInitialized) return;

            const finishesSwiper = new Swiper('.finishes-swiper', {
                effect: 'coverflow',
                grabCursor: true, 
                loop: true,
                speed: 800,
                centeredSlides: true,
                slidesPerView: 'auto',
                mousewheel: true,
                // --- MEMORY FIX: Drastically reduced clones ---
                loopedSlides: 2, 
                loopAdditionalSlides: 1,
                watchSlidesProgress: true, // Stops math for off-screen elements
                
                autoHeight: true,
                observer: true,
                observeParents: true,
                spaceBetween: -15,
                coverflowEffect: {
                    rotate: 30, stretch: 0, depth: 300, modifier: 1, scale: 0.85, slideShadows: false,
                },
                on: {
                    afterInit: function (swiper) {
                        requestAnimationFrame(() => {
                            swiper.autoplay.start();
                        });
                    },
                },
                autoplay: {
                    delay: 3000,
                    disableOnInteraction: false,
                },
                pagination: {
                    el: '.swiper-pagination', clickable: true,
                },
            });

            saddleSwiperInitialized = true;
        };

        // 2. Function strictly for the Wood Finishes Tab's Swipers
        window.initWoodSwipers = function() {
            if (woodSwipersInitialized) return; 

            // Shared Config for the 3D Wood Finish Main Sliders
            const woodFinishMainConfig = {
                effect: 'coverflow',
                grabCursor: true, 
                centeredSlides: true,
                slidesPerView: 'auto',
                mousewheel: true,
                loop: true, 
                // --- MEMORY FIX: Drastically reduced clones ---
                loopedSlides: 8, 
                loopAdditionalSlides: 4,
                autoHeight: true,
                observer: true,
                observeParents: true,
                speed: 1200, 
                spaceBetween: -10, 
                coverflowEffect: {
                    rotate: 20, stretch: 0, depth: 400, modifier: 1, scale: 0.9, slideShadows: false,
                },
                autoplay: {
                    delay: 3000, disableOnInteraction: false,
                },
                // --- THE FIX: Force mobile browsers to wake up the autoplay ---
                on: {
                    init: function () {
                        const swiper = this;
                        // Give the browser 100ms to paint the tab layout, 
                        // then explicitly command the autoplay to start
                        setTimeout(function () {
                            swiper.update();
                            if (swiper.autoplay) {
                                swiper.autoplay.start();
                            }
                        }, 100);
                    }
                }
            };

            function initSyncedSwipers(name) {
                const thumbSwiper = new Swiper('.' + name + '-finishes-swiper-thumbs', {
                    spaceBetween: 10,
                    slidesPerView: 'auto',
                    mousewheel: true,
                    freeMode: true,
                    watchSlidesProgress: true,
                    observer: true,
                    observeParents: true,
                    speed: 1200,
                    centeredSlides: false, 
                    slideToClickedSlide: true,
                });

                const mainConfig = Object.assign({}, woodFinishMainConfig, {
                    thumbs: {
                        swiper: thumbSwiper,
                    }
                });

                new Swiper('.' + name + '-finishes-swiper-main', mainConfig);
            }

            initSyncedSwipers('poplar');
            initSyncedSwipers('walnut');
            initSyncedSwipers('white-oak');
            initSyncedSwipers('mahogany');

            woodSwipersInitialized = true;
        };
        // --- (END) NEW SWIPER.JS LOGIC & ARCHITECTURE ---


        
    });
EOT;
    // Note: The 'EOT;' above MUST be on its own line with no spaces
    
    // **FIX**: This is the original, safe way to add JS to the footer.
    // The complex 'add_action' wrapper I tried was causing a fatal error.
    add_action('wp_footer', function() use ($js) {
        // *** THE FIX (PART 2) ***
        // Echo the lightbox HTML here, so it's at the end of the <body>
        // and 'position: fixed' works correctly.
        echo '
        <div id="starke-lightbox" class="starke-lightbox">
            <span class="starke-lightbox-close">&times;</span>
            <img class="starke-lightbox-content" id="lightbox-image">
            <a class="starke-lightbox-prev">&#10094;</a>
            <a class="starke-lightbox-next">&#10095;</a>
        </div>
        ';

        echo '<script type' . '="text/javascript">' . $js . '</script>';
    }, 99);
}

/**
 * 5. Handle secure file downloads
 * This function checks for a 'download_file' request, verifies the nonce,
 * and forces a secure download.
 */
add_action('template_redirect', 'dps_handle_file_download');
function dps_handle_file_download() {
    // Check if this is a download request
    if ( isset($_GET['download_file']) && isset($_GET['_wpnonce']) ) {
        
        $file_id = intval($_GET['download_file']);
        // Verify Nonce
        $nonce_valid = wp_verify_nonce($_GET['_wpnonce'], 'pdf_download_nonce') || wp_verify_nonce($_GET['_wpnonce'], 'dxf_download_nonce');

        if (!$file_id || !$nonce_valid) {
            wp_die('Invalid or expired download link.', 'Security Error', array('response' => 403));
        }

        // Get the attachment URL (WP Offload Media returns the CloudFront URL here)
        $attachment_url = wp_get_attachment_url($file_id);
        $extension = strtolower(pathinfo(parse_url($attachment_url, PHP_URL_PATH), PATHINFO_EXTENSION));

        // ========================================================
        // --- NEW: CLOUDFRONT SIGNED URL LOGIC FOR DXF FILES ---
        // ========================================================
        if ( $extension === 'dxf' ) {
            // 1. Must be logged in
            if ( ! is_user_logged_in() ) {
                wp_die('Access denied. Please login.', 'Access Denied', ['response' => 403]);
            }
            
            // 2. Must be Architect or Impersonating
            $is_architect = function_exists('starke_has_architect_access') && starke_has_architect_access();
            $is_impersonating = function_exists('impersonation_is_active') && impersonation_is_active();

            if ( ! $is_architect && ! $is_impersonating ) {
                wp_die('Access Denied. You need Architect Access to download this file.', 'Access Denied', ['response' => 403]);
            }

            if (!$attachment_url) {
                wp_die('Download URL could not be generated.', 'File Not Found', ['response' => 404]);
            }

            // 3. Generate Signed CloudFront URL
            try {
                // Using the fully qualified namespace so we don't need a 'use' statement at the top
                $cloudFrontClient = new \Aws\CloudFront\CloudFrontClient([
                    'profile' => 'default',
                    'version' => '2014-11-06',
                    'region'  => 'us-east-1'
                ]);

                $expires = time() + 150; // URL dies in exactly 2.5 minutes
                $keyPairId = 'K3A5DCXG206IB0'; 
                $privateKeyPath = '/var/app/current/.keys/dxf_download_private_key.pem';

                $signedUrl = $cloudFrontClient->getSignedUrl([
                    'url'         => $attachment_url,
                    'expires'     => $expires,
                    'private_key' => $privateKeyPath,
                    'key_pair_id' => $keyPairId
                ]);

                // Redirect the user directly to the secure CloudFront URL
                wp_redirect($signedUrl);
                exit;

            } catch (Exception $e) {
                if ( function_exists( 'wc_get_logger' ) ) {
                    $logger = wc_get_logger();
                    $logger->error( 'AWS SDK Error (Doors): ' . $e->getMessage(), array( 'source' => 'starke-dxf-security' ) );
                }
                wp_die('A secure connection to the file could not be established.', 'Security Error', ['response' => 500]);
            }
        }
        // ========================================================
        // --- END CLOUDFRONT LOGIC ---
        // ========================================================

        // --- FALLBACK FOR PDFS (Local Server Streaming) ---
        $file_path = get_attached_file($file_id);
        
        // This is important: Since WP Offload Media moves DXFs off the server, 
        // the old file_exists() check was breaking. We only check for PDFs now.
        if (!file_exists($file_path)) {
            wp_die('File not found.', 'Error', array('response' => 404));
        }

        // Serve File
        $file_name = basename($file_path);
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file_name . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));
        
        if (ob_get_length()) ob_clean();
        flush();
        readfile($file_path);
        exit;
    }
}