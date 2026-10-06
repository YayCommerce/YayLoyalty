import { AlertTriangle, Ban, ServerCrash, WifiOff } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';

/** The kind of failure. Today it only picks the icon. */
export type ErrorStateKind = 'generic' | 'network' | 'not-found' | 'forbidden' | 'server';

const KIND_ICON: Record<ErrorStateKind, typeof AlertTriangle> = {
  generic: AlertTriangle,
  network: WifiOff,
  'not-found': Ban,
  forbidden: Ban,
  server: ServerCrash,
};

/**
 * Shared inline banner for a failed query or mutation. No caller passes `kind` yet, since none
 * reads the underlying error. `onRetry` adds a retry action.
 *
 * TODO(error-state): move CampaignTable, BuiltInCampaignList and ConditionsPreview onto this.
 */
export function ErrorState({
  message,
  kind = 'generic',
  onRetry,
  className,
}: {
  message: string;
  kind?: ErrorStateKind;
  onRetry?: () => void;
  className?: string;
}) {
  const Icon = KIND_ICON[kind];

  return (
    <Alert destructive className={className}>
      <Icon />
      <AlertDescription className="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span>{message}</span>
        {onRetry && (
          <Button variant="link" size="sm" className="text-destructive h-auto p-0" onClick={onRetry}>
            {__('Try again', 'yayloyalty')}
          </Button>
        )}
      </AlertDescription>
    </Alert>
  );
}
