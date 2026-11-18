import { useRef, useState } from 'react';
import { Link } from 'react-router';

import { formatWPCurrency, formatWPDate } from '@/lib/helpers/format.helper';
import { formatCount, pointsPhrase } from '@/lib/helpers/points.helper';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/custom/error-state';
import { Input } from '@/components/ui/input';
import { TableCell, TableHead, TableRow } from '@/components/ui/table';
import { CustomerDrawerStat } from '@/pages/customers/drawer/CustomerDrawerStat';
import { DRAWER_ROW_CLASS, DrawerTable } from '@/pages/customers/drawer/tabs/DrawerTable';
import { DRAWER_PAGE_SIZE, DrawerTabPager } from '@/pages/customers/drawer/tabs/DrawerTabPager';
import { useCustomerReferralsQuery } from '../referral.queries';
import { toReferralRow } from '../referrals/referral-row';
import { CUSTOMER_REFERRALS_COPY as COPY } from './customer-referrals.copy';
import { friendDiscount, pointsShare, toAdvocateCell, toFriendCell } from './customer-referrals.rows';

/** The drawer's Referrals tab (UX-2 "Customer drawer → Referrals"): one customer as advocate, with their link. */
export function CustomerReferralsTab({
  userId,
  customerFirstName,
  lifetimeEarned,
}: {
  userId: number;
  customerFirstName: string;
  lifetimeEarned: number;
}) {
  const [page, setPage] = useState(1);
  const [copied, setCopied] = useState(false);
  const linkInput = useRef<HTMLInputElement>(null);
  const query = useCustomerReferralsQuery(userId, page);
  const data = query.data;

  if (query.isError) {
    return <ErrorState message={COPY.loadError()} onRetry={() => query.refetch()} />;
  }

  async function copyLink() {
    if (!data?.link) return;

    try {
      await navigator.clipboard.writeText(data.link);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      // No clipboard access: select the text so Ctrl/Cmd+C copies it.
      linkInput.current?.select();
    }
  }

  return (
    <div className="flex flex-col gap-4 pt-3">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <CustomerDrawerStat
          label={COPY.referred()}
          value={data ? formatCount(data.counts.referred) : '—'}
          subLine={data && COPY.referredSub(data.counts.rewarded, data.counts.waiting)}
        />
        <CustomerDrawerStat
          label={COPY.pointsEarned()}
          value={data ? formatCount(data.counts.points_earned) : '—'}
          subLine={data && COPY.pointsShare(pointsShare(data.counts, lifetimeEarned))}
        />
        <CustomerDrawerStat
          label={COPY.revenue()}
          value={data ? formatWPCurrency(data.counts.revenue_referred) : '—'}
          subLine={COPY.revenueSub()}
        />
      </div>

      <div className="flex flex-col gap-2 rounded-md border p-3">
        <span className="text-sm font-medium">{COPY.link()}</span>
        {data && !data.link ? (
          <p className="text-muted-foreground text-sm">{COPY.noLink()}</p>
        ) : (
          <div className="flex gap-2">
            <Input readOnly dir="ltr" value={data?.link ?? ''} ref={linkInput} aria-label={COPY.link()} />
            <Button variant="outline" disabled={!data?.link} onClick={copyLink}>
              {copied ? COPY.copied() : COPY.copy()}
            </Button>
            <span aria-live="polite" className="sr-only">
              {copied ? COPY.copied() : ''}
            </span>
          </div>
        )}
        {data && (
          <p className="text-muted-foreground text-xs">
            {data.terms.enabled
              ? COPY.termsLine(
                  pointsPhrase(data.terms.advocate_points),
                  customerFirstName,
                  friendDiscount(data.terms),
                  data.terms.coupon_days,
                )
              : COPY.programOff()}{' '}
            <Link to="/referral/settings" className="text-primary hover:underline">
              {COPY.settings()}
            </Link>
          </p>
        )}
      </div>

      <div className="overflow-x-auto rounded-md border">
        <DrawerTable
          head={
            <TableRow>
              <TableHead>{COPY.columnReferred()}</TableHead>
              <TableHead>{COPY.columnSignedUp()}</TableHead>
              <TableHead>{COPY.columnFirstOrder()}</TableHead>
              <TableHead>{COPY.columnToAdvocate(customerFirstName)}</TableHead>
              <TableHead>{COPY.columnToFriend()}</TableHead>
              <TableHead>{COPY.columnStatus()}</TableHead>
            </TableRow>
          }
          columnCount={6}
          isLoading={query.isPending}
          isEmpty={(data?.data.length ?? 0) === 0}
          emptyTitle={COPY.emptyTitle()}
          emptyBody={COPY.emptyBody()}
        >
          {data?.data.map((referral) => {
            const row = toReferralRow(referral);

            return (
              <TableRow key={referral.id} className={DRAWER_ROW_CLASS}>
                <TableCell>
                  <span className="flex flex-col gap-0.5">
                    <span className="font-medium">{referral.friend.name}</span>
                    <span className="text-muted-foreground text-xs">{referral.friend.email}</span>
                  </span>
                </TableCell>
                <TableCell className="text-muted-foreground">{row.date}</TableCell>
                <TableCell>
                  {referral.first_order ? (
                    <a href={referral.first_order.edit_url} className="hover:underline">
                      {row.order} · {row.value}
                    </a>
                  ) : (
                    <span className="text-muted-foreground">
                      {referral.status === 'pending' ? COPY.noQualifyingOrder() : '—'}
                    </span>
                  )}
                </TableCell>
                <TableCell className="tabular-nums">{toAdvocateCell(referral)}</TableCell>
                <TableCell>{toFriendCell(referral)}</TableCell>
                <TableCell>
                  <span className="flex flex-col gap-0.5">
                    <Badge variant={row.variant}>{row.status}</Badge>
                    {referral.status === 'pending' && referral.coupon_expires_at ? (
                      <span className="text-muted-foreground max-w-56 text-xs whitespace-normal">
                        {COPY.pendingNote(formatWPDate(referral.coupon_expires_at))}
                      </span>
                    ) : (
                      row.reason && <span className="text-muted-foreground text-xs">{row.reason}</span>
                    )}
                  </span>
                </TableCell>
              </TableRow>
            );
          })}
        </DrawerTable>
      </div>

      <DrawerTabPager
        page={page}
        perPage={DRAWER_PAGE_SIZE}
        totalItems={data?.totalItems ?? 0}
        onPageChange={setPage}
        disabled={query.isFetching}
        isPending={query.isPending}
      />
    </div>
  );
}
