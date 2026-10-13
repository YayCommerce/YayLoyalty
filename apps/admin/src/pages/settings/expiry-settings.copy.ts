import { __ } from '@wordpress/i18n';

/**
 * Expiry tab strings, shared with Pro's expiry sections (`@/pro`).
 * Functions, not constants: `__()` must run after the locale loads.
 */

export const INACTIVITY_TITLE = () => __('Expire points after inactivity', 'yayloyalty');
export const INACTIVITY_OFF_HINT = () => __('When off, balances are kept until they are spent.', 'yayloyalty');
export const INACTIVITY_AFTER_LABEL = () => __('Expire after', 'yayloyalty');
export const INACTIVITY_DAYS_UNIT = () => __('days', 'yayloyalty');
export const INACTIVITY_SINCE = () => __('since the customer last earned or redeemed', 'yayloyalty');

export const CONFIRM_SHORTEN_TITLE = () => __('Shorten points expiry?', 'yayloyalty');
export const CONFIRM_SHORTEN_BODY = () =>
  __(
    'Nobody loses points today. Customers whose new expiry date falls within 30 days get 30 days from today.',
    'yayloyalty',
  );
export const CONFIRM_BATCH_TITLE = () => __('Switch to batch expiry?', 'yayloyalty');
export const CONFIRM_BATCH_BODY = () =>
  __("Each earning will expire on its own date. This can't be switched back.", 'yayloyalty');
export const CONFIRM_SAVE = () => __('Save', 'yayloyalty');
export const CONFIRM_CANCEL = () => __('Cancel', 'yayloyalty');
