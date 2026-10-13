import { __ } from '@wordpress/i18n';

/** Pro's expiry-model strings. Functions, not constants: `__()` must run after the locale loads. */

export const MODEL_TITLE = () => __('Expiry model', 'yayloyalty');
export const MODEL_ONE_WAY = () => __('One-way migration', 'yayloyalty');
export const MODEL_INACTIVITY_TITLE = () => __('When a customer goes inactive', 'yayloyalty');
export const MODEL_INACTIVITY_BODY = () =>
  __(
    'The whole balance expires after a period with no earning or redemption. A customer sees one date for all their points, and any activity pushes it out.',
    'yayloyalty',
  );
export const MODEL_BATCHES_TITLE = () => __('When each batch of points ages out', 'yayloyalty');
export const MODEL_BATCHES_BODY = () =>
  __(
    'Each earning expires a set time after it was earned, oldest spent first. A customer sees several dates, and part of the balance can expire while the rest stays.',
    'yayloyalty',
  );
export const MODEL_EXPLAINER = () =>
  __(
    'Switching models is a one-way migration. Moving to batches gives every existing balance its earned dates; moving back would have to invent a single date, so it is not offered.',
    'yayloyalty',
  );
export const BATCH_MONTHS_LABEL = () => __('Points expire', 'yayloyalty');
export const BATCH_MONTHS_UNIT = () => __('months', 'yayloyalty');
export const BATCH_MONTHS_HINT = () => __('after the day they were earned', 'yayloyalty');
export const SWITCH_CONFIRM_TITLE = () => __('Switch to batch expiry?', 'yayloyalty');
export const SWITCH_CONFIRM_BODY = () =>
  __(
    'Every customer’s points get expiry dates from when they were earned, and the oldest points may expire on the next daily run. This cannot be undone.',
    'yayloyalty',
  );
export const SWITCH_CONFIRM_ACTION = () => __('Switch to batches', 'yayloyalty');
