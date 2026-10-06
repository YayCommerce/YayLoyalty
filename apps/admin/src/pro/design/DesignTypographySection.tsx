import { useFormContext } from 'react-hook-form';

import { type Design } from '@/lib/schema/design.schema';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Field, FieldContent, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import {
  TYPOGRAPHY_CUSTOM_CSS_DESCRIPTION,
  TYPOGRAPHY_CUSTOM_CSS_LABEL,
  TYPOGRAPHY_SECTION_DESCRIPTION,
  TYPOGRAPHY_SECTION_TITLE,
} from '@/pages/settings/design-settings.copy';

/**
 * Pro. The merchant's custom CSS for the launcher. Stored but not yet applied to the storefront;
 * a future renderer must emit it via `wp_add_inline_style`, never interpolate it into markup, or
 * merchant CSS becomes an injection path.
 */
export function DesignTypographySection() {
  const { register } = useFormContext<Design>();

  return (
    <Card>
      <CardHeader>
        <CardTitle>{TYPOGRAPHY_SECTION_TITLE()}</CardTitle>
        <CardDescription>{TYPOGRAPHY_SECTION_DESCRIPTION()}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <Field>
          <FieldLabel htmlFor="design-typography-custom-css">{TYPOGRAPHY_CUSTOM_CSS_LABEL()}</FieldLabel>
          <FieldContent>
            <Textarea id="design-typography-custom-css" rows={6} {...register('typography.custom_css')} />
            <FieldDescription>{TYPOGRAPHY_CUSTOM_CSS_DESCRIPTION()}</FieldDescription>
          </FieldContent>
        </Field>
      </CardContent>
    </Card>
  );
}
