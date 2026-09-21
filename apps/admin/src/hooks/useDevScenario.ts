import { useSearchParams } from 'react-router';

/**
 * Dev-only `?scenario=` read from the hash route — the fixture switch the
 * dashboard and customer drawer pages forward to their endpoints. This hook
 * is the ONLY place the production gate lives: it returns `undefined` outside
 * the Vite dev server, so no request builder needs its own `DEV` check. The
 * server registers the arg only under `WP_DEBUG` regardless.
 */
export function useDevScenario(): string | undefined {
  const [searchParams] = useSearchParams();

  return import.meta.env.DEV ? (searchParams.get('scenario') ?? undefined) : undefined;
}
