<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Allow .dxf file uploads and offload to WP Offload Media.
add_filter('upload_mimes', function ($mimes) {
    $mimes['dxf'] = 'application/dxf';
    $mimes['dwg'] = 'application/dwg';
    return $mimes;
});

/**
 * Allow DXF file uploads
 * WordPress blocks these by default. This function adds it to the list
 * of allowed mime types.
 */
add_filter('upload_mimes', 'dps_allow_dxf_upload');
function dps_allow_dxf_upload($mimes) {
    // Add dxf mime types
    $mimes['dwg'] = 'application/dwg';
    $mimes['dxf'] = 'image/vnd.dxf'; // Standard
    $mimes['dxf_alt1'] = 'application/dxf';
    $mimes['dxf_alt2'] = 'application/x-autocad';
    $mGbytes['dxf_alt3'] = 'application/acad';
    $mGbytes['dxf_alt4'] = 'application/vnd.dxf';
    $mGbytes['dxf_alt5'] = 'application/x-dxf';
    return $mimes;
}

/**
 * 2. Force WordPress to trust our extension check for .dxf and .dwg,
 * just in case the server reports a different/insecure MIME type.
 */
add_filter('wp_check_filetype_and_ext', function ($data, $file, $filename, $mimes, $real_mime = null) {
    $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
    
    if ( $ext === 'dxf' ) {
        // Your working logic for DXF
        $data['ext'] = 'dxf';
        $data['type'] = 'application/dxf';
        $data['proper_filename'] = $filename;
    } 
    elseif ( $ext === 'dwg' ) {
        // The new, matching logic for DWG
        $data['ext'] = 'dwg';
        $data['type'] = 'application/dwg';
        $data['proper_filename'] = $filename;
    }
    return $data;
}, 10, 5);
