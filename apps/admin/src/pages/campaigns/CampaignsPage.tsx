import { useState } from 'react';
import { __ } from '@wordpress/i18n';

import { parseWPDate } from '@/lib/helpers/format.helper';
import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { useCampaignsQuery } from '@/lib/queries/campaign.queries';
import {
  CAMPAIGN_STATUSES,
  LITE_CAMPAIGN_TRIGGERS,
  PRO_CAMPAIGN_TRIGGERS,
  type Campaign,
  type CampaignStatus,
  type CampaignTrigger,
} from '@/lib/schema/campaign.schema';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import PageLayout from '@/components/layout/PageLayout';
import { ProGate } from '@/components/pro/ProGate';
import { ConditionsPreview } from '@/pro';

const PER_PAGE = 20;

/**
 * Trigger filter options.
 *
 * Pro triggers are shown in BOTH builds, disabled in Lite rather than hidden —
 * a merchant has to see what upgrading would give them. `locked` is decided at
 * build time from `IS_PRO_BUILD`, so in a Pro build the constant folds to false
 * and the badge disappears along with it.
 */
const TRIGGER_OPTIONS: FilterOption[] = [
  ...LITE_CAMPAIGN_TRIGGERS.map((value) => ({ value })),
  ...PRO_CAMPAIGN_TRIGGERS.map((value) => ({ value, locked: !IS_PRO_BUILD })),
];

const STATUS_OPTIONS: FilterOption[] = CAMPAIGN_STATUSES.map((value) => ({ value }));

/**
 * Proof-of-life list for the definition-table slice. Read-only: no editor.
 *
 * Filtering is sent to the server rather than applied to the current page, so
 * paging stays correct across the whole filtered set.
 */
export default function CampaignsPage() {
  const [trigger, setTrigger] = useState<CampaignTrigger | undefined>();
  const [status, setStatus] = useState<CampaignStatus | undefined>();
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<Campaign | undefined>();

  const campaigns = useCampaignsQuery({ page, per_page: PER_PAGE, trigger, status });

  return (
    <PageLayout
      title={__('Campaigns', 'yayloyalty')}
      description={__('Earning rules. Built-in campaigns cannot be deleted.', 'yayloyalty')}
    >
      <Card>
        <CardContent className="flex flex-col gap-4">
          <div className="flex flex-wrap gap-4">
            <FilterGroup
              label={__('Trigger', 'yayloyalty')}
              options={TRIGGER_OPTIONS}
              active={trigger}
              onSelect={(value) => {
                setPage(1);
                setTrigger(value as CampaignTrigger | undefined);
              }}
            />
            <FilterGroup
              label={__('Status', 'yayloyalty')}
              options={STATUS_OPTIONS}
              active={status}
              onSelect={(value) => {
                setPage(1);
                setStatus(value as CampaignStatus | undefined);
              }}
            />
          </div>

          {campaigns.isPending && <Skeleton className="h-24 w-full" />}

          {campaigns.isError && (
            <p className="text-destructive text-sm">{__('Could not load campaigns.', 'yayloyalty')}</p>
          )}

          {campaigns.data && (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{__('Name', 'yayloyalty')}</TableHead>
                    <TableHead>{__('Trigger', 'yayloyalty')}</TableHead>
                    <TableHead>{__('Status', 'yayloyalty')}</TableHead>
                    <TableHead className="text-right">{__('Priority', 'yayloyalty')}</TableHead>
                    <TableHead>{__('Window', 'yayloyalty')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {campaigns.data.data.map((campaign) => (
                    <TableRow
                      key={campaign.id}
                      className="cursor-pointer"
                      data-state={selected?.id === campaign.id ? 'selected' : undefined}
                      onClick={() => setSelected(campaign)}
                    >
                      <TableCell>
                        <div className="flex items-center gap-2">
                          <span>{campaign.name}</span>
                          {campaign.is_builtin && <Badge variant="secondary">{__('Built-in', 'yayloyalty')}</Badge>}
                        </div>
                        <span className="text-muted-foreground text-xs">{campaign.slug}</span>
                      </TableCell>
                      <TableCell>
                        <span className="flex items-center gap-1.5">
                          {campaign.trigger}
                          {/*
                            A campaign carrying a Pro trigger renders here in
                            Lite too — that is downgrade safety visible in the
                            UI. ProGate renders nothing in a Pro build.
                          */}
                          {isProTrigger(campaign.trigger) && <ProGate inline />}
                        </span>
                      </TableCell>
                      <TableCell>
                        <Badge variant={campaign.status === 'active' ? 'primary' : 'secondary'}>
                          {campaign.status}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-right tabular-nums">{campaign.priority}</TableCell>
                      <TableCell className="text-muted-foreground text-xs">
                        {describeWindow(campaign.starts_at, campaign.ends_at)}
                      </TableCell>
                    </TableRow>
                  ))}

                  {campaigns.data.data.length === 0 && (
                    <TableRow>
                      <TableCell colSpan={5} className="text-muted-foreground text-center">
                        {__('No campaigns match these filters.', 'yayloyalty')}
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </div>
          )}

          {campaigns.data && campaigns.data.totalPage > 1 && (
            <div className="flex items-center justify-end gap-2">
              <Button
                variant="outline"
                size="sm"
                disabled={page <= 1}
                onClick={() => setPage((current) => Math.max(1, current - 1))}
              >
                {__('Previous', 'yayloyalty')}
              </Button>
              <span className="text-muted-foreground text-xs">
                {page} / {campaigns.data.totalPage}
              </span>
              <Button
                variant="outline"
                size="sm"
                disabled={page >= campaigns.data.totalPage}
                onClick={() => setPage((current) => current + 1)}
              >
                {__('Next', 'yayloyalty')}
              </Button>
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{__('Eligibility conditions', 'yayloyalty')}</CardTitle>
          <CardDescription>
            {selected ? selected.name : __('Select a campaign above to inspect its conditions.', 'yayloyalty')}
          </CardDescription>
        </CardHeader>
        <CardContent>
          {/*
            The whole Pro surface, gated twice and for two different reasons.

            ProGate decides what a user sees: children in Pro, the upgrade
            overlay in Lite.

            The `IS_PRO_BUILD &&` decides what ships. It is NOT redundant. JSX
            evaluates children eagerly, so <ProGate><ConditionsPreview /></ProGate>
            would reference the component at runtime and pull all of `@/pro`
            — sentinel included — into the Lite bundle. The build would look
            fine and validate-build.php would fail it. Keep the guard.
          */}
          <ProGate>
            {IS_PRO_BUILD && selected ? (
              <ConditionsPreview campaignId={selected.id} conditions={selected.conditions} />
            ) : null}
          </ProGate>
        </CardContent>
      </Card>
    </PageLayout>
  );
}

type FilterOption = {
  value: string;
  /** Offered but not available in this build: disabled and badged, never hidden. */
  locked?: boolean;
};

function FilterGroup({
  label,
  options,
  active,
  onSelect,
}: {
  label: string;
  options: readonly FilterOption[];
  active?: string;
  onSelect: (value: string | undefined) => void;
}) {
  return (
    <div className="flex flex-col gap-1">
      <span className="text-muted-foreground text-xs">{label}</span>
      <div className="flex flex-wrap items-center gap-1">
        <Button variant={active ? 'outline' : 'primary'} size="sm" onClick={() => onSelect(undefined)}>
          {__('All', 'yayloyalty')}
        </Button>
        {options.map((option) => (
          <span key={option.value} className="flex items-center gap-0.5">
            <Button
              variant={active === option.value ? 'primary' : 'outline'}
              size="sm"
              disabled={option.locked}
              onClick={() => onSelect(option.value)}
            >
              {option.value}
            </Button>
            {option.locked && <ProGate inline />}
          </span>
        ))}
      </div>
    </div>
  );
}

/** Is this a trigger only a Pro build can fire? */
function isProTrigger(trigger: string): boolean {
  return (PRO_CAMPAIGN_TRIGGERS as readonly string[]).includes(trigger);
}

/**
 * Campaigns have no `scheduled` or `ended` status — liveness is derived from
 * these dates at read time, so the window is shown rather than a status word.
 */
function describeWindow(startsAt: string | null, endsAt: string | null): string {
  if (!startsAt && !endsAt) {
    return __('Always', 'yayloyalty');
  }

  const from = startsAt ? parseWPDate(startsAt) : __('any time', 'yayloyalty');
  const to = endsAt ? parseWPDate(endsAt) : __('no end', 'yayloyalty');

  return `${from} → ${to}`;
}
