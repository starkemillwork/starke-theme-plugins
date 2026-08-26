<?php
/**
 * Starke Commerce Settings
 *
 */

if (!class_exists('Starke_Commerce')) {

    class Starke_Commerce {

        private static $instance;

        public static function get_instance() {
            if (self::$instance == null) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'register_settings'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
        }

        /**
        * Add the new menu page to the main admin menu.
        */
        public function add_admin_menu() {
            add_menu_page(
                __('Starke Commerce', 'starke-domain'), // Page title
                __('Starke Commerce', 'starke-domain'), // Menu title
                'manage_woocommerce',                  // Capability
                'starke_commerce',                     // Menu slug
                array($this, 'settings_page_html'),    // Callback function
                'dashicons-admin-generic',             // Icon URL (a generic gear icon)
                58                                     // Position (just below Comments)
            );
        }

        /**
         * Enqueue custom CSS for the settings page.
         */
        public function enqueue_admin_styles($hook) {
            // Only load on our settings page
            if ('toplevel_page_starke_commerce' != $hook) {
                return;
            }

            wc_get_logger()->warning('$style: ' . print_r(get_stylesheet_directory_uri() . '/assets/css/starke-commerce.css', true), ['source' => 'admin_debug']);

            wp_enqueue_style(
                'starke-commerce-styles',
                get_stylesheet_directory_uri() . '/assets/css/starke-commerce.css', // Assumes a starke-commerce.css file is in the theme root
                array(),
                '1.0.0'
            );
        }

        /**
         * Register the settings and fields.
         */
        public function register_settings() {
            register_setting('starke_commerce_group', 'starke_commerce_options', array($this, 'sanitize_settings'));

            add_settings_section(
                'starke_plugin_settings_section',
                __('Cost Settings', 'starke-domain'),
                null,
                'starke_commerce'
            );

            $fields = [
                'minimum_cost_per_linear_foot' => __('Minimum Cost per Linear Foot:', 'starke-domain'),
                'upcharge_for_runs_under_100_linear_feet' => __('Upcharge for Runs Under 100 Linear Feet:', 'starke-domain'),
                'ripping_cost_per_linear_foot' => __('Ripping Cost per Linear Foot:', 'starke-domain'),
                'machining_cost_per_linear_foot' => __('Machining Cost per Linear Foot:', 'starke-domain'),
                'charge_per_sample' => __('Charge per Sample:', 'starke-domain'),
                'samples_standard_shipping_cost' => __('Samples Standard Shipping Cost (aka minimum total sample cost):', 'starke-domain'),
                'samples_express_shipping_cost' => __('Samples Express Shipping Cost:', 'starke-domain'),
                'rabbet_cost_per_linear_foot_100up' => __('Rabbet Cost per Linear Foot:', 'starke-domain'),
                'relief_angle_cost_per_linear_foot_100up' => __('Relief Angle Cost per Linear Foot:', 'starke-domain'),
                'rabbet_under100ft_setup_charge' => __("Rabbet Under 100ft Charge:", 'starke-domain'),
                'relief_angle_under100ft_setup_charge' => __("Relief Angle Under 100ft Charge:", 'starke-domain'),
                'card_convenience_fee' => __("Card Convenience Fee:", 'starke-domain'),
                'quote_duration' => __("Quote Duration:", 'starke-domain'),
                'discounts' => __('Discounts:', 'starke-domain'),
            ];

            foreach ($fields as $id => $title) {
                add_settings_field(
                    $id,
                    $title,
                    array($this, 'render_field'),
                    'starke_commerce',
                    'starke_plugin_settings_section',
                    ['id' => $id]
                );
            }
        }

        /**
        * Render the appropriate field type.
        */
        public function render_field($args) {
            $options = get_option('starke_commerce_options');
            $id = $args['id'];
            $value = isset($options[$id]) ? $options[$id] : '';

            if ($id === 'discounts') {
                $this->render_discounts_repeater($value);
            } else if ($id === 'card_convenience_fee') {
                // Render field for Card Convenience Fee with "Percentage" placeholder
                echo '<div class="starke-number-input">';
                printf(
                    '<input type="number" step="1" min="0" id="%s" name="starke_commerce_options[%s]" value="%s" class="regular-text" style="margin-left: 16px;" placeholder="Percentage">',
                    esc_attr($id),
                    esc_attr($id),
                    esc_attr($value)
                );
                echo '</div>';
            } else if ($id === 'quote_duration') {
                // Render field for Quote Duration with "Days" placeholder and integer steps
                echo '<div class="starke-number-input">';
                printf(
                    '<input type="number" step="1" min="0" id="%s" name="starke_commerce_options[%s]" value="%s" class="regular-text" style="margin-left: 16px;" placeholder="Days">',
                    esc_attr($id),
                    esc_attr($id),
                    esc_attr($value)
                );
                echo '</div>';
            } else {
                // Default rendering for all other fields (currency)
                $formatted_value = '';
                if (is_numeric($value)) {
                    $formatted_value = number_format((float)$value, 2, '.', '');
                }
                
                echo '<div class="starke-number-input">';
                echo '<span class="starke-currency-symbol">$</span>';
                printf(
                    '<input type="number" step="0.01" min="0" id="%s" name="starke_commerce_options[%s]" value="%s" class="regular-text">',
                    esc_attr($id),
                    esc_attr($id),
                    esc_attr($formatted_value)
                );
                echo '</div>';
            }
        }

        /**
        * Render the discounts repeater field.
        */
        public function render_discounts_repeater($values) {
            $discounts = is_array($values) && !empty($values) ? $values : [['amount' => '', 'percentage' => '']];
            ?>
            <div id="discounts-repeater">
                <div class="repeater-template" style="display: none;">
                    <div class="repeater-item">
                        <input type="number" step="1" min="0" name="starke_commerce_options[discounts][__index__][amount]" placeholder="Linear Feet">
                        <input type="number" step="1" min="0" name="starke_commerce_options[discounts][__index__][percentage]" placeholder="Percentage">
                        <button type="button" class="button add-discount-row">+</button>
                        <button type="button" class="button remove-discount-row">-</button>
                    </div>
                </div>
                <div class="repeater-items">
                    <?php foreach ($discounts as $index => $discount) : ?>
                        <div class="repeater-item">
                            <input type="number" step="1" min="0" name="starke_commerce_options[discounts][<?php echo $index; ?>][amount]" value="<?php echo esc_attr($discount['amount']); ?>" placeholder="Linear Feet">
                            <input type="number" step="1" min="0" name="starke_commerce_options[discounts][<?php echo $index; ?>][percentage]" value="<?php echo esc_attr($discount['percentage']); ?>" placeholder="Percentage">
                            <button type="button" class="button add-discount-row">+</button>
                            <?php if ($index !== 0) { ?> <button type="button" class="button remove-discount-row">-</button> <?php } ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const repeater = document.getElementById('discounts-repeater');
                    if (repeater) {
                        const template = repeater.querySelector('.repeater-template').innerHTML;
                        const itemsContainer = repeater.querySelector('.repeater-items');

                        function reindexDiscounts() {
                            const items = itemsContainer.querySelectorAll('.repeater-item');
                            items.forEach((item, index) => {
                                const amountInput = item.querySelector('input[name*="[amount]"]');
                                const percentageInput = item.querySelector('input[name*="[percentage]"]');
                                if (amountInput) {
                                    amountInput.name = `starke_commerce_options[discounts][${index}][amount]`;
                                }
                                if (percentageInput) {
                                    percentageInput.name = `starke_commerce_options[discounts][${index}][percentage]`;
                                }
                            });
                        }

                        repeater.addEventListener('click', function(e) {
                            if (e.target.classList.contains('add-discount-row')) {
                                const currentRow = e.target.closest('.repeater-item');
                                
                                // Create a temporary container to correctly parse the template HTML
                                const tempDiv = document.createElement('div');
                                tempDiv.innerHTML = template.replace(/__index__/g, 'new');
                                
                                // Get the actual .repeater-item element from the temporary container
                                const newRow = tempDiv.firstElementChild;
                                
                                // Insert the new row correctly after the current one
                                if (newRow) {
                                    currentRow.insertAdjacentElement('afterend', newRow);
                                }
                                
                                reindexDiscounts();
                            }
                            
                            if (e.target.classList.contains('remove-discount-row')) {
                                if (itemsContainer.querySelectorAll('.repeater-item').length > 1) {
                                    e.target.closest('.repeater-item').remove();
                                    reindexDiscounts();
                                }
                            }
                        });
                    }
                });
            </script>
            <?php
        }

        /**
        * Sanitize the settings before saving.
        */
        public function sanitize_settings($input) {
            // Get the old options to compare against for shipping cost changes.
            $old_options = get_option('starke_commerce_options', []);

            $sanitized_input = [];
            if (empty($input)) {
                return $sanitized_input;
            }
            
            // --- NEW: Handle shipping cost updates ---
            // This part of the code runs before sanitizing and saving. It reads the submitted values for the two
            // specific shipping cost fields. If a field's value has changed, it calls a helper function 
            // to update all corresponding shipping methods across all WooCommerce shipping zones.
            $shipping_option_map = [
                // Maps the option key from the settings page to the exact title of the shipping method.
                'samples_standard_shipping_cost' => 'Samples Standard Shipping',
                'samples_express_shipping_cost'  => 'Samples Express 1 Day Shipping',
            ];

            foreach ($shipping_option_map as $option_key => $method_title) {
                // Get the previously saved cost and the newly submitted cost.
                $old_cost = isset($old_options[$option_key]) ? (float)$old_options[$option_key] : null;
                $new_cost_raw = isset($input[$option_key]) ? $input[$option_key] : null;
                
                // Proceed only if a new value was actually submitted for this option.
                if ($new_cost_raw !== null) {
                    $new_cost = (float)$new_cost_raw;
                    // Compare the new cost to the old cost. Using a small tolerance (epsilon) for comparing
                    // floating-point numbers helps avoid issues with precision. If they are different,
                    // trigger the update function.
                    if (abs($new_cost - $old_cost) > 0.001) {
                        $this->update_shipping_method_costs($method_title, $new_cost);
                    }
                }
            }

            foreach ($input as $key => $value) {
                if ($key === 'discounts' && is_array($value)) {
                    $clean_discounts = []; // Start with a clean array to store only valid rows.
                    foreach ($value as $row) {
                        // Convert submitted values to numbers for a reliable check.
                        $amount_val = isset($row['amount']) ? floatval($row['amount']) : 0;
                        $percentage_val = isset($row['percentage']) ? floatval($row['percentage']) : 0;

                        // This is the crucial check: Only save the row if at least one field
                        // contains a number that is actually greater than 0.
                        if ($amount_val > 0 && $percentage_val > 0) {
                            // If the row is valid, add the original sanitized strings to our clean array.
                            // This preserves empty fields correctly, allowing placeholders to work.
                            $clean_discounts[] = [
                                'amount' => isset($row['amount']) ? sanitize_text_field($row['amount']) : '',
                                'percentage' => isset($row['percentage']) ? sanitize_text_field($row['percentage']) : ''
                            ];
                        }
                    }
                    // Save the fully cleaned and filtered array.
                    $sanitized_input[$key] = $clean_discounts;
                } else if ($key === 'quote_duration') {
                    // Sanitize as a non-negative integer.
                    $sanitized_input[$key] = !empty($value) ? absint($value) : '';
                } else if ($key === 'card_convenience_fee') {
                    // Sanitize as a non-negative float, preserving decimals.
                    $numeric_value = isset($value) ? floatval($value) : 0;
                    $non_negative_value = max(0, $numeric_value);
                    $sanitized_input[$key] = $non_negative_value > 0 ? strval($non_negative_value) : '';
                } else {
                    // For all other fields, sanitize, ensure non-negative, and format as currency.
                    $numeric_value = isset($value) ? floatval($value) : 0;
                    $non_negative_value = max(0, $numeric_value);
                    $field_value = number_format($non_negative_value, 2, '.', '');
                    $sanitized_input[$key] = $field_value > 0 ? $field_value : '';
                }
            }
            return $sanitized_input;
        }

        /**
         * --- NEW HELPER FUNCTION ---
         * Finds and updates the cost for all instances of a specific shipping method title.
         *
         * This function iterates through every shipping zone in WooCommerce, including the default
         * "Locations not covered by your other zones", and updates the cost for every shipping method
         * that has a matching title.
         *
         * @param string $method_title The title of the shipping method to update (e.g., "Samples Standard Shipping Cost").
         * @param float  $new_cost     The new cost to set for the method.
         */
        private function update_shipping_method_costs($method_title, $new_cost) {
            if (!class_exists('WC_Shipping_Zones')) {
                return;
            }
            
            // Format the cost to a standard WooCommerce decimal format.
            $sanitized_cost = wc_format_decimal($new_cost);

            // Get all defined shipping zones from WooCommerce.
            $all_zones_data = WC_Shipping_Zones::get_zones();
            
            // Manually add the default "Rest of the World" zone (which has an ID of 0) to the list.
            $zone_zero = new WC_Shipping_Zone(0);
            $all_zones_data[0] = $zone_zero->get_data();

            // Loop through every shipping zone.
            foreach ($all_zones_data as $zone_data) {
                // Basic validation to ensure we have valid zone data to work with.
                if (empty($zone_data) || !isset($zone_data['id'])) continue;

                $zone = WC_Shipping_Zones::get_zone($zone_data['id']);
                if (!$zone) continue;

                // Get all shipping methods configured for the current zone.
                $shipping_methods = $zone->get_shipping_methods();
                
                // Loop through the methods in this zone.
                foreach ($shipping_methods as $instance_id => $method) {
                    // The user-facing title is stored in the method's instance_settings.
                    if (isset($method->instance_settings['title']) && $method->instance_settings['title'] === $method_title) {
                        
                        // Get the current settings, update only the 'cost' value, and save it back.
                        $settings = $method->instance_settings;
                        $settings['cost'] = $sanitized_cost;

                        // WooCommerce saves instance settings as a distinct WordPress option.
                        // The 'get_instance_option_key' method provides the correct option name to update.
                        update_option($method->get_instance_option_key(), $settings, 'yes');
                    }
                }
            }
        }

        /**
        * Render the settings page HTML structure.
        */
        public function settings_page_html() {
            if (!current_user_can('manage_woocommerce')) {
                return;
            }
            ?>
            <div class="wrap starke-commerce-wrap">
                <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
                <form action="options.php" method="post">
                    <?php
                    settings_fields('starke_commerce_group');
                    do_settings_sections('starke_commerce');
                    submit_button(__('Save Changes', 'starke-domain'));
                    ?>
                </form>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    // Select all number inputs that are NOT part of the discounts repeater
                    const currencyFields = document.querySelectorAll('.starke-commerce-wrap .form-table input[type="number"]:not([name*="[discounts]"])');

                    // Function to format the value of a field to two decimal places
                    function formatCurrency(e) {
                        const field = e.target;
                        if (field.value === '') return;
                        
                        let value = parseFloat(field.value);
                        if (!isNaN(value)) {
                            field.value = value.toFixed(2);
                        }
                    }

                    // Add a 'blur' event listener to each field.
                    // This will trigger the formatting when you click or tab out of the field.
                    currencyFields.forEach(function(field) {
                        //field.addEventListener('blur', formatCurrency);
                    });    
                    
                    // --- MOUSE WHEEL INCREMENT/DECREMENT LOGIC ---
                    // This code finds all number inputs on the page and adds a 'wheel' event listener.
                    // It will only adjust the number if the input is currently the active (focused)
                    // element on the page.
                    const numberInputs = document.querySelectorAll('.starke-commerce-wrap input[type="number"]');

                    numberInputs.forEach(function(input) {
                        input.addEventListener('wheel', function(event) {
                            // Only run the logic if the input is the currently focused element.
                            if (document.activeElement === input) {
                                // Prevent the default browser action (scrolling the page).
                                event.preventDefault();

                                // Determine the scroll direction and adjust the input's value accordingly.
                                if (event.deltaY < 0) {
                                    // Scrolled up - increment the value.
                                    input.stepUp();
                                } else {
                                    // Scrolled down - decrement the value.
                                    input.stepDown();
                                }

                                // Trigger a 'change' event to ensure any other scripts relying on this
                                // input's value are notified of the update.
                                input.dispatchEvent(new Event('change', { bubbles: true }));
                            }
                        }, { passive: false }); // 'passive: false' is required to allow preventDefault().
                    });
                });
            </script>
            <?php
        }
    }

    Starke_Commerce::get_instance();
}