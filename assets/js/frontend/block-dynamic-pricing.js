(function () {
    // Ensure required globals exist (Blocks checkout environment).
    if (typeof window === 'undefined' ||
        !window.wc ||
        !window.wc.wcBlocksData ||
        !window.wp ||
        !window.wp.data ||
        !window.wc.blocksCheckout) {
        return;
    }

    const { select, subscribe } = window.wp.data;
    const { PAYMENT_STORE_KEY } = window.wc.wcBlocksData;
    const { extensionCartUpdate } = window.wc.blocksCheckout;

    let previousMethod = null;

    /**
     * Checks the current active payment method and, if it changed, triggers
     * a cart update so server-side totals (and our dynamic pricing fees)
     * are recalculated with the correct payment method context.
     */
    const maybeTriggerDynamicPricingUpdate = () => {
        const store = select(PAYMENT_STORE_KEY);
        if (!store || typeof store.getActivePaymentMethod !== 'function') {
            return;
        }
        const current = store.getActivePaymentMethod();
        if (current && current !== previousMethod) {
            console.log('Vibe Dynamic Pricing: Payment method changed from', previousMethod, 'to', current);
            previousMethod = current;
            try {
                // Send the payment method information to the backend
                extensionCartUpdate({
                    namespace: 'vibe-dynamic-pricing',
                    data: {
                        payment_method: current,
                        trigger: 'payment_method_change',
                        timestamp: Date.now()
                    },
                });
            } catch (e) {
                console.warn('Vibe Dynamic Pricing: Failed to trigger cart update', e);
            }
        }
    };

    // React to store changes.
    subscribe(maybeTriggerDynamicPricingUpdate);

    // Run once on load in case the initial payment method is already selected.
    maybeTriggerDynamicPricingUpdate();
})(); 