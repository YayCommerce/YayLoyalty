import { ComponentProps, createContext, forwardRef, useContext } from 'react';
import { ChevronDownIcon, ChevronUpIcon } from 'lucide-react';
import { NumericFormat, NumericFormatProps } from 'react-number-format';

import { cn } from '@/lib/utils';
import { useUncontrolled } from '@/hooks/useUncontrolled';
import { InputGroup, InputGroupInput } from './input-group';
import { InputGroupVariantProps } from './variants/input.variants';

interface NumberInputContextValue extends Omit<NumericFormatProps, 'value' | 'onValueChange' | 'className' | 'step'> {
  defaultValue: number;
  /** null only when the root is `nullable` and the field is empty. */
  value: number | null;
  setValue: (value: number | null) => void;
  nullable: boolean;

  step: number;
  min: number;
  max: number;

  handleIncrement: () => void;
  handleDecrement: () => void;
}
const NumberInputContext = createContext<NumberInputContextValue | null>(null);
export const useNumberInputContext = () => {
  const ctx = useContext(NumberInputContext);
  if (!ctx) throw new Error('NumberInput components must be used within NumberInput.Root');
  return ctx;
};

interface NumberInputRootBaseProps extends Omit<
  NumericFormatProps,
  'value' | 'onChange' | 'onValueChange' | 'step' | 'size'
> {
  defaultValue?: number;

  step?: number;
  min?: number;
  max?: number;
  children?: React.ReactNode;
  size?: InputGroupVariantProps['size'];
}

/** `nullable`: the field may be left empty, which reads and emits null (e.g. "no limit"). */
type NumberInputRootProps = NumberInputRootBaseProps &
  (
    | { nullable?: false; value?: number; onValueChange?: (value: number) => void }
    | { nullable: true; value: number | null; onValueChange: (value: number | null) => void }
  );

function NumberInputRoot({
  value: controlledValue,
  defaultValue,
  onValueChange,
  nullable = false,
  step = 1,
  min = -Infinity,
  max = Infinity,
  size,
  fixedDecimalScale = false,
  decimalScale = 0,
  className,
  disabled = false,
  children,
  ...restContextProps
}: NumberInputRootProps & { children?: React.ReactNode }) {
  const _defaultValue = defaultValue ?? (min === -Infinity ? 0 : min);
  const [value, setValue] = useUncontrolled<number | null>({
    value: controlledValue === null && !nullable ? undefined : controlledValue,
    defaultValue: _defaultValue,
    finalValue: 0,
    // Never null unless `nullable`, so a number-only handler never receives one.
    onChange: onValueChange as ((value: number | null) => void) | undefined,
  });

  const handleIncrement = () => {
    if (disabled) return;
    setValue(value === undefined || value === null ? Math.max(step, min) : Math.min(value + step, max));
  };

  const handleDecrement = () => {
    if (disabled) return;
    setValue(value === undefined || value === null ? Math.max(0 - step, min) : Math.max(value - step, min));
  };

  return (
    <NumberInputContext.Provider
      value={{
        defaultValue: defaultValue ?? 0,
        value,
        setValue,
        nullable,
        disabled,
        step,
        min,
        max,
        fixedDecimalScale,
        decimalScale,

        handleIncrement,
        handleDecrement,

        ...restContextProps,
      }}
    >
      <InputGroup size={size} data-disabled={disabled} className={className}>
        {children}
      </InputGroup>
    </NumberInputContext.Provider>
  );
}

type NumberInputInputProps = Omit<
  ComponentProps<'input'>,
  'value' | 'onValueChange' | 'defaultValue' | 'type' | 'min' | 'max'
>;
const NumberInputInput = forwardRef<HTMLInputElement, NumberInputInputProps>(
  ({ className, onBlur, ...inputProps }, ref) => {
    const {
      value,
      setValue,
      nullable,
      defaultValue,
      min,
      disabled,
      max,
      handleIncrement,
      handleDecrement,
      ...contextProps
    } = useNumberInputContext();

    const handleChange = (values: { value: string; floatValue: number | undefined }) => {
      setValue(values.floatValue ?? (nullable ? null : defaultValue));
    };

    const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
      if (e.key === 'ArrowUp') {
        handleIncrement();
      } else if (e.key === 'ArrowDown') {
        handleDecrement();
      }
    };

    const handleBlur = (e: React.FocusEvent<HTMLInputElement>) => {
      const inputEl = (ref as React.RefObject<HTMLInputElement> | null)?.current;

      // An empty nullable field stays empty.
      if (value === null) {
        onBlur?.(e);
        return;
      }

      if (value < min) {
        setValue(min);
        if (inputEl) {
          inputEl.value = String(min);
        }
      } else if (value > max) {
        setValue(max);
        if (inputEl) {
          inputEl.value = String(max);
        }
      }
      onBlur?.(e);
    };

    return (
      <NumericFormat
        data-slot="input-group-control"
        className={cn(
          'peer/number-input-input data-[negative=true]:text-destructive [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none',
          className,
        )}
        min={min}
        max={max}
        value={value ?? ''}
        onValueChange={handleChange}
        onKeyDown={handleKeyDown}
        onBlur={handleBlur}
        disabled={disabled}
        customInput={InputGroupInput}
        getInputRef={ref}
        {...contextProps}
        {...inputProps}
      />
    );
  },
);

interface NumberInputChevronsProps extends ComponentProps<'div'> {
  hasUnit?: boolean;
}
function NumberInputChevrons({ className, children, hasUnit = false, ...props }: NumberInputChevronsProps) {
  return (
    <div
      data-slot="number-input-chevrons"
      className={cn(
        'bg-background absolute inset-y-0 end-0 flex w-6 flex-col rounded-r-sm border-s opacity-0 transition-opacity duration-500 group-hover/input-group:opacity-100 peer-focus/number-input-input:opacity-100',
        hasUnit && 'static rounded-r-none',
        className,
      )}
      {...props}
    >
      {children ?? (
        <>
          <NumberInputChevronUp />
          <NumberInputChevronDown />
        </>
      )}
    </div>
  );
}

function NumberInputChevronUp({ className, children, ...props }: ComponentProps<'span'>) {
  const { handleIncrement } = useNumberInputContext();

  const handleMouseDown = (e: React.MouseEvent<HTMLSpanElement>) => {
    e.preventDefault();
    handleIncrement();
  };

  return (
    <span
      data-slot="number-input-chevron-up"
      onMouseDown={handleMouseDown}
      className={cn(
        'text-muted-foreground-400 hover:text-accent-foreground hover:bg-accent inline-flex flex-1 cursor-pointer items-center justify-center rounded-tr-[inherit] transition-all select-none',
        className,
      )}
      {...props}
    >
      {children ?? <ChevronUpIcon className="size-3" />}
    </span>
  );
}

function NumberInputChevronDown({ className, children, ...props }: ComponentProps<'span'>) {
  const { handleDecrement } = useNumberInputContext();

  const handleMouseDown = (e: React.MouseEvent<HTMLSpanElement>) => {
    e.preventDefault();
    handleDecrement();
  };

  return (
    <span
      data-slot="number-input-chevron-down"
      onMouseDown={handleMouseDown}
      className={cn(
        'text-muted-foreground-400 hover:text-accent-foreground hover:bg-accent inline-flex flex-1 cursor-pointer items-center justify-center rounded-br-[inherit] border-t transition-all select-none',
        className,
      )}
      {...props}
    >
      {children ?? <ChevronDownIcon className="size-3" />}
    </span>
  );
}

interface NumberInputUnitProps extends ComponentProps<'span'> {
  unit: string;
}
function NumberInputUnit({ className, children, unit, ...props }: NumberInputUnitProps) {
  return (
    <span
      data-slot="number-input-unit"
      className={cn(
        'bg-muted text-muted-foreground flex h-full items-center justify-center rounded-r-sm border-s px-3',
        className,
      )}
      {...props}
    >
      {children ?? <span>{unit}</span>}
    </span>
  );
}

type NumberInputProps = NumberInputRootProps & {
  inputProps?: Omit<NumberInputInputProps, 'size'> & { size: InputGroupVariantProps['size'] };
};
const NumberInput = forwardRef<HTMLInputElement, NumberInputProps>(({ inputProps, ...rootProps }, ref) => {
  const { size, ..._inputProps } = inputProps ?? {};

  return (
    <NumberInputRoot size={size} {...rootProps}>
      <NumberInputInput ref={ref} {..._inputProps} />
      <NumberInputChevrons />
    </NumberInputRoot>
  );
});

export {
  NumberInput,
  NumberInputRoot,
  NumberInputInput,
  NumberInputChevrons,
  NumberInputChevronUp,
  NumberInputChevronDown,
  NumberInputUnit,
};
