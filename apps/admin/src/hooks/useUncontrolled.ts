/* eslint-disable @typescript-eslint/no-explicit-any -- copied from Mantine, whose onChange forwards an untyped payload */
import { useState } from 'react';

/** State that is either controlled or uncontrolled, for custom inputs. Copied from Mantine hooks. */

export interface UseUncontrolledOptions<T> {
  value?: T;
  defaultValue?: T;
  /** Used when neither `value` nor `defaultValue` is given. */
  finalValue?: T;
  onChange?: (value: T, ...payload: any[]) => void;
}

export type UseUncontrolledReturnValue<T> = [
  T,
  (value: T, ...payload: any[]) => void,
  /** True when the state is controlled. */
  boolean,
];

export function useUncontrolled<T>({
  value,
  defaultValue,
  finalValue,
  onChange = () => {},
}: UseUncontrolledOptions<T>): UseUncontrolledReturnValue<T> {
  const [uncontrolledValue, setUncontrolledValue] = useState(defaultValue !== undefined ? defaultValue : finalValue);

  const handleUncontrolledChange = (val: T, ...payload: any[]) => {
    setUncontrolledValue(val);
    onChange?.(val, ...payload);
  };

  if (value !== undefined) {
    return [value as T, onChange, true];
  }

  return [uncontrolledValue as T, handleUncontrolledChange, false];
}
