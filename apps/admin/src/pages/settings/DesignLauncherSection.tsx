import { Controller, useFormContext } from 'react-hook-form';

import {
  designLauncherButtonStyleSchema,
  designLauncherIconSchema,
  designLauncherPositionSchema,
  type Design,
} from '@/lib/schema/design.schema';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ColorPicker } from '@/components/ui/color-picker';
import { Field, FieldContent, FieldDescription, FieldLabel } from '@/components/ui/field';
import { ImagePicker } from '@/components/ui/image-picker';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
  LAUNCHER_BRAND_COLOR_LABEL,
  LAUNCHER_BUTTON_STYLE_LABEL,
  LAUNCHER_CORNER_RADIUS_DESCRIPTION,
  LAUNCHER_CORNER_RADIUS_LABEL,
  LAUNCHER_ICON_LABEL,
  LAUNCHER_ICON_UPLOAD_DESCRIPTION,
  LAUNCHER_ICON_UPLOAD_LABEL,
  LAUNCHER_POSITION_LABEL,
  LAUNCHER_SECTION_DESCRIPTION,
  LAUNCHER_SECTION_TITLE,
} from './design-settings.copy';
import { launcherButtonStyleLabel, launcherIconLabel, launcherPositionLabel } from './design-settings.option-labels';

/** Position, button style, brand colour, corner radius, icon choice and the conditional icon upload. */
export function DesignLauncherSection() {
  const { control, watch, setValue } = useFormContext<Design>();
  const icon = watch('launcher.icon');
  const buttonStyle = watch('launcher.button_style');

  return (
    <Card>
      <CardHeader>
        <CardTitle>{LAUNCHER_SECTION_TITLE()}</CardTitle>
        <CardDescription>{LAUNCHER_SECTION_DESCRIPTION()}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <Field>
          <FieldLabel>{LAUNCHER_POSITION_LABEL()}</FieldLabel>
          <FieldContent>
            <Controller
              control={control}
              name="launcher.position"
              render={({ field }) => (
                <Segmented value={field.value} onValueChange={(value) => value && field.onChange(value)}>
                  {designLauncherPositionSchema.options.map((position) => (
                    <SegmentedItem key={position} value={position}>
                      {launcherPositionLabel(position)}
                    </SegmentedItem>
                  ))}
                </Segmented>
              )}
            />
          </FieldContent>
        </Field>

        <Field>
          <FieldLabel>{LAUNCHER_BUTTON_STYLE_LABEL()}</FieldLabel>
          <FieldContent>
            <Controller
              control={control}
              name="launcher.button_style"
              render={({ field }) => (
                <Segmented value={field.value} onValueChange={(value) => value && field.onChange(value)}>
                  {designLauncherButtonStyleSchema.options.map((style) => (
                    <SegmentedItem key={style} value={style}>
                      {launcherButtonStyleLabel(style)}
                    </SegmentedItem>
                  ))}
                </Segmented>
              )}
            />
          </FieldContent>
        </Field>

        <Field>
          <FieldLabel>{LAUNCHER_BRAND_COLOR_LABEL()}</FieldLabel>
          <FieldContent>
            <Controller
              control={control}
              name="launcher.brand_color"
              render={({ field }) => <ColorPicker value={field.value} onValueChange={field.onChange} />}
            />
          </FieldContent>
        </Field>

        <Field>
          <FieldLabel htmlFor="design-launcher-corner-radius">{LAUNCHER_CORNER_RADIUS_LABEL()}</FieldLabel>
          <FieldContent>
            <Controller
              control={control}
              name="launcher.corner_radius"
              render={({ field }) => (
                <NumberInputRoot
                  size="small"
                  className="w-28"
                  min={0}
                  max={24}
                  value={field.value}
                  onValueChange={field.onChange}
                >
                  <NumberInputInput id="design-launcher-corner-radius" />
                  <NumberInputUnit unit="px" />
                </NumberInputRoot>
              )}
            />
            <FieldDescription>{LAUNCHER_CORNER_RADIUS_DESCRIPTION()}</FieldDescription>
          </FieldContent>
        </Field>

        {buttonStyle !== 'label' && (
          <Field>
            <FieldLabel>{LAUNCHER_ICON_LABEL()}</FieldLabel>
            <FieldContent>
              <Controller
                control={control}
                name="launcher.icon"
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {designLauncherIconSchema.options.map((option) => (
                        <SelectItem key={option} value={option}>
                          {launcherIconLabel(option)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              />
            </FieldContent>
          </Field>
        )}

        {/* Upload control revealed only for icon === 'custom'; switching away never clears icon_upload_id,
            so switching back restores the merchant's file. */}
        {buttonStyle !== 'label' && icon === 'custom' && (
          <Field>
            <FieldLabel>{LAUNCHER_ICON_UPLOAD_LABEL()}</FieldLabel>
            <FieldContent>
              <ImagePicker
                coverSrc={watch('launcher.icon_upload_url')}
                onSelectCover={(selection) => {
                  setValue('launcher.icon_upload_id', selection.id, { shouldDirty: true });
                  setValue('launcher.icon_upload_url', selection.url, { shouldDirty: true });
                }}
                onRemoveCover={() => {
                  setValue('launcher.icon_upload_id', 0, { shouldDirty: true });
                  setValue('launcher.icon_upload_url', '', { shouldDirty: true });
                }}
              />
              <FieldDescription>{LAUNCHER_ICON_UPLOAD_DESCRIPTION()}</FieldDescription>
            </FieldContent>
          </Field>
        )}
      </CardContent>
    </Card>
  );
}
