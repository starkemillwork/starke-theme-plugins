/* eslint-disable no-nested-ternary */
/* eslint-disable jsx-a11y/no-static-element-interactions */
/* eslint-disable jsx-a11y/click-events-have-key-events */
/* eslint-disable jsx-a11y/label-has-associated-control */
/* eslint-disable no-unused-expressions */
/* eslint-disable react/no-unescaped-entities */
/* eslint-disable @typescript-eslint/no-shadow */
/* eslint-disable @typescript-eslint/no-unused-vars */
/* eslint-disable no-console */
/* eslint-disable @wordpress/no-unused-vars-before-return */
/* eslint-disable no-undef */
/* eslint-disable @typescript-eslint/no-use-before-define */
/* eslint-disable react-hooks/exhaustive-deps */
/**
 * External dependencies
 */
import { SelectControl, SVG, Path } from '@wordpress/components';
import { useCallback, useEffect, useState, useRef, useMemo, createPortal } from '@wordpress/element';
import { CheckboxControl, ValidationInputError, extensionCartUpdate } from '@woocommerce/blocks-checkout';
import { getSetting } from '@woocommerce/settings';
import { useSelect, useDispatch } from '@wordpress/data';
import debounce from 'lodash/debounce';
import { TextInput } from '@woocommerce/blocks-components';
//const { TextInput } = wc.blocksComponents;
//const { TextInput } = wc.components;
const { optInDefaultText } = getSetting('vern_shipping_block_data', '');
const nameSpace = 'vern_shipping_block';
const CARTSTORE = 'wc/store/cart';
const CHECKOUTSTORE = 'wc/store/checkout';
const PAYMENTSTORE = 'wc/store/payment';
const isActive = true;

const Block = ({ children, checkoutExtensionData }) => {
	const { setExtensionData } = checkoutExtensionData;
	const [poNumber, setPoNumber] = useState('');
	const [jobContact, setJobContact] = useState('');
	const [jobContactCell, setJobContactCell] = useState('');
	const [savedAddresses, setSavedAddresses] = useState({
		samples_shipping_country: 'US'
	});
	const componentContainer = useRef(document.createElement('div'));
	const isInitialized = useRef(false);

	const { setValidationErrors, clearValidationError } = useDispatch('wc/store/validation');
	const { setShippingAddress } = useDispatch(CARTSTORE);

	const { cartItems, primaryPoNumberJobName, primaryJobContact, primaryJobContactCell, savedShippingAddresses } = useSelect((select) => {
		const store = select(CARTSTORE);
		const cartData = store.getCartData();
		const extensionData = cartData?.extensions[nameSpace];
		return {
			cartData,
			cartItems: cartData?.items || [],
			primaryPoNumberJobName: extensionData?.po_number_job_name,
			primaryJobContact: extensionData?.jobsite_contact,
			primaryJobContactCell: extensionData?.jobsite_contact_cell_number,
			savedShippingAddresses: extensionData?.saved_shipping_addresses
		};
	}, []);

	const { isShippingMode, getEditingShippingAddress, useShippingAsBilling } = useSelect((select) => {
		const store = select(CHECKOUTSTORE);
		return {
			isShippingMode: !store.prefersCollection(),
			getEditingShippingAddress: store.getEditingShippingAddress(),
			useShippingAsBilling: store.getUseShippingAsBilling()
		};
	}, []);

	useEffect(() => {
		if (isInitialized.current) return;
		if (
			primaryPoNumberJobName !== undefined &&
			primaryJobContact !== undefined &&
			primaryJobContactCell !== undefined &&
			savedShippingAddresses !== undefined
		) {
			setPoNumber(primaryPoNumberJobName);
			setJobContact(primaryJobContact);
			setJobContactCell(primaryJobContactCell);
			setSavedAddresses(savedShippingAddresses);
			isInitialized.current = true;
		}
	}, [primaryPoNumberJobName, primaryJobContact, primaryJobContactCell, savedShippingAddresses]);

	// Force sync local state if the store's data is overridden externally (e.g., during cart transition or unmounting/remounting)
	useEffect(() => {
		if (isInitialized.current) {
			if (primaryPoNumberJobName !== undefined && primaryPoNumberJobName !== poNumber) {
				setPoNumber(primaryPoNumberJobName);
			}
			if (primaryJobContact !== undefined && primaryJobContact !== jobContact) {
				setJobContact(primaryJobContact);
			}
			if (primaryJobContactCell !== undefined && primaryJobContactCell !== jobContactCell) {
				setJobContactCell(primaryJobContactCell);
			}
		}
	}, [primaryPoNumberJobName, primaryJobContact, primaryJobContactCell, poNumber, jobContact, jobContactCell]);

	// Check if any linear feet products exist in the cart
	const requiredKeys = ['Linear Feet', 'Thickness', 'Width', 'Lengths', 'Species', 'Finish'];
	const hasLinearFtProducts = useMemo(
		() =>
			cartItems.some((item) => {
				// Ensure the item has a name and item_data is an array.
				if (!item.name || !Array.isArray(item.item_data)) {
					return false;
				}
				// Exclude sample products.
				if (item.name.includes('(sample)')) {
					return false;
				}
				// Check that item_data contains all required keys with non-null, non-empty values.
				const hasAllRequired = requiredKeys.every((requiredKey) => {
					const found = item.item_data.find((entry) => entry.key === requiredKey);
					return found && found.value !== null && found.value !== '';
				});
				return hasAllRequired;
			}),
		[cartItems]
	);

	const [parentElement, setTargetElement] = useState(null);

	// This hook specifically targets the Payment options fieldset step numbering issue by forcing a browser reflow.
	useEffect(() => {
		if (useShippingAsBilling) {
			const paymentStepElement = document.getElementById('payment-method');
			// If the payment step element exists, we'll force it to repaint.
			if (paymentStepElement) {
				paymentStepElement.style.display = 'none';
				// The next line is the key: reading a geometric property flushes the style queue.
				paymentStepElement.offsetHeight;
				paymentStepElement.style.display = '';
			}
		}
	}, [useShippingAsBilling]);

	// Component placement logic
	useEffect(() => {
		// This function attempts to find the correct parent element and move our component.
		const placeComponent = () => {
			let targetParent = null;
			let isPickup = false;

			// React to the state, not the DOM existence directly.
			if (isShippingMode) {
				targetParent = document.getElementById('shipping-fields');
				isPickup = false;
			} else {
				targetParent = document.getElementById('pickup-options');
				isPickup = true;
			}

			if (targetParent) {
				// We found the target container, now find the specific spot to insert before.
				const referenceElement = targetParent.querySelector('.wc-block-components-checkout-step__content');
				if (referenceElement) {
					// Insert our component's container div into the DOM.
					referenceElement.parentElement.insertBefore(componentContainer.current, referenceElement);
				}
				// Update state to reflect the new parent and mode.
				setTargetElement(targetParent);
			} else {
				// If the target parent isn't in the DOM yet, clear our state.
				setTargetElement(null);
			}
		};

		// Run the placement logic immediately.
		placeComponent();

		// Since the checkout components can be dynamically rendered, we need to
		// handle cases where our target element appears slightly after the state change.
		// A MutationObserver is perfect for this.
		const observer = new MutationObserver((mutationsList, observer) => {
			// Check if the target parent for the current mode has been added to the DOM.
			const expectedId = isShippingMode ? 'shipping-fields' : 'pickup-options';
			if (document.getElementById(expectedId)) {
				placeComponent(); // If it's there, run our placement logic.
				observer.disconnect(); // We've done our job, so we can stop observing.
			}
		});

		// Start observing the main checkout container for changes.
		const checkoutContainer = document.querySelector('.wp-block-woocommerce-checkout');
		if (checkoutContainer) {
			observer.observe(checkoutContainer, {
				childList: true,
				subtree: true
			});
		}

		// This is the cleanup function. It runs when the component unmounts or
		// before the effect runs again.
		return () => {
			observer.disconnect(); // Always disconnect the observer on cleanup.
			// If our component's container is still in the DOM, remove it.
			// This prevents orphaned elements if the user navigates away.
			if (componentContainer.current.parentElement) {
				componentContainer.current.parentElement.removeChild(componentContainer.current);
			}
		};
		// This dependency array is key. The effect will ONLY re-run when
		// `isShippingMode` changes, which is exactly when we need to re-evaluate
		// the component's position.
	}, [isShippingMode]);

	// --- NEW: Google Maps Places API (New) AutocompleteSuggestion Method ---
	useEffect(() => {
		if (!isActive || !isShippingMode) return;

		const tryInit = () => {
			const addressInput = document.getElementById('shipping-address_1');

			if (!addressInput) return false;
			// Check for the new places library structure
			if (!window?.google?.maps?.places) return false;

			if (!addressInput.dataset.autocompleteAttached) {
				addressInput.dataset.autocompleteAttached = 'true';
				addressInput.setAttribute('autocomplete', 'starke-custom-address');
				let sessionToken = new window.google.maps.places.AutocompleteSessionToken();

				const dropdown = document.createElement('ul');
				dropdown.id = 'starke-custom-autocomplete-dropdown';
				dropdown.style.cssText =
					'position: absolute; z-index: 1000; background: white; border: 1px solid #ccc; border-radius: 4px; width: 100%; max-height: 250px; overflow-y: auto; list-style: none; padding: 0; margin-top: 4px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); display: none;';

				addressInput.parentNode.style.position = 'relative';
				addressInput.parentNode.appendChild(dropdown);

				let typingTimer;
				addressInput.addEventListener('input', (e) => {
					clearTimeout(typingTimer);
					const val = e.target.value;

					if (!val) {
						dropdown.style.display = 'none';
						return;
					}

					// Use async/await for the new Google Maps Promises architecture
					typingTimer = setTimeout(async () => {
						try {
							const request = {
								input: val,
								sessionToken,
								includedRegionCodes: ['US']
							};

							const { suggestions } = await window.google.maps.places.AutocompleteSuggestion.fetchAutocompleteSuggestions(request);

							if (!suggestions || suggestions.length === 0) {
								dropdown.style.display = 'none';
								return;
							}

							dropdown.innerHTML = '';
							dropdown.style.display = 'block';

							for (const suggestion of suggestions) {
								const prediction = suggestion.placePrediction;
								if (!prediction) continue;

								const li = document.createElement('li');
								li.style.cssText =
									'padding: 10px; cursor: pointer; border-bottom: 1px solid #eee; font-family: inherit; font-size: 14px;';

								// Safely map the new Google API response structure
								const mainText = prediction.mainText?.text || '';
								const secondaryText = prediction.secondaryText?.text || '';

								li.innerHTML = `<strong style="color: #6431F6;">${mainText}</strong> <span style="color: #666; font-size: 12px; margin-left: 5px;">${secondaryText}</span>`;

								li.addEventListener('mouseover', () => (li.style.backgroundColor = '#f4f4f4'));
								li.addEventListener('mouseout', () => (li.style.backgroundColor = 'white'));

								li.addEventListener('click', async () => {
									dropdown.style.display = 'none';

									try {
										const place = prediction.toPlace();
										// Pass the session token to close the free-tier billing loop!
										await place.fetchFields({ fields: ['addressComponents'], sessionToken });

										let streetNumber = '';
										let route = '';
										let state = '';
										let zip = '';

										// Variables to catch edge-case towns
										let locality = '';
										let sublocality = '';
										let neighborhood = '';

										for (const component of place.addressComponents) {
											const types = component.types || [];

											if (types.includes('street_number')) streetNumber = component.longText;
											if (types.includes('route')) route = component.shortText;

											// Grab all possible town/city classifications
											if (types.includes('locality')) locality = component.longText;
											if (types.includes('sublocality')) sublocality = component.longText;
											if (types.includes('administrative_area_level_3')) sublocality = component.longText; // Often used for US townships
											if (types.includes('neighborhood')) neighborhood = component.longText;

											if (types.includes('administrative_area_level_1')) state = component.shortText;
											if (types.includes('postal_code')) zip = component.longText;
										}

										// The Fallback: If 'locality' is blank, grab the sublocality or neighborhood instead.
										const city = locality || sublocality || neighborhood || '';

										const address1 = `${streetNumber} ${route}`.trim();

										setShippingAddress({
											address_1: address1,
											city,
											state,
											postcode: zip,
											address_2: '',
											country: 'US'
										});

										// Generate a fresh token in case they want to search a new address
										sessionToken = new window.google.maps.places.AutocompleteSessionToken();
									} catch (error) {
										console.error('Starke Place Details Error:', error);
									}
								});
								dropdown.appendChild(li);
							}
						} catch (error) {
							console.error('Starke Places API Error:', error);
						}
					}, 300);
				});

				document.addEventListener('click', (e) => {
					if (e.target !== addressInput && e.target !== dropdown && !dropdown.contains(e.target)) {
						dropdown.style.display = 'none';
					}
				});

				return true;
			}
			return true;
		};

		if (tryInit()) {
			return () => {
				const addressInput = document.getElementById('shipping-address_1');
				if (addressInput) delete addressInput.dataset.autocompleteAttached;
			};
		}

		const observer = new MutationObserver((mutationsList, obs) => {
			if (tryInit()) obs.disconnect();
		});

		const checkoutContainer = document.querySelector('.wp-block-woocommerce-checkout') || document.body;
		// Fix for the Node error: ensure the container actually exists before observing
		if (checkoutContainer) {
			observer.observe(checkoutContainer, { childList: true, subtree: true });
		}

		const networkCheck = setInterval(() => {
			if (tryInit()) clearInterval(networkCheck);
		}, 500);

		return () => {
			if (observer) observer.disconnect();
			clearInterval(networkCheck);
			const addressInput = document.getElementById('shipping-address_1');
			if (addressInput) delete addressInput.dataset.autocompleteAttached;
		};
	}, [isShippingMode, setShippingAddress]);

	// The current location for modifying all miscellaneous checkout HTML elements (this useEffect)
	useEffect(() => {
		// --- Get all element references with optimized, single-line selectors ---
		const shippingCountryElement = document.querySelector('#shipping .wc-block-components-address-form__country');
		const billingCountryElement = document.querySelector('#billing .wc-block-components-address-form__country');
		const deliveryMethodTitleElement = document.querySelector('#shipping-method .wc-block-components-checkout-step__title');
		const deliveryMethodDescriptionElement = document.querySelector('#shipping-method .wc-block-components-checkout-step__description');
		const shippingAddressTitleElement = document.querySelector('#shipping-fields .wc-block-components-checkout-step__title');
		const shippingAddressDescriptionElement = document.querySelector('#shipping-fields .wc-block-components-checkout-step__description');
		const shippingAddressEditElement = document.querySelector('#shipping-fields .wc-block-components-address-card__edit');
		const pickupLocationTitleElement = document.querySelector('#pickup-options .wc-block-components-checkout-step__title');
		const shippingOptionTitleElement = document.querySelector('#shipping-option .wc-block-components-checkout-step__title');
		const billingAddressEditElement = document.querySelector('#billing-fields .wc-block-components-address-card__edit');

		// --- Perform all DOM manipulations ---

		// Hide Country fields
		if (shippingCountryElement && shippingCountryElement.style.display !== 'none') shippingCountryElement.style.display = 'none';
		if (billingCountryElement && billingCountryElement.style.display !== 'none') billingCountryElement.style.display = 'none';

		// Change Delivery Method step verbiage
		if (deliveryMethodTitleElement)
			deliveryMethodTitleElement.innerText = hasLinearFtProducts ? 'Delivery for Linear Feet Profiles' : 'Delivery for Samples';
		if (deliveryMethodDescriptionElement)
			deliveryMethodDescriptionElement.innerText = hasLinearFtProducts
				? 'Select how you would like to receive your Linear Feet Profiles.'
				: 'Select how you would like to receive your Samples.';

		// Change Shipping Address step verbiage
		if (shippingAddressTitleElement)
			shippingAddressTitleElement.innerText = hasLinearFtProducts
				? 'Shipping Address for Linear Feet Profiles'
				: 'Shipping Address for Samples';
		if (shippingAddressDescriptionElement)
			shippingAddressDescriptionElement.innerText = hasLinearFtProducts
				? 'Enter the address/info where you want your Linear Feet Profiles delivered.'
				: 'Enter the address/info where you want your Samples delivered.';
		if (shippingAddressEditElement && shippingAddressEditElement.innerText !== 'Edit/Add') shippingAddressEditElement.innerText = 'Edit/Add';

		// Change Pickup Locations step verbiage
		if (pickupLocationTitleElement)
			pickupLocationTitleElement.innerText = hasLinearFtProducts ? 'Pickup Location for Linear Feet Profiles' : 'Pickup Location for Samples';

		// Change Shipping Option step verbiage
		if (shippingOptionTitleElement)
			shippingOptionTitleElement.innerText = hasLinearFtProducts ? 'Shipping Option for Linear Feet Profiles' : 'Shipping Option for Samples';

		// Change Billing Address Edit button verbiage
		if (billingAddressEditElement && billingAddressEditElement.innerText !== 'Edit/Add') billingAddressEditElement.innerText = 'Edit/Add';
	}, [isShippingMode, hasLinearFtProducts]);

	// THE BOUNCER: Actively listen for ghost errors without the [] dependency.
	const validationState = useSelect((select) => {
		const store = select('wc/store/validation');
		return {
			// NEW: Grab rogue native WooCommerce errors that get stuck
			shippingStateError: store.getValidationError('shipping_state'),
			shippingZipError: store.getValidationError('shipping_postcode'),
			shippingCityError: store.getValidationError('shipping_city'),
			shippingAddressError: store.getValidationError('shipping_address_1'),
			jobContact: store.getValidationError('jobsite_contact-error'),
			jobContactCell: store.getValidationError('jobsite_contact_cell_number-error') // FIXED: Matched exact component ID
		};
	});

	// THE BOUNCER: Reactively scrubs ghost errors the exact millisecond they appear in the store.
	useEffect(() => {
		// Scrub Shipping Selector AND rogue native shipping errors if we are in Pickup mode
		if (!isShippingMode) {
			if (validationState.shippingStateError) clearValidationError('shipping_state');
			if (validationState.shippingZipError) clearValidationError('shipping_postcode');
			if (validationState.shippingCityError) clearValidationError('shipping_city');
			if (validationState.shippingAddressError) clearValidationError('shipping_address_1');
			if (validationState.jobContact) clearValidationError('jobsite_contact-error');
			if (validationState.jobContactCell) clearValidationError('jobsite_contact_cell_number-error'); // FIXED: Matched exact component ID
		}
	}, [isShippingMode, hasLinearFtProducts, validationState, clearValidationError]);

	const blockContent = (
		<div
			className="job-info-and-address-selector-div"
			id="job-info-and-address-selector-div"
			style={{
				color: 'black',
				display: 'flex',
				flexWrap: 'wrap',
				gap: '0 16px',
				justifyContent: 'space-between',
				marginBottom: '16px'
			}}
		>
			<TextInputV_JobInfo
				key={`po-number-${hasLinearFtProducts}`} // Forces the component to unmount/remount, clearing the 'hasUserEdited' lock
				nameSpace={nameSpace}
				setExtensionData={setExtensionData}
				setValidationErrors={setValidationErrors}
				clearValidationError={clearValidationError}
				paramName="po_number_job_name"
				value={poNumber}
				errorMessage="Please enter a PO number or job name"
				id="po_number_job_reference"
				label={hasLinearFtProducts ? 'PO Number/Job Label' : 'PO Number/Job Label (optional)'}
				autoComplete="off"
				required={hasLinearFtProducts}
				classNames={'text-input-v-component-field-full'}
				extraStyle={{ marginTop: '0px' }}
				parentStyle={{ marginTop: '0px' }}
				isShippingMode={isShippingMode}
			/>
			{isShippingMode && hasLinearFtProducts && (
				<>
					<TextInputV_JobInfo
						nameSpace={nameSpace}
						setExtensionData={setExtensionData}
						setValidationErrors={setValidationErrors}
						clearValidationError={clearValidationError}
						paramName="jobsite_contact"
						value={jobContact}
						errorMessage="Please enter a valid jobsite contact"
						id="jobsite_contact"
						label="Jobsite Contact"
						autoComplete="off"
						required={hasLinearFtProducts && isShippingMode}
						classNames={'text-input-v-component-field-half'}
						extraStyle={{}}
						parentStyle={{ marginTop: '16px' }}
						isShippingMode={isShippingMode}
					/>
					<TextInputV_JobInfo
						nameSpace={nameSpace}
						setExtensionData={setExtensionData}
						setValidationErrors={setValidationErrors}
						clearValidationError={clearValidationError}
						paramName="jobsite_contact_cell_number"
						value={jobContactCell}
						errorMessage="Please enter a valid jobsite contact cell number"
						id="jobsite_contact_cell_number"
						label="Contact Number"
						autoComplete="off"
						required={hasLinearFtProducts && isShippingMode}
						classNames={'text-input-v-component-field-half'}
						extraStyle={{}}
						parentStyle={{ marginTop: '16px' }}
						formatAs="phone"
						isShippingMode={isShippingMode}
					/>
				</>
			)}
			{isShippingMode && (
				<SelectControlV_ShippingAddress
					nameSpace={nameSpace}
					savedAddresses={savedAddresses}
					setExtensionData={setExtensionData}
					setValidationErrors={setValidationErrors}
					clearValidationError={clearValidationError}
					errorMessage="Please select a valid shipping address"
					id="shipping_address_selector"
					label="Saved Shipping Addresses"
					required={false}
					extraStyle={{}}
					extraParentStyle={{ flex: '1 0 100%' }}
				/>
			)}
		</div>
	);

	return (
		<>
			{isActive && parentElement ? createPortal(blockContent, componentContainer.current) : null}
			<PhoneInputSwapper targetId="shipping-phone" addressType="shipping" />
			<PhoneInputSwapper targetId="billing-phone" addressType="billing" />
		</>
	);
};

// UTILITY FUNCTIONS

const formatAddress = (address) => {
	const { address_1, address_2, city, state, postcode } = address;
	let formatted = address_1;
	if (address_2) {
		formatted += ', ' + address_2;
	}
	formatted += ', ' + city + ', ' + state + ' ' + postcode;
	return formatted;
};

/**
 * Formats a string of numbers into a US phone number format (XXX-XXX-XXXX).
 *
 * @param {string} value The input string.
 * @return {string} The formatted phone number.
 */
const formatPhoneNumber = (value) => {
	// If the value is empty, return it as is.
	if (!value) return value;

	// 1. Remove all characters that are not digits.
	const phoneNumber = value.replace(/[^\d]/g, '');
	const phoneNumberLength = phoneNumber.length;

	// 2. Apply formatting based on the number of digits.
	if (phoneNumberLength < 4) {
		return phoneNumber;
	}
	if (phoneNumberLength < 7) {
		return `${phoneNumber.slice(0, 3)}-${phoneNumber.slice(3)}`;
	}
	// 3. Slice the final string to a max of 10 digits to get XXX-XXX-XXXX
	return `${phoneNumber.slice(0, 3)}-${phoneNumber.slice(3, 6)}-${phoneNumber.slice(6, 10)}`;
};

// COMPONENTS

// TextInput component I created to match WooCommerce's TextInput component from the checkout block
function TextInputV_JobInfo({
	nameSpace,
	setExtensionData,
	setValidationErrors,
	clearValidationError,
	paramName,
	value,
	errorMessage,
	id,
	label,
	required,
	classNames,
	extraStyle,
	parentStyle,
	autoComplete,
	formatAs,
	isShippingMode
}) {
	const [valueState, setValueState] = useState('');
	const [elementBlurred, setElementBlurred] = useState(false);
	const hasUserEdited = useRef(false);

	// Validation
	const textInputErrorID = `${id}-error`;
	const textInputError = useSelect((select) => {
		const store = select('wc/store/validation');
		return store.getValidationError(textInputErrorID);
	});

	const { isBeforeProcessing } = useSelect((select) => {
		const store = select(CHECKOUTSTORE);
		return {
			isBeforeProcessing: store.isBeforeProcessing()
		};
	}, []);

	useEffect(() => {
		return () => {
			clearValidationError(textInputErrorID);
		};
	}, [clearValidationError, textInputErrorID]);

	useEffect(() => {
		if (!hasUserEdited.current && value) {
			setValueState(value);
		}
	}, [value]);

	// Debounce sending data to the data store
	const debouncedSetExtensionData = useCallback(
		debounce((nameSpace, id, value) => {
			setExtensionData(nameSpace, id, value);
			if (hasUserEdited.current) {
				// Currently used
				extensionCartUpdate({
					namespace: nameSpace,
					data: {
						action: 'update_job_info_in_session',
						[id]: value ? value : ''
					}
				})
					.then(() => {
						console.log(id, value);
					})
					.catch((error) => {
						// Handle error.
						console.log('Failed');
					});
			}
		}, 300),
		[]
	);

	useEffect(() => {
		//if (!hasUserEdited.current) return;

		const label = document.querySelector(`label[for=${id}]`);
		const showError = elementBlurred || isBeforeProcessing;
		if (valueState === '') {
			if (required) {
				console.log('isShippingMode', isShippingMode);
				console.log('Setting validation error', textInputErrorID);
				setValidationErrors({
					[textInputErrorID]: {
						message: errorMessage,
						hidden: !showError
					}
				});
				if (label) {
					label.style.color = showError ? '#cc1818' : 'hsla(0,0%,7%,.7)';
				}
			}
		} else {
			if (label) {
				label.style.color = 'hsla(0,0%,7%,.7)';
			}
			if (required) {
				if (textInputError) {
					console.log('isShippingMode', isShippingMode);
					console.log('Removing validation error', textInputErrorID);
					clearValidationError(textInputErrorID);
				}
			}
		}
	}, [valueState, elementBlurred, isBeforeProcessing, setValidationErrors, clearValidationError]);

	useEffect(() => {
		if (!hasUserEdited.current) return;
		if ((required && valueState !== '') || !required) {
			debouncedSetExtensionData(nameSpace, paramName, valueState);
		}
	}, [valueState]);

	useEffect(() => {
		const element = document.querySelector(`#${id}`);
		if (element && element.parentElement) {
			element.parentElement.style.marginTop = '0px';
		}
	}, []);

	function handleTextInput(input) {
		const valueToSet = formatAs === 'phone' ? formatPhoneNumber(input) : input;
		setValueState(valueToSet);
		setElementBlurred(false);
		hasUserEdited.current = true;
	}

	function handleTextInputBlur() {
		if (!elementBlurred) {
			setElementBlurred(true);
		}
	}

	const baseStyle = {
		color: textInputError?.hidden === false ? '#cc1818' : 'black',
		borderColor: textInputError?.hidden === false ? '#cc1818' : 'black'
	};
	const fullStyle = { ...baseStyle, ...extraStyle };
	return (
		<div className={classNames} style={parentStyle}>
			<TextInput
				id={id}
				label={label}
				value={valueState}
				required={required}
				onChange={(inputValue) => handleTextInput(inputValue)}
				onBlur={handleTextInputBlur}
				feedback={
					textInputError?.hidden === false && (
						<div className="wc-block-components-validation-error">
							<p>
								<SVG
									xmlns="http://www.w3.org/2000/svg"
									viewBox="-2 -2 24 24"
									width="24"
									height="24"
									aria-hidden="true"
									focusable="false"
								>
									<Path d="M10 2c4.42 0 8 3.58 8 8s-3.58 8-8 8-8-3.58-8-8 3.58-8 8-8zm1.13 9.38l.35-6.46H8.52l.35 6.46h2.26zm-.09 3.36c.24-.23.37-.55.37-.96 0-.42-.12-.74-.36-.97s-.59-.35-1.06-.35-.82.12-1.07.35-.37.55-.37.97c0 .41.13.73.38.96.26.23.61.34 1.06.34s.8-.11 1.05-.34z"></Path>
								</SVG>
								{textInputError?.message}
							</p>
						</div>
					)
				}
				style={fullStyle}
				autoComplete={autoComplete}
			/>
		</div>
	);
}

// SelectControl component I created to match WooCommerce's Select component from the checkout block
function SelectControlV_ShippingAddress({
	nameSpace,
	savedAddresses,
	setExtensionData,
	setValidationErrors,
	clearValidationError,
	errorMessage,
	id,
	label,
	required,
	extraStyle,
	extraParentStyle
}) {
	const [address, setAddress] = useState('');
	const [elementBlurred, setElementBlurred] = useState(false);
	const [removedPlaceholder, setRemovedPlaceholder] = useState(0);
	const [addresses, setAddresses] = useState([]);
	const [counter, setCounter] = useState(0);
	const thisComponent = useRef(null);
	const parentElement = useRef(null);
	const inputControlBackdrop = useRef(null);
	const selectIcon = useRef(null);
	//const [fullOptions, setFullOptions] = useState([{ label: 'Select a saved shipping address', value: '' }, ...options, ]);

	// Validation
	const selectInputErrorID = `${id}-error`;
	const selectInputError = useSelect((select) => {
		const store = select('wc/store/validation');
		return store.getValidationError(selectInputErrorID);
	});

	const { setShippingAddress, setBillingAddress } = useDispatch(CARTSTORE);

	useEffect(() => {
		return () => {
			clearValidationError(selectInputErrorID);
		};
	}, [clearValidationError, selectInputErrorID]);

	useEffect(() => {
		if (Array.isArray(savedAddresses) && savedAddresses.length > 0) {
			setAddresses(savedAddresses);
		}
	}, [savedAddresses]);

	// Map each address object to an option for the select control.
	const options = addresses.map((address, index) => {
		return {
			// Use JSON.stringify to store the object as a string. Alternatively, use a unique ID if available.
			value: JSON.stringify(address),
			label: formatAddress(address)
		};
	});

	// Add a placeholder option at the beginning.
	options.unshift({
		value: '',
		label: 'Select a saved shipping address'
	});

	// Debounce sending data to the data store
	const debouncedSetExtensionData = useCallback(
		debounce((value) => {
			const fullAddress = JSON.parse(value);
			setShippingAddress(fullAddress);
			console.log('fullAddress', fullAddress);
		}, 0),
		[]
	);

	useEffect(() => {
		//const label = document.querySelector(`label[for=${id}]`);
		if (address === '') {
			if (required) {
				setValidationErrors({
					[selectInputErrorID]: {
						message: errorMessage,
						hidden: !elementBlurred
					}
				});
				//if (label) {
				//	label.style.color = elementBlurred ? '#cc1818' : 'hsla(0,0%,7%,.7)';
				//}
			}
		} else {
			//if (label) {
			//	label.style.color = 'hsla(0,0%,7%,.7)';
			//}
			if (required) {
				clearValidationError(selectInputErrorID);
			}
			debouncedSetExtensionData(address);
			console.log('Selector Ran');
		}
	}, [address, setValidationErrors, clearValidationError]);

	useEffect(() => {
		if (!thisComponent.current) {
			thisComponent.current = document.getElementById(id);
		}
		if (thisComponent.current) {
			if (!parentElement.current) {
				parentElement.current = document.getElementById(id).parentElement;
			}
			if (parentElement.current) {
				if (!selectIcon.current) {
					selectIcon.current = parentElement.current.querySelector('[data-wp-component="InputControlSuffixWrapper"]');
					if (selectIcon.current) {
						selectIcon.current.style.transform = 'scale(1.35)';
						selectIcon.current.style.paddingInlineEnd = '19px';
					}
				}
				if (!inputControlBackdrop.current) {
					inputControlBackdrop.current = parentElement.current.querySelector('.components-input-control__backdrop');
					if (inputControlBackdrop.current) {
						inputControlBackdrop.current.style.display = 'none';
					}
				}
			}
		}
	});

	function handleSelectInput(valueState) {
		setAddress(valueState);
		setElementBlurred(false);

		/*if (valueState !== '' && removedPlaceholder === 0) {
			setFullOptions((prevOptions) =>
			prevOptions.filter((option) => option.value !== '')
		  );
		  setRemovedPlaceholder(1);
		}*/
	}

	function handleSelectInputBlur() {
		if (!elementBlurred) {
			setElementBlurred(true);
		}
	}

	const baseStyle = {
		color: selectInputError?.hidden === false ? '#cc1818' : 'black',
		border: '1px solid',
		borderColor: selectInputError?.hidden === false ? '#cc1818' : 'black',
		borderRadius: '4px',
		width: '100%',
		height: '100%',
		margin: '0px',
		padding: '15px 8px 0px',
		fontSize: '18px',
		minHeight: '56px',
		fontFamily: 'Inter, sans-serif',
		whiteSpace: 'pre-line'
	};
	const fullStyle = { ...baseStyle, ...extraStyle };

	const baseParentStyle = {
		height: 'fit-content',
		width: '100%',
		position: 'relative',
		boxSizing: 'border-box',
		marginTop: '16px',
		backGround: 'transparent',
		border: 'none'
	};
	const fullParentStyle = { ...baseParentStyle, ...extraParentStyle };

	//const options = [ { label: 'New Jersey', value: 'NJ' }, { label: 'New York', value: 'NY' } ];
	//const fullOptions = [ { label: 'Select a state', value: '' }, ...options ];

	return (
		<>
			{options.length > 1 && (
				<div style={fullParentStyle}>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						id={id}
						label=""
						options={options}
						onChange={(inputValue) => handleSelectInput(inputValue)}
						onBlur={handleSelectInputBlur}
						value={address}
						style={fullStyle}
					/>
					<label
						style={{
							color: selectInputError?.hidden === false ? '#cc1818' : 'hsla(0,0%,7%,.7)',
							background: 'none',
							position: 'absolute',
							left: '9px',
							top: '2px',
							transform: 'translateY(15%) scale(.75)',
							transformOrigin: 'top left',
							whiteSpace: 'nowrap',
							zIndex: '1',
							lineHeight: '1.25',
							margin: '0',
							maxWidth: 'calc(100% - 32px)'
						}}
					>
						{label}
					</label>
					{selectInputError?.hidden === false && <ValidationInputError errorMessage={selectInputError?.message} />}
				</div>
			)}
		</>
	);
}

// This is a new, self-contained component for our solution.
const PhoneInputSwapper = ({ targetId, addressType }) => {
	const [mountNode, setMountNode] = useState(null);
	const { useShippingAsBilling, isShippingMode } = useSelect((select) => {
		const store = select(CHECKOUTSTORE);
		return {
			useShippingAsBilling: store.getUseShippingAsBilling(),
			isShippingMode: !store.prefersCollection()
		};
	}, []);
	const { setShippingAddress, setBillingAddress } = useDispatch(CARTSTORE);

	// Get the current phone number from the WooCommerce data store.
	const { phoneNumber } = useSelect(
		(select) => {
			const store = select(CARTSTORE);
			const cartData = store.getCartData();
			const address = addressType === 'shipping' ? cartData.shippingAddress : cartData.billingAddress;
			return {
				phoneNumber: address.phone
			};
		},
		[addressType]
	);

	// Auto-format legacy unformatted phone numbers from the database on load
	useEffect(() => {
		if (phoneNumber) {
			const formatted = formatPhoneNumber(phoneNumber);
			// If the raw number doesn't match the formatted version, update the store
			if (formatted !== phoneNumber) {
				if (addressType === 'shipping') {
					setShippingAddress({ phone: formatted });
				} else {
					setBillingAddress({ phone: formatted });
				}
			}
		}
	}, [phoneNumber, addressType, setShippingAddress, setBillingAddress]);

	// Find the container for the original input. This is where we'll inject our component.
	useEffect(() => {
		const originalInput = document.getElementById(targetId);
		if (originalInput) {
			const parentWrapper = originalInput.parentElement;
			// Hide the original input
			originalInput.style.display = 'none';

			// MODIFIED: Find and hide the original label
			const originalLabel = parentWrapper.querySelector('label[for="' + targetId + '"]');
			if (originalLabel) {
				originalLabel.style.display = 'none';
			}

			if (parentWrapper) {
				parentWrapper.style.marginTop = '0px';
			}
			// Set the parent node as our injection point
			setMountNode(parentWrapper);
		}
	}, [targetId, useShippingAsBilling, isShippingMode]);

	// This function will be called when our custom input changes.
	const handleValueChange = (newPhoneNumber) => {
		const formatted = formatPhoneNumber(newPhoneNumber);

		// Dispatch the change to the correct WooCommerce data store action.
		if (addressType === 'shipping') {
			setShippingAddress({ phone: formatted });
		} else {
			setBillingAddress({ phone: formatted });
		}
	};

	// If we have found a place to inject our component, render it there using a Portal.
	if (!mountNode) {
		return null;
	}

	return createPortal(
		<TextInput
			id={`${targetId}-custom`}
			type="tel"
			label="Phone (optional)"
			value={phoneNumber}
			onChange={handleValueChange}
			// MODIFIED: Add attributes to match the original input
			autocapitalize="characters"
			autoComplete="tel"
			// MODIFIED: Add style to fix vertical alignment
			style={{ marginTop: '0px' }}
		/>,
		mountNode
	);
};

export default Block;
