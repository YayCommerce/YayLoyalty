import { useState } from 'react';
import { format } from 'date-fns';
import { CalendarIcon } from 'lucide-react';
import type { DateRange } from 'react-day-picker';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { YayLoyaltyToolTip } from '@/components/ui/custom/tooltip';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { DATE_RANGE_LABEL, DATE_RANGE_RESET } from '../customer-drawer.copy';

/**
 * A square calendar button that opens a two-month range picker with a Reset. Its tooltip names the chosen range,
 * or says what the button does while no range is set.
 */
export function DrawerDateRangeButton({
  value,
  onValueChange,
}: {
  value: DateRange | undefined;
  onValueChange: (range: DateRange | undefined) => void;
}) {
  const [open, setOpen] = useState(false);
  const label = DATE_RANGE_LABEL();
  const hint = value?.from
    ? [value.from, value.to]
        .filter((date): date is Date => !!date)
        .map((date) => format(date, 'PPP'))
        .join(' → ')
    : label;

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <YayLoyaltyToolTip
        content={hint}
        trigger={
          <PopoverTrigger asChild>
            <Button
              variant="outline"
              size="icon"
              aria-label={label}
              className={cn(value?.from && 'border-primary text-primary')}
            >
              <CalendarIcon />
            </Button>
          </PopoverTrigger>
        }
      />
      <PopoverContent className="flex w-auto flex-col p-0" side="bottom" align="end" sideOffset={5}>
        <Calendar
          mode="range"
          defaultMonth={value?.from}
          selected={value}
          numberOfMonths={2}
          onSelect={onValueChange}
        />
        <div className="flex justify-end border-t p-2">
          <Button
            variant="outline"
            size="sm"
            disabled={!value?.from}
            onClick={() => {
              onValueChange(undefined);
              setOpen(false);
            }}
          >
            {DATE_RANGE_RESET()}
          </Button>
        </div>
      </PopoverContent>
    </Popover>
  );
}
