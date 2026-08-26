<?php
// Ensure this file is being accessed through WordPress
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// // Reorganize Tools, etc to different menus
add_action( 'admin_init', function() {
    global $submenu;
    
    // --- NEW: Remove Customize from Appearance ---
    // The Customize link uses a dynamic slug (customize.php?return=...), 
    // so we must search the global $submenu array and unset it manually.
    if ( isset( $submenu['themes.php'] ) ) {
        foreach ( $submenu['themes.php'] as $index => $menu_item ) {
            // $menu_item[2] holds the URL slug. If it contains 'customize.php', remove it.
            if ( false !== strpos( $menu_item[2], 'customize.php' ) ) {
                unset( $submenu['themes.php'][$index] );
                break; // Stop looping once we find and remove it
            }
        }
    }
    
    // Remove Theme File Editor from Tools menu
    remove_submenu_page( 'tools.php', 'theme-editor.php' );

    // Add Theme File Editor under Appearance menu
    add_submenu_page(
        'themes.php',            // Parent slug for Appearance
        'Theme File Editor',     // Page title
        'Theme File Editor',     // Menu title
        'edit_themes',           // Capability
        'theme-editor.php'       // Menu slug
    );

    // Remove Plugin File Editor from Tools menu
    remove_submenu_page( 'tools.php', 'plugin-editor.php' );

    // Add Plugin File Editor under Plugins menu
    add_submenu_page(
        'plugins.php',           // Parent slug for Plugins
        'Plugin File Editor',    // Page title
        'Plugin File Editor',    // Menu title
        'edit_plugins',          // Capability
        'plugin-editor.php'      // Menu slug
    );
}, 99 ); // High priority to ensure it runs after menus are registered