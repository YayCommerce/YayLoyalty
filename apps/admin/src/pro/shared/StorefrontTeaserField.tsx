import { __, sprintf } from '@wordpress/i18n';

import { ADVERT_MODES, ADVERT_TEXT_MAX, type AdvertConfig, type AdvertMode } from '@/lib/schema/campaign.schema';
import { cn } from '@/lib/utils';
import { FieldDescription, FieldLegend, FieldSet } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';

const MODE_LABELS: Record<AdvertMode, () => string> = {
  auto: () => __('Auto', 'yayloyalty'),
  custom: () => __('Custom', 'yayloyalty'),
  off: () => __('Off', 'yayloyalty'),
};

/**
 * The "Storefront teaser" of a campaign or reward with conditions: the line shoppers who don't meet them
 * see under the offer, worded from the conditions (Auto), the merchant's own text (Custom), or none (Off).
 * Writes `advert_mode` and `advert_text` into the item's config. `autoPhrase` is the Auto wording when the
 * caller can ask the server for it: a string, null when the conditions can't be worded, undefined when unknown.
 */
export function StorefrontTeaserField({
  config,
  onPatch,
  autoPhrase,
}: {
  config: Record<string, unknown>;
  onPatch: (patch: AdvertConfig) => void;
  autoPhrase?: string | null;
}) {
  const mode = isAdvertMode(config.advert_mode) ? config.advert_mode : 'auto';
  const text = typeof config.advert_text === 'string' ? config.advert_text : '';

  return (
    <FieldSet className="gap-3">
      <FieldLegend variant="label">{__('Storefront teaser', 'yayloyalty')}</FieldLegend>
      <FieldDescription>
        {__("The line shoppers who don't meet the conditions see under this offer.", 'yayloyalty')}
      </FieldDescription>

      <RadioGroup
        className="flex flex-wrap gap-3"
        value={mode}
        onValueChange={(next) => isAdvertMode(next) && onPatch({ advert_mode: next })}
      >
        {ADVERT_MODES.map((value) => (
          <label
            key={value}
            className={cn(
              'flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm',
              mode === value && 'border-primary',
            )}
          >
            <RadioGroupItem value={value} />
            {MODE_LABELS[value]()}
          </label>
        ))}
      </RadioGroup>

      {mode === 'auto' && <FieldDescription>{autoDescription(autoPhrase)}</FieldDescription>}

      {mode === 'custom' && (
        <div className="flex flex-col gap-1">
          <Input
            aria-label={__('Teaser text', 'yayloyalty')}
            maxLength={ADVERT_TEXT_MAX}
            value={text}
            placeholder={__('for our insiders', 'yayloyalty')}
            onChange={(event) => onPatch({ advert_text: event.target.value })}
          />
          <FieldDescription>
            {sprintf(
              /* translators: 1: characters typed, 2: the most allowed. */
              __('%1$d / %2$d characters. Left empty, no teaser is shown.', 'yayloyalty'),
              text.length,
              ADVERT_TEXT_MAX,
            )}
          </FieldDescription>
        </div>
      )}

      {mode === 'off' && (
        <FieldDescription>
          {__("Shoppers who don't qualify aren't told about this offer.", 'yayloyalty')}
        </FieldDescription>
      )}
    </FieldSet>
  );
}

function isAdvertMode(value: unknown): value is AdvertMode {
  return typeof value === 'string' && (ADVERT_MODES as readonly string[]).includes(value);
}

function autoDescription(autoPhrase: string | null | undefined): string {
  if (autoPhrase === undefined) {
    return __('Worded from the conditions.', 'yayloyalty');
  }

  if (autoPhrase === null) {
    return __("These conditions can't be worded, so no teaser is shown. Choose Custom to write one.", 'yayloyalty');
  }

  /* translators: %s: the teaser worded from the saved conditions, e.g. "for VIP members". */
  return sprintf(__('Shown as "%s", worded from the saved conditions.', 'yayloyalty'), autoPhrase);
}
