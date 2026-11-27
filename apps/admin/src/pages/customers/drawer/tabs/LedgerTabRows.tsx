import type { ComponentType } from 'react';
import { GiftIcon, HourglassIcon, SlidersHorizontalIcon, TrendingUpIcon, Undo2Icon } from 'lucide-react';

import type { LedgerType } from '@/lib/schema/ledger.schema';
import {
  balanceAfter,
  EMPTY_FILTERED_BODY,
  EMPTY_FILTERED_TITLE,
  EMPTY_LEDGER_TITLE,
  emptyLedgerBody,
} from '../customer-drawer.copy';
import type { LedgerRow } from '../customer-drawer.ledger-rows';
import { DrawerPointsBadge } from './DrawerPointsBadge';
import { DrawerTimeline } from './DrawerTimeline';

const TYPE_ICONS: Record<LedgerType, ComponentType<{ className?: string }>> = {
  earn: TrendingUpIcon,
  redeem: GiftIcon,
  refund_earn: Undo2Icon,
  refund_redeem: Undo2Icon,
  expire: HourglassIcon,
  adjust: SlidersHorizontalIcon,
  correction: SlidersHorizontalIcon,
};

/** The ledger page as a timeline: each entry with its type icon, sub-lines, balance after, and signed points. */
export function LedgerTabRows({
  rows,
  isLoading,
  isFiltered,
  customerFirstName,
}: {
  rows: LedgerRow[];
  isLoading: boolean;
  isFiltered: boolean;
  customerFirstName: string;
}) {
  return (
    <DrawerTimeline
      isLoading={isLoading}
      emptyTitle={isFiltered ? EMPTY_FILTERED_TITLE() : EMPTY_LEDGER_TITLE()}
      emptyBody={isFiltered ? EMPTY_FILTERED_BODY() : emptyLedgerBody(customerFirstName)}
      items={rows.map((row, index) => ({
        key: index,
        date: row.date,
        icon: TYPE_ICONS[row.type],
        title: row.description,
        subLines: [row.subLine, balanceAfter(row.balance)],
        trailing: <DrawerPointsBadge points={Number(row.points)} />,
        isMarked: row.isCause,
      }))}
    />
  );
}
