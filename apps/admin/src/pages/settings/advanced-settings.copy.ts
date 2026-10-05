import { __ } from '@wordpress/i18n';

/**
 * Strings shared by the Advanced tab's Pro form (`@/pro` AdvancedFraudLimitsForm) and its Lite upsell card.
 * Functions, not constants: `__()` must run after the locale loads.
 */

export const FRAUD_LIMITS_TITLE = () => __('Fraud limits', 'yayloyalty');
export const FRAUD_LIMITS_DESCRIPTION = () => __('Cap how many points one customer can earn in a day.', 'yayloyalty');
export const FRAUD_LIMITS_UPSELL = () =>
  __('Cap how many points one customer can earn in a day. Available in YayLoyalty Pro.', 'yayloyalty');
