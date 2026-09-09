import { useState } from 'react';
import { __ } from '@wordpress/i18n';

import { parseWPDate } from '@/lib/helpers/format.helper';
import { useCampaignsQuery } from '@/lib/queries/campaign.queries';
import {
  CAMPAIGN_STATUSES,
  CAMPAIGN_TRIGGERS,
  type CampaignStatus,
  type CampaignTrigger,
} from '@/lib/schema/campaign.schema';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

const PER_PAGE = 20;

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

  const campaigns = useCampaignsQuery({ page, per_page: PER_PAGE, trigger, status });

  return (
    <div className="mx-auto flex max-w-5xl flex-col gap-4 p-6">
      <Card>
        <CardHeader>
          <CardTitle>{__('Campaigns', 'yayloyalty')}</CardTitle>
          <CardDescription>
            {__('Earning rules. Built-in campaigns cannot be deleted.', 'yayloyalty')}
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <div className="flex flex-wrap gap-4">
            <FilterGroup
              label={__('Trigger', 'yayloyalty')}
              options={CAMPAIGN_TRIGGERS}
              active={trigger}
              onSelect={(value) => {
                setPage(1);
                setTrigger(value as CampaignTrigger | undefined);
              }}
            />
            <FilterGroup
              label={__('Status', 'yayloyalty')}
              options={CAMPAIGN_STATUSES}
              active={status}
              onSelect={(value) => {
                setPage(1);
                setStatus(value as CampaignStatus | undefined);
              }}
            />
          </div>

          {campaigns.isPending && <Skeleton className="h-24 w-full" />}

          {campaigns.isError && (
            <p className="text-destructive text-sm">
              {__('Could not load campaigns.', 'yayloyalty')}
            </p>
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
                    <TableRow key={campaign.id}>
                      <TableCell>
                        <div className="flex items-center gap-2">
                          <span>{campaign.name}</span>
                          {campaign.is_builtin && (
                            <Badge variant="secondary">{__('Built-in', 'yayloyalty')}</Badge>
                          )}
                        </div>
                        <span className="text-muted-foreground text-xs">{campaign.slug}</span>
                      </TableCell>
                      <TableCell>{campaign.trigger}</TableCell>
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
    </div>
  );
}

function FilterGroup({
  label,
  options,
  active,
  onSelect,
}: {
  label: string;
  options: readonly string[];
  active?: string;
  onSelect: (value: string | undefined) => void;
}) {
  return (
    <div className="flex flex-col gap-1">
      <span className="text-muted-foreground text-xs">{label}</span>
      <div className="flex gap-1">
        <Button
          variant={active ? 'outline' : 'primary'}
          size="sm"
          onClick={() => onSelect(undefined)}
        >
          {__('All', 'yayloyalty')}
        </Button>
        {options.map((option) => (
          <Button
            key={option}
            variant={active === option ? 'primary' : 'outline'}
            size="sm"
            onClick={() => onSelect(option)}
          >
            {option}
          </Button>
        ))}
      </div>
    </div>
  );
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
