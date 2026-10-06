import { api } from '@/lib/api/api';
import { dashboardProResponseSchema, type DashboardProResponse } from '@/lib/schema/dashboard-pro.schema';
import type { DashboardRange } from '@/lib/schema/dashboard.schema';

/** `GET /dashboard/pro` — Pro build only. */
export async function getDashboardPro(params: { range: DashboardRange }): Promise<DashboardProResponse> {
  const searchParams: Record<string, string> = { range: String(params.range) };

  return dashboardProResponseSchema.parse(await api.get('dashboard/pro', { searchParams }).json());
}
