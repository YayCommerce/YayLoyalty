import { api } from '@/lib/api/api';
import {
  dashboardResponseSchema,
  dismissDashboardNoticeParamsSchema,
  type DashboardRange,
  type DashboardResponse,
  type DismissDashboardNoticeParams,
} from '@/lib/schema/dashboard.schema';

/** GET /dashboard. `scenario` is forwarded only on the dev server, so a built bundle never sends it. */
export async function getDashboard(params: {
  range: DashboardRange;
  refresh?: boolean;
  scenario?: string;
}): Promise<DashboardResponse> {
  const searchParams: Record<string, string> = { range: String(params.range) };

  if (params.refresh) searchParams.refresh = '1';
  if (import.meta.env.DEV && params.scenario) searchParams.scenario = params.scenario;

  return dashboardResponseSchema.parse(await api.get('dashboard', { searchParams }).json());
}

/** POST /dashboard/notices/dismiss; the server takes the user from the session. */
export async function dismissDashboardNotice(
  params: DismissDashboardNoticeParams,
): Promise<DismissDashboardNoticeParams> {
  return dismissDashboardNoticeParamsSchema.parse(await api.post('dashboard/notices/dismiss', { json: params }).json());
}
