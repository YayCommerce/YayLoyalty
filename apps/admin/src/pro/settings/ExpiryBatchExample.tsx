import { addMonths } from 'date-fns';
import { __, sprintf } from '@wordpress/i18n';

import { parseWPDate } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';

/** A sample balance, not a real customer: batch sizes and how many months ago each was earned. */
const SAMPLE_BATCHES = [
  { points: 4200, monthsAgo: 7 },
  { points: 3000, monthsAgo: 4 },
  { points: 2650, monthsAgo: 2 },
];

/** Points the example redeems: more than the oldest batch, so the sentence shows a second batch being touched. */
const REDEEM_EXAMPLE = 5000;

/**
 * The batch model on one sample balance: each batch with its earned and expiry dates, and what a
 * redemption does to the next date. The dates follow the months field as it changes.
 */
export function ExpiryBatchExample({ months }: { months: number }) {
  const today = new Date();
  const batches = SAMPLE_BATCHES.map((batch) => {
    const earned = addMonths(today, -batch.monthsAgo);
    return { ...batch, earned, expires: addMonths(earned, months) };
  });
  const total = SAMPLE_BATCHES.reduce((sum, batch) => sum + batch.points, 0);

  return (
    <div className="bg-muted/40 flex flex-col gap-3 rounded-md border p-3">
      <div className="text-sm font-medium">
        {/* translators: 1: points balance, 2: number of batches. */}
        {sprintf(
          __('Example: a balance of %1$s points in %2$d batches', 'yayloyalty'),
          formatPoints(total),
          batches.length,
        )}
      </div>
      <ul className="flex flex-col gap-1 text-sm">
        {batches.map((batch, index) => (
          <li key={batch.monthsAgo} className="flex flex-wrap gap-x-3">
            <span className="w-20 font-medium tabular-nums">{formatPoints(batch.points)}</span>
            {/* translators: %s: a date. */}
            <span className="text-muted-foreground">
              {sprintf(__('earned %s', 'yayloyalty'), parseWPDate(batch.earned))}
            </span>
            {/* translators: %s: a date. */}
            <span>{sprintf(__('expires %s', 'yayloyalty'), parseWPDate(batch.expires))}</span>
            {index === 0 && (
              <span className="text-primary text-xs uppercase">{__('Next to expire', 'yayloyalty')}</span>
            )}
          </li>
        ))}
      </ul>
      <p className="text-muted-foreground text-xs">
        {sprintf(
          /* translators: 1: points redeemed, 2: points taken from the second batch. */
          __(
            'Redeeming %1$s points tomorrow spends the oldest batch first, then %2$s from the next. The next expiry date moves later because the oldest batch is gone, not because the customer was active.',
            'yayloyalty',
          ),
          formatPoints(REDEEM_EXAMPLE),
          formatPoints(REDEEM_EXAMPLE - SAMPLE_BATCHES[0].points),
        )}
      </p>
    </div>
  );
}
