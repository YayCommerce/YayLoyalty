import { ReactNode } from 'react';

/**
 * One label + control row for a built-in's in-place config form. A plain
 * grid, not components/ui/field.tsx: its conditional `has-[...]:items-start`
 * fought the fixed label column and produced uneven row gaps.
 */
export function ConfigField({
  label,
  description,
  children,
}: {
  label: string;
  description?: string;
  children: ReactNode;
}) {
  return (
    <div className="grid grid-cols-[14rem_1fr] items-start gap-x-5">
      <span className="pt-2 text-sm font-medium">{label}</span>
      <div className="flex flex-col gap-1.5">
        {children}
        {description && <p className="text-muted-foreground text-xs">{description}</p>}
      </div>
    </div>
  );
}
