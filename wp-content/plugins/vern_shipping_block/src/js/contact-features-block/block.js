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
import { useCallback, useEffect, useState, useRef, useMemo, flushSync } from '@wordpress/element';
import { CheckboxControl, ValidationInputError, extensionCartUpdate } from '@woocommerce/blocks-checkout';
import { getSetting } from '@woocommerce/settings';
import { useSelect, useDispatch } from '@wordpress/data';
import debounce from 'lodash/debounce';
import { TextInput } from '@woocommerce/blocks-components';
//const { TextInput } = wc.blocksComponents;
const { optInDefaultText } = getSetting('vern_shipping_block_data', '');
const nameSpace = 'vern_shipping_block';
const CARTSTORE = 'wc/store/cart';
const isActive = true;

const MAX_EMAILS = 5;
const isValidEmail = (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);

const Block = ({ children, checkoutExtensionData }) => {
	const { setExtensionData } = checkoutExtensionData;
	const { setValidationErrors, clearValidationError } = useDispatch('wc/store/validation');
	const [emails, setEmails] = useState([]);
	const generateId = () => crypto.randomUUID();
	const mainEmailElem = useRef(null);
	const isInitialized = useRef(false);

	const { ccEmails } = useSelect((select) => {
		const store = select(CARTSTORE);
		const cartData = store.getCartData();
		const extensionData = cartData?.extensions[nameSpace];
		return {
			ccEmails: extensionData?.cc_emails
		};
	}, []);

	useEffect(() => {
		if (isInitialized.current) return;
		if (ccEmails === undefined) return;
		setEmails(
			ccEmails.slice(0, MAX_EMAILS).map((email) => ({
				id: generateId(),
				value: email
			}))
		);
		isInitialized.current = true;
	}, [ccEmails]);

	// Debounced save of the whole email array
	const debouncedSaveEmails = useCallback(
		debounce((emailArray) => {
			extensionCartUpdate({
				namespace: nameSpace,
				data: {
					action: 'update_cc_emails_in_session',
					field_name: 'cc_emails',
					cc_emails: emailArray.map((entry) => entry.value)
				}
			})
				.then(() => {
					console.log('Saved cc_emails:', emailArray);
				})
				.catch(() => {
					console.log('Failed to save cc_emails');
				});
		}, 250),
		[]
	);

	// Whenever the email array changes, trigger the debounced save
	useEffect(() => {
		const allValid = emails.every((entry) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(entry.value));
		const anyEmpty = emails.some((entry) => entry.value.trim() === '');

		if (allValid || anyEmpty) {
			debouncedSaveEmails(emails);
		}
	}, [emails]);

	useEffect(() => {
		if (!mainEmailElem.current) {
			mainEmailElem.current = document.getElementById('contact');
			if (mainEmailElem.current) {
				mainEmailElem.current.style.marginBottom = '0px';
			}
		}
	});

	const addEmailField = () => {
		if (emails.length < MAX_EMAILS) {
			const newId = generateId();

			// 1. Force React to update the DOM *synchronously* (Immediately)
			// This keeps us inside the user's "Click" event stack.
			flushSync(() => {
				setEmails([...emails, { id: newId, value: '' }]);
			});

			// 2. Now that the input exists in the DOM, we can focus it.
			// Because we are still in the click event, the mobile keyboard is allowed to open.
			const inputElement = document.getElementById(`cc_email_${newId}`);
			if (inputElement) {
				inputElement.focus();
			}
		}
	};

	const updateEmailValue = (id, value) => {
		setEmails((prev) => prev.map((entry) => (entry.id === id ? { ...entry, value } : entry)));
	};

	const removeEmailField = (idToRemove) => {
		setEmails((prev) => prev.filter((entry) => entry.id !== idToRemove));
	};

	return (
		<>
			{isActive && (
				<style>
					{/*`
						.email-buttons {
							cursor: pointer;
							color: rgb(75, 85, 99);
							text-decoration: none;
						}
						.email-buttons:hover {
							text-decoration: underline;
						}
					`*/}
				</style>
			)}
			{isActive && (
				<div
					className="cc-email-addresses"
					id="cc-email-addresses"
					style={{ color: 'black', display: 'flex', flexDirection: 'column', gap: '12px', marginTop: emails.length === 0 ? '6px' : '16px' }}
				>
					{emails.map((entry, index) => (
						<div key={entry.id} style={{ display: 'flex', flexDirection: 'column', gap: '4px' }}>
							<TextInputV
								nameSpace={nameSpace}
								setValidationErrors={setValidationErrors}
								clearValidationError={clearValidationError}
								errorMessage="Please enter a valid email address"
								id={`cc_email_${entry.id}`}
								label={`CC Email Address ${index + 1}`}
								required={false}
								type="email"
								extraStyle={{}}
								parentStyle={{ width: '100%' }}
								initialValue={entry.value}
								onValueChange={(val) => updateEmailValue(entry.id, val)}
							/>
							<div style={{ display: 'flex', gap: '20px', justifyContent: 'center' }}>
								{index === emails.length - 1 && emails.length < MAX_EMAILS && (
									<span className="email-buttons" onClick={addEmailField}>
										+ Add email
									</span>
								)}
								<span className="email-buttons" onClick={() => removeEmailField(entry.id)}>
									- Remove email
								</span>
							</div>
						</div>
					))}
					{emails.length === 0 && (
						<span style={{ alignSelf: 'center', marginTop: '5px' }} className="email-buttons" onClick={addEmailField}>
							+ Add email address
						</span>
					)}
				</div>
			)}
		</>
	);
};

// Reusable text input with validation
function TextInputV({
	nameSpace,
	setValidationErrors,
	clearValidationError,
	errorMessage,
	id,
	label,
	required,
	extraStyle,
	parentStyle,
	type = 'text',
	initialValue = '',
	onValueChange = () => {}
}) {
	const [valueState, setValueState] = useState(initialValue);
	const [elementBlurred, setElementBlurred] = useState(false);

	const textInputErrorID = `${id}-error`;
	const textInputError = useSelect((select) => {
		const store = select('wc/store/validation');
		return store.getValidationError(textInputErrorID);
	});

	useEffect(() => {
		const label = document.querySelector(`label[for=${id}]`);
		let shouldShowError = false;
		let errorMsg = errorMessage;

		if (valueState === '') {
			if (required) {
				shouldShowError = true;
			}
		} else if (type === 'email' && !isValidEmail(valueState)) {
			shouldShowError = true;
			errorMsg = 'Please enter a valid email address.';
		}

		if (shouldShowError) {
			setValidationErrors({
				[textInputErrorID]: {
					message: errorMsg,
					hidden: !elementBlurred
				}
			});
			if (label) {
				label.style.color = elementBlurred ? '#cc1818' : 'hsla(0,0%,7%,.7)';
			}
		} else {
			if (label) {
				label.style.color = 'hsla(0,0%,7%,.7)';
			}
			if (textInputError) {
				clearValidationError(textInputErrorID);
			}
		}
	}, [valueState, elementBlurred]);

	useEffect(() => {
		const element = document.querySelector(`#${id}`);
		if (element && element.parentElement) {
			element.parentElement.style.marginTop = '0px';
		}
	}, []);

	function handleTextInput(val) {
		setValueState(val);
		onValueChange(val);
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
		<div style={parentStyle}>
			<TextInput
				id={id}
				label={label}
				value={valueState}
				required={required}
				type={type}
				onChange={handleTextInput}
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
									<Path d="M10 2c4.42 0 8 3.58 8 8s-3.58 8-8 8-8-3.58-8-8 3.58-8 8-8zm1.13 9.38l.35-6.46H8.52l.35 6.46h2.26zm-.09 3.36c.24-.23.37-.55.37-.96 0-.42-.12-.74-.36-.97s-.59-.35-1.06-.35-.82.12-1.07.35-.37.55-.37.97c0 .41.13.73.38.96.26.23.61.34 1.06.34s.8-.11 1.05-.34z" />
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

export default Block;
