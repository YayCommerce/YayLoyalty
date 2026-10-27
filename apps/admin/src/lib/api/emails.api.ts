import { api } from '@/lib/api/api';
import { EmailsResponse, EmailsUpdate } from '@/lib/schema/emails.schema';

export function getEmails() {
  return api.get('emails').json<EmailsResponse>();
}

export function postEmails(data: EmailsUpdate) {
  return api.post('emails', { json: data }).json<EmailsResponse>();
}
