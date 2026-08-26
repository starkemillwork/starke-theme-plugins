<?php
/**
 * Starke Custom Login / Register Page (Tabbed) (WooCommerce template override file)
 * VERSION: FIXED CSS HEIGHT (OVERRIDES STYLE.CSS)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// 1. PHP LOG: Confirm file load
$logger = wc_get_logger();
$logger->debug( 'Starke form-login.php loaded (CSS Override Version)', array( 'source' => 'starke-login-debug' ) );

do_action( 'woocommerce_before_customer_login_form' ); ?>

<style>
    /* Scope everything to this wrapper */
    .starke-login-page-wrapper {
        --target-height: 56px;
        --target-font-size: 16px;
        --target-padding-top: 22px;
        --target-padding-bottom: 8px;
        --target-padding-sides: 12px;
        --target-label-color: rgba(18, 18, 18, 0.7);
        --target-border-color: #000000;
        --target-border-radius: 4px;
        font-family: "Inter", sans-serif;
    }

    /* --- FORM CONTAINER STABILITY (The Fix) --- */
    /* We use !important here to override the 'min-height: 0 !important' found in style.css */
    .starke-drawer-form {
        position: relative; 
        border-radius: 8px !important; 
        box-shadow: 0px 0px 15px rgba(0, 0, 0, 0.2); 
        padding: 0; 
        overflow: hidden; 
        background: #fff;
        /* Reset margins that might be inherited from the drawer style */
        margin: 0 !important;
    }

    /* --- TABS --- */
    .starke-login-tabs {
        display: flex;
        justify-content: center;
        gap: 30px;
        margin-bottom: 25px;
        border-bottom: 1px solid #eee;
    }
    .starke-tab-btn {
        background: none;
        border: none;
        padding: 15px 10px;
        font-family: 'Inter', sans-serif;
        font-size: 1.2rem;
        color: #999;
        cursor: pointer;
        position: relative;
        transition: all 0.2s ease;
    }
    .starke-tab-btn:hover { color: #6431F6; }
    .starke-tab-btn.active { color: #000; font-weight: 600; }
    .starke-tab-btn.active::after {
        content: '';
        position: absolute;
        bottom: -1px;
        left: 0;
        width: 100%;
        height: 3px;
        background-color: #000000;
    }

    /* --- INPUTS --- */
    .starke-login-page-wrapper .starke-float-wrapper {
        position: relative;
        margin-bottom: 20px;
        width: 100%;
    }
    .starke-login-page-wrapper .starke-input {
        box-sizing: border-box !important;
        width: 100% !important;
        min-height: var(--target-height) !important;
        height: var(--target-height) !important;
        font-size: var(--target-font-size) !important;
        font-family: "Inter", sans-serif !important;
        padding-top: var(--target-padding-top) !important;
        padding-bottom: var(--target-padding-bottom) !important;
        padding-left: var(--target-padding-sides) !important;
        padding-right: var(--target-padding-sides) !important;
        border: 1px solid #000000 !important;
        border-radius: var(--target-border-radius) !important;
        background-color: #ffffff !important;
        color: #000000 !important;
        outline: none !important;
        box-shadow: none !important;
    }
    
    /* Labels & Float */
    .starke-login-page-wrapper .starke-float-wrapper label {
        position: absolute;
        left: var(--target-padding-sides);
        top: 19px;
        font-size: var(--target-font-size);
        font-family: "Inter", sans-serif;
        font-weight: 400;
        color: var(--target-label-color);
        pointer-events: none;
        transition: all 0.2s ease;
        margin: 0;
        line-height: 1;
        z-index: 10;
    }
    .starke-login-page-wrapper .starke-input:focus + label,
    .starke-login-page-wrapper .starke-input:not(:placeholder-shown) + label {
        top: 6px;
        transform: scale(0.75);
        transform-origin: left top;
        color: rgba(18, 18, 18, 0.6);
    }

    /* --- CHECKBOXES (Shared) --- */
    .starke-login-page-wrapper input[type="checkbox"] {
        appearance: none !important;
        -webkit-appearance: none !important;
        width: 18px;
        height: 18px;
        background-color: #ffffff;
        border: 1px solid #000000;
        border-radius: 4px;
        cursor: pointer;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0; 
        flex-shrink: 0; 
    }
    .starke-login-page-wrapper input[type="checkbox"]:checked {
        background-color: #6431F6;
        border-color: #6431F6;
    }
    .starke-login-page-wrapper input[type="checkbox"]:checked::after {
        content: '';
        width: 5px;
        height: 9px;
        border: solid white;
        border-width: 0 2px 2px 0;
        transform: rotate(45deg);
        margin-bottom: 2px;
        display: block;
    }

    /* Remember Me (Centered) */
    .starke-login-page-wrapper .starke-remember-me {
        display: flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        font-size: 0.9rem;
        color: #666;
    }

    /* Architect Request (Top Aligned for multi-line text) */
    .starke-login-page-wrapper .starke-architect-request {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        cursor: pointer;
        font-size: 0.9rem;
        color: #333;
        line-height: 1.4;
    }
    
    .starke-login-page-wrapper .starke-req-text {
        margin-top: -2px; 
    }

    /* --- BUTTONS --- */
    .starke-login-page-wrapper button[type="submit"] {
        background-color: #6431F6 !important;
        color: #fff !important;
        border: none !important;
        border-radius: 5px !important;
        font-weight: 600 !important;
        text-transform: uppercase !important;
        cursor: pointer !important;
        transition: all 0.2s ease !important;
        box-shadow: 4px 4px 7px 0 rgba(0, 0, 0, .2) !important;
    }
    .starke-login-page-wrapper button[type="submit"]:hover {
        background-color: #4f26c9 !important;
        transform: translateY(-2px);
    }
    .starke-login-page-wrapper button[type="submit"]:disabled {
        opacity: 0.5 !important;
        cursor: not-allowed !important;
        box-shadow: none !important;
        transform: none !important;
    }
    
    .starke-login-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 15px;
    }
    .starke-lost-pw { color: #6431F6; text-decoration: none; font-weight: 500; font-size: 0.9rem; }
    .starke-login-page-wrapper .required { color: #cc1818; }

    .starke-drawer-form .starke-page-form.login {
        margin: 0;
        border: none;
    }

    .starke-drawer-form .starke-page-form.register {
        margin: 0;
        border: none;
    }
</style>

<div class="starke-login-page-wrapper" style="width: 500px !important; max-width: 95% !important; margin: 0 auto; padding: 40px 0;">

    <?php if ( wc_notice_count( 'error' ) > 0 ) : ?>
        <div class="starke-wc-errors" style="margin-bottom: 20px;">
            <?php wc_print_notices(); ?>
        </div>
    <?php endif; ?>

    <div class="starke-drawer-form">

        <div class="starke-login-tabs" style="margin: 25px 50px;">
            <button class="starke-tab-btn active" data-target="starke-page-tab-login">Log In</button>
            <button class="starke-tab-btn" data-target="starke-page-tab-register">Register</button>
        </div>

        <div class="starke-login-content-area" style="padding: 0 0 25px 0;">

            <div id="starke-page-tab-login" class="starke-page-tab-content" style="display:block; padding: 5px 30px 20px 30px;">
                <form class="starke-page-form login" method="post">
                    <?php do_action( 'woocommerce_login_form_start' ); ?>
                    
                    <div class="starke-float-wrapper">
                        <input type="text" class="starke-input" name="username" id="starke_page_username" autocomplete="username" value="<?php echo ( ! empty( $_POST['username'] ) ) ? esc_attr( wp_unslash( $_POST['username'] ) ) : ''; ?>" placeholder=" " required />
                        <label for="starke_page_username">Email address / Username <span class="required">*</span></label>
                    </div>

                    <div class="starke-float-wrapper">
                        <input type="password" class="starke-input" name="password" id="starke_page_password" autocomplete="current-password" placeholder=" " required />
                        <label for="starke_page_password">Password <span class="required">*</span></label>
                    </div>

                    <div class="starke-login-actions">
                        <label class="starke-remember-me">
                            <input name="rememberme" type="checkbox" id="starke_rememberme_page" value="forever" /> 
                            <span>Remember me</span>
                        </label>
                        <a href="<?php echo esc_url( wp_lostpassword_url() ); ?>" class="starke-lost-pw">Lost password?</a>
                    </div>

                    <?php do_action( 'woocommerce_login_form' ); ?>
                    <input type="hidden" name="login" value="Log in">
                    <?php wp_nonce_field( 'woocommerce-login', 'woocommerce-login-nonce' ); ?>
                    
                    <button type="submit" class="woocommerce-button button wp-element-button" name="login" value="Log in" style="margin-top: 30px; margin-left: auto; margin-right: auto; display: block; padding: 14px 60px;">LOG IN</button>
                    <?php do_action( 'woocommerce_login_form_end' ); ?>
                </form>
            </div>

            <div id="starke-page-tab-register" class="starke-page-tab-content" style="display:none; padding: 5px 30px 20px 30px;">
                <form id="starke-page-register-form" class="starke-page-form register" method="post" <?php do_action( 'woocommerce_register_form_tag' ); ?> >
                    <?php do_action( 'woocommerce_register_form_start' ); ?>

                    <div class="starke-float-wrapper">
                        <input type="email" class="starke-input" name="email" id="starke_page_reg_email" autocomplete="email" value="<?php echo ( ! empty( $_POST['email'] ) ) ? esc_attr( wp_unslash( $_POST['email'] ) ) : ''; ?>" placeholder=" " required />
                        <label for="starke_page_reg_email">Email address <span class="required">*</span></label>
                    </div>

                    <?php if ( 'no' === get_option( 'woocommerce_registration_generate_password' ) ) : ?>
                        
                        <div style="position: relative; display: flex; justify-content: flex-end; align-items: center; margin-bottom: 10px; min-height: 24px;">
                            <div id="starke-generated-alert" style="position: absolute; left: 0; color: #6431F6; font-size: 0.85rem; font-weight: 500; font-family: 'Inter', sans-serif; opacity: 0; transition: opacity 0.2s ease; pointer-events: none; white-space: nowrap;">
                                Password generated! Please copy it.
                            </div>
                            <button type="button" id="starke-generate-pw" style="background: transparent; border: none; color: #6431F6; font-size: 0.85rem; font-weight: 600; font-family: 'Inter', sans-serif; cursor: pointer; padding: 0; text-transform: uppercase; transition: all 0.2s ease; white-space: nowrap; flex-shrink: 0;">
                                <i class="fas fa-magic"></i> Generate Password
                            </button>
                        </div>

                        <div class="starke-float-wrapper">
                            <input type="password" class="starke-input" name="password" id="starke_page_reg_password" autocomplete="new-password" placeholder=" " required />
                            <label for="starke_page_reg_password">Password <span class="required">*</span></label>
                        </div>
                        
                        <div class="starke-float-wrapper">
                            <input type="password" class="starke-input" name="password_2" id="starke_page_reg_password_2" autocomplete="new-password" placeholder=" " required />
                            <label for="starke_page_reg_password_2">Confirm Password <span class="required">*</span></label>
                        </div>

                        <div id="starke-page-password-strength" class="woocommerce-password-strength" style="display:none; text-align:center; margin-bottom:10px; font-weight:600;" aria-live="polite"></div>
                        <div id="starke-page-match-error" class="starke-password-error" style="display:none;">
                            <i class="fas fa-exclamation-triangle"></i> PASSWORDS DO NOT MATCH
                        </div>
                        
                        <small id="starke-page-password-hint" class="woocommerce-password-hint" style="display:none; margin-bottom: 20px; display:block; text-align: center; color: #666;"></small>
                    <?php else : ?>
                        <p style="font-size: 0.9em; color: #667; margin-bottom: 20px;">A password will be sent to your email address.</p>
                    <?php endif; ?>

                    <div class="starke-checkbox-container" style="margin: 20px 0 20px 0;">
                        <label class="starke-architect-request" for="starke_architect_request_page">
                            <input type="checkbox" name="starke_request_architect" id="starke_architect_request_page" value="1" />
                            <span class="starke-req-text">Request Architect Access (access to .dxf files for door and molding profiles)</span>
                        </label>
                    </div>

                    <div style="margin-bottom: 20px; font-size: 0.85em; color: #667; margin-top: 20px;">
                        Your personal data will be used to support your experience throughout this website, to manage access to your account, and for other purposes described in our <a href="/policies" style="text-decoration: underline;">privacy policy</a>.
                    </div>

                    <?php wp_nonce_field( 'woocommerce-register', 'woocommerce-register-nonce' ); ?>
                    
                    <button type="submit" id="starke-page-register-btn" class="woocommerce-button button wp-element-button" name="register" value="Register" style="margin-top: 10px; margin-left: auto; margin-right: auto; display: block; padding: 14px 40px;" disabled>ENTER EMAIL ADDRESS</button>

                    <?php do_action( 'woocommerce_register_form_end' ); ?>
                </form>
            </div>
        </div>
    </div>
</div>

<?php do_action( 'woocommerce_after_customer_login_form' ); ?>

<?php
// === FOOTER INJECTION ===
// We inject the JS in the footer to ensure the DOM is ready and prevent syntax errors in the HTML body.
// We use an anonymous function hook to keep everything in this one file.
add_action( 'wp_footer', function() {
    
    // Prepare data safely using JSON_ENCODE to prevent syntax errors
    $hint_text = wp_strip_all_tags( wp_get_password_hint() );
    $script_data = array(
        'hint' => $hint_text
    );
    ?>
    <script type="text/javascript">
    // Define Data Object safely
    window.starkeLoginConfig = <?php echo json_encode( $script_data ); ?>;
    
    // Debug Log
    console.log('[STARKE] Footer Script Loaded. Config:', window.starkeLoginConfig);

    jQuery(document).ready(function($) {
        
        // --- 1. TABS ---
        $('.starke-login-page-wrapper .starke-tab-btn').on('click', function(e) {
            e.preventDefault();
            $('.starke-login-page-wrapper .starke-tab-btn').removeClass('active');
            $(this).addClass('active');
            $('.starke-login-page-wrapper .starke-page-tab-content').hide();
            var target = $(this).data('target');
            $('#' + target).fadeIn(200);
        });

        // --- 2. VALIDATION ---
        var $emailInput    = $('#starke_page_reg_email');
        var $pass1         = $('#starke_page_reg_password');
        var $pass2         = $('#starke_page_reg_password_2');
        var $strengthMeter = $('#starke-page-password-strength');
        var $matchError    = $('#starke-page-match-error');
        var $hintText      = $('#starke-page-password-hint');
        var $registerBtn   = $('#starke-page-register-btn');
        var $generateBtn   = $('#starke-generate-pw');

        // Use the safely encoded data
        var hintMessage = (window.starkeLoginConfig && window.starkeLoginConfig.hint) ? window.starkeLoginConfig.hint : '';

        // Default Strength
        var minStrength = 3;
        if ( typeof wc_password_strength_meter_params !== 'undefined' ) {
            minStrength = parseInt( wc_password_strength_meter_params.min_password_strength );
        }

        function isValidEmail(email) {
            var regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return regex.test(email);
        }

        function validatePageRegistration() {
            var emailVal = $emailInput.val().trim();
            var val1 = ($pass1.length) ? $pass1.val() : '';
            var val2 = ($pass2.length) ? $pass2.val() : '';
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
                    $hintText.text(hintMessage).show();
                } else {
                    $hintText.hide();
                }
            } else {
                $strengthMeter.hide();
                $hintText.hide();
            }

            // Match Check
            var isMatch = (val1 === val2 && val1 !== '');
            if ( val2 !== '' && !isMatch ) { 
                $matchError.show(); 
            } else { 
                $matchError.hide(); 
            }

            // Button Logic
            if ( emailVal === '' ) {
                $registerBtn.prop('disabled', true).css('opacity', '0.5').text('ENTER EMAIL ADDRESS');
                return;
            }
            if ( !isValidEmail(emailVal) ) {
                $registerBtn.prop('disabled', true).css('opacity', '0.5').text('ENTER VALID EMAIL');
                return;
            }
            
            if ( $pass1.length > 0 ) {
                // If strength lib exists, enforce it
                if ( typeof wp !== 'undefined' && typeof wp.passwordStrength !== 'undefined' ) {
                    if ( strengthScore < minStrength ) {
                        if ( val1 === '' ) {
                            $registerBtn.prop('disabled', true).css('opacity', '0.5').text('ENTER PASSWORD');
                        } else {
                            $registerBtn.prop('disabled', true).css('opacity', '0.5').text('ENTER STRONGER PASSWORD');
                        }
                        return;
                    }
                }
                if ( !isMatch ) {
                    $registerBtn.prop('disabled', true).css('opacity', '0.5').text('MAKE PASSWORDS MATCH');
                    return;
                }
            }
            
            // Success
            $registerBtn.prop('disabled', false).css('opacity', '1').text('REGISTER');
            $registerBtn.css('padding', '14px 60px'); 
        }

        // --- NEW: PASSWORD GENERATOR LOGIC ---
        if ($generateBtn.length) {
            $generateBtn.on('click', function(e) {
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

                // 2. Inject into inputs and show as text so the user can copy it
                $pass1.val(newPass).attr('type', 'text');
                $pass2.val(newPass).attr('type', 'text');
                
                // 3. Update the button and reveal the pre-rendered text
                $(this).html('<i class="fas fa-sync-alt"></i> Regenerate');
                
                // --- NEW: AUTO-COPY TO CLIPBOARD ---
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(newPass);
                    $('#starke-generated-alert').text('Copied to clipboard!').css('opacity', '1');
                } else {
                    $('#starke-generated-alert').text('Password generated! Please copy it.').css('opacity', '1');
                }

                // Uses the specific validation function for the login/register page
                validatePageRegistration();
            });

            // HIDE PASSWORD ONLY WHEN THEY START TYPING (Changed 'focus' to 'input')
            $pass1.add($pass2).on('input', function() {
                if ($pass1.attr('type') === 'text') {
                    $pass1.attr('type', 'password');
                    $pass2.attr('type', 'password');
                    $('#starke-generated-alert').css('opacity', '0'); 
                    $generateBtn.html('<i class="fas fa-magic"></i> Generate Password');
                }
            });
        }

        // Bind
        $emailInput.on('keyup change input', validatePageRegistration);
        if ($pass1.length) {
            $pass1.on('keyup change input', validatePageRegistration);
            $pass2.on('keyup change input', validatePageRegistration);
        }
        
        // Run
        validatePageRegistration();
    });
    </script>
    <?php
}, 999 );
?>