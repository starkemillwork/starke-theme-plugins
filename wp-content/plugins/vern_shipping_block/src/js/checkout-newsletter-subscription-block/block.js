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
import { useCallback, useEffect, useState, useRef, useMemo } from '@wordpress/element';
import { CheckboxControl, ValidationInputError, extensionCartUpdate } from '@woocommerce/blocks-checkout';
import { getSetting } from '@woocommerce/settings';
import { useSelect, useDispatch, select } from '@wordpress/data';
import debounce from 'lodash/debounce';
import isEqual from 'lodash/isEqual';
import { TextInput } from '@woocommerce/blocks-components';
//const { TextInput } = wc.blocksComponents;
const { optInDefaultText } = getSetting('vern_shipping_block_data', '');
const nameSpace = 'vern_shipping_block';
const CARTSTORE = 'wc/store/cart';
const CHECKOUTSTORE = 'wc/store/checkout';
const isActive = true;

const Block = ({ children, checkoutExtensionData, cart }) => {
	const { setExtensionData } = checkoutExtensionData;
	const [address, setAddress] = useState(null);
	const [samplesPoNumber, setSamplesPoNumber] = useState('');
	const [samplesShippingFields, setSamplesShippingFields] = useState({});
	const [stateOptions, setStateOptions] = useState([]);
	const [savedAddresses, setSavedAddresses] = useState({ country: 'US' });
	const [useSamplesShippingAsBilling, setUseSamplesShippingAsBilling] = useState(false);
	const isInitialized = useRef(false);

	/*const parentElement = useRef(null);
	const thisElementsFieldset = useRef(null);
	const siblingElement = useRef(null);
	const shippingOptionSiblingElementID = useRef(null);
	const prevshippingOptionSiblingElementID = useRef(null);*/

	const { setBillingAddress, setShippingAddress } = useDispatch(CARTSTORE);
	const { setValidationErrors, clearValidationError } = useDispatch('wc/store/validation');

	const { cartItems, samplesPoNumberJobName, samplesFullShippingAddress, savedShippingAddresses, usaStates } = useSelect((select) => {
		const store = select(CARTSTORE);
		const cartData = store.getCartData();
		const extensionData = cartData?.extensions[nameSpace];
		return {
			cartData,
			cartItems: cartData?.items || [],
			samplesPoNumberJobName: extensionData?.samples_address_po_number_job_name,
			samplesFullShippingAddress: extensionData?.samples_full_shipping_address,
			savedShippingAddresses: extensionData?.saved_shipping_addresses,
			usaStates: extensionData?.usa_states || []
		};
	}, []);

	const { isShippingMode, useShippingAsBilling } = useSelect((select) => {
		const store = select(CHECKOUTSTORE);
		const useShippingAsBilling = store.getUseShippingAsBilling();
		return {
			isShippingMode: !store.prefersCollection(),
			useShippingAsBilling
		};
	}, []);

	useEffect(() => {
		if (isInitialized.current) return;
		// 2. Check if the essential data has loaded from the store.
		//    We check for `undefined` because the initial value could be an empty string or null.
		if (
			samplesPoNumberJobName !== undefined &&
			samplesFullShippingAddress !== undefined &&
			savedShippingAddresses !== undefined &&
			usaStates !== undefined
		) {
			// 3. The data is available AND we haven't initialized yet, so run all setters.
			setSamplesPoNumber(samplesPoNumberJobName);
			setSamplesShippingFields(samplesFullShippingAddress);
			setSavedAddresses(savedShippingAddresses);
			setExtensionData(nameSpace, 'samples_full_shipping_address', samplesFullShippingAddress);
			const formattedStateOptions = Object.entries(usaStates).map(([code, name]) => ({
				label: name,
				value: code
			}));
			setStateOptions(formattedStateOptions);
			isInitialized.current = true;
		}
	}, [samplesPoNumberJobName, samplesFullShippingAddress, savedShippingAddresses, usaStates]);

	const debouncedSendFullAddress = useCallback(
		debounce((fields) => {
			// 1. BROADCAST START: Tell the page we are updating
			document.body.dispatchEvent(new CustomEvent('starke_samples_address_updating_start'));

			setExtensionData(nameSpace, 'samples_full_shipping_address', fields);
			extensionCartUpdate({
				namespace: nameSpace,
				data: {
					action: 'update_samples_full_shipping_address',
					fields
				}
			})
				.then(() => {
					console.log('Full address sent:', fields);
					useSamplesShippingAsBilling && setBillingAddress(fields);
				})
				.catch((err) => {
					console.error('Failed to send full address', err);
				})
				.finally(() => {
					// 2. BROADCAST END: Tell the page the update is finished
					document.body.dispatchEvent(new CustomEvent('starke_samples_address_updating_end'));
				});
		}, 250),
		[useSamplesShippingAsBilling]
	);

	const updateSampleShippingField = (id, value, required) => {
		setSamplesShippingFields((prev) => {
			const updated = { ...prev, [id]: value };
			if ((required && value !== '') || !required) debouncedSendFullAddress(updated);
			return updated;
		});
	};

	useEffect(() => {
		useSamplesShippingAsBilling && debouncedSendFullAddress(samplesShippingFields);
	}, [useSamplesShippingAsBilling]);

	useEffect(() => {
		if (address) {
			setSamplesShippingFields(address);
			debouncedSendFullAddress(address);
		}
	}, [address]);

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

	// Check if any sample products exist in the cart
	const hasSampleProducts = useMemo(
		() =>
			cartItems.some((item) => {
				if (!item.name || !Array.isArray(item.item_data)) {
					return false;
				}
				return item.name.includes('(sample)') && item.item_data.length <= 2 && item.id !== 444;
			}),
		[cartItems]
	);

	// Track previous state of linear feet products to detect the removal transition
	const prevHasLinearFtProducts = useRef(hasLinearFtProducts);

	useEffect(() => {
		// Detect transition: Linear feet products were just removed, leaving ONLY samples.
		if (prevHasLinearFtProducts.current === true && hasLinearFtProducts === false && hasSampleProducts === true) {
			// 1. Promote Samples PO Number to the Primary PO Number
			if (samplesPoNumberJobName) {
				setExtensionData(nameSpace, 'po_number_job_name', samplesPoNumberJobName);
				extensionCartUpdate({
					namespace: nameSpace,
					data: {
						action: 'update_job_info_in_session',
						po_number_job_name: samplesPoNumberJobName
					}
				}).catch((err) => console.error('Failed to update primary PO number', err));
			}

			// 2. Promote Samples Shipping Address to the Primary Shipping Address
			if (samplesFullShippingAddress && Object.keys(samplesFullShippingAddress).length > 0) {
				setShippingAddress(samplesFullShippingAddress);
			}
		}

		// Update the ref for the next render cycle
		prevHasLinearFtProducts.current = hasLinearFtProducts;
	}, [hasLinearFtProducts, hasSampleProducts, samplesPoNumberJobName, samplesFullShippingAddress, setExtensionData, setShippingAddress]);

	// Component placement logic
	useEffect(() => {
		// Only run this logic if the block should be visible
		if (!isActive || !hasLinearFtProducts || !hasSampleProducts) {
			return;
		}

		const thisElementsFieldset = document.getElementById('samples-second-shipping-address');
		const parentElement = document.querySelector('.wc-block-checkout__form--with-step-numbers');

		if (!parentElement || !thisElementsFieldset) {
			return;
		}

		// Create an observer instance
		const observer = new MutationObserver((mutations, obs) => {
			const siblingElement = document.getElementById(isShippingMode ? 'shipping-option' : 'pickup-options');
			if (siblingElement) {
				// The target element now exists, so we can insert our block.
				siblingElement.insertAdjacentElement('afterend', thisElementsFieldset);
				// Once we've placed the element, we don't need to observe anymore.
				obs.disconnect();
			}
		});

		// Start observing the parent element for added child nodes.
		observer.observe(parentElement, {
			childList: true,
			subtree: true
		});

		// Cleanup function to disconnect the observer if the component unmounts
		return () => {
			observer.disconnect();
		};
	}, [isActive, hasLinearFtProducts, hasSampleProducts, isShippingMode, useShippingAsBilling]);

	// --- NEW: Google Maps Places API (New) AutocompleteSuggestion for Samples ---
	useEffect(() => {
		if (!isActive || !hasLinearFtProducts || !hasSampleProducts) return;

		const tryInit = () => {
			const addressInput = document.getElementById('samples_shipping_address_1');

			if (!addressInput) return false;
			if (!window?.google?.maps?.places) return false;

			if (!addressInput.dataset.autocompleteAttached) {
				addressInput.dataset.autocompleteAttached = 'true';
				addressInput.setAttribute('autocomplete', 'starke-custom-address');
				let sessionToken = new window.google.maps.places.AutocompleteSessionToken();

				const dropdown = document.createElement('ul');
				dropdown.id = 'starke-samples-autocomplete-dropdown';
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

								li.innerHTML = `<strong style="color: #6431F6;">${mainText}</strong> <span style="color: #667; font-size: 12px; margin-left: 5px;">${secondaryText}</span>`;

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

										if (address1) updateSampleShippingField('address_1', address1, true);
										if (city) updateSampleShippingField('city', city, true);
										if (state) updateSampleShippingField('state', state, true);
										if (zip) updateSampleShippingField('postcode', zip, true);
										updateSampleShippingField('address_2', '', false);
										updateSampleShippingField('country', 'US', true);

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
				const addressInput = document.getElementById('samples_shipping_address_1');
				if (addressInput) delete addressInput.dataset.autocompleteAttached;
			};
		}

		const observer = new MutationObserver((mutationsList, obs) => {
			if (tryInit()) obs.disconnect();
		});

		const checkoutContainer = document.querySelector('.wp-block-woocommerce-checkout') || document.body;
		if (checkoutContainer) {
			observer.observe(checkoutContainer, { childList: true, subtree: true });
		}

		const networkCheck = setInterval(() => {
			if (tryInit()) clearInterval(networkCheck);
		}, 500);

		return () => {
			if (observer) observer.disconnect();
			clearInterval(networkCheck);
			const addressInput = document.getElementById('samples_shipping_address_1');
			if (addressInput) delete addressInput.dataset.autocompleteAttached;
		};
	}, [isActive, hasLinearFtProducts, hasSampleProducts]);

	return (
		<>
			{isActive && hasLinearFtProducts && hasSampleProducts && (
				<fieldset
					className="wc-block-components-checkout-step wc-block-components-checkout-step--with-step-number"
					id="samples-second-shipping-address"
				>
					<legend className="screen-reader-text">Shipping Address for Samples</legend>
					<div className="wc-block-components-checkout-step__heading">
						<h2 className="wc-block-components-title wc-block-components-checkout-step__title">Shipping Address for Samples</h2>
					</div>
					<div className="wc-block-components-checkout-step__container">
						<p className="wc-block-components-checkout-step__description">
							Enter the address/info where you want your Samples delivered.
						</p>
						<div className="wc-block-components-checkout-step__content">
							<div id="samples-second-address-content">
								<div
									className="second-address-job-info-and-address-selector-div"
									id="second-address-job-info-and-address-selector-div"
									style={{
										color: 'black',
										display: 'flex',
										flexWrap: 'wrap',
										gap: '0 16px',
										justifyContent: 'space-between',
										marginBottom: '0px'
									}}
								>
									<TextInputV_JobInfo
										nameSpace={nameSpace}
										setExtensionData={setExtensionData}
										setValidationErrors={setValidationErrors}
										clearValidationError={clearValidationError}
										paramName="samples_address_po_number_job_name"
										value={samplesPoNumber}
										errorMessage="Please enter a PO number or job name"
										id="samples_po_reference"
										label="PO Number/Job Label (optional)"
										autoComplete="off"
										required={false}
										classNames={'text-input-v-component-field-full'}
										extraStyle={{ marginTop: '0px' }}
										parentStyle={{ marginTop: '0px' }}
									/>
									<SelectControlV_ShippingAddress
										nameSpace={nameSpace}
										setAddress={setAddress}
										savedAddresses={savedAddresses}
										setExtensionData={setExtensionData}
										setValidationErrors={setValidationErrors}
										clearValidationError={clearValidationError}
										errorMessage="Please select a valid shipping address"
										id="second_address_selector"
										label="Saved Shipping Addresses"
										required={false}
										extraStyle={{}}
										extraParentStyle={{ flex: '1 0 100%' }}
									/>
								</div>

								<div
									className="samples-shipping"
									id="samples-shipping"
									style={{ color: 'black', display: 'flex', flexWrap: 'wrap', gap: '0 16px', justifyContent: 'space-between' }}
								>
									<TextInputV
										nameSpace={nameSpace}
										setExtensionData={setExtensionData}
										setValidationErrors={setValidationErrors}
										clearValidationError={clearValidationError}
										paramName="first_name"
										value={samplesShippingFields.first_name || ''}
										setValue={updateSampleShippingField}
										errorMessage="Please enter a valid first name"
										id="samples_shipping_first_name"
										label="First Name"
										autoComplete="section-samples given-name"
										required={true}
										classNames={'text-input-v-component-field-half'}
										extraStyle={{}}
										parentStyle={{}}
									/>
									<TextInputV
										nameSpace={nameSpace}
										setExtensionData={setExtensionData}
										setValidationErrors={setValidationErrors}
										clearValidationError={clearValidationError}
										paramName="last_name"
										value={samplesShippingFields.last_name || ''}
										setValue={updateSampleShippingField}
										errorMessage="Please enter a valid last name"
										id="samples_shipping_last_name"
										label="Last Name"
										autoComplete="section-samples family-name"
										required={true}
										classNames={'text-input-v-component-field-half'}
										extraStyle={{}}
										parentStyle={{}}
									/>
									<TextInputV
										nameSpace={nameSpace}
										setExtensionData={setExtensionData}
										setValidationErrors={setValidationErrors}
										clearValidationError={clearValidationError}
										paramName="company"
										value={samplesShippingFields.company || ''}
										setValue={updateSampleShippingField}
										errorMessage="Please enter a valid company name"
										id="samples_shipping_company"
										label="Company (optional)"
										autoComplete="section-samples organization"
										required={false}
										classNames={'text-input-v-component-field-full'}
										extraStyle={{}}
										parentStyle={{}}
									/>
									<TextInputV
										nameSpace={nameSpace}
										setExtensionData={setExtensionData}
										setValidationErrors={setValidationErrors}
										clearValidationError={clearValidationError}
										paramName="address_1"
										value={samplesShippingFields.address_1 || ''}
										setValue={updateSampleShippingField}
										errorMessage="Please enter a valid address"
										id="samples_shipping_address_1"
										label="Address"
										autoComplete="section-samples address-line1"
										required={true}
										classNames={'text-input-v-component-field-full'}
										extraStyle={{}}
										parentStyle={{}}
									/>
									<TextInputV
										nameSpace={nameSpace}
										setExtensionData={setExtensionData}
										setValidationErrors={setValidationErrors}
										clearValidationError={clearValidationError}
										paramName="address_2"
										value={samplesShippingFields.address_2 || ''}
										setValue={updateSampleShippingField}
										errorMessage="Please enter a valid address"
										id="samples_shipping_address_2"
										label="Apartment, suite, etc. (optional)"
										autoComplete="section-samples address-line2"
										required={false}
										classNames={'text-input-v-component-field-full'}
										extraStyle={{}}
										parentStyle={{}}
									/>
									<TextInputV
										nameSpace={nameSpace}
										setExtensionData={setExtensionData}
										setValidationErrors={setValidationErrors}
										clearValidationError={clearValidationError}
										paramName="city"
										value={samplesShippingFields.city || ''}
										setValue={updateSampleShippingField}
										errorMessage="Please enter a valid city"
										id="samples_shipping_city"
										label="City"
										autoComplete="section-samples address-level2"
										required={true}
										classNames={'text-input-v-component-field-half'}
										extraStyle={{}}
										parentStyle={{}}
									/>
									<SelectControlV
										options={stateOptions}
										nameSpace={nameSpace}
										setExtensionData={setExtensionData}
										setValidationErrors={setValidationErrors}
										clearValidationError={clearValidationError}
										paramName="state"
										value={samplesShippingFields.state || ''}
										setValue={updateSampleShippingField}
										errorMessage="Please select a valid state"
										id="samples_shipping_state"
										label="State"
										required={true}
										extraStyle={{}}
										extraParentStyle={{ flex: '1 0 calc(50% - 12px)' }}
									/>
									<TextInputV
										nameSpace={nameSpace}
										setExtensionData={setExtensionData}
										setValidationErrors={setValidationErrors}
										clearValidationError={clearValidationError}
										paramName="postcode"
										value={samplesShippingFields.postcode || ''}
										setValue={updateSampleShippingField}
										errorMessage="Please enter a valid zip code"
										id="samples_shipping_zip_code"
										label="ZIP Code"
										autoComplete="section-samples postal-code"
										required={true}
										classNames={'text-input-v-component-field-half'}
										extraStyle={{}}
										parentStyle={{}}
									/>
									<TextInputV
										nameSpace={nameSpace}
										setExtensionData={setExtensionData}
										setValidationErrors={setValidationErrors}
										clearValidationError={clearValidationError}
										paramName="phone"
										value={samplesShippingFields.phone || ''}
										setValue={updateSampleShippingField}
										errorMessage="Please enter a valid phone number"
										id="samples_shipping_phone"
										label="Phone (optional)"
										autoComplete="section-samples tel"
										required={false}
										classNames={'text-input-v-component-field-half'}
										extraStyle={{}}
										parentStyle={{}}
										formatAs="phone"
									/>
								</div>
								<div
									className="below-samples-shipping-features"
									id="below-samples-shipping-features"
									style={{ color: 'black', display: 'flex', flexWrap: 'wrap', gap: '0 16px', justifyContent: 'space-between' }}
								>
									<CheckboxControlV
										nameSpace={nameSpace}
										setExtensionData={setExtensionData}
										setValidationErrors={setValidationErrors}
										clearValidationError={clearValidationError}
										paramName="use_samples_address_for_billing"
										checked={useSamplesShippingAsBilling}
										setChecked={setUseSamplesShippingAsBilling}
										errorMessage="Please check this box"
										id="use_samples_address_for_billing"
										label="Use same address for billing"
										required={false}
										extraStyle={{}}
										parentStyle={{ flex: '1 0 calc(50% - 12px)' }}
									/>
								</div>
							</div>
						</div>
					</div>
				</fieldset>
			)}
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
function TextInputV({
	nameSpace,
	setExtensionData,
	setValidationErrors,
	clearValidationError,
	paramName,
	value,
	setValue,
	errorMessage,
	id,
	label,
	required,
	classNames,
	extraStyle,
	parentStyle,
	autoComplete,
	formatAs
}) {
	const [elementBlurred, setElementBlurred] = useState(false);

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
		const label = document.querySelector(`label[for=${id}]`);
		const showError = elementBlurred || isBeforeProcessing;
		if (value === '') {
			if (required) {
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
					clearValidationError(textInputErrorID);
				}
			}
			//debouncedSetExtensionData( nameSpace, id, valueState );
		}
	}, [value, elementBlurred, isBeforeProcessing, setValidationErrors, clearValidationError]);

	useEffect(() => {
		const element = document.querySelector(`#${id}`);
		if (element && element.parentElement) {
			element.parentElement.style.marginTop = '16px';
		}
	}, []);

	function handleTextInput(newValue) {
		const valueToSet = formatAs === 'phone' ? formatPhoneNumber(newValue) : newValue;
		setValue(paramName, valueToSet, required); // triggers updateSampleShippingField
		setElementBlurred(false);
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
				value={value}
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
function SelectControlV({
	options,
	nameSpace,
	setExtensionData,
	setValidationErrors,
	clearValidationError,
	paramName,
	value,
	setValue,
	errorMessage,
	id,
	label,
	required,
	extraStyle,
	extraParentStyle
}) {
	const [valueState, setValueState] = useState('');
	const [elementBlurred, setElementBlurred] = useState(false);
	const [removedPlaceholder, setRemovedPlaceholder] = useState(0);
	const [fullOptions, setFullOptions] = useState([{ label: 'Select a state', value: '' }]);
	const counter = useRef(0);

	// Validation
	const selectInputErrorID = `${id}-error`;
	const selectInputError = useSelect((select) => {
		const store = select('wc/store/validation');
		return store.getValidationError(selectInputErrorID);
	});

	useEffect(() => {
		if (Array.isArray(options) && options.length > 0) {
			setFullOptions((prev) => [...prev, ...options]);
		}
	}, [options]);

	useEffect(() => {
		removePlaceholder();
	}, [value]);

	useEffect(() => {
		const label = document.querySelector(`label[for=${id}]`);
		if (value === '') {
			if (required) {
				setValidationErrors({
					[selectInputErrorID]: {
						message: errorMessage,
						hidden: !elementBlurred
					}
				});
				if (label) {
					label.style.color = elementBlurred ? '#cc1818' : 'hsla(0,0%,7%,.7)';
				}
			}
		} else {
			if (label) {
				label.style.color = 'hsla(0,0%,7%,.7)';
			}
			if (required) {
				clearValidationError(selectInputErrorID);
			}
			//debouncedSetExtensionData( nameSpace, id, value );
		}
	}, [value, setValidationErrors, clearValidationError, elementBlurred]);

	useEffect(() => {
		const parentElement = document.getElementById(id).parentElement;
		if (parentElement) {
			const selectIcon = parentElement.querySelector('[data-wp-component="InputControlSuffixWrapper"]');
			const inputControlBackdrop = parentElement.querySelector('.components-input-control__backdrop');
			if (selectIcon) {
				selectIcon.style.transform = 'scale(1.35';
				selectIcon.style.paddingInlineEnd = '19px';
			}
			if (inputControlBackdrop) {
				inputControlBackdrop.style.display = 'none';
			}
		}
	}, []);

	function handleSelectInput(newValue) {
		setValue(paramName, newValue);
		setElementBlurred(false);
		removePlaceholder();
	}

	function handleSelectInputBlur() {
		if (!elementBlurred) {
			setElementBlurred(true);
		}
	}

	function removePlaceholder() {
		if (value !== '' && removedPlaceholder === 0) {
			setFullOptions((prevOptions) => prevOptions.filter((option) => option.value !== ''));
			setRemovedPlaceholder(1);
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
		fontFamily: 'Inter, sans-serif'
	};
	const fullStyle = { ...baseStyle, ...extraStyle };

	const baseParentStyle = { height: 'fit-content', width: '100%', position: 'relative', boxSizing: 'border-box', marginTop: '16px' };
	const fullParentStyle = { ...baseParentStyle, ...extraParentStyle };

	//const options = [ { label: 'New Jersey', value: 'NJ' }, { label: 'New York', value: 'NY' } ];
	//const fullOptions = [ { label: 'Select a state', value: '' }, ...options ];

	return (
		<div style={fullParentStyle}>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				id={id}
				label=""
				options={fullOptions}
				onChange={(inputValue) => handleSelectInput(inputValue)}
				onBlur={handleSelectInputBlur}
				value={value}
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
	);
}

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
	autoComplete
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
						console.log('samples_address_po_number_job_name', value);
					})
					.catch((error) => {
						// Handle error.
						console.log('Failed');
					});
			}
		}, 250),
		[]
	);

	useEffect(() => {
		const label = document.querySelector(`label[for=${id}]`);
		const showError = elementBlurred || isBeforeProcessing;
		if (valueState === '') {
			if (required) {
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

	function handleTextInput(valueState) {
		setValueState(valueState);
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
	setAddress,
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
	const [valueState, setValueState] = useState('');
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
		debounce((address) => {
			setAddress(JSON.parse(address));
		}, 0),
		[]
	);

	useEffect(() => {
		//const label = document.querySelector(`label[for=${id}]`);
		if (valueState === '') {
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
			debouncedSetExtensionData(valueState);
			console.log('Selector Ran');
		}
	}, [valueState, setValidationErrors, clearValidationError]);

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
		setValueState(valueState);
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
		whiteSpace: 'pre-line',
		marginBottom: '16px'
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
						value={valueState}
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

// SelectControl component I created to match WooCommerce's Select component from the checkout block
function CheckboxControlV({
	nameSpace,
	setExtensionData,
	setValidationErrors,
	clearValidationError,
	paramName,
	checked,
	setChecked,
	errorMessage,
	id,
	label,
	required,
	extraStyle,
	extraParentStyle
}) {
	const { __internalSetUseShippingAsBilling } = useDispatch(CHECKOUTSTORE);

	const { usePrimaryShippingAsBilling } = useSelect((select) => {
		const store = select(CHECKOUTSTORE);
		return {
			usePrimaryShippingAsBilling: store.getUseShippingAsBilling()
		};
	}, []);

	// Validation
	const checkboxInputErrorID = `${id}-error`;
	const checkboxInputError = useSelect((select) => {
		const store = select('wc/store/validation');
		return store.getValidationError(checkboxInputErrorID);
	});

	useEffect(() => {
		checked && usePrimaryShippingAsBilling && __internalSetUseShippingAsBilling(false);
	}, [checked]);

	useEffect(() => {
		usePrimaryShippingAsBilling && setChecked(false);
	}, [usePrimaryShippingAsBilling]);

	useEffect(() => {
		if (!checked) {
			if (required) {
				setValidationErrors({
					[checkboxInputErrorID]: {
						message: errorMessage,
						hidden: false
					}
				});
			}
		} else if (required) {
			clearValidationError(checkboxInputErrorID);
		}
	}, [checked, setValidationErrors, clearValidationError]);

	const baseStyle = {
		color:
			checkboxInputError?.hidden === false
				? '#cc1818'
				: 'black' /*border: '1px solid', borderColor: checkboxInputError?.hidden === false ? '#cc1818' : 'black', borderRadius: '4px', width: '100%', height: '100%', margin: '0px', padding: '15px 8px 0px', fontSize: '18px', minHeight: '48px', fontFamily: 'Inter, sans-serif'*/
	};
	const fullStyle = { ...baseStyle, ...extraStyle };

	const baseParentStyle = { height: 'fit-content', width: '100%', position: 'relative', boxSizing: 'border-box', marginTop: '0px' };
	const fullParentStyle = { ...baseParentStyle, ...extraParentStyle };

	return (
		<div style={fullParentStyle}>
			<CheckboxControl id={id} label={label} checked={checked} required={required} style={fullStyle} onChange={setChecked} />
			{checkboxInputError?.hidden === false && (
				<div>
					<span role="img" aria-label="Warning emoji">
						⚠️
					</span>
					{checkboxInputError?.message}
				</div>
			)}
		</div>
	);
}

export default Block;
