import { formatCount } from '@/lib/helpers/points.helper';
import { TabsTrigger } from '@/components/ui/tabs';
import { ProGate } from '@/components/pro/ProGate';

/**
 * One tab, with its count beside the label in a smaller, lighter weight.
 */
export function DrawerTab({
  value,
  label,
  count,
  proGated = false,
}: {
  value: string;
  label: string;
  count: number;
  /** A Pro badge in place of the count. */
  proGated?: boolean;
}) {
  return (
    <TabsTrigger value={value} className="data-[state=active]:text-foreground group h-7 flex-none px-2.5 font-medium">
      {label}

      {proGated ? (
        <ProGate inline />
      ) : (
        <span className="text-muted-foreground text-xs font-normal tabular-nums">{formatCount(count)}</span>
      )}
    </TabsTrigger>
  );
}
