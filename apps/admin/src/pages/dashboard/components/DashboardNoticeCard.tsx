import { XIcon } from '@phosphor-icons/react';
import { Link } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Can } from '@/lib/access';
import type { DashboardNotice } from '@/lib/schema/dashboard.schema';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { YayLoyaltyToolTip } from '@/components/ui/custom/tooltip';
import type { NoticeAction } from '../dashboard-page.notice-actions';
import type { NoticeCopy } from '../dashboard-page.notice-copy';

/**
 * One notice. Severity is about the customer, not the wording (artboard 6a
 * #1): only `affected` gets the dark left rule and a primary button;
 * `waiting` / `progress` are plain with link-style actions. Actions with a
 * `cap` are hidden from users who lack it.
 */
export function DashboardNoticeCard({
  notice,
  copy,
  onDismiss,
  isDismissing,
}: {
  notice: DashboardNotice;
  copy: NoticeCopy;
  onDismiss: () => void;
  isDismissing: boolean;
}) {
  const affected = notice.severity === 'affected';

  return (
    <div
      role="status"
      className={cn(
        'bg-card relative flex flex-col gap-2 rounded-md border border-l-4 p-4 pr-10',
        affected ? 'border-l-foreground' : 'border-l-muted-foreground/40',
        notice.severity === 'progress' && 'bg-muted/40',
      )}
    >
      <div className="text-sm font-semibold">{copy.title}</div>
      <div className="text-muted-foreground text-xs">{copy.body}</div>
      {(copy.primary || copy.secondary) && (
        <div className="mt-1 flex flex-wrap items-center gap-3">
          {copy.primary && <Action action={copy.primary} primary={affected} />}
          {copy.secondary && <Action action={copy.secondary} primary={false} />}
        </div>
      )}
      <Button
        variant="ghost"
        size="icon-sm"
        className="absolute top-2 right-2"
        aria-label={__('Dismiss', 'yayloyalty')}
        onClick={onDismiss}
        disabled={isDismissing}
      >
        <XIcon />
      </Button>
    </div>
  );
}

function Action({ action, primary }: { action: NoticeAction; primary: boolean }) {
  const control = <ActionControl action={action} primary={primary} />;

  return action.cap ? <Can cap={action.cap}>{control}</Can> : control;
}

function ActionControl({ action, primary }: { action: NoticeAction; primary: boolean }) {
  const variant = primary ? 'primary' : 'link';
  const className = primary ? undefined : 'h-auto p-0 text-xs';

  switch (action.kind) {
    case 'link':
      return action.external ? (
        <Button asChild variant={variant} size="sm" className={className}>
          <a href={action.href} target="_blank" rel="noreferrer">
            {action.label}
          </a>
        </Button>
      ) : (
        <Button asChild variant={variant} size="sm" className={className}>
          <Link to={action.href}>{action.label}</Link>
        </Button>
      );

    case 'button':
      return (
        <Button variant={variant} size="sm" className={className} onClick={action.onClick}>
          {action.label}
        </Button>
      );

    case 'disabled':
      // Disabled controls swallow pointer events, so the tooltip hangs off a wrapping span.
      return (
        <YayLoyaltyToolTip
          content={action.reason}
          trigger={
            <span className="inline-flex" tabIndex={0}>
              <Button variant={variant} size="sm" className={className} disabled>
                {action.label}
              </Button>
            </span>
          }
        />
      );
  }
}
