import { __ } from '@wordpress/i18n';

/**
 * General tab strings: the program switch, the points naming card, and those shared by Pro's fraud section (`@/pro` FraudLimitsSection) and its Lite upsell card.
 * Functions, not constants: `__()` must run after the locale loads.
 */

export const PROGRAM_STATUS_TITLE = () => __('Rewards program', 'yayloyalty');
export const PROGRAM_STATUS_DESCRIPTION = () =>
  __(
    'When off, customers stop earning and redeeming points, and the storefront hides the launcher and rewards messages. Balances are kept.',
    'yayloyalty',
  );

export const FRAUD_LIMITS_TITLE = () => __('Fraud limits', 'yayloyalty');
export const FRAUD_LIMITS_DESCRIPTION = () => __('Cap how many points one customer can earn in a day.', 'yayloyalty');
export const FRAUD_LIMITS_UPSELL = () =>
  __('Cap how many points one customer can earn in a day. Available in YayLoyalty Pro.', 'yayloyalty');

export const POINTS_NAMING_TITLE = () => __('Points naming', 'yayloyalty');
export const POINTS_NAMING_SINGULAR = () => __('Singular', 'yayloyalty');
export const POINTS_NAMING_PLURAL = () => __('Plural', 'yayloyalty');
export const POINTS_NAMING_ERROR = () => __('Enter a name of 1 to 30 characters.', 'yayloyalty');
export const POINTS_NAMING_PREVIEW_TITLE = () => __('Example:', 'yayloyalty');
export const POINTS_NAMING_DESCRIPTION = () => __('What customers see on the storefront and in emails.', 'yayloyalty');
