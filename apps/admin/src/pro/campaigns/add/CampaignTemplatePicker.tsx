import { ChevronLeft } from 'lucide-react';
import { useNavigate } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import PageLayout from '@/components/layout/PageLayout';
import { CampaignTemplate, getCampaignTemplates } from './campaign-templates';

/**
 * "Add a campaign" chooser: pick a template or start from scratch. Each
 * opens the builder in create mode via campaigns/new/:templateId. A static
 * catalog, so there is no loading or error state to render. No "Duplicate a
 * campaign" card — CampaignTable's
 * row menu offers that directly against a specific campaign, which this
 * page has no way to pick one from.
 */
export function CampaignTemplatePicker() {
  const navigate = useNavigate();

  return (
    <>
      <div className="mx-auto flex max-w-6xl items-center gap-2 px-6 pt-6 text-sm">
        <Button type="button" variant="link" className="h-auto gap-1 p-0!" onClick={() => navigate('/campaigns')}>
          <ChevronLeft className="size-3.5" />
          {__('Campaigns', 'yayloyalty')}
        </Button>
      </div>

      <PageLayout
        className="max-w-6xl"
        title={__('Add a campaign', 'yayloyalty')}
        description={__(
          'Start from a template and edit it, or build one from scratch. Nothing is created until you save.',
          'yayloyalty',
        )}
      >
        <div className="grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-3">
          {getCampaignTemplates().map((template) => (
            <CampaignTemplateCard
              key={template.id}
              template={template}
              onSelect={() => navigate(`/campaigns/new/${template.id}`)}
            />
          ))}
        </div>
      </PageLayout>
    </>
  );
}

function CampaignTemplateCard({ template, onSelect }: { template: CampaignTemplate; onSelect: () => void }) {
  return (
    <button type="button" onClick={onSelect} className="flex h-full text-left outline-none">
      <Card className="hover:border-primary focus-visible:border-primary size-full transition-colors">
        <CardHeader>
          <CardTitle className="text-base">{template.title}</CardTitle>
        </CardHeader>
        <CardContent className="flex h-full flex-col gap-3">
          <p className="text-muted-foreground flex-1 text-sm">{template.description}</p>
          <p className="text-foreground border-t pt-3 text-xs">{template.example}</p>
        </CardContent>
      </Card>
    </button>
  );
}
