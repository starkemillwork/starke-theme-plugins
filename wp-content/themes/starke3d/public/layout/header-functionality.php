<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * ===============================================================
 * STARKE SEARCH LOCKDOWN (Prevent "Enter" Key on Live Search)
 * ===============================================================
 * Blocks the generic search results page if a user hits Enter.
 * "Smart Enter" Logic: Auto-clicks the first result if available.
 */
add_action( 'wp_footer', 'starke_search_lockdown_script', 999 );

function starke_search_lockdown_script() {
    // Fetch the cached profile list so JavaScript knows what profiles exist
    $valid_profiles = get_transient('starke_cached_profile_list');
    if ( false === $valid_profiles ) {
        $valid_profiles = starke_rebuild_profile_list_cache();
    }
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Pass the PHP valid profiles array into JavaScript
        var validProfiles = <?php echo wp_json_encode($valid_profiles); ?>;
        var productBaseUrl = '<?php echo esc_url( home_url('/product/') ); ?>';

        // 1. Target the Search form
        var $searchForm = $('.searchwp-live-search-results').closest('form');
        if ( $searchForm.length === 0 ) {
             $searchForm = $('input[name="s"]').closest('form');
        }

        // 2. Intercept the "Enter" key
        $searchForm.on('keydown', 'input[type="search"], input[type="text"]', function(e) {
            if (e.which == 13) { 
                e.preventDefault(); // STOP the generic search page
                
                // --- NEW: INSTANT EXACT MATCH REDIRECT ---
                var searchTerm = $(this).val().trim().toLowerCase();
                
                var matchedProfile = validProfiles.find(function(profile) {
                    return profile.toLowerCase() === searchTerm;
                });

                if (matchedProfile) {
                    // EXACT MATCH FOUND! Redirect immediately and halt the script.
                    window.location.href = productBaseUrl + matchedProfile + '/';
                    return; 
                }
                // --- END EXACT MATCH REDIRECT ---
                
                // SMART BEHAVIOR: Find the first result DIV
                // We target the class directly, not an 'a' tag inside it
                var $firstResult = $('.searchwp-live-search-result').first();
                
                // If there is a result, trigger a click on it
                if ( $firstResult.length > 0 ) {
                    console.log('Starke: Enter key pressed. Selecting first result: ' + $firstResult.text().trim());
                    $firstResult.trigger('click');
                } else {
                    console.log('Starke: No results found to select.');
                }
                
                return false;
            }
        });

        // 3. Block the submit button too
        $searchForm.find('button[type="submit"], input[type="submit"]').on('click', function(e){
             e.preventDefault();
             return false;
        });

    });
    </script>
    <?php
}

/**
 * STARKE UX FIX: Re-open Live Search on Click/Focus
 * Logic: If the user clicks back into the search bar and it has text,
 * we force the plugin to show the results again immediately.
 */
add_action( 'wp_footer', function() {
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Target the search input (standard WordPress name="s")
        // We listen for 'focus' (tabbing in) and 'click' (clicking in)
        $(document).on('focus click', 'input[name="s"]', function() {
            
            var $input = $(this);
            
            // Check if the input actually has text in it
            if ( $input.val().trim().length > 0 ) {
                
                // THE TRICK: Trigger an 'input' event.
                // This tells SearchWP: "Hey, something changed! Run the search!"
                // even though the user didn't actually type a new letter.
                $input.trigger('input').trigger('keyup');
            }
        });
    });
    </script>
    <?php
}, 99 );

/**
 * 1. FORCE LOAD SEARCHWP LIVE SEARCH JS
 * Necessary because we removed the standard block/shortcode.
 */
add_action( 'wp_enqueue_scripts', 'starke_ensure_searchwp_js' );
function starke_ensure_searchwp_js() {
    wp_enqueue_script( 'searchwp-live-search' );
}

/**
 * STARKE SEARCH DRAWER (Slide-Out) - FIXED V10 (ISOLATED CLASSES)
 * Completely separated classes from Login Drawer to prevent conflicts.
 */
add_action( 'wp_footer', 'starke_render_search_drawer', 100 );

function starke_render_search_drawer() {
    ?>
    <div style="display:none!important; visibility:hidden!important; height:0; width:0; overflow:hidden;">
        <?php echo do_shortcode('[searchwp_live_search]'); ?>
    </div>

    <div id="starke-search-overlay" class="starke-search-overlay-backdrop"></div>

    <div id="starke-search-drawer" class="starke-search-drawer-panel">
        
        <div class="starke-search-drawer-header">
            <span class="starke-search-drawer-title">SEARCH</span>
            <button type="button" class="starke-search-drawer-close" aria-label="Close Search">
                <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>

        <div class="starke-search-input-wrapper">
            <form role="search" method="get" class="search-form" action="<?php echo home_url( '/' ); ?>">
                <label>
                    <span class="screen-reader-text">Search for:</span>
                    <div class="starke-shop-input-style">
                        <input type="search" class="search-field wpgb-input" placeholder="Search molding, doors, profiles..." value="" name="s" title="Search for:" autocomplete="off" data-swplive="true" data-swpengine="default" data-swpconfig="default" />
                        
                        <svg class="wpgb-input-icon" viewBox="0 0 24 24" height="16" width="16" aria-hidden="true" focusable="false" style="fill: none; stroke: #6431F6; stroke-width: 3; stroke-linecap: round; stroke-linejoin: round;">
                            <path d="M17.5 17.5 23 23Zm-16-7a9 9 0 1 1 9 9 9 9 0 0 1-9-9Z"></path>
                        </svg>
                    </div>
                </label>
            </form>
        </div>

        <div class="starke-search-results-area">
        </div>

    </div>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        
        var $drawer = $('#starke-search-drawer');
        var $body = $('body');

        function calculateScrollbarWidth() {
            var scrollDiv = document.createElement("div");
            scrollDiv.style.cssText = "width: 100px; height: 100px; overflow: scroll; position: absolute; top: -9999px;";
            document.body.appendChild(scrollDiv);
            var scrollbarWidth = scrollDiv.offsetWidth - scrollDiv.clientWidth;
            document.body.removeChild(scrollDiv);
            document.documentElement.style.setProperty('--starke-sb-width', scrollbarWidth + 'px');
        }
        calculateScrollbarWidth();

        // 1. OPEN DRAWER (Optimized: Smooth Slide + Keyboard)
        $(document).on('click', '.starke-search-trigger', function(e) {
            e.stopImmediatePropagation(); 
            e.preventDefault();
            
            var drawerEl = document.getElementById('starke-search-drawer');
            var inputEl  = drawerEl ? drawerEl.querySelector('input[type="search"]') : null;

            if ( !drawerEl || !inputEl ) return;

            window.starkeSearchLock = true;
            setTimeout(function(){ window.starkeSearchLock = false; }, 500); 

            calculateScrollbarWidth();
            
            // 1. SETUP: "Stealth Mode"
            // We force the input to be present (for focus) but invisible (opacity 0)
            // so we don't see a "flash" before the animation starts.
            drawerEl.style.cssText = 'display: block !important; visibility: visible !important; opacity: 0 !important; transition: none !important;';
            
            // 2. TRIGGER KEYBOARD (The Heavy Lift)
            // We do this FIRST so the mobile OS starts the keyboard animation immediately.
            inputEl.focus();

            // 3. START ANIMATION (With Micro-Delay)
            // We wait 75ms. This is imperceptible to the human eye, but it gives 
            // the browser enough time to start the keyboard process BEFORE we 
            // ask it to calculate the drawer slide. This stops the "Fighting/Stutter".
            setTimeout(function() {
                // A. Reset Stealth Styles (Back to "Closed" state)
                drawerEl.style.cssText = ''; 
                
                // B. Force Browser to accept the reset
                void drawerEl.offsetWidth; 

                // C. Trigger the Smooth Slide
                $('body').addClass('starke-search-open');
                
            }, 50); // <-- 75ms delay restores the smoothness

            // 4. CLEANUP
            // We increased this to 450ms because we added a 75ms delay to the start.
            // (350ms animation + 75ms delay + buffer = 450ms)
            setTimeout(function(){
                $(drawerEl).addClass('starke-animation-complete');
            }, 350);
        });

        // 2. CLOSE DRAWER
        function closeStarkeDrawer() {
            $drawer.removeClass('starke-animation-complete');
            $body.removeClass('starke-search-open');
        }

        // UPDATED SELECTORS
        $('.starke-search-drawer-close, #starke-search-overlay').on('click', function(e) {
            e.preventDefault();
            closeStarkeDrawer();
        });

        $(document).keyup(function(e) {
            if (e.key === "Escape") { closeStarkeDrawer(); }
        });

        // 3. SKELETON LOADER & STATE MANAGER
        // UPDATED: Using .starke-search-results-area
        $(document).on('input propertychange', '#starke-search-drawer input[type="search"]', function() {
            var val = $(this).val();
            var $container = $('.starke-search-results-area');
            var $existingResults = $container.find('.searchwp-live-search-results');
            
            if ( val.length > 0 ) {
                $existingResults.addClass('starke-force-hidden');
                if ( $container.find('.starke-skeleton-wrapper').length === 0 ) {
                    renderSkeleton($container);
                }
            } else {
                $container.find('.starke-skeleton-wrapper').remove();
                $existingResults.addClass('starke-force-hidden'); 
            }
        });

        function renderSkeleton($container) {
            var skeletonHTML = `
                <div class="starke-skeleton-wrapper">
                    <div class="starke-skeleton-header"></div>
                    <div class="starke-skeleton-row"><div class="starke-skeleton-img"></div><div class="starke-skeleton-text"></div></div>
                    <div class="starke-skeleton-row"><div class="starke-skeleton-img"></div><div class="starke-skeleton-text"></div></div>
                    <div class="starke-skeleton-row"><div class="starke-skeleton-img"></div><div class="starke-skeleton-text"></div></div>
                    <div class="starke-skeleton-row"><div class="starke-skeleton-img"></div><div class="starke-skeleton-text"></div></div>
                </div>
            `;
            $container.append(skeletonHTML);
        }

        // 4. RESULTS HANDLER
        // UPDATED: Using .starke-search-results-area
        $(document).on('searchwp_live_search_success', function() {
            var $drawer = $('.starke-search-results-area');
            $drawer.find('.starke-skeleton-wrapper').remove();
            var $newResults = $('.searchwp-live-search-results').not('.moved-to-drawer');
            var $existingResults = $drawer.find('.searchwp-live-search-results');
            if ( $newResults.length ) {
                $drawer.append($newResults);
                $newResults.addClass('moved-to-drawer').removeClass('starke-force-hidden');
            } else if ( $existingResults.length ) {
                $existingResults.removeClass('starke-force-hidden');
            }
        });

        $(document).on('searchwp_live_search_shutdown', function() {
            initStickyShadows(); 
        });

        function initStickyShadows() {
            var $scrollArea = $('.starke-search-container');
            if ($scrollArea.length === 0) return;
            var ticking = false;
            function checkHeaders() {
                var $headers = $('.starke-search-header');
                $headers.removeClass('starke-shadow-hidden');
                $headers.each(function(index) {
                    if (index < $headers.length - 1) {
                        var currentHeader = this;
                        var nextHeader = $headers[index + 1];
                        var r1 = currentHeader.getBoundingClientRect();
                        var r2 = nextHeader.getBoundingClientRect();
                        if ( Math.abs(r1.top - r2.top) < 12 ) {
                            $(currentHeader).addClass('starke-shadow-hidden');
                        }
                    }
                });
                ticking = false; 
            }
            $scrollArea.off('scroll.starkeShadow');
            $scrollArea.on('scroll.starkeShadow', function() {
                if (!ticking) {
                    window.requestAnimationFrame(checkHeaders);
                    ticking = true;
                }
            });
            window.requestAnimationFrame(checkHeaders);
        }

        $('#starke-search-drawer').on('click', function(e) {
            e.stopPropagation();
        });
    });
    </script>
    <?php
}

/**
 * ===============================================================
 * STARKE: GLOBAL ON-DEMAND PASSWORD SCRIPT INJECTION
 * ===============================================================
 * Keeps the 400KB math engine completely off the initial page load 
 * for the entire site. It only downloads when the Drawer triggers it.
 */
add_action( 'wp_footer', 'starke_lazy_load_password_meter', 999 );

function starke_lazy_load_password_meter() {
    // Run globally for logged-out users, but skip the final Order Received page
    if ( class_exists( 'WooCommerce' ) && ! is_user_logged_in() ) {
        
        if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
            return;
        }
        
        $zxcvbn_url = includes_url( 'js/zxcvbn.min.js' );
        $psm_url    = admin_url( 'js/password-strength-meter.min.js' );
        $wc_psm_url = WC()->plugin_url() . '/assets/js/frontend/password-strength-meter.min.js';
        
        $params = array(
            'min_password_strength' => 3,
            'i18n_password_error'   => esc_attr__( 'Please enter a stronger password.', 'woocommerce' ),
            'i18n_password_hint'    => wp_strip_all_tags( wp_get_password_hint() )
        );
        ?>
        <script type="text/javascript">
        window.starkePasswordEngineLoaded = false;
        
        window.starkeLoadPasswordEngine = function() {
            if ( window.starkePasswordEngineLoaded ) return;
            window.starkePasswordEngineLoaded = true;
            
            // 1. Setup the parameters WooCommerce expects
            window.wc_password_strength_meter_params = <?php echo wp_json_encode( $params ); ?>;
            
            // 2. Load the scripts sequentially so dependencies don't break
            jQuery.getScript('<?php echo esc_url( $zxcvbn_url ); ?>', function() {
                jQuery.getScript('<?php echo esc_url( $psm_url ); ?>', function() {
                    jQuery.getScript('<?php echo esc_url( $wc_psm_url ); ?>', function() {
                        // Once fully loaded, trigger an input event on the password field to wake up the meter visually
                        jQuery('#starke_reg_password').trigger('input');
                    });
                });
            });
        };
        </script>
        <?php
    }
}

/* =========================================
   STARKE LOGIN/REGISTER DRAWER (Direct Attachment Fix)
   ========================================= */
add_action( 'wp_footer', 'starke_render_login_drawer', 200 );

function starke_render_login_drawer() {
    if ( is_account_page() ) {
        return;
    }

    global $wp;
    $current_url = add_query_arg( $_GET, home_url( $wp->request ) );
    $account_url = get_permalink( get_option('woocommerce_myaccount_page_id') );
    $logged_in   = is_user_logged_in();
    ?>
    
    <div id="starke-login-overlay" class="starke-login-overlay-backdrop"></div>

    <div id="starke-login-drawer" class="starke-login-drawer-panel">
        
        <div class="starke-login-drawer-header" style="justify-content: flex-end; border-bottom: none; padding-bottom: 0;">
            <button type="button" class="starke-login-drawer-close" id="starke-login-close" aria-label="Close">
                <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>

        <?php if ( $logged_in ) : ?>
            
            <div class="starke-login-header-title">
                <span>My Account</span>
            </div>

            <div class="starke-login-content-area">
                <div class="starke-account-menu-card">
                    
                    <?php 
                    // Check Architect Access Status
                    $is_architect = function_exists('starke_has_architect_access') && starke_has_architect_access();
                    
                    // Check if they are PENDING (waiting for approval)
                    $arch_status  = get_user_meta( get_current_user_id(), '_starke_architect_status', true );
                    $is_pending   = ( 'pending' === $arch_status );
                    ?>
                    <script>
                        window.starkeUserLoggedIn = true;
                        window.starkeUserIsArchitect = <?php echo $is_architect ? 'true' : 'false'; ?>;
                    </script>

                    <div id="starke-dxf-denial-msg" class="starke-dxf-denial-notice" style="display:none; margin: 15px 30px; border-radius: 6px;">
                        
                        <?php if ( $is_pending ) : ?>
                            <div id="starke-dxf-pending-content">
                                <i class="fas fa-clock" style="font-size: 1.5rem; margin-bottom: 8px; color: #000000;"></i><br>
                                <strong style="font-weight:700; color: #000000;">Request Pending</strong><br>
                                <span style="font-size: 0.85rem; color: #333333;">We are reviewing your Architect Access status (required for downloading DXFs).<br>You will be notified via email.</span>
                            </div>
                        
                        <?php else : ?>
                            <div id="starke-dxf-initial-content">
                                <strong style="font-weight:700; font-size: 1rem; color: #000000;">Architect Access Required</strong><br>
                                <span style="font-size: 0.85rem; display:block; margin: 8px 0 12px 0; line-height: 1.5; color: #222222;">
                                    You do not have active architect access.<br>
                                    We reserve this access to architectural firms only. If you are not an architectural firm and would like a DXF, please email us.
                                </span>
                                <a href="mailto:info@starkemillwork.com" style="color: #000000; font-weight: bold; text-decoration: underline; font-size: 0.9rem;">
                                    info@starkemillwork.com
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <div id="starke-dxf-success-content" style="display:none;">
                            <i class="fas fa-check-circle" style="font-size: 1.5rem; margin-bottom: 8px; color: #000000;"></i><br>
                            <strong style="font-weight:700; color: #000000;">Request Sent!</strong><br>
                            <span style="font-size: 0.85rem; color: #333333;">We will notify you via email.</span>
                        </div>
                    </div>

                    <div id="starke-limited-access-msg" class="starke-dxf-denial-notice" style="display:none; margin: 15px 30px; border-radius: 6px;">
                        <div id="starke-limited-content">
                            <i class="fas fa-lock" style="font-size: 1.5rem; margin-bottom: 8px; color: #000000;"></i><br>
                            <strong style="font-weight:700; font-size: 1rem; color: #000000;">Account Limited</strong><br>
                            <span style="font-size: 0.85rem; display:block; margin: 8px 0 12px 0; line-height: 1.5; color: #222222;">
                                Your account currently has limited access. DXF downloads, purchasing and viewing pricing are disabled.<br><br>
                                Please contact the office for assistance.
                            </span>
                            <a href="mailto:info@starkemillwork.com" style="color: #000000; font-weight: bold; text-decoration: underline; font-size: 0.9rem;">
                                info@starkemillwork.com
                            </a>
                        </div>
                    </div>

                    <a href="<?php echo esc_url( home_url( '/my-account/quotes/' ) ); ?>" class="starke-drawer-link">
                        <span class="starke-link-icon"><i class="far fa-file-alt"></i></span>
                        <span class="starke-link-text">Quotes</span>
                        <span class="starke-link-arrow"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg></span>
                    </a>

                    <a href="<?php echo esc_url( home_url( '/my-account/orders/' ) ); ?>" class="starke-drawer-link">
                        <span class="starke-link-icon"><i class="fas fa-shopping-bag"></i></span>
                        <span class="starke-link-text">Orders</span>
                        <span class="starke-link-arrow"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg></span>
                    </a>

                    <a href="<?php echo esc_url( home_url( '/my-account/edit-address/' ) ); ?>" class="starke-drawer-link">
                        <span class="starke-link-icon"><i class="fas fa-map-marker-alt"></i></span>
                        <span class="starke-link-text">Addresses</span>
                        <span class="starke-link-arrow"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg></span>
                    </a>

                    <a href="<?php echo esc_url( home_url( '/my-account/edit-account/' ) ); ?>" class="starke-drawer-link">
                        <span class="starke-link-icon"><i class="fas fa-user"></i></span>
                        <span class="starke-link-text">Account Details</span>
                        <span class="starke-link-arrow"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg></span>
                    </a>

                    <?php
                    // --- IMPERSONATION CHECK ---
                    // If an admin is impersonating, we route the logout button directly to the Switch Back logic
                    if ( function_exists('impersonation_is_active') && impersonation_is_active() ) {
                        $starke_logout_url = wp_nonce_url( admin_url( 'admin-ajax.php?action=switch_back_to_admin' ), 'switch_back_nonce' );
                    } else {
                        // Standard user logout (keeps them on the current frontend page)
                        $starke_logout_url = wp_logout_url( $current_url );
                    }
                    ?>
                    <a href="<?php echo esc_url( $starke_logout_url ); ?>" class="starke-drawer-link">
                        <span class="starke-link-icon"><i class="fas fa-sign-out-alt"></i></span>
                        <span class="starke-link-text">Log Out</span>
                    </a>

                </div>
            </div>

        <?php else : ?>

            <div class="starke-login-tabs">
                <button class="starke-tab-btn active" data-tab="starke-tab-login">Log In</button>
                <button class="starke-tab-btn" data-tab="starke-tab-register">Register</button>
            </div>

            <div class="starke-login-content-area">
                
                <div id="starke-tab-login" class="starke-auth-tab-content active">
                    <form id="starke-login-form-element" class="starke-drawer-form login" method="post" action="<?php echo esc_url( $account_url ); ?>" novalidate="novalidate">
                        
                        <div class="starke-form-scroll-wrapper">
                            <div class="starke-form-row">
                                <div class="field-full starke-float-wrapper">
                                    <input type="email" class="starke-input" name="username" id="starke_username" autocomplete="email" value="<?php echo ( ! empty( $_POST['username'] ) ) ? esc_attr( wp_unslash( $_POST['username'] ) ) : ''; ?>" placeholder=" " required />
                                    <label for="starke_username">Email address / Username <span class="required">*</span></label>
                                </div>
                            </div>

                            <div class="starke-form-row">
                                <div class="field-full starke-float-wrapper">
                                    <input type="password" class="starke-input" name="password" id="starke_password" autocomplete="current-password" placeholder=" " required />
                                    <label for="starke_password">Password <span class="required">*</span></label>
                                </div>
                            </div>

                            <div class="starke-login-actions">
                                <label class="starke-remember-me">
                                    <input name="rememberme" type="checkbox" id="starke_rememberme" value="forever" /> 
                                    <span>Remember me</span>
                                </label>
                                <a href="<?php echo esc_url( wp_lostpassword_url() ); ?>" class="starke-lost-pw">Lost password?</a>
                            </div>

                            <input type="hidden" name="login" value="Log in">
                            <input type="hidden" name="woocommerce-login-nonce" value="<?php echo wp_create_nonce( 'woocommerce-login' ); ?>" />
                            <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( $current_url ); ?>" />
                            <input type="hidden" name="redirect" value="<?php echo esc_url( $current_url ); ?>" />
                            
                            <button type="button" id="starke-manual-login-btn" class="woocommerce-button button wp-element-button starke-sample-btn request-mode">Log In</button>
                        </div>

                    </form>
                </div>

                <div id="starke-tab-register" class="starke-auth-tab-content">
                    <form id="starke-register-form-element" class="starke-drawer-form register" method="post" action="<?php echo esc_url( $account_url ); ?>" novalidate="novalidate">
                        
                        <?php 
                        // --- ADD THIS LINE HERE ---
                        // This injects the hidden honeypot/timestamp into your custom drawer
                        starke_print_bot_protection_fields(); 
                        ?>

                        <div class="starke-form-scroll-wrapper">
                            <div class="starke-form-row">
                                <div class="field-full starke-float-wrapper">
                                    <input type="email" class="starke-input" name="email" id="starke_reg_email" autocomplete="email" placeholder=" " required />
                                    <label for="starke_reg_email">Email address <span class="required">*</span></label>
                                </div>
                            </div>
                            
                            <?php if ( 'no' === get_option( 'woocommerce_registration_generate_password' ) ) : ?>
                            
                            <div style="position: relative; display: flex; justify-content: flex-end; align-items: center; margin-bottom: 10px; min-height: 24px;">
                                <div id="starke-drawer-generated-alert" style="position: absolute; left: 0; color: #6431F6; font-size: 0.85rem; font-weight: 500; font-family: 'Inter', sans-serif; opacity: 0; transition: opacity 0.2s ease; pointer-events: none; white-space: nowrap;">
                                    Password generated! Please copy it.
                                </div>
                                <button type="button" id="starke-drawer-generate-pw" style="background: transparent; border: none; color: #6431F6; font-size: 0.85rem; font-weight: 600; font-family: 'Inter', sans-serif; cursor: pointer; padding: 0; text-transform: uppercase; transition: all 0.2s ease; white-space: nowrap; flex-shrink: 0;">
                                    <i class="fas fa-magic"></i> Generate Password
                                </button>
                            </div>

                            <div class="starke-form-row">
                                <div class="field-full starke-float-wrapper">
                                    <input type="password" class="starke-input" name="password" id="starke_reg_password" autocomplete="new-password" placeholder=" " required />
                                    <label for="starke_reg_password">Password <span class="required">*</span></label>
                                </div>
                            </div>
                            <div class="starke-form-row">
                                <div class="field-full starke-float-wrapper">
                                    <input type="password" class="starke-input" name="password_2" id="starke_reg_password_2" autocomplete="new-password" placeholder=" " required />
                                    <label for="starke_reg_password_2">Confirm Password <span class="required">*</span></label>
                                </div>
                            </div>
                            <div id="starke-password-strength" class="woocommerce-password-strength" style="display:none;" aria-live="polite"></div>
                            <div id="starke-password-match-error" class="starke-password-error" style="display:none;">
                                <i class="fas fa-exclamation-triangle"></i> PASSWORDS DO NOT MATCH
                            </div>
                            <small id="starke-password-hint" class="woocommerce-password-hint" style="display:none; margin-bottom: 20px; display:block;">
                                <?php echo wp_get_password_hint(); ?>
                            </small>
                            <?php else: ?>
                                <p style="font-size: 0.9em; color: #667; margin-bottom: 20px;">A password will be sent to your email address.</p>
                            <?php endif; ?>

                            <div class="starke-checkbox-container" style="margin: 20px 0 20px 0;">
                                <label class="starke-architect-request" for="starke_architect_request_drawer">
                                    <input type="checkbox" name="starke_request_architect" id="starke_architect_request_drawer" value="1" />
                                    <span class="starke-req-text">Request Architect Access (access to .dxf files for door and molding profiles)</span>
                                </label>
                            </div>
                            
                            <div style="margin-bottom: 20px; font-size: 0.85em; color: #667; margin-top: 10px;">
                                Your personal data will be used to support your experience throughout this website, to manage access to your account, and for other purposes described in our <a href="/policies" style="text-decoration: underline;">privacy policy</a>.
                            </div>

                            <input type="hidden" name="register" value="Register">
                            <input type="hidden" name="woocommerce-register-nonce" value="<?php echo wp_create_nonce( 'woocommerce-register' ); ?>" />
                            <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( $current_url ); ?>" />
                            <input type="hidden" name="redirect" value="<?php echo esc_url( $current_url ); ?>" />
                            
                            <button type="button" id="starke-manual-register-btn" class="woocommerce-button button wp-element-button starke-sample-btn request-mode" disabled>Enter Email Address</button>
                        </div>

                    </form>
                </div>

            </div>

        <?php endif; ?>

    </div>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        
        var accountUrl = "<?php echo esc_url( $account_url ); ?>";
        var $body = $('body');
        var $drawer = $('#starke-login-drawer');

        function calculateScrollbarWidth() {
            var scrollDiv = document.createElement("div");
            scrollDiv.style.cssText = "width: 100px; height: 100px; overflow: scroll; position: absolute; top: -9999px;";
            document.body.appendChild(scrollDiv);
            var scrollbarWidth = scrollDiv.offsetWidth - scrollDiv.clientWidth;
            document.body.removeChild(scrollDiv);
            document.documentElement.style.setProperty('--starke-sb-width', scrollbarWidth + 'px');
        }

        // --- 1. DIRECT ATTACHMENT FIX (THE ISOLATION LOGIC) ---
        // Instead of a global document listener, we find specific triggers and attach directly.
        // This allows us to use 'stopPropagation' to kill the event before it triggers the hamburger.
        
        var loginTriggers = document.querySelectorAll('.wp-block-woocommerce-customer-account a, .starke-login-trigger');
        
        if (loginTriggers.length > 0) {
            loginTriggers.forEach(function(trigger) {
                // Attach listener directly to the element
                trigger.addEventListener('click', function(e) {
                    
                    // STOP EVERYTHING: Prevents bubbling to Hamburger or Document
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();

                    // Security Check
                    if ( window.starkeSearchLock ) { return; }
                    if ( e.ctrlKey || e.metaKey || e.which === 2 ) return;

                    // Open Drawer Logic
                    calculateScrollbarWidth();
                    $drawer.css('visibility', 'visible');
                    $body.addClass('starke-login-open');
                    
                }, true); // Use Capture Phase for priority
            });
        }

        // --- 2. CLOSE DRAWER LOGIC ---
        function closeLoginDrawer() { 
            $body.removeClass('starke-login-open'); 
            setTimeout(function(){
                if(!$body.hasClass('starke-login-open')) {
                    $drawer.css('visibility', 'hidden');
                }
            }, 350);
        }
        
        $('.starke-login-drawer-close, #starke-login-overlay').on('click', function(e) { 
            e.preventDefault(); 
            closeLoginDrawer(); 
        });
        
        $(document).keyup(function(e) { 
            if (e.key === "Escape") { closeLoginDrawer(); } 
        });

        // --- 3. FORM VALIDATION LOGIC (Logged Out State Only) ---
        if ( $('#starke-login-form-element').length > 0 ) {
            
            var $emailInput    = $('#starke_reg_email');
            var $pass1         = $('#starke_reg_password');
            var $pass2         = $('#starke_reg_password_2');
            var $strengthMeter = $('#starke-password-strength');
            var $matchError    = $('#starke-password-match-error');
            var $hintText      = $('#starke-password-hint');
            var $registerBtn   = $('#starke-manual-register-btn');
            var $drawerGenerateBtn = $('#starke-drawer-generate-pw');
            
            var minStrength = 3; 
            var wooParams = (typeof wc_password_strength_meter_params !== 'undefined') ? wc_password_strength_meter_params : false;
            if ( wooParams ) { minStrength = parseInt( wooParams.min_password_strength ); }

            function isValidEmail(email) {
                var regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                return regex.test(email);
            }

            function validateRegistration() {
                var emailVal = $emailInput.val().trim();
                var val1 = $pass1.val();
                var val2 = $pass2.val();
                
                var strengthScore = 0; 
                var strengthLabel = '';

                // --- Helper: Generate Star HTML ---
                function getStarHTML(filledCount) {
                    var html = '<div class="starke-password-stars">';
                    for(var i = 1; i <= 5; i++) {
                        if (i <= filledCount) {
                            html += '<i class="fas fa-star"></i>'; // Filled
                        } else {
                            html += '<i class="far fa-star"></i>'; // Hollow
                        }
                    }
                    html += '</div>';
                    return html;
                }

                // --- Strength Logic Replacement ---
                if ( val1 !== '' && typeof wp !== 'undefined' && typeof wp.passwordStrength !== 'undefined' ) {
                    strengthScore = wp.passwordStrength.meter( val1, wp.passwordStrength.userInputDisallowedList(), val1 );
                    
                    $strengthMeter.show().removeClass('short bad good strong');
                    
                    // STARKE CUSTOM LABELS & STARS
                    var starCount = 0;
                    switch ( strengthScore ) {
                        case 0: 
                            $strengthMeter.addClass('short'); 
                            strengthLabel = 'Too Short'; 
                            starCount = 1;
                            break;
                        case 1: 
                            $strengthMeter.addClass('bad'); 
                            strengthLabel = 'Weak'; 
                            starCount = 2;
                            break;
                        case 2: 
                            $strengthMeter.addClass('bad'); 
                            strengthLabel = 'Medium'; 
                            starCount = 3;
                            break;
                        case 3: 
                            $strengthMeter.addClass('good'); 
                            strengthLabel = 'Good'; 
                            starCount = 4;
                            break;
                        case 4: 
                            $strengthMeter.addClass('strong'); 
                            strengthLabel = 'Strong'; 
                            starCount = 5;
                            break;
                    }
                    
                    // Render: Label + Stars
                    $strengthMeter.html( strengthLabel + getStarHTML(starCount) );

                    if ( strengthScore < minStrength ) {
                        $hintText.show();
                    } else {
                        $hintText.hide();
                    }
                } else {
                    $strengthMeter.hide();
                    $hintText.hide();
                }

                var isMatch = (val1 === val2 && val1 !== '');
                if ( val2 !== '' && !isMatch ) { $matchError.show(); } else { $matchError.hide(); }

                if ( emailVal === '' ) {
                    $registerBtn.prop('disabled', true).css('opacity', '0.5').text('Enter Email Address').removeClass('register-ready');
                    return;
                }
                if ( !isValidEmail(emailVal) ) {
                    $registerBtn.prop('disabled', true).css('opacity', '0.5').text('Enter Valid Email').removeClass('register-ready');
                    return;
                }
                // THE FIX: Check if WP's strength script actually loaded
                var strengthLibLoaded = (typeof wp !== 'undefined' && typeof wp.passwordStrength !== 'undefined');
                
                var isStrongEnough = false;
                if ( strengthLibLoaded ) {
                    // Use standard WooCommerce strength meter if available
                    isStrongEnough = (strengthScore >= minStrength);
                } else {
                    // Fallback for the Checkout page: Enforce the 8 character server minimum
                    isStrongEnough = (val1.length >= 8);
                }

                if ( !isStrongEnough ) {
                    if ( val1 === '' ) {
                        $registerBtn.prop('disabled', true).css('opacity', '0.5').text('Enter Password').removeClass('register-ready');
                    } else if ( !strengthLibLoaded && val1.length < 8 ) {
                        $registerBtn.prop('disabled', true).css('opacity', '0.5').text('Password Too Short').removeClass('register-ready');
                    } else {
                        $registerBtn.prop('disabled', true).css('opacity', '0.5').text('Enter Stronger Password').removeClass('register-ready');
                    }
                    return;
                } 
                if ( !isMatch ) {
                    $registerBtn.prop('disabled', true).css('opacity', '0.5').text('Make Passwords Match').removeClass('register-ready');
                    return;
                }
                
                $registerBtn.prop('disabled', false).css('opacity', '1').text('Register').addClass('register-ready');
            }

            // --- NEW: PASSWORD GENERATOR LOGIC (DRAWER) ---
            if ($drawerGenerateBtn.length) {
                $drawerGenerateBtn.on('click', function(e) {
                    e.preventDefault();
                    
                    var charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+~";
                    var newPass = "";
                    newPass += "ABCDEFGHIJKLMNOPQRSTUVWXYZ".charAt(Math.floor(Math.random() * 26)); 
                    newPass += "abcdefghijklmnopqrstuvwxyz".charAt(Math.floor(Math.random() * 26)); 
                    newPass += "0123456789".charAt(Math.floor(Math.random() * 10)); 
                    newPass += "!@#$%^&*()_+~".charAt(Math.floor(Math.random() * 13)); 

                    for (var i = 0; i < 12; i++) {
                        newPass += charset.charAt(Math.floor(Math.random() * charset.length));
                    }
                    
                    newPass = newPass.split('').sort(function(){return 0.5-Math.random()}).join('');

                    $pass1.val(newPass).attr('type', 'text');
                    $pass2.val(newPass).attr('type', 'text');
                    
                    $(this).html('<i class="fas fa-sync-alt"></i> Regenerate');
                    
                    // --- AUTO-COPY TO CLIPBOARD ---
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(newPass);
                        $('#starke-drawer-generated-alert').text('Copied to clipboard!').css('opacity', '1');
                    } else {
                        $('#starke-drawer-generated-alert').text('Password generated! Please copy it.').css('opacity', '1');
                    }

                    validateRegistration();
                });

                // HIDE PASSWORD ONLY WHEN THEY START TYPING
                $pass1.add($pass2).on('input', function() {
                    if ($pass1.attr('type') === 'text') {
                        $pass1.attr('type', 'password');
                        $pass2.attr('type', 'password');
                        $('#starke-drawer-generated-alert').css('opacity', '0'); 
                        $drawerGenerateBtn.html('<i class="fas fa-magic"></i> Generate Password');
                    }
                });
            }

            $emailInput.on('keyup change input', validateRegistration);
            $pass1.on('keyup change input', validateRegistration);
            $pass2.on('keyup change input', validateRegistration);

            $('.starke-drawer-form input').on('keydown', function(e) {
                if (e.which === 13) { 
                    e.preventDefault(); 
                    var $btn = $(this).closest('form').find('button.starke-sample-btn');
                    $btn.trigger('click');
                }
            });

            $('#starke-manual-login-btn').on('click', function(e) {
                e.preventDefault(); 
                document.getElementById('starke-login-form-element').submit();
            });

            $('#starke-manual-register-btn').on('click', function(e) {
                e.preventDefault();
                if( $(this).prop('disabled') ) return; 
                document.getElementById('starke-register-form-element').submit();
            });

            $('.starke-tab-btn').on('click', function(e) {
                e.preventDefault();
                $('.starke-tab-btn').removeClass('active');
                $(this).addClass('active');
                var targetId = $(this).data('tab');
                $('.starke-auth-tab-content').removeClass('active');
                $('#' + targetId).addClass('active');
                
                if ( targetId === 'starke-tab-register' && typeof window.starkeLoadPasswordEngine === 'function' ) {
                    window.starkeLoadPasswordEngine();
                }

                $pass1.val('').trigger('keyup'); 
                $pass2.val('');
                $emailInput.val('');
                $registerBtn.prop('disabled', true).text('Enter Email Address').removeClass('register-ready');

                // Reset Drawer Generator visually
                if ($drawerGenerateBtn.length) {
                    $pass1.attr('type', 'password');
                    $pass2.attr('type', 'password');
                    $('#starke-drawer-generated-alert').css('opacity', '0');
                    $drawerGenerateBtn.html('<i class="fas fa-magic"></i> Generate Password');
                }
            });
        }

        // --- 4. HANDLE ARCHITECT REQUEST BUTTON (AJAX) ---
        $('#starke-drawer-request-btn').on('click', function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var originalText = $btn.text();
            
            // UI Loading State
            $btn.text('Sending...').prop('disabled', true).css('opacity', '0.7');

            $.ajax({
                type: 'POST',
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                data: {
                    action: 'starke_ajax_request_architect',
                    nonce: '<?php echo wp_create_nonce('starke_architect_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        // Swap content to Success Message
                        $('#starke-dxf-initial-content').fadeOut(200, function() {
                            $('#starke-dxf-success-content').fadeIn(200);
                        });
                        sessionStorage.removeItem('starke_wanted_dxf');
                    } else {
                        // Handle "Already Pending" gracefully
                        if ( response.data === 'Request pending' ) {
                             $('#starke-dxf-initial-content').fadeOut(200, function() {
                                // We can reuse the success message or reload to show the pending state
                                location.reload(); 
                            });
                        } else {
                            alert('Error: ' + response.data);
                            $btn.text(originalText).prop('disabled', false).css('opacity', '1');
                        }
                    }
                },
                error: function() {
                    alert('System error. Please contact us.');
                    $btn.text(originalText).prop('disabled', false).css('opacity', '1');
                }
            });
        });
    });
    </script>
    <?php
}

/**
 * STARKE SERVER-SIDE STRENGTH ENFORCEMENT (The "Sweet Spot")
 * 1. Frontend JS encourages "Strong" (12+ chars).
 * 2. Server PHP requires "Minimum Viable" (8 chars).
 * This prevents bots and extremely weak passwords without frustrating real users.
 */
add_filter( 'woocommerce_registration_errors', 'starke_validate_password_strength_server_side', 10, 3 );

function starke_validate_password_strength_server_side( $errors, $username, $email ) {
    // Only run this check if a password was actually submitted
    if ( isset( $_POST['password'] ) && ! empty( $_POST['password'] ) ) {
        $pass = $_POST['password'];
        
        // HARD LIMIT: 8 Characters
        // If it's shorter than 8, we block it no matter what.
        if ( strlen( $pass ) < 8 ) {
            $errors->add( 'password_too_short', __( 'Error: Your password is too short. Please use at least 8 characters.', 'woocommerce' ) );
        }

        // OPTIONAL: Basic complexity check (Must not be "password" or "12345678")
        $weak_blocklist = ['password', '12345678', '123456789', 'qwertyuiop', 'starkemillwork'];
        if ( in_array( strtolower( $pass ), $weak_blocklist ) ) {
             $errors->add( 'password_too_common', __( 'Error: That password is too common. Please choose a more secure password.', 'woocommerce' ) );
        }
    }
    return $errors;
}

/* --- Starke Dynamic Menu Offset Script (flush-header-fix) --- */
/* Calculates the exact pixel gap between the menu link and the header bottom */
function starke_dynamic_menu_flush_fix() {
    ?>
    <script>
    (function() {
        function recalculateMenuOffset() {
            // 1. Target your Main Header (The container with the shadow/padding)
            const header = document.querySelector('.header-group');
            
            // 2. Target a Menu Item (The reference point)
            const navItem = document.querySelector('.wp-block-navigation-item');

            if (header && navItem) {
                // Measure physical screen positions
                const headerRect = header.getBoundingClientRect();
                const navItemRect = navItem.getBoundingClientRect();

                // Calculate the exact distance: Header Bottom - Link Bottom
                const offset = Math.max(0, headerRect.bottom - navItemRect.bottom);

                // 3. Send this number to CSS as a variable
                document.documentElement.style.setProperty('--starke-menu-offset', offset + 'px');
            }
        }

        // Run on standard events
        window.addEventListener('DOMContentLoaded', recalculateMenuOffset);
        window.addEventListener('load', recalculateMenuOffset);
        window.addEventListener('resize', recalculateMenuOffset);
        
        // NEW: Run on Scroll (Catches the sticky stick/unstick moment)
        window.addEventListener('scroll', recalculateMenuOffset, { passive: true });

        // NEW: ResizeObserver (The Magic Fix)
        // This watches the header for size changes (animation) and updates the gap continuously.
        const headerGroup = document.querySelector('.header-group');
        if (headerGroup) {
            const resizeObserver = new ResizeObserver((entries) => {
                recalculateMenuOffset();
            });
            resizeObserver.observe(headerGroup);
        }
        
        // Safety check loop
        setTimeout(recalculateMenuOffset, 500);
    })();
    </script>
    <?php
}
add_action('wp_footer', 'starke_dynamic_menu_flush_fix');

/* =========================================
   STARKE HAMBURGER DRAWER (Direct Attachment Fix + Unique Class)
   ========================================= */
add_action( 'wp_footer', 'starke_render_hamburger_drawer', 210 );

function starke_render_hamburger_drawer() {
    ?>
    <div id="starke-hamburger-overlay" class="starke-hamburger-overlay-backdrop"></div>

    <div id="starke-hamburger-drawer" class="starke-hamburger-drawer-panel">
        
        <div class="starke-login-drawer-header" style="justify-content: flex-end; border-bottom: none; padding-bottom: 0;">
            <button type="button" class="starke-login-drawer-close" id="starke-hamburger-close" aria-label="Close">
                <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>

        <div class="starke-login-header-title">
            <span>Menu</span>
        </div>

        <div class="starke-login-content-area">
            <div class="starke-account-menu-card">
                <div class="starke-drawer-scroll-content">
                    <?php
                    // --- UPDATED MENU ITEMS WITH UNIQUE ICONS ---
                    $menu_items = array(
                        array( 
                            'title' => 'Home', 
                            'url'   => 'https://www.starkemillwork.com', 
                            'icon'  => 'fas fa-home', 
                            'children' => [] 
                        ),
                        array( 
                            'title' => 'Shop Molding', 
                            'url'   => 'https://www.starkemillwork.com/shop/', 
                            'icon'  => 'fas fa-store', 
                            'children' => [] 
                        ),
                        array( 
                            'title' => 'Doors', 
                            'url'   => 'https://www.starkemillwork.com/door-details/', 
                            'icon'  => 'fas fa-door-open', 
                            'children' => array(
                                array( 'title' => 'Sticking Profiles', 'url' => 'https://www.starkemillwork.com/door-details/#sticking' ),
                                array( 'title' => 'Panel Profiles', 'url' => 'https://www.starkemillwork.com/door-details/#panel' ),
                                array( 'title' => 'Groove Profiles', 'url' => 'https://www.starkemillwork.com/door-details/#groove' ),
                                array( 'title' => 'Saddle Options', 'url' => 'https://www.starkemillwork.com/door-details/#saddle-options' ),
                                array( 'title' => 'Finish Options', 'url' => 'https://www.starkemillwork.com/door-details/#finish-options' ),
                        )),
                        array( 
                            'title' => 'Photos', 
                            'url'   => 'https://www.starkemillwork.com/photos', 
                            'icon'  => 'fas fa-images', 
                            'children' => array(
                                array( 'title' => 'Interior Doors', 'url' => 'https://www.starkemillwork.com/photos/#interior-doors' ),
                                array( 'title' => 'Exterior Doors', 'url' => 'https://www.starkemillwork.com/photos/#exterior-doors' ),
                                array( 'title' => 'Molding', 'url' => 'https://www.starkemillwork.com/photos/#molding' ),
                                array( 'title' => 'Wainscoting', 'url' => 'https://www.starkemillwork.com/photos/#wainscoting' ),
                                array( 'title' => 'Commercial Jobs', 'url' => 'https://www.starkemillwork.com/photos/#commercial-jobs' ),
                                array( 'title' => 'Odds and Ends', 'url' => 'https://www.starkemillwork.com/photos/#odds-and-ends' ),
                                array( 'title' => 'Work In Progress', 'url' => 'https://www.starkemillwork.com/photos/#work-in-progress' ),
                        )),
                        array( 
                            'title' => 'About', 
                            'url'   => '/about-us', 
                            'icon'  => 'fas fa-users', 
                            'children' => array(
                                array( 'title' => 'Policies', 'url' => 'https://www.starkemillwork.com/policies/' ),
                                array( 'title' => 'Delivery', 'url' => 'https://www.starkemillwork.com/delivery/' ),
                                array( 'title' => 'FAQs', 'url' => 'https://www.starkemillwork.com/faqs/' ),
                                array( 'title' => 'Our Story', 'url' => 'https://www.starkemillwork.com/about-us/' ),
                        )),
                        array( 
                            'title' => 'Contact Us', 
                            'url'   => 'https://www.starkemillwork.com/contact-us/', 
                            'icon'  => 'fas fa-envelope', 
                            'children' => [] 
                        ),
                    );

                    foreach ( $menu_items as $parent ) {
                        $has_children = ! empty( $parent['children'] );
                        // Fallback icon just in case
                        $icon_class = ! empty( $parent['icon'] ) ? $parent['icon'] : 'fas fa-bars';
                        ?>
                        <div class="starke-menu-group">
                            <a href="<?php echo esc_url( $parent['url'] ); ?>" class="starke-drawer-link parent-link">
                                <span class="starke-link-icon"><i class="<?php echo esc_attr( $icon_class ); ?>"></i></span>
                                
                                <span class="starke-link-text"><?php echo esc_html( $parent['title'] ); ?></span>
                                <span class="starke-link-arrow">
                                    <?php if ( $has_children ) : ?>
                                        <span class="starke-toggle-arrow">
                                            <svg class="plus-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                        </span>
                                    <?php else : ?>
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                                    <?php endif; ?>
                                </span>
                            </a>
                            <?php if ( $has_children ) : ?>
                                <div class="starke-drawer-submenu">
                                    <?php foreach ( $parent['children'] as $child ) : ?>
                                        <a href="<?php echo esc_url( $child['url'] ); ?>" class="starke-drawer-link sub-link">
                                            <span class="starke-link-icon" style="font-size: 0.5rem; opacity: 0.5;"><i class="fas fa-circle"></i></span>
                                            <span class="starke-link-text"><?php echo esc_html( $child['title'] ); ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div> 
                        <?php
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>

    <script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function() {
        
        var body = document.body;
        var drawer = document.getElementById('starke-hamburger-drawer');
        var overlay = document.getElementById('starke-hamburger-overlay');
        var closeBtn = document.getElementById('starke-hamburger-close');

        // 1. SCROLLBAR CALCULATOR
        function calculateScrollbarWidth() {
            var scrollDiv = document.createElement("div");
            scrollDiv.style.cssText = "width: 100px; height: 100px; overflow: scroll; position: absolute; top: -9999px;";
            document.body.appendChild(scrollDiv);
            var scrollbarWidth = scrollDiv.offsetWidth - scrollDiv.clientWidth;
            document.body.removeChild(scrollDiv);
            document.documentElement.style.setProperty('--starke-sb-width', scrollbarWidth + 'px');
        }

        // 2. THE FIX: DIRECT ATTACHMENT
        // Instead of listening to the whole document, we find the EXACT button
        // and attach the listener ONLY to it. This isolates the logic completely.
        var hamburgerButton = document.querySelector('.wp-block-navigation__responsive-container-open');
        
        if ( hamburgerButton ) {
            
            // Tag it so we know we found it
            hamburgerButton.classList.add('starke-verified-hamburger-trigger');
            
            // Attach 'Capture Phase' listener directly to the button element.
            hamburgerButton.addEventListener('click', function(e) {
                
                // STOP everything so WordPress doesn't "lock" the body
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();

                if ( window.starkeSearchLock ) { return; }

                console.log('[Starke] Hamburger Triggered via Direct Attachment');

                calculateScrollbarWidth();
                drawer.style.visibility = 'visible';
                body.classList.add('starke-hamburger-open');
                
                // Scrub just in case WP sneaks in
                body.classList.remove('wp-block-navigation-open', 'lock-scroll', 'modal-open');

            }, true); // <--- 'true' means Priority Mode (Capture) on THIS element
        }

        // 3. CLOSE DRAWER LOGIC
        function closeHamburgerDrawer() { 
            body.classList.remove('starke-hamburger-open'); 
            setTimeout(function(){
                if(!body.classList.contains('starke-hamburger-open')) {
                    drawer.style.visibility = 'hidden';
                    var openMenus = document.querySelectorAll('.starke-menu-group.starke-menu-open');
                    openMenus.forEach(function(menu) { menu.classList.remove('starke-menu-open'); });
                }
            }, 350);
        }

        var menuGroups = document.querySelectorAll('.starke-menu-group');
        
        menuGroups.forEach(function(group) {
            var hoverTimer; 

            // --- 1. HOVER LOGIC (Desktop Only) ---
            // We check 'matchMedia' to ensure this ONLY runs on devices with a mouse/cursor.
            // This prevents the "Double Tap" issue on mobile/touch devices.
            if (window.matchMedia('(hover: hover)').matches) {
                
                group.addEventListener('mouseenter', function() { 
                    // Only open if the user stays hovering for 35ms (Intent)
                    hoverTimer = setTimeout(function() {
                        group.classList.add('starke-menu-open');
                    }, 350); 
                });

                group.addEventListener('mouseleave', function() {
                    if (hoverTimer) {
                        clearTimeout(hoverTimer);
                    }
                });
            }

            // --- 2. CLICK LOGIC (All Devices) ---
            // The arrow click listener works on both Desktop and Mobile.
            // On mobile, since we skipped the 'mouseenter' logic above, this fires INSTANTLY.
            var arrow = group.querySelector('.starke-link-arrow');
            if(arrow) {
                arrow.addEventListener('click', function(e) {
                    e.preventDefault(); 
                    e.stopPropagation();
                    
                    // Clear any pending hover timer just in case
                    if (hoverTimer) clearTimeout(hoverTimer);
                    
                    group.classList.toggle('starke-menu-open');
                });
            }
        });

        if(overlay) overlay.addEventListener('click', function(e) { e.preventDefault(); closeHamburgerDrawer(); });
        if(closeBtn) closeBtn.addEventListener('click', function(e) { e.preventDefault(); closeHamburgerDrawer(); });
        document.addEventListener('keyup', function(e) { if (e.key === "Escape") { closeHamburgerDrawer(); } });
        
        // --- THE FIX: Close drawer when any sub-menu link is clicked ---
        var subLinks = document.querySelectorAll('.starke-drawer-link.sub-link');
        subLinks.forEach(function(link) {
            link.addEventListener('click', function() {
                // We do NOT use e.preventDefault() here because we still want 
                // the browser to follow the link to the new tab/hash!
                closeHamburgerDrawer();
            });
        });

    });
    </script>
    <?php
}

/* =========================================
   STARKE EYEBROW BAR (Top Notification)
   ========================================= */
add_action( 'wp_body_open', 'starke_render_eyebrow_bar', 5 );

function starke_render_eyebrow_bar() {
    // CONDITIONAL HIDE: Do not show on Checkout or Single Product pages
    if ( is_product() || ( is_checkout() && ! is_order_received_page() ) ) {
        return;
    }
    ?>
    <div id="starke-eyebrow-bar" class="starke-eyebrow-bar">
        <div class="starke-eyebrow-container">
            
            <a href="tel:6107591753" class="starke-eyebrow-phone">
                <i class="fas fa-phone-alt"></i> <span>(610) 759-1753</span>
            </a>

            <a href="https://www.starkemillwork.com/product/4304" class="starke-eyebrow-btn">
                <i class="fas fa-cube"></i> <span>Try 3D Configurator</span>
            </a>

        </div>
    </div>
    <?php
}

/* =========================================
   STARKE STICKY HEADER STATE DETECTOR (Fix: Checks on Load)
   ========================================= */
add_action( 'wp_footer', 'starke_sticky_header_script', 999 );

function starke_sticky_header_script() {
    if ( is_product() || ( is_checkout() && ! is_order_received_page() ) ) {
        return;
    }
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var header = document.querySelector('header.wp-block-template-part');
        var headerGroup = document.querySelector('.header-group');
        
        // --- CONFIGURATION ---
        var triggerPoint = 40; 
        var releasePoint = 20; 
        
        // TRACKING: Store the initial width.
        var lastWindowWidth = window.innerWidth;

        // 1. MEASUREMENT FUNCTION (Calculates Natural Height)
        function updateHeaderHeight() {
            if (!headerGroup || !header) return;
            
            // SAFETY: If the header is currently shrunk (stuck), DO NOT measure.
            if (header.classList.contains('starke-header-stuck')) {
                return;
            }
            
            // Measure the exact visible box on screen
            var rect = headerGroup.getBoundingClientRect();
            
            // Send this exact number to CSS
            document.documentElement.style.setProperty('--starke-header-natural-height', rect.height + 'px');
        }

        // 2. SCROLL STATE FUNCTION (The Logic)
        function checkScrollState() {
            var currentScroll = window.scrollY;

            if (currentScroll > triggerPoint) {
                if (!header.classList.contains('starke-header-stuck')) {
                    header.classList.add('starke-header-stuck');
                }
            } 
            else if (currentScroll < releasePoint) {
                if (header.classList.contains('starke-header-stuck')) {
                    header.classList.remove('starke-header-stuck');
                    
                    // Optional: Remeasure when returning to top
                    // setTimeout(updateHeaderHeight, 350); 
                }
            }
        }

        // 3. INITIALIZATION
        if (header) {
            // A. Measure Height (Load & Resize)
            updateHeaderHeight(); 
            window.addEventListener('load', updateHeaderHeight); 
            
            window.addEventListener('resize', function() {
                var currentWidth = window.innerWidth;
                if (currentWidth === lastWindowWidth) return; // Ignore mobile bar resize
                lastWindowWidth = currentWidth;
                updateHeaderHeight();
            });

            // B. Check Scroll (Load & Scroll)
            window.addEventListener('scroll', checkScrollState, { passive: true });
            
            // THE FIX: Run this immediately on load to catch refresh position
            checkScrollState(); 
        }
    });
    </script>
    <?php
}

/**
 * STARKE ARCHITECT DRAWER LOGIC
 * Handles auto-opening the drawer and showing the 'Access Denied' message
 * if a user tries to download a DXF without permission.
 */
add_action( 'wp_footer', 'starke_architect_drawer_script', 999 );

function starke_architect_drawer_script() {
    ?>
    <script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function() {
        
        const dxfNotice = document.getElementById('starke-dxf-denial-msg');
        
        // 1. LISTEN FOR CLICKS on "DXF Download" buttons (The ones that trigger the drawer)
        document.body.addEventListener('click', function(e) {
            // Check for our generic trigger class
            if (e.target.matches('.starke-trigger-login-drawer') || e.target.closest('.starke-trigger-login-drawer')) {
                
                var triggerEl = e.target.matches('.starke-trigger-login-drawer') ? e.target : e.target.closest('.starke-trigger-login-drawer');

                // --- FIX: EXCLUSION CHECK ---
                // If this is the "Login to See Pricing" button (which has 'login-prompt-button'),
                // we SKIP all Architect checks. We only run the check if this class is MISSING.
                if ( ! triggerEl.classList.contains('login-prompt-button') ) {

                    // NOW we check if it looks like a DXF button (by Class OR Text "DXF")
                    var btnText = (triggerEl.innerText || triggerEl.textContent).trim().toUpperCase();
                    
                    if ( triggerEl.matches('.dxfDownloadButton') || triggerEl.closest('.dxfDownloadButton') || btnText.includes('DXF') ) {
                    
                        // A. If User is LOGGED OUT -> Set Flag
                        if ( typeof window.starkeUserLoggedIn === 'undefined' || !window.starkeUserLoggedIn ) {
                            sessionStorage.setItem('starke_wanted_dxf', '1');
                        }
                        // B. If User is LOGGED IN -> Show "Access Denied" Notice
                        else {
                            if(dxfNotice) {
                                dxfNotice.style.display = 'block';
                            }
                        }
                    }
                }
                
                // Trigger the Drawer Open (This happens for ALL buttons, including the login prompt)
                const headerLoginBtn = document.querySelector('header a[href*="my-account"]');
                if (headerLoginBtn) headerLoginBtn.click();
            }
        });

        // 2. CHECK ON PAGE LOAD (Auto-Reopen Feature)
        // Did we just log in after trying to click a DXF?
        if ( sessionStorage.getItem('starke_wanted_dxf') === '1' ) {
            
            // If we are now logged in
            if ( typeof window.starkeUserLoggedIn !== 'undefined' && window.starkeUserLoggedIn ) {
                
                // Double check they still don't have access (to avoid showing it to approved architects)
                if ( typeof window.starkeUserIsArchitect !== 'undefined' && !window.starkeUserIsArchitect ) {
                    
                    // A. Open the Drawer (with a slight delay to let elements settle)
                    const headerLoginBtn = document.querySelector('header a[href*="my-account"]');
                    if (headerLoginBtn) {
                        setTimeout(() => headerLoginBtn.click(), 500); 
                    }

                    // B. Show the Gold Notice
                    if(dxfNotice) {
                        dxfNotice.style.display = 'block';
                    }
                }
            }
            
            // Clear the flag so it doesn't happen again
            sessionStorage.removeItem('starke_wanted_dxf');
        }
    });
    </script>
    <?php
}

/**
 * ===============================================================
 * STARKE BOT PROTECTION (Honeypot + Time Trap)
 * ===============================================================
 */

/**
 * 1. OUTPUT HIDDEN FIELDS
 * - 'starke_hp_website': A honeypot field hidden from humans. If filled, it's a bot.
 * - 'starke_reg_ts': A timestamp. If submitted too fast, it's a bot.
 */
function starke_print_bot_protection_fields() {
    // Avoid printing twice if multiple hooks fire on the same page
    if ( defined( 'STARKE_BOT_FIELDS_PRINTED' ) ) {
        return;
    }
    define( 'STARKE_BOT_FIELDS_PRINTED', true );

    ?>
    <div style="position: absolute; left: -9999px; opacity: 0;">
        <label for="starke_hp_website">Website</label>
        <input type="text" name="starke_hp_website" id="starke_hp_website" tabindex="-1" autocomplete="off" value="">
        <input type="hidden" name="starke_reg_ts" value="<?php echo time(); ?>">
    </div>
    <?php
}

// Hook 1: Standard WooCommerce Registration Form (My Account)
add_action( 'woocommerce_register_form', 'starke_print_bot_protection_fields' );

// Hook 2: Checkout Page Registration (Fixes "Missing TS" during checkout)
add_action( 'woocommerce_checkout_after_customer_details', 'starke_print_bot_protection_fields' );

// Hook 3: Backup for specific themes (Register Form Start)
// Some themes overwrite the main hook; this ensures we catch it at the start too.
add_action( 'woocommerce_register_form_start', 'starke_print_bot_protection_fields' );


/**
 * 2. VALIDATION LOGIC (Strict Mode)
 * Checks both the Custom Drawer, Standard Page, and Checkout submissions.
 * Logs every blocked attempt to WooCommerce > Status > Logs.
 */
add_filter( 'woocommerce_registration_errors', 'starke_validate_bot_protection', 20, 3 );

function starke_validate_bot_protection( $errors, $username, $email ) {
    
    // Get the User IP for the log
    $user_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'Unknown IP';
    
    // A. Honeypot Check (Must be empty)
    if ( ! empty( $_POST['starke_hp_website'] ) ) {
        $logger = wc_get_logger();
        $logger->info( "BLOCKED [Honeypot]: Bot filled out the hidden field. IP: {$user_ip} | Email: {$email}", array( 'source' => 'starke-bot-protection' ) );

        $errors->add( 'starke_bot', __( 'Error: Suspicious activity detected.', 'woocommerce' ) );
        return $errors;
    }

    // B. Time Trap Check (Must be > 2 seconds)
    if ( isset( $_POST['starke_reg_ts'] ) ) {
        $submission_time = intval( $_POST['starke_reg_ts'] );
        $current_time    = time();
        $time_diff       = $current_time - $submission_time;

        if ( $time_diff < 2 ) {
            $logger = wc_get_logger();
            $logger->info( "BLOCKED [Time Trap]: Submitted in {$time_diff} seconds (Too Fast). IP: {$user_ip} | Email: {$email}", array( 'source' => 'starke-bot-protection' ) );

            $errors->add( 'starke_too_fast', __( 'Error: You are submitting too fast. Please wait a moment.', 'woocommerce' ) );
        }
    } else {
        // C. Missing Timestamp (Strict Security: BLOCK the request)
        // If this error triggers, it means the form HTML is missing the hidden fields.
        // Likely Causes: Caching, Theme Overrides, or submitting via a non-standard form.
        
        $logger = wc_get_logger();
        $logger->warning( "BLOCKED [Missing TS]: Direct POST attack (No timestamp found). IP: {$user_ip} | Email: {$email}", array( 'source' => 'starke-bot-protection' ) );

        $errors->add( 'starke_missing_ts', __( 'Error: Validation failed. Please refresh the page.', 'woocommerce' ) );
    }

    return $errors;
}

/**
 * STARKE FIX: Force WS Form to display Turnstile server-side errors
 * Overrides the wsf-valid class conflict that hides expired token messages.
 */
add_action( 'wp_footer', 'starke_fix_wsform_turnstile_error', 999 );

function starke_fix_wsform_turnstile_error() {
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Listen for any background AJAX requests completing (WS Form uses AJAX to submit)
        $(document).ajaxComplete(function() {
            
            // Check the Turnstile feedback container
            $('.wsf-field-wrapper[data-type="turnstile"] .wsf-invalid-feedback').each(function() {
                
                // If the server injected the Captcha Error text, force it to become visible
                if ($(this).text().includes('Captcha Error')) {
                    $(this).attr('aria-hidden', 'false').css({
                        'display': 'block',
                        'color': '#cc1818',
                        'margin-top': '8px',
                        'text-align': 'center',
                        'font-weight': '600'
                    });
                }
            });
            
        });
    });
    </script>
    <?php
}

/**
 * FORCE REDIRECT: Kick limited users off the Checkout page entirely.
 */
add_action( 'template_redirect', 'starke_block_checkout_page_for_limited_accounts' );

function starke_block_checkout_page_for_limited_accounts() {
    // Check if we are on the checkout page (but NOT the "Order Received" thank you page)
    if ( is_checkout() && ! is_order_received_page() ) {
        
        // If the user is limited, redirect them to the My Account page
        if ( function_exists( 'starke_is_account_limited' ) && starke_is_account_limited() ) {
            
            // Optional: Add a standard WooCommerce notice so they know why they were redirected
            wc_add_notice( 'Your account currently has limited access. Checkout is disabled.', 'error' );
            
            wp_redirect( wc_get_page_permalink( 'shop' ) );
            exit;
        }
    }
}

/**
 * ===============================================================
 * STARKE: FIX TABLE ROW ALTERNATING COLORS (Order Confirmation & Pay)
 * ===============================================================
 * Prevents color collisions between tbody and tfoot.
 */
add_action( 'wp_footer', 'starke_fix_order_table_striping_script', 999 );

function starke_fix_order_table_striping_script() {
    // Only run this on the Checkout (Order Confirmation/Pay) and My Account pages
    if ( function_exists('is_checkout') && ( is_checkout() || is_account_page() ) ) {
        ?>
        <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            function applyStrictZebraStriping() {
                // Target the modern block table AND classic tables to be perfectly safe
                const tables = document.querySelectorAll('.wc-block-order-confirmation-totals__table, .woocommerce-table--order-details, .shop_table');
                
                tables.forEach(table => {
                    // Select all rows across the body and footer as one continuous list
                    const rows = table.querySelectorAll('tbody tr, tfoot tr');
                    
                    rows.forEach((row, index) => {
                        // Clean up
                        row.classList.remove('starke-row-white', 'starke-row-gray');
                        
                        // Because the header is white, we want the first data row (index 0) to be gray
                        if (index % 2 === 0) {
                            row.classList.add('starke-row-gray');
                        } else {
                            row.classList.add('starke-row-white');
                        }
                    });
                });
            }

            // Run immediately on page load
            applyStrictZebraStriping();

            // THE FIX: Watch the universal '.woocommerce' wrapper or 'document.body' 
            // This ensures it runs on Balance Payment pages where the specific wrapper might be missing.
            const container = document.querySelector('.woocommerce') || document.body;
            if (container) {
                const observer = new MutationObserver(() => applyStrictZebraStriping());
                observer.observe(container, { childList: true, subtree: true });
            }
        });
        </script>
        <?php
    }
}

/**
 * Redirect logged-out users on the Order Pay page to the My Account login page.
 * Bypasses the un-stylable WooCommerce Checkout Block login form.
 */
add_action( 'template_redirect', 'starke_redirect_logged_out_order_pay' );

function starke_redirect_logged_out_order_pay() {
    // 1. Check if we are on the Order Pay endpoint and the user is NOT logged in
    if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) && ! is_user_logged_in() ) {
        
        // 2. Reconstruct the exact URL they are trying to reach (including the order key)
        global $wp;
        $current_url = home_url( add_query_arg( array(), $wp->request ) );
        if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
            $current_url .= '?' . $_SERVER['QUERY_STRING'];
        }

        // 3. Get the My Account URL and attach the current URL as the 'redirect_to' parameter
        $my_account_url = wc_get_page_permalink( 'myaccount' );
        $login_url      = add_query_arg( 'redirect_to', urlencode( $current_url ), $my_account_url );

        // 4. Send them to your custom styled login page
        wp_safe_redirect( $login_url );
        exit;
    }
}

/**
 * Redirect logged-out users on the Order Confirmation (Order Received) page to the homepage.
 */
add_action( 'template_redirect', 'starke_redirect_logged_out_order_confirmation' );

function starke_redirect_logged_out_order_confirmation() {
    // Check if we are on the order confirmation endpoint and the user is NOT logged in
    if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) && ! is_user_logged_in() ) {
        
        // Redirect to the homepage
        wp_safe_redirect( home_url() );
        exit;
    }
}

add_action( 'wp_ajax_nopriv_starke_check_login_email', 'starke_ajax_check_login_email' );
add_action( 'wp_ajax_starke_check_login_email', 'starke_ajax_check_login_email' );

function starke_ajax_check_login_email() {
    check_ajax_referer( 'starke-login-check', 'security' );
    
    $email = sanitize_email( $_POST['email'] );
    if ( ! $email || ! is_email( $email ) ) {
        wp_send_json_success( ['action' => 'normal_login'] );
    }

    $user = get_user_by( 'email', $email );
    if ( ! $user ) {
        wp_send_json_success( ['action' => 'normal_login'] );
    }

    $password_set = get_user_meta( $user->ID, '_starke_password_set_done', true );
    if ( ! empty( $password_set ) ) {
        wp_send_json_success( ['action' => 'normal_login'] );
    }

    // Email is valid, user exists, and password is NOT set.
    $reset_key = get_password_reset_key( $user );
    if ( is_wp_error( $reset_key ) ) {
        wp_send_json_success( ['action' => 'normal_login'] ); // Failsafe
    }

    // Save the quote ID they are viewing so auto-resume works after setup
    if ( WC()->session && WC()->session->get( 'editing_original_order_id' ) ) {
        update_user_meta( $user->ID, '_starke_quote_link_for_redirect', WC()->session->get( 'editing_original_order_id' ) );
    }

    // Build the secure reset URL
    $reset_url = wc_get_endpoint_url( 'lost-password', '', wc_get_page_permalink( 'myaccount' ) );
    $reset_url = add_query_arg( array(
        'key'   => $reset_key,
        'login' => rawurlencode( $user->user_login )
    ), $reset_url );

    wp_send_json_success( [
        'action'       => 'needs_setup',
        'redirect_url' => $reset_url
    ]);
}

add_action( 'wp_footer', 'starke_login_drawer_smart_check_js' );
function starke_login_drawer_smart_check_js() {
    // No need to run this for logged-in users
    if ( is_user_logged_in() ) return;
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        var debounceTimer;
        var securityNonce = '<?php echo wp_create_nonce("starke-login-check"); ?>';
        var ajaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';

        // --- Reset "Loading" state if user clicks the browser Back button ---
        $(window).on('pageshow', function(e) {
            if (e.originalEvent.persisted) {
                $('.starke-setup-mode').text('Create My Password').css('opacity', '1');
            }
        });

        // Target BOTH the Drawer form and the My Account page form based on your custom classes
        var formsToCheck = [
            $('#starke-login-form-element'), // The Drawer
            $('.starke-page-form.login')     // The My Account Page
        ];

        formsToCheck.forEach(function($form) {
            if ($form.length === 0) return;

            // Target the specific inputs dynamically within each form
            var $emailInput   = $form.find('input[name="username"]');
            var $passInput    = $form.find('input[name="password"]');
            
            // THE FIX: The drawer has a parent '.starke-form-row' that uses flexbox. 
            // Sliding a flex-child causes horizontal shrinking. We target the row instead to force a vertical slide.
            var $passWrap = $passInput.closest('.starke-form-row');
            if ($passWrap.length === 0) {
                $passWrap = $passInput.closest('.starke-float-wrapper');
            }

            var $loginActions = $form.find('.starke-login-actions'); // TARGET THE CHECKBOX/LINK ROW
            var $submitBtn    = $form.find('button[name="login"], button#starke-manual-login-btn');
            
            var originalBtnText   = $submitBtn.text().trim() || 'Log In';
            var storedRedirectUrl = '';
            var isSetupMode       = false;

            // 1. Inject the helper message
            var $helperText = $('<div class="starke-needs-password-msg" style="display:none; height:56px; margin-bottom:5px; width:100%;"><div style="display:flex; height:100%; align-items:center; justify-content:center;"><p style="color:#6431F6; font-weight:500; font-size:0.95em; line-height:1.4; text-align:center; margin:0; padding:0 10px;">Your account is ready! You just need to create your password.</p></div></div>');
            $passWrap.before($helperText);

            // 2. Email Input Check (Live AJAX)
            $emailInput.on('input keyup', function() {
                var emailVal = $(this).val().trim();
                clearTimeout(debounceTimer);

                if (emailVal.indexOf('@') === -1 || emailVal.indexOf('.') === -1) {
                    resetUI();
                    return;
                }

                // Fire AJAX after user pauses typing for 250ms
                debounceTimer = setTimeout(function() {
                    $.ajax({
                        type: 'POST',
                        url: ajaxUrl,
                        data: {
                            action: 'starke_check_login_email',
                            email: emailVal,
                            security: securityNonce
                        },
                        success: function(response) {
                            if (response.success && response.data.action === 'needs_setup') {
                                storedRedirectUrl = response.data.redirect_url;
                                isSetupMode       = true;
                                
                                // Morph the UI: slide up password and the extra links/checkbox
                                $passWrap.slideUp(200);
                                $loginActions.slideUp(200);
                                $helperText.slideDown(200);
                                
                                $submitBtn.text('Create My Password').addClass('starke-setup-mode');
                            } else {
                                resetUI();
                            }
                        }
                    });
                }, 200); // UPDATED DEBOUNCE
            });

            // Helper to put the UI back to normal if they change the email back
            function resetUI() {
                if (!isSetupMode) return;
                storedRedirectUrl = '';
                isSetupMode       = false;
                
                // Restore password and the extra links/checkbox
                $passWrap.slideDown(200);
                $loginActions.slideDown(200);
                $helperText.slideUp(200);

                $submitBtn.text(originalBtnText).removeClass('starke-setup-mode');
            }

            // 3. Intercept Button Click
            $submitBtn.on('click', function(e) {
                if (isSetupMode && storedRedirectUrl !== '') {
                    e.preventDefault();
                    // This specifically stops your existing custom drawer JS from forcing a standard form submit
                    e.stopImmediatePropagation(); 
                    
                    $submitBtn.text('Loading...').css('opacity', '0.7');
                    window.location.href = storedRedirectUrl;
                }
            });
        });
    });
    </script>
    <?php
}

function add_homepage_meta_description() {
    // Only target the front page so you don't duplicate this tag across your entire site
    if ( is_front_page() || is_home() ) {
        $description = "Bring us your most ambitious vision. Starke Millwork manufactures high-end, fully custom door units of absolutely any complexity. From unique interior architectural slabs to complete, engineered exterior entry systems, we build exactly what you dream of.";
        echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
    }
}
// Using priority 1 ensures the meta tag loads early in the head section
add_action( 'wp_head', 'add_homepage_meta_description', 1 );

/**
 * FORCE REDIRECT: Route all product category archive pages directly back to the main shop catalog.
 * This captures old incoming category URLs, typed inputs, and multi-level breadcrumb links.
 */
add_action( 'template_redirect', 'starke_redirect_product_categories_to_shop' );
function starke_redirect_product_categories_to_shop() {
    // Check if the current request is an internal WooCommerce product category archive view
    if ( is_product_category() ) {
        
        // Securely pull the official shop page link and execute a safe redirect
        wp_safe_redirect( wc_get_page_permalink( 'shop' ) );
        exit;
    }
}