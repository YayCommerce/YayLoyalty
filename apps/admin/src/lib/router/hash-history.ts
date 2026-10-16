/**
 * The URL hash as the app's location: `admin.php?page=yayloyalty#/customers/5?group=vip`.
 * Each entry's `history.state` holds the caller's state and an index, so a Back press that a
 * blocker refuses can be undone with `history.go()`.
 */

export type Location = { pathname: string; search: string; state: unknown; key: string };

type EntryState = { usr: unknown; idx: number; key: string } | null;

function entryState(): EntryState {
  const state = window.history.state as EntryState;
  return state && typeof state.idx === 'number' ? state : null;
}

function randomKey(): string {
  return Math.random().toString(36).slice(2, 10);
}

/** The location the hash names now; `/` for an empty hash. */
export function readLocation(): Location {
  const hash = window.location.hash.replace(/^#/, '') || '/';
  const q = hash.indexOf('?');
  const pathname = q === -1 ? hash : hash.slice(0, q);
  const entry = entryState();
  return {
    pathname: pathname.startsWith('/') ? pathname : `/${pathname}`,
    search: q === -1 ? '' : hash.slice(q),
    state: entry?.usr ?? null,
    key: entry?.key ?? 'default',
  };
}

/** The current entry's index, or null for an entry the router never wrote (a typed or pasted URL). */
export function readIndex(): number | null {
  return entryState()?.idx ?? null;
}

/** Gives the current entry `idx`, keeping its state. */
export function stampIndex(idx: number): void {
  window.history.replaceState({ usr: entryState()?.usr ?? null, idx, key: randomKey() }, '');
}

/** Writes `path` (pathname plus search) as entry `idx`: a new entry, or over the current one. */
export function writeLocation(path: string, state: unknown, idx: number, replace: boolean): void {
  const url = `${window.location.pathname}${window.location.search}#${path}`;
  const entry = { usr: state ?? null, idx, key: randomKey() };
  if (replace) window.history.replaceState(entry, '', url);
  else window.history.pushState(entry, '', url);
}
