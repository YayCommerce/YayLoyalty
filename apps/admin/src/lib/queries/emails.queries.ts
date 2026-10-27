import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { getEmails, postEmails } from '@/lib/api/emails.api';
import { SETTINGS_QUERIES } from '@/lib/queries/settings.queries';
import type { EmailsUpdate } from '@/lib/schema/emails.schema';

export const EMAILS_QUERIES = {
  main: queryOptions({
    queryKey: ['emails', 'main'],
    // Fetched here, not preloaded: building WooCommerce's mailer is too costly for every admin page.
    queryFn: getEmails,
    staleTime: Infinity,
    // The page posts every id, so a tab left open would clobber a second admin's
    // save without either noticing. This narrows, not closes, that window.
    refetchOnWindowFocus: true,
  }),
};

export function useEmailsQuery() {
  return useQuery(EMAILS_QUERIES.main);
}

export function useSaveEmailsMutation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationKey: ['emails', 'save'],
    mutationFn: (data: EmailsUpdate) => postEmails(data),
    // A focus refetch started before the save would resolve after it and put the old state back.
    onMutate: () => queryClient.cancelQueries({ queryKey: EMAILS_QUERIES.main.queryKey }),
    onSuccess: (emails) => {
      queryClient.setQueryData(EMAILS_QUERIES.main.queryKey, emails);
      // The throttle is a plugin setting too.
      void queryClient.invalidateQueries({ queryKey: SETTINGS_QUERIES.main.queryKey });
    },
  });
}
