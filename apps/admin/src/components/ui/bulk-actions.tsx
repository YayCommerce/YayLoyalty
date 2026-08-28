import { XIcon } from 'lucide-react';

import { cn } from '@/lib/utils';
import { Button } from './button';

type BulkActionBoxProps = {
  selected: number;
  onClose: () => void;
  children?: React.ReactNode;
  className?: string;
};

const BulkActionBox = ({ selected, onClose, children, className }: BulkActionBoxProps) => {
  return (
    <>
      <div
        className={cn(
          'border-input -my-1.5 items-center gap-2 rounded-lg border p-1 shadow-xs',
          selected > 1 ? 'flex' : 'hidden',
          className,
        )}
      >
        <Button
          variant="ghost"
          size="icon"
          className="hover:text-foreground text-muted-foreground ms-1 size-6 shrink-0 hover:bg-transparent"
          onClick={() => {
            onClose();
          }}
        >
          <XIcon className="size-4 stroke-[2.5px]" />
        </Button>
        {children}
      </div>
    </>
  );
};

export { BulkActionBox };
