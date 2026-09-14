import { __ } from '@wordpress/i18n';

import { CAP, Can } from '@/lib/access';
import { Button } from '@/components/ui/button';

/**
 * Split out of `CustomersToolbar` so it can sit in its own header row — see
 * `CustomersFilterBar.tsx` for why (a `ml-auto` button was wrapping onto its
 * own line alongside 3-4 filter controls on a narrower admin viewport).
 */
export function CustomersExportButton({ disabled }: { disabled: boolean }) {
  return (
    <Can cap={CAP.VIEW} disable>
      <Button variant="outline" size="sm" disabled={disabled}>
        {__('Export CSV', 'yayloyalty')}
      </Button>
    </Can>
  );
}
