import type { ReactNode } from 'react';
import { ChevronDown, ChevronUp } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

import { parseWPDate } from '@/lib/helpers/format.helper';
import { Badge } from '@/components/ui/badge';
import { Button, LoadingButton } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';

/** The collapsible card Lite edits a built-in campaign or reward in: header with an On/Off switch, fields, then Save/Cancel. */
export function BuiltInConfigCard({
  name,
  subtitle,
  isOn,
  onToggle,
  toggleDisabled,
  expanded,
  onToggleExpanded,
  onSave,
  onCancel,
  saving,
  updatedAt,
  children,
}: {
  name: string;
  subtitle: ReactNode;
  isOn: boolean;
  onToggle: (checked: boolean) => void;
  toggleDisabled?: boolean;
  expanded: boolean;
  onToggleExpanded: () => void;
  onSave?: () => void;
  onCancel: () => void;
  saving?: boolean;
  updatedAt: string;
  children: ReactNode;
}) {
  return (
    <Card className={expanded ? 'border-primary gap-0 p-0' : 'gap-0 p-0'}>
      <CardContent
        role="button"
        tabIndex={0}
        aria-expanded={expanded}
        className="flex cursor-pointer items-center gap-3.5 p-0 px-4.5 py-4"
        onClick={onToggleExpanded}
        onKeyDown={(event) => {
          if (event.target === event.currentTarget && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            onToggleExpanded();
          }
        }}
      >
        <div className="flex flex-1 flex-col gap-0.5">
          <div className="flex items-center gap-2">
            <span className="text-base font-semibold">{name}</span>
            <Badge variant="outline" className="uppercase">
              {__('Default', 'yayloyalty')}
            </Badge>
          </div>
          <span className="text-muted-foreground text-xs">{subtitle}</span>
        </div>

        {/* Its own click target: toggling on/off isn't "open this card". */}
        <div className="flex items-center gap-2" onClick={(event) => event.stopPropagation()}>
          <Switch checked={isOn} disabled={toggleDisabled} onCheckedChange={onToggle} />
          <span className="text-sm">{isOn ? __('On', 'yayloyalty') : __('Off', 'yayloyalty')}</span>
        </div>

        <span className="text-muted-foreground flex size-6 items-center justify-center">
          {expanded ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
        </span>
      </CardContent>

      {expanded && (
        <CardContent className="flex flex-col gap-3.5 border-t p-4.5">
          {children}

          <div className="flex flex-wrap items-center gap-3 border-t pt-4">
            {/* Disabled without onSave, so a card with no save route never looks saved. */}
            <LoadingButton loading={!!saving} disabled={saving || !onSave} onClick={onSave}>
              {__('Save changes', 'yayloyalty')}
            </LoadingButton>
            <Button variant="link" size="sm" className="h-auto p-0" onClick={onCancel}>
              {__('Cancel', 'yayloyalty')}
            </Button>
            <span className="text-muted-foreground ml-auto text-xs">
              {sprintf(
                /* translators: %s: the date this was last saved. */
                __('Last edited %s', 'yayloyalty'),
                parseWPDate(updatedAt),
              )}
            </span>
          </div>
        </CardContent>
      )}
    </Card>
  );
}
