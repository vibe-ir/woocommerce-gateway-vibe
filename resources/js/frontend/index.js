import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { __ } from '@wordpress/i18n';
import { getSetting } from '@woocommerce/settings';
import { decodeEntities } from '@wordpress/html-entities';

const settings = getSetting('vibe_data', {});

/**
 * Content component
 */
const Content = () => {
	return <div dangerouslySetInnerHTML={{ __html: decodeEntities(settings.description || '') }} />;
};

/**
 * Label component
 */
const Label = (props) => {
	const { PaymentMethodLabel } = props.components;
	return <PaymentMethodLabel text={decodeEntities(settings.title || __('Vibe Payment', 'woocommerce-gateway-vibe'))} />;
};

/**
 * Vibe payment method config
 */
const vibePaymentMethod = {
	name: 'vibe',
	label: <Label />,
	content: <Content />,
	edit: <Content />,
	canMakePayment: () => true,
	ariaLabel: decodeEntities(settings.title || __('Vibe Payment', 'woocommerce-gateway-vibe')),
	supports: {
		features: settings.supports || [],
	},
};

registerPaymentMethod(vibePaymentMethod);
