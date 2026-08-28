import { useNavigate, useParams } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import PagePlaceholder from '@/pages/PagePlaceholder';

const tabs = [
  { value: 'general', label: __('General', 'yayloyalty') },
  { value: 'emails', label: __('Emails', 'yayloyalty') },
  { value: 'advanced', label: __('Advanced', 'yayloyalty') },
];

export default function SettingsPage() {
  const { subMenu = 'general' } = useParams();
  const navigate = useNavigate();

  return (
    <div className="mx-auto max-w-5xl p-6">
      <Tabs value={subMenu} onValueChange={(value) => navigate(`/settings/${value}`)}>
        <TabsList>
          {tabs.map((tab) => (
            <TabsTrigger key={tab.value} value={tab.value}>
              {tab.label}
            </TabsTrigger>
          ))}
        </TabsList>
      </Tabs>

      <div className="-mx-6 mt-4">
        <PagePlaceholder
          title={tabs.find((tab) => tab.value === subMenu)?.label ?? ''}
          description={__('Settings for this section are not implemented yet.', 'yayloyalty')}
        />
      </div>
    </div>
  );
}
