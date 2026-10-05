import { Control, Controller, useFormContext } from 'react-hook-form';

import { designPanelLayoutSchema, type Design, type DesignPanelLayout } from '@/lib/schema/design.schema';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Field, FieldContent, FieldLabel } from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Switch } from '@/components/ui/switch';
import {
  PANEL_LAYOUT_LABEL,
  PANEL_SECTION_DESCRIPTION,
  PANEL_SECTION_TITLE,
  PANEL_SHOW_PROGRESS_TO_NEXT_TIER_DESCRIPTION,
  PANEL_SHOW_PROGRESS_TO_NEXT_TIER_LABEL,
  PANEL_SHOW_REFERRAL_CARD_DESCRIPTION,
  PANEL_SHOW_REFERRAL_CARD_LABEL,
  PANEL_SHOW_REWARD_CATALOG_DESCRIPTION,
  PANEL_SHOW_REWARD_CATALOG_LABEL,
} from '@/pages/settings/design-settings.copy';
import { panelLayoutDescription, panelLayoutLabel } from '@/pages/settings/design-settings.option-labels';

/** A miniature of the panel: the highlighted top and bottom bars appear only in "full". */
function PanelLayoutPreview({ layout }: { layout: DesignPanelLayout }) {
  const full = layout === 'full';
  return (
    <svg viewBox="0 0 120 72" className="w-full" aria-hidden="true">
      <rect x="1" y="1" width="118" height="70" rx="6" className="fill-background stroke-border" />
      <rect x="10" y="10" width="100" height="10" rx="2" className={full ? 'fill-primary' : 'fill-muted'} />
      <rect x="10" y="28" width="100" height="6" rx="2" className="fill-muted" />
      <rect x="10" y="40" width="70" height="6" rx="2" className="fill-muted" />
      {full && <rect x="10" y="54" width="100" height="10" rx="2" className="fill-primary/60" />}
    </svg>
  );
}

/** One optional-block row: switch on the left, title + one-line description on the right. */
function PanelToggleRow({
  control,
  name,
  id,
  label,
  description,
}: {
  control: Control<Design>;
  name: 'panel.show_referral_card' | 'panel.show_progress_to_next_tier' | 'panel.show_reward_catalog';
  id: string;
  label: string;
  description: string;
}) {
  return (
    <div className="flex items-start gap-3">
      <Controller
        control={control}
        name={name}
        render={({ field }) => (
          <Switch id={id} className="mt-0.5 w-11!" checked={field.value} onCheckedChange={field.onChange} />
        )}
      />
      <label htmlFor={id} className="cursor-pointer">
        <div className="text-sm font-medium">{label}</div>
        <div className="text-muted-foreground text-xs">{description}</div>
      </label>
    </div>
  );
}

/**
 * Pro. Layout radio cards, each with a miniature preview, beside the panel's optional blocks.
 * Mounted through `@/pro`, never imported directly.
 */
export function DesignPanelSection() {
  const { control } = useFormContext<Design>();

  return (
    <Card>
      <CardHeader>
        <CardTitle>{PANEL_SECTION_TITLE()}</CardTitle>
        <CardDescription>{PANEL_SECTION_DESCRIPTION()}</CardDescription>
      </CardHeader>
      <CardContent>
        <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
          <Field>
            <FieldLabel>{PANEL_LAYOUT_LABEL()}</FieldLabel>
            <FieldContent>
              <Controller
                control={control}
                name="panel.layout"
                render={({ field }) => (
                  <RadioGroup value={field.value} onValueChange={field.onChange} className="grid grid-cols-2 gap-3">
                    {designPanelLayoutSchema.options.map((option) => (
                      <FieldLabel key={option} htmlFor={`design-panel-layout-${option}`}>
                        <Field>
                          <PanelLayoutPreview layout={option} />
                          <FieldContent className="flex-row items-center gap-2">
                            <RadioGroupItem id={`design-panel-layout-${option}`} value={option} />
                            <div>
                              <div className="text-sm font-medium">{panelLayoutLabel(option)}</div>
                              <div className="text-muted-foreground text-xs">{panelLayoutDescription(option)}</div>
                            </div>
                          </FieldContent>
                        </Field>
                      </FieldLabel>
                    ))}
                  </RadioGroup>
                )}
              />
            </FieldContent>
          </Field>

          <div className="flex flex-col gap-4">
            <PanelToggleRow
              control={control}
              name="panel.show_referral_card"
              id="design-panel-show-referral-card"
              label={PANEL_SHOW_REFERRAL_CARD_LABEL()}
              description={PANEL_SHOW_REFERRAL_CARD_DESCRIPTION()}
            />
            <PanelToggleRow
              control={control}
              name="panel.show_progress_to_next_tier"
              id="design-panel-show-progress-to-next-tier"
              label={PANEL_SHOW_PROGRESS_TO_NEXT_TIER_LABEL()}
              description={PANEL_SHOW_PROGRESS_TO_NEXT_TIER_DESCRIPTION()}
            />
            <PanelToggleRow
              control={control}
              name="panel.show_reward_catalog"
              id="design-panel-show-reward-catalog"
              label={PANEL_SHOW_REWARD_CATALOG_LABEL()}
              description={PANEL_SHOW_REWARD_CATALOG_DESCRIPTION()}
            />
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
