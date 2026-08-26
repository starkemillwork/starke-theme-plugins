jQuery(function ($) {
    console.log('Run9');
	let cartCheckoutURL = '';

    // This ensures requests run one at a time, preventing session conflicts.
    const sampleQueue = {
        items: [],
        isBusy: false,
        add: function(taskFunction) {
            this.items.push(taskFunction);
            this.process();
        },
        process: function() {
            if (this.isBusy || this.items.length === 0) return;
            
            this.isBusy = true;
            const currentTask = this.items.shift();
            
            // Execute the task (AJAX call)
            // We expect the task to return the jQuery AJAX object so we can use .always()
            currentTask().always(() => {
                this.isBusy = false;
                this.process(); // Trigger the next item in line
            });
        }
    };

    /* global wc_add_to_cart_params */
    if (typeof wc_add_to_cart_params === 'undefined') {
        return false;
    }

    $(document).on('submit', 'form.cart', function (e) {
        var form = $(this),
            button = form.find('#addToCart_button');

        // Get the product name from the #profileNumber_label element
        var productName = $('#profileNumber_label').text() || 'Product';

        var formFields = form.find('input:not([name="product_id"]), select, button, textarea');
        // create the form data array
        var formData = [];
        formFields.each(function (i, field) {
			$field = $(field); // jQuery object for the field
            var fieldName = field.name,
                fieldValue = field.value;
            if (fieldName && fieldValue) {
                // set the correct product/variation id for single or variable products
                if (fieldName == 'add-to-cart') {
                    fieldName = 'product_id';
                    fieldValue = form.find('input[name=variation_id]').val() || fieldValue;
                }
                // if the field is a checkbox/radio and is not checked, skip it
                if ((field.type == 'checkbox' || field.type == 'radio') && field.checked == false) {
                    return;
                }
                // add the data to the array
                formData.push({
                    name: fieldName,
                    value: fieldValue
                });
				
				// Check if the field has a `data-actual-value` attribute and add it if present
                var actualValue = $field.attr('data-actual-value');
                if (actualValue !== undefined) {
                    formData.push({
						name: `${fieldName}_actual`,
						value: actualValue
				});
                }
				
            }
        });
        if (!formData.length) {
            return;
        }

        e.preventDefault();

        form.block({
            message: null,
            overlayCSS: {
                background: "#ffffff",
                opacity: 0.6
            }
        });
        $(document.body).trigger('adding_to_cart', [button, formData]);

        $.ajax({
            type: 'POST',
            url: woocommerce_params.wc_ajax_url.toString().replace('%%endpoint%%', 'add_to_cart'),
            data: formData,
            success: function (response) {
                if (!response) {
                    return;
                }

                // Check for the custom error message for duplicate sample
                if (response.data && response.data.message === 'duplicate_sample') {
                    // Trigger the custom popup
                    $('#infoPopUpTitle_label').text('Note!');
                    $('#infoTextContent_p').html(`Profile <b>${productName} sample</b> has already been added to your cart.`);
                    // Trigger the Main.js function so the background overlay dims properly
                    if (typeof window.openInfoPopup === 'function') {
                        window.openInfoPopup();
                    } else {
                        $('#infoPopUp_div').css('display', 'flex');
                    }
                    return;
                }

                if (response.error && response.product_url) {
                    window.location = response.product_url;
                    return;
                }

                // Trigger different messages based on whether it's a sample
                if (formData.some(item => item.name === 'sample' && item.value === 'true')) {
                    // Update the specific Add Sample button text and state
                    $('#addSampleToCart_button').text('SAMPLE ADDED').prop('disabled', true).css('opacity', '1').css('cursor', 'default');
                    // Show Sample Added Message
                    $('#infoPopUpTitle_label').text('Sample Added');
                    $('#infoTextContent_p').html(`Profile <b>${productName} sample</b> has been added to your cart. Sample is always provided in the standard size.`);
					$('#viewCart_button').css('display', 'block');
                    // Trigger the Main.js function so the background overlay dims properly
                    if (typeof window.openInfoPopup === 'function') {
                        window.openInfoPopup();
                    } else {
                        $('#infoPopUp_div').css('display', 'flex');
                    }
                } else {
					
					// Check if this action is to update, consolidate, or add the cart item
					if (response.data && response.data.message === 'cart_item_updated') {
						// Trigger the custom popup
						$('#addToCart_button').text('SAVED!');
                        cartCheckoutURL = response.data.checkout_url;
						window.location.href = cartCheckoutURL;
					}
					else if (response.data && response.data.message) {

                        console.log('response.data.message1', response.data?.message);

						// Trigger the custom popup
						handleAddedToCartForLinearFeetProfiles(productName, response.data.message);
					} else {

                    console.log('response.data.message2', response.data?.message);

						// Show Normal Product Added Message
						handleAddedToCartForLinearFeetProfiles(productName);
					}
                }

                


                $(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, button]);
            },
            complete: function () {
                form.unblock();
            }
        });

        return false;
    });
	
	// Added To Cart event handler for custom profiles
    $( document.body ).on( 'added_to_cart', function( event, fragments, cart_hash, button ) {
        if ( fragments ) { 
            if( fragments['this_custom_profile_number'] ) {
                // When same custom profile is added to cart
                console.log('this_custom_profile_number', fragments['this_custom_profile_number']);
                handleAddedToCartForLinearFeetProfiles(fragments.this_custom_profile_number);
            }
            if( fragments['next_custom_profile_number'] ) {
                // When new custom profile is added, updates the Temp# for the next placeholder custom profile number
                console.log('this_custom_profile_number', fragments['next_custom_profile_number']);
                $('#tempProfileNumberValue').text(fragments.next_custom_profile_number);
            }
        }
    });

	$('#viewCart_button').on('click', viewCart);
    function viewCart(e) {
        if (e) e.preventDefault();
        
        // Trigger the WooCommerce mini-cart to open
        $('.wc-block-mini-cart__button').trigger('click');
        
        // Close the info popup so it doesn't block the cart
        if (typeof window.closeInfoPopup === 'function') {
            window.closeInfoPopup();
        }
    }
	
    // Handles actions for Added To Cart events for Linear Feet Products (including Normal and Custom Profiles)
    function handleAddedToCartForLinearFeetProfiles(productName, message = undefined) {
        $('#infoPopUpTitle_label').text('Profile Added');
        if (!message) {
            if ($('#linearFeet_number').val() >= 100 || productName === '5000') {
                $('#infoTextContent_p').html(`Profile <b>${productName}</b> has been updated to your cart.`);
            }
            else {
                $('#infoTextContent_p').html(`Profile <b>${productName}</b> has been updated to your cart. Setup Charge will be calculated in Cart <b>if Linear Feet is under 100'</b>.`);
            }
        } else {
            if (message === 'cart_item_consolidated') {
                $('#infoTextContent_p').html(`Profile <b>${productName}</b> has been updated to your cart.`);
                //$('#infoTextContent_p').html(`Profile <b>${productName}</b> has been added to your cart. The <b>Linear Feet</b> amount has been <b>added into an identical ${productName}</b> Profile in your Cart.`);
            }
        }
        $('#viewCart_button').css('display', 'block');
        // Trigger the Main.js function so the background overlay dims properly
        if (typeof window.openInfoPopup === 'function') {
            window.openInfoPopup();
        } else {
            $('#infoPopUp_div').css('display', 'flex');
        }
        $('#addToCart_button').text('ADD TO CART');
    }
	
    /* --- STARKE GRID: ADD SAMPLE BUTTON HANDLER --- */
    $(document).on('click', '.starke-sample-btn', function(e) {
        if (typeof window.starke3d_data !== 'undefined' && window.starke3d_data.isAccountLimited) {
            e.preventDefault();
            e.stopImmediatePropagation();
            
            const dxfMsg = document.getElementById('starke-dxf-denial-msg');
            if (dxfMsg) dxfMsg.style.display = 'none';
            const limitedMsg = document.getElementById('starke-limited-access-msg');
            if (limitedMsg) limitedMsg.style.display = 'block';
            
            const headerLoginBtn = document.querySelector('header a[href*="my-account"]');
            if (headerLoginBtn) headerLoginBtn.click();
            return false;
        }
        e.preventDefault();
        
        var $clickedBtn = $(this);
        var action = $clickedBtn.data('action');
        var productId = $clickedBtn.data('product-id');
        var nonce = $clickedBtn.data('nonce');

        // --- UPDATE: Select ALL buttons (Popup + Shop) AND the Single Product Button ---
        var $allButtons = $('.starke-sample-btn[data-product-id="' + productId + '"]');
        
        // If we are on the single product page for THIS product, include the main button too
        // This ensures the popup button clicks reflect on the main page button immediately
        if (typeof window.starke3d_data !== 'undefined' && window.starke3d_data.productId == productId) {
            $allButtons = $allButtons.add($('#addSampleToCart_button'));
        }

        // SCENARIO 3: LOGIN REQUIRED
        if (action === 'login') {
            var loginUrl = $clickedBtn.data('login-url');
            if (loginUrl) {
                window.location.href = loginUrl;
            }
            return;
        }

        if ($clickedBtn.prop('disabled') || $clickedBtn.hasClass('processing')) return;
        
        // Update ALL buttons to processing state immediately
        $allButtons.addClass('processing').css('opacity', '0.5');

        // SCENARIO 1: ADD TO CART
        if (action === 'add') {
            
            var formData = {
                'action': 'woocommerce_add_to_cart',
                'product_id': productId,
                'quantity': 1,
                'sample': 'true'
            };

            var ajaxUrl = (typeof wc_add_to_cart_params !== 'undefined') 
                ? wc_add_to_cart_params.wc_ajax_url.toString().replace('%%endpoint%%', 'add_to_cart') 
                : '/?wc-ajax=add_to_cart';

            // Wrap in Queue to prevent multiple clicks from conflicting
            sampleQueue.add(() => {
                return $.post(ajaxUrl, formData, function(response) {
                    
                    // Reset if response is empty
                    if (!response) {
                        $allButtons.removeClass('processing').css('opacity', '1').text('ADD SAMPLE');
                        return;
                    }

                    // Handle Redirects
                    if (response.error && response.product_url) {
                        window.location = response.product_url;
                        return;
                    }
                    
                    // Handle Generic Errors
                    if (response.error) {
                        $allButtons.removeClass('processing').css('opacity', '1').text('TRY AGAIN');
                        console.log('WooCommerce Error:', response);
                        return; 
                    }
                    
                    // 1. Legacy Trigger
                    $(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, $clickedBtn]);
                    
                    // 2. Block Cart Refresh (SAFE WRAP)
                    try {
                        if ( typeof wp !== 'undefined' && wp.data && wp.data.dispatch ) {
                            wp.data.dispatch( 'wc/store/cart' ).invalidateResolutionForStoreSelector( 'getCartData' );
                            wp.data.dispatch( 'wc/store/cart' ).invalidateResolutionForStoreSelector( 'getCartTotals' );
                        }
                    } catch (err) {
                        console.log('Block Cart Refresh Error (Ignored):', err);
                    }

                    // 3. Trigger Native Event (SAFE WRAP)
                    try {
                        document.body.dispatchEvent(new Event('wc-blocks_added_to_cart', {
                            bubbles: true,
                            cancelable: true
                        }));
                    } catch (err) {
                        console.log('Event Trigger Error:', err);
                    }
                    
                    // 4. Update Button State - SUCCESS
                    $allButtons.removeClass('processing').css('opacity', '1').text('SAMPLE ADDED');
                    $allButtons.addClass('disabled added').prop('disabled', true).css('cursor', 'default');

                    // 5. UPDATE ON-PAGE MEMORY: Add this ID so WPGB remembers it on next AJAX reload
                    var $syncDiv = $('#starke-sample-sync');
                    if ($syncDiv.length) {
                        try {
                            var currentSamples = JSON.parse($syncDiv.attr('data-samples') || '[]');
                            if (!currentSamples.includes(productId)) {
                                currentSamples.push(productId);
                                $syncDiv.attr('data-samples', JSON.stringify(currentSamples));
                            }
                        } catch(e) {}
                    }

                }).fail(function() {
                     // Fail handler for network errors
                     $allButtons.removeClass('processing').css('opacity', '1').text('ADD SAMPLE');
                     console.log('AJAX Error');
                });
            });
        }

        // SCENARIO 2: REQUEST RESTOCK
        else if (action === 'request') {
            $.ajax({
                url: '/wp-json/vern-shipping-block/v1/request-sample',
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce
                },
                data: JSON.stringify({ product_id: productId }),
                success: function(result) {
                    // Update ALL buttons
                    $allButtons.text('SAMPLE REQUESTED');
                    $allButtons.addClass('disabled requested').prop('disabled', true);
                    $allButtons.removeClass('processing').css('opacity', '1');
                },
                error: function(err) {
                    console.log(err);
                    $allButtons.text('TRY AGAIN');
                    $allButtons.removeClass('processing').css('opacity', '1');
                }
            });
        }
    });

    /* --- STARKE GRID: COMPARE BUTTON LOGIC (Global Sync) --- */
    $(document).on('click', '.starke-compare-btn', function(e) {
        // 1. Setup Variables
        var $clickedBtn = $(this);
        var $wrapper = $clickedBtn.closest('.starke-compare-wrapper');
        var productId = $wrapper.data('product-id');
        
        // --- UPDATE: Select ALL wrapper instances for this Product (Popup + Shop + Single Page) ---
        var $allWrappers = $('.starke-compare-wrapper[data-product-id="' + productId + '"]');
        
        // If on Single Product Page, ensure we catch any wrappers there too (if strictly matching ID)
        if (typeof window.starke3d_data !== 'undefined' && window.starke3d_data.productId == productId) {
             // This adds redundancy to ensure the single page wrapper is definitely included
             var $singlePageWrapper = $('.starke-compare-wrapper[data-product-id="' + productId + '"]');
             $allWrappers = $allWrappers.add($singlePageWrapper);
        }

        var $allBtns = $allWrappers.find('.starke-compare-btn');
        var $allTexts = $allWrappers.find('.compare-text');
        var $allCheckboxes = $allWrappers.find('.compare-checkbox');
        
        // 2. Detect Click Target
        var isNativeCheckboxClick = $(e.target).is('.compare-checkbox');

        // 3. Handle Default Behavior
        if (isNativeCheckboxClick) {
            e.stopPropagation(); 
        } else {
            e.preventDefault();
            e.stopPropagation();
        }

        // 4. Determine Current State (using the clicked button as source of truth)
        var wasActive = $clickedBtn.hasClass('active');

        // 5. Execute Logic
        if (wasActive) {
            // CASE: Item WAS in list (Active)

            if (isNativeCheckboxClick) {
                // User unchecked the box -> REMOVE
                if (typeof remove_products_compare === 'function') {
                    remove_products_compare(productId);
                    if (typeof load_selected_list === 'function') { load_selected_list(); }
                }
                
                // Update UI on ALL instances (Popup + Single Page)
                $allTexts.text('COMPARE');
                $allBtns.removeClass('active');
                $allCheckboxes.prop('checked', false);
            } 
            else {
                // User clicked Button Text -> VIEW POPUP
                if (typeof load_smart_compare_table === 'function') {
                    load_smart_compare_table();
                } else {
                    var $widgetBtn = $('.berocket_compare_widget_start .berocket_open_compare').first();
                    if ($widgetBtn.length) $widgetBtn.click();
                }
            }
        }
        else {
            // CASE: Item WAS NOT in list (Inactive) -> ADD
            if (typeof add_products_compare === 'function') {
                add_products_compare(productId);
                if (typeof load_selected_list === 'function') { load_selected_list(); }
            }

            // Update UI on ALL instances (Popup + Single Page)
            $allTexts.text('ADDED');
            $allBtns.addClass('active');
            $allCheckboxes.prop('checked', true);
        }
    });

    console.log('Ran14');
    /**
     * ============================================================
     * EVENT LISTENER: Sample Removed
     * ============================================================
     * This ONLY runs when the user explicitly clicks "Remove item" 
     * on a sample in the Mini Cart. 
     * It resets both Shop Page buttons AND Single Product Page buttons.
     */
    document.body.addEventListener('starke_sample_removed', function(e) {
        var productId = e.detail.product_id;
        
        console.log('Sample Removed. Resetting buttons for Product ID:', productId);

        // UPDATE ON-PAGE MEMORY: Remove this ID so WPGB doesn't accidentally re-apply it
        var $syncDiv = $('#starke-sample-sync');
        if ($syncDiv.length) {
            try {
                var currentSamples = JSON.parse($syncDiv.attr('data-samples') || '[]');
                var newSamples = currentSamples.filter(function(id) { return id != productId; });
                $syncDiv.attr('data-samples', JSON.stringify(newSamples));
            } catch(err) {}
        }

        // 1. Reset Shop / Archive Page Buttons
        var $shopButtons = $('.starke-sample-btn[data-product-id="' + productId + '"]');
        if ($shopButtons.length) {
            $shopButtons.removeClass('disabled added processing requested')
                   .prop('disabled', false)
                   .attr('data-action', 'add') // FIX: Reset DOM attribute
                   .data('action', 'add')      // FIX: Reset jQuery data cache
                   .css({'opacity': '1', 'cursor': 'pointer'}) // <--- Added cursor reset here
                   .text('ADD SAMPLE');
        }

        // 2. Reset Single Product Page Button (3D Configurator)
        // We check if the global 'starke3d_data' exists (meaning we are on a product page)
        // and if the removed ID matches the current page's Product ID.
        if (typeof window.starke3d_data !== 'undefined' && window.starke3d_data.productId == productId) {
            var $singlePageBtn = $('#addSampleToCart_button');
            
            if ($singlePageBtn.length) {
                $singlePageBtn.text('ADD SAMPLE') // Reset text
                              .attr('data-action', 'add') // FIX: Reset DOM attribute
                              .data('action', 'add')      // FIX: Reset jQuery data cache
                              .prop('disabled', false) // Make clickable
                              .css('opacity', '1')
                              .css('cursor', 'pointer');
                
                console.log('Single Product Page button reset.');
            }
        }
    });
    
    console.log('Ran16');
    /**
     * DYNAMIC SYNC: Correct button text instantly on page load and WP Grid Builder load.
     */
    function syncSampleButtons() {
        var $syncDiv = $('#starke-sample-sync');
        if ($syncDiv.length && $syncDiv.attr('data-samples')) {
            try {
                var samplesInCart = JSON.parse($syncDiv.attr('data-samples'));
                
                // 1. Reset all buttons first to clear any stale state
                $('.starke-sample-btn.added').each(function() {
                    $(this).text('ADD SAMPLE')
                           .removeClass('disabled added processing requested')
                           .attr('data-action', 'add')
                           .data('action', 'add')
                           .prop('disabled', false)
                           .css({'cursor': 'pointer', 'opacity': '1'});
                });
                
                // 2. Lock buttons to "SAMPLE ADDED" for items actually in the cart
                samplesInCart.forEach(function(productId) {
                    $('.starke-sample-btn[data-product-id="' + productId + '"]')
                        .text('SAMPLE ADDED')
                        .removeClass('processing')
                        .addClass('disabled added')
                        .prop('disabled', true)
                        .css({'cursor': 'default', 'opacity': '1'});
                });
            } catch(e) {
                console.log('Error parsing sample sync data', e);
            }
        }
    }

    // ==============================================================================
    // EVENT BINDINGS & FIREWALL
    // We wrap this inside $(document).ready() so the browser waits until 
    // window.starke3d_data is completely defined before checking the firewall!
    // ==============================================================================
    $(document).ready(function() {
        
        // KILL SWITCH: If on Single Product Page, abort completely.
        if (typeof window.starke3d_data !== 'undefined' || $('body').hasClass('single-product')) {
            return; 
        }

        // 1. Run Initial Sync
        syncSampleButtons();

        // 2. Run whenever WooCommerce updates the mini-cart
        $(document.body).on('wc_fragments_refreshed wc_fragments_loaded', function() {
            syncSampleButtons();
        });

        // 3. WP Grid Builder Integration 
        window.WP_Grid_Builder = window.WP_Grid_Builder || {};
        window.WP_Grid_Builder.on = window.WP_Grid_Builder.on || function() {
            ( window.WP_Grid_Builder.events = window.WP_Grid_Builder.events || [] ).push( arguments );
        };

        window.WP_Grid_Builder.on( 'init', function( wpgb ) {
            if ( wpgb && wpgb.facets && typeof wpgb.facets.on === 'function' ) {
                wpgb.facets.on( 'loaded', function() {
                    syncSampleButtons();
                });
            }
        });

        if ( window.WP_Grid_Builder.instances ) {
            Object.keys( window.WP_Grid_Builder.instances ).forEach( function( id ) {
                var instance = window.WP_Grid_Builder.instances[id];
                if ( instance && instance.facets && typeof instance.facets.on === 'function' ) {
                    instance.facets.on( 'loaded', function() {
                        syncSampleButtons();
                    });
                }
            });
        }
    });
});