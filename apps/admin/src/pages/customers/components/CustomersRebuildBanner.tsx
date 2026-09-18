import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';

/**
 * Balance-rebuild banner, static demo copy behind the "Simulate rebuild"
 * toggle. Grey, not an error colour: progress, not failure. A plain div
 * bar: components/ui/custom/progress imports a package not in package.json.
 */
export function CustomersRebuildBanner() {
  return (
    <div className="border-muted-foreground/40 bg-muted/40 flex flex-col gap-3 rounded-md border border-l-4 p-4">
      <div className="flex flex-col gap-1">
        <span className="text-sm font-semibold">
          {__('Balances are being rebuilt — 1,240 of 2,431 checked', 'yayloyalty')}
        </span>
        <span className="text-muted-foreground text-xs">
          {__(
            'Started 11:02 by Sarah Chen from Tools. The figures below are as they were at 11:02 and will not update until it finishes, in about two minutes. 3 balances corrected so far.',
            'yayloyalty',
          )}
        </span>
      </div>
      <div
        className="bg-background h-2 w-full overflow-hidden rounded-full border"
        role="progressbar"
        aria-valuenow={51}
      >
        <div className="bg-muted-foreground h-full rounded-full" style={{ width: '51%' }} />
      </div>
      <div className="flex flex-wrap items-center gap-4 text-xs">
        <Button variant="link" size="sm">
          {__('Watch the log', 'yayloyalty')}
        </Button>
        <Button variant="link" size="sm">
          {__('Stop the rebuild', 'yayloyalty')}
        </Button>
        <span className="text-muted-foreground ml-auto">
          {__('Stopping is safe: it never writes a partial balance, only whole customers.', 'yayloyalty')}
        </span>
      </div>
    </div>
  );
}
