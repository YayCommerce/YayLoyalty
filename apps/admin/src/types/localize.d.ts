import { AdminData, Meta } from './localize.type';

declare global {
  interface Window {
    yayLoyaltyMeta: Meta;
    yayLoyaltyAdmin: AdminData;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any -- WordPress's global ships without types
    wp: any;
  }
}

declare module '@tanstack/react-table' {
  interface ColumnMeta<TData, TValue> {
    align?: 'left' | 'center' | 'right';
    isCheckbox?: boolean;
  }
}

declare module 'rooks' {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any -- a constraint that accepts every function signature
  type AnyFunction = (...args: any[]) => any;
  type DebounceOptions = {
    leading?: boolean;
    trailing?: boolean;
    maxWait?: number;
  };

  declare function useDebounceFn<F extends AnyFunction>(
    func: F,
    delay: number,
    options?: DebounceOptions,
  ): [(...args: Parameters<F>) => void, boolean];
}

export {};
