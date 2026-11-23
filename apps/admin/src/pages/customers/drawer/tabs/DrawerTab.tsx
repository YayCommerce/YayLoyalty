import { formatCount } from '@/lib/helpers/points.helper';
import { TabsTrigger } from '@/components/ui/tabs';
import { ProGate } from '@/components/pro/ProGate';

/**
 * One tab, with its count beside the label in a smaller, lighter weight.
 * The classes replace the shared TabsTrigger's pill look with an underline at the call site.
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
    <TabsTrigger
      value={value}
      // `-mb-px` pulls the active tab's 2px underline over the list's 1px rule, so they do not stack.
      // That needs the list to stay `overflow: visible`.
      className="data-[state=active]:border-primary data-[state=active]:text-primary group -mb-px h-auto flex-none rounded-none border-0 border-b-2 border-transparent px-0 py-2.5 font-medium data-[state=active]:bg-transparent data-[state=active]:shadow-none"
    >
      {label}

      {proGated ? (
        <ProGate inline />
      ) : (
        <span className="text-muted-foreground group-data-[state=active]:text-primary text-xs font-normal tabular-nums">
          {formatCount(count)}
        </span>
      )}
    </TabsTrigger>
  );
}
