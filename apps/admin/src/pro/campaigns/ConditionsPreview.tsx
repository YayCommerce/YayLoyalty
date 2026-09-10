import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { previewConditions, type ConditionResult } from './conditions-preview.api';

/**
 * Pro surface for a campaign's eligibility conditions.
 *
 * DELETED FROM THE LITE BUILD along with the rest of `src/pro/`. Lite renders
 * UpgradeToProOverlay in its place, via the ProGate wrapper at the call site.
 *
 * A preview, not an editor. Authoring conditions needs a campaign editor, and
 * the SPA has none — the campaigns page is a read-only list. Shipping a
 * condition-authoring UI with nowhere to save it would be worse than shipping
 * this: the evaluator is what Pro adds, and this is the surface that shows what
 * it decided and why.
 *
 * The "why" is the point. A merchant asking "why didn't this customer earn
 * points?" gets a per-condition answer from the same evaluation path that runs
 * at award time, rather than a bare yes/no they have to trust.
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
 * Turn the evaluator's reason code into something a merchant can act on.
 *
 * `unknown_condition_type` and `unsupported_operator` are configuration errors,
 * not "customer does not qualify" — Pro fails them deliberately (it can
 * evaluate, so an unrecognised type is a real fault), and saying so is the
 * difference between a merchant fixing the campaign and blaming the customer.
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
    case 'unsupported_operator':
      return __('Unsupported operator', 'yayloyalty');
    case 'non_numeric_value':
      return __('Value is not a number', 'yayloyalty');
    default:
      return result.reason;
  }
}
