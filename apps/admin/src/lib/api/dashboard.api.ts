import { api } from '@/lib/api/api';
import {
  dashboardResponseSchema,
  dismissDashboardNoticeParamsSchema,
  type DashboardRange,
  type DashboardResponse,
  type DismissDashboardNoticeParams,
} from '@/lib/schema/dashboard.schema';

/**
 * `GET /dashboard` — the landing page's single payload, `.parse()`d at the
 * boundary like every other response (see customer.api.ts).
 *
 * `scenario` is a dev-only switch: the server registers the argument only
 * under WP_DEBUG, and this client forwards it only on the Vite dev server
 * (`import.meta.env.DEV`), so a built bundle never sends it. Scenario review
 * therefore happens on the dev server; production builds are verified on
 * real data (plan decision).
 */
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

/** `POST /dashboard/notices/dismiss` — per-user; the server takes the user from the session. */
export async function dismissDashboardNotice(
  params: DismissDashboardNoticeParams,
): Promise<DismissDashboardNoticeParams> {
  return dismissDashboardNoticeParamsSchema.parse(await api.post('dashboard/notices/dismiss', { json: params }).json());
}
