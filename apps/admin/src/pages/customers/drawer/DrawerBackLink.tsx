import { ChevronLeft } from 'lucide-react';
import { __ } from '@wordpress/i18n';

/** 5c's `‹ Customers` back link — visible only below the `sm` breakpoint,
 *  where the Sheet becomes a full-screen view. Shared by the normal header
 *  and the error/not-found branch (see CustomerDrawer.tsx), which both need
 *  it but render otherwise-different content around it. */
export function DrawerBackLink({ onClick }: { onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="text-muted-foreground hover:text-foreground -ml-1 flex w-fit items-center gap-1 text-sm sm:hidden"
    >
      <ChevronLeft className="size-4" />
      {__('Customers', 'yayloyalty')}
    </button>
  );
}
