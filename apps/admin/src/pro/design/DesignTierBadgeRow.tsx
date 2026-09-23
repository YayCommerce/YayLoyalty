import { XIcon } from 'lucide-react';
import { Controller, useFormContext, useWatch } from 'react-hook-form';

import { designBadgeIconSchema, type Design, type DesignBadgeIcon } from '@/lib/schema/design.schema';
import { Button } from '@/components/ui/button';
import {
  ColorPicker,
  ColorPickerContent,
  ColorPickerInput,
  ColorPickerPanel,
  ColorPickerTrigger,
} from '@/components/ui/color-picker';
import { FieldDescription } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger } from '@/components/ui/select';
import {
  TIER_BADGE_COLOR_LABEL,
  TIER_BADGE_ICON_LABEL,
  TIER_BADGE_LABEL_DESCRIPTION,
  TIER_BADGE_LABEL_LABEL,
  TIER_BADGE_TIER_ID_LABEL,
  TIER_REMOVE_BADGE_LABEL,
} from '@/pages/settings/design-settings.copy';
import { badgeIconLabel } from '@/pages/settings/design-settings.option-labels';

/** The shape itself, not its name — triggers show this instead of text so the row stays compact. */
function BadgeIconGlyph({ icon, color }: { icon: DesignBadgeIcon; color: string }) {
  switch (icon) {
    case 'ring':
      return <span className="size-3.5 shrink-0 rounded-full border-2" style={{ borderColor: color }} />;
    case 'diamond':
      return <span className="size-3 shrink-0 rotate-45" style={{ backgroundColor: color }} />;
    case 'square':
      return <span className="size-3.5 shrink-0" style={{ backgroundColor: color }} />;
  }
}

/**
 * Pro. One `tier.badges[index]` row: a colour-bordered `tier_id` chip doubles
 * as a live preview, a colour swatch, an icon-shape trigger, then the
 * customer-facing `label`. `tier_id` is a free-form slug, not a foreign key
 * — no Tiers entity exists yet (see DesignTierSection's footer note and
 * phase 04's Risk Assessment). The icon control is bound to `DesignBadgeIcon`
 * (ring/diamond/square) — never `DesignLauncherIcon`, which offers `custom`
 * with no upload behind it here.
 */
export function DesignTierBadgeRow({ index, onRemove }: { index: number; onRemove: () => void }) {
  const { control, register } = useFormContext<Design>();
  const color = useWatch({ control, name: `tier.badges.${index}.color` });

  return (
    <div className="flex flex-col gap-1.5 rounded-lg border p-3">
      <div className="flex flex-wrap items-center gap-2">
        <Input
          aria-label={TIER_BADGE_TIER_ID_LABEL()}
          className="w-32 shrink-0 text-center font-mono text-xs font-semibold tracking-wide uppercase"
          style={{ borderColor: color }}
          {...register(`tier.badges.${index}.tier_id`)}
        />

        <Controller
          control={control}
          name={`tier.badges.${index}.color`}
          render={({ field }) => (
            <ColorPicker value={field.value} onValueChange={field.onChange}>
              <ColorPickerTrigger aria-label={TIER_BADGE_COLOR_LABEL()} className="w-9 justify-center px-2">
                <span className="border-accent size-5 rounded-xs border" style={{ backgroundColor: field.value }} />
              </ColorPickerTrigger>
              <ColorPickerContent>
                <ColorPickerPanel />
                <ColorPickerInput />
              </ColorPickerContent>
            </ColorPicker>
          )}
        />

        <Controller
          control={control}
          name={`tier.badges.${index}.icon`}
          render={({ field }) => (
            <Select value={field.value} onValueChange={field.onChange}>
              {/* [&>svg]:hidden drops the shared trigger's chevron — this picker is a bare
                  shape swatch, matching the colour swatch beside it; the shared component
                  itself is untouched so every other select keeps its chevron. */}
              <SelectTrigger
                aria-label={TIER_BADGE_ICON_LABEL()}
                className="w-9 justify-center px-2 [&>svg]:hidden"
              >
                <span className="flex size-4 items-center justify-center">
                  <BadgeIconGlyph icon={field.value} color={color} />
                </span>
              </SelectTrigger>
              <SelectContent>
                {designBadgeIconSchema.options.map((option) => (
                  <SelectItem key={option} value={option}>
                    {/* Two top-level children left to ItemText's own flex layout don't reliably
                        render the glyph — wrap them in one explicit flex span instead, matching
                        the icon+text pattern DesignVisibilitySection already uses. */}
                    <span className="flex items-center gap-2">
                      <BadgeIconGlyph icon={option} color={color} />
                      {badgeIconLabel(option)}
                    </span>
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        />

        <Input
          aria-label={TIER_BADGE_LABEL_LABEL()}
          className="min-w-40 flex-1"
          {...register(`tier.badges.${index}.label`)}
        />

        <Button type="button" variant="ghost" size="icon-sm" aria-label={TIER_REMOVE_BADGE_LABEL()} onClick={onRemove}>
          <XIcon className="size-4" />
        </Button>
      </div>

      <FieldDescription>{TIER_BADGE_LABEL_DESCRIPTION()}</FieldDescription>
    </div>
  );
}
