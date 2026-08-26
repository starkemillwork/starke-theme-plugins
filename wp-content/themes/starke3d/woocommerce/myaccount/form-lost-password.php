<?php
/**
 * Lost password form (Overrides WooCommerce default)
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

do_action( 'woocommerce_before_lost_password_form' );
?>

<style>
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
</style>

<div class="starke-login-page-wrapper" style="width: 500px !important; max-width: 95% !important; margin: 0 auto; padding: 40px 0;">
    <div class="starke-drawer-form">
        <div style="padding: 40px 30px;">
            <h2 style="text-align:center; font-family:'Inter', sans-serif; margin-bottom: 20px; font-size: 1.5rem; margin-top: 0;">Lost Password</h2>
            
            <form method="post" class="woocommerce-ResetPassword lost_reset_password starke-page-form">
                <p style="text-align:center; color:#666; font-family:'Inter', sans-serif; margin-bottom:25px; font-size: 0.95rem;">
                    <?php echo apply_filters( 'woocommerce_lost_password_message', esc_html__( 'Lost your password? Please enter your email address. You will receive a link to create a new password via email.', 'woocommerce' ) ); ?>
                </p>

                <div class="starke-float-wrapper">
                    <input class="starke-input" type="text" name="user_login" id="user_login" autocomplete="username" placeholder=" " required />
                    <label for="user_login">Email <span style="color:#cc1818;">*</span></label>
                </div>

                <div class="clear"></div>

                <?php do_action( 'woocommerce_lostpassword_form' ); ?>

                <input type="hidden" name="wc_reset_password" value="true" />
                
                <?php wp_nonce_field( 'lost_password', 'woocommerce-lost-password-nonce' ); ?>
                
                <button type="submit" class="woocommerce-button button wp-element-button" value="Reset password" style="margin-top: 20px; display: block; padding: 14px 40px; width: 100%;">RESET PASSWORD</button>
            </form>
        </div>
    </div>
</div>

<?php do_action( 'woocommerce_after_lost_password_form' ); ?>