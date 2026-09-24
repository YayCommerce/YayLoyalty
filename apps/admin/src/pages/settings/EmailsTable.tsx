import { Fragment } from 'react';
import { SendIcon } from 'lucide-react';
import { Controller, useFormContext } from 'react-hook-form';
import { __, sprintf } from '@wordpress/i18n';

import { EmailGroup, EmailsFormValues } from '@/lib/schema/emails.schema';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import { YayLoyaltyToolTip } from '@/components/ui/custom/tooltip';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

interface EmailsTableProps {
  groups: EmailGroup[];
}

/**
 * One grouped table for every email this build registered with WooCommerce; each row's switch
 * edits WooCommerce's own on/off. Card-wrapped the same way DashboardRecentActivity frames its table.
 */
export function EmailsTable({ groups }: EmailsTableProps) {
  const { control } = useFormContext<EmailsFormValues>();

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
                  {group.group_label}
                </TableCell>
              </TableRow>
              {group.items.map((item) => (
                <TableRow key={item.id}>
                  <TableCell>
                    <div className="font-medium">{item.title}</div>
                    <div className="text-muted-foreground text-xs">{item.description}</div>
                  </TableCell>
                  <TableCell className="capitalize">{item.recipient}</TableCell>
                  <TableCell>
                    <Controller
                      name={`emails.${item.id}`}
                      control={control}
                      render={({ field }) => (
                        <Switch checked={field.value} onCheckedChange={field.onChange} aria-label={item.title} />
                      )}
                    />
                  </TableCell>
                  <TableCell>
                    <div className="flex items-center justify-end gap-2">
                      {/* The wrapping span is required: a disabled button emits no pointer events for the tooltip to catch. */}
                      <YayLoyaltyToolTip
                        trigger={
                          <span>
                            <Button
                              variant="outline"
                              size="icon"
                              disabled
                              /* translators: %s: email title */
                              aria-label={sprintf(__('Send a test of %s (coming soon)', 'yayloyalty'), item.title)}
                            >
                              <SendIcon />
                            </Button>
                          </span>
                        }
                        content={__('Send test — coming soon', 'yayloyalty')}
                      />
                      <Button asChild variant="ghost" size="sm">
                        <a
                          href={item.edit_url}
                          target="_blank"
                          rel="noreferrer"
                          /* translators: %s: email title */
                          aria-label={sprintf(__('Edit template for %s', 'yayloyalty'), item.title)}
                        >
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
