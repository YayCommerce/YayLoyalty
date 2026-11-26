import { useState } from 'react';
import { Gift } from 'lucide-react';
import { useFormContext, useWatch } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import { pointsPhrase } from '@/lib/helpers/points.helper';
import { Reward } from '@/lib/schema/reward.schema';
import { EditorSaveIntent } from '@/hooks/useEditorSave';
import { Button, LoadingButton } from '@/components/ui/button';
import { rewardEditorActions, RewardFormValues } from './reward-editor.form';

/**
 * The checkout preview, then Archive on the left and Cancel, Save as draft and Save on the right.
 * Both saves are type="submit" so the resolver runs for a draft too; data-intent says which was pressed.
 */
export function RewardEditorModalFooter({
  reward,
  busy,
  onDraftIntent,
  onArchive,
  onCancel,
}: {
  /** Null while creating. */
  reward: Reward | null;
  busy: boolean;
  onDraftIntent: () => void;
  onArchive?: () => void;
  onCancel: () => void;
}) {
  const { control, formState } = useFormContext<RewardFormValues>();
  const name = useWatch({ control, name: 'name' });
  const costPoints = useWatch({ control, name: 'cost_points' });
  const { canSave, canSaveDraft, showArchive } = rewardEditorActions(reward, formState.isDirty);
  // Which save was pressed, so only that button spins. The leave dialog's save counts as Save.
  const [pressed, setPressed] = useState<EditorSaveIntent>('save');

  return (
    <div className="flex flex-col">
      <div className="bg-muted/40 flex flex-col gap-2 border-t px-6 py-3">
        <span className="text-muted-foreground text-xs">{__('Checkout preview', 'yayloyalty')}</span>
        <div className="bg-background flex items-center gap-2.5 rounded-lg border px-3 py-2.5">
          <Gift className="text-primary size-4 shrink-0" aria-hidden />
          <span className="truncate text-sm font-medium">{name || __('Reward name', 'yayloyalty')}</span>
          {costPoints !== null && (
            <span className="bg-muted ms-auto shrink-0 rounded-full px-2.5 py-0.5 text-xs tabular-nums">
              {pointsPhrase(costPoints)}
            </span>
          )}
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-2 border-t px-6 py-4">
        {showArchive && onArchive && (
          // type="button": archiving is the dialog's decision, never a form save.
          <Button
            type="button"
            variant="link"
            className="text-destructive hover:text-destructive-accent px-0"
            disabled={busy}
            onClick={onArchive}
          >
            {__('Archive reward', 'yayloyalty')}
          </Button>
        )}
        <div className="ms-auto flex flex-wrap items-center gap-2">
          <Button type="button" variant="outline" disabled={busy} onClick={onCancel}>
            {__('Cancel', 'yayloyalty')}
          </Button>
          {canSaveDraft && (
            <LoadingButton
              type="submit"
              data-intent="draft"
              variant="inverse"
              loading={busy && pressed === 'draft'}
              disabled={busy || !canSave}
              onClick={() => {
                setPressed('draft');
                onDraftIntent();
              }}
            >
              {__('Save as draft', 'yayloyalty')}
            </LoadingButton>
          )}
          <LoadingButton
            type="submit"
            loading={busy && pressed === 'save'}
            disabled={busy || !canSave}
            onClick={() => setPressed('save')}
          >
            {__('Save reward', 'yayloyalty')}
          </LoadingButton>
        </div>
      </div>
    </div>
  );
}
