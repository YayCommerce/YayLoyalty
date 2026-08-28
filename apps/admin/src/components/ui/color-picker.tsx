import './color-picker.css';

import {
  ComponentProps,
  createContext,
  ElementRef,
  forwardRef,
  InputHTMLAttributes,
  useContext,
  useState,
} from 'react';
import { Check } from 'lucide-react';
import { HexAlphaColorPicker, HexColorInput, HexColorPicker } from 'react-colorful';
import { useDebounceFn } from 'rooks';

import { cn } from '@/lib/utils';
import { useUncontrolled } from '@/hooks/useUncontrolled';
import { InputGroup, InputGroupAddon } from '@/components/ui/input-group';
import { Popover, PopoverContent } from '@/components/ui/popover';
import { ComboboxTrigger } from './combobox';
import { CopyButton } from './copy-button';
import { InputVariantProps, inputVariants } from './variants/input.variants';

export type ColorPickerProps = {
  value?: string;
  defaultValue?: string;
  onValueChange?: (value: string) => void;
  children?: React.ReactNode;
};

export function ColorPicker({
  value: controlledValue,
  defaultValue = '#000000',
  onValueChange,
  children,
}: ColorPickerProps) {
  const [value, setValue] = useUncontrolled({
    value: controlledValue === null ? undefined : controlledValue,
    defaultValue: defaultValue,
    onChange: onValueChange,
  });
  const [debouncedSetValue] = useDebounceFn(setValue, 200, { trailing: true, maxWait: 500 });
  const [open, setOpen] = useState(false);
  return (
    <ColorPickerContext.Provider
      value={{
        value,
        defaultValue,
        setValue,
        debouncedSetValue,
        open,
        setOpen,
      }}
    >
      <Popover open={open} onOpenChange={setOpen}>
        {children ? (
          children
        ) : (
          <>
            <ColorPickerTrigger />
            <ColorPickerContent>
              <ColorPickerPanel />
              <ColorPickerInput />
            </ColorPickerContent>
          </>
        )}
      </Popover>
    </ColorPickerContext.Provider>
  );
}

export const ColorPickerTrigger = forwardRef<
  ElementRef<typeof ComboboxTrigger>,
  ComponentProps<typeof ComboboxTrigger>
>(({ className, children, ...props }, ref) => {
  const { value } = useColorPicker();

  return (
    <ComboboxTrigger
      ref={ref}
      className={cn('min-h-0 min-w-0 justify-start gap-2 py-1 pr-4 pl-1', className)}
      {...props}
    >
      {children ? (
        children
      ) : (
        <>
          <span className="border-accent size-6.5 rounded-xs border" style={{ backgroundColor: value }} />
          <span className="overflow-hidden">{value}</span>
        </>
      )}
    </ComboboxTrigger>
  );
});

export const ColorPickerContent = forwardRef<ElementRef<typeof PopoverContent>, ComponentProps<typeof PopoverContent>>(
  ({ className, ...props }, ref) => {
    return (
      <PopoverContent
        ref={ref}
        className={cn('yayui-color-picker flex max-h-160 min-w-0 flex-col gap-3 rounded-sm p-3', className)}
        side="bottom"
        align="start"
        sideOffset={5}
        {...props}
      />
    );
  },
);

export function ColorPickerPanel({ alpha = false }: { alpha?: boolean }) {
  const { value, debouncedSetValue } = useColorPicker();

  if (alpha) {
    return <HexAlphaColorPicker color={value} onChange={debouncedSetValue} />;
  } else {
    return <HexColorPicker color={value} onChange={debouncedSetValue} />;
  }
}

type ColorPickerInputProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'size' | 'value' | 'onChange'> &
  InputVariantProps & { alpha?: boolean };

export function ColorPickerInput({ className, variant = 'input', alpha = false, ...props }: ColorPickerInputProps) {
  const { value, debouncedSetValue } = useColorPicker();

  return (
    <InputGroup size="medium">
      <HexColorInput
        color={value}
        onChange={debouncedSetValue}
        data-slot="input-group-control"
        autoFocus
        prefixed
        alpha={alpha}
        className={cn(
          inputVariants({ variant: variant }),
          'mx-2 w-30 flex-1 rounded-none border-0 bg-transparent shadow-none focus-visible:ring-0 focus-visible:ring-offset-0 dark:bg-transparent',
          className,
        )}
        {...props}
      />
      <InputGroupAddon align="inline-end">
        <CopyButton
          content={value}
          className="size-6 rounded-[calc(var(--radius-md)-4px)] p-0 has-[>svg]:p-0 [&_svg]:size-3"
        />
      </InputGroupAddon>
    </InputGroup>
  );
}

export function ColorPickerSwatch({
  className,
  style,
  swatchValue,
  ...props
}: ComponentProps<'span'> & { swatchValue: string }) {
  const { value, setValue } = useColorPicker();

  return (
    <span
      className={cn(
        'relative inline-flex size-5.5 shrink-0 items-center justify-center overflow-hidden rounded-full border border-black/10',
        className,
      )}
      style={{ ...style, backgroundColor: swatchValue }}
      onClick={() => {
        setValue(swatchValue);
      }}
      {...props}
    >
      {value === swatchValue && <Check className="shadow-base size-2.5 text-white" strokeWidth="4" />}
    </span>
  );
}

type UseColorPickerReturn = {
  value: string;
  defaultValue: string;
  setValue: (value: string) => void;
  debouncedSetValue: (value: string) => void;
  open: boolean;
  setOpen: (open: boolean) => void;
};

const ColorPickerContext = createContext<UseColorPickerReturn>({
  value: '#000000',
  defaultValue: '#000000',
  setValue: () => {},
  debouncedSetValue: () => {},
  open: false,
  setOpen: () => {},
});

export const useColorPicker = () => {
  return useContext(ColorPickerContext);
};
