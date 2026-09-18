import type { ReactNode } from 'react';
import { SlidersHorizontal } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

/**
 * 5c callout 2 — the ledger tab's three filter controls collapse into one
 * "Filters (N)" trigger below `sm`; the search field is the explicit
 * exception and stays outside, full width (see `LedgerTabFilters.tsx`).
 *
 * Both branches render always; only Tailwind's `sm:` breakpoint decides
 * which one is visible — CSS-only switching, not a JS media-query hook this
 * codebase has no existing pattern for. Unlike the tables' `order-*`/`w-1/2`
 * technique (one set of DOM nodes, CSS-reordered), `children` here mounts
 * TWICE — once inside `PopoverContent`, once in the inline `sm:flex` div —
 * so `Segmented`/`DateRangePicker` exist as two live instances at all
 * times, one `display:none`. Safe (no name collisions, the hidden copy
 * drops out of the tab order and a11y tree) but heavier, and the hidden
 * copy's own nested popover (the date-range calendar) can be left open and
 * orphaned — portalled to `document.body` — if the viewport crosses `sm`
 * while it's open. Edge case, not a phone scenario; noted rather than
 * handled.
 *
 * `Popover`, not `Sheet`: this already lives inside the drawer's own Sheet,
 * and a Sheet-in-a-Sheet is a nested-dialog problem (focus trap fighting
 * focus trap) this app doesn't need to take on for a small filter panel.
 *
 * The label is a trivial, component-local `__()` call — same precedent
 * `DrawerBackLink.tsx` sets — not routed through `customer-drawer.copy.ts`,
 * which is at its 200-line hard cap (see phase 06's Deviations).
 */
export function DrawerFiltersCollapse({
  activeCount,
  disabled,
  children,
}: {
  /** Non-default filter state only — a set type or date range. Search is
   *  visible on its own, so counting it here would double-report it. */
  activeCount: number;
  disabled: boolean;
  children: ReactNode;
}) {
  return (
    <>
      <div className="sm:hidden">
        <Popover>
          <PopoverTrigger asChild>
            <Button variant="outline" size="sm" disabled={disabled}>
              <SlidersHorizontal />
              {sprintf(__('Filters (%s)', 'yayloyalty'), String(activeCount))}
            </Button>
          </PopoverTrigger>
          <PopoverContent align="start" className="flex w-72 flex-col gap-3">
            {children}
          </PopoverContent>
        </Popover>
      </div>
      <div className="hidden items-center gap-2 sm:flex">{children}</div>
    </>
  );
}
