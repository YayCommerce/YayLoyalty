import { ChevronLeft } from 'lucide-react';
import { __ } from '@wordpress/i18n';

/** The `‹ Customers` back link, shown only below `sm`, where the sheet fills the screen. */
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
