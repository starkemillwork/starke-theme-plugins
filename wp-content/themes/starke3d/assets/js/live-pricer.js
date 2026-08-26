jQuery(document).ready(function ($) {
    // Function to trigger the pricing calculator
    function updatePricing() {
        if (typeof window.starke3d_data !== 'undefined' && window.starke3d_data.isAccountLimited) {
            return false;
        }
        
        const profile_num = $('#profileNumber_label').text();
        const width = $('#profileWidth_dropdown').val(); // Replace with your input selector
		const thickness = $('#profileThickness_dropdown').val();
		const linear_feet_formatted = $('#linearFeet_number').val(); // e.g., "5,000"
        const linear_feet = linear_feet_formatted.replace(/,/g, ''); // NEW: strips commas to "5000"
		const lengths = $('#lengthsDropdown_dropdown option:selected').text();
		const rabbet_position = $('#rabbetPosition_dropdown option:selected').text();
		const relief_angle = $("#reliefAngleSwitch_checkbox").prop("checked");
		const species = $('#species_dropdown').val();
		const finish_option = $('#finishOptions_dropdown').val();
        const customProfiles = ['xBaseboard', 'xCasing', 'xCrown', 'xMiscellaneous'];
        let markup;
        let waste;


        let data = {
            action: 'calculate_pricing',
            width: width,
            thickness: thickness,
            linear_feet: linear_feet,
            lengths: lengths,
            rabbet_position: rabbet_position,
            relief_angle: relief_angle,
            species: species,
            finish_option: finish_option,
            nonce: pricing_ajax.nonce,
        }

        if (customProfiles.includes(profile_num)) {
            markup = $('#markup_percentage').val();
            waste = $('#waste_percentage').val();
            data.markup = markup;
            data.waste = waste;
        }

        console.log('Profile Number:', profile_num); // Debugging line to check profile number
        console.log('Data sent to AJAX:', data); // Debugging line to check data being sent

        // Make AJAX request
        $.ajax({
            url: pricing_ajax.ajax_url, // AJAX URL provided by WordPress
            type: 'POST',
            data: data,
            success: function (response) {
                if (response.success) {
                    console.log('Priced');
					if (response.data.linear_feet == 0) {
						$('#quantityDiscountValue_span').text(response.data.quantity_discount);
						$('#pricePerFootValue_span').text('0.00');
						$('#subtotalValue_span').text('0.00');
					}
					else {
						// Update pricing display
						$('#quantityDiscountValue_span').text(response.data.quantity_discount);
						$('#pricePerFootValue_span').text(formatPrice(response.data.price_per_foot));
						$('#subtotalValue_span').text(formatPrice(response.data.subtotal));
					}
                } else {
                    console.error('Error:', response.data);
                }
            },
            error: function (xhr, status, error) {
				if (xhr.responseJSON && xhr.responseJSON.data) {
					alert(xhr.responseJSON.data); // Display the error message
				} else {
					console.error('AJAX Error:', error);
				}
			},
        });
    }
   
    // Attach event listeners to inputs
    $(document).on('change', '#profileWidth_dropdown, #profileWidth_slider, #profileThickness_dropdown, #profileThickness_slider, #lengthsDropdown_dropdown, #rabbetPosition_dropdown, #rabbetPosition_slider, #reliefAngleSwitch_checkbox, #species_dropdown, #finishOptions_dropdown', updatePricing);
    // --- REPLACE YOUR OLD LINEAR FEET ATTACHMENT WITH THIS CHANNEL ---
    const debouncedUpdatePricing = debounce(updatePricing, 360);
    $('#linearFeet_number').on('keyup input', function(e) {
        if (e.isTrusted === false || (e.originalEvent && e.originalEvent.isTrusted === false)) {
            updatePricing(); // Programmatic page-load trigger runs instantly
        } else {
            debouncedUpdatePricing(); // Manual user inputs get the 360ms delay
        }
    });
    $(document).on('keyup input', '#markup_percentage, #waste_percentage', debounce(updatePricing, 360));
    
    $('#resetValues_button').on('click', function() { requestAnimationFrame(updatePricing); });
});

function debounce(func, delay) {
    let timeout;
    return function (...args) {
        // Clear the existing timer if the event fires again within the delay
        clearTimeout(timeout);
        // Set a new timer
        timeout = setTimeout(() => func.apply(this, args), delay);
    };
}

function formatPrice(number) {
  const float = parseFloat(number);

  if (isNaN(float)) return '0.00';

  return float.toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });
}