import { useEffect, useRef, useState } from 'react';
import { __ } from '@wordpress/i18n';

import {
  adjustPointsSchema,
  type AdjustPointsInput,
  type AdjustPointsPreview,
  type Customer,
} from '@/lib/schema/customer.schema';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/custom/dialog';
import { FieldError } from '@/components/ui/field';
import { DEFAULT_CUSTOMER_LIST_FILTERS, type CustomerListFilters } from '../customers-page.filters';
import { AdjustPointsAmountField } from './adjust-points/AdjustPointsAmountField';
import { AdjustPointsBulkPanels } from './adjust-points/AdjustPointsBulkPanels';
import { AdjustPointsFields } from './adjust-points/AdjustPointsFields';
import { AdjustPointsFooter } from './adjust-points/AdjustPointsFooter';
import { AdjustPointsHeader } from './adjust-points/AdjustPointsHeader';
import { AdjustPointsNegativeBalancePanel } from './adjust-points/AdjustPointsNegativeBalancePanel';
import { AdjustPointsOptions } from './adjust-points/AdjustPointsOptions';
import { AdjustPointsReasonField } from './adjust-points/AdjustPointsReasonField';
import { AdjustPointsStaleBalanceBanner } from './adjust-points/AdjustPointsStaleBalanceBanner';
import { AMOUNT_EACH_LABEL, bulkNotifyByEmailCount } from './customers-adjust-points.bulk.copy';
import {
  AMOUNT_LABEL,
  COULD_NOT_VERIFY_BODY,
  COULD_NOT_VERIFY_TITLE,
  DEFAULT_REASON,
  FOOTER_NOTICE,
  RETRY_LABEL,
} from './customers-adjust-points.copy';
import {
  initialAdjustFormState,
  newBalance,
  signedPoints,
  submitBlockers,
  type AdjustFormState,
} from './customers-adjust-points.form-state';
import { useBulkAdjustCounts } from './customers-adjust-points.use-bulk-adjust-counts';
import { useSingleCustomerBaseline } from './customers-adjust-points.use-single-customer-baseline';

/**
 * A live read the dialog renders from and re-checks against.
 * A failed `refetch()` does not reject: it resolves with the previous `data` and a set `error`,
 * so a caller must check `error` or it fails open.
 */
type LiveRead<T> = {
  data?: T;
  isLoading: boolean;
  isFetching: boolean;
  error: unknown;
  refetch: () => Promise<{ data?: T; error?: unknown }>;
};

/**
 * Adjust points for one customer (the drawer, or one ticked row) or in bulk.
 * Plain useState, not react-hook-form: a flat set of fields behind one shared gate.
 * The customer description is sent only when its switch is on and it isn't blank; otherwise customers see "Adjustment by {store}".
 */
export function CustomersAdjustPointsDialog({
  open,
  onOpenChange,
  count,
  isSubmitting,
  onSubmit,
  target,
  excludedCount = 0,
  selectionMode = 'partial',
  filters,
  tierLabel = null,
  rowsOnPage = 0,
  acknowledgementResetKey = 0,
  recountedCount = null,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** How many customers this adjustment applies to. */
  count: number;
  isSubmitting: boolean;
  /** `expectedCount` (bulk only) is the count the admin acknowledged, re-checked before the writes. */
  onSubmit: (input: AdjustPointsInput, expectedCount?: number) => void;
  /**
   * Picks the layout. Single reads the customer's own record
   * (`GET /customers/{id}`); bulk reads `POST /customers/adjust/preview`.
   */
  target: { kind: 'single'; customer: LiveRead<Customer> } | { kind: 'bulk'; preview: LiveRead<AdjustPointsPreview> };
  /** Rows deselected under "select all matching": the server's preview count knows only the filter. */
  excludedCount?: number;
  /** Bulk only: whether the selection is an explicit tick list or "every row matching the filter". */
  selectionMode?: 'partial' | 'all';
  /** Bulk only, for the filter restatement. */
  filters?: CustomerListFilters;
  /** Bulk only: the tier filter's choices as text, for the filter restatement. */
  tierLabel?: string | null;
  /** Bulk only: rows already loaded on the current page, for the "not shown on this page" line. */
  rowsOnPage?: number;
  /** Bulk only: bumped by the caller after a pre-submit count mismatch, to force a fresh consent. */
  acknowledgementResetKey?: number;
  /** Bulk only: the fresh count a submit resolved when it disagreed with the one shown; replaces it until close. */
  recountedCount?: number | null;
}) {
  const [state, setState] = useState<AdjustFormState>(() => initialAdjustFormState(DEFAULT_REASON()));
  const [validationError, setValidationError] = useState<string | null>(null);
  const [isCheckingBeforeSubmit, setIsCheckingBeforeSubmit] = useState(false);

  const isSingle = target.kind === 'single';
  const source = target.kind === 'single' ? target.customer : target.preview;
  const singleCustomer = target.kind === 'single' ? target.customer.data : undefined;
  const previewData = target.kind === 'bulk' ? target.preview.data : undefined;

  const baseline = useSingleCustomerBaseline(
    open,
    isSingle,
    singleCustomer,
    target.kind === 'single' ? target.customer.refetch : undefined,
    source.isFetching,
  );
  const { isStale, openedBalance } = baseline;

  const bulk = useBulkAdjustCounts({
    isSingle,
    count,
    previewCount: previewData?.count ?? undefined,
    selectionMode,
    excludedCount,
    rowsOnPage,
    acknowledgementResetKey,
    recountedCount,
    consentKey: `${state.direction}:${state.amount}`,
  });

  function patchState(patch: Partial<AdjustFormState>) {
    setState((current) => ({ ...current, ...patch }));
  }

  // Keyed on `open`, not done in handleOpenChange: callers also close the dialog by setting
  // `open` to false after a successful submit, which bypasses handleOpenChange.
  useEffect(() => {
    if (open) return;
    setState(initialAdjustFormState(DEFAULT_REASON()));
    setValidationError(null);
    baseline.reset();
    bulk.resetAcknowledged();
    // eslint-disable-next-line react-hooks/exhaustive-deps -- reset handles must run once per open->close edge, not on their own churn.
  }, [open]);

  // Read after handleSubmit's await: a close during the pre-submit check
  // must cancel the write, not let it land behind a dialog the admin dismissed.
  const openRef = useRef(open);
  useEffect(() => {
    openRef.current = open;
    return () => {
      openRef.current = false;
    };
  }, [open]);

  const isBusy = isSubmitting || isCheckingBeforeSubmit;

  function handleOpenChange(next: boolean) {
    if (!next && isBusy) return;
    onOpenChange(next);
  }

  // Fail closed: without a record neither guard below can run. Bulk has no
  // per-customer record to verify against and is not covered by this rule.
  const isUnverified = isSingle && !source.isLoading && (!!source.error || !singleCustomer);
  const isNegativeStart = isSingle && !!singleCustomer && singleCustomer.balance < 0;
  const balanceAfter = singleCustomer ? newBalance(state, singleCustomer.balance) : 0;

  const blockers = submitBlockers(state, {
    // No baseline yet (reopen refetch still in flight): nothing acknowledged to compare against.
    isUnverified: isUnverified || (isSingle && !baseline.hasBaseline),
    isStale,
    needsAcknowledgement: !isSingle,
    acknowledged: bulk.acknowledged,
    isOverLimit: bulk.isOverLimit,
  });

  async function handleSubmit() {
    const result = adjustPointsSchema.safeParse({
      points: signedPoints(state),
      note: state.reason,
      adjustLifetime: state.adjustLifetime,
      notify: state.notifyByEmail,
      customerNote: state.showCustomerNote && state.customerNote.trim() ? state.customerNote.trim() : undefined,
    });

    if (!result.success) {
      setValidationError(result.error.issues[0]?.message ?? __('Invalid input.', 'yayloyalty'));
      return;
    }

    setValidationError(null);

    if (target.kind === 'single') {
      // Re-read before writing: `isStale` only notices a change once something has refetched,
      // so this catches a change made in the last few seconds.
      setIsCheckingBeforeSubmit(true);
      const fresh = await target.customer.refetch().finally(() => setIsCheckingBeforeSubmit(false));
      const latest = fresh.data ?? null;

      // Check `fresh.error` (see LiveRead). Abort on a failed read, a missing record,
      // or a balance that moved since the acknowledged baseline.
      if (!openRef.current || fresh.error || !latest || baseline.isStaleAgainst(latest)) return;

      onSubmit(result.data);
      return;
    }

    // Bulk re-checks its count in useCustomersBulkActions, which re-resolves the matching set before writing.
    onSubmit(result.data, bulk.effectiveCount);
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="max-h-[90vh] sm:max-w-lg">
        <AdjustPointsHeader
          isSingle={isSingle}
          isLoading={source.isLoading}
          singleCustomer={singleCustomer}
          isStale={isStale}
          openedBalance={openedBalance}
          bulkEffectiveCount={bulk.effectiveCount}
        />

        {isUnverified && (
          <Alert variant="outline" destructive>
            <AlertTitle>{COULD_NOT_VERIFY_TITLE()}</AlertTitle>
            <AlertDescription>
              <p>{COULD_NOT_VERIFY_BODY()}</p>
              <Button variant="outline" size="sm" onClick={() => source.refetch()}>
                {RETRY_LABEL()}
              </Button>
            </AlertDescription>
          </Alert>
        )}

        {isStale && singleCustomer && openedBalance !== null && (
          <AdjustPointsStaleBalanceBanner
            customerName={singleCustomer.name}
            openedBalance={openedBalance}
            latestBalance={singleCustomer.balance}
            onReload={baseline.handleReload}
            isReloading={source.isFetching}
          />
        )}

        {isNegativeStart && singleCustomer && (
          <AdjustPointsNegativeBalancePanel
            state={state}
            customerName={singleCustomer.name}
            balance={singleCustomer.balance}
          />
        )}

        <AdjustPointsAmountField
          state={state}
          onChange={patchState}
          label={isSingle ? AMOUNT_LABEL() : AMOUNT_EACH_LABEL()}
        />

        {isSingle && singleCustomer && (
          <AdjustPointsFields
            state={state}
            customerName={singleCustomer.name}
            balance={singleCustomer.balance}
            showResultPanel={!isNegativeStart || balanceAfter >= 0}
          />
        )}

        {!isSingle && (
          <AdjustPointsBulkPanels
            state={state}
            effectiveCount={bulk.effectiveCount}
            isCountLoading={bulk.usedFilters && source.isLoading}
            selectionMode={selectionMode}
            filters={filters ?? DEFAULT_CUSTOMER_LIST_FILTERS}
            tierLabel={tierLabel}
            notShownOnPage={bulk.notShownOnPage}
            liabilityPoints={previewData?.liability_points ?? null}
            hasPreviewError={!!source.error}
            isOverLimit={bulk.isOverLimit}
            acknowledged={bulk.acknowledged}
            onAcknowledgedChange={bulk.setAcknowledged}
          />
        )}

        <AdjustPointsReasonField reason={state.reason} onChange={(reason) => patchState({ reason })} />

        <AdjustPointsOptions
          state={state}
          onChange={patchState}
          notifyCountLabel={!isSingle ? bulkNotifyByEmailCount(bulk.effectiveCount) : undefined}
        />

        {validationError && <FieldError>{validationError}</FieldError>}

        <p className="text-muted-foreground text-xs">{FOOTER_NOTICE()}</p>

        <AdjustPointsFooter
          isSingle={isSingle}
          state={state}
          effectiveCount={bulk.effectiveCount}
          isSubmitting={isSubmitting}
          isCheckingBeforeSubmit={isCheckingBeforeSubmit}
          isPreviewLoading={source.isLoading}
          blockers={blockers}
          onCancel={() => handleOpenChange(false)}
          onSubmit={handleSubmit}
        />
      </DialogContent>
    </Dialog>
  );
}
