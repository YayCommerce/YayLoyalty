import { Link } from 'react-router';

import { useCsvExport } from '@/hooks/useCsvExport';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import PageLayout from '@/components/layout/PageLayout';
import { exportCustomersToCsv } from '../customers/customers-page.export';
import { DEFAULT_CUSTOMER_LIST_FILTERS } from '../customers/customers-page.filters';
import { fetchAllMatchingCustomers } from '../customers/customers-page.matching-customers';
import * as copy from './tools-page.copy';
import { ToolsImportCard } from './ToolsImportCard';

/** UX-3 "Tools page": Import balances, plus the Customers page's export and bulk adjust, reached from here. */
export default function ToolsPage() {
  const { isExporting, start: handleExport } = useCsvExport({
    fetchRows: (signal) =>
      fetchAllMatchingCustomers(DEFAULT_CUSTOMER_LIST_FILTERS, { by: 'balance', dir: 'desc' }, signal),
    download: exportCustomersToCsv,
    emptyMessage: copy.exportEmpty,
    errorFallback: copy.exportFailed,
  });

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
            <Button variant="outline" onClick={() => void handleExport()} disabled={isExporting}>
              {copy.exportButton()}
            </Button>
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
