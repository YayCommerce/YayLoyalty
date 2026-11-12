import { useMemo } from 'react';
import { Controller, useWatch } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import type { DesignPreviewInput } from '@/lib/api/design.api';
import { useDesignQuery } from '@/lib/queries/design.queries';
import { cn } from '@/lib/utils';
import { ColorPicker } from '@/components/ui/color-picker';
import { LauncherPreviewCard } from '@/pages/settings/LauncherPreviewCard';
import { DEFAULT_BRAND_COLOR, type WizardStepProps } from './setup-wizard.form';
import { WizardSection, WizardStepHeading } from './WizardStepParts';

/** Swatches offered before the free picker, the default brand colour first. */
const SWATCHES = [DEFAULT_BRAND_COLOR, '#2a2a2a', '#6a6a6a', '#c8c8c8'];

/**
 * Step 4 (artboard 1d): one brand colour, with the launcher drawn by the server as in Settings → Design.
 * Only the colour is unsaved here: labels and rates come from the stored settings until step 5 saves the answers.
 */
export function LauncherLookStep({ form }: WizardStepProps) {
  const brandColor = useWatch({ control: form.control, name: 'brandColor' });
  const { data: design } = useDesignQuery();
  const previewInput = useMemo<DesignPreviewInput>(
    () => ({
      launcher: { ...design.launcher, brand_color: brandColor },
      visibility: {
        hide_on_mobile: design.visibility.hide_on_mobile,
        show_bonus_offers: design.visibility.show_bonus_offers,
      },
      panel: { show_reward_catalog: design.panel.show_reward_catalog },
    }),
    [design, brandColor],
  );

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
            </div>
          </WizardSection>
          <div className="bg-muted/50 flex flex-col gap-1.5 rounded-md border p-3.5">
            <div className="text-xs font-semibold">{__('Later, in Settings → Design', 'yayloyalty')}</div>
            <div className="text-muted-foreground text-xs">
              {__('Icons, page rules, custom CSS. One choice here is enough to go live.', 'yayloyalty')}
            </div>
          </div>
        </div>

        <div className="flex min-w-0 flex-1 flex-col gap-2">
          <LauncherPreviewCard input={previewInput} title={__('On your storefront', 'yayloyalty')} />
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
