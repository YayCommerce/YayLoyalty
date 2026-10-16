import type { ReactNode } from 'react';

/** A route, in react-router's object shape. `redirect` replaces the location when the route is the deepest match. */
export type RouteObject = {
  path?: string;
  index?: boolean;
  element?: ReactNode;
  errorElement?: ReactNode;
  redirect?: string;
  children?: RouteObject[];
};

export type RouteMatch = {
  route: RouteObject;
  /** The part of the pathname this route and its parents matched, e.g. `/customers` for the drawer's parent. */
  pathname: string;
  params: Record<string, string>;
};

type Branch = { matches: RouteMatch[]; score: number };

const STATIC = 10;
const DYNAMIC = 3;
const INDEX = 2;
const SPLAT = 1;

function split(path: string): string[] {
  return path.split('/').filter(Boolean);
}

function join(base: string, segments: string[]): string {
  return `/${[...split(base), ...segments].join('/')}`;
}

function matchBranches(
  routes: RouteObject[],
  segments: string[],
  base: string,
  params: Record<string, string>,
): Branch[] {
  const branches: Branch[] = [];
  for (const route of routes) {
    if (route.index) {
      if (segments.length === 0) branches.push({ matches: [{ route, pathname: base, params }], score: INDEX });
      continue;
    }

    const own = { ...params };
    let score = 0;
    let used = 0;
    let ok = true;
    for (const seg of split(route.path ?? '')) {
      if (seg === '*') {
        own['*'] = segments.slice(used).join('/');
        used = segments.length;
        score += SPLAT;
      } else if (seg.startsWith(':')) {
        if (used >= segments.length) ok = false;
        else own[seg.slice(1)] = decodeURIComponent(segments[used++]);
        score += DYNAMIC;
      } else if (segments[used]?.toLowerCase() === seg.toLowerCase()) {
        used++;
        score += STATIC;
      } else {
        ok = false;
      }
      if (!ok) break;
    }
    if (!ok) continue;

    const match: RouteMatch = { route, pathname: join(base, segments.slice(0, used)), params: own };
    const rest = segments.slice(used);
    if (rest.length === 0) branches.push({ matches: [match], score });
    for (const child of matchBranches(route.children ?? [], rest, match.pathname, own)) {
      branches.push({ matches: [match, ...child.matches], score: score + child.score });
    }
  }
  return branches;
}

/** The best-ranked chain of routes, parent first, that matches all of `pathname`, or null. */
export function matchRoutes(routes: RouteObject[], pathname: string): RouteMatch[] | null {
  const branches = matchBranches(routes, split(pathname), '/', {});
  if (branches.length === 0) return null;
  return branches.reduce((best, b) => (b.score > best.score ? b : best)).matches;
}
