export function useCombinedRefs<T>(...refs: (React.ForwardedRef<T> | React.MutableRefObject<T | null>)[]) {
  return (node: T | null) => {
    refs.forEach((r) => {
      if (!r) return;
      if (typeof r === 'function') r(node);
      else (r as React.MutableRefObject<T | null>).current = node;
    });
  };
}
