import { useRef } from 'react';
import { useDebounceFn } from 'rooks';

/**
 * Commits a trimmed search value: an empty one at once, any other after a 300ms debounce.
 *
 * rooks' `useDebounceFn` has no `.cancel()`, so a generation counter stands in for it.
 * A pending commit applies only if nothing bumped the counter since it was scheduled.
 * Callers with other filters must call `cancelPending()` when those change,
 * or a search commit still in flight overwrites them.
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
