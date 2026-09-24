import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { getEmails, postEmails } from '@/lib/api/emails.api';
import type { EmailsFormValues } from '@/lib/schema/emails.schema';

export const EMAILS_QUERIES = {
  main: queryOptions({
    queryKey: ['emails', 'main'],
    queryFn: async () => {
      const emails = await getEmails();
      window.yayLoyaltyAdmin.emails = emails;
      return emails;
    },
    initialData: () => window.yayLoyaltyAdmin.emails,
    staleTime: Infinity,
    // POST is a whole blob, not a patch; a tab left open would otherwise clobber a
    // second admin's save without either noticing. This narrows, not closes, that window.
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
    mutationFn: (data: EmailsFormValues) => postEmails(data),
    onSuccess: (emails) => {
      queryClient.setQueryData(EMAILS_QUERIES.main.queryKey, emails);
    },
  });
}
