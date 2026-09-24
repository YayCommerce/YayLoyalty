import { Fragment } from 'react';
import { Controller, useFormContext } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { EmailGroup, EmailItem, EmailsFormValues } from '@/lib/schema/emails.schema';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import { YayLoyaltyToolTip } from '@/components/ui/custom/tooltip';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ProGate } from '@/components/pro/ProGate';

interface EmailsTableProps {
  groups: EmailGroup[];
  wcEmailsUrl: string;
}

/**
 * One grouped table for every email (Free + Pro), matching the design — no separate Free/Pro
 * tables. A row is editable when `item.isFree` (server-computed) or this is a Pro build; a
 * Pro-only row in the Lite build renders disabled with an inline upgrade badge next to its label.
 * Card-wrapped the same way DashboardRecentActivity frames its table.
 */
export function EmailsTable({ groups, wcEmailsUrl }: EmailsTableProps) {
  const { control } = useFormContext<EmailsFormValues>();

  function isEditable(item: EmailItem): boolean {
    return item.isFree || IS_PRO_BUILD;
  }

  return (
    <Card className="gap-3">
      <CardHeader>
        <CardTitle>{__('Emails', 'yayloyalty')}</CardTitle>
      </CardHeader>
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{__('Email', 'yayloyalty')}</TableHead>
            <TableHead>{__('Goes to', 'yayloyalty')}</TableHead>
            <TableHead>{__('Status', 'yayloyalty')}</TableHead>
            <TableHead className="text-right">{__('Test and template', 'yayloyalty')}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {groups.map((group) => (
            <Fragment key={group.group}>
              <TableRow className="hover:bg-transparent">
                <TableCell colSpan={4} className="text-muted-foreground text-xs font-semibold uppercase">
                  {group.groupLabel}
                </TableCell>
              </TableRow>
              {group.items.map((item) => (
                <TableRow key={item.id}>
                  <TableCell>
                    <div className="flex items-center gap-1.5 font-medium">
                      {item.label}
                      {/* Static id-based special case: becomes data-driven once tier variants exist. */}
                      {'tier_reached' === item.id && (
                        <span className="text-muted-foreground text-xs font-normal">
                          {__('(3 variants)', 'yayloyalty')}
                        </span>
                      )}
                      {!isEditable(item) && <ProGate inline />}
                    </div>
                    <div className="text-muted-foreground text-xs">{item.description}</div>
                  </TableCell>
                  <TableCell className="capitalize">{item.recipient}</TableCell>
                  <TableCell>
                    <Controller
                      name={`emails.${item.id}`}
                      control={control}
                      render={({ field }) => (
                        <Switch
                          checked={field.value ?? item.enabled}
                          onCheckedChange={field.onChange}
                          disabled={!isEditable(item)}
                          aria-label={item.label}
                        />
                      )}
                    />
                  </TableCell>
                  <TableCell>
                    <div className="flex items-center justify-end gap-2">
                      {/* The wrapping span is required: a disabled button emits no pointer events for the tooltip to catch. */}
                      <YayLoyaltyToolTip
                        trigger={
                          <span>
                            <Button variant="outline" size="sm" disabled>
                              {__('Send test', 'yayloyalty')}
                            </Button>
                          </span>
                        }
                        content={__('Coming soon', 'yayloyalty')}
                      />
                      {/* Generic WC Emails list: no section anchor until a WC_Email class is registered for this id. */}
                      <Button asChild variant="ghost" size="sm">
                        <a href={wcEmailsUrl} target="_blank" rel="noreferrer">
                          {__('Edit template', 'yayloyalty')}
                        </a>
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </Fragment>
          ))}
        </TableBody>
      </Table>
    </Card>
  );
}
