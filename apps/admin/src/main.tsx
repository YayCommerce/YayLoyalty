import './main.css';

import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createRoot } from 'react-dom/client';
import { RouterProvider } from 'react-router';

import { registerPro } from '@/pro';
import { getManagerRouter } from '@/router';

/*
 * The one call site @/pro tree-shaking depends on. Reads import.meta.env
 * directly, not IS_PRO_BUILD: a same-file comparison folds most reliably.
 * A static import, not a dynamic one: a side chunk would slip past
 * validate-build.php's scan of main.js.
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
      <RouterProvider router={getManagerRouter()} />
    </QueryClientProvider>
  </React.StrictMode>,
);
