import { createContext, ReactNode, useContext, useMemo, useState } from 'react';

import type { Design } from '@/lib/schema/design.schema';

export type StorefrontTextKey = keyof Design['storefront_text'];

type PreviewHighlight = {
  /** The text to outline in the preview: the hovered field, else the focused one. */
  highlighted: StorefrontTextKey | null;
  setHovered: (key: StorefrontTextKey | null) => void;
  setFocused: (key: StorefrontTextKey | null) => void;
};

const PreviewHighlightContext = createContext<PreviewHighlight>({
  highlighted: null,
  setHovered: () => {},
  setFocused: () => {},
});

/** Shares the Storefront text field under the pointer or in focus with the launcher preview. */
export function PreviewHighlightProvider({ children }: { children: ReactNode }) {
  const [hovered, setHovered] = useState<StorefrontTextKey | null>(null);
  const [focused, setFocused] = useState<StorefrontTextKey | null>(null);
  const value = useMemo(() => ({ highlighted: hovered ?? focused, setHovered, setFocused }), [hovered, focused]);

  return <PreviewHighlightContext.Provider value={value}>{children}</PreviewHighlightContext.Provider>;
}

export function usePreviewHighlight(): PreviewHighlight {
  return useContext(PreviewHighlightContext);
}
