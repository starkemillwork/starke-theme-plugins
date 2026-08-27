<?php
/**
 * DXF architect-access downloads are gated by a login + capability check in
 * download.php, enforced in PHP before the file is served. Offloading them to S3
 * (a public bucket path, since WP Offload Media has no built-in "downloadable
 * product" awareness of this custom feature) bypasses that check entirely, anyone
 * with the direct CloudFront/S3 URL could download them with no auth at all.
 * Keep .dxf files local-only instead, so the existing PHP-level gate stays the only
 * way to get one. See CLAUDE.md 2026-08-27 "DXF public-exposure gap" for the story.
 */
add_filter( 'as3cf_pre_upload_attachment', function ( $cancel, $id, $metadata ) {
	$file = get_attached_file( $id );
	if ( $file && strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) === 'dxf' ) {
		return true;
	}
	return $cancel;
}, 10, 3 );
