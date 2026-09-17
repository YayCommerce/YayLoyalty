import { api } from '@/lib/api/api';
import { dashboardProResponseSchema, type DashboardProResponse } from '@/lib/schema/dashboard-pro.schema';
import type { DashboardRange } from '@/lib/schema/dashboard.schema';

/** `GET /dashboard/pro` — Pro build only; `scenario` forwarded on the dev server only, like the Free call. */
export async function getDashboardPro(params: {
  range: DashboardRange;
  scenario?: string;
}): Promise<DashboardProResponse> {
  const searchParams: Record<string, string> = { range: String(params.range) };

  if (import.meta.env.DEV && params.scenario) searchParams.scenario = params.scenario;

  return dashboardProResponseSchema.parse(await api.get('dashboard/pro', { searchParams }).json());
}
