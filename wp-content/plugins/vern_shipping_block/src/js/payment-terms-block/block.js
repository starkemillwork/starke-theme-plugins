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
import { useEffect, useState, useCallback, useRef, createPortal } from '@wordpress/element';
import { getSetting } from '@woocommerce/settings';
import { RadioControl } from '@woocommerce/blocks-components';
import { extensionCartUpdate } from '@woocommerce/blocks-checkout';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import debounce from 'lodash/debounce';

/**
 * Internal dependencies
 */
import SkeletonV from '../vcomponents/Skeleton/SkeletonV';

const formatCurrency = (cents) => {
	if (typeof cents !== 'number' || isNaN(cents)) return '';
	return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(cents / 100);
};

const Block = (props) => {
	const { checkoutExtensionData } = props;
	const setExtensionData = checkoutExtensionData ? checkoutExtensionData.setExtensionData : null;

	const settings = getSetting('vern_shipping_block_data', {});
	const defaultTerm = settings.defaultPaymentTerms || 'no_terms';

	// --- 1. DATA & STATUS ---
	const { naturalCartTotal, isCalculating, serverPaymentTerm, assignedPaymentTerm, isRemovingItem, isSamplesOnly } = useSelect((select) => {
		const cartStore = select('wc/store/cart');
		const checkoutStore = select('wc/store/checkout');
		const cartData = cartStore.getCartData();

		const extensionData = cartData.extensions && cartData.extensions.vern_shipping_block ? cartData.extensions.vern_shipping_block : {};

		let isRemoving = false;
		if (cartStore.isItemPendingDelete && cartData?.items) {
			isRemoving = cartData.items.some((item) => cartStore.isItemPendingDelete(item.key));
		}

		return {
			naturalCartTotal: extensionData.starke_natural_total ? parseInt(extensionData.starke_natural_total, 10) : 0,
			serverPaymentTerm: extensionData.starke_payment_terms || 'no_terms',
			assignedPaymentTerm: extensionData.starke_assigned_payment_term || 'no_terms',
			isSamplesOnly: extensionData.is_samples_only || false,
			isCalculating: checkoutStore ? checkoutStore.isCalculating() : false,
			isRemovingItem: isRemoving
		};
	}, []);

	const THRESHOLD = 5000; // $50 Payment Terms Threshold (in cents)
	const isBelowThreshold = naturalCartTotal > 0 && naturalCartTotal < THRESHOLD;
	const forceNoTerms = isBelowThreshold || isSamplesOnly;

	// --- 2. LOCAL STATE ---
	const [selectedTerm, setSelectedTerm] = useState(defaultTerm);
	const [isTermUpdating, setIsTermUpdating] = useState(false);
	const [isUpdatingSamplesAddress, setIsUpdatingSamplesAddress] = useState(false);
	const [isUpdatingPaymentMethod, setIsUpdatingPaymentMethod] = useState(false);

	const [totalRowContainer, setTotalRowContainer] = useState(null);
	const [skeletonPortalContainer, setSkeletonPortalContainer] = useState(null);
	const [futureDueRowContainer, setFutureDueRowContainer] = useState(null);

	// NEW: Listen for the Custom DOM events from the samples address
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

	// NEW: Listen for the Custom DOM events from the payment method
	useEffect(() => {
		const handleStart = () => setIsUpdatingPaymentMethod(true);
		const handleEnd = () => setIsUpdatingPaymentMethod(false);

		document.body.addEventListener('starke_payment_method_updating_start', handleStart);
		document.body.addEventListener('starke_payment_method_updating_end', handleEnd);

		return () => {
			document.body.removeEventListener('starke_payment_method_updating_start', handleStart);
			document.body.removeEventListener('starke_payment_method_updating_end', handleEnd);
		};
	}, []);

	// --- 3. SKELETON LOGIC (UPDATED) ---
	// Total Skeleton: Show if calculating native OR updating samples OR updating payment method OR removing item, BUT not if actively swapping terms.
	const showTotalSkeleton = (isCalculating || isUpdatingSamplesAddress || isUpdatingPaymentMethod || isRemovingItem) && !isTermUpdating;
	// Amount Due Skeleton: Show if calculating, updating samples, updating payment method, removing item, OR updating terms.
	const showAmountDueSkeleton = isCalculating || isUpdatingSamplesAddress || isUpdatingPaymentMethod || isTermUpdating || isRemovingItem;
	// Radio Options Skeleton: ONLY show when actively swapping the payment terms.
	const showRadioSkeleton = isTermUpdating;

	// --- 4. SYNC LOCAL STATE TO EXTENSION DATA ---
	useEffect(() => {
		if (setExtensionData) {
			setExtensionData('vern_shipping_block', 'starke_payment_terms', selectedTerm);
		}
	}, [selectedTerm, setExtensionData]);

	// --- 5. STATE HYDRATION (FIXED) ---
	useEffect(() => {
		// Case A: Server matches Local (Sync Complete)
		if (serverPaymentTerm === selectedTerm) {
			if (isTermUpdating) setIsTermUpdating(false);
		}
		// Case B: Server differs, BUT we are NOT trying to update it.
		// This means it's an external change (like page load or threshold enforcement).
		// We SHOULD update local state here.
		else if (serverPaymentTerm && !isTermUpdating) {
			setSelectedTerm(serverPaymentTerm);
		}
		// Case C: Server differs, BUT we ARE updating (isTermUpdating === true).
		// We DO NOTHING. We keep the user's selection visible and wait for the server to catch up.
		// This prevents the flickering.
	}, [serverPaymentTerm, selectedTerm, isTermUpdating]);

	// --- 6. SERVER TRIGGER ---
	const triggerServerUpdate = useCallback(
		debounce((term) => {
			extensionCartUpdate({
				namespace: 'vern_shipping_block',
				data: {
					action: 'update_payment_terms',
					selected_term: term
				}
			});
		}, 400),
		[]
	);

	const handleTermChange = (value) => {
		setIsTermUpdating(true);
		setSelectedTerm(value);
		triggerServerUpdate(value);
	};

	// Threshold / Samples Only Check
	useEffect(() => {
		if (forceNoTerms && selectedTerm !== 'no_terms') {
			handleTermChange('no_terms');
		}
	}, [forceNoTerms, selectedTerm]);

	// --- DISPATCH CUSTOM EVENTS FOR BUTTON DISABLING ---
	useEffect(() => {
		// TURN BACK ON ASAP
		if (isTermUpdating) {
			document.body.dispatchEvent(new CustomEvent('starke_payment_terms_updating_start'));
		} else {
			document.body.dispatchEvent(new CustomEvent('starke_payment_terms_updating_end'));
		}
	}, [isTermUpdating]);

	// --- 7. DOM HIJACK & SKELETON HANDLING ---
	useEffect(() => {
		const updateUI = () => {
			const footerItem = document.querySelector('.wc-block-components-totals-footer-item');
			if (!footerItem) return false;

			const labelSpan = footerItem.querySelector('.wc-block-components-totals-item__label');
			const valueSpan = footerItem.querySelector('.wc-block-components-totals-item__value');

			if (!labelSpan || !valueSpan) return false;

			const isTermActive = selectedTerm !== 'no_terms';

			// A. Rename "Total" -> "Amount Due Today" & Add Sub-label
			if (isTermActive) {
				// 1. FORCE TOP ALIGNMENT: This keeps the Price ($) aligned with "Amount Due Today"
				// preventing it from floating in the middle of the two lines.
				footerItem.style.alignItems = 'flex-start';

				// 2. Define the sub-text based on selection
				const termLabels = {
					net_30: 'Net 30',
					'50_50': '50% Down / 50% on Delivery'
				};
				const termText = termLabels[selectedTerm] || '';

				// 3. Inject the HTML (Only if not already present to avoid loops)
				if (!labelSpan.innerHTML.includes(termText)) {
					labelSpan.innerHTML = `Amount Due Today<span style="display: block; font-weight: 400; font-size: 0.65em; color: rgb(75, 85, 99); margin-top: 3px; line-height: 1.2;">(${termText})</span>`;
				}

				// 4. Ensure the main label is Bold
				labelSpan.style.fontWeight = '700';
			} else if (labelSpan.textContent !== 'Total') {
				labelSpan.textContent = 'Total';
				footerItem.style.alignItems = ''; // Reset alignment to default
			}

			// B. Handle Amount Due Skeleton
			if (showAmountDueSkeleton) {
				const priceText = valueSpan.querySelector('.wc-block-formatted-money-amount');
				if (priceText) priceText.style.display = 'none';

				let skelDiv = document.getElementById('starke-amount-skeleton-wrapper');
				if (!skelDiv) {
					skelDiv = document.createElement('div');
					skelDiv.id = 'starke-amount-skeleton-wrapper';
					skelDiv.style.display = 'flex';
					skelDiv.style.justifyContent = 'flex-end';
					skelDiv.style.width = '100%';
					valueSpan.appendChild(skelDiv);
					setSkeletonPortalContainer(skelDiv);
				}
			} else {
				const priceText = valueSpan.querySelector('.wc-block-formatted-money-amount');
				if (priceText) {
					priceText.style.display = '';
					priceText.style.fontWeight = '700';
				}

				const skelDiv = document.getElementById('starke-amount-skeleton-wrapper');
				if (skelDiv) {
					skelDiv.remove();
					setSkeletonPortalContainer(null);
				}
			}

			// C. Inject Total Row Container
			let container = document.getElementById('starke-real-total-portal-container');
			if (isTermActive) {
				if (!container) {
					container = document.createElement('div');
					container.id = 'starke-real-total-portal-container';
					container.style.width = '100%';
					footerItem.parentNode.insertBefore(container, footerItem);
					setTotalRowContainer(container);
				}
			} else if (container) {
				container.remove();
				setTotalRowContainer(null);
			}

			// D. Inject Future Due Row Container (Underneath)
			let futureContainer = document.getElementById('starke-future-due-portal-container');
			if (isTermActive) {
				if (!futureContainer) {
					futureContainer = document.createElement('div');
					futureContainer.id = 'starke-future-due-portal-container';
					futureContainer.style.width = '100%';
					// Insert AFTER the footer item (Amount Due Today)
					if (footerItem.nextSibling) {
						footerItem.parentNode.insertBefore(futureContainer, footerItem.nextSibling);
					} else {
						footerItem.parentNode.appendChild(futureContainer);
					}
					setFutureDueRowContainer(futureContainer);
				}
			} else if (futureContainer) {
				futureContainer.remove();
				setFutureDueRowContainer(null);
			}

			return true;
		};

		updateUI();

		const observer = new MutationObserver(() => {
			updateUI();
		});

		const totalsWrapper = document.querySelector('.wc-block-components-totals-wrapper');
		if (totalsWrapper && totalsWrapper.parentNode) {
			observer.observe(totalsWrapper.parentNode, { childList: true, subtree: true });
		} else {
			observer.observe(document.body, { childList: true, subtree: true });
		}

		return () => observer.disconnect();
	}, [selectedTerm, showAmountDueSkeleton]);

	// --- 8. PLACEMENT ---
	useEffect(() => {
		const thisFieldset = document.getElementById('starke-payment-terms-step');
		if (!thisFieldset) return;

		const placeBlock = () => {
			const paymentOptionsStep = document.getElementById('payment-method');

			// --- Hide Payment Options if Net 30 ---
			if (paymentOptionsStep) {
				paymentOptionsStep.style.display = selectedTerm === 'net_30' ? 'none' : '';
			}

			if (paymentOptionsStep && paymentOptionsStep.parentNode) {
				if (paymentOptionsStep.nextElementSibling !== thisFieldset) {
					paymentOptionsStep.parentNode.insertBefore(thisFieldset, paymentOptionsStep.nextSibling);
				}
				return true;
			}
			if (selectedTerm === 'net_30') return true;
			return false;
		};

		if (placeBlock()) return;

		const observer = new MutationObserver((mutations, obs) => {
			if (placeBlock()) obs.disconnect();
		});

		const checkoutBlock = document.querySelector('.wp-block-woocommerce-checkout-block') || document.body;
		observer.observe(checkoutBlock, { childList: true, subtree: true });

		return () => observer.disconnect();
	}, [selectedTerm]);

	// --- CRITICAL CHECK: Hide block if no special terms assigned ---
	// If the admin has set 'no_terms' (default), the customer pays in full.
	// The block UI should not appear, but we STILL need to render the portals for the skeletons!
	const shouldShowTermsUI = checkoutExtensionData && assignedPaymentTerm !== 'no_terms' && !isSamplesOnly;

	// --- Define Options Dynamically ---
	// Always include No Terms. Then include the specific assigned term.
	const allOptions = {
		no_terms: { value: 'no_terms', label: 'No Terms', desc: 'Full payment needed at time of order.' },
		'50_50': { value: '50_50', label: '50% Down / 50% on Delivery', desc: 'Pay 50% today. Remainder due on delivery.' },
		net_30: { value: 'net_30', label: 'Net 30', desc: 'Full payment due within 30 days of invoice.' }
	};

	const termOptions = [
		allOptions.no_terms,
		// Safely add the assigned term if it exists in our map
		allOptions[assignedPaymentTerm]
	].filter(Boolean); // Removes undefined if assignedPaymentTerm is invalid

	return (
		<>
			{shouldShowTermsUI && (
				<fieldset
					className="wp-block-vern_shipping_block-payment-terms-block wc-block-components-checkout-step wc-block-components-checkout-step--with-step-number"
					id="starke-payment-terms-step"
					style={isBelowThreshold ? { opacity: 0.6 } : {}}
				>
					<div className="wc-block-components-checkout-step__heading">
						<h2 className="wc-block-components-title wc-block-components-checkout-step__title">
							{__('Payment Terms', 'vern_shipping_block')}
						</h2>
					</div>
					<div className="wc-block-components-checkout-step__container">
						<div className="wc-block-components-checkout-step__content">
							{isBelowThreshold ? (
								<p style={{ marginBottom: '15px', color: '#cc1818', fontSize: '0.95rem', fontWeight: '500' }}>
									{__('Orders under $50 must be paid in full.', 'vern_shipping_block')}
								</p>
							) : (
								<p style={{ marginBottom: '15px', color: '#667', fontSize: '0.95rem' }}>
									{__('Select your preferred default payment structure for this order.', 'vern_shipping_block')}
								</p>
							)}

							<div className="wc-block-components-radio-control" style={{ display: 'flex', flexDirection: 'column', width: '100%' }}>
								{showRadioSkeleton ? (
									<div style={{ width: '100%', display: 'flex', flexDirection: 'column' }}>
										{termOptions.map((option, index) => (
											<div
												key={index}
												style={{
													display: 'flex',
													alignItems: 'center',
													width: '100%',
													marginBottom: '0px',
													border: '1px solid #e5e5e5',
													borderRadius: '4px',
													paddingLeft: '.9em',
													boxSizing: 'border-box',
													minHeight: '82px'
												}}
											>
												{/* 1. The Radio Circle Skeleton */}
												<div
													style={{
														width: '22px',
														height: '22px',
														borderRadius: '50%',
														overflow: 'hidden',
														flexShrink: 0,
														marginRight: '45px'
													}}
												>
													<SkeletonV width="100%" height="100%" />
												</div>

												{/* 2. The Text Skeleton (Title & Description) */}
												<div
													style={{
														display: 'flex',
														flexDirection: 'column',
														justifyContent: 'center',
														width: '100%',
														padding: '.875em .875em .875em 0',
														margin: '0px',
														gap: '8px'
													}}
												>
													<SkeletonV width={index === 0 ? '30%' : '55%'} height="18px" />
													<SkeletonV width={index === 0 ? '60%' : '85%'} height="15px" />
												</div>
											</div>
										))}
									</div>
								) : (
									<RadioControl
										id="starke_payment_terms_options"
										selected={selectedTerm}
										onChange={handleTermChange}
										highlightChecked={true}
										disabled={isBelowThreshold}
										options={termOptions.map((option) => ({
											value: option.value,
											label: (
												<div
													style={{
														display: 'flex',
														flexDirection: 'column',
														justifyContent: 'center',
														width: '100%',
														padding: '.875em .875em .875em 1.25em',
														margin: '0px'
													}}
												>
													<span style={{ fontWeight: 500, color: 'black' }}>{option.label}</span>
													<span style={{ fontSize: '0.9em', color: '#667', marginTop: '2px' }}>{option.desc}</span>
												</div>
											)
										}))}
									/>
								)}
							</div>
						</div>
					</div>
				</fieldset>
			)}

			{/* Portal: Real "Total" Row */}
			{totalRowContainer &&
				createPortal(
					<div
						className="wc-block-components-totals-item"
						style={{
							display: 'flex',
							justifyContent: 'space-between',
							marginBottom: '15px',
							paddingBottom: '11px',
							borderBottom: '2px dashed rgb(210, 210, 210)'
						}}
					>
						<span className="wc-block-components-totals-item__label" style={{ fontWeight: '600', fontSize: '1.25em' }}>
							Total
						</span>

						<span
							className="wc-block-formatted-money-amount wc-block-components-formatted-money-amount wc-block-components-totals-item__value"
							style={{ fontWeight: '600', fontSize: '1.25em' }}
						>
							{showTotalSkeleton ? (
								<div style={{ display: 'flex', justifyContent: 'flex-end', width: '100%' }}>
									<SkeletonV width="45px" height="18px" />
								</div>
							) : (
								formatCurrency(naturalCartTotal)
							)}
						</span>
						<div className="wc-block-components-totals-item__description"></div>
					</div>,
					totalRowContainer
				)}

			{/* Portal: "Future Amount Due" Row */}
			{futureDueRowContainer &&
				(function () {
					// Calculate Future Amounts
					let futureLabel = '';
					let futureAmount = 0;

					if (selectedTerm === '50_50') {
						futureLabel = 'Balance Due Upon Delivery';

						// Math.round ensures the extra penny goes to 'Due Today'
						const dueTodayCents = Math.round(naturalCartTotal / 2);

						console.log('Natural Cart Total (cents):', naturalCartTotal);
						console.log('Due Today (cents):', dueTodayCents);

						// The future amount is just whatever is left over
						futureAmount = naturalCartTotal - dueTodayCents;
					} else if (selectedTerm === 'net_30') {
						futureLabel = 'Balance Due in 30 Days';
						futureAmount = naturalCartTotal; // Assuming $0 paid today
					} else {
						return null; // Should not happen if container exists, but safe
					}

					return createPortal(
						<div
							className="wc-block-components-totals-item"
							style={{
								display: 'flex',
								justifyContent: 'space-between',
								marginTop: '15px', // Add some spacing from the "Today" row
								marginBottom: '0px',
								paddingBottom: '0px'
							}}
						>
							<span className="wc-block-components-totals-item__label" style={{ fontWeight: '600', fontSize: '1.25em', color: '#667' }}>
								{futureLabel}
							</span>

							<span
								className="wc-block-formatted-money-amount wc-block-components-formatted-money-amount wc-block-components-totals-item__value"
								style={{ fontWeight: '600', fontSize: '1.25em', color: '#667' }}
							>
								{/* UPDATED: Use showAmountDueSkeleton to match the behavior of the main Amount Due row */}
								{showAmountDueSkeleton ? (
									<div style={{ display: 'flex', justifyContent: 'flex-end', width: '100%' }}>
										<SkeletonV width="45px" height="18px" />
									</div>
								) : (
									formatCurrency(futureAmount)
								)}
							</span>
							<div className="wc-block-components-totals-item__description"></div>
						</div>,
						futureDueRowContainer
					);
				})()}

			{/* Portal: "Amount Due Today" Skeleton */}
			{skeletonPortalContainer && showAmountDueSkeleton && createPortal(<SkeletonV width="45px" height="18px" />, skeletonPortalContainer)}
		</>
	);
};

export default Block;
