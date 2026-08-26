/**
 * External dependencies
 */
import { __experimentalRegisterCheckoutFilters } from "@woocommerce/blocks-checkout";
import { registerPaymentMethodExtensionCallbacks } from "@woocommerce/blocks-registry";

export const registerFilters = () => {
	__experimentalRegisterCheckoutFilters("vern_shipping_block", {
		itemName: (name) => {
			return `${name} + extra data!`;
		}
	});

	registerPaymentMethodExtensionCallbacks("vern_shipping_block", {
		cod: (arg) => arg.billingData.city !== "Denver"
	});
};
