import type { ReactElement } from 'react';
import type { UseQueryResult } from '@tanstack/react-query';

import { isNotFoundError } from '@/lib/api/api';
import { ErrorState } from '@/components/ui/custom/error-state';
import NotFoundPage from '@/pages/404';
import PageLayout from './PageLayout';

/** The skeleton, 404 or retryable error a page's query shows until it holds data. */
export function resolveQueryLoad<T>(
  query: UseQueryResult<T>,
  screens: { skeleton: ReactElement; title: string; errorMessage: string; className?: string },
): { screen: ReactElement } | { data: T } {
  if (query.isPending) {
    return { screen: screens.skeleton };
  }

  if (query.isError) {
    if (isNotFoundError(query.error)) {
      return { screen: <NotFoundPage /> };
    }

    return {
      screen: (
        <PageLayout className={screens.className} title={screens.title}>
          <ErrorState message={screens.errorMessage} onRetry={() => query.refetch()} />
        </PageLayout>
      ),
    };
  }

  return { data: query.data };
}
