import clsx from 'clsx';
import { CircleHelpIcon } from 'lucide-react';

import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';

interface YayLoyaltyToolTipProps {
  className?: string;
  trigger?: React.ReactNode;
  content: React.ReactNode;
  side?: 'top' | 'bottom' | 'left' | 'right';
}

export function YayLoyaltyToolTip({
  trigger = <CircleHelpIcon color="#A0A0A7" strokeWidth={1.5} className="size-4" />,
  content,
  className,
  side = 'top',
}: YayLoyaltyToolTipProps) {
  return (
    <TooltipProvider>
      <Tooltip>
        <TooltipTrigger asChild>{trigger}</TooltipTrigger>
        <TooltipContent
          side={side}
          className={clsx('z-100000 rounded-[4px] p-0', undefined === content && 'hidden', className)}
        >
          <div className="w-fit p-2.5 text-center text-[12px] leading-[100%]">{content}</div>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  );
}
