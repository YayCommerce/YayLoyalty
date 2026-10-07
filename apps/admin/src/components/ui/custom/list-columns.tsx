import { cn } from '@/lib/utils';

/**
 * The two lists an archive or delete dialog shows: what survives on the left,
 * what ends on the right. A `nothing` right column stays visible and muted,
 * because the empty column is the reassurance.
 */
export function ListColumns({
  leftTitle,
  leftLines,
  rightTitle,
  right,
}: {
  leftTitle: string;
  leftLines: string[];
  rightTitle: string;
  right: { lines: string[]; nothing?: boolean };
}) {
  return (
    <div className="grid gap-3 sm:grid-cols-2">
      <ListColumn title={leftTitle} lines={leftLines} />
      <ListColumn
        title={rightTitle}
        lines={right.lines}
        className={cn(!right.nothing && 'bg-muted border-foreground/40')}
        muted={right.nothing}
      />
    </div>
  );
}

function ListColumn({
  title,
  lines,
  className,
  muted,
}: {
  title: string;
  lines: string[];
  className?: string;
  muted?: boolean;
}) {
  return (
    <div className={cn('flex flex-col gap-3 rounded-md border p-4', className)}>
      <span className="text-xs font-semibold tracking-wide uppercase">{title}</span>
      <ul className={cn('flex flex-col gap-2 text-sm', muted && 'text-muted-foreground')}>
        {lines.map((line) => (
          <li key={line}>{line}</li>
        ))}
      </ul>
    </div>
  );
}
