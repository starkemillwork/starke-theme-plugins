<?php
/**
 * Reset Password Form (Overrides WooCommerce default)
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Re-use the exact same CSS as the lost password file
do_action( 'woocommerce_before_reset_password_form' );
?>

<style>
    .starke-login-page-wrapper {
        --target-height: 56px; --target-font-size: 16px; --target-padding-top: 22px; --target-padding-bottom: 8px;
        --target-padding-sides: 12px; --target-label-color: rgba(18, 18, 18, 0.7); --target-border-color: #000000;
        --target-border-radius: 4px; font-family: "Inter", sans-serif;
    }
    .starke-drawer-form {
        position: relative; border-radius: 8px !important; box-shadow: 0px 0px 15px rgba(0, 0, 0, 0.2); 
        padding: 0; overflow: hidden; background: #fff; margin: 0 !important;
    }
    .starke-login-page-wrapper .starke-float-wrapper { position: relative; margin-bottom: 20px; width: 100%; }
    .starke-login-page-wrapper .starke-input {
        box-sizing: border-box !important; width: 100% !important; min-height: var(--target-height) !important;
        height: var(--target-height) !important; font-size: var(--target-font-size) !important; font-family: "Inter", sans-serif !important;
        padding-top: var(--target-padding-top) !important; padding-bottom: var(--target-padding-bottom) !important;
        padding-left: var(--target-padding-sides) !important; padding-right: var(--target-padding-sides) !important;
        border: 1px solid #000000 !important; border-radius: var(--target-border-radius) !important;
        background-color: #ffffff !important; color: #000000 !important; outline: none !important; box-shadow: none !important;
    }
    .starke-login-page-wrapper .starke-float-wrapper label {
        position: absolute; left: var(--target-padding-sides); top: 19px; font-size: var(--target-font-size);
        font-family: "Inter", sans-serif; font-weight: 400; color: var(--target-label-color); pointer-events: none;
        transition: all 0.2s ease; margin: 0; line-height: 1; z-index: 10;
    }
    .starke-login-page-wrapper .starke-input:focus + label,
    .starke-login-page-wrapper .starke-input:not(:placeholder-shown) + label {
        top: 6px; transform: scale(0.75); transform-origin: left top; color: rgba(18, 18, 18, 0.6);
    }
    .starke-login-page-wrapper button[type="submit"] {
        background-color: #6431F6 !important; color: #fff !important; border: none !important; border-radius: 5px !important;
        font-weight: 600 !important; text-transform: uppercase !important; cursor: pointer !important; transition: all 0.2s ease !important;
        box-shadow: 4px 4px 7px 0 rgba(0, 0, 0, .2) !important;
    }
    .starke-login-page-wrapper button[type="submit"]:hover { background-color: #4f26c9 !important; transform: translateY(-2px); }
    .starke-login-page-wrapper button[type="submit"]:disabled {
        opacity: 0.5 !important; cursor: not-allowed !important; box-shadow: none !important; transform: none !important;
    }
</style>

<div class="starke-login-page-wrapper" style="width: 500px !important; max-width: 95% !important; margin: 0 auto; padding: 40px 0;">
    <div class="starke-drawer-form">
        <div style="padding: 40px 30px;">
            
            <h2 id="starke-reset-heading" style="text-align:center; font-family:'Inter', sans-serif; margin-bottom: 25px; font-size: 1.5rem; margin-top: 0; opacity: 0;">
                Create New Password
            </h2>
            
            <script>
                (function() {
                    var isNewAccount = true;
                    if ((window.location.search || '').indexOf('id=') > -1 || (window.location.hash || '').indexOf('reset') > -1) {
                        isNewAccount = false;
                    } 
                    window.starkeIsNewAccount = isNewAccount;
                    
                    var heading = document.getElementById('starke-reset-heading');
                    if (heading) {
                        heading.innerText = isNewAccount ? 'Create New Password' : 'Reset Your Password';
                        heading.style.opacity = '1';
                    }
                })();
            </script>
            
            <form method="post" class="woocommerce-ResetPassword lost_reset_password starke-page-form">
				<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; min-height: 24px;">
                    <div id="starke-generated-alert" style="color: #6431F6; font-size: 0.85rem; font-weight: 500; font-family: 'Inter', sans-serif; opacity: 0; transition: opacity 0.2s ease; pointer-events: none;">
                        Password generated! Please copy it.
                    </div>
                    <button type="button" id="starke-generate-pw" style="background: transparent; border: none; color: #6431F6; font-size: 0.85rem; font-weight: 600; font-family: 'Inter', sans-serif; cursor: pointer; padding: 0; text-transform: uppercase; transition: all 0.2s ease;">
                        <i class="fas fa-magic"></i> Generate Password
                    </button>
                </div>

                <div class="starke-float-wrapper">
                    <input type="password" class="starke-input" name="password_1" id="password_1" autocomplete="new-password" placeholder=" " required />
                    <label for="password_1">New password <span style="color:#cc1818;">*</span></label>
                </div>

                <div class="starke-float-wrapper">
                    <input type="password" class="starke-input" name="password_2" id="password_2" autocomplete="new-password" placeholder=" " required />
                    <label for="password_2">Confirm new password <span style="color:#cc1818;">*</span></label>
                </div>

                <div id="starke-page-password-strength" class="woocommerce-password-strength" style="display:none; text-align:center; margin-bottom:10px; font-weight:600; font-family: 'Inter', sans-serif;" aria-live="polite"></div>
                <div id="starke-page-match-error" class="starke-password-error" style="display:none; color: #cc1818; text-align: center; margin-bottom: 10px; font-family: 'Inter', sans-serif; font-size: 0.9rem; font-weight: 600;">
                    <i class="fas fa-exclamation-triangle"></i> PASSWORDS DO NOT MATCH
                </div>
                <small id="starke-page-password-hint" class="woocommerce-password-hint" style="display:none; margin-bottom: 20px; text-align: center; color: #666; font-family: 'Inter', sans-serif;"></small>

                <input type="hidden" name="reset_key" value="<?php echo esc_attr( $args['key'] ); ?>" />
                <input type="hidden" name="reset_login" value="<?php echo esc_attr( $args['login'] ); ?>" />

                <div class="clear"></div>
                <?php do_action( 'woocommerce_resetpassword_form' ); ?>

                <input type="hidden" name="wc_reset_password" value="true" />
                <?php wp_nonce_field( 'reset_password', 'woocommerce-reset-password-nonce' ); ?>
                
                <button type="submit" id="starke-reset-btn" class="woocommerce-button button wp-element-button" value="Save" style="margin-top: 10px; display: block; padding: 14px 40px; width: 100%;" disabled>ENTER PASSWORD</button>
            </form>
        </div>
    </div>
</div>

<?php do_action( 'woocommerce_after_reset_password_form' ); ?>

<?php
// Inject custom validation JS
add_action( 'wp_footer', function() {
    $hint_text = wp_strip_all_tags( wp_get_password_hint() );
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        var $pass1         = $('#password_1');
        var $pass2         = $('#password_2');
        var $strengthMeter = $('#starke-page-password-strength');
        var $matchError    = $('#starke-page-match-error');
        var $hintText      = $('#starke-page-password-hint');
        var $registerBtn   = $('#starke-reset-btn');
        var hintMessage    = "<?php echo esc_js($hint_text); ?>";
		var $generateBtn   = $('#starke-generate-pw');
        
        // NEW: Pull the text from the JS variable we created at the top
        var successBtnText = window.starkeIsNewAccount ? 'CREATE PASSWORD' : 'RESET PASSWORD';

        var minStrength = 3;
        if ( typeof wc_password_strength_meter_params !== 'undefined' ) {
            minStrength = parseInt( wc_password_strength_meter_params.min_password_strength );
        }

        function getStarHTML(filledCount) {
            var html = '<div class="starke-password-stars">';
            for(var i = 1; i <= 5; i++) {
                if (i <= filledCount) {
                    html += '<i class="fas fa-star" style="color:#6431F6;"></i>';
                } else {
                    html += '<i class="far fa-star" style="color:#ccc;"></i>';
                }
            }
            html += '</div>';
            return html;
        }

        function validatePasswordReset() {
            if (!$pass1.length) return;
            
            var val1 = $pass1.val();
            var val2 = $pass2.val();
            var strengthScore = 0; 
            var strengthLabel = '';

            if ( val1 !== '' && typeof wp !== 'undefined' && typeof wp.passwordStrength !== 'undefined' ) {
                strengthScore = wp.passwordStrength.meter( val1, wp.passwordStrength.userInputDisallowedList(), val1 );
                $strengthMeter.show().removeClass('short bad good strong');
                
                var starCount = 0;
                switch ( strengthScore ) {
                    case 0: $strengthMeter.addClass('short'); strengthLabel = 'Too Short'; starCount = 1; break;
                    case 1: $strengthMeter.addClass('bad'); strengthLabel = 'Weak'; starCount = 2; break;
                    case 2: $strengthMeter.addClass('bad'); strengthLabel = 'Medium'; starCount = 3; break;
                    case 3: $strengthMeter.addClass('good'); strengthLabel = 'Good'; starCount = 4; break;
                    case 4: $strengthMeter.addClass('strong'); strengthLabel = 'Strong'; starCount = 5; break;
                }
                
                $strengthMeter.html( strengthLabel + getStarHTML(starCount) );
                if ( strengthScore < minStrength ) { $hintText.text(hintMessage).show(); } else { $hintText.hide(); }
            } else {
                $strengthMeter.hide(); $hintText.hide();
            }

            var isMatch = (val1 === val2 && val1 !== '');
            if ( val2 !== '' && !isMatch ) { $matchError.show(); } else { $matchError.hide(); }

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
            
            // Apply the dynamic text on success
            $registerBtn.prop('disabled', false).css('opacity', '1').text(successBtnText);
        }

		// --- NEW: PASSWORD GENERATOR LOGIC ---
        $generateBtn.on('click', function(e) {
            e.preventDefault();
            
            // 1. Create a guaranteed-strong 16 character password
            var charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+~";
            var newPass = "";
            newPass += "ABCDEFGHIJKLMNOPQRSTUVWXYZ".charAt(Math.floor(Math.random() * 26)); // Force 1 Uppercase
            newPass += "abcdefghijklmnopqrstuvwxyz".charAt(Math.floor(Math.random() * 26)); // Force 1 Lowercase
            newPass += "0123456789".charAt(Math.floor(Math.random() * 10)); // Force 1 Number
            newPass += "!@#$%^&*()_+~".charAt(Math.floor(Math.random() * 13)); // Force 1 Symbol

            // Fill the remaining 12 characters randomly
            for (var i = 0; i < 12; i++) {
                newPass += charset.charAt(Math.floor(Math.random() * charset.length));
            }
            
            // Shuffle the generated characters so the required ones aren't always first
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

            // 4. Trigger validation to update the strength meter and enable the save button
            validatePasswordReset();
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

        $pass1.on('keyup change input', validatePasswordReset);
        $pass2.on('keyup change input', validatePasswordReset);
        
        validatePasswordReset();
    });
    </script>
    <?php
}, 999 );
?>