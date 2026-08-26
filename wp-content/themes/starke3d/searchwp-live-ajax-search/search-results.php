<?php
/**
 * Custom SearchWP Live Ajax Search Results Template
 * Theme: Starke Midnight
 * Logic: "Loop First" + "Safe Deep Linking" + "Contextual Parents"
 * Updated: Deep Links now ONLY trigger if matching products are actually found.
 */

// --- CONFIGURATION: DISPLAY LIMITS ---
$limit_molding = 5; 
$limit_doors   = 5;
$limit_other   = 3;

// --- CONFIGURATION: PAGE IDs ---
$shop_page_id    = 25;   // Shop Molding Page
$door_page_id    = 9106; // Door Options Page
$contact_page_id = 8030; // Contact Us Page

// --- CONFIGURATION: VIP LISTS ---
// IDs of pages to always feature if found or forced via "Profile"
$vip_page_ids = [ $shop_page_id, $door_page_id ]; 

// IDs of pages to show when NO results are found
$fallback_page_ids = [ $shop_page_id, $door_page_id, $contact_page_id ];

// =============================================================================
// CONFIGURATION: DEEP LINKING MAPS (CUSTOM TITLES)
// =============================================================================

// MAP 1: MOLDING LINKS (Targets Shop Page ID 25)
// Format: 'keyword' => [ 'url' => '...', 'title' => '...' ]
$molding_link_map = [
    'baseboards'       => [ 'url' => '?_molding_category=baseboards',       'title' => 'Shop Baseboard Molding' ],
    'casings'          => [ 'url' => '?_molding_category=casings',          'title' => 'Shop Casing Molding' ],
    'crowns'           => [ 'url' => '?_molding_category=crowns',           'title' => 'Shop Crown Molding' ],
    'miscellaneous'    => [ 'url' => '?_molding_category=miscellaneous',    'title' => 'Shop Miscellaneous Molding' ],
    'applied sticking' => [ 'url' => '?_molding_type=applied-sticking',     'title' => 'Shop Applied Sticking' ],
    'bolection'        => [ 'url' => '?_molding_type=bolection',            'title' => 'Shop Bolection Molding' ],
    'sills'            => [ 'url' => '?_molding_type=sills',                'title' => 'Shop Sills Molding' ],
    'art deco'         => [ 'url' => '?_molding_style=art-deco',            'title' => 'Shop Art Deco Molding' ],
    'colonial'         => [ 'url' => '?_molding_style=colonial',            'title' => 'Shop Colonial Molding' ],
    'contemporary'     => [ 'url' => '?_molding_style=contemporary',        'title' => 'Shop Contemporary Molding' ],
    'craftsman'        => [ 'url' => '?_molding_style=craftsman',           'title' => 'Shop Craftsman Molding' ],
    'traditional'      => [ 'url' => '?_molding_style=traditional',         'title' => 'Shop Traditional Molding' ],
    'victorian'        => [ 'url' => '?_molding_style=victorian',           'title' => 'Shop Victorian Molding' ],
];

// MAP 2: DOOR LINKS (Targets Door Page ID 9106)
// Customized titles as requested (Profiles vs Options)
$door_link_map = [
    'sticking' => [ 'url' => '#sticking',        'title' => 'Door Sticking Profiles' ],
    'panel'    => [ 'url' => '#panel',           'title' => 'Door Panel Profiles' ],
    'groove'   => [ 'url' => '#groove',          'title' => 'Door Groove Profiles' ],
    'finish'   => [ 'url' => '#finish-options',  'title' => 'Door Finish Options' ],
    'saddle'   => [ 'url' => '#saddle-options',  'title' => 'Door Saddle Options' ],
    'color'    => [ 'url' => '#finish-options',  'title' => 'Door Finish Options' ],
];

// Initialize buckets
$featured_results = [];
$molding_results  = [];
$door_results     = [];
$other_results    = [];
$seen_ids         = []; 

// Get query safely
$search_query = isset( $_REQUEST['s'] ) ? strtolower( trim( $_REQUEST['s'] ) ) : '';
$query_len    = strlen( $search_query );


// =============================================================================
// STEP 1: STANDARD LOOP (Run First to Check Inventory)
// =============================================================================
if ( have_posts() ) :
	while ( have_posts() ) : the_post();
		$post_type = get_post_type();
        $post_id   = get_the_ID();
        $obj       = get_post( $post_id );

        if ( in_array( $post_id, $seen_ids ) ) { continue; }

        // A. CATCH VIPs
        if ( in_array( $post_id, $vip_page_ids ) ) {
            $featured_results[] = $obj;
            $seen_ids[] = $post_id;
            continue;
        }

        // B. SORT PRODUCTS
		if ( 'door' === $post_type || 'saddle' === $post_type ) { 
            if ( count( $door_results ) < $limit_doors ) {
                
                // --- STARKE AUTOMATION FIX: DYNAMIC TAXONOMY ROUTING ---
                // Default fallback anchor just in case a door doesn't have a type assigned
                $target_anchor = '#sticking'; 

                // Pull all assigned terms from your custom 'door_type' taxonomy for this door post
                $assigned_types = wp_get_post_terms( $obj->ID, 'door_type', array( 'fields' => 'slugs' ) );

                if ( ! empty( $assigned_types ) && ! is_wp_error( $assigned_types ) ) {
                    // Match against your exact structural hash targets: #sticking, #panel, or #groove
                    // Grabs the first assigned taxonomy slug dynamically
                    $target_anchor = '#' . $assigned_types[0];
                }
                // Handle your custom 'saddle' post type routing seamlessly if found here
                elseif ( 'saddle' === $post_type ) {
                    $target_anchor = '#saddle-options';
                }

                // Append the resolved category hash anchor to your Door Options Page URL
                $obj->custom_permalink = get_permalink( $door_page_id ) . $target_anchor;
                // --- END FIX ---

			    $door_results[] = $obj;
            }
		} 
        elseif ( 'product' === $post_type ) {
            if ( count( $molding_results ) < $limit_molding ) {
			    $molding_results[] = $obj;
            }
		} 
        else {
            if ( count( $other_results ) < $limit_other ) {
			    $other_results[] = $obj;
            }
		}
        
        // C. STOP IF FULL
        if ( count($door_results) >= $limit_doors && 
             count($molding_results) >= $limit_molding && 
             count($other_results) >= $limit_other ) {
            break; 
        }
	endwhile;
endif;


// =============================================================================
// STEP 2: DEEP LINK CHECKS (SMARTER "STATIC vs DYNAMIC" LOGIC)
// =============================================================================

// CHECK 1: MOLDING DEEP LINKS
foreach ( $molding_link_map as $keyword => $data ) {
    $is_exact   = ( strpos( $search_query, $keyword ) !== false );
    $is_partial = ( $query_len >= 1 && strpos( $keyword, $search_query ) === 0 );

    // LOGIC: Check if it's an Anchor Link (#) or a Filter Link (?)
    $is_anchor      = ( strpos( $data['url'], '#' ) !== false );
    $found_products = ! empty( $molding_results );

    // If it's an Anchor (#), show it even if no products found. 
    // If it's a Filter (?), only show if products exist.
    if ( ($is_exact || $is_partial) && ( $is_anchor || $found_products ) ) {
        
        $shop_post = get_post( $shop_page_id );
        if ( $shop_post ) {
            $shop_post_clone = clone $shop_post;
            
            // USE CUSTOM DATA FROM MAP
            $shop_post_clone->custom_permalink = get_permalink( $shop_page_id ) . $data['url'];
            $shop_post_clone->post_title       = $data['title']; 

            // If Shop Page is already in results, replace it. Otherwise, add to top.
            if ( in_array( $shop_page_id, $seen_ids ) ) {
                foreach ( $featured_results as $key => $p_obj ) {
                    if ( $p_obj->ID == $shop_page_id ) {
                        $featured_results[$key] = $shop_post_clone;
                        break; 
                    }
                }
            } else {
                array_unshift( $featured_results, $shop_post_clone );
                $seen_ids[] = $shop_page_id; 
            }
        }
        break; 
    }
}

// CHECK 2: DOOR DEEP LINKS
foreach ( $door_link_map as $keyword => $data ) {
    $is_exact   = ( strpos( $search_query, $keyword ) !== false );
    $is_partial = ( $query_len >= 1 && strpos( $keyword, $search_query ) === 0 );

    // LOGIC: Check if it's an Anchor Link (#) or a Filter Link (?)
    // "Finish" uses '#finish-options', so $is_anchor will be TRUE.
    $is_anchor      = ( strpos( $data['url'], '#' ) !== false );
    $found_products = ! empty( $door_results );

    // This ensures "Finish" shows up (because it's an anchor) 
    // while protecting filters from showing empty results.
    if ( ($is_exact || $is_partial) && ( $is_anchor || $found_products ) ) {
        
        $door_post = get_post( $door_page_id );
        if ( $door_post ) {
            $door_post_clone = clone $door_post;
            
            // USE CUSTOM DATA FROM MAP
            $door_post_clone->custom_permalink = get_permalink( $door_page_id ) . $data['url'];
            $door_post_clone->post_title       = $data['title'];

            // If Door Page is already in results, replace it. Otherwise, add to top.
            if ( in_array( $door_page_id, $seen_ids ) ) {
                foreach ( $featured_results as $key => $p_obj ) {
                    if ( $p_obj->ID == $door_page_id ) {
                        $featured_results[$key] = $door_post_clone;
                        break; 
                    }
                }
            } else {
                $featured_results[] = $door_post_clone;
                $seen_ids[] = $door_page_id; 
            }
        }
        break; 
    }
}


// =============================================================================
// STEP 3: FORCE INJECT "PROFILE" & "DOOR"
// =============================================================================

// A. "Profile" -> Force Shop & Door Page (if not already seen)
// Logic: Checks for "profile" (exact) OR "p"/"pr"/"pro" (partial start)
$p_keyword = 'profile';
$p_exact   = ( strpos( $search_query, $p_keyword ) !== false );
// CHANGE: Lowered limit from 3 to 1
$p_partial = ( $query_len >= 1 && strpos( $p_keyword, $search_query ) === 0 );

if ( $p_exact || $p_partial ) {
    foreach ( $vip_page_ids as $f_id ) {
        if ( ! in_array( $f_id, $seen_ids ) ) {
            $f_post = get_post( $f_id );
            if ( $f_post ) {
                $featured_results[] = $f_post;
                $seen_ids[] = $f_id; 
            }
        }
    }
}

// B. "Door" -> Force Door Page (if not already seen)
// CHANGE: Added partial match for "Door" as well (so 'd' triggers it)
$d_keyword = 'door';
$d_exact   = ( strpos( $search_query, $d_keyword ) !== false );
$d_partial = ( $query_len >= 1 && strpos( $d_keyword, $search_query ) === 0 );

if ( ($d_exact || $d_partial) && ! in_array( $door_page_id, $seen_ids ) ) {
    $d_post = get_post( $door_page_id );
    if ( $d_post ) {
        $featured_results[] = $d_post;
        $seen_ids[] = $door_page_id;
    }
}


// =============================================================================
// STEP 4: CONTEXTUAL PARENT SUGGESTIONS (Fallback)
// =============================================================================

// A. Found Molding? -> Suggest Shop Page (if not shown)
if ( ! empty( $molding_results ) && ! in_array( $shop_page_id, $seen_ids ) ) {
    $shop_post = get_post( $shop_page_id );
    if ( $shop_post ) {
        array_unshift( $other_results, $shop_post ); 
        $seen_ids[] = $shop_page_id;
    }
}

// B. Found Doors? -> Suggest Door Page (if not shown)
if ( ! empty( $door_results ) && ! in_array( $door_page_id, $seen_ids ) ) {
    $d_post = get_post( $door_page_id );
    if ( $d_post ) {
        array_unshift( $other_results, $d_post ); 
        $seen_ids[] = $door_page_id;
    }
}


// =============================================================================
// RENDER FUNCTION
// =============================================================================
function starke_render_row( $post_obj, $type_class ) {
    // 1. Link Logic (Checks for custom deep link)
    if ( isset( $post_obj->custom_permalink ) ) {
        $link = esc_url( $post_obj->custom_permalink );
    } else {
        $link = esc_url( get_permalink( $post_obj->ID ) );
    }
    
    // 2. Title Logic (Checks for custom title override)
    $title = esc_html( $post_obj->post_title );
    
    $img_html = '';
    if ( has_post_thumbnail( $post_obj->ID ) ) {
        $img_html = get_the_post_thumbnail( $post_obj->ID, 'thumbnail', array( 'class' => 'starke-thumb' ) );
    } else {
        $img_html = '<div class="starke-thumb-placeholder"></div>';
    }
    ?>
    <div class="searchwp-live-search-result <?php echo esc_attr($type_class); ?>" 
         onclick="window.location.href='<?php echo $link; ?>';">
        <div class="starke-result-img">
            <?php echo $img_html; ?>
        </div>
        <div class="starke-result-info">
            <p class="starke-result-title"><?php echo $title; ?></p>
        </div>
    </div>
    <?php
}
?>

<div class="starke-search-container">

    <?php if ( ! empty( $featured_results ) ) : ?>
        <div class="starke-search-header featured-header">
            <span><i class="fa fa-star" style="margin-right:5px;"></i> FEATURED</span>
        </div>
        <div class="starke-group featured-group">
            <?php foreach ( $featured_results as $post ) { starke_render_row($post, 'type-featured'); } ?>
        </div>
    <?php endif; ?>

    <?php if ( ! empty( $door_results ) ) : ?>
        <div class="starke-search-header"><span>DOOR DETAILS</span></div>
        <div class="starke-group door-group">
            <?php foreach ( $door_results as $post ) { starke_render_row($post, 'type-door'); } ?>
        </div>
    <?php endif; ?>

    <?php if ( ! empty( $molding_results ) ) : ?>
        <div class="starke-search-header"><span>MOLDING PROFILES</span></div>
        <div class="starke-group molding-group">
            <?php foreach ( $molding_results as $post ) { starke_render_row($post, 'type-molding'); } ?>
        </div>
    <?php endif; ?>

    <?php if ( ! empty( $other_results ) ) : ?>
        <div class="starke-search-header"><span>OTHER PAGES</span></div>
        <div class="starke-group other-group">
            <?php foreach ( $other_results as $post ) { starke_render_row($post, 'type-other'); } ?>
        </div>
    <?php endif; ?>

    <?php 
    // --- NO RESULTS FOUND: SHOW RECOVERY PAGES ---
    if ( empty( $featured_results ) && empty( $molding_results ) && empty( $door_results ) && empty( $other_results ) ) : 
    ?>
        <div class="searchwp-live-search-no-results">
            <p>No exact matches found. Try these popular pages:</p>
            <?php
            foreach ( $fallback_page_ids as $fb_id ) {
                $fb_post = get_post( $fb_id );
                if ( $fb_post ) {
                    starke_render_row( $fb_post, 'type-other' );
                }
            }
            ?>
        </div>
    <?php endif; ?>

</div>