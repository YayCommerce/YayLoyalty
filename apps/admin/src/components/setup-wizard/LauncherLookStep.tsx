import { useState } from 'react';
import { Controller, useWatch } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { ColorPicker } from '@/components/ui/color-picker';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { LauncherPreview, type PreviewDevice } from './LauncherPreview';
import type { WizardStepProps } from './setup-wizard.form';
import { WizardSection, WizardStepHeading } from './WizardStepParts';

/** Swatches offered before the free picker; the first is the design default. */
const SWATCHES = ['#1f6fb2', '#2a2a2a', '#6a6a6a', '#c8c8c8'];

/** Step 4 (artboard 1d): one brand colour, with the launcher drawn from the answers so far. */
export function LauncherLookStep({ form }: WizardStepProps) {
  const [device, setDevice] = useState<PreviewDevice>('desktop');
  const values = useWatch({ control: form.control });
  const brandColor = values.brandColor ?? '';

  return (
    <>
      <WizardStepHeading
        title={__('Make it look like your store', 'yayloyalty')}
        description={__(
          'One choice here. Everything else about the look can wait until the program is running.',
          'yayloyalty',
        )}
      />
      <div className="flex items-start gap-5">
        <div className="flex w-80 flex-none flex-col gap-4">
          <WizardSection>
            <div className="flex flex-col gap-2.5">
              <div className="text-sm font-semibold">{__('Your brand colour', 'yayloyalty')}</div>
              <div className="flex flex-wrap items-center gap-2">
                {SWATCHES.map((swatch) => (
                  <button
                    key={swatch}
                    type="button"
                    aria-label={swatch}
                    className={cn(
                      'size-7 cursor-pointer border',
                      swatch.toLowerCase() === brandColor.toLowerCase() && 'ring-primary ring-2 ring-offset-1',
                    )}
                    style={{ backgroundColor: swatch }}
                    onClick={() => form.setValue('brandColor', swatch)}
                  />
                ))}
              </div>
              <Controller
                control={form.control}
                name="brandColor"
                render={({ field }) => <ColorPicker value={field.value} onValueChange={field.onChange} />}
              />
              <div className="text-muted-foreground text-xs">
                {__(
                  'One colour, used for the button and the panel header. Everything else follows your theme.',
                  'yayloyalty',
                )}
              </div>
            </div>
          </WizardSection>
          <div className="bg-muted/50 flex flex-col gap-1.5 rounded-md border p-3.5">
            <div className="text-xs font-semibold">{__('Later, in Settings → Design', 'yayloyalty')}</div>
            <div className="text-muted-foreground text-xs">
              {__(
                'Icons, storefront wording, page rules, custom CSS. One choice here is enough to go live.',
                'yayloyalty',
              )}
            </div>
          </div>
        </div>

        <div className="bg-background flex min-w-0 flex-1 flex-col gap-3 rounded-md border p-4">
          <div className="flex items-center gap-2.5">
            <div className="text-sm font-semibold">{__('On your storefront', 'yayloyalty')}</div>
            <Segmented
              className="ml-auto"
              value={device}
              onValueChange={(next) => next && setDevice(next as PreviewDevice)}
            >
              <SegmentedItem value="desktop">{__('Desktop', 'yayloyalty')}</SegmentedItem>
              <SegmentedItem value="mobile">{__('Mobile', 'yayloyalty')}</SegmentedItem>
            </Segmented>
          </div>
          <LauncherPreview
            device={device}
            color={brandColor}
            pluralLabel={values.pointsLabelPlural ?? ''}
            earnPoints={values.earnPoints ?? 0}
            earnSpend={values.earnSpend ?? 0}
            worthPoints={values.worthPoints ?? 0}
            worthAmount={values.worthAmount ?? 0}
            minPoints={values.minPoints ?? 0}
          />
          <div className="text-muted-foreground text-xs">
            {__(
              'A simplified page, not your theme. Fonts and spacing will follow whatever your theme uses.',
              'yayloyalty',
            )}
          </div>
        </div>
      </div>
    </>
  );
}
