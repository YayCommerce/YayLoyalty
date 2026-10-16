import { readIndex, readLocation, stampIndex, writeLocation, type Location } from './hash-history';
import type { RouteObject } from './match-routes';

export type NavigateOptions = { replace?: boolean; state?: unknown };

/** Asked before every navigation; `block` gets the function that performs the navigation it stopped. */
type BlockerEntry = { shouldBlock: (next: Location) => boolean; block: (proceed: () => void) => void };

export type Router = ReturnType<typeof createHashRouter>;

function parsePath(path: string, state: unknown): Location {
  const q = path.indexOf('?');
  return {
    pathname: q === -1 ? path : path.slice(0, q),
    search: q === -1 ? '' : path.slice(q),
    state: state ?? null,
    key: '',
  };
}

/**
 * The app's router: the location from the URL hash, navigation, and blockers. A blocked
 * Back or Forward press has already moved the browser, so it is undone with `history.go()`
 * and replayed if the blocker proceeds.
 */
export function createHashRouter(routes: RouteObject[]) {
  let location = readLocation();
  let index = readIndex() ?? 0;
  if (readIndex() === null) stampIndex(0);
  const listeners = new Set<() => void>();
  const blockers = new Set<BlockerEntry>();
  /** How to treat the next popstate: one the router caused by undoing or replaying a blocked pop. */
  let nextPop: 'normal' | 'ignore' | 'accept' = 'normal';

  function commit() {
    location = readLocation();
    listeners.forEach((listener) => listener());
  }

  function isBlocked(next: Location, proceed: () => void): boolean {
    for (const blocker of blockers) {
      if (blocker.shouldBlock(next)) {
        blocker.block(proceed);
        return true;
      }
    }
    return false;
  }

  /** `path` is absolute, pathname plus search. `force` skips the blockers. */
  function navigate(path: string, { replace = false, state }: NavigateOptions = {}, force = false) {
    if (!force && isBlocked(parsePath(path, state), () => navigate(path, { replace, state }, true))) return;
    if (!replace) index++;
    writeLocation(path, state, index, replace);
    commit();
  }

  function onPopState() {
    const mode = nextPop;
    nextPop = 'normal';
    if (mode === 'ignore') return;

    const stamped = readIndex();
    const nextIndex = stamped ?? index + 1;
    if (stamped === null) stampIndex(nextIndex);
    const delta = nextIndex - index;

    const proceed = () => {
      nextPop = 'accept';
      window.history.go(delta);
    };
    if (mode === 'normal' && delta !== 0 && isBlocked(readLocation(), proceed)) {
      nextPop = 'ignore';
      window.history.go(-delta);
      return;
    }
    index = nextIndex;
    commit();
  }

  window.addEventListener('popstate', onPopState);

  return {
    routes,
    navigate,
    getLocation: () => location,
    subscribe(listener: () => void) {
      listeners.add(listener);
      return () => {
        listeners.delete(listener);
      };
    },
    addBlocker(blocker: BlockerEntry) {
      blockers.add(blocker);
      return () => {
        blockers.delete(blocker);
      };
    },
  };
}
