import type { ReactNode } from 'react';

/** One block of the reward modal: a title with a short accent bar, then its fields. */
export function RewardModalSection({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="flex flex-col gap-4 py-5 first:pt-0 last:pb-0">
      <h3 className="m-0 flex items-center gap-2 text-base font-semibold">
        <span className="bg-foreground h-4 w-1 rounded-full" aria-hidden />
        {title}
      </h3>
      {children}
    </section>
  );
}
