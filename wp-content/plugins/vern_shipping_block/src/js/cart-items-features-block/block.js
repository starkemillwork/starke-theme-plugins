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
import { TextInput, Spinner } from '@woocommerce/blocks-components';
import { SVG, Path, Button } from '@wordpress/components';
import { Fragment, useCallback, useEffect, useState, useRef, useMemo, createPortal } from '@wordpress/element';
import { extensionCartUpdate } from '@woocommerce/blocks-checkout';
import { useSelect, useDispatch } from '@wordpress/data';
import debounce from 'lodash/debounce';
/**
 * Internal dependencies
 */
import SkeletonV from '../vcomponents/Skeleton/SkeletonV';
//import { getSetting } from '@woocommerce/setting';
//import { use } from 'react';
//import { TextInput, RadioControl, Spinner } from '@woocommerce/blocks-components';
//const { TextInput, RadioControl, Spinner } = wc.blocksComponents;
//const { optInDefaultText } = getSetting( 'vern_shipping_block_data', '' );
const CARTSTORE = 'wc/store/cart';
const CHECKOUTSTORE = 'wc/store/checkout';
const PAYMENTSTORE = 'wc/store/payment';
const nameSpace = 'vern_shipping_block';
const SETUP_CHARGE_ID = 444;
const TOOLING_CHARGE_ID = 2843;
const isActive = true;

const Block = ({}) => {
	const orderSummaryCartItemsElement = useRef(null);
	const thisElement = useRef(null);
	const quoteStatusElement = useRef(null);
	const [didMoveElements, setDidMoveElements] = useState(false);
	const [shippingTotalsContainer, setShippingTotalsContainer] = useState(null);
	const [miniCartItemsContainer, setMiniCartItemsContainer] = useState(null);
	const [checkoutItemsContainer, setCheckoutItemsContainer] = useState(null);
	const [miniCartSubtotalContainer, setMiniCartSubtotalContainer] = useState(null);
	const [showFreightQuoteButton, setShowFreightQuoteButton] = useState(false);
	const [actionsRowContainer, setActionsRowContainer] = useState(null);
	const [ltlRateContainer, setLtlRateContainer] = useState(null);
	const [shippingRateSelection, setShippingRateSelection] = useState(null);
	const [quoteButtonsAnchor, setQuoteButtonsAnchor] = useState(null);
	const [isUpdatingLtlCost, setIsUpdatingLtlCost] = useState(false);
	const [ltlCostBlurred, setLtlCostBlurred] = useState(false);
	const prevIsAddressCompleteAndIsCalculating = useRef(false);
	const [taxesContainer, setTaxesContainer] = useState(null);
	const [subtotalContainer, setSubtotalContainer] = useState(null);
	const [isPaymentMethodBeingSelected, setIsPaymentMethodBeingSelected] = useState(false);
	const [isUpdatingSamplesAddress, setIsUpdatingSamplesAddress] = useState(false);
	const [isUpdatingPaymentTerms, setIsUpdatingPaymentTerms] = useState(false);
	const [isSavingQuote, setIsSavingQuote] = useState(false);
	const [isRequestingFreight, setIsRequestingFreight] = useState(false);
	const [isSendingQuote, setIsSendingQuote] = useState(false);
	const hasInitializedPaymentMethod = useRef(false);

	const { selectShippingRate } = useDispatch(CARTSTORE);

	const {
		shippingAddress,
		billingAddress,
		allShippingRates,
		primaryShippingRates,
		isShippingRateBeingSelected,
		isQuoteLocked,
		activeQuoteNumber,
		freightQuoteNumber,
		pendingQuoteNumber,
		profilesNeededNumber,
		isImpersonation,
		isAdmin,
		chosenPaymentMethod,
		correctPrimaryFlatRate,
		packageTypeMap,
		ltlFreightCost,
		isShippingMode,
		isCalculating,
		isGuest,
		paymentMethodsInitialized,
		isCustomerDataUpdating,
		isAddressFieldsForShippingRatesUpdating,
		activePaymentMethod,
		triggerLostLockPopup,
		editingQuoteId,
		uniqueSessionKey,
		isRemovingItem
	} = useSelect((select) => {
		const cartStore = select(CARTSTORE);
		const checkoutStore = select(CHECKOUTSTORE);
		const paymentStore = select(PAYMENTSTORE);
		const cartData = cartStore.getCartData();
		const allShipRates = cartStore.getShippingRates() || [];
		const extensionData = cartData?.extensions[nameSpace] || {};

		let isRemoving = false;
		if (cartStore.isItemPendingDelete && cartData?.items) {
			isRemoving = cartData.items.some((item) => cartStore.isItemPendingDelete(item.key));
		}
		return {
			shippingAddress: cartData.shippingAddress,
			billingAddress: cartData.billingAddress,
			allShippingRates: allShipRates,
			primaryShippingRates: allShipRates?.[0]?.shipping_rates || [],
			isShippingRateBeingSelected: cartStore.isShippingRateBeingSelected() || false,
			isQuoteLocked: extensionData?.is_quote_locked,
			activeQuoteNumber: extensionData?.active_quote_starke_number,
			freightQuoteNumber: extensionData?.freight_quote_starke_number,
			pendingQuoteNumber: extensionData?.pending_quote_starke_number,
			profilesNeededNumber: extensionData?.profiles_needed_starke_number,
			isImpersonation: extensionData?.is_impersonation,
			isAdmin: extensionData?.is_admin,
			chosenPaymentMethod: extensionData?.chosen_payment_method,
			correctPrimaryFlatRate: extensionData?.correct_primary_flat_rate,
			packageTypeMap: extensionData?.package_type_map || [],
			cartTotals: cartData.totals,
			cartItems: cartData.items,
			ltlFreightCost: extensionData?.ltl_freight_cost,
			isShippingMode: !checkoutStore.prefersCollection(),
			isCalculating: checkoutStore.isCalculating(),
			isGuest: checkoutStore.getCustomerId() === 0,
			paymentMethodsInitialized: paymentStore.paymentMethodsInitialized(),
			isCustomerDataUpdating: cartStore.isCustomerDataUpdating(),
			isAddressFieldsForShippingRatesUpdating: cartStore.isAddressFieldsForShippingRatesUpdating(),
			activePaymentMethod: paymentStore.getActivePaymentMethod(),
			triggerLostLockPopup: extensionData?.trigger_lost_lock_popup,
			editingQuoteId: extensionData?.editing_quote_id,
			uniqueSessionKey: extensionData?.unique_session_key,
			isRemovingItem: isRemoving
		};
	}, []);

	useEffect(() => {
		console.log('isCustomerDataUpdating', isCustomerDataUpdating);
	}, [isCustomerDataUpdating]);

	useEffect(() => {
		console.log('isAddressFieldsForShippingRatesUpdating', isAddressFieldsForShippingRatesUpdating);
	}, [isAddressFieldsForShippingRatesUpdating]);

	const { setShippingAddress, setBillingAddress } = useDispatch(CARTSTORE);
	const { setEditingShippingAddress, setEditingBillingAddress } = useDispatch(CHECKOUTSTORE);
	const { __internalSetActivePaymentMethod } = useDispatch(PAYMENTSTORE);

	const quoteStatusContent = useMemo(() => {
		let content = null;
		if (freightQuoteNumber) {
			content = (
				<>
					Freight Quote <strong style={{ fontWeight: '700', color: '#6431f6' }}>Q{freightQuoteNumber}</strong>
				</>
			);
		} else if (pendingQuoteNumber) {
			content = (
				<>
					Pending Quote <strong style={{ fontWeight: '700', color: '#6431f6' }}>Q{pendingQuoteNumber}</strong>
				</>
			);
		} else if (activeQuoteNumber) {
			const hasRenderedSetupHeader = false;
			content = (
				<>
					Pricing for quote <strong style={{ fontWeight: '700', color: '#6431f6' }}>Q{activeQuoteNumber}</strong> is{' '}
					<strong style={{ fontWeight: '700', color: '#6431f6' }}>{isQuoteLocked ? 'Active' : 'Inactive'}</strong>
				</>
			);
		} else if (profilesNeededNumber) {
			content = (
				<>
					Profiles Needed <strong style={{ fontWeight: '700', color: '#6431f6' }}>{profilesNeededNumber}</strong> is{' '}
					<strong style={{ fontWeight: '700', color: '#6431f6' }}>{isQuoteLocked ? 'Valid' : 'Invalid'}</strong>
				</>
			);
		}
		return content;
	}, [freightQuoteNumber, pendingQuoteNumber, activeQuoteNumber, profilesNeededNumber, isQuoteLocked]);

	console.log('chosenPaymentMethod1', chosenPaymentMethod);
	console.log('paymentMethodsInitialized', paymentMethodsInitialized);
	useEffect(() => {
		// 1. Wait until everything is loaded and the late API response has provided the method
		if (paymentMethodsInitialized && chosenPaymentMethod) {
			// 2. Only force the update ONCE. This waits for 'undefined' to turn into 'cheque', etc,
			// sets it, and then disables itself so it doesn't cause an infinite loop.
			if (!hasInitializedPaymentMethod.current) {
				if (activePaymentMethod !== chosenPaymentMethod) {
					__internalSetActivePaymentMethod(chosenPaymentMethod);
				}
				hasInitializedPaymentMethod.current = true;
			}
		}
	}, [paymentMethodsInitialized, chosenPaymentMethod, activePaymentMethod, __internalSetActivePaymentMethod]);

	/*useEffect( () => {
		// Don't run on the initial empty state or if not on the checkout page.
		if ( !selectedPaymentMethod || !starkeData.isCheckout ) {
			return;
		}
		extensionCartUpdate( {
			namespace: nameSpace,
			data: {
				action: 'update_chosen_payment_method',
				payment_method: selectedPaymentMethod,
			},
		} );
	}, [ selectedPaymentMethod ] );*/

	useEffect(() => {
		if (shippingAddress) {
			if (shippingAddress.country !== 'US') {
				setShippingAddress({ country: 'US' });
			}
		}
		if (billingAddress) {
			if (billingAddress.country !== 'US') {
				setBillingAddress({ country: 'US' });
			}
		}
	});

	// Maybe later check if this is running
	/*useEffect(() => {
		if (shippingAddress) {
			if (shippingAddress.country != 'US') {
				console.log('Ran');
				setShippingAddress({ country: 'US' });
			}
		}
		if (billingAddress) {
			if (billingAddress.country != 'US') {
				console.log('Ran2');
				setBillingAddress({ country: 'US' });
			}
		}
	}, [getEditingShippingAddress]);*/

	useEffect(() => {
		setEditingShippingAddress(true);
		setEditingBillingAddress(true);
	}, []);

	// --- NEW: Google Maps Places API (New) Autocomplete for Billing Address (Observer Only) ---
	useEffect(() => {
		// Only run on checkout, but do NOT restrict by isShippingMode since Billing is always active
		if (!starkeData?.isCheckout) return;

		const tryInit = () => {
			const addressInput = document.getElementById('billing-address_1');

			if (!addressInput) return false;
			if (!window?.google?.maps?.places) return false;

			if (!addressInput.dataset.autocompleteAttached) {
				addressInput.dataset.autocompleteAttached = 'true';
				addressInput.setAttribute('autocomplete', 'starke-custom-address');
				let sessionToken = new window.google.maps.places.AutocompleteSessionToken();

				const dropdown = document.createElement('ul');
				dropdown.id = 'starke-billing-autocomplete-dropdown';
				dropdown.style.cssText =
					'position: absolute; z-index: 1000; background: white; border: 1px solid #ccc; border-radius: 4px; width: 100%; max-height: 250px; overflow-y: auto; list-style: none; padding: 0; margin-top: 4px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); display: none;';

				addressInput.parentNode.style.position = 'relative';
				addressInput.parentNode.appendChild(dropdown);

				let typingTimer; // Used strictly for debouncing API requests to save limits
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

								const mainText = prediction.mainText?.text || '';
								const secondaryText = prediction.secondaryText?.text || '';

								li.innerHTML = `<strong style="color: #6431F6;">${mainText}</strong> <span style="color: #666; font-size: 12px; margin-left: 5px;">${secondaryText}</span>`;

								li.addEventListener('mouseover', () => (li.style.backgroundColor = '#f4f4f4'));
								li.addEventListener('mouseout', () => (li.style.backgroundColor = 'white'));

								li.addEventListener('click', async () => {
									dropdown.style.display = 'none';

									try {
										const place = prediction.toPlace();
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

										// Instantly hydrate the WooCommerce Billing Store!
										setBillingAddress({
											address_1: address1,
											city,
											state,
											postcode: zip,
											address_2: '',
											country: 'US'
										});

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

		// Try immediately in case it mounted already
		if (tryInit()) {
			return () => {
				const addressInput = document.getElementById('billing-address_1');
				if (addressInput) delete addressInput.dataset.autocompleteAttached;
			};
		}

		// If it's hidden behind the checkbox, let the Observer cleanly catch it without polling
		const observer = new MutationObserver((mutationsList, obs) => {
			if (tryInit()) obs.disconnect();
		});

		const checkoutContainer = document.querySelector('.wp-block-woocommerce-checkout') || document.body;
		if (checkoutContainer) {
			observer.observe(checkoutContainer, { childList: true, subtree: true });
		}

		// Cleanup
		return () => {
			if (observer) observer.disconnect();
			const addressInput = document.getElementById('billing-address_1');
			if (addressInput) delete addressInput.dataset.autocompleteAttached;
		};
	}, [starkeData?.isCheckout, setBillingAddress]);

	// Quote Lock Turned Off Notification (for Active Quotes)
	useEffect(() => {
		// Safety check: We need the Session Key to handle the cookie logic.
		// We rely on triggerLostLockPopup to know if we are in an editing state.
		if (!uniqueSessionKey) {
			return;
		}

		// CHANGE: We removed 'editingQuoteId' from the cookie name.
		// Now, the cookie is tied ONLY to the User Session.
		// This ensures they see the popup ONCE per login, even if they switch between multiple quotes.
		const cookieName = 'starke_seen_lock_global_' + uniqueSessionKey;

		// CASE 1: The Lock is Broken (Server says True)
		if (triggerLostLockPopup === true && activeQuoteNumber) {
			// Check if we have ALREADY clicked OK for this specific session (Global check)
			const hasSeen = document.cookie.split('; ').find((row) => row.startsWith(cookieName + '='));

			// If NOT seen, show the popup
			if (!hasSeen) {
				const popup = document.getElementById('starke-lost-lock-popup');
				const overlay = document.getElementById('starke-lost-lock-popup-overlay');
				const okBtn = document.getElementById('starke-lost-lock-ok-btn');

				if (popup && overlay) {
					popup.style.display = 'flex';
					overlay.style.display = 'block';

					// Lock Scroll
					const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
					document.body.style.paddingRight = scrollbarWidth + 'px';
					document.body.style.overflow = 'hidden';

					// Listener: Set Cookie ONLY when clicking OK
					if (okBtn) {
						okBtn.onclick = function () {
							// Save cookie (Session duration)
							document.cookie = cookieName + '=true; path=/';

							// Close popup visual logic is handled by the PHP script's listener
							popup.style.display = 'none';
							overlay.style.display = 'none';
							document.body.style.overflow = '';
							document.body.style.paddingRight = '';
						};
					}
				}
			}
		}
		// CASE 2: The Lock is VALID (Server says False)
		else {
			// Just ensure it is closed. We DO NOT delete the cookie.
			const popup = document.getElementById('starke-lost-lock-popup');
			const overlay = document.getElementById('starke-lost-lock-popup-overlay');
			if (popup) {
				popup.style.display = 'none';
				if (overlay) overlay.style.display = 'none';
				document.body.style.overflow = '';
				document.body.style.paddingRight = '';
			}
		}
	}, [triggerLostLockPopup, uniqueSessionKey, activeQuoteNumber]);

	// Sync buttons and order totals with the Samples Address update
	useEffect(() => {
		const handleStart = () => {
			setIsUpdatingSamplesAddress(true);
			document.body.classList.add('starke-samples-updating');
		};

		const handleEnd = () => {
			setIsUpdatingSamplesAddress(false);
			document.body.classList.remove('starke-samples-updating');
		};

		document.body.addEventListener('starke_samples_address_updating_start', handleStart);
		document.body.addEventListener('starke_samples_address_updating_end', handleEnd);

		return () => {
			document.body.removeEventListener('starke_samples_address_updating_start', handleStart);
			document.body.removeEventListener('starke_samples_address_updating_end', handleEnd);
		};
	}, []);

	// Sync buttons with the Payment Terms update
	useEffect(() => {
		const handleStart = () => {
			setIsUpdatingPaymentTerms(true);
			document.body.classList.add('starke-payment-updating');
		};

		const handleEnd = () => {
			setIsUpdatingPaymentTerms(false);
			document.body.classList.remove('starke-payment-updating');
		};

		document.body.addEventListener('starke_payment_terms_updating_start', handleStart);
		document.body.addEventListener('starke_payment_terms_updating_end', handleEnd);

		return () => {
			document.body.removeEventListener('starke_payment_terms_updating_start', handleStart);
			document.body.removeEventListener('starke_payment_terms_updating_end', handleEnd);
		};
	}, []);

	// Sync buttons with the native WooCommerce Payment Method update using custom variables
	useEffect(() => {
		if (!paymentMethodsInitialized) return;

		const isUpdating = activePaymentMethod && chosenPaymentMethod && activePaymentMethod !== chosenPaymentMethod;

		if (isUpdating) {
			setIsPaymentMethodBeingSelected(true);
			document.body.classList.add('starke-payment-method-updating');
			document.body.dispatchEvent(new CustomEvent('starke_payment_method_updating_start'));
		} else {
			setIsPaymentMethodBeingSelected(false);
			document.body.classList.remove('starke-payment-method-updating');
			document.body.dispatchEvent(new CustomEvent('starke_payment_method_updating_end'));
		}
	}, [activePaymentMethod, chosenPaymentMethod, paymentMethodsInitialized]);

	// Sync buttons with Item Removal
	useEffect(() => {
		if (isRemovingItem) {
			document.body.classList.add('starke-item-removing');
		} else {
			document.body.classList.remove('starke-item-removing');
		}
	}, [isRemovingItem]);

	// Sets a cookie to indicate the current delivery mode (shipping or pickup).
	const updateDeliveryMode = useCallback(
		(isShipping) => {
			// Convert the incoming boolean to a string ('true' or 'false') for the cookie.
			const cookieValue = isShipping ? 'true' : 'false';

			// Set an expiration date for the cookie (e.g., 1 day from now).
			// This makes it a persistent cookie.
			const date = new Date();
			date.setTime(date.getTime() + 1 * 24 * 60 * 60 * 1000); // 1 day in milliseconds
			const expires = '; expires=' + date.toUTCString();

			// Set the cookie.
			document.cookie =
				'is_ship_mode=' + // The name of the cookie
				cookieValue + // The value ('true' or 'false')
				expires + // The expiration date
				'; path=/; SameSite=Lax'; // Path and security attribute
		},
		[allShippingRates]
	);

	useEffect(() => {
		updateDeliveryMode(isShippingMode);
	}, [isShippingMode]);

	// Shipping totals layout
	useEffect(() => {
		// eslint-disable-next-line no-undef
		if (!starkeData.isCheckout) {
			return;
		}

		const runShippingSetup = () => {
			// Use a GLOBAL flag to ensure this setup runs only once across all component instances.
			if (window.shippingTotalsSetupCompleted) {
				return;
			}

			const containerNode = document.querySelector('.wp-block-woocommerce-checkout-order-summary-shipping-block');

			// If the container isn't in the DOM yet, wait for the observer.
			if (!containerNode) {
				return;
			}

			// Lock it down globally so the second instance won't run this code.
			window.shippingTotalsSetupCompleted = true;

			const elemToReplace = containerNode.querySelector('.wc-block-components-totals-shipping');

			// Hide the original content to avoid the "removeChild" error.
			if (elemToReplace) {
				elemToReplace.style.display = 'none';
			}
			setShippingTotalsContainer(containerNode);
		};

		// Try to run the setup immediately.
		runShippingSetup();

		// If it failed, set up an observer to try again when the DOM changes.
		const shippingObserver = new MutationObserver(() => {
			runShippingSetup();
			if (window.shippingTotalsSetupCompleted) {
				shippingObserver.disconnect();
			}
		});

		shippingObserver.observe(document.body, {
			childList: true,
			subtree: true
		});

		return () => {
			shippingObserver.disconnect();
		};
	}, []); // Run this effect only once per component instance.

	// Mini-cart items layout
	useEffect(() => {
		if (starkeData?.isCheckout) return;

		// This function finds and sets up the container.
		const setupContainer = (containerNode) => {
			const elemToReplace = containerNode.querySelector('tbody');
			if (elemToReplace) {
				elemToReplace.remove();
				containerNode.classList.add('is-react-initialized');
			}
			setMiniCartItemsContainer(containerNode);
		};

		// 1. CHECK: First, try to find the container immediately.
		const existingContainer = document.querySelector('.wc-block-mini-cart-items');
		if (existingContainer) {
			setupContainer(existingContainer);
			return; // Exit the effect, no observer needed.
		}

		// 2. WATCH: If not found, set up the observer to wait for it.
		const observer = new MutationObserver((mutations, obs) => {
			const containerNode = document.querySelector('.wc-block-mini-cart-items');
			if (containerNode) {
				setupContainer(containerNode);
				obs.disconnect(); // Found it, so we can stop observing.
			}
		});

		const miniCartDrawer = document.querySelector('.wc-block-mini-cart__drawer');
		const elemToSearchIn = miniCartDrawer || document.body;
		observer.observe(elemToSearchIn, {
			childList: true,
			subtree: true
		});

		// Cleanup function runs when the component unmounts (on mini-cart close).
		return () => {
			observer.disconnect();
		};
	}, []);

	// Checkout cart items layout
	useEffect(() => {
		if (!starkeData?.isCheckout) {
			return;
		}

		const runSetup = (triggerSource = 'immediate') => {
			// 1. Find the outermost static wrapper which is less likely to be wiped by React
			const stableParent = document.querySelector('.wp-block-woocommerce-checkout-order-summary-cart-items-block');
			// 2. Find the original volatile container we still want to hide
			const originalContainer = document.querySelector('.wc-block-components-order-summary > .wc-block-components-order-summary__content');

			if (!stableParent || !originalContainer) {
				return false;
			}

			if (originalContainer.style.display !== 'none') {
				originalContainer.style.display = 'none';
			}

			const existingContainer = document.getElementById('custom-checkout-items-wrapper');
			const isAttached = existingContainer ? document.body.contains(existingContainer) : false;

			if (!existingContainer || !isAttached) {
				const newContainer = document.createElement('div');
				newContainer.id = 'custom-checkout-items-wrapper';

				// CRITICAL CHANGE: Inject into the stable parent instead of the volatile container
				stableParent.appendChild(newContainer);

				// Update state with the NEW live container
				setCheckoutItemsContainer(newContainer);
			}
			return true;
		};

		// 1. Try to run the setup immediately on normal page load
		let initialObserver;
		if (!runSetup('initial load')) {
			initialObserver = new MutationObserver((mutations, obs) => {
				if (runSetup('MutationObserver')) {
					obs.disconnect();
				}
			});

			initialObserver.observe(document.body, {
				childList: true,
				subtree: true
			});
		}

		// 2. ONLY run when the page is restored via the Back Button/History navigation
		const handleNavigationRestore = (event) => {
			// We fire a rapid sequence of checks over the next 1.6 seconds.
			// This guarantees we catch WooCommerce whenever it decides to re-render the DOM,
			// without leaving a permanent MutationObserver running.
			let attempts = 0;
			const restoreInterval = setInterval(() => {
				attempts++;
				runSetup(`restore-interval-check-${attempts}`);
				if (attempts >= 8) {
					// 8 checks * 200ms = 1.6 seconds max duration
					clearInterval(restoreInterval);
				}
			}, 200);
		};

		// Listen to both native browser history events unconditionally
		window.addEventListener('pageshow', handleNavigationRestore);
		window.addEventListener('popstate', handleNavigationRestore);

		return () => {
			if (initialObserver) initialObserver.disconnect();
			window.removeEventListener('pageshow', handleNavigationRestore);
			window.removeEventListener('popstate', handleNavigationRestore);
		};
	}, [starkeData?.isCheckout]);

	// Quote buttons & Quote Readout section placement for checkout
	useEffect(() => {
		// This function finds and moves the elements. It returns true if both elements are placed or not needed.
		const placeElements = () => {
			const buttonsElement = document.getElementById('cart-items-features');

			// --- Step 1: Handle the buttons section placement ---
			if (orderSummaryCartItemsElement.current && buttonsElement) {
				// Check if the element has already been moved to the correct parent to prevent re-moving it.
				if (
					!orderSummaryCartItemsElement.current.nextElementSibling ||
					!orderSummaryCartItemsElement.current.nextElementSibling.isSameNode(buttonsElement)
				) {
					orderSummaryCartItemsElement.current.insertAdjacentElement('afterend', buttonsElement);
				}
				// Use your original ref to mark the main button placement as complete.
				thisElement.current = buttonsElement;
			} else {
				// If we can't place the buttons, we are not done.
				return false;
			}

			// --- Step 2: Handle the status section only if it exists ---
			const statusElement = document.getElementById('quote-status-features');
			if (statusElement) {
				// Check if the element has already been moved.
				if (!thisElement.current.nextElementSibling || !thisElement.current.nextElementSibling.isSameNode(statusElement)) {
					thisElement.current.insertAdjacentElement('afterend', statusElement);
				}
				// Use your original ref to mark the status placement as complete.
				quoteStatusElement.current = statusElement;
			}

			return true; // Success
		};

		// If the anchor has been found (from a previous run), the buttons should be in the DOM.
		// We can now try to place them.
		if (quoteButtonsAnchor) {
			placeElements();
			return; // We're done, no need for an observer.
		}

		// --- Observer Setup ---
		// If we get here, it's the first run and we need to find the anchor element.
		const observer = new MutationObserver((mutations, obs) => {
			const anchorNode = document.querySelector('.wp-block-woocommerce-checkout-order-summary-cart-items-block');

			if (anchorNode) {
				// Set the ref so the JSX conditional `{isActive && orderSummaryCartItemsElement.current && ...}` passes on the next render.
				orderSummaryCartItemsElement.current = anchorNode;
				// Set state to trigger the re-render. This is the crucial step.
				setQuoteButtonsAnchor(anchorNode);
				// We've found the anchor, so we can stop observing.
				obs.disconnect();
			}
		});

		// Per your feedback, find a more specific parent element to observe for efficiency.
		const totalsBlock = document.querySelector('.wp-block-woocommerce-checkout-totals-block');
		const elemToWatch = totalsBlock || document.body;

		// It's possible the anchor is already there, so we check once before observing.
		const existingAnchor = elemToWatch.querySelector('.wp-block-woocommerce-checkout-order-summary-cart-items-block');
		if (existingAnchor) {
			orderSummaryCartItemsElement.current = existingAnchor;
			setQuoteButtonsAnchor(existingAnchor);
		} else {
			observer.observe(elemToWatch, {
				childList: true,
				subtree: true
			});
		}

		// Cleanup function to disconnect the observer when the component unmounts.
		return () => {
			if (observer) {
				observer.disconnect();
			}
		};
		// Rerun this effect if `quoteButtonsAnchor` or `quoteStatusContent` changes.
	}, [quoteButtonsAnchor, quoteStatusContent]);

	// Quote Readout section placement for mini-cart
	useEffect(() => {
		// Don't run this logic on the checkout page.
		if (starkeData?.isCheckout) return;

		const observer = new MutationObserver((mutations, obs) => {
			// Find the subtotal row in the mini-cart footer.
			const subtotalRow = document.querySelector('.wc-block-mini-cart__footer .wc-block-components-totals-item');

			if (subtotalRow) {
				// Check if our portal target has already been added to prevent duplicates.
				let portalTarget = subtotalRow.querySelector('#quote-status-portal-target');

				if (!portalTarget) {
					portalTarget = document.createElement('div');
					portalTarget.id = 'quote-status-portal-target';
					portalTarget.style.textAlign = 'center'; // Center the text within the portal target.
					portalTarget.style.flexGrow = 1;

					// The label is the first child, price is the second. Insert our target between them.
					if (subtotalRow.children.length > 1) {
						subtotalRow.insertBefore(portalTarget, subtotalRow.children[1]);
						setMiniCartSubtotalContainer(portalTarget);
						subtotalRow.parentElement.style.paddingTop = '19px';
						subtotalRow.style.rowGap = '5px';
						subtotalRow.children[0].style.flexGrow = 0;
					}
				}
				// Once we've found and modified the subtotal row, we don't need to observe anymore.
				obs.disconnect();
			}
		});

		// We only want to search within the mini-cart drawer when it's open.
		const miniCartDrawer = document.querySelector('.wc-block-mini-cart__drawer');
		const elemToSearchIn = miniCartDrawer || document.body;

		observer.observe(elemToSearchIn, {
			childList: true,
			subtree: true
		});

		// Cleanup: disconnect the observer when the component unmounts or mini-cart closes.
		return () => {
			observer.disconnect();
		};
	}, []); // Empty dependency array means it runs once when the component mounts.

	// Moves checkout Actions and Terms sections below the totals.
	useEffect(() => {
		// Exit immediately if this isn't the checkout page or if the elements have already been moved.
		if (!starkeData?.isCheckout || didMoveElements) {
			return;
		}

		// This function finds and moves the elements. It returns true on success.
		const moveElements = () => {
			const totalsBlock = document.querySelector('.wp-block-woocommerce-checkout-totals-block');
			const termsBlock = document.querySelector('.wc-block-checkout__terms');
			const actionsBlock = document.querySelector('.wc-block-checkout__actions');

			// Only proceed if all three elements are present in the DOM.
			if (totalsBlock && termsBlock && actionsBlock) {
				termsBlock.style.borderTop = 'none';
				termsBlock.style.paddingTop = '35px';
				totalsBlock.append(termsBlock, actionsBlock);

				// Set state to prevent this from running again.
				setDidMoveElements(true);
				return true; // Indicate success
			}
			return false; // Indicate that elements are not ready
		};

		// Attempt to move the elements on the first run. If successful, we're done.
		if (moveElements()) {
			return;
		}

		// If elements weren't found, set up an observer to watch for them.
		const observer = new MutationObserver((mutations, obs) => {
			// On any DOM change, we re-run our function.
			if (moveElements()) {
				// Once successful, we disconnect the observer to save resources.
				obs.disconnect();
			}
		});

		// For better performance, we'll watch the main checkout block container instead of the whole document.
		const checkoutBlock = document.querySelector('.wp-block-woocommerce-checkout-block');
		const elementToWatch = checkoutBlock || document.body;

		observer.observe(elementToWatch, {
			childList: true,
			subtree: true
		});

		// Cleanup function: It's crucial to disconnect the observer if the component is unmounted.
		return () => {
			observer.disconnect();
		};
		// This effect runs only when the page context changes or after the elements have been successfully moved.
	}, [starkeData, didMoveElements]);

	// This effect handles showing/hiding the Terms and Place Order buttons.
	// Highly Performant Visibility Controller (No MutationObservers)
	useEffect(() => {
		if (!starkeData?.isCheckout) return;

		// 1. Handle native button visibility
		const placeOrderButton = document.querySelector('.wc-block-components-checkout-place-order-button');
		const termsBlock = document.querySelector('.wc-block-checkout__terms');
		const actionsRow = document.querySelector('.wc-block-checkout__actions_row');

		if (actionsRow && !actionsRowContainer) {
			setActionsRowContainer(actionsRow);
		}

		// Disable checkout features if freight quote, profiles needed, or guest
		const shouldHide = showFreightQuoteButton || profilesNeededNumber || isGuest;
		if (placeOrderButton) placeOrderButton.style.display = shouldHide ? 'none' : '';
		if (termsBlock) termsBlock.style.display = shouldHide ? 'none' : '';

		// 2. Text-Based Node Cleanup (CSS cannot target text content)
		// If Profiles Needed is active, ensure the main total label isn't stuck saying "Amount Due Today"
		if (profilesNeededNumber) {
			const totalsFooterItem = document.querySelector('.wc-block-components-totals-footer-item');
			if (totalsFooterItem) {
				const labelSpan = totalsFooterItem.querySelector('.wc-block-components-totals-item__label');
				if (labelSpan && labelSpan.textContent.includes('Amount Due Today')) {
					labelSpan.textContent = 'Total';
				}
			}
		}

		// Native React Triggers: This will re-run perfectly when Woo finishes calculating a state change
	}, [starkeData?.isCheckout, showFreightQuoteButton, profilesNeededNumber, actionsRowContainer, isGuest, isCalculating, primaryShippingRates]);

	// Automatically checks on the Terms and Conditions checkbox for Admins.
	useEffect(() => {
		// Only run this logic for admins on the checkout page.
		if (!(isAdmin || isImpersonation) || !starkeData?.isCheckout) {
			return;
		}

		// This function finds and clicks the checkbox.
		const checkTermsBox = () => {
			const termsCheckbox = document.getElementById('terms-and-conditions');
			// If the checkbox exists and is not already checked, click it.
			if (termsCheckbox && !termsCheckbox.checked) {
				termsCheckbox.click();
				return true; // Indicate success
			}
			// Return true even if already checked, as the goal is met.
			return !!termsCheckbox;
		};

		// If the box is found and clicked immediately, we're done.
		if (checkTermsBox()) {
			return;
		}

		// If not found, set up an observer to wait for it.
		const observer = new MutationObserver((mutations, obs) => {
			if (checkTermsBox()) {
				// Once successful, stop observing.
				obs.disconnect();
			}
		});

		// MODIFIED: Watch the more specific parent element where the terms block is moved.
		const totalsBlock = document.querySelector('.wp-block-woocommerce-checkout-totals-block');
		const elementToWatch = totalsBlock || document.body; // Fallback to body just in case.

		observer.observe(elementToWatch, {
			childList: true,
			subtree: true
		});

		// Cleanup: disconnect the observer when the component unmounts.
		return () => {
			observer.disconnect();
		};
		// Reruns only if isAdmin status changes.
	}, [isAdmin, isImpersonation, starkeData?.isCheckout]);

	// This effect determines if the "Request Freight Quote" button should be shown.
	useEffect(() => {
		if (!starkeData?.isCheckout || !primaryShippingRates || isShippingRateBeingSelected) {
			return;
		}
		const allRatesArePickupLocation =
			primaryShippingRates.length > 0 && primaryShippingRates.every((rate) => rate?.method_id === 'pickup_location');
		const noRateIsSelected = !primaryShippingRates.some((rate) => rate?.selected);
		const isAddressComplete = shippingAddress?.country && shippingAddress?.city && shippingAddress?.state && shippingAddress?.postcode;
		const shouldShowButton =
			allRatesArePickupLocation &&
			noRateIsSelected &&
			!(isImpersonation || isAdmin) &&
			isAddressComplete &&
			isShippingMode &&
			!packageTypeMap?.includes('sample_only');
		setShowFreightQuoteButton(shouldShowButton);
	}, [didMoveElements, primaryShippingRates, isImpersonation, isAdmin, isShippingMode, packageTypeMap, isShippingRateBeingSelected]); // Rerun this logic whenever shipping rates change.

	// Change primary shipping method notice verbiage
	useEffect(() => {
		if (!isShippingMode) return;

		let elemToSearchIn;
		const shippingOptionElem = document.getElementById('shipping-option');
		if (shippingOptionElem) {
			elemToSearchIn = shippingOptionElem;
		} else {
			elemToSearchIn = document.body;
		}

		const setNoticeContent = () => {
			const hasFlatRate = primaryShippingRates.some((rate) => rate?.method_id === 'flat_rate');
			const isAddressComplete = shippingAddress?.country && shippingAddress?.city && shippingAddress?.state && shippingAddress?.postcode;
			const isAddressCompleteAndIsCalculating = isAddressComplete && isCalculating;
			const noticeContent = document.querySelector('#shipping-option .wc-block-components-notice-banner__content');
			if (!noticeContent) {
				prevIsAddressCompleteAndIsCalculating.current = isAddressCompleteAndIsCalculating;
				return false;
			}
			if (!hasFlatRate && (prevIsAddressCompleteAndIsCalculating.current || showFreightQuoteButton) && !isCalculating) {
				if (packageTypeMap?.includes('sample_only')) {
					noticeContent.innerText = 'Updating shipping options for Samples...';
				} else {
					noticeContent.innerText = `Your shipping address is out of our delivery zone. Click the 'REQUEST FREIGHT QUOTE' button and we will send you a freight price.`;
				}
				prevIsAddressCompleteAndIsCalculating.current = isAddressCompleteAndIsCalculating;
				return true;
			}
			//if (!hasFlatRate && prevIsAddressCompleteAndIsCalculating.current) {
			//	noticeContent.innerText = 'Updating shipping options for Linear Feet Profiles...';
			//}
			prevIsAddressCompleteAndIsCalculating.current = isAddressCompleteAndIsCalculating;
			return true;
		};
		if (setNoticeContent()) return;

		const observer = new MutationObserver((mutations, obs) => {
			if (setNoticeContent()) obs.disconnect();
		});
		observer.observe(elemToSearchIn, {
			childList: true,
			subtree: true
		});
		return () => {
			if (observer) {
				observer.disconnect();
			}
		};
	}, [isShippingMode, primaryShippingRates, shippingAddress, isCalculating, packageTypeMap, showFreightQuoteButton]);

	// Adds the LTL Shipping Cost input to the LTL Shipping option.
	useEffect(() => {
		// Exit if not on checkout, if rates are unavailable, or if the user is not an admin/impersonating.
		if (!starkeData?.isCheckout || !primaryShippingRates || !(isImpersonation || isAdmin)) {
			return;
		}

		const selectedRate = primaryShippingRates.find((rate) => rate.selected);
		const isLtlSelected = selectedRate && selectedRate.name === 'LTL Shipping';

		// If LTL is not the selected rate, ensure the container for the input is cleared and then exit.
		if (!isLtlSelected) {
			setLtlRateContainer(null);
			return;
		}

		// This function finds the LTL radio button's label and injects our portal target.
		// It returns true on success, allowing us to stop observing.
		const injectLtlInputContainer = () => {
			const shippingOptionsContainer = document.querySelector('.wc-block-components-shipping-rates-control');
			if (!shippingOptionsContainer) {
				return false;
			}

			const ltlRadioInput = shippingOptionsContainer.querySelector(`input[value="${selectedRate?.rate_id}"]`);
			if (!ltlRadioInput) {
				return false;
			}

			const labelElement = ltlRadioInput.closest('label');
			if (labelElement) {
				const originalPriceLabel = labelElement.querySelector('.wc-block-components-radio-control__secondary-label');
				if (originalPriceLabel) {
					originalPriceLabel.style.display = 'none';
				}

				if (!labelElement.querySelector('.ltl-cost-input-container')) {
					labelElement.style.display = 'flex';
					labelElement.style.justifyContent = 'space-between';
					labelElement.style.alignItems = 'center';
					labelElement.style.width = '100%';

					const portalTarget = document.createElement('div');
					portalTarget.className = 'ltl-cost-input-container';
					labelElement.appendChild(portalTarget);

					setLtlRateContainer(portalTarget);
				}
				return true; // Success!
			}
			return false; // Label not found yet.
		};

		// Attempt to inject the container immediately.
		if (injectLtlInputContainer()) {
			return;
		}

		// If the elements aren't ready, set up an observer.
		const observer = new MutationObserver((mutations, obs) => {
			if (injectLtlInputContainer()) {
				obs.disconnect(); // Success, so we stop observing.
			}
		});

		// Observe the specific shipping rates container for better performance.
		const elementToWatch = document.querySelector('.wc-block-components-shipping-rates-control') || document.body;

		observer.observe(elementToWatch, {
			childList: true,
			subtree: true
		});

		// Cleanup function is crucial to prevent memory leaks.
		return () => {
			observer.disconnect();
		};
		// Reruns only when these key dependencies change.
	}, [starkeData, primaryShippingRates, isImpersonation, isAdmin]);

	// This finds the Tax AND Subtotal ROW elements and prepares them for the skeleton portals.
	useEffect(() => {
		// Only run on the checkout page
		if (!starkeData?.isCheckout) {
			return;
		}

		const setupContainers = () => {
			let foundAll = true;

			// 1. Setup Taxes
			const taxRowElement = document.querySelector('.wc-block-components-totals-taxes');
			if (taxRowElement) {
				taxRowElement.style.position = 'relative';
				setTaxesContainer(taxRowElement);
			} else {
				foundAll = false;
			}

			// 2. Setup Subtotal (Using the exact HTML wrapper you provided)
			const subtotalRowElement = document.querySelector(
				'.wp-block-woocommerce-checkout-order-summary-subtotal-block .wc-block-components-totals-item'
			);
			if (subtotalRowElement) {
				subtotalRowElement.style.position = 'relative';
				setSubtotalContainer(subtotalRowElement);
			} else {
				foundAll = false;
			}

			return foundAll;
		};

		if (setupContainers()) {
			return;
		}

		// If not found, set up an observer to wait for it.
		const observer = new MutationObserver((mutations, obs) => {
			if (setupContainers()) {
				obs.disconnect(); // Found them, stop observing.
			}
		});

		observer.observe(document.body, {
			childList: true,
			subtree: true
		});

		return () => observer.disconnect();
	}, [starkeData?.isCheckout, allShippingRates]);

	// Unified Master UI Controller: Centralizes the lock state of the native
	// WooCommerce 'Place Order' button to eliminate cross-talk and race conditions.
	useEffect(() => {
		if (!starkeData?.isCheckout) {
			return;
		}

		// Consolidate every independent processing metric into an immutable source of truth
		const isSystemBusy =
			isCalculating ||
			isUpdatingLtlCost ||
			isUpdatingSamplesAddress ||
			isUpdatingPaymentTerms ||
			isPaymentMethodBeingSelected ||
			isRemovingItem ||
			isSavingQuote ||
			isRequestingFreight;

		const placeOrderBtn = document.querySelector('.wc-block-components-checkout-place-order-button');
		const placeOrderBtnText = document.querySelector('.wc-block-components-checkout-place-order-button__text');

		if (placeOrderBtn) {
			placeOrderBtn.disabled = isSystemBusy;
			placeOrderBtn.style.pointerEvents = isSystemBusy ? 'none' : '';
		}
		if (placeOrderBtnText) {
			placeOrderBtnText.style.opacity = isSystemBusy ? '0.5' : '1';
		}
	}, [
		isCalculating,
		isUpdatingLtlCost,
		isUpdatingSamplesAddress,
		isUpdatingPaymentTerms,
		isPaymentMethodBeingSelected,
		isRemovingItem,
		isSavingQuote,
		isRequestingFreight,
		starkeData?.isCheckout
	]);

	// CREATE A BETTER SOLUTION LATER
	// Makes address blocks read-only except for the one currently being edited so that each address autocompletes seperately.
	const lastActiveBlock = useRef(null);

	useEffect(() => {
		const addressBlockIds = ['shipping-fields', 'samples-second-shipping-address', 'billing-fields'];

		// MODIFIED: Renamed function for clarity
		const prepareFieldsForInteraction = (event) => {
			const targetElement = event.target.closest('input, select');
			if (!targetElement) return;

			let activeBlockId = null;
			for (const id of addressBlockIds) {
				const container = document.getElementById(id);
				if (container && container.contains(targetElement)) {
					activeBlockId = id;
					break;
				}
			}

			if (!activeBlockId || activeBlockId === lastActiveBlock.current) {
				return;
			}

			lastActiveBlock.current = activeBlockId;

			for (const id of addressBlockIds) {
				const container = document.getElementById(id);
				if (!container) continue;

				const shouldBeReadOnly = id !== activeBlockId;
				const inputs = container.querySelectorAll('input, select');

				inputs.forEach((input) => {
					if (input.id.includes('_reference') || input.id.includes('_contact')) {
						return;
					}
					input.readOnly = shouldBeReadOnly;
				});
			}
		};

		// MODIFIED: Changed the event listeners from 'focusin'
		document.addEventListener('mousedown', prepareFieldsForInteraction);
		document.addEventListener('touchstart', prepareFieldsForInteraction);

		// MODIFIED: Update cleanup to remove the new listeners
		return () => {
			document.removeEventListener('mousedown', prepareFieldsForInteraction);
			document.removeEventListener('touchstart', prepareFieldsForInteraction);
		};
	}, []);

	// Removes the "Shipment" label from the Pickup shipping option.
	useEffect(() => {
		if (!starkeData?.isCheckout || isShippingMode) return;
		const findAndHideLabel = () => {
			const pickupContainers = document.querySelectorAll('.wc-block-components-local-pickup-select');

			// We only proceed if we find at least one container.
			if (pickupContainers.length === 0) {
				return false; // Return false to indicate we didn't find it yet.
			}

			pickupContainers.forEach((container) => {
				const shipmentLabel = container.firstElementChild;
				if (shipmentLabel) {
					if (shipmentLabel.textContent.includes('Shipment')) {
						shipmentLabel.style.display = 'none';
					} else {
						shipmentLabel.style.display = '';
					}
				}
			});

			return true; // Return true to indicate we found and processed the element.
		};

		// 1. Try to run the function immediately.
		// If it succeeds, we don't need to set up the observer.
		if (findAndHideLabel()) {
			return;
		}

		// 2. If the element wasn't found, set up the MutationObserver.
		const observer = new MutationObserver(() => {
			// On any change, we try to find the label again.
			// If findAndHideLabel() returns true, it means we found it.
			if (findAndHideLabel()) {
				// Once we've successfully found and styled the element,
				// we disconnect the observer to stop watching for changes.
				observer.disconnect();
			}
		});

		// Find a stable parent element to observe for changes.
		const checkoutForm = document.querySelector('.wp-block-woocommerce-checkout');

		// Start observing if the checkout form exists.
		if (checkoutForm) {
			observer.observe(checkoutForm, {
				childList: true, // Watch for added/removed nodes.
				subtree: true // Watch the entire subtree of the form.
			});
		}

		// 3. The cleanup function is crucial.
		// It runs when the component unmounts or before the effect runs again.
		return () => {
			observer.disconnect();
		};
	}, [isShippingMode]);

	return (
		<>
			{isActive && orderSummaryCartItemsElement.current && (
				<>
					{/* Section 1: The Quote Buttons */}
					<div
						className="cart-items-features"
						id="cart-items-features"
						style={{
							borderTop: '2px dashed rgb(210, 210, 210)',
							color: 'black',
							display: 'flex',
							flexWrap: 'wrap',
							gap: '22px 35px',
							justifyContent: 'center',
							padding: '15px 10px'
						}}
					>
						<ButtonV
							nameSpace={nameSpace}
							id="save_cart_quote"
							extraStyle={{}}
							parentStyle={{}}
							startingText={profilesNeededNumber ? 'UPDATE ORDER' : 'SAVE CART / QUOTE'}
							processingText={profilesNeededNumber ? 'CHECKING ORDER...' : 'CHECKING CART...'}
							redirectURL={
								profilesNeededNumber
									? window.location.origin + '/my-account/orders/'
									: window.location.origin + '/my-account/quotes/'
							}
							restApiCallFunction="save-cart-as-quote"
							errorMessage="Error saving cart/quote:"
							isUpdatingLtlCost={isUpdatingLtlCost}
							isUpdatingSamplesAddress={isUpdatingSamplesAddress}
							isUpdatingPaymentTerms={isUpdatingPaymentTerms}
							isPaymentMethodBeingSelected={isPaymentMethodBeingSelected}
							showFreightQuoteButton={showFreightQuoteButton}
							setIsSavingQuote={setIsSavingQuote}
							isGuest={isGuest}
							isRemovingItem={isRemovingItem}
							isRequestingFreight={isRequestingFreight}
							isSendingQuote={isSendingQuote}
						/>
						{(isImpersonation || isAdmin) && !profilesNeededNumber && (
							<ButtonV
								nameSpace={nameSpace}
								id="send_quote"
								extraStyle={{}}
								parentStyle={{}}
								startingText="EMAIL CART / QUOTE"
								processingText="PROCESSING..."
								redirectURL={window.location.origin + '/my-account/quotes/'}
								restApiCallFunction="save-cart-as-quote"
								errorMessage="Error sending quote:"
								isUpdatingLtlCost={isUpdatingLtlCost}
								isUpdatingSamplesAddress={isUpdatingSamplesAddress}
								isUpdatingPaymentTerms={isUpdatingPaymentTerms}
								isPaymentMethodBeingSelected={isPaymentMethodBeingSelected}
								primaryShippingRates={primaryShippingRates}
								setLtlCostBlurred={setLtlCostBlurred}
								isRemovingItem={isRemovingItem}
								setIsSavingQuote={setIsSavingQuote}
								isSavingQuote={isSavingQuote}
								isRequestingFreight={isRequestingFreight}
								setIsSendingQuote={setIsSendingQuote}
							/>
						)}
					</div>

					{/* Section 2: The Quote Pricing Status for Checkout*/}
					{quoteStatusContent && (
						<div className="quote-status-features" id="quote-status-features" style={{ borderTop: '2px dashed rgb(210, 210, 210)' }}>
							<div
								className="quote-pricing-status"
								style={{
									padding: '12px 15px',
									textAlign: 'center',
									color: 'rgb(81, 81, 81)',
									fontSize: '16px',
									fontWeight: '500'
								}}
							>
								{quoteStatusContent}
							</div>
						</div>
					)}
				</>
			)}
			{shippingTotalsContainer &&
				createPortal(
					<ShippingTotalsV
						nameSpace={nameSpace}
						id="shipping_totals"
						freightQuoteNumber={freightQuoteNumber}
						ltlFreightCost={ltlFreightCost}
					/>,
					shippingTotalsContainer
				)}
			{miniCartItemsContainer && createPortal(<MiniCartItemsV nameSpace={nameSpace} id="mini_cart_items" />, miniCartItemsContainer)}
			{checkoutItemsContainer && createPortal(<CheckoutCartItemsV nameSpace={nameSpace} id="checkout_cart_items" />, checkoutItemsContainer)}
			{/* New Portal for Mini-Cart Quote Status */}
			{miniCartSubtotalContainer &&
				quoteStatusContent &&
				createPortal(
					<span style={{ fontSize: '14px', color: 'rgb(81, 81, 81)', fontWeight: '500' }}>{quoteStatusContent}</span>,
					miniCartSubtotalContainer
				)}
			{/* New Portal to render the new freight quote button when needed. */}
			{actionsRowContainer &&
				showFreightQuoteButton &&
				createPortal(
					<ButtonV
						nameSpace={nameSpace}
						id="request_freight_quote"
						extraStyle={{ width: '100%', wordSpacing: '5px', fontSize: '18px' }}
						parentStyle={{ width: '100%', marginTop: '37px' }}
						startingText="REQUEST FREIGHT QUOTE"
						processingText="SENDING..."
						finishedText="FREIGHT REQUEST SENT!"
						redirectURL={window.location.origin + '/my-account/quotes/'}
						restApiCallFunction="save-cart-as-quote"
						errorMessage="Error saving freight quote:"
						isUpdatingSamplesAddress={isUpdatingSamplesAddress}
						isUpdatingPaymentTerms={isUpdatingPaymentTerms}
						isPaymentMethodBeingSelected={isPaymentMethodBeingSelected}
						isRemovingItem={isRemovingItem}
						setIsSavingQuote={setIsSavingQuote}
						isSavingQuote={isSavingQuote}
						isSendingQuote={isSendingQuote}
						setIsRequestingFreight={setIsRequestingFreight}
					/>,
					actionsRowContainer
				)}
			{/* ADDED: Portal to render the guest login button */}
			{actionsRowContainer &&
				isGuest &&
				!showFreightQuoteButton &&
				!profilesNeededNumber &&
				createPortal(<GuestLoginButton />, actionsRowContainer)}
			{/* New Portal for Adding LTL Shipping shipping rate cost component*/}
			{ltlRateContainer &&
				createPortal(
					<LtlFreightCostInputV
						setIsUpdatingLtlCost={setIsUpdatingLtlCost}
						ltlCostBlurred={ltlCostBlurred}
						setLtlCostBlurred={setLtlCostBlurred}
						ltlFreightCost={ltlFreightCost}
					/>,
					ltlRateContainer
				)}
			{/* 4. Add this new Portal at the end of the return statement */}
			{taxesContainer &&
				createPortal(
					<TaxesSkeletonWrapper
						isCalculating={isCalculating || isUpdatingSamplesAddress || isPaymentMethodBeingSelected || isRemovingItem}
					/>,
					taxesContainer
				)}
			{/* Subtotal Portal */}
			{subtotalContainer && createPortal(<SubtotalSkeletonWrapper isCalculating={isUpdatingSamplesAddress} />, subtotalContainer)}

			{/* HIGH PERFORMANCE CSS LOCKDOWN: Completely hides payment/financial blocks natively */}
			{profilesNeededNumber && (
				<style>{`
					#payment-method,
					.wp-block-vern_shipping_block-payment-terms-block,
					.starke-payment-terms-wrapper,
					#starke-real-total-portal-container,
					#starke-future-due-portal-container {
						display: none !important;
					}
				`}</style>
			)}
		</>
	);
	// END: Added by Gemini
};

// UTILITY FUNCTIONS

function usePrevious(value) {
	// A custom hook that returns the previous value of a variable from the last render
	const ref = useRef();
	useEffect(() => {
		ref.current = value;
	});
	return ref.current;
}

function isCustomProfile(productId) {
	// An array of all your custom product IDs.
	const customProfileIds = [
		6173, // Baseboard
		6156, // Casing
		6159, // Crown
		6157 // Miscellaneous
	];

	// The .includes() method efficiently checks if the ID exists in the array.
	return customProfileIds.includes(productId);
}

function formatFeet(value) {
	const match = value.match(/^(\d+)\s*ft$/i); // supports optional space before 'ft', case-insensitive

	if (!match) return value; // return original if format is unexpected

	const number = parseInt(match[1], 10);
	return number.toLocaleString('en-US') + 'ft';
}

function formatPriceUSD(price) {
	// Create a formatter for United States English with options for USD currency.
	const formatter = new Intl.NumberFormat('en-US', {
		style: 'currency',
		currency: 'USD'
		// minimumFractionDigits is typically 2 for USD, but this ensures it
		// e.g., it will format 5 as $5.00
	});
	return price !== '0' ? formatter.format(price) : 'FREE';
}

const buildEditUrl = (item) => {
	//Helper function to build the "EDIT" link URL with query parameters, including specific data transformations.
	const params = new URLSearchParams();
	params.append('cikey', item.key);

	// Create a deep copy to avoid modifying the original cart data
	const dataFields = JSON.parse(JSON.stringify(item.item_data));

	dataFields.forEach((field) => {
		// --- Apply transformations based on the field key ---
		switch (field.key) {
			case 'Linear Feet':
				field.value = field.value.slice(0, -2);
				break;

			case 'Rabbet Position':
				field.value = field.value === 'OFF' ? '0' : field.value.slice(1);
				break;

			case 'Relief Angle':
				field.value = field.value === 'OFF' ? 'false' : 'true';
				break;

			case 'Back Relief':
				if (field.value === 'Rectangular Shape' || field.value === 'Trapezoidal Shape') {
					field.value = 'true';
				} else {
					field.value = 'false'; // Covers 'OFF' and any other case
				}
				break;

			// Filter out these fields so they are not added to the URL
			case 'Rabbet Setup Charge (Under 100ft)':
			case 'Relief Angle Setup Charge (Under 100ft)':
				return; // Skip to the next item in the loop
		}

		params.append(field.key, field.value);
	});

	return `${item.permalink}?${params.toString()}`;
};

// COMPONENTS

// TextInput component I created to match WooCommerce's TextInput component from the checkout block
function TextInputV({
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
	labelStyle,
	labelStyleValueOn,
	labelStyleValueOff,
	wrapperStyle,
	elementBlurred: externalBlurred,
	setElementBlurred: setExternalBlurred,
	onFocus,
	onBlur
}) {
	//const [valueState, setValueState] = useState('');
	const [internalBlurred, setInternalBlurred] = useState(false);
	const elementBlurred = externalBlurred !== undefined ? externalBlurred : internalBlurred;
	const setElementBlurred = setExternalBlurred || setInternalBlurred;
	const [isFocused, setIsFocused] = useState(false);
	const wrapperRef = useRef(null);

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
		if (!wrapperRef.current) {
			wrapperRef.current = document.querySelector(`#${id}`).parentElement;
		}
		const textlabel = document.querySelector(`label[for=${id}]`);
		const showError = elementBlurred || isBeforeProcessing;
		if (textlabel) {
			if (labelStyle?.transform) textlabel.style.transform = labelStyle.transform;
			if (labelStyle?.marginLeft) textlabel.style.marginLeft = labelStyle.marginLeft;
			if (labelStyle?.fontSize) textlabel.style.fontSize = labelStyle.fontSize;
			if (labelStyle?.right) textlabel.style.right = labelStyle.right;
			if (labelStyle?.top) textlabel.style.top = labelStyle.top;
			if (labelStyle?.textAlign) textlabel.style.textAlign = labelStyle.textAlign;
			if (labelStyle?.maxWidth) textlabel.style.maxWidth = labelStyle.maxWidth;
			if (labelStyle?.fontWeight) textlabel.style.fontWeight = labelStyle.fontWeight;
			if (labelStyle?.left) textlabel.style.left = labelStyle.left;

			if (isFocused || value !== '') {
				// Small textlabel (active state)
				if (labelStyleValueOn?.transform) textlabel.style.transform = labelStyleValueOn.transform;
				if (labelStyleValueOn?.fontWeight) textlabel.style.fontWeight = labelStyleValueOn.fontWeight;
			} else {
				// Large textlabel (inactive state)
				if (labelStyleValueOff?.transform) textlabel.style.transform = labelStyleValueOff.transform;
				if (labelStyleValueOff?.fontWeight) textlabel.style.fontWeight = labelStyleValueOff.fontWeight;
			}
		}
		if (value === '') {
			if (textlabel) {
				if (labelStyleValueOff?.display) textlabel.style.display = labelStyleValueOff.display;
				textlabel.style.textAlign = isFocused ? 'left' : 'right';
			}
			if (required) {
				setValidationErrors({
					[textInputErrorID]: {
						message: errorMessage,
						hidden: !showError
					}
				});
				if (textlabel) {
					textlabel.style.display = '';
					textlabel.style.color = showError ? '#cc1818' : 'hsla(0,0%,7%,.7)';
					//if (labelStyleValueOff?.transform && labelStyleValueOn?.transform) textlabel.style.transform = elementBlurred ? labelStyleValueOff.transform : labelStyleValueOn.transform;
				}
				if (wrapperRef.current) {
					// eslint-disable-next-line no-nested-ternary
					wrapperRef.current.style.height = showError ? 'fit-content' : wrapperStyle?.height ? wrapperStyle?.height : '';
				}
			} else {
				if (textlabel) {
					textlabel.style.color = 'hsla(0,0%,7%,.7)';
					//textlabel.style.display = labelStyleValueOff?.display ? labelStyleValueOff?.display : '';
				}
				if (wrapperRef.current && wrapperStyle) {
					if (wrapperStyle?.height) wrapperRef.current.style.height = wrapperStyle.height;
				}
			}
		} else {
			if (textlabel) {
				textlabel.style.color = 'hsla(0,0%,7%,.7)';
				if (labelStyleValueOn?.display) textlabel.style.display = labelStyleValueOn.display;
				//if (labelStyleValueOn?.transform) textlabel.style.transform = labelStyleValueOn.transform;
			}
			if (required) {
				if (textInputError) {
					clearValidationError(textInputErrorID);
				}
			}
			if (wrapperRef.current && wrapperStyle) {
				if (wrapperStyle?.height) wrapperRef.current.style.height = wrapperStyle.height;
			}
			//debouncedSetExtensionData( nameSpace, id, valueState );
		}
	}, [value, isFocused, elementBlurred, isBeforeProcessing, setValidationErrors, clearValidationError]);

	useEffect(() => {
		wrapperRef.current = document.querySelector(`#${id}`)?.parentElement;
		if (wrapperRef.current && wrapperStyle) {
			if (wrapperStyle?.marginTop) wrapperRef.current.style.marginTop = wrapperStyle.marginTop;
		}
	}, []);

	function handleFocus() {
		setIsFocused(true);
		if (onFocus) onFocus();
	}

	function handleTextInput(newValue) {
		setValue(paramName, newValue, required); // triggers updateSampleShippingField
		setElementBlurred(false);
	}

	function handleTextInputBlur() {
		if (!elementBlurred) {
			setElementBlurred(true);
		}
		setIsFocused(false);
		if (onBlur) onBlur();
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
				onFocus={handleFocus}
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
			/>
		</div>
	);
}

// Button component for saving the cart as a quote
function ButtonV({
	id,
	extraStyle,
	parentStyle,
	startingText,
	processingText,
	redirectURL,
	restApiCallFunction,
	errorMessage,
	isUpdatingLtlCost,
	isUpdatingSamplesAddress,
	isUpdatingPaymentTerms,
	isPaymentMethodBeingSelected,
	primaryShippingRates,
	setLtlCostBlurred,
	isGuest,
	isRemovingItem,
	setIsSavingQuote,
	isSavingQuote,
	isRequestingFreight,
	setIsRequestingFreight,
	isSendingQuote,
	setIsSendingQuote,
	showFreightQuoteButton
}) {
	const [isProcessing, setIsProcessing] = useState(false);
	const [buttonInnerText, setButtonInnerText] = useState(startingText);
	const initialText = useRef(startingText);

	const { billingEmail, ltl_freight_cost } = useSelect((select) => {
		const store = select(CARTSTORE);
		const cartData = store.getCartData();
		const extensionData = cartData?.extensions[nameSpace] || {};
		return {
			billingEmail: cartData.billingAddress.email,
			ltl_freight_cost: extensionData?.ltl_freight_cost
		};
	}, []);

	const { isCalculating, isCheckoutProcessing } = useSelect((select) => {
		const store = select(CHECKOUTSTORE);
		return {
			isCalculating: store.isCalculating(),
			isCheckoutProcessing: store.isProcessing() || store.isBeforeProcessing()
		};
	}, []);

	const shouldBeDisabled =
		isCalculating ||
		isProcessing ||
		isUpdatingLtlCost ||
		isUpdatingSamplesAddress ||
		isUpdatingPaymentTerms ||
		isPaymentMethodBeingSelected ||
		isRemovingItem ||
		isSavingQuote ||
		isRequestingFreight ||
		isSendingQuote ||
		isCheckoutProcessing;

	useEffect(() => {
		if (!isProcessing && startingText !== initialText.current) {
			setButtonInnerText(startingText);
			initialText.current = startingText;
		}
	}, [startingText, isProcessing]);

	function handleClick() {
		// --- STARKE UPDATE: Open Login Drawer for Guests ---
		if (isGuest) {
			// We simulate a click on the Header Account Icon to trigger the drawer slide
			const loginTrigger = document.querySelector('.wp-block-woocommerce-customer-account a');
			if (loginTrigger) {
				loginTrigger.click();
			}
			return;
		}
		if (id === 'send_quote') {
			if (!billingEmail) {
				const emailInput = document.querySelector('#email');
				if (emailInput) {
					// Programmatically "blur" the input to trigger its built-in validation.
					emailInput.blur();
					emailInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
				}
				return; // Stop the function if the email is missing.
			}
			const selectedRate = primaryShippingRates.find((rate) => rate.selected);
			if (selectedRate && selectedRate.name === 'LTL Shipping') {
				if (ltl_freight_cost === '' || ltl_freight_cost === null || parseFloat(ltl_freight_cost) < 0) {
					// We trigger the input's validation by setting its "blurred" state to true.
					setLtlCostBlurred(true);
					document.querySelector('#ltl_freight_cost')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
					return;
				}
			}
		}
		if (shouldBeDisabled) return;
		setIsProcessing(true);
		setButtonInnerText(processingText);

		// Immediately lock down the Place Order button before the request fires
		if (setIsSavingQuote) {
			setIsSavingQuote(true);
		}

		// --- Lock down alternate paths dynamically based on button interaction ---
		if (id === 'request_freight_quote' && setIsRequestingFreight) {
			setIsRequestingFreight(true);
		}
		if (id === 'send_quote' && setIsSendingQuote) {
			// ADD THIS BLOCK
			setIsSendingQuote(true);
		}

		wp.apiFetch({
			path: `/vern-shipping-block/v1/${restApiCallFunction}`,
			method: 'POST',
			nonce: starkeData.saveQuoteNonce,
			data: {
				id,
				billing_email: billingEmail
			}
		})
			.then((response) => {
				if (response.success === true) {
					if (response.message) {
						setButtonInnerText(response.message);
						console.log('Response Message:', response.message);
					}
					if (response.redirect) {
						window.location.href = redirectURL;
						// Notice: We intentionally leave setIsSavingQuote as true here
						// to keep the UI completely locked down while the browser unloads the page.
						return;
					}
					setIsProcessing(false);
					if (setIsSavingQuote) {
						setIsSavingQuote(false);
					}
					if (id === 'request_freight_quote' && setIsRequestingFreight) {
						setIsRequestingFreight(false);
					}
					if (id === 'send_quote' && setIsSendingQuote) {
						setIsSendingQuote(false);
					}
				}
			})
			.catch((error) => {
				console.error(errorMessage, error);
				setIsProcessing(false);
				if (setIsSavingQuote) {
					setIsSavingQuote(false);
				}
				if (id === 'request_freight_quote' && setIsRequestingFreight) {
					setIsRequestingFreight(false);
				}
				if (id === 'send_quote' && setIsSendingQuote) {
					// ADD THIS BLOCK
					setIsSendingQuote(false);
				}
				setButtonInnerText(error?.data?.button_text || startingText);
			});
	}

	useEffect(() => {
		if (!isProcessing && buttonInnerText !== startingText) {
			setTimeout(() => {
				setButtonInnerText(startingText);
			}, 2000);
		}
	}, [isProcessing, buttonInnerText]);

	const combinedStyle = { ...extraStyle };
	if (shouldBeDisabled) {
		// If disabled, merge in disabled styles.
		combinedStyle.opacity = 0.5;
		combinedStyle.pointerEvents = 'none'; // This physically prevents clicks.
	}

	//const baseStyle = { paddingLeft: '12px', paddingRight: '12px', paddingTop: '10px', paddingBottom: '10px', display: 'block', backgroundColor: '#fab83e', color: 'black', boxShadow: 'rgb(0, 0, 0) 2px 2px 1px 1px', borderColor: 'black', borderRadius: '5px', fontFamily: 'Muli, Arial, sans-serif', fontSize: '18px', fontWeight: '700', pointerEvents: 'auto', width: 'fit-content', height: 'fit-content', borderStyle: 'solid', borderWidth: '2px', cursor: 'pointer', textAlign: 'center' };
	//const fullStyle = {...baseStyle, ...extraStyle};

	return (
		<div style={parentStyle}>
			<Button
				id={id}
				__next40pxDefaultSize
				isBusy={isProcessing}
				isDisabled={shouldBeDisabled}
				variant={'primary'}
				onClick={handleClick}
				style={combinedStyle}
				className="quote-buttons"
				isUpdatingLtlCost={isUpdatingLtlCost}
			>
				{buttonInnerText}
				{isProcessing && (
					<div className="custom-spinner-wrapper">
						<Spinner />
					</div>
				)}
			</Button>
		</div>
	);
}

// Shipping totals component for displaying info for 0 to multiple shipping packages
function ShippingTotalsV({ id, freightQuoteNumber, ltlFreightCost }) {
	const { allShippingRates, packageTypeMap } = useSelect((select) => {
		const store = select(CARTSTORE);
		const cartData = store.getCartData();
		const allShippingRates = store.getShippingRates() || [];
		const extensionData = cartData?.extensions[nameSpace];
		return {
			allShippingRates,
			packageTypeMap: extensionData?.package_type_map || []
		};
	}, []);

	const isSinglePackage = allShippingRates.length === 1;
	let validPackagesCount = 0;

	return (
		<div className="wc-block-components-totals-shipping wp-block-vern_shipping_block-cart-items-features-block">
			{allShippingRates.map((shippingPackage, index) => {
				// Find the rate that the user has selected for this specific package.
				const selectedRate = shippingPackage.shipping_rates.find((rate) => rate.selected);

				// If no rate is selected for this package, don't render anything for it.
				if (!selectedRate) {
					return null;
				}

				// Use the package_id (or index as a fallback) to look up the type in our map.
				const packageId = shippingPackage.package_id ?? index;
				const packageType = packageTypeMap[packageId] || 'standard';

				// The price from WooCommerce is in minor units (cents), so divide by 100.
				let formattedPrice;
				const isLtlFreightUnset = freightQuoteNumber && selectedRate.name === 'LTL Shipping' && ltlFreightCost === null;

				if (isLtlFreightUnset) {
					formattedPrice = <span style={{ color: '#cc1818', fontWeight: '600' }}>Enter Cost</span>;
				} else {
					formattedPrice = selectedRate.price !== '0' ? formatPriceUSD(parseInt(selectedRate.price, 10) / 100) : 'FREE';
				}

				// Logic for setting the 'For: ' innerText
				let forText = '';
				if (isSinglePackage) {
					forText = 'All Items';
				} else if (packageType === 'sample') {
					forText = 'Samples';
				} else {
					forText = 'Linear Feet Profiles';
				}

				let locationPreposition = 'To:';
				let rateName = selectedRate.name;
				let addressString = '';

				// Check if the selected rate is a pickup location
				if (selectedRate.method_id === 'pickup_location') {
					locationPreposition = 'At:';
					rateName = 'Local Pickup';

					// Find the pickup address from the meta_data array
					const pickupAddressMeta = selectedRate.meta_data.find((meta) => meta.key === 'pickup_address');

					if (pickupAddressMeta && pickupAddressMeta.value) {
						// Example value: '671 Bangor Rd, Nazareth, PA 18064'
						const addressParts = pickupAddressMeta.value.split(',').map((part) => part.trim());

						// Check if we have enough parts to parse
						if (addressParts.length >= 3) {
							const city = addressParts[1];
							const stateAndZip = addressParts[2].split(' ');
							const state = stateAndZip[0];
							const postcode = stateAndZip[1];

							addressString = `${postcode}, ${city}, ${state}`;
						} else {
							// Fallback if the address format is unexpected
							addressString = pickupAddressMeta.value;
						}
					} else {
						addressString = 'Address not available';
					}
				} else {
					// This is the original logic for standard shipping addresses
					const address = shippingPackage.destination;
					addressString = `${address.postcode}, ${address.city}, ${address.state}`;
				}

				return (
					// Use the unique package_id as the key for React's rendering.
					<div
						className="wc-block-components-totals-item"
						key={packageId}
						style={validPackagesCount++ > 0 && index >= 1 ? { marginTop: '18px' } : undefined}
					>
						<div className="shipping-totals-label-and-cost">
							<div className="wc-block-components-totals-item__label">{rateName}</div>
							<div className="wc-block-formatted-money-amount wc-block-components-formatted-money-amount wc-block-components-totals-item__value">
								{' '}
								{formattedPrice}
							</div>
						</div>
						<div className="wc-block-components-totals-item__description">
							<div className="wc-block-components-shipping-address">{locationPreposition}</div>
							<div className="wc-block-components-shipping-address">{addressString}</div>
						</div>
						<div className="wc-block-components-totals-item__description" style={{ borderBottom: '2px dashed rgb(210, 210, 210)' }}>
							<div className="wc-block-components-shipping-address">For:</div>
							<div className="wc-block-components-shipping-address">{forText}</div>
						</div>
					</div>
				);
			})}
		</div>
	);
}

// Mini cart items display component
function MiniCartItemsV({ nameSpace, id }) {
	// Subscribe to the cart items from the WooCommerce data store.
	const { cartItems, profilesNeededNumber, isImpersonation, isAdmin, pendingDeleteItems } = useSelect((select) => {
		const cartStore = select(CARTSTORE);
		const cartData = cartStore.getCartData();
		const items = cartData?.items || [];

		// Check the built-in store API to find items currently being deleted
		const pendingDeletes = [];
		if (cartStore.isItemPendingDelete) {
			items.forEach((item) => {
				if (cartStore.isItemPendingDelete(item.key)) {
					pendingDeletes.push(item.key);
				}
			});
		}

		return {
			cartItems: items,
			profilesNeededNumber: cartData?.extensions?.vern_shipping_block?.profiles_needed_starke_number,
			isImpersonation: cartData?.extensions?.vern_shipping_block?.is_impersonation,
			isAdmin: cartData?.extensions?.vern_shipping_block?.is_admin,
			pendingDeleteItems: pendingDeletes // Pass the array of deleting keys down
		};
	}, []);

	// Hook to dispatch actions, like removing an item.
	const { removeItemFromCart } = useDispatch(CARTSTORE);

	// 1. Categorize all items based on your logic.
	const itemCategories = {
		standardAndSamples: [],
		setupCharges: [],
		toolingCharges: []
	};

	cartItems.forEach((item) => {
		if (item.id === SETUP_CHARGE_ID) {
			// Setup Charge ID
			itemCategories.setupCharges.push(item);
		} else if (item.id === TOOLING_CHARGE_ID) {
			// Tooling Charge ID
			itemCategories.toolingCharges.push(item);
		} else {
			// Group standard and sample items together
			itemCategories.standardAndSamples.push(item);
		}
	});

	// 2. Sort each category alphabetically by name.
	const sortByName = (a, b) => a.name.localeCompare(b.name);
	itemCategories.standardAndSamples.sort(sortByName);
	itemCategories.toolingCharges.sort(sortByName);
	itemCategories.setupCharges.sort(sortByName);

	// 3. Combine all categories into the final sorted array.
	const sortedItems = [...itemCategories.standardAndSamples, ...itemCategories.toolingCharges, ...itemCategories.setupCharges];

	let hasRenderedSetupHeader = false;
	let hasRenderedToolingHeader = false;

	// NEW: Track which custom profiles have already received an input field
	const renderedCustomProfiles = new Set();

	return (
		<tbody>
			{sortedItems.map((item) => {
				// Check the type of the item for rendering logic
				const isStandard = item.id !== SETUP_CHARGE_ID && item.id !== TOOLING_CHARGE_ID && !item?.extensions?.[nameSpace]?.sample;
				const isSample = item?.extensions?.[nameSpace]?.sample;
				const isSetupCharge = item.id === SETUP_CHARGE_ID;
				const isToolingCharge = item.id === TOOLING_CHARGE_ID;
				const isRemoving = pendingDeleteItems.includes(item.key);

				// NEW: Determine if this is the first instance of this specific custom profile name
				let isFirstOfThisCustomProfile = false;
				if (isStandard && isCustomProfile(item.id)) {
					if (!renderedCustomProfiles.has(item.name)) {
						isFirstOfThisCustomProfile = true;
						renderedCustomProfiles.add(item.name);
					}
				}

				// --- Render conditional headers in their own table row for valid HTML ---
				const maybeRenderHeader = () => {
					if (isToolingCharge && !hasRenderedToolingHeader) {
						hasRenderedToolingHeader = true;
						return (
							<tr className="wc-block-cart-items__header-row">
								<td colSpan="3">
									<h2
										id="tooling-charges-title"
										style={{
											borderBottom: '2px dashed',
											textAlign: 'center',
											fontSize: '1.1rem',
											color: 'rgb(81, 81, 81)',
											margin: '20px 0 0 30px',
											paddingBottom: '8px'
										}}
									>
										Tooling Charge for Custom Profiles
									</h2>
								</td>
							</tr>
						);
					}
					if (isSetupCharge && !hasRenderedSetupHeader) {
						hasRenderedSetupHeader = true;
						return (
							<tr className="wc-block-cart-items__header-row">
								<td colSpan="3">
									<h2
										id="main-setup-charges-title"
										style={{
											borderBottom: '2px dashed',
											textAlign: 'center',
											fontSize: '1.1rem',
											color: 'rgb(81, 81, 81)',
											margin: '20px 0 0 30px',
											paddingBottom: '8px'
										}}
									>
										Main Setup Charge for Profiles under 100'
									</h2>
								</td>
							</tr>
						);
					}
					return null;
				};

				// --- Prepare data for standard items ---
				let perFootPrice = 0;
				let baseItemPrice = 0;
				let rabbetChargeValue = 0;
				let reliefChargeValue = 0;
				let hasRabbetCharge = false;
				let hasReliefCharge = false;

				if (isStandard) {
					const rabbetChargeData = item.item_data.find((data) => data.key === 'Rabbet Setup Charge (Under 100ft)');
					rabbetChargeValue = rabbetChargeData ? parseFloat(rabbetChargeData.value.slice(1)) : 0;
					hasRabbetCharge = !!rabbetChargeData;

					const reliefChargeData = item.item_data.find((data) => data.key === 'Relief Angle Setup Charge (Under 100ft)');
					reliefChargeValue = reliefChargeData ? parseFloat(reliefChargeData.value.slice(1)) : 0;
					hasReliefCharge = !!reliefChargeData;

					baseItemPrice = item.prices.price / 100 - rabbetChargeValue - reliefChargeValue;
					perFootPrice = item.extensions?.vern_shipping_block?.price_per_foot
						? parseFloat(item.extensions.vern_shipping_block.price_per_foot)
						: 0;
				}
				const isPriceReady = baseItemPrice >= 0;

				// Render the skeleton if the item is being removed
				if (isRemoving) {
					return (
						<Fragment key={item.key}>
							{maybeRenderHeader()}
							<tr className="wc-block-cart-items__row">
								<td className="wc-block-cart-item__image" aria-hidden="true">
									{!isSetupCharge && !isToolingCharge && <SkeletonV width="100%" className="cart-item-image-skeleton" />}
								</td>
								<td className="wc-block-cart-item__product">
									<div className="wc-block-cart-item__wrap" style={{ display: 'flex', flexDirection: 'column', gap: '1px 8px' }}>
										<SkeletonV width="60%" height="20px" />
										<SkeletonV width="40%" height="14px" />
										<SkeletonV width="50%" height="14px" />
										<SkeletonV width="70px" height="14px" style={{ marginTop: '8px' }} />
									</div>
								</td>
								<td className="wc-block-cart-item__total">
									<SkeletonV width="45px" height="20px" style={{ float: 'right', marginTop: '-.2vh' }} />
								</td>
							</tr>
						</Fragment>
					);
				}

				return (
					<Fragment key={item.key}>
						{maybeRenderHeader()}
						<tr className="wc-block-cart-items__row">
							<td className="wc-block-cart-item__image" aria-hidden="true">
								<a href={item.permalink} tabIndex="-1">
									<img
										src={item.images[0]?.src}
										alt={item.images[0]?.alt}
										style={{
											display: isSetupCharge || isToolingCharge ? 'none' : 'block'
										}}
									/>
								</a>
							</td>
							<td className="wc-block-cart-item__product">
								<div className="wc-block-cart-item__wrap">
									{isSetupCharge || isToolingCharge ? (
										<span
											className="wc-block-components-product-name"
											style={{
												color: 'rgb(100, 49, 246)',
												marginBottom: '5px'
											}}
										>
											{item.name}
										</span>
									) : (
										<a
											className="wc-block-components-product-name"
											href={
												isStandard && !(isCustomProfile(item.id) && !(isImpersonation || isAdmin))
													? buildEditUrl(item)
													: item.permalink
											}
											style={{
												color: 'rgb(100, 49, 246)',
												marginBottom: '5px'
											}}
										>
											{item.name}
											{isStandard && (
												<span
													style={{
														fontSize: '.9em',
														position: 'relative',
														left: '7px',
														top: '0px'
													}}
												>
													&nbsp;&nbsp;&nbsp;&nbsp;@&nbsp;
													{isPriceReady ? formatPriceUSD(perFootPrice) : '--'}
													&nbsp;per lin'
												</span>
											)}
										</a>
									)}
									<div className="wc-block-components-product-metadata" style={isSample ? { marginBottom: '5px' } : {}}>
										<ul className="wc-block-components-product-details">
											{item.item_data.map((data) => {
												const isRabbet = data.key === 'Rabbet Setup Charge (Under 100ft)';
												const isRelief = data.key === 'Relief Angle Setup Charge (Under 100ft)';
												if (isRabbet || isRelief) {
													const liStyles = { fontSize: '1.2em' };
													const nameStyles = {};
													let nameJsx;

													if (isRabbet) {
														liStyles.marginBottom = hasReliefCharge ? '6px' : '11px';
														nameJsx = (
															<>
																Rabbet Charge <small>(Under 100')</small>
															</>
														);
														if (!hasReliefCharge) {
															// Apply border if it's the only charge
															nameStyles.borderBottom = '1px dashed';
															nameStyles.paddingBottom = '3px';
															nameStyles.paddingRight = '15px';
															nameStyles.fontWeight = 'normal';
														}
													}

													if (isRelief) {
														liStyles.marginBottom = '11px';
														nameJsx = (
															<>
																Relief Angle Charge <small>(Under 100')</small>
															</>
														);
														// Relief charge always gets border as it's the last one
														nameStyles.borderBottom = '1px dashed';
														nameStyles.paddingBottom = '3px';
														nameStyles.paddingRight = '15px';
														nameStyles.fontWeight = 'normal';
													}

													return (
														<li key={data.key} style={liStyles}>
															<span className="wc-block-components-product-details__name" style={nameStyles}>
																{nameJsx}
															</span>
														</li>
													);
												}

												const displayValue = data.key === 'Linear Feet' ? formatFeet(data.value) : data.value;
												return (
													<li key={data.key}>
														<span className="wc-block-components-product-details__name" style={{ fontWeight: '700' }}>
															{data.key}:
														</span>
														<span
															className="wc-block-components-product-details__value"
															style={{ marginInlineStart: '4px' }}
														>
															{displayValue}
														</span>
													</li>
												);
											})}
										</ul>
									</div>
									{isStandard &&
										isCustomProfile(item.id) &&
										isFirstOfThisCustomProfile &&
										profilesNeededNumber &&
										(isImpersonation || isAdmin) && <OfficialProfileNumberInputV item={item} />}
									{isStandard && !(isCustomProfile(item.id) && !(isImpersonation || isAdmin)) && (
										<a href={buildEditUrl(item)} id="edit-profile-button">
											{isCustomProfile(item.id) ? 'EDIT / ADD' : 'EDIT'}
										</a>
									)}
									<div
										className="wc-block-cart-item__quantity"
										style={{
											display: isSetupCharge || isToolingCharge ? 'none' : 'block'
										}}
									>
										{false && (
											<div className="wc-block-components-quantity-selector">
												<input
													className="wc-block-components-quantity-selector__input"
													readOnly
													type="number"
													value={item.quantity}
													disabled={true}
												/>
											</div>
										)}
										<button
											className="wc-block-cart-item__remove-link"
											onClick={() => {
												if (isSample) {
													// Dispatch a custom event to the document body.
													// 'item.id' contains the Product ID (shared by both sample and full product).
													const event = new CustomEvent('starke_sample_removed', {
														detail: { product_id: item.id }
													});
													document.body.dispatchEvent(event);
												}
												removeItemFromCart(item.key);
											}}
										>
											Remove item
										</button>
									</div>
								</div>
							</td>
							<td className="wc-block-cart-item__total">
								<div className="wc-block-cart-item__total-price-and-sale-badge-wrapper" style={{ marginTop: '-.2vh' }}>
									{isPriceReady ? (
										<>
											{isStandard && (rabbetChargeValue > 0 || reliefChargeValue > 0) && (
												<span style={{ display: 'block' }}>{formatPriceUSD(baseItemPrice)}</span>
											)}
											{isStandard && rabbetChargeValue > 0 && (
												<span style={{ display: 'block' }}>{formatPriceUSD(rabbetChargeValue)}</span>
											)}
											{isStandard && reliefChargeValue > 0 && (
												<span style={{ display: 'block' }}>{formatPriceUSD(reliefChargeValue)}</span>
											)}
											<span className="price wc-block-components-product-price">
												<span
													className="wc-block-formatted-money-amount wc-block-components-formatted-money-amount wc-block-components-product-price__value"
													style={
														isStandard && (rabbetChargeValue > 0 || reliefChargeValue > 0)
															? {
																	borderTop: '1px solid',
																	paddingInlineStart: '5px'
															  }
															: {}
													}
												>
													{formatPriceUSD(item.totals.line_total / 100)}
												</span>
											</span>
										</>
									) : (
										<div className="custom-spinner-wrapper">
											<Spinner />
										</div>
									)}
								</div>
							</td>
						</tr>
					</Fragment>
				);
			})}
		</tbody>
	);
}

// Wrapper Cart items component for the checkout
function CheckoutCartItemsV({ nameSpace, id }) {
	return (
		<table className="wc-block-cart-items" id="custom-checkout-items">
			<MiniCartItemsV nameSpace={nameSpace} id={id} />
		</table>
	);
}

// This component renders the text input for the LTL Freight cost.
const LtlFreightCostInputV = ({ setIsUpdatingLtlCost, ltlCostBlurred, setLtlCostBlurred, ltlFreightCost }) => {
	// Get validation functions from the store dispatch.
	const { setValidationErrors, clearValidationError } = useDispatch('wc/store/validation');

	console.log('ltlFreightCost', ltlFreightCost);
	// Local state for the input field for a responsive UI.
	const [cost, setCost] = useState(ltlFreightCost ?? '');
	const [isFocused, setIsFocused] = useState(false);

	useEffect(() => {
		if (!isFocused) {
			setCost(ltlFreightCost ?? '');
		}
	}, [ltlFreightCost, isFocused]);

	// Debounce the update function to prevent excessive API calls.
	const debouncedUpdate = useCallback(
		debounce((newCost) => {
			console.log('newCost', newCost);
			setIsUpdatingLtlCost(true);
			extensionCartUpdate({
				namespace: 'vern_shipping_block',
				data: {
					action: 'update_ltl_freight_cost',
					ltl_freight_cost: newCost
				}
			}).finally(() => {
				// Set updating state to false after the API call completes (success or fail)
				setIsUpdatingLtlCost(false);
			});
		}, 400),
		[setIsUpdatingLtlCost]
	);

	// This function will be called by TextInputV whenever the input value changes.
	const handleValueChange = (paramName, newValue, isRequired) => {
		setCost(newValue);
		if (ltlCostBlurred) {
			setLtlCostBlurred(false);
		}
		debouncedUpdate(newValue);
	};

	/// Render the text input.
	return (
		<div style={{ display: 'flex', alignItems: 'flex-start', marginLeft: 'auto' }}>
			<span style={{ lineHeight: '28px', fontSize: '1.1em' }}>$</span>
			<TextInputV
				id="ltl_freight_cost"
				nameSpace="vern_shipping_block"
				label="Cost"
				value={cost}
				setValue={handleValueChange}
				paramName="ltl_freight_cost"
				required={true}
				errorMessage="Cost is required."
				setValidationErrors={setValidationErrors}
				clearValidationError={clearValidationError}
				elementBlurred={ltlCostBlurred}
				setElementBlurred={setLtlCostBlurred}
				onFocus={() => setIsFocused(true)}
				onBlur={() => setIsFocused(false)}
				parentStyle={{ marginTop: '0' }} // Override the default margin from your component.
				extraStyle={{
					textAlign: 'right',
					width: '121px',
					marginLeft: '6px',
					// Adjustments to fix vertical alignment and prevent stretching the parent row.
					height: '28px', // Set an explicit, smaller height.
					minHeight: 'unset', // Override default min-height from Woo Blocks.
					padding: '0 8px' // Remove vertical padding, keep horizontal.
				}}
				labelStyle={{
					position: 'absolute',
					right: '9px', // Position from the right edge.
					top: '0', // Center vertically.
					transform: 'translateY(0)',
					textAlign: 'right',
					maxWidth: '100%',
					marginLeft: '4px',
					color: '#757575', // A slightly lighter color for the label text.
					pointerEvents: 'none' // Allows clicks to pass through to the input.
				}}
				labelStyleValueOn={{ display: cost !== '' ? 'none' : 'inline' }}
				labelStyleValueOff={{ display: 'inline' }}
				wrapperStyle={{ height: '28px', marginTop: '0px' }}
			/>
		</div>
	);
};

// Renders the text input for the Official Profile Number for custom profile items.
const OfficialProfileNumberInputV = ({ item }) => {
	const { setValidationErrors, clearValidationError } = useDispatch('wc/store/validation');

	// Get the initial value from the cart item data.
	const initialValue = item.extensions?.vern_shipping_block?.official_profile_number || '';
	const [number, setNumber] = useState(initialValue);

	// Debounce the update function to avoid excessive API calls.
	const debouncedUpdate = useCallback(
		debounce((newValue) => {
			extensionCartUpdate({
				namespace: nameSpace,
				data: {
					action: 'update_official_profile_number',
					cart_item_key: item.key,
					official_profile_number: newValue
				}
			});
		}, 400),
		[item.key]
	);

	const handleValueChange = (paramName, newValue, isRequired) => {
		setNumber(newValue);
		debouncedUpdate(newValue);
	};

	return (
		<div style={{ marginTop: '-6px', marginBottom: '20px' }}>
			<TextInputV
				id={`official_profile_number_${item.key}`}
				nameSpace={nameSpace}
				label="Profile Number:"
				value={number}
				setValue={handleValueChange}
				paramName="official_profile_number"
				required={false}
				errorMessage="Profile Number is required."
				setValidationErrors={setValidationErrors}
				clearValidationError={clearValidationError}
				parentStyle={{ marginTop: '0' }} // Override the default margin from your component.
				extraStyle={{
					textAlign: 'right',
					width: '220px',
					height: '28px',
					minHeight: 'unset',
					padding: '0 6px',
					fontSize: starkeData?.isCheckout ? '.9em' : '1em'
				}}
				labelStyle={{
					position: 'absolute',
					top: '0',
					left: '0',
					transform: 'translateY(0.17em)',
					maxWidth: '100%',
					marginLeft: '7px',
					color: 'black', // A slightly lighter color for the label text.
					pointerEvents: 'none', // Allows clicks to pass through to the input.
					fontWeight: '700',
					fontSize: starkeData?.isCheckout ? '.85em' : '.9em'
				}}
				labelStyleValueOn={{ display: 'inline' }}
				labelStyleValueOff={{ display: 'inline' }}
				wrapperStyle={{ height: '28px', marginTop: '0px' }}
			/>
		</div>
	);
};

// 1. Define the new button component for guests.
const GuestLoginButton = () => {
	// --- STARKE UPDATE: Trigger Account Drawer ---
	const handleClick = (e) => {
		e.preventDefault();

		// Find the Header Account Icon and click it to open the drawer
		const loginTrigger = document.querySelector('.wp-block-woocommerce-customer-account a');
		if (loginTrigger) {
			loginTrigger.click();
		}
	};
	// ---------------------------------------------

	return (
		<button
			type="button" /* Satisfies Linter */
			className="guest-login-checkout-button" /* Keeps your original CSS styling */
			onClick={handleClick}
			style={{
				/* Kept exact styles from your original code */
				color: 'black',
				textDecoration: 'none',
				textAlign: 'center',
				width: '100%',
				wordSpacing: '2px',
				cursor: 'pointer' /* Added to ensure it acts like a link on hover */
			}}
		>
			LOGIN / REGISTER TO PLACE ORDER
		</button>
	);
};

// This component now handles showing the skeleton AND hiding the original price.
const TaxesSkeletonWrapper = ({ isCalculating }) => {
	// This effect runs when `isCalculating` changes. It finds the original price
	// element and hides or shows it accordingly.
	useEffect(() => {
		const originalPriceElement = document.querySelector('.wc-block-components-totals-taxes .wc-block-components-totals-item__value');
		const itemDescription = document.querySelector('.wc-block-components-totals-taxes .wc-block-components-totals-item__description');

		if (originalPriceElement && itemDescription) {
			// Use visibility instead of display. This keeps the layout stable.
			originalPriceElement.style.display = isCalculating ? 'none' : 'block';
			itemDescription.style.display = isCalculating ? 'none' : 'block';
		}
	}, [isCalculating]);

	// When calculating, render the skeleton. It will be positioned over the hidden price.
	if (isCalculating) {
		return <SkeletonV width="45px" height="18px" />;
	}

	// When not calculating, render nothing.
	return null;
};

// This component handles showing the skeleton AND hiding the original price for Subtotal.
const SubtotalSkeletonWrapper = ({ isCalculating }) => {
	useEffect(() => {
		// Targeting the exact HTML snippet you provided
		const originalPriceElement = document.querySelector(
			'.wp-block-woocommerce-checkout-order-summary-subtotal-block .wc-block-components-totals-item__value'
		);
		const itemDescription = document.querySelector(
			'.wp-block-woocommerce-checkout-order-summary-subtotal-block .wc-block-components-totals-item__description'
		);

		if (originalPriceElement) {
			originalPriceElement.style.display = isCalculating ? 'none' : 'block';
		}
		if (itemDescription) {
			itemDescription.style.display = isCalculating ? 'none' : 'block';
		}
	}, [isCalculating]);

	if (isCalculating) {
		return <SkeletonV width="45px" height="18px" />;
	}

	return null;
};

export default Block;
