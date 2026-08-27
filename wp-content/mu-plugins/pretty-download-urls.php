<?php
/**
 * Replicates production's nginx-level rewrites for /download/, /quote-pdf/, and
 * /order-pdf/ pretty URLs, entirely inside WordPress. Cloudways (and likely other
 * managed hosts) offers no way to inject custom nginx location/rewrite blocks, only
 * a fixed set of platform settings, so the original .platform/nginx/conf.d rules
 * (rewriting to admin-ajax.php internally) can't be reproduced at the server level
 * here. This does the same job via WP's own rewrite API instead, works on any host.
 *
 * Original nginx rules (see StarkeWebsite/.platform/nginx/conf.d/elasticbeanstalk/
 * location.conf), for reference:
 *   /download/{id}/{nonce}/    -> admin-ajax.php?action=handle_dxf_download&product_id={id}&nonce={nonce}
 *   /quote-pdf/{id}/{nonce}/   -> admin-ajax.php?action=download_order_quote_pdf&order_id={id}&nonce={nonce}
 *   /order-pdf/{id}/{nonce}/   -> admin-ajax.php?action=download_order_quote_pdf&order_id={id}&nonce={nonce}
 */

add_action( 'init', function () {
	add_rewrite_rule(
		'^download/([0-9]+)/([a-zA-Z0-9]+)/?$',
		'index.php?starke_pretty_action=handle_dxf_download&starke_pretty_id=$matches[1]&starke_pretty_nonce=$matches[2]',
		'top'
	);
	add_rewrite_rule(
		'^quote-pdf/([0-9]+)/([a-zA-Z0-9]+)/?$',
		'index.php?starke_pretty_action=download_order_quote_pdf&starke_pretty_id=$matches[1]&starke_pretty_nonce=$matches[2]',
		'top'
	);
	add_rewrite_rule(
		'^order-pdf/([0-9]+)/([a-zA-Z0-9]+)/?$',
		'index.php?starke_pretty_action=download_order_quote_pdf&starke_pretty_id=$matches[1]&starke_pretty_nonce=$matches[2]',
		'top'
	);
} );

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'starke_pretty_action';
	$vars[] = 'starke_pretty_id';
	$vars[] = 'starke_pretty_nonce';
	return $vars;
} );

add_action( 'template_redirect', function () {
	$action = get_query_var( 'starke_pretty_action' );
	if ( ! $action ) {
		return;
	}

	// The two original ajax handlers expect these exact $_GET keys.
	if ( 'handle_dxf_download' === $action ) {
		$_GET['product_id'] = get_query_var( 'starke_pretty_id' );
	} else {
		$_GET['order_id'] = get_query_var( 'starke_pretty_id' );
	}
	$_GET['nonce'] = get_query_var( 'starke_pretty_nonce' );
	$_GET['action'] = $action;

	do_action( "wp_ajax_{$action}" );
	exit;
} );

/**
 * Flush rewrite rules once after this file is deployed/updated, so the new rules
 * actually take effect (WordPress caches rewrite rules in the DB otherwise).
 */
add_action( 'init', function () {
	if ( get_option( 'starke_pretty_urls_flushed_v1' ) !== 'yes' ) {
		flush_rewrite_rules();
		update_option( 'starke_pretty_urls_flushed_v1', 'yes' );
	}
}, 20 );
