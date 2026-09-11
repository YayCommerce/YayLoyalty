import { ReactNode } from 'react';

import { Card, CardContent } from '@/components/ui/card';
import PageLayout from '@/components/layout/PageLayout';

/** Shared scaffold placeholder until each feature screen is implemented. */
export default function PagePlaceholder({
  title,
  description,
  children,
}: {
  title: string;
  description: string;
  children?: ReactNode;
}) {
  return (
    <PageLayout title={title} description={description}>
      {children ? (
        <Card>
          <CardContent>{children}</CardContent>
        </Card>
      ) : null}
    </PageLayout>
  );
}
