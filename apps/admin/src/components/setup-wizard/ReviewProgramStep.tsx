import { __, sprintf } from '@wordpress/i18n';

import { parseWPCurrency } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import type { WizardStep, WizardStepProps } from './setup-wizard.form';
import { WizardSection, WizardStepHeading } from './WizardStepParts';

type ReviewProgramStepProps = WizardStepProps & { onEdit: (step: WizardStep) => void };

/** Step 5 (artboard 1e): every answer on one card, each with an Edit link back to the step that set it. */
export function ReviewProgramStep({ form, onEdit }: ReviewProgramStepProps) {
  // The step has no inputs, so a snapshot is enough; Edit unmounts it.
  const v = form.getValues();
  const off = __('Off', 'yayloyalty');

  const rows: { label: string; value: string; step: WizardStep }[] = [
    {
      label: __('Points are called', 'yayloyalty'),
      value: `${v.pointsLabelSingular} · ${v.pointsLabelPlural}`,
      step: 1,
    },
    {
      label: __('Customers earn', 'yayloyalty'),
      /* translators: 1: points earned; 2: the spend that earns them, formatted. */
      value: sprintf(
        __('%1$s points per %2$s spent', 'yayloyalty'),
        formatPoints(v.earnPoints),
        parseWPCurrency(v.earnSpend),
      ),
      step: 2,
    },
    {
      label: __('Account signup', 'yayloyalty'),
      /* translators: %s: points awarded, formatted. */
      value: v.signupEnabled
        ? sprintf(__('%s points, once per customer', 'yayloyalty'), formatPoints(v.signupPoints))
        : off,
      step: 2,
    },
    {
      label: __('Product review', 'yayloyalty'),
      value: v.reviewEnabled
        ? /* translators: %s: points awarded, formatted. */
          sprintf(__('%s points, verified purchases only', 'yayloyalty'), formatPoints(v.reviewPoints))
        : off,
      step: 2,
    },
    {
      label: __('Points are worth', 'yayloyalty'),
      /* translators: 1: a points amount; 2: the discount it buys, formatted. */
      value: sprintf(
        __('%1$s points = %2$s off', 'yayloyalty'),
        formatPoints(v.worthPoints),
        parseWPCurrency(v.worthAmount),
      ),
      step: 3,
    },
    {
      label: __('Redemption limits', 'yayloyalty'),
      value: sprintf(
        /* translators: 1: the smallest redemption, in points; 2: the most of the subtotal points may cover, a percentage. */
        __('from %1$s points, up to %2$s%% of the subtotal', 'yayloyalty'),
        formatPoints(v.minPoints),
        String(v.maxPercent),
      ),
      step: 3,
    },
    {
      label: __('Launcher', 'yayloyalty'),
      /* translators: %s: a hex colour, e.g. #1F6FB2. */
      value: sprintf(__('brand colour %s', 'yayloyalty'), v.brandColor.toUpperCase()),
      step: 4,
    },
  ];

  return (
    <>
      <WizardStepHeading
        title={__('Ready to turn it on?', 'yayloyalty')}
        description={__(
          'This is the first moment anything reaches your storefront. Check it over, or edit any step.',
          'yayloyalty',
        )}
      />
      <WizardSection>
        <div className="flex items-center gap-2.5">
          <div className="text-sm font-semibold">{__('Your program', 'yayloyalty')}</div>
          <div className="text-muted-foreground ml-auto text-xs">{__('every choice you made', 'yayloyalty')}</div>
        </div>
        <div>
          {rows.map((row) => (
            <div
              key={row.label}
              className="grid grid-cols-[1.15fr_2fr_0.5fr] items-baseline gap-3 border-b py-2.5 text-sm last:border-b-0"
            >
              <div className="text-muted-foreground">{row.label}</div>
              <div>{row.value}</div>
              <div className="text-right">
                <button
                  type="button"
                  className="text-primary cursor-pointer text-xs hover:underline"
                  onClick={() => onEdit(row.step)}
                >
                  {__('Edit', 'yayloyalty')}
                </button>
              </div>
            </div>
          ))}
        </div>
      </WizardSection>
    </>
  );
}
