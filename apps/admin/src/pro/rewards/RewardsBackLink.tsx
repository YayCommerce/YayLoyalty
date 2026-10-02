import { ChevronLeft } from 'lucide-react';
import { useNavigate } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';

/** The row above a rewards sub-screen's title: back to the rewards list. type="button" so it never submits a form it sits in. */
export function RewardsBackLink() {
  const navigate = useNavigate();

  return (
    <div className="mx-auto flex max-w-6xl items-center gap-2 px-6 pt-6 text-sm">
      <Button type="button" variant="link" className="h-auto gap-1 p-0!" onClick={() => navigate('/rewards')}>
        <ChevronLeft className="size-3.5" />
        {__('Rewards', 'yayloyalty')}
      </Button>
    </div>
  );
}
