import './main.css';

import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { domAnimation, LazyMotion } from 'motion/react';
import { createRoot } from 'react-dom/client';
import { RouterProvider } from 'react-router';

import { registerPro } from '@/pro';
import { getManagerRouter } from '@/router';

/*
 * The single entry point for pro SPA code.
 *
 * STATIC import, called behind a build constant read DIRECTLY here —
 * `import.meta.env.VITE_IS_PRO`, not the `IS_PRO_BUILD` constant everywhere
 * else in the app imports from `@/lib/is-pro-build`. This is the one call
 * site `@/pro` tree-shaking depends on: Vite/Rollup fold a same-file
 * `import.meta.env.VITE_X` comparison into a literal most reliably, so in a
 * Lite build this call is eliminated, `@/pro` loses its only importer, and
 * Rollup drops the whole subtree.
 *
 * Deliberately not a dynamic import(): that would emit a separate chunk, and
 * scripts/validate-build.php scans main.js for the Pro sentinel — pro code
 * in a side-chunk would slip past the check entirely.
 *
 * Never deep-import from `@/pro/...` elsewhere; a second importer would
 * survive the Lite build.
 */
if (import.meta.env.VITE_IS_PRO === 'true') {
  registerPro();
}

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: false,
      staleTime: 5 * 60 * 1000,
    },
  },
});

createRoot(document.getElementById('yayloyalty') as HTMLElement).render(
  <React.StrictMode>
    <QueryClientProvider client={queryClient}>
      <LazyMotion features={domAnimation}>
        <RouterProvider router={getManagerRouter()} />
      </LazyMotion>
    </QueryClientProvider>
  </React.StrictMode>,
);
