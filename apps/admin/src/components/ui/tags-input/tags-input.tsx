import {
  ChangeEvent,
  ComponentProps,
  createContext,
  ElementRef,
  FocusEvent,
  forwardRef,
  InputHTMLAttributes,
  KeyboardEvent,
  useContext,
  useRef,
} from 'react';
import clsx from 'clsx';
import { XIcon } from 'lucide-react';

import { cn } from '@/lib/utils';
import { useCombinedRefs } from '@/hooks/useCombinedRefs';
import { useUncontrolled } from '@/hooks/useUncontrolled';
import { ComboboxBadge, ComboboxRemove } from '@/components/ui/combobox';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { buildSplitPattern, mergeTags } from './tags-input.helper';
import { useTagsState } from './useTagsState';

export interface TagsInputProps extends Omit<ComponentProps<typeof InputGroup>, 'children'> {
  value?: string[];
  defaultValue?: string[];
  onValueChange?: (value: string[]) => void;

  inputValue?: string;
  onInputValueChange?: (value: string) => void;

  placeholder?: string;
  addTagOnBlur?: boolean;
  clearable?: boolean;
  splitChars?: string[];
  disabled?: boolean;
  readOnly?: boolean;

  children?: React.ReactNode;
}

const TagsInput = forwardRef<ElementRef<typeof InputGroup>, TagsInputProps>(
  (
    {
      value: controlledValue,
      defaultValue = [],
      onValueChange,
      inputValue: controlledInputValue,
      onInputValueChange,
      placeholder = 'Enter tag',
      addTagOnBlur = true,
      clearable = true,
      splitChars = [','],
      disabled = false,
      readOnly = false,
      children,

      ...props
    },
    ref,
  ) => {
    const [value, setValue] = useUncontrolled({
      value: controlledValue,
      defaultValue,
      onChange: onValueChange,
    });

    const [inputValue, setInputValue] = useUncontrolled({
      value: controlledInputValue,
      defaultValue: '',
      onChange: onInputValueChange,
    });

    const { addTag, removeTag, clearTags } = useTagsState({ value, setValue, setInputValue });

    return (
      <TagsInputContext.Provider
        value={{
          value,
          inputValue,
          addTagOnBlur,
          clearable,
          disabled,
          readOnly,
          splitChars,
          setValue,
          setInputValue,
          addTag,
          removeTag,
          clearTags,
        }}
      >
        {children ? children : <TagsInputTrigger ref={ref} placeholder={placeholder} {...props} />}
      </TagsInputContext.Provider>
    );
  },
);

const TagsInputTrigger = forwardRef<
  ElementRef<typeof InputGroup>,
  ComponentProps<typeof InputGroup> & { placeholder: string }
>((props, ref) => {
  const { value, removeTag, disabled, readOnly } = useTagsInput();
  const { placeholder, 'aria-invalid': ariaInvalid, className, ...restProps } = props;

  return (
    <InputGroup ref={ref} className={clsx('h-auto min-h-9', className)} {...restProps}>
      <div
        data-slot="tags-input-list"
        className={cn('my-1 flex flex-1 flex-wrap items-center gap-1 ps-1', value.length === 0 && 'ps-2')}
      >
        {value.map((tag) => (
          <ComboboxBadge key={tag}>
            {tag}
            <ComboboxRemove
              className="cursor-pointer"
              onRemove={() => {
                if (disabled || readOnly) {
                  return;
                }
                removeTag(tag);
              }}
            />
          </ComboboxBadge>
        ))}

        <TagsInputField className="pl-1" placeholder={placeholder} aria-invalid={ariaInvalid} />
      </div>

      {value.length > 0 && (
        <InputGroupAddon align="inline-end">
          <TagsInputClearButton />
        </InputGroupAddon>
      )}
    </InputGroup>
  );
});

const TagsInputField = forwardRef<HTMLInputElement, Omit<InputHTMLAttributes<HTMLInputElement>, 'value' | 'onChange'>>(
  ({ className, ...props }, ref) => {
    const inputRef = useRef<HTMLInputElement>(null);

    const {
      value,
      disabled,
      readOnly,
      setValue,
      inputValue,
      splitChars,
      addTag,
      removeTag,
      setInputValue,
      addTagOnBlur,
    } = useTagsInput();

    const handleChange = (event: ChangeEvent<HTMLInputElement>) => {
      const nextValue = event.currentTarget.value;
      const splitPattern = buildSplitPattern(splitChars);
      if (!splitPattern) {
        setInputValue(nextValue);
        return;
      }

      if (!splitPattern.test(nextValue)) {
        setInputValue(nextValue);
        return;
      }
      const parts = nextValue.split(splitPattern);
      if (parts.length > 1) {
        setValue(mergeTags(value, parts));
        setInputValue('');
      } else {
        setInputValue(nextValue);
      }
    };

    const handleKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
      const nextValue = event.currentTarget.value;
      if (event.key === 'Enter') {
        event.preventDefault();

        if (!nextValue.trim()) {
          return;
        }

        addTag(nextValue);
        setInputValue('');
        return;
      }

      if (event.key === 'Backspace' && nextValue === '') {
        event.preventDefault();

        const lastTag = value.at(-1);

        if (lastTag) {
          removeTag(lastTag);
        }

        return;
      }

      if (splitChars.includes(event.key)) {
        event.preventDefault();

        if (!nextValue.trim()) {
          return;
        }

        addTag(nextValue);
        setInputValue('');
      }
    };

    const handleBlur = (event: FocusEvent<HTMLInputElement>) => {
      if (!addTagOnBlur) {
        return;
      }
      const nextValue = event.currentTarget.value;
      if (!nextValue.trim()) {
        return;
      }
      addTag(nextValue);
      setInputValue('');
    };

    return (
      <InputGroupInput
        ref={useCombinedRefs(ref, inputRef)}
        className={className}
        placeholder={props.placeholder}
        type="text"
        value={inputValue}
        onChange={handleChange}
        onBlur={handleBlur}
        onKeyDown={handleKeyDown}
        disabled={disabled}
        readOnly={readOnly}
        {...props}
      />
    );
  },
);

function TagsInputClearButton({ className }: { className?: string }) {
  const { value, clearable, clearTags, disabled, readOnly } = useTagsInput();

  if (!clearable || value.length === 0) {
    return null;
  }

  return (
    <button
      type="button"
      tabIndex={-1}
      aria-label="Clear all tags"
      className={cn('group cursor-pointer rounded-sm p-0.5', className)}
      onMouseDown={(event) => {
        event.preventDefault();
        event.stopPropagation();
        if (disabled || readOnly) {
          return;
        }
        clearTags();
      }}
    >
      <XIcon className="text-muted-foreground group-hover:text-destructive size-3.5 stroke-[2.5px] transition-colors" />
    </button>
  );
}

type UseTagsInputReturn = {
  value: string[];
  inputValue: string;
  disabled: boolean;
  readOnly: boolean;
  addTagOnBlur: boolean;
  clearable: boolean;
  splitChars: string[];
  setValue: (value: string[]) => void;
  setInputValue: (value: string) => void;
  addTag: (tag: string) => void;
  removeTag: (tag: string) => void;
  clearTags: () => void;
};

const TagsInputContext = createContext<UseTagsInputReturn | null>(null);

export function useTagsInput() {
  const ctx = useContext(TagsInputContext);
  if (!ctx) throw new Error('TagsInput must be used within provider');
  return ctx;
}

export { TagsInput, TagsInputTrigger, TagsInputField, TagsInputClearButton };
