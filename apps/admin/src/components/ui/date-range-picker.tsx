import { ComponentProps, ElementRef, forwardRef, useState } from 'react';
import { format } from 'date-fns';
import { ArrowRight, Calendar as CalendarIcon } from 'lucide-react';
import { type DateRange } from 'react-day-picker';

import { cn } from '@/lib/utils';
import { useUncontrolled } from '@/hooks/useUncontrolled';
import { Calendar } from '@/components/ui/calendar';
import { ComboboxIcon, ComboboxTrigger } from '@/components/ui/combobox';
import { Popover, PopoverContent } from '@/components/ui/popover';
import { Button } from './button';

export type DateRangePickerProps = Omit<ComponentProps<typeof ComboboxTrigger>, 'value' | 'defaultValue'> & {
  dayPickerProps?: Omit<ComponentProps<typeof Calendar>, 'mode' | 'selected' | 'onSelect'>;
  value?: DateRange;
  defaultValue?: DateRange;
  onValueChange?: (value: DateRange) => void;
  dateFormat?: string;
  placeholder?: string;
};

export const DateRangePicker = forwardRef<ElementRef<typeof Button>, DateRangePickerProps>(
  (
    {
      value,
      defaultValue,
      onValueChange,
      className,
      dayPickerProps,
      dateFormat = 'PPP',
      placeholder = 'Pick a date range',
      ...props
    },
    ref,
  ) => {
    const [dateRange, setDateRange] = useUncontrolled<DateRange>({
      value: value,
      defaultValue: defaultValue,
      finalValue: { from: undefined, to: undefined },
      onChange: onValueChange,
    });
    const [open, setOpen] = useState(false);

    return (
      <Popover open={open} onOpenChange={setOpen}>
        <ComboboxTrigger ref={ref} className={cn('gap-2 px-3', className)} {...props}>
          <div className="flex items-center gap-2">
            <CalendarIcon />
            <span className="flex-auto overflow-hidden text-start">
              {dateRange?.from ? format(dateRange.from, dateFormat) : placeholder}
            </span>
            {dateRange?.from && dateRange?.to && <ArrowRight />}
            <span className="flex-auto overflow-hidden text-start">
              {dateRange?.to ? format(dateRange.to, dateFormat) : ''}
            </span>
          </div>
          <ComboboxIcon />
        </ComboboxTrigger>
        <PopoverContent className="flex w-auto p-0" side="bottom" align="start" sideOffset={5}>
          <Calendar
            mode="range"
            defaultMonth={dateRange?.from}
            selected={dateRange}
            numberOfMonths={2}
            onSelect={(range) => {
              setDateRange(range ?? { from: undefined, to: undefined });
            }}
            {...dayPickerProps}
          />
        </PopoverContent>
      </Popover>
    );
  },
);
