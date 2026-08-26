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
import { extensionCartUpdate, CheckboxControl, ValidationInputError, StoreNoticesContainer } from '@woocommerce/blocks-checkout';
import { getSetting } from '@woocommerce/settings';
import { useSelect, useDispatch, use } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import debounce from 'lodash/debounce';
import isEqual from 'lodash/isEqual';
import { set } from 'lodash';
import { RadioControl } from '@woocommerce/blocks-components';
/**
 * Internal dependencies
 */
import SkeletonV from '../vcomponents/Skeleton/SkeletonV';
//const { TextInput, RadioControl } = wc.blocksComponents;
const { optInDefaultText } = getSetting('vern_shipping_block_data', '');
const nameSpace = 'vern_shipping_block';
const blockName = 'samples-shipping-methods-block';
const context = nameSpace + '/' + blockName;
const CARTSTORE = 'wc/store/cart';
const CHECKOUTSTORE = 'wc/store/checkout';
//const noticesStore = 'wc/store/store-notices';//
const isActive = true;

const Block = ({ checkoutExtensionData }) => {
	const { setExtensionData } = checkoutExtensionData;

	const { cartItems } = useSelect((select) => {
		const store = select(CARTSTORE);
		const cartData = store.getCartData();
		return {
			cartItems: cartData?.items || []
		};
	}, []);

	//useEffect( () => {
	//if (isShippingMode) {
	//	console.log('correctPrimaryFlatRate', correctPrimaryFlatRate);
	//}
	//}, [correctPrimaryFlatRate]);

	// Place the component in the correct order before Billing Address, or Payment Method if Billing Address is off
	/*useEffect( () => {
		if (!parentElement.current) {
			parentElement.current = document.querySelector(".wc-block-checkout__form--with-step-numbers");
		}
		if (!thisElementsFieldset.current) {
			thisElementsFieldset.current = document.getElementById("samples-shipping-option");
		}

		if (parentElement.current && thisElementsFieldset.current) {
			const tempBillingAddressElem = document.getElementById("billing-fields");
			if (tempBillingAddressElem && tempBillingAddressElem === prevSiblingElement.current) {
				return;
			} else if (tempBillingAddressElem) {
				parentElement.current.insertBefore(thisElementsFieldset.current, tempBillingAddressElem);
				prevSiblingElement.current = tempBillingAddressElem;
			} else {
				const tempPaymentMethodElem = document.getElementById("payment-method");
				if (tempPaymentMethodElem && tempPaymentMethodElem === prevSiblingElement.current) {
					return;
				} else if (tempPaymentMethodElem) {
					parentElement.current.insertBefore(thisElementsFieldset.current, tempPaymentMethodElem);
					prevSiblingElement.current = tempPaymentMethodElem;
				}
			}
		}
	});*/

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
				// Make sure name and item_data exist and item_data is an array.
				if (!item.name || !Array.isArray(item.item_data)) {
					return false;
				}
				return (
					item.name.includes('(sample)') && // The name contains "(sample)"
					item.item_data.length <= 2 && // The item_data array is empty
					item.id !== 444 // The id is not 444
				);
			}),
		[cartItems]
	);

	//console.log('Has Linear Ft', hasLinearFtProducts);
	//console.log('Has Samples', hasSampleProducts);
	//console.log('Samples Rates', secondShippingRates_Samples);

	/*useEffect( () => { 
		if (hasLinearFtProducts || !hasSampleProducts) {
			const shippingOptionFieldset = document.getElementById('shipping-option');
			if (shippingOptionFieldset) {
				const radioControl = shippingOptionFieldset.querySelector(".wc-block-components-radio-control");
				if (radioControl && samples_shipping_rates?.length > 0) {
					samples_shipping_rates.forEach(rate => {
						// Select all labels inside the radio control that have a 'for' attribute containing the rate's rate_id
						const labels = radioControl.querySelectorAll(`label[for*="${rate.rate_id}"]`);
						labels.forEach(label => {
							// Remove the label from the DOM
							label.remove();
						});
					});
				}
			}
		}

	}, [hasLinearFtProducts, hasSampleProducts]);*/

	/*useEffect(() => {
		// Only run this logic if the block should be visible
		if (!isActive || !hasLinearFtProducts || !hasSampleProducts) {
			return;
		}

		const thisElementsFieldset = document.getElementById("samples-shipping-option");
		const parentElement = document.querySelector(".wc-block-checkout__form--with-step-numbers");

		if (!parentElement || !thisElementsFieldset) {
			return;
		}

		// This function handles the placement and disconnects the observer.
		const placeAndDisconnect = (observerInstance) => {
			const siblingElement = document.getElementById("samples-second-shipping-address");
			if (siblingElement) {
				siblingElement.insertAdjacentElement('afterend', thisElementsFieldset);
				if (observerInstance) {
					observerInstance.disconnect();
				}
				return true; // Placement was successful
			}
			return false; // Sibling not found
		};

		// First, try to place the block immediately without an observer.
		if (placeAndDisconnect(null)) {
			return; // We're done for this render cycle.
		}

		// If the sibling wasn't found, create an observer.
		const observer = new MutationObserver((mutations, obs) => {
			// On any change, try to place the block. If successful, the observer will be disconnected.
			placeAndDisconnect(obs);
		});

		// Start observing the parent element for changes.
		observer.observe(parentElement, {
			childList: true,
			subtree: true,
		});

		// Cleanup function to disconnect the observer if the component unmounts or dependencies change.
		return () => {
			observer.disconnect();
		};
	}, [isActive, hasLinearFtProducts, hasSampleProducts, isShippingMode, useShippingAsBilling]);*/

	useEffect(() => {
		// Only run this logic if the block should be visible
		if (!isActive || !hasLinearFtProducts || !hasSampleProducts) {
			return;
		}

		const thisElementsFieldset = document.getElementById('samples-shipping-option');
		const parentElement = document.querySelector('.wc-block-checkout__form--with-step-numbers');

		if (!parentElement || !thisElementsFieldset) {
			return;
		}

		// This function checks and fixes the position of our block.
		const positionBlock = () => {
			const siblingElement = document.getElementById('samples-second-shipping-address');

			// If the target sibling exists, but our block is not immediately after it, move it.
			if (siblingElement && siblingElement.nextSibling !== thisElementsFieldset) {
				siblingElement.insertAdjacentElement('afterend', thisElementsFieldset);
			}
		};

		// Run it once on initial load, in case the element is already there.
		positionBlock();

		// Create an observer instance that calls our positioning function on every change.
		const observer = new MutationObserver(positionBlock);

		// Start observing the parent element for changes to its children.
		observer.observe(parentElement, {
			childList: true,
			subtree: true
		});

		// Cleanup function to disconnect the observer if the component unmounts.
		return () => {
			observer.disconnect();
		};
	}, [isActive, hasLinearFtProducts, hasSampleProducts]);

	return (
		<>
			{
				/*isActive && */ hasLinearFtProducts && hasSampleProducts && (
					<fieldset
						className="wp-block-vern_shipping_block-samples-shipping-methods-block wc-block-components-checkout-step wc-block-components-checkout-step--with-step-number"
						id="samples-shipping-option"
					>
						<legend className="screen-reader-text">Shipping Options for Samples</legend>
						<div className="wc-block-components-checkout-step__heading">
							<h2 className="wc-block-components-title wc-block-components-checkout-step__title">Shipping Option for Samples</h2>
						</div>
						<div className="wc-block-components-checkout-step__container">
							<div className="wc-block-components-checkout-step__content">
								<div
									className="samples-shipping-methods"
									id="samples-shipping-methods"
									style={{
										color: 'black',
										display: 'flex',
										flexWrap: 'wrap',
										gap: '0px 0px',
										justifyContent: 'left',
										marginTop: '12px'
									}}
								>
									{isActive && (
										<RadioControlV
											nameSpace={nameSpace}
											setExtensionData={setExtensionData}
											id="samples_shipping_method"
											extraStyle={{}}
											extraParentStyle={{}}
										/>
									)}
								</div>
							</div>
						</div>
					</fieldset>
				)
			}
		</>
	);
};

// RadioControl component I created to match WooCommerce's Select component from the checkout block
function RadioControlV({ nameSpace, setExtensionData, id, extraStyle, extraParentStyle }) {
	/*const [valueState, setValueState] = useState('');
	const [elementBlurred, setElementBlurred] = useState(false);
	const [removedPlaceholder, setRemovedPlaceholder] = useState(0);
	const [fullOptions, setFullOptions] = useState([{ label: 'Select a state', value: '' }, ...options, ]);*/

	const labelMargin = useRef(null);
	const prevShippingRates = useRef(null);
	const prevSamplesAddress = useRef(null);
	const [selectedRate, setSelectedRate] = useState(null);
	const [samplesShippingRates, setSamplesShippingRates] = useState(null);
	const [isUpdatingSamplesAddress, setIsUpdatingSamplesAddress] = useState(false);
	const currentRate = useRef(null);
	const samplesAddressInitiated = useRef(false);
	const wrapperDiv = useRef(null);

	const { selectShippingRate } = useDispatch(CARTSTORE);
	const { createNotice, removeNotice } = useDispatch(noticesStore);

	const { samplesShippingAddress, shipping_rates, isShippingRateBeingSelected } = useSelect((select) => {
		const store = select(CARTSTORE);
		const allShippingRates = store.getShippingRates();
		const isUpdating = store.isShippingRateBeingSelected();
		return {
			samplesShippingAddress: allShippingRates?.[1]?.destination,
			shipping_rates: allShippingRates?.[1]?.shipping_rates || [],
			isShippingRateBeingSelected: isUpdating || false
		};
	}, []);

	/*useEffect(() => {
		console.log('isShippingRateBeingSelected', isShippingRateBeingSelected);
	}, [isShippingRateBeingSelected]);*/

	useEffect(() => {
		if (!samplesAddressInitiated.current) {
			if (
				samplesShippingAddress !== undefined &&
				samplesShippingAddress?.address_1 !== '' &&
				samplesShippingAddress?.city !== '' &&
				samplesShippingAddress?.state !== '' &&
				samplesShippingAddress?.postcode !== ''
			) {
				samplesAddressInitiated.current = true;
			}
		}
	}, [samplesShippingAddress]);

	useEffect(() => {
		//console.log('Shipping Rates1', shipping_rates);
		//console.log('samplesShippingAddress', samplesShippingAddress);
		//console.log('isShippingRateBeingSelected', isShippingRateBeingSelected);
		if (!isShippingRateBeingSelected) {
			if (!isEqual(shipping_rates, prevShippingRates.current) || !isEqual(samplesShippingAddress, prevSamplesAddress.current)) {
				// Filter rates that include "Samples" in the name
				const { samples_shipping_rates, selected_samples_shipping_rate_id } = shipping_rates.reduce(
					(acc, rate) => {
						if (rate.name.includes('Samples')) {
							acc.samples_shipping_rates.push(rate);
							if (rate.selected) {
								acc.selected_samples_shipping_rate_id = rate.rate_id;
							}
						}
						return acc;
					},
					{ samples_shipping_rates: [], selected_samples_shipping_rate_id: null }
				);

				const tempRate = selected_samples_shipping_rate_id
					? selected_samples_shipping_rate_id
					: samples_shipping_rates?.length > 0
					? samples_shipping_rates[0]?.rate_id
					: null;
				setSamplesShippingRates(samples_shipping_rates);
				if (tempRate /* && currentRate.current !== tempRate*/) {
					setSelectedRate(tempRate);
				}

				console.log('tempRate', tempRate);

				prevShippingRates.current = shipping_rates;
				prevSamplesAddress.current = samplesShippingAddress;

				if (samples_shipping_rates?.length > 0) {
					removeNotice('samples_invalid_shipping_option', context);
				} else if (samplesAddressInitiated.current) {
					createNotice('warning', 'There are no shipping options available. Please check your shipping address.', {
						context,
						id: 'samples_invalid_shipping_option',
						isDismissible: false
					});
				}
			}
		}
	}, [shipping_rates, isShippingRateBeingSelected]);

	useEffect(() => {
		if (labelMargin.current !== '0') {
			setElementStyling();
		}

		function setElementStyling() {
			const parent = document.getElementById('samples-shipping-methods');
			if (parent) {
				const labels = parent.querySelectorAll('.wc-block-components-radio-control__option');
				if (labels) {
					labels.forEach((item) => {
						item.style.margin = 0;
					});
					labelMargin.current = labels[0]?.style.margin;
				}
			}
		}
	});

	// Listen for the custom updating events
	useEffect(() => {
		const handleStart = () => setIsUpdatingSamplesAddress(true);
		const handleEnd = () => setIsUpdatingSamplesAddress(false);

		document.body.addEventListener('starke_samples_address_updating_start', handleStart);
		document.body.addEventListener('starke_samples_address_updating_end', handleEnd);

		return () => {
			document.body.removeEventListener('starke_samples_address_updating_start', handleStart);
			document.body.removeEventListener('starke_samples_address_updating_end', handleEnd);
		};
	}, []);

	// Debounce sending data to the data store
	const debouncedSetExtensionData = useCallback(
		debounce((nameSpace, id, rateID) => {
			selectShippingRate(rateID, 1);
			setExtensionData(nameSpace, id, rateID);
		}, 0),
		[]
	);

	useEffect(() => {
		if (selectedRate) {
			currentRate.current = selectedRate;
			debouncedSetExtensionData(nameSpace, id, selectedRate);
		}
	}, [selectedRate]);

	function handleSelectInput(value) {
		if (!isShippingRateBeingSelected) {
			setSelectedRate(value);
		}
	}

	const baseStyle = { color: 'black', borderRadius: '4px', width: '100%', height: '100%', fontSize: '18px', fontFamily: 'Inter, sans-serif' };
	const fullStyle = { ...baseStyle, ...extraStyle };

	const baseParentStyle = { height: 'fit-content', width: '100%', position: 'relative', boxSizing: 'border-box', marginTop: '0px' };
	const fullParentStyle = { ...baseParentStyle, ...extraParentStyle };

	// Determine if we should show the loading skeleton
	const isLoading = isShippingRateBeingSelected || isUpdatingSamplesAddress;

	//const options = [ { label: 'New Jersey', value: 'NJ' }, { label: 'New York', value: 'NY' } ];
	//const fullOptions = [ { label: 'Select a state', value: '' }, ...options ];

	return (
		<>
			<div className="wc-block-components-shipping-rates-control__package">
				<StoreNoticesContainer context={context} />
				{!samplesAddressInitiated.current && (
					<div
						style={{
							marginBottom: '0px',
							marginTop: '25px',
							padding: '27px',
							backgroundColor: '#a1a1a112',
							textAlign: 'center',
							color: '#00000070'
						}}
					>
						Enter a shipping address to view shipping options.
					</div>
				)}

				{/* UPDATED SKELETON STRUCTURE: Added borders, perfect circles, and increased heights */}
				{isLoading ? (
					<div style={fullParentStyle}>
						{[1, 2].map((key) => (
							<div
								key={key}
								style={{
									display: 'flex',
									alignItems: 'center',
									width: '100%',
									marginBottom: '0px', // Spacing between options (Previously: marginBottom: key === 1 ? '16px' : '0px')
									border: '1px solid #e5e5e5', // 1. ADDED BORDER
									borderRadius: '4px',
									paddingLeft: '.9em', // Shifted the padding to the outer bordered container
									boxSizing: 'border-box',
									...fullStyle
								}}
							>
								{/* 1. The Radio Circle Skeleton (Forced to be a perfect circle) */}
								<div
									style={{
										width: '22px', // 3. INCREASED HEIGHT/WIDTH
										height: '22px',
										borderRadius: '50%', // 2. FORCES PERFECT CIRCLE
										overflow: 'hidden',
										flexShrink: 0,
										marginRight: '16px'
									}}
								>
									<SkeletonV width="100%" height="100%" />
								</div>

								{/* 2. The Label Container */}
								<div
									style={{
										display: 'flex',
										justifyContent: 'space-between',
										width: '100%',
										padding: '.875em .875em .875em 0', // Removed left padding so it sits flush next to the circle
										margin: '0px'
									}}
								>
									{/* Method Name Skeleton */}
									<SkeletonV width={key === 1 ? '40%' : '55%'} height="22px" />

									{/* Price Skeleton */}
									<SkeletonV width="55px" height="22px" />
								</div>
							</div>
						))}
					</div>
				) : samplesShippingRates ? (
					<div style={fullParentStyle}>
						<RadioControl
							id={id}
							options={samplesShippingRates.map((rate) => ({
								value: rate.rate_id,
								label: (
									<div
										style={{
											display: 'flex',
											justifyContent: 'space-between',
											width: '100%',
											padding: '.875em .875em .875em 1.25em',
											margin: '0px'
										}}
									>
										<span>{rate.name}</span>
										<span style={{}}>${(+rate.price * 0.01).toFixed(2)}</span>
									</div>
								)
							}))}
							selected={selectedRate}
							onChange={(inputValue) => handleSelectInput(inputValue)}
							disabled={false}
							highlightChecked={true}
							style={fullStyle}
						/>
					</div>
				) : null}
			</div>
		</>
	);
}

export default Block;
