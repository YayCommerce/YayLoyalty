import {
  Component,
  createContext,
  useContext,
  useLayoutEffect,
  useMemo,
  useSyncExternalStore,
  type ReactNode,
} from 'react';

import type { Router } from './create-hash-router';
import type { Location } from './hash-history';
import { matchRoutes, type RouteMatch } from './match-routes';

export const RouterContext = createContext<{ router: Router; location: Location } | null>(null);
/** The matched chain and which level of it the reading component renders in; level -1 outside any route. */
export const RouteContext = createContext<{ matches: RouteMatch[]; level: number }>({ matches: [], level: -1 });
export const RouteErrorContext = createContext<unknown>(undefined);

class RouteErrorBoundary extends Component<
  { fallback: ReactNode; resetKey: string; children: ReactNode },
  { error: unknown; resetKey: string }
> {
  state = { error: undefined as unknown, resetKey: this.props.resetKey };

  static getDerivedStateFromError(error: unknown) {
    return { error };
  }

  // A new location clears the error, so navigating away from the error page renders the next route.
  static getDerivedStateFromProps(props: { resetKey: string }, state: { resetKey: string }) {
    return props.resetKey === state.resetKey ? null : { error: undefined, resetKey: props.resetKey };
  }

  render() {
    if (this.state.error === undefined) return this.props.children;
    return <RouteErrorContext.Provider value={this.state.error}>{this.props.fallback}</RouteErrorContext.Provider>;
  }
}

function renderLevel(matches: RouteMatch[], level: number, resetKey: string): ReactNode {
  const match = matches[level];
  if (!match) return null;
  const { element, errorElement } = match.route;
  const value = { matches, level };
  const content = <RouteContext.Provider value={value}>{element ?? <Outlet />}</RouteContext.Provider>;
  if (!errorElement) return content;
  return (
    <RouteErrorBoundary
      resetKey={resetKey}
      fallback={<RouteContext.Provider value={value}>{errorElement}</RouteContext.Provider>}
    >
      {content}
    </RouteErrorBoundary>
  );
}

/** Renders the child route of the route it sits in, or nothing when there is none. */
export function Outlet() {
  const { matches, level } = useContext(RouteContext);
  const router = useContext(RouterContext);
  return renderLevel(matches, level + 1, router?.location.pathname ?? '');
}

export function RouterProvider({ router }: { router: Router }) {
  const location = useSyncExternalStore(router.subscribe, router.getLocation);
  const matches = useMemo(() => matchRoutes(router.routes, location.pathname) ?? [], [router, location.pathname]);
  const redirectTo = matches[matches.length - 1]?.route.redirect;

  useLayoutEffect(() => {
    if (redirectTo) router.navigate(redirectTo, { replace: true }, true);
  }, [router, redirectTo]);

  const value = useMemo(() => ({ router, location }), [router, location]);
  if (redirectTo) return null;
  return <RouterContext.Provider value={value}>{renderLevel(matches, 0, location.pathname)}</RouterContext.Provider>;
}
