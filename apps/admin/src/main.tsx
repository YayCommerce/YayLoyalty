import './main.css';

import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { domAnimation, LazyMotion } from 'motion/react';
import { createRoot } from 'react-dom/client';
import { RouterProvider } from 'react-router';

import { getManagerRouter } from '@/router';

import { registerPremium } from '@/premium';

/*
 * The single entry point for premium SPA code.
 *
 * STATIC import, called behind a build constant. `__IS_PREMIUM__` is
 * substituted inline by Vite, so in a Lite build this call is eliminated,
 * `@/premium` loses its only importer, and Rollup drops the whole subtree.
 *
 * Deliberately not a dynamic import(): that would emit a separate chunk, and
 * validate-build.php greps main.js for the sentinel — premium code in a
 * side-chunk would slip past the check entirely.
 *
 * Never deep-import from `@/premium/...` elsewhere; a second importer would
 * survive the Lite build.
 */
if (__IS_PREMIUM__) {
  registerPremium();
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
