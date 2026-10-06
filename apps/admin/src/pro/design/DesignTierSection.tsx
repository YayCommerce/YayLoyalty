import { useFieldArray, useFormContext } from 'react-hook-form';

import { type Design } from '@/lib/schema/design.schema';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { FieldDescription } from '@/components/ui/field';
import {
  TIER_BADGE_TIER_ID_DESCRIPTION,
  TIER_EMPTY_DESCRIPTION,
  TIER_EMPTY_TITLE,
  TIER_SECTION_DESCRIPTION,
  TIER_SECTION_TITLE,
} from '@/pages/settings/design-settings.copy';
import { DesignTierBadgeRow } from './DesignTierBadgeRow';

/**
 * Pro. Restyles the badge (label, colour, icon) of each tier the defaults ship with; no add or
 * remove. There is no Tiers entity yet (`customers.tier_id` is always NULL), so the badge set from
 * `DesignSchema::get_default_design()` stays fixed until one exists. `useFieldArray` is used
 * read-only, for its stable `field.id` keys.
 */
export function DesignTierSection() {
  const { control } = useFormContext<Design>();
  const { fields } = useFieldArray({ control, name: 'tier.badges' });

  return (
    <Card>
      <CardHeader>
        <CardTitle>{TIER_SECTION_TITLE()}</CardTitle>
        <CardDescription>{TIER_SECTION_DESCRIPTION()}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {fields.length === 0 ? (
          <Empty className="py-8">
            <EmptyHeader>
              <EmptyTitle>{TIER_EMPTY_TITLE()}</EmptyTitle>
              <EmptyDescription>{TIER_EMPTY_DESCRIPTION()}</EmptyDescription>
            </EmptyHeader>
          </Empty>
        ) : (
          <div className="flex flex-col gap-3">
            {fields.map((field, index) => (
              <DesignTierBadgeRow key={field.id} index={index} />
            ))}
          </div>
        )}

        <FieldDescription>{TIER_BADGE_TIER_ID_DESCRIPTION()}</FieldDescription>
      </CardContent>
    </Card>
  );
}
