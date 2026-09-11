import { Button } from '@/components/ui/button';

/**
 * Balance-rebuild-in-progress banner (4b). Grey with a left accent —
 * deliberately NOT an error colour: this is progress, not failure. A plain
 * bordered block rather than `Alert variant="destructive"`, which is the
 * tempting-but-wrong shortcut here.
 *
 * The progress bar is a plain div, not `@/components/ui/custom/progress`:
 * that file imports `@radix-ui/react-progress`, which isn't in package.json
 * (pre-existing gap, unrelated to this page — flagged separately, not fixed
 * here since adding a dependency is outside a UI-only build).
 */
export function CustomersRebuildBanner({
  title,
  detail,
  percent,
  watchLogLabel,
  stopLabel,
  safetyNote,
}: {
  title: string;
  detail: string;
  percent: number;
  watchLogLabel: string;
  stopLabel: string;
  safetyNote: string;
}) {
  return (
    <div className="border-muted-foreground/40 bg-muted/40 flex flex-col gap-3 rounded-md border-l-4 border p-4">
      <div className="flex flex-col gap-1">
        <span className="text-sm font-semibold">{title}</span>
        <span className="text-muted-foreground text-xs">{detail}</span>
      </div>
      <div className="bg-background h-2 w-full overflow-hidden rounded-full border" role="progressbar" aria-valuenow={percent}>
        <div className="bg-muted-foreground h-full rounded-full" style={{ width: `${percent}%` }} />
      </div>
      <div className="flex flex-wrap items-center gap-4 text-xs">
        <Button variant="link" size="sm">
          {watchLogLabel}
        </Button>
        <Button variant="link" size="sm">
          {stopLabel}
        </Button>
        <span className="text-muted-foreground ml-auto">{safetyNote}</span>
      </div>
    </div>
  );
}
