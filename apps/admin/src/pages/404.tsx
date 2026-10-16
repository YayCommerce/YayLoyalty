import { __ } from '@wordpress/i18n';

import { useNavigate } from '@/lib/router';
import { Button } from '@/components/ui/button';

export default function NotFoundPage() {
  const navigate = useNavigate();

  return (
    <div className="flex h-[80vh] flex-col items-center justify-center gap-6">
      <div className="text-center">
        <h1 className="text-primary mb-2 text-6xl font-bold">404</h1>
        <h2 className="text-foreground mb-4 text-xl font-semibold">Page Not Found</h2>
        <p className="text-muted-foreground text-sm">
          {__("The page you are looking for doesn't exist or has been moved.", 'yayloyalty')}
        </p>
      </div>

      <Button variant="primary" onClick={() => navigate('/dashboard')}>
        {__('Back to Dashboard', 'yayloyalty')}
      </Button>
    </div>
  );
}
