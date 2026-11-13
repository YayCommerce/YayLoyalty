import { __ } from '@wordpress/i18n';

import { CAMPAIGN_CONDITION_TYPES } from '@/lib/schema/campaign.schema';
import { ConditionListEditor } from '../../conditions/ConditionListEditor';

/** Conditions section: the campaign vocabulary in the shared group editor. */
export function CampaignEditorConditions() {
  return (
    <ConditionListEditor
      types={CAMPAIGN_CONDITION_TYPES}
      emptyTitle={__('No conditions — this applies to every order in the date range', 'yayloyalty')}
      emptyDescription={__('Add one to narrow it, for example to a country or a minimum lifetime spend.', 'yayloyalty')}
    />
  );
}
