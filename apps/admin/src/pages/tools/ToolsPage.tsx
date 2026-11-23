import { Link } from 'react-router';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import PageLayout from '@/components/layout/PageLayout';
import { CustomersExportButton } from '../customers/components/CustomersExportButton';
import { DEFAULT_CUSTOMER_LIST_FILTERS } from '../customers/customers-page.filters';
import * as copy from './tools-page.copy';
import { ToolsImportCard } from './ToolsImportCard';

/** Tools: Import balances, plus the Customers page's export and bulk adjust, reached from here. */
export default function ToolsPage() {
  return (
    <PageLayout title={copy.toolsTitle()} description={copy.toolsDescription()}>
      <ToolsImportCard />
      <div className="grid gap-4 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>{copy.exportTitle()}</CardTitle>
            <CardDescription>{copy.exportDescription()}</CardDescription>
          </CardHeader>
          <CardContent>
            <CustomersExportButton
              disabled={false}
              queryFilters={DEFAULT_CUSTOMER_LIST_FILTERS}
              sort={{ by: 'balance', dir: 'desc' }}
            />
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>{copy.adjustTitle()}</CardTitle>
            <CardDescription>{copy.adjustDescription()}</CardDescription>
          </CardHeader>
          <CardContent>
            <Button variant="outline" asChild>
              <Link to="/customers">{copy.adjustButton()}</Link>
            </Button>
          </CardContent>
        </Card>
      </div>
    </PageLayout>
  );
}
