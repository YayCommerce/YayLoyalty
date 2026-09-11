/** Generic bordered explanation panels — one full-width for 4a, two side-by-side for 4b. */
export function CustomersInfoPanels({ panels }: { panels: { title: string; lines: string[] }[] }) {
  return (
    <div className={panels.length > 1 ? 'grid gap-4 sm:grid-cols-2' : 'flex flex-col gap-4'}>
      {panels.map((panel) => (
        <div key={panel.title} className="bg-muted/30 flex flex-col gap-2 rounded-md border p-4">
          <span className="text-sm font-semibold">{panel.title}</span>
          <div className="text-muted-foreground flex flex-col gap-1.5 text-xs">
            {panel.lines.map((line) => (
              <p key={line}>{line}</p>
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}
