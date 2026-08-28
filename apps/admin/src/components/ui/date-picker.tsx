import { ComponentProps, useState } from 'react';
import { format } from 'date-fns';
import { Calendar as CalendarIcon } from 'lucide-react';

import { useUncontrolled } from '@/hooks/useUncontrolled';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent } from '@/components/ui/popover';
import { ComboboxIcon, ComboboxTrigger } from './combobox';

export type DatePickerProps = Omit<ComponentProps<typeof ComboboxTrigger>, 'value' | 'defaultValue'> & {
  value?: Date | undefined;
  defaultValue?: Date | undefined;
  onValueChange?: (value: Date | undefined) => void;
  placeholder?: string;
};

export function DatePicker({
  value,
  defaultValue,
  onValueChange,
  placeholder = 'Pick a date',
  ...props
}: DatePickerProps) {
  const [date, setDate] = useUncontrolled<Date | undefined>({
    value,
    defaultValue: defaultValue,
    onChange: onValueChange,
  });
  const [open, setOpen] = useState(false);
  return (
    <Popover open={open} onOpenChange={setOpen}>
      <ComboboxTrigger {...props}>
        <div className="flex items-center gap-2">
          <CalendarIcon />
          {date ? format(date, 'PPP') : <span>{placeholder}</span>}
        </div>
        <ComboboxIcon />
      </ComboboxTrigger>
      <PopoverContent side="bottom" align="start" sideOffset={5} className="w-auto min-w-0 overflow-hidden p-0">
        <Calendar
          mode="single"
          selected={date}
          onSelect={(date) => {
            setDate(date ?? defaultValue);
            setOpen(false);
          }}
          required={false}
        />
      </PopoverContent>
    </Popover>
  );
}
