import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Every label and helper string for the setup wizard.
 * Functions, not constants: `__()` must run after the locale loads.
 */

// Shell

export const WIZARD_TITLE = () => __('YayLoyalty setup', 'yayloyalty');
export const STEP_OF = (step: number, total: number) =>
  /* translators: 1: the current step, 2: the number of steps. */
  sprintf(__('Step %1$d of %2$d', 'yayloyalty'), step, total);
export const EXIT_LABEL = () => __('Exit setup', 'yayloyalty');
export const BACK_LABEL = () => __('‹ Back', 'yayloyalty');
export const CONTINUE_LABEL = () => __('Continue', 'yayloyalty');
export const SKIP_FIRST_LABEL = () => __('Skip setup and use the defaults', 'yayloyalty');
export const SKIP_LABEL = () => __('Skip — keep the suggested values', 'yayloyalty');
export const SAVE_ERROR_FALLBACK = () => __('Could not save your setup answers.', 'yayloyalty');
export const LOAD_ERROR = () => __('Could not load the setup.', 'yayloyalty');
export const NOTHING_PUBLISHED = () =>
  __('Nothing is published until the last step, and every choice can be changed afterwards.', 'yayloyalty');

// Step 1: points name

export const NAME_TITLE = () => __('What should we call your points?', 'yayloyalty');
export const NAME_SINGULAR_LABEL = () => __('One of them', 'yayloyalty');
export const NAME_PLURAL_LABEL = () => __('More than one', 'yayloyalty');
export const NAME_HELP = () => __('Both forms, so nothing reads “1 points”.', 'yayloyalty');
export const NAME_PREVIEW = (plural: string) =>
  /* translators: %s: the plural points name, as "Points". */
  sprintf(__('You have 850 %s', 'yayloyalty'), plural);
export const CURRENCY_LABEL = () => __('Store currency', 'yayloyalty');
export const CURRENCY_READ_ONLY = () => __('Read only', 'yayloyalty');
export const CURRENCY_HELP = () =>
  __(
    'Taken from WooCommerce → Settings → General. Change it there if it is wrong; what points are worth is set in step 3.',
    'yayloyalty',
  );

// Step 2: earning

export const EARN_TITLE = () => __('How do customers earn points?', 'yayloyalty');
export const EARN_RATE_TITLE = () => __('Points for spending money', 'yayloyalty');
export const EARN_POINTS_SUFFIX = (points: number) => _n('point', 'points', points, 'yayloyalty');
export const EARN_FOR_EVERY = () => __('for every', 'yayloyalty');
export const EARN_SPENT = () => __('spent', 'yayloyalty');
export const EARN_BASE_HELP = () => __('Calculated on the order subtotal, before shipping and tax.', 'yayloyalty');
export const EARN_WORKED = (orderTotal: string, points: string, label: string) =>
  /* translators: 1: an order total, as "$50", 2: the points it earns, 3: the points name. */
  sprintf(__('A %1$s order earns %2$s %3$s', 'yayloyalty'), orderTotal, points, label);
export const EARN_TIMING = () =>
  __('Points arrive when the order is completed, so a cancelled order never leaves points behind.', 'yayloyalty');
export const SUGGESTED_TITLE = () => __('Two more ways to earn, suggested', 'yayloyalty');
export const SIGNUP_TITLE = () => __('Points for creating an account', 'yayloyalty');
export const SIGNUP_HELP = () => __('Awarded once, when the account is created.', 'yayloyalty');
export const REVIEW_TITLE = () => __('Points for writing a product review', 'yayloyalty');
export const REVIEW_HELP = () => __('Verified purchases only, so reviews cannot be farmed for points.', 'yayloyalty');
export const POINTS_UNIT = () => __('points', 'yayloyalty');
export const CAMPAIGNS_NOTE = () =>
  __('These three become your campaigns. Afterwards you edit them on the Campaigns screen, not here.', 'yayloyalty');

// Step 3: spending

export const SPEND_TITLE = () => __('How do customers spend their points?', 'yayloyalty');
export const SPEND_WORTH_TITLE = () => __('What are points worth?', 'yayloyalty');
export const SPEND_OFF = () => __('off an order', 'yayloyalty');
export const SPEND_EARN_BACK = (percent: string) =>
  /* translators: %s: a percentage, as "1%". */
  sprintf(__('At the rate in step 2, a customer earns back %s of what they spend.', 'yayloyalty'), percent);
export const SPEND_MIN_TITLE = () => __('Smallest redemption', 'yayloyalty');
export const SPEND_MIN_HELP = () =>
  __('Below this nobody can redeem, so nobody spends 7 points for 7 cents.', 'yayloyalty');
export const SPEND_MAX_TITLE = () => __('Most of an order points can cover', 'yayloyalty');
export const SPEND_MAX_SUFFIX = () => __('% of the subtotal', 'yayloyalty');
export const SPEND_MAX_HELP = () =>
  __('100% lets a loyal customer pay entirely in points. Lower it if you always want a card payment.', 'yayloyalty');

// Step 4: look

export const LOOK_TITLE = () => __('Make it look like your store', 'yayloyalty');
export const LOOK_POSITION_LABEL = () => __('Where should the button sit?', 'yayloyalty');
export const LOOK_COLOR_LABEL = () => __('Your brand colour', 'yayloyalty');
export const LOOK_COLOR_HELP = () =>
  __('One colour, used for the button and the panel header. Everything else follows your theme.', 'yayloyalty');
export const LOOK_LATER = () =>
  __(
    'Later, in Settings → Design: storefront wording, page rules and more. Two choices here are enough to go live.',
    'yayloyalty',
  );

// Step 5: review and turn on

export const TURN_ON_LABEL = () => __('Turn on my program', 'yayloyalty');
export const SAVE_LATER_LABEL = () => __('Save and finish later', 'yayloyalty');
export const TURN_ON_SUCCESS_TOAST = () => __('Your program is live.', 'yayloyalty');
export const TURN_ON_ERROR_FALLBACK = () => __('Could not turn on your program.', 'yayloyalty');

export const REVIEW_STEP_TITLE = () => __('Ready to turn it on?', 'yayloyalty');
export const REVIEW_STEP_HELP = () =>
  __('This is the first moment anything reaches your storefront. Check it over, or edit any step.', 'yayloyalty');
export const REVIEW_PROGRAM_TITLE = () => __('Your program', 'yayloyalty');
export const REVIEW_EDIT = () => __('Edit', 'yayloyalty');
export const REVIEW_NAMES_LABEL = () => __('Points are called', 'yayloyalty');
export const REVIEW_EARN_LABEL = () => __('Customers earn', 'yayloyalty');
export const REVIEW_EARN_VALUE = (points: string, label: string, amount: string) =>
  /* translators: 1: points, 2: the merchant's points name, 3: an amount of money, as "$1.00". */
  sprintf(__('%1$s %2$s per %3$s spent, when the order is completed', 'yayloyalty'), points, label, amount);
export const REVIEW_SIGNUP_LABEL = () => __('Account signup', 'yayloyalty');
export const REVIEW_SIGNUP_VALUE = (points: string, label: string) =>
  /* translators: 1: points, as "100", 2: the merchant's points name. */
  sprintf(__('%1$s %2$s, once per customer', 'yayloyalty'), points, label);
export const REVIEW_REVIEW_LABEL = () => __('Product review', 'yayloyalty');
export const REVIEW_REVIEW_VALUE = (points: string, label: string) =>
  /* translators: 1: points, as "50", 2: the merchant's points name. */
  sprintf(__('%1$s %2$s, verified purchases only', 'yayloyalty'), points, label);
export const REVIEW_OFF = () => __('Off', 'yayloyalty');
export const REVIEW_WORTH_LABEL = () => __('Points are worth', 'yayloyalty');
export const REVIEW_WORTH_VALUE = (points: string, label: string, amount: string) =>
  /* translators: 1: points, 2: the merchant's points name, 3: an amount of money, as "$1.00". */
  sprintf(__('%1$s %2$s = %3$s off', 'yayloyalty'), points, label, amount);
export const REVIEW_LIMITS_LABEL = () => __('Redemption limits', 'yayloyalty');
export const REVIEW_LIMITS_VALUE = (points: string, label: string, percent: number) =>
  /* translators: 1: the smallest redemption in points, 2: the merchant's points name, 3: a percentage. */
  sprintf(__('from %1$s %2$s, up to %3$d%% of the subtotal', 'yayloyalty'), points, label, percent);
export const REVIEW_LAUNCHER_LABEL = () => __('Launcher', 'yayloyalty');
export const REVIEW_CREATES_TITLE = () => __('What this creates', 'yayloyalty');
export const REVIEW_CAMPAIGNS_LABEL = () => __('Campaigns', 'yayloyalty');
export const REVIEW_CAMPAIGNS_HELP = () =>
  __('The three every install has. Add more, or change these, on the Campaigns screen.', 'yayloyalty');
export const REVIEW_REWARDS_LABEL = () => __('Rewards', 'yayloyalty');
/** Mirrors the name Installer::seed_builtin_reward() seeds, for a user who cannot open the Rewards list. */
export const REVIEW_REWARD_DEFAULT_NAME = () => __('Cart discount', 'yayloyalty');
export const REVIEW_REWARDS_HELP = () => __('The one reward every install has.', 'yayloyalty');
export const REVIEW_WHEN_ON_TITLE = () => __('When you turn it on', 'yayloyalty');
export const REVIEW_WHEN_EARN = () =>
  __(
    'The three campaigns start earning on the next order that completes; orders already placed are not counted.',
    'yayloyalty',
  );
export const REVIEW_WHEN_LAUNCHER = () => __('The launcher appears on your storefront.', 'yayloyalty');
/** Mirrors the names Installer::seed_builtin_campaigns() seeds, joined with " · ", for a user who cannot open the Campaigns list. */
export const REVIEW_CAMPAIGNS_DEFAULT_NAMES = () =>
  __('Points for purchases · Points for signing up · Points for writing a review', 'yayloyalty');
