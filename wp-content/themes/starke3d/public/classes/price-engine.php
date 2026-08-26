<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Price_Engine {

	// The core function
	public static function calculate_pricing($linear_feet, $width, $thickness, $lengths, $rabbet_position, $relief_angle, $species, $finish_option, $product_id, $markup = 0, $waste = 0) {
		$setup_charge_product_id = 444;
		$knife_cost_product_id = 2843;

		$starke_options = get_option('starke_commerce_options');

		// Check Linear Feet
		$linear_feet = isset($linear_feet) ? $linear_feet : 0;
		$linear_feet = $linear_feet < 0 ? 0 : $linear_feet;
		$lengths_is_valid = (isset($lengths) && $lengths !== '' && strpos($lengths, 'Select') !== 0);

		if (!$product_id || $product_id == $setup_charge_product_id || $product_id == $knife_cost_product_id || $linear_feet == 0 || !$lengths_is_valid) {
			return [
				'rabbet_position' => null,
				'relief_angle' => null,
				'linear_feet' => '0',
				'quantity_discount' => '0',
				'price_per_foot' => '0.00',
				'subtotal' => '0.00',
			];
		} else {
			// Collect inputs
			$width = isset($width) ? $width : 0;
			$width = $width < 0 ? 0 : $width;

			$thickness = isset($thickness) ? $thickness : 0;
			$thickness = $thickness < 0 ? 0 : $thickness;

			$lengths = isset($lengths) ? $lengths : '';
			$rabbet_position = isset($rabbet_position) ? $rabbet_position : 'OFF';
			$relief_angle = isset($relief_angle) ? filter_var($relief_angle, FILTER_VALIDATE_BOOLEAN ) : false;
			$species = isset($species) ? intval($species) : -1;
			$finish_option = isset($finish_option) ? intval($finish_option) : -1;

			// Other variables needed for calculation
			$product_fields = get_fields($product_id);
			$width_cleanup_amount = .375;
			$full_width = $width + $width_cleanup_amount;
			$thickness_cleanup_amount = .1875;
			$full_thickness = $thickness + $thickness_cleanup_amount;
			$species_fields = get_fields($species);
			$finish_option_fields = get_fields($finish_option);
			$wide_lumber_premium = floatval($species_fields['wide_lumber_premium']);
			$wide_lumber_threshold = floatval($species_fields['wide_lumber_threshold']);
			$wide_lumber_upcharge = 0;
			$lengths_markup = floatval($species_fields[self::convert_to_underscore($lengths)]);
			$specie_thickness_results = self::get_closest_thickness_price($species_fields, $full_thickness);

			if (is_custom_profile($product_id)) {
				$product_markup = $markup / 100; // Converts percentage to decimal
				$product_waste = $waste / 100; // Converts percentage to decimal
			} else {
				$product_markup = floatval($product_fields['markup']);
				$product_waste = floatval($product_fields['waste']);
			}

			// A: Lumber Cost Per Foot
			$lumber_cost_per_ft = (($full_width / 12) * $specie_thickness_results[0]) * $specie_thickness_results[1];
			// B: Species Length upcharge 
			$species_length_upcharge = $lumber_cost_per_ft * $lengths_markup;
			// C: Wide Lumber upcharge
			if ($wide_lumber_premium > 0 && $wide_lumber_threshold > 0){
				if ($full_width >= $wide_lumber_threshold) {
					$wide_lumber_upcharge = $lumber_cost_per_ft * $wide_lumber_premium;
				}
			}
			// D: Product Waste upcharge
			$product_waste_per_ft_charge = ($lumber_cost_per_ft + $species_length_upcharge + $wide_lumber_upcharge) * $product_waste;
			// E: Lumber Cost Per Foot Total so far including Species Length upcharge, Wide Lumber upcharge, and Product Wast upcharge
			$total_lumber_cost_per_ft = $lumber_cost_per_ft + $species_length_upcharge + $wide_lumber_upcharge + $product_waste_per_ft_charge;
			// F: Labor Cost 
			$ripping_cost_per_ft = isset($starke_options['ripping_cost_per_linear_foot']) ? floatval($starke_options['ripping_cost_per_linear_foot']) : .23 ;  // Pull this cost from Starke Commerce tool when I create it later
			$machining_cost_per_ft = isset($starke_options['machining_cost_per_linear_foot']) ? floatval($starke_options['machining_cost_per_linear_foot']) : .41;  // Pull this cost from Starke Commerce tool when I create it later
			$labor_cost_per_ft = $ripping_cost_per_ft + $machining_cost_per_ft;
			// G: Finish Cost Per Linear Foot
			$finish_cost_per_ft = $finish_option_fields['cost_per_inch_in_width'] * $width;
			if ($finish_cost_per_ft < $finish_option_fields['minimum_cost_per_linear_foot']) {
				$finish_cost_per_ft = $finish_option_fields['minimum_cost_per_linear_foot'];
			} elseif ($finish_cost_per_ft > $finish_option_fields['maximum_cost_per_linear_foot']) {
				$finish_cost_per_ft = $finish_option_fields['maximum_cost_per_linear_foot'];
			}
			// H: Product Markup Per Foot upcharge
			$product_markup_per_ft_charge = ($total_lumber_cost_per_ft + $labor_cost_per_ft + $finish_cost_per_ft) * $product_markup;
			// I: Rabbet Per Foot charge if this line item is 100' or more 
			$rabbet_per_ft_charge_100up = 0;
 			if ($rabbet_position != 'OFF' && $rabbet_position != '') {
				$rabbet_position = 'true';
			}
			else {
				$rabbet_position = 'false';
			}
			if ($rabbet_position != 'false' && $linear_feet >= 100) {
				$rabbet_per_ft_charge_100up = isset($starke_options['rabbet_cost_per_linear_foot_100up']) ? floatval($starke_options['rabbet_cost_per_linear_foot_100up']) : .2; // Pull this cost from Starke Commerce tool when I create it later
			}
			// J: Relief Angle Per Foot charge if this line item is 100' or more
			$relief_angle_per_ft_charge_100up = 0;
			if ($relief_angle === true) {
				$relief_angle = 'true';
			}
			else {
				$relief_angle = 'false';
			}
			if ($relief_angle === 'true' && $linear_feet >= 100) {
				$relief_angle_per_ft_charge_100up = isset($starke_options['relief_angle_cost_per_linear_foot_100up']) ? floatval($starke_options['relief_angle_cost_per_linear_foot_100up']) : .2; // Pull this cost from Starke Commerce tool when I create it later
			}
			// K: Quantity Discount 
			$discounts_from_settings = isset($starke_options['discounts']) ? $starke_options['discounts'] : [];
			$quantity_discounts = []; // Pull this cost from Starke Commerce tool when I create it later
			if (!empty($discounts_from_settings)) {
				foreach ($discounts_from_settings as $discount_row) {
					// Check if both amount and percentage are set and not empty
					if (!empty($discount_row['amount']) && isset($discount_row['percentage']) && $discount_row['percentage'] !== '') {
						$amount_key = $discount_row['amount'];
						$percentage_value = floatval($discount_row['percentage']);
						$quantity_discounts[$amount_key] = $percentage_value;
					}
				}
			}
			$discount_as_percent = self::findClosestKeyBelow($quantity_discounts, $linear_feet);
			$discount = $discount_as_percent / 100;
			// Cost Per Linear Foot (Also checks against Minimum Charge Per Linear Ft (excluding finish))
			$min_charge_used = false;
			$min_charge_per_ft = isset($starke_options['minimum_cost_per_linear_foot']) ? floatval($starke_options['minimum_cost_per_linear_foot']) : .6; // Pull this cost from Starke Commerce tool when I create it later
			$cost_per_ft_excl_finish = $total_lumber_cost_per_ft + $labor_cost_per_ft + $product_markup_per_ft_charge + $rabbet_per_ft_charge_100up + $relief_angle_per_ft_charge_100up;
			if ($cost_per_ft_excl_finish - ($cost_per_ft_excl_finish * $discount) < $min_charge_per_ft) {
				$cost_per_ft_excl_finish = $min_charge_per_ft;
				$min_charge_used = true;
			}
			$cost_per_ft = $cost_per_ft_excl_finish + $finish_cost_per_ft;
			// Final Cost Per Linear Foot
			$final_cost_per_ft = round($cost_per_ft - ($cost_per_ft * $discount), 2);
			// Total Cost of Linear Feet
			$total_cost_linear_ft = $final_cost_per_ft * $linear_feet;

			// Return the calculated values
			return [
				'rabbet_position' => $rabbet_position,
				'relief_angle' => $relief_angle,
				'linear_feet' => $linear_feet,
				'quantity_discount' => $discount_as_percent,
				'price_per_foot' => $final_cost_per_ft,
				'subtotal' => $total_cost_linear_ft
			];
		}
	}
	

	private static function convert_to_underscore($input) {
		// 1. Convert the entire string to lowercase for consistency.
		$output = strtolower($input);

		// 2. Replace all sequences of non-alphanumeric characters 
		//    (like ′, –, and spaces) with a single underscore.
		$output = preg_replace('/[^a-z0-9]+/', '_', $output);

		// 3. Remove any leading or trailing underscores that might result from the replacement.
		$output = trim($output, '_');

		// 4. Prepend the 'markup_' prefix to the final string.
		return 'markup_' . $output;
	}
	
	// Function to get the closest thickness and its price
	private static function get_closest_thickness_price($array, $input_number) {
		$thickness_values = [];
		// Extract relevant keys and numeric values
		foreach ($array as $key => $value) {
			if (strpos($key, 'price_at_thickness_') !== false) {
				$thickness = (float) str_replace('price_at_thickness_', '', $key);
				$thickness_values["$thickness"] = $value; // Store as a string to preserve precision
			}
		}
		// Find the closest thickness value
		$closest = null;
		foreach ($thickness_values as $thickness => $price) {
			if ($input_number <= (float) $thickness) {
				$closest = $thickness;
				break;
			}
		}
		// Return the closest thickness and the price for the closest thickness
		return [(float) $closest, $closest !== null ? (float) $thickness_values[$closest] : null];
	}
	
	// Function to find the closest key lower than a given number and return the key's value
	private static function findClosestKeyBelow($array, $inputNumber) {
		$closest = null;
		foreach ($array as $key => $value) {
			if ($key <= $inputNumber && ($closest === null || $key > $closest)) {
				$closest = $key;
			}
		}
		return $closest !== null ? $array[$closest] : 0;
	}
} // Class end