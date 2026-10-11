import { __ } from '@wordpress/i18n';

import { ROUNDING, RoundingMode } from '@/lib/points-math/points-math';
import type { PurchaseConfig } from '@/lib/schema/campaign.schema';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';

type PurchaseBase = NonNullable<PurchaseConfig['base']>;

/** A purchase campaign's base amount: before or after discounts. Shared by the built-in card and the Pro editor. */
export function BaseAmountSegmented({
  value,
  onValueChange,
}: {
  value: PurchaseBase;
  onValueChange: (value: PurchaseBase) => void;
}) {
  return (
    <Segmented value={value} onValueChange={(next) => next && onValueChange(next as PurchaseBase)}>
      <SegmentedItem value="after_discounts">{__('After discounts', 'yayloyalty')}</SegmentedItem>
      <SegmentedItem value="before_discounts">{__('Before discounts', 'yayloyalty')}</SegmentedItem>
    </Segmented>
  );
}

/** How a purchase campaign turns its result into whole points. */
export function RoundingSegmented({
  value,
  onValueChange,
}: {
  value: RoundingMode;
  onValueChange: (value: RoundingMode) => void;
}) {
  return (
    <Segmented value={value} onValueChange={(next) => next && onValueChange(next as RoundingMode)}>
      <SegmentedItem value={ROUNDING.DOWN}>{__('Down', 'yayloyalty')}</SegmentedItem>
      <SegmentedItem value={ROUNDING.NEAREST}>{__('Nearest', 'yayloyalty')}</SegmentedItem>
      <SegmentedItem value={ROUNDING.UP}>{__('Up', 'yayloyalty')}</SegmentedItem>
    </Segmented>
  );
}
