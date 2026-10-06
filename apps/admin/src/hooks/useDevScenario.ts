import { useSearchParams } from 'react-router';

/**
 * Dev-only `?scenario=` fixture switch, which the dashboard forwards to its endpoint.
 * This hook is the only production gate: it returns `undefined` outside the Vite dev server,
 * so request builders need no `DEV` check of their own.
 */
export function useDevScenario(): string | undefined {
  const [searchParams] = useSearchParams();

  return import.meta.env.DEV ? (searchParams.get('scenario') ?? undefined) : undefined;
}
