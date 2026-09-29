import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { previewConditions, type ConditionResult } from './conditions-preview.api';

/**
 * Per-condition preview of why a customer does or does not match a
 * campaign, from the same evaluation path that runs at award time. A
 * preview, not an editor: the SPA has no campaign editor to save into.
 * Not mounted yet; the call site must guard with IS_PRO_BUILD.
 */
export function ConditionsPreview({
  campaignId,
  conditions,
}: {
  campaignId: number;
  /** The campaign's stored conditions, shown when no preview has been run. */
  conditions: unknown;
}) {
  const [userId, setUserId] = useState('');

  const preview = useMutation({
    mutationFn: (id: number) => previewConditions(campaignId, id),
  });

  const stored = Array.isArray(conditions) ? conditions : [];
  const parsedUserId = Number.parseInt(userId, 10);
  const canPreview = Number.isFinite(parsedUserId) && parsedUserId > 0;

  return (
    <div className="flex flex-col gap-4">
      {stored.length === 0 && (
        <p className="text-muted-foreground text-sm">
          {__('This campaign has no conditions, so every customer qualifies.', 'yayloyalty')}
        </p>
      )}

      <div className="flex items-end gap-2">
        <div className="flex flex-col gap-1">
          <label className="text-muted-foreground text-xs" htmlFor="yayloyalty-preview-user">
            {__('Preview for user ID', 'yayloyalty')}
          </label>
          <Input
            id="yayloyalty-preview-user"
            className="w-40"
            inputMode="numeric"
            value={userId}
            onChange={(event) => setUserId(event.target.value)}
            placeholder="1"
          />
        </div>
        <Button size="sm" disabled={!canPreview || preview.isPending} onClick={() => preview.mutate(parsedUserId)}>
          {preview.isPending ? __('Checking…', 'yayloyalty') : __('Check', 'yayloyalty')}
        </Button>
      </div>

      {preview.isError && (
        <p className="text-destructive text-sm">{__('Could not evaluate conditions for that user.', 'yayloyalty')}</p>
      )}

      {preview.data && (
        <div className="flex flex-col gap-2">
          <div className="flex items-center gap-2">
            <Badge variant={preview.data.matches ? 'primary' : 'secondary'}>
              {preview.data.matches ? __('Qualifies', 'yayloyalty') : __('Does not qualify', 'yayloyalty')}
            </Badge>
            <span className="text-muted-foreground text-xs">
              {sprintf(
                /* translators: %d: WordPress user ID. */
                __('User %d', 'yayloyalty'),
                preview.data.user_id,
              )}
            </span>
          </div>

          {preview.data.conditions.length > 0 && (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{__('Condition', 'yayloyalty')}</TableHead>
                    <TableHead>{__('Expected', 'yayloyalty')}</TableHead>
                    <TableHead>{__('Result', 'yayloyalty')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {preview.data.conditions.map((result, index) => (
                    <TableRow key={`${result.type}-${index}`}>
                      <TableCell>{result.type}</TableCell>
                      <TableCell className="text-muted-foreground text-xs">
                        {result.operator} {describeValue(result.value)}
                      </TableCell>
                      <TableCell>
                        <Badge variant={result.matched ? 'primary' : 'secondary'}>{describeReason(result)}</Badge>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

function describeValue(value: unknown): string {
  if (Array.isArray(value)) {
    return value.join(', ');
  }

  return value === null || value === undefined ? '' : String(value);
}

/**
 * The evaluator's reason code as something a merchant can act on. Unknown
 * type/operator are configuration errors, not "does not qualify";
 * unsupported_condition_type is recognised but not yet evaluable;
 * needs_order is an order-scoped condition this customer-only preview
 * cannot check.
 */
function describeReason(result: ConditionResult): string {
  switch (result.reason) {
    case 'matched':
      return __('Matched', 'yayloyalty');
    case 'no_overlap':
      return __('No match', 'yayloyalty');
    case 'out_of_range':
      return result.actual === undefined
        ? __('Out of range', 'yayloyalty')
        : sprintf(
            /* translators: %s: the customer's actual figure, e.g. lifetime spend. */
            __('Out of range (%s)', 'yayloyalty'),
            String(result.actual),
          );
    case 'unknown_condition_type':
      return __('Unknown condition type', 'yayloyalty');
    case 'unsupported_condition_type':
      return __('This condition type is known but cannot be evaluated yet.', 'yayloyalty');
    case 'no_customer':
      return __('Needs a signed-in customer', 'yayloyalty');
    case 'needs_order':
      return __('Checked against an order — not evaluable in a customer preview', 'yayloyalty');
    case 'unsupported_operator':
      return __('Unsupported operator', 'yayloyalty');
    case 'non_numeric_value':
      return __('Value is not a number', 'yayloyalty');
    case 'malformed_value':
      return __('Value is malformed — re-save the condition', 'yayloyalty');
    default:
      return result.reason;
  }
}
