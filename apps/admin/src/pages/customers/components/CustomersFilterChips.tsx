import { X } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { CAP, Can } from '@/lib/access';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/**
 * Active-filter chip row (artboard 4a): the typed search plus one removable
 * chip per applied filter, and a "Clear all" link. Remove/clear are no-ops —
 * UI only, real filtering lands with the list endpoint.
 */
export function CustomersFilterChips({
  searchValue,
  chips,
  exportDisabled,
}: {
  searchValue: string;
  chips: string[];
  exportDisabled: boolean;
}) {
  return (
    <div className="flex flex-wrap items-center gap-2">
      <Input className="w-60" defaultValue={searchValue} />
      {chips.map((chip) => (
        <Badge key={chip} variant="outline" className="h-8 gap-1.5 rounded-md px-2.5 text-sm font-normal">
          {chip}
          <X className="size-3.5" />
        </Badge>
      ))}
      <Button variant="link" size="sm">
        {__('Clear all', 'yayloyalty')}
      </Button>
      <Can cap={CAP.VIEW} disable>
        <Button variant="outline" size="sm" className="ml-auto" disabled={exportDisabled}>
          {__('Export CSV', 'yayloyalty')}
        </Button>
      </Can>
    </div>
  );
}
