import { Controller, useFormContext, useWatch } from 'react-hook-form';

import { designTypographyModeSchema, type Design } from '@/lib/schema/design.schema';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Field, FieldContent, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
  TYPOGRAPHY_CUSTOM_CSS_DESCRIPTION,
  TYPOGRAPHY_CUSTOM_CSS_LABEL,
  TYPOGRAPHY_FONT_LABEL,
  TYPOGRAPHY_MODE_LABEL,
  TYPOGRAPHY_SECTION_DESCRIPTION,
  TYPOGRAPHY_SECTION_TITLE,
} from '@/pages/settings/design-settings.copy';
import {
  TYPOGRAPHY_FONT_DEFAULT_VALUE,
  typographyFontOptions,
  typographyModeLabel,
} from '@/pages/settings/design-settings.option-labels';

/**
 * Pro. Mode select; `font` and `custom_css` only render when mode is
 * `custom`. `custom_css` is stored but not yet applied to the storefront —
 * a future renderer must emit it via `wp_add_inline_style`, never
 * interpolate it into markup (phase file, Security Considerations).
 */
export function DesignTypographySection() {
  const { control, register } = useFormContext<Design>();
  const mode = useWatch({ control, name: 'typography.mode' });

  return (
    <Card>
      <CardHeader>
        <CardTitle>{TYPOGRAPHY_SECTION_TITLE()}</CardTitle>
        <CardDescription>{TYPOGRAPHY_SECTION_DESCRIPTION()}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <Field>
          <FieldLabel>{TYPOGRAPHY_MODE_LABEL()}</FieldLabel>
          <FieldContent>
            <Controller
              control={control}
              name="typography.mode"
              render={({ field }) => (
                <Select value={field.value} onValueChange={field.onChange}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {designTypographyModeSchema.options.map((option) => (
                      <SelectItem key={option} value={option}>
                        {typographyModeLabel(option)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            />
          </FieldContent>
        </Field>

        {mode === 'custom' && (
          <>
            <Field>
              <FieldLabel>{TYPOGRAPHY_FONT_LABEL()}</FieldLabel>
              <FieldContent>
                <Controller
                  control={control}
                  name="typography.font"
                  render={({ field }) => (
                    <Select
                      value={field.value || TYPOGRAPHY_FONT_DEFAULT_VALUE}
                      onValueChange={(value) =>
                        field.onChange(value === TYPOGRAPHY_FONT_DEFAULT_VALUE ? '' : value)
                      }
                    >
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {typographyFontOptions().map((option) => (
                          <SelectItem key={option.value} value={option.value}>
                            {option.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                />
              </FieldContent>
            </Field>

            <Field>
              <FieldLabel htmlFor="design-typography-custom-css">{TYPOGRAPHY_CUSTOM_CSS_LABEL()}</FieldLabel>
              <FieldContent>
                <Textarea id="design-typography-custom-css" rows={6} {...register('typography.custom_css')} />
                <FieldDescription>{TYPOGRAPHY_CUSTOM_CSS_DESCRIPTION()}</FieldDescription>
              </FieldContent>
            </Field>
          </>
        )}
      </CardContent>
    </Card>
  );
}
