<?php
/**
 * Email Header
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; 
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=<?php bloginfo( 'charset' ); ?>" />
		<title><?php echo get_bloginfo( 'name', 'display' ); ?></title>
	</head>
	<body <?php echo is_rtl() ? 'rightmargin' : 'leftmargin'; ?>="0" marginwidth="0" topmargin="0" marginheight="0" offset="0">
		<div id="wrapper" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
			<table border="0" cellpadding="0" cellspacing="0" height="100%" width="100%">
				<tr>
					<td align="center" valign="top">
						<div id="template_header_image">
							<?php
							if ( $img = get_option( 'woocommerce_email_header_image' ) ) {
								echo '<p style="margin-top:0;"><img src="' . esc_url( $img ) . '" alt="' . get_bloginfo( 'name', 'display' ) . '" /></p>';
							}
							?>
						</div>
						<table border="0" cellpadding="0" cellspacing="0" width="600" id="template_container">
							<tr>
								<td align="center" valign="top">
									<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_header" style="background-color: #6431f6; border-bottom: 0;" bgcolor="#6431f6">
										<tr>
											<td id="header_wrapper" bgcolor="#6431f6" style="padding: 36px 48px; display: block; background-color: #6431f6;">
                                                <h1 style="font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; font-size: 30px; font-weight: 300; line-height: 150%; margin: 0; text-align: center; -webkit-text-size-adjust: none; color: #ffffff;">
                                                    <span style="color: #ffffff !important; text-shadow: 0 1px 0 #6431f6;">
                                                        <?php echo $email_heading; ?>
                                                    </span>
                                                </h1>
											</td>
										</tr>
									</table>
									</td>
							</tr>
							<tr>
								<td align="center" valign="top">
									<table border="0" cellpadding="0" cellspacing="0" width="600" id="template_body">
										<tr>
											<td valign="top" id="body_content">
												<table border="0" cellpadding="20" cellspacing="0" width="100%">
													<tr>
														<td valign="top">
															<div id="body_content_inner">