import { useEffect, useState } from 'react';

import { cn } from '@/lib/utils';
import { HeaderMenu } from './HeaderMenu';

/** Just below the WP admin bar: 46px tall below md, 32px from md. Shared by every bar that sticks under it. */
export const BELOW_ADMIN_BAR = 'top-11.5 md:top-8';

export default function Header() {
  const scrolled = useScrolled();
  return (
    <header
      className={cn(
        'bg-background relative flex h-13.5 items-center justify-between gap-0 py-0 pr-3 lg:pr-6',
        BELOW_ADMIN_BAR,
        // Narrow screens scroll the admin bar away, so a scrolled header rises to the top.
        scrolled ? 'shadow-[0_8px_8px_0_rgba(85,93,102,0.3)] max-sm:top-0' : 'shadow-none',
        'z-10 md:pr-3',
      )}
    >
      <div className="border-border flex h-full bg-[#FFF5DB] px-0.75 pt-0.75">
        <img
          src={`${window.yayLoyaltyMeta.loyaltyMeta.assetsUrl}/images/logo/favicon.svg`}
          alt="YayLoyalty"
          className="size-12.5"
        />
      </div>

      <HeaderMenu />

      <div className="flex items-center">
        <div id="yayloyalty-header-actions" />
      </div>
    </header>
  );
}

function useScrolled(threshold = 0) {
  const [scrolled, setScrolled] = useState(false);
  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > threshold);
    window.addEventListener('scroll', onScroll);
    return () => window.removeEventListener('scroll', onScroll);
  }, [threshold]);
  return scrolled;
}
