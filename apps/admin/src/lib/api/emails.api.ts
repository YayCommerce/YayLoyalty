import { api } from '@/lib/api/api';
import { EmailsFormValues, EmailsResponse } from '@/lib/schema/emails.schema';

export function getEmails() {
  return api.get('emails').json<EmailsResponse>();
}

export function postEmails(data: EmailsFormValues) {
  return api.post('emails', { json: data }).json<EmailsResponse>();
}
