import { __ } from '@wordpress/i18n';

import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { ProGate } from '@/components/pro/ProGate';
import { CUSTOMERS_SCENARIO_ORDER, CUSTOMERS_SCENARIOS, type CustomersScenarioId } from '../customers-page.scenarios';

/**
 * DEMO ONLY. Lets you browse the 7 designed states of this screen without a
 * real backend. Delete this file, and the `customers-page.mock-data.ts` /
 * `customers-page.scenarios*.ts` modules it depends on, once the Customers
 * list endpoint lands and the page reads real rows.
 */
export function ScenarioSwitcher({
  value,
  onChange,
}: {
  value: CustomersScenarioId;
  onChange: (id: CustomersScenarioId) => void;
}) {
  return (
    <div className="bg-muted/30 flex flex-wrap items-center gap-2 rounded-md border px-3 py-2">
      <span className="text-muted-foreground text-xs font-medium">{__('Demo state', 'yayloyalty')}</span>
      <Segmented
        size="sm"
        value={value}
        onValueChange={(next) => next && onChange(next as CustomersScenarioId)}
      >
        {CUSTOMERS_SCENARIO_ORDER.map((id) => {
          const scenario = CUSTOMERS_SCENARIOS[id];
          return (
            <SegmentedItem key={id} value={id} className="gap-1.5">
              {scenario.label}
              {scenario.isPro && !__IS_PRO__ && <ProGate inline />}
            </SegmentedItem>
          );
        })}
      </Segmented>
    </div>
  );
}
