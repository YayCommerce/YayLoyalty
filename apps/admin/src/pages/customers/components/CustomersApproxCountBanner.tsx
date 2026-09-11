import { Button } from '@/components/ui/button';

/** At-scale (2f) approximate match count, with an explicit "Count now" escape hatch. */
export function CustomersApproxCountBanner({
  headline,
  note,
  actionLabel,
}: {
  headline: string;
  note: string;
  actionLabel: string;
}) {
  return (
    <div className="bg-muted/40 flex flex-wrap items-center gap-3 rounded-md border px-3 py-2 text-xs">
      <span>{headline}</span>
      <span className="text-muted-foreground">{note}</span>
      <Button variant="link" size="sm">
        {actionLabel}
      </Button>
    </div>
  );
}
