import { FormEvent, useState } from 'react';
import { __ } from '@wordpress/i18n';

import { parseWPDate } from '@/lib/helpers/format.helper';
import { describeLedgerEntry, formatSignedPoints } from '@/lib/helpers/ledger.helper';
import { useAdjustPointsMutation, useCustomerLedgerQuery, useCustomerQuery } from '@/lib/queries/customer.queries';
import { Badge } from '@/components/ui/badge';
import { Button, LoadingButton } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import PageLayout from '@/components/layout/PageLayout';

const PER_PAGE = 10;

/**
 * Proof-of-life view for the ledger slice.
 *
 * Deliberately minimal: this exists to prove the data layer end to end, not to
 * be the finished Customers screen. Real UI work is a separate task.
 */
export default function CustomersPage() {
  const [userIdInput, setUserIdInput] = useState('');
  const [userId, setUserId] = useState(0);
  const [page, setPage] = useState(1);

  const customer = useCustomerQuery(userId);
  const history = useCustomerLedgerQuery(userId, page, PER_PAGE);
  const adjust = useAdjustPointsMutation(userId);

  const [points, setPoints] = useState('');
  const [note, setNote] = useState('');

  function lookup(event: FormEvent) {
    event.preventDefault();
    setPage(1);
    setUserId(Number(userIdInput) || 0);
  }

  function submitAdjustment(event: FormEvent) {
    event.preventDefault();

    const value = Number(points);

    if (!value || !note.trim()) {
      return;
    }

    adjust.mutate(
      { points: value, note: note.trim() },
      {
        onSuccess: () => {
          setPoints('');
          setNote('');
        },
      },
    );
  }

  return (
    <PageLayout
      title={__('Customers', 'yayloyalty')}
      description={__('Look up a customer to see their point balance and make adjustments.', 'yayloyalty')}
    >
      <Card>
        <CardContent>
          <form className="flex items-end gap-2" onSubmit={lookup}>
            <div className="flex flex-col gap-1">
              <label className="text-muted-foreground text-xs" htmlFor="yl-user-id">
                {__('User ID', 'yayloyalty')}
              </label>
              <Input
                id="yl-user-id"
                className="w-40"
                inputMode="numeric"
                value={userIdInput}
                onChange={(event) => setUserIdInput(event.target.value)}
                placeholder="1"
              />
            </div>
            <Button type="submit">{__('Look up', 'yayloyalty')}</Button>
          </form>
        </CardContent>
      </Card>

      {userId > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>{__('Balance', 'yayloyalty')}</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            {customer.isPending && <Skeleton className="h-8 w-40" />}

            {customer.isError && (
              <p className="text-destructive text-sm">{__('Could not load this customer.', 'yayloyalty')}</p>
            )}

            {customer.data && (
              <div className="flex flex-wrap gap-6">
                <Stat label={__('Balance', 'yayloyalty')} value={customer.data.balance} />
                <Stat label={__('Pending', 'yayloyalty')} value={customer.data.pending_points} />
                <Stat label={__('Lifetime earned', 'yayloyalty')} value={customer.data.lifetime_earned} />
                <Stat label={__('Lifetime redeemed', 'yayloyalty')} value={customer.data.lifetime_redeemed} />
              </div>
            )}

            <form className="flex flex-wrap items-end gap-2" onSubmit={submitAdjustment}>
              <div className="flex flex-col gap-1">
                <label className="text-muted-foreground text-xs" htmlFor="yl-points">
                  {__('Points (negative to deduct)', 'yayloyalty')}
                </label>
                <Input
                  id="yl-points"
                  className="w-40"
                  inputMode="numeric"
                  value={points}
                  onChange={(event) => setPoints(event.target.value)}
                  placeholder="100"
                />
              </div>
              <div className="flex flex-1 flex-col gap-1">
                <label className="text-muted-foreground text-xs" htmlFor="yl-note">
                  {__('Reason', 'yayloyalty')}
                </label>
                <Input
                  id="yl-note"
                  value={note}
                  onChange={(event) => setNote(event.target.value)}
                  placeholder={__('Goodwill gesture', 'yayloyalty')}
                />
              </div>
              <LoadingButton type="submit" loading={adjust.isPending}>
                {__('Adjust', 'yayloyalty')}
              </LoadingButton>
            </form>

            {adjust.isError && (
              <p className="text-destructive text-sm">{__('The adjustment was rejected.', 'yayloyalty')}</p>
            )}
          </CardContent>
        </Card>
      )}

      {userId > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>{__('History', 'yayloyalty')}</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{__('When', 'yayloyalty')}</TableHead>
                    <TableHead>{__('Description', 'yayloyalty')}</TableHead>
                    <TableHead>{__('Type', 'yayloyalty')}</TableHead>
                    <TableHead className="text-right">{__('Points', 'yayloyalty')}</TableHead>
                    <TableHead className="text-right">{__('Balance', 'yayloyalty')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {history.data?.data.map((entry) => (
                    <TableRow key={entry.id}>
                      <TableCell className="whitespace-nowrap">{parseWPDate(entry.created_at)}</TableCell>
                      {/* Composed from type/source, never a stored string. */}
                      <TableCell>{describeLedgerEntry(entry)}</TableCell>
                      <TableCell>
                        <Badge variant="secondary">{entry.type}</Badge>
                      </TableCell>
                      <TableCell className="text-right tabular-nums">{formatSignedPoints(entry.points)}</TableCell>
                      <TableCell className="text-right tabular-nums">{entry.balance_after}</TableCell>
                    </TableRow>
                  ))}

                  {history.data && history.data.data.length === 0 && (
                    <TableRow>
                      <TableCell colSpan={5} className="text-muted-foreground text-center">
                        {__('No activity yet.', 'yayloyalty')}
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </div>

            {history.data && history.data.totalPage > 1 && (
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
                  {page} / {history.data.totalPage}
                </span>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={page >= history.data.totalPage}
                  onClick={() => setPage((current) => current + 1)}
                >
                  {__('Next', 'yayloyalty')}
                </Button>
              </div>
            )}
          </CardContent>
        </Card>
      )}
    </PageLayout>
  );
}

function Stat({ label, value }: { label: string; value: number }) {
  return (
    <div className="flex flex-col">
      <span className="text-muted-foreground text-xs">{label}</span>
      <span className="text-2xl tabular-nums">{value}</span>
    </div>
  );
}
