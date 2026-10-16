import { ChevronLeft } from 'lucide-react';

import { useNavigate } from '@/lib/router';
import { Button } from '@/components/ui/button';

/** The row above a sub-screen's title: back to its list. type="button" so it never submits a form it sits in. */
export function BackLink({ to, label }: { to: string; label: string }) {
  const navigate = useNavigate();

  return (
    <div className="mx-auto flex max-w-6xl items-center gap-2 px-6 pt-6 text-sm">
      <Button type="button" variant="link" className="h-auto gap-1 p-0!" onClick={() => navigate(to)}>
        <ChevronLeft className="size-3.5" />
        {label}
      </Button>
    </div>
  );
}
