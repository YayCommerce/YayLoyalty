import { useState } from 'react';
import { __ } from '@wordpress/i18n';

import { adjustPointsSchema, type AdjustPointsInput, type AdjustPointsPreview } from '@/lib/schema/customer.schema';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/custom/dialog';
import { Field, FieldContent, FieldError, FieldLabel } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { AdjustPointsAmountField } from './adjust-points/AdjustPointsAmountField';
import { AdjustPointsBulkPanels } from './adjust-points/AdjustPointsBulkPanels';
import { AdjustPointsFields } from './adjust-points/AdjustPointsFields';
import { AdjustPointsFooter } from './adjust-points/AdjustPointsFooter';
import { AdjustPointsHeader } from './adjust-points/AdjustPointsHeader';
import { AdjustPointsNegativeBalancePanel } from './adjust-points/AdjustPointsNegativeBalancePanel';
import { AdjustPointsOptions } from './adjust-points/AdjustPointsOptions';
import { AdjustPointsStaleBalanceBanner } from './adjust-points/AdjustPointsStaleBalanceBanner';
import { AMOUNT_EACH_LABEL, bulkNotifyByEmailCount } from './customers-adjust-points.bulk.copy';
import { AMOUNT_LABEL, COULD_NOT_VERIFY_BODY, COULD_NOT_VERIFY_TITLE, FOOTER_NOTICE, REASON_HELPER, REASON_LABEL, REASON_PLACEHOLDER, RETRY_LABEL } from './customers-adjust-points.copy';
import { canSubmit, initialAdjustFormState, newBalance, signedPoints, type AdjustFormState } from './customers-adjust-points.form-state';
import { useAdjustPreviewBaseline } from './customers-adjust-points.use-preview-baseline';
import { useBulkSelection } from './customers-adjust-points.use-bulk-selection';
import { DEFAULT_ROW_FILTERS, type RowFilters } from '../customers-page.filter-rows';

/**
 * Adjust points — single (count === 1, from the drawer) or bulk (count > 1,
 * from the Customers list). Plain useState, not react-hook-form: this stays
 * a flat set of fields with one shared blockers gate, no dependent forms.
 *
 * Lifetime toggle, customer-facing description and notify-by-email are
 * local component state ONLY — the submit payload is unchanged from before
 * this redesign (`{ points, note }`). See plan.md's scope guardrails.
 */
export function CustomersAdjustPointsDialog({
  open,
  onOpenChange,
  count,
  isSubmitting,
  onSubmit,
  preview,
  excludedCount = 0,
  selectionMode = 'partial',
  filters,
  rowsOnPage = 0,
  acknowledgementResetKey = 0,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** How many customers this adjustment applies to. */
  count: number;
  isSubmitting: boolean;
  /** `expectedCount` (bulk only) is the count acknowledged, for bulk-actions' pre-fan-out re-check. */
  onSubmit: (input: AdjustPointsInput, expectedCount?: number) => void;
  /** Live snapshot from `POST /customers/adjust/preview`. */
  preview: {
    data?: AdjustPointsPreview;
    isLoading: boolean;
    isFetching: boolean;
    error: unknown;
    /**
     * react-query's `refetch()` never rejects and, on a failed fetch,
     * resolves with the *previous* successful `data` plus a set `error` —
     * so `fresh.data` alone cannot tell a fresh read from a failed one that
     * happened to return the cached baseline. Every caller of this must
     * check `error` explicitly or it fails open (see handleSubmit below).
     */
    refetch: () => Promise<{ data?: AdjustPointsPreview; error?: unknown }>;
  };
  /** Rows deselected under "select all matching" — the server's preview count knows only the filter. */
  excludedCount?: number;
  /** Bulk only: whether the selection is an explicit tick list or "every row matching the filter". */
  selectionMode?: 'partial' | 'all';
  /** Bulk only, for the filter restatement. */
  filters?: RowFilters;
  /** Bulk only: rows already loaded on the current page, for the "not shown on this page" line. */
  rowsOnPage?: number;
  /** Bulk only: bumped by the caller after a pre-submit count mismatch, to force a fresh consent. */
  acknowledgementResetKey?: number;
}) {
  const [state, setState] = useState<AdjustFormState>(initialAdjustFormState);
  const [validationError, setValidationError] = useState<string | null>(null);
  const [isCheckingBeforeSubmit, setIsCheckingBeforeSubmit] = useState(false);

  const isSingle = count === 1;
  const singleCustomer = isSingle ? preview.data?.customers[0] : undefined;
  const pointsPerCurrencyUnit = preview.data?.points_per_currency_unit ?? 0;

  const baseline = useAdjustPreviewBaseline(isSingle, singleCustomer, preview.refetch);
  const { isStale, openedBalance } = baseline;

  const bulk = useBulkSelection({
    isSingle,
    count,
    previewCount: preview.data?.count,
    selectionMode,
    excludedCount,
    rowsOnPage,
    acknowledgementResetKey,
  });

  function patchState(patch: Partial<AdjustFormState>) {
    setState((current) => ({ ...current, ...patch }));
  }

  function handleOpenChange(next: boolean) {
    if (!next) {
      // A stale value carrying into the next adjustment would be a footgun.
      setState(initialAdjustFormState());
      setValidationError(null);
      baseline.reset();
      bulk.resetAcknowledged();
    }
    onOpenChange(next);
  }

  // Fail closed: without a record neither guard below can run. Bulk has no
  // per-customer record to verify against and is not covered by this rule.
  const isUnverified = isSingle && !preview.isLoading && (!!preview.error || !singleCustomer);
  const isNegativeStart = isSingle && !!singleCustomer && singleCustomer.balance < 0;
  const balanceAfter = singleCustomer ? newBalance(state, singleCustomer.balance) : 0;

  const submitEnabled =
    canSubmit(state, {
      // Bulk has no per-customer lifetime total; Infinity keeps the typo
      // guard inactive rather than firing on a made-up number.
      lifetimeEarned: isSingle ? (singleCustomer?.lifetime_earned ?? 0) : Number.POSITIVE_INFINITY,
      pointsPerCurrencyUnit,
      isUnverified,
      isStale,
      needsAcknowledgement: !isSingle,
      acknowledged: bulk.acknowledged,
    }) && !bulk.isOverLimit;

  async function handleSubmit() {
    const result = adjustPointsSchema.safeParse({ points: signedPoints(state), note: state.reason });

    if (!result.success) {
      setValidationError(result.error.issues[0]?.message ?? __('Invalid input.', 'yayloyalty'));
      return;
    }

    setValidationError(null);

    if (isSingle) {
      // One cheap read stands between this write and a stale balance — the
      // reactive `isStale` above only catches a change once something else
      // (Reload, a refetch) has already fetched it; this is what actually
      // catches a change made in the seconds since.
      setIsCheckingBeforeSubmit(true);
      const fresh = await preview.refetch().finally(() => setIsCheckingBeforeSubmit(false));
      const latest = fresh.data?.customers[0] ?? null;

      // A failed refetch never rejects and resolves with the *previous*
      // successful data plus a set `error` — checking `error` explicitly is
      // required, or a failed re-check silently "verifies" against the
      // stale cache it already had (the exact fail-open this guard exists
      // to prevent). No record or still stale against the acknowledged
      // baseline: abort either way. Any of the three becomes `isUnverified`
      // on the next render, never treated as "not stale".
      if (fresh.error || !latest || baseline.isStaleAgainst(latest)) return;

      onSubmit(result.data);
      return;
    }

    // Bulk's own pre-fan-out re-check lives in customers-page.bulk-actions.ts,
    // which already re-resolves the matching set fresh before writing.
    onSubmit(result.data, bulk.effectiveCount);
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="max-h-[90vh] sm:max-w-lg">
        <AdjustPointsHeader
          isSingle={isSingle}
          isLoading={preview.isLoading}
          singleCustomer={singleCustomer}
          pointsPerCurrencyUnit={pointsPerCurrencyUnit}
          isStale={isStale}
          openedBalance={openedBalance}
          bulkEffectiveCount={bulk.effectiveCount}
        />

        {isUnverified && (
          <Alert variant="outline" destructive>
            <AlertTitle>{COULD_NOT_VERIFY_TITLE()}</AlertTitle>
            <AlertDescription>
              <p>{COULD_NOT_VERIFY_BODY()}</p>
              <Button variant="outline" size="sm" onClick={() => preview.refetch()}>
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
            isReloading={preview.isFetching}
          />
        )}

        {isNegativeStart && singleCustomer && (
          <AdjustPointsNegativeBalancePanel state={state} customerName={singleCustomer.name} balance={singleCustomer.balance} />
        )}

        <AdjustPointsAmountField state={state} onChange={patchState} label={isSingle ? AMOUNT_LABEL() : AMOUNT_EACH_LABEL()} />

        {isSingle && singleCustomer && (
          <AdjustPointsFields
            state={state}
            onChange={patchState}
            customerName={singleCustomer.name}
            balance={singleCustomer.balance}
            lifetimeEarned={singleCustomer.lifetime_earned}
            pointsPerCurrencyUnit={pointsPerCurrencyUnit}
            showResultPanel={!isNegativeStart || balanceAfter >= 0}
          />
        )}

        {!isSingle && (
          <AdjustPointsBulkPanels
            state={state}
            effectiveCount={bulk.effectiveCount}
            isCountLoading={bulk.usedFilters && preview.isLoading}
            selectionMode={selectionMode}
            filters={filters ?? DEFAULT_ROW_FILTERS}
            notShownOnPage={bulk.notShownOnPage}
            pointsPerCurrencyUnit={pointsPerCurrencyUnit}
            liabilityPoints={preview.data?.liability_points ?? null}
            hasPreviewError={!!preview.error}
            isOverLimit={bulk.isOverLimit}
            acknowledged={bulk.acknowledged}
            onAcknowledgedChange={bulk.setAcknowledged}
          />
        )}

        <Field>
          <FieldLabel htmlFor="adjust-points-reason">{REASON_LABEL()}</FieldLabel>
          <FieldContent>
            <Textarea
              id="adjust-points-reason"
              value={state.reason}
              onChange={(event) => patchState({ reason: event.target.value })}
              placeholder={REASON_PLACEHOLDER()}
            />
            <p className="text-muted-foreground text-xs">{REASON_HELPER()}</p>
          </FieldContent>
        </Field>

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
          isPreviewLoading={preview.isLoading}
          submitEnabled={submitEnabled}
          onCancel={() => handleOpenChange(false)}
          onSubmit={handleSubmit}
        />
      </DialogContent>
    </Dialog>
  );
}
