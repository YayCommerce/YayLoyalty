import { useSyncExternalStore, type ReactNode } from 'react';
import { Check, X } from 'lucide-react';

import { cn } from '@/lib/utils';

/**
 * Success and error toasts, top centre, each gone after `DURATION_MS`. `toast` works outside
 * React too (the API client calls it), so the list lives in a module store that `<Toaster />`
 * subscribes to. Mount one `<Toaster />`, in AppLayout.
 */

type ToastType = 'success' | 'error';
type ToastItem = { id: number; type: ToastType; message: ReactNode };

const DURATION_MS = 4000;

let items: ToastItem[] = [];
let nextId = 1;
const listeners = new Set<() => void>();

function setItems(next: ToastItem[]) {
  items = next;
  listeners.forEach((listener) => listener());
}

function dismiss(id: number) {
  setItems(items.filter((item) => item.id !== id));
}

function show(type: ToastType, message: ReactNode) {
  const id = nextId++;
  setItems([...items, { id, type, message }]);
  setTimeout(() => dismiss(id), DURATION_MS);
}

export const toast = {
  success: (message: ReactNode) => show('success', message),
  error: (message: ReactNode) => show('error', message),
};

function subscribe(listener: () => void) {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

const ICONS: Record<ToastType, ReactNode> = {
  success: (
    <span className="bg-success text-success-foreground inline-flex size-4 shrink-0 items-center justify-center rounded-full">
      <Check className="size-2.5 stroke-3" />
    </span>
  ),
  error: (
    <span className="bg-destructive text-destructive-foreground inline-flex size-4 shrink-0 items-center justify-center rounded-full">
      <X className="size-2.5 stroke-3" />
    </span>
  ),
};

export function Toaster() {
  const current = useSyncExternalStore(subscribe, () => items);

  return (
    <section
      aria-live="polite"
      className="pointer-events-none fixed inset-x-0 top-6 z-100000 flex flex-col items-center gap-2 px-4"
    >
      {current.map((item) => (
        <div
          key={item.id}
          role={item.type === 'error' ? 'alert' : 'status'}
          onClick={() => dismiss(item.id)}
          className={cn(
            'bg-popover text-popover-foreground border-border pointer-events-auto flex max-w-md items-center gap-2 rounded-md border px-4 py-3 text-sm shadow-lg',
            'animate-in fade-in slide-in-from-top-2 duration-200',
          )}
        >
          {ICONS[item.type]}
          <span>{item.message}</span>
        </div>
      ))}
    </section>
  );
}
