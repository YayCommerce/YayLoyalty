import { useSearchParams } from 'react-router';

/**
 * Dev-only `?scenario=` read from the hash route — mirrors
 * `DashboardPage.tsx`'s own `scenario` read exactly. The server registers
 * the arg only under `WP_DEBUG`; this hook forwards it only on the Vite dev
 * server, so a production build never reads or sends it.
 */
export function useDrawerScenario(): string | undefined {
  const [searchParams] = useSearchParams();

  return import.meta.env.DEV ? (searchParams.get('scenario') ?? undefined) : undefined;
}
