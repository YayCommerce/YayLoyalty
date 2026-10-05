import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { __, _n, sprintf } from '@wordpress/i18n';

import { useDismissNoticeMutation } from '@/lib/queries/dashboard.queries';
import {
  DISMISSABLE_NOTICE_KEYS,
  type DashboardNotice,
  type DismissableNoticeKey,
} from '@/lib/schema/dashboard.schema';
import { Button } from '@/components/ui/button';
import { noticeCopy, noticeSummary } from '../dashboard-page.notice-copy';
import { DashboardNoticeCard } from './DashboardNoticeCard';

/** Two at a time; four stacked notices push the dashboard below the fold. */
const SHOWN = 2;

/**
 * The notice strip, in server order (affected → waiting → progress). The
 * first two render as cards; the rest collapse into one line that still
 * says what they are, with "Show all". Dismissal is optimistic; a refusal
 * rolls back and toasts.
 */
export function DashboardNoticeStrip({ notices }: { notices: DashboardNotice[] }) {
  const [expanded, setExpanded] = useState(false);
  const dismiss = useDismissNoticeMutation();

  // Drop anything whose data does not parse rather than crash the page.
  const items = useMemo(
    () => notices.map((notice) => ({ notice, copy: noticeCopy(notice) })).filter((i) => i.copy !== null),
    [notices],
  );

  if (items.length === 0) return null;

  const visible = expanded ? items : items.slice(0, SHOWN);
  const hidden = items.slice(SHOWN);

  const onDismiss = (notice: DashboardNotice) => {
    if (!(DISMISSABLE_NOTICE_KEYS as readonly string[]).includes(notice.key)) {
      // Fixture-only keys never persist; explain instead of hiding, so the preview notice stays on screen.
      toast.info(__('This preview notice cannot be dismissed.', 'yayloyalty'));
      return;
    }

    dismiss.mutate(
      { key: notice.key as DismissableNoticeKey, fingerprint: notice.fingerprint },
      { onError: () => toast.error(__('Could not dismiss the notice. Please try again.', 'yayloyalty')) },
    );
  };

  return (
    <div className="flex flex-col gap-2" aria-label={__('Notices', 'yayloyalty')}>
      {visible.map(({ notice, copy }) => (
        <DashboardNoticeCard
          key={`${notice.key}:${notice.fingerprint}`}
          notice={notice}
          copy={copy!}
          onDismiss={() => onDismiss(notice)}
          isDismissing={dismiss.isPending && dismiss.variables?.key === notice.key}
        />
      ))}
      {hidden.length > 0 && (
        <div className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-1 px-1 text-xs">
          {!expanded && (
            <span>
              {sprintf(
                /* translators: 1: number of hidden notices; 2: what they are, joined with " · ". */
                _n('%1$d more notice — %2$s', '%1$d more notices — %2$s', hidden.length, 'yayloyalty'),
                hidden.length,
                hidden.map(({ notice }) => noticeSummary(notice)).join(' · '),
              )}
            </span>
          )}
          <Button variant="link" size="sm" className="h-auto p-0 text-xs" onClick={() => setExpanded((v) => !v)}>
            {expanded ? __('Show fewer', 'yayloyalty') : __('Show all', 'yayloyalty')}
          </Button>
        </div>
      )}
    </div>
  );
}
