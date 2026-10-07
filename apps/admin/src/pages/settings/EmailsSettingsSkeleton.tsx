import { Fragment } from 'react';
import { __ } from '@wordpress/i18n';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { EmailsMoreInProCard } from './EmailsMoreInProCard';

// Rows per WooCommerce email group as each build registers them: Lite's points emails; Pro's points, rewards and birthday.
const GROUP_SIZES = IS_PRO_BUILD ? [4, 2, 1] : [3];

/** The Emails page while it loads: the real titles and headers, with every value greyed out. */
export function EmailsSettingsSkeleton() {
  return (
    <div className="flex flex-col gap-4">
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
            {GROUP_SIZES.map((size, group) => (
              <Fragment key={group}>
                <TableRow className="bg-muted/50 hover:bg-muted/50">
                  <TableCell colSpan={4} className="h-8 py-0">
                    <Skeleton tone="page" className="h-3 w-20" />
                  </TableCell>
                </TableRow>
                {Array.from({ length: size }, (_, row) => (
                  <TableRow key={row} className="hover:bg-transparent">
                    <TableCell>
                      <div className="flex flex-col gap-1.5">
                        <Skeleton className="h-3.5 w-36" />
                        <Skeleton className="h-3 w-64" />
                      </div>
                    </TableCell>
                    <TableCell>
                      <Skeleton className="h-3.5 w-16" />
                    </TableCell>
                    <TableCell>
                      <Skeleton className="h-6 w-11 rounded-full" />
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center justify-end gap-2">
                        <Skeleton className="size-9" />
                        <Skeleton className="h-8 w-28" />
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </Fragment>
            ))}
          </TableBody>
        </Table>
      </Card>

      {IS_PRO_BUILD ? null : <EmailsMoreInProCard />}

      <Card className="gap-3">
        <CardHeader>
          <CardTitle>{__('Throttle', 'yayloyalty')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <div className="flex items-center gap-3">
            <Skeleton className="h-9 w-40" />
            <Skeleton className="h-3.5 w-64" />
          </div>
          <Skeleton className="h-3 w-96 max-w-full" />
          <Skeleton className="h-3 w-36" />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{__('Sending', 'yayloyalty')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-2">
          {/* Each Skeleton sits in a text-sm line box so the rows keep the loaded card's height. */}
          {['w-48', 'w-60', 'w-80'].map((width) => (
            <div key={width} className="flex h-5 items-center">
              <Skeleton className={`h-3.5 ${width}`} />
            </div>
          ))}
        </CardContent>
      </Card>

      <div className="flex items-center justify-end gap-2">
        <Skeleton tone="page" className="h-9 w-32" />
      </div>
    </div>
  );
}
