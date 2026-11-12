import { useFormContext } from 'react-hook-form';

import type { Design } from '@/lib/schema/design.schema';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Field, FieldContent, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
  STOREFRONT_TEXT_CART_EARN_LINE_DESCRIPTION,
  STOREFRONT_TEXT_CART_EARN_LINE_LABEL,
  STOREFRONT_TEXT_REQUIRED_ERROR,
  STOREFRONT_TEXT_SECTION_DESCRIPTION,
  STOREFRONT_TEXT_SECTION_TITLE,
} from './design-settings.copy';

/** The cart and checkout earning line's wording (`storefront_text.cart_earn_line`), pre-filled with the stored text. */
export function DesignStorefrontTextSection() {
  const { register, formState } = useFormContext<Design>();
  const id = 'design-storefront-cart-earn-line';
  const invalid = !!formState.errors.storefront_text?.cart_earn_line;

  return (
    <Card>
      <CardHeader>
        <CardTitle>{STOREFRONT_TEXT_SECTION_TITLE()}</CardTitle>
        <CardDescription>{STOREFRONT_TEXT_SECTION_DESCRIPTION()}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <Field data-invalid={invalid}>
          <FieldLabel htmlFor={id}>{STOREFRONT_TEXT_CART_EARN_LINE_LABEL()}</FieldLabel>
          <FieldContent>
            <Input id={id} aria-invalid={invalid} {...register('storefront_text.cart_earn_line')} />
            <FieldDescription>{STOREFRONT_TEXT_CART_EARN_LINE_DESCRIPTION()}</FieldDescription>
            {invalid && <FieldError>{STOREFRONT_TEXT_REQUIRED_ERROR()}</FieldError>}
          </FieldContent>
        </Field>
      </CardContent>
    </Card>
  );
}
