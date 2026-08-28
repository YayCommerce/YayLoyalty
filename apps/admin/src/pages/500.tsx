import { useNavigate, useRouteError } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';

export default function ErrorPage() {
  const navigate = useNavigate();
  const error = useRouteError() as { message: string };

  return (
    <div className="flex h-[80vh] flex-col items-center justify-center gap-6">
      <div className="text-center">
        <h1 className="text-primary mb-2 text-6xl font-bold">500</h1>
        <h2 className="text-foreground mb-4 text-xl font-semibold">{error?.message || 'Something went wrong'}</h2>
        <p className="text-muted-foreground text-sm">
          {__('Something went wrong. Please try again later.', 'yayloyalty')}
        </p>
      </div>

      <Button variant="primary" onClick={() => navigate('/dashboard')}>
        {__('Back to Dashboard', 'yayloyalty')}
      </Button>
    </div>
  );
}
