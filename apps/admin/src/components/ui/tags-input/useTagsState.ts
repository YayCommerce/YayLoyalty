import { useCallback } from 'react';

import { normalizeTag } from './tags-input.helper';

export type UseTagsStateOptions = {
  value: string[];
  setValue: (value: string[]) => void;
  setInputValue: (value: string) => void;
};

export function useTagsState({ value, setValue, setInputValue }: UseTagsStateOptions) {
  const isSelected = useCallback(
    (item: string) => {
      const normalizedItem = normalizeTag(item);
      return value.some((val) => normalizeTag(val) === normalizedItem);
    },
    [value],
  );

  function addTag(item: string) {
    const trimmed = item.trim();
    if (!trimmed) {
      setInputValue('');
      return;
    }
    const normalized = trimmed.trim().toLowerCase();

    const exists = value.some((item) => item.trim().toLowerCase() === normalized);

    if (exists) {
      setInputValue('');
      return;
    }

    setValue([...value, trimmed]);
    setInputValue('');
  }

  function removeTag(item: string) {
    const normalized = normalizeTag(item);
    const next = value.filter((val) => normalizeTag(val) !== normalized);
    setValue(next.length === value.length ? value : next);
  }

  function clearTags() {
    setValue([]);
    setInputValue('');
  }

  return { isSelected, addTag, removeTag, clearTags };
}
