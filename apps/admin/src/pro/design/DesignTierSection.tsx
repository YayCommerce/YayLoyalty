import { PlusIcon } from 'lucide-react';
import { useFieldArray, useFormContext } from 'react-hook-form';

import { designBadgeSchema, type Design } from '@/lib/schema/design.schema';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { FieldDescription } from '@/components/ui/field';
import {
  TIER_ADD_BADGE_BUTTON_LABEL,
  TIER_BADGE_TIER_ID_DESCRIPTION,
  TIER_EMPTY_DESCRIPTION,
  TIER_EMPTY_TITLE,
  TIER_MAX_BADGES_REACHED,
  TIER_SECTION_DESCRIPTION,
  TIER_SECTION_TITLE,
} from '@/pages/settings/design-settings.copy';
import { DesignTierBadgeRow } from './DesignTierBadgeRow';

/** Matches DesignCoercion::coerce_badge_list()'s server-side cap. */
const MAX_BADGES = 20;

const EMPTY_BADGE: Design['tier']['badges'][number] = {
  tier_id: '',
  color: '#c8c8c8',
  icon: 'ring',
  label: '',
};

/**
 * Pro. `useFieldArray` over `tier.badges` — add/remove, capped at 20 to
 * match the server. `tier_id` is a merchant-entered slug, not a foreign
 * key: no Tiers entity exists yet (`customers.tier_id` is always NULL).
 * When Tiers ships, a reconciliation migration is required (phase 04 Risk
 * Assessment); building Tiers is out of scope here.
 */
export function DesignTierSection() {
  const { control } = useFormContext<Design>();
  const { fields, append, remove } = useFieldArray({ control, name: 'tier.badges' });
  const atCap = fields.length >= MAX_BADGES;

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
              <DesignTierBadgeRow key={field.id} index={index} onRemove={() => remove(index)} />
            ))}
          </div>
        )}

        <div className="flex flex-col gap-1.5">
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="w-fit"
            disabled={atCap}
            onClick={() => append(designBadgeSchema.parse(EMPTY_BADGE))}
          >
            <PlusIcon className="size-4" />
            {TIER_ADD_BADGE_BUTTON_LABEL()}
          </Button>
          {atCap && <span className="text-muted-foreground text-xs">{TIER_MAX_BADGES_REACHED()}</span>}
        </div>

        {/* Moved out of the per-row card (phase 03/04's earlier layout) — one disclaimer for
            the whole list reads better than repeating it on every badge. */}
        <FieldDescription>{TIER_BADGE_TIER_ID_DESCRIPTION()}</FieldDescription>
      </CardContent>
    </Card>
  );
}
