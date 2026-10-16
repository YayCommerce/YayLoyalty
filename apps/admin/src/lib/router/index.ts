/**
 * The admin's hash router: the slice of react-router's API the app uses (hash URLs, nested
 * routes, `..` navigation, location state, a navigation blocker and a route error page),
 * in a few KB instead of react-router's 90.
 */
export { createHashRouter, type Router } from './create-hash-router';
export type { Location } from './hash-history';
export type { RouteObject } from './match-routes';
export { Outlet, RouterProvider } from './router-provider';
export {
  useBlocker,
  useLocation,
  useNavigate,
  useParams,
  useRouteError,
  useSearchParams,
  type Blocker,
  type To,
} from './router-hooks';
export { Link, NavLink } from './router-link';
