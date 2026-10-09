import { __, sprintf } from '@wordpress/i18n';

import { parseWPCurrency } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import { cn } from '@/lib/utils';
import type { PreviewDevice } from '@/pages/settings/LauncherPreviewFrame';
import { SAMPLE_BALANCE, valueOfPoints, type SetupForm } from './setup-wizard.form';

/** The answers the preview draws from. */
export type LauncherPreviewValues = Pick<
  SetupForm,
  'brandColor' | 'pointsLabelPlural' | 'earnPoints' | 'earnSpend' | 'worthPoints' | 'worthAmount' | 'minPoints'
>;

/**
 * A simplified storefront with the launcher closed and open at once, drawn from the wizard's
 * unsaved answers. Static on purpose: Settings → Design has the real rendered preview.
 */
export function LauncherPreview({ device, values }: { device: PreviewDevice; values: LauncherPreviewValues }) {
  const { brandColor: color, pointsLabelPlural: pluralLabel, earnPoints, earnSpend, minPoints } = values;
  const worth = valueOfPoints(values, SAMPLE_BALANCE);
  const sliderAt = minPoints < SAMPLE_BALANCE ? 34 : 0;

  return (
    <div
      className={cn(
        'bg-muted relative mx-auto h-140 w-full overflow-hidden border',
        device === 'mobile' && 'max-w-93.75',
      )}
    >
      <div className="bg-border/60 text-muted-foreground flex h-8 items-center gap-4 px-3.5 text-[11px]">
        <span className="font-semibold">STORE</span>
        <span className="ml-auto">{__('Cart', 'yayloyalty')}</span>
      </div>
      <div className="flex gap-4 p-4">
        <div className="bg-border/50 h-52 flex-[0_0_46%]" />
        <div className="flex flex-1 flex-col gap-2.5">
          <div className="bg-border h-4 w-3/4" />
          <div className="bg-border/60 h-3 w-2/5" />
          <div className="bg-border/40 h-2 w-full" />
          <div className="bg-border/40 h-2 w-11/12" />
        </div>
      </div>

      <div className="absolute right-5 bottom-24 flex w-72 max-w-[calc(100%-2.5rem)] flex-col overflow-hidden rounded-lg border bg-white text-neutral-900 shadow-xl">
        <div className="flex items-center gap-2 px-4 py-3 text-white" style={{ backgroundColor: color }}>
          <span className="size-3 bg-white" />
          <span className="text-sm font-semibold">{pluralLabel}</span>
          <span className="ml-auto text-sm">✕</span>
        </div>
        <div className="flex flex-col gap-3 p-4">
          <div className="flex flex-col gap-0.5">
            <span className="text-[11px] tracking-wide text-neutral-500 uppercase">{__('You have', 'yayloyalty')}</span>
            <span className="text-2xl font-semibold">{`${formatPoints(SAMPLE_BALANCE)} ${pluralLabel}`}</span>
            <span className="text-[11px] text-neutral-500">
              {/* translators: %s: what the sample balance is worth, formatted. */}
              {sprintf(__('worth %s', 'yayloyalty'), parseWPCurrency(worth))}
            </span>
          </div>
          <div className="text-xs">
            {sprintf(
              /* translators: 1: points earned; 2: the points label; 3: the spend that earns them, formatted. */
              __('Earn %1$s %2$s for every %3$s you spend', 'yayloyalty'),
              formatPoints(earnPoints),
              pluralLabel,
              parseWPCurrency(earnSpend),
            )}
          </div>
          <div className="flex flex-col gap-2 border-t pt-2.5">
            <div className="text-xs">{__('Spend your points at checkout', 'yayloyalty')}</div>
            <div className="relative h-1 bg-neutral-200">
              <div className="absolute inset-y-0 left-0" style={{ width: `${sliderAt}%`, backgroundColor: color }} />
              <div
                className="absolute -top-1.5 size-4 rounded-full border-2 bg-white"
                style={{ left: `${sliderAt}%`, borderColor: color }}
              />
            </div>
            <div className="flex text-[11px] text-neutral-500">
              {/* translators: %s: the smallest redemption, in points. */}
              <span>{sprintf(__('%s minimum', 'yayloyalty'), formatPoints(minPoints))}</span>
              {/* translators: %s: the sample balance, in points. */}
              <span className="ml-auto">{sprintf(__('%s maximum', 'yayloyalty'), formatPoints(SAMPLE_BALANCE))}</span>
            </div>
            <div
              className="rounded-lg py-2 text-center text-xs font-medium text-white"
              style={{ backgroundColor: color }}
            >
              {__('Apply points', 'yayloyalty')}
            </div>
          </div>
        </div>
      </div>

      <div
        className="absolute right-5 bottom-5 flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium text-white"
        style={{ backgroundColor: color }}
      >
        <span className="size-3 bg-white" />
        {pluralLabel}
      </div>
    </div>
  );
}
