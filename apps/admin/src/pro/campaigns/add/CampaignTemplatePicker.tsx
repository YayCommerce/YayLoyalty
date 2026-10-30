import { useNavigate } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { BackLink } from '@/components/ui/custom/back-link';
import PageLayout from '@/components/layout/PageLayout';
import { CampaignTemplate, getCampaignTemplates } from './campaign-templates';

/**
 * "Add a campaign" chooser: one card per kind opens the editor in create mode via
 * campaigns/new/:trigger. No Duplicate card: CampaignTable's row menu duplicates a specific
 * campaign, which this page cannot pick.
 */
export function CampaignTemplatePicker() {
  const navigate = useNavigate();

  return (
    <>
      <BackLink to="/campaigns" label={__('Campaigns', 'yayloyalty')} />

      <PageLayout
        className="max-w-6xl"
        title={__('Add a campaign', 'yayloyalty')}
        description={__('Choose what customers earn points for. Nothing is created until you save.', 'yayloyalty')}
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
