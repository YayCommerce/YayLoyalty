import { useCallback, useContext, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';

import type { NavigateOptions, Router } from './create-hash-router';
import type { Location } from './hash-history';
import type { RouteMatch } from './match-routes';
import { RouteContext, RouteErrorContext, RouterContext } from './router-provider';

/** A path, or its parts. A relative pathname resolves against the current route; `..` is the parent route. */
export type To = string | { pathname?: string; search?: string };

function useRouter(): { router: Router; location: Location } {
  const value = useContext(RouterContext);
  if (!value) throw new Error('Router hooks must be used inside <RouterProvider>');
  return value;
}

/** `to` as an absolute pathname plus search, from the route at `level` of `matches`. */
function resolvePath(to: To, currentPathname: string, matches: RouteMatch[], level: number): string {
  const q = typeof to === 'string' ? to.indexOf('?') : -1;
  const parts =
    typeof to === 'string' ? { pathname: q === -1 ? to : to.slice(0, q), search: q === -1 ? '' : to.slice(q) } : to;
  const pathname = parts.pathname ?? currentPathname;
  let absolute = pathname;
  if (pathname === '..') absolute = matches[level - 1]?.pathname ?? '/';
  else if (!pathname.startsWith('/')) absolute = `${(matches[level]?.pathname ?? '/').replace(/\/$/, '')}/${pathname}`;
  return `${absolute}${parts.search ?? ''}`;
}

/** Turns `to` into an absolute pathname plus search, for an href. */
export function useResolvedPath(to: To): string {
  const { location } = useRouter();
  const { matches, level } = useContext(RouteContext);
  return resolvePath(to, location.pathname, matches, level);
}

export function useNavigate() {
  const { router, location } = useRouter();
  const { matches, level } = useContext(RouteContext);
  return useCallback(
    (to: To, options?: NavigateOptions) => router.navigate(resolvePath(to, location.pathname, matches, level), options),
    [router, location.pathname, matches, level],
  );
}

export function useLocation(): Location {
  return useRouter().location;
}

export function useParams(): Record<string, string | undefined> {
  const { matches, level } = useContext(RouteContext);
  return matches[level]?.params ?? {};
}

export function useSearchParams(): readonly [URLSearchParams] {
  const { search } = useLocation();
  return useMemo(() => [new URLSearchParams(search)] as const, [search]);
}

/** The error the nearest route `errorElement` caught. */
export function useRouteError(): unknown {
  return useContext(RouteErrorContext);
}

export type Blocker =
  | { state: 'unblocked'; proceed?: undefined; reset?: undefined }
  | { state: 'blocked'; proceed: () => void; reset: () => void };

/** Stops a navigation when `shouldBlock` says so, until the caller proceeds with it or resets. */
export function useBlocker(shouldBlock: (args: { nextLocation: Location }) => boolean): Blocker {
  const { router } = useRouter();
  const shouldBlockRef = useRef(shouldBlock);
  useLayoutEffect(() => {
    shouldBlockRef.current = shouldBlock;
  });
  const [pending, setPending] = useState<(() => void) | null>(null);

  useEffect(
    () =>
      router.addBlocker({
        shouldBlock: (nextLocation) => shouldBlockRef.current({ nextLocation }),
        block: (proceed) => setPending(() => proceed),
      }),
    [router],
  );

  return useMemo<Blocker>(
    () =>
      pending
        ? {
            state: 'blocked',
            proceed: () => {
              setPending(null);
              pending();
            },
            reset: () => setPending(null),
          }
        : { state: 'unblocked' },
    [pending],
  );
}
