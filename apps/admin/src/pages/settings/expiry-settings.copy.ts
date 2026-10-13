import { __ } from '@wordpress/i18n';

/**
 * Expiry tab strings. Functions, not constants: `__()` must run after the locale loads.
 */

export const EXPIRY_TITLE = () => __('Expire points after inactivity', 'yayloyalty');
export const EXPIRY_DESCRIPTION = () => __('When off, balances are kept until they are spent.', 'yayloyalty');
export const EXPIRY_AFTER_LABEL = () => __('Expire after', 'yayloyalty');
export const EXPIRY_AFTER_HINT = () => __('since the customer last earned or redeemed', 'yayloyalty');
export const EXPIRY_REMIND_LABEL = () => __('Remind before expiry', 'yayloyalty');
export const EXPIRY_REMIND_HINT = () => __('sends the “Points expiring soon” email', 'yayloyalty');
export const EXPIRY_DAYS_UNIT = () => __('days', 'yayloyalty');
export const EXPIRY_EMAILS_LINK = () => __('Emails', 'yayloyalty');
export const EXPIRY_VIEW_THEM = () => __('View them', 'yayloyalty');

export const EXPIRY_PRO_TITLE = () => __('What Pro adds to expiry', 'yayloyalty');
export const EXPIRY_PRO_DESCRIPTION = () => __('The settings above keep working exactly as they are.', 'yayloyalty');
export const EXPIRY_PRO_FEATURES = () => [
  __(
    'Points expire in batches: each batch expires on its own date instead of the whole balance together, so a customer never loses points they earned last week.',
    'yayloyalty',
  ),
  __('A second reminder: one more email closer to the date, on top of the first notice.', 'yayloyalty'),
  __('A reminder floor: skip the email when the expiring points are too few to spend.', 'yayloyalty'),
];
