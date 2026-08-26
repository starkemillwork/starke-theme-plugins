<?php
/**
 * Title: ACFFields
 * Slug: starke3d/acffields
 * Categories: sidebar
 * Inserter: false
 */


if ( function_exists( 'get_field' ) ) {
    // Example: Display a custom field
    $custom_field = get_field( 'thickness' );
    if ( $custom_field ) {
        echo '<div>' . esc_html( $custom_field ) . '</div>';
    }
}

echo '<div class="custom-field">';
    echo '<label for="width">Enter Width:</label>';
    echo '<input type="range" name="width" id="width" value="0"/>';
    echo '</div>';