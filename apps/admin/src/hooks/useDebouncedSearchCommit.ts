import { useRef } from 'react';
import { useDebounceFn } from 'rooks';

/**
 * Extracted from `CustomersPage.tsx`'s original inline block — same policy,
 * now shared by every screen with a debounced search box (the Customers
 * list, the drawer's ledger tab).
 *
 * An EMPTY value (search cleared, or trimmed down to nothing) commits
 * immediately — there's nothing worth waiting out. A NON-EMPTY value
 * debounces 300ms (`maxWait` 800ms) before `onCommit` runs, trimmed.
 *
 * rooks' `useDebounceFn` has no `.cancel()`, so a generation counter does
 * that job: every call — `commit()`, or `cancelPending()` — bumps it first,
 * and a pending debounced commit only applies if nothing else (a later
 * keystroke, a "Clear all", another filter change routed through
 * `cancelPending()`) has happened since it was scheduled. Without this,
 * typing → picking an unrelated filter inside the 300ms window would let
 * the stale search win once the timer fired.
 *
 * Returns `cancelPending()` alongside `commit()` (not just the commit
 * function) because a faithful extraction needs it: `CustomersPage.tsx`'s
 * original code also bumped the generation on every OTHER filter change
 * (Balance range, "Clear all", ...), not only on search input — callers
 * that have other filters must call `cancelPending()` when those change, or
 * a search commit still in flight can silently overwrite them.
 */
export function useDebouncedSearchCommit(onCommit: (value: string) => void) {
  const generation = useRef(0);

  const [debouncedCommit] = useDebounceFn(
    (value: string, expected: number) => {
      if (expected !== generation.current) return;
      onCommit(value);
    },
    300,
    { trailing: true, maxWait: 800 },
  );

  function commit(value: string) {
    const trimmed = value.trim();

    if (trimmed === '') {
      generation.current++;
      onCommit(trimmed);
      return;
    }

    debouncedCommit(trimmed, ++generation.current);
  }

  function cancelPending() {
    generation.current++;
  }

  return { commit, cancelPending };
}
