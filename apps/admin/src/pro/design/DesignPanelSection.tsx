import { Control, Controller, useFormContext } from 'react-hook-form';

import type { Design } from '@/lib/schema/design.schema';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import {
  PANEL_SECTION_DESCRIPTION,
  PANEL_SECTION_TITLE,
  PANEL_SHOW_REWARD_CATALOG_DESCRIPTION,
  PANEL_SHOW_REWARD_CATALOG_LABEL,
} from '@/pages/settings/design-settings.copy';

/** One optional-block row: switch on the left, title + one-line description on the right. */
function PanelToggleRow({
  control,
  name,
  id,
  label,
  description,
}: {
  control: Control<Design>;
  name: 'panel.show_reward_catalog';
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

/** Pro. The panel's optional blocks. Mounted through `@/pro`, never imported directly. */
export function DesignPanelSection() {
  const { control } = useFormContext<Design>();

  return (
    <Card>
      <CardHeader>
        <CardTitle>{PANEL_SECTION_TITLE()}</CardTitle>
        <CardDescription>{PANEL_SECTION_DESCRIPTION()}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <PanelToggleRow
          control={control}
          name="panel.show_reward_catalog"
          id="design-panel-show-reward-catalog"
          label={PANEL_SHOW_REWARD_CATALOG_LABEL()}
          description={PANEL_SHOW_REWARD_CATALOG_DESCRIPTION()}
        />
      </CardContent>
    </Card>
  );
}
