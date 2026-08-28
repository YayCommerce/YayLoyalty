import * as React from 'react';
import { Slot } from '@radix-ui/react-slot';
import { useLocation } from 'react-router';

import { cn } from '@/lib/utils';
import { useCombinedRefs } from '@/hooks/useCombinedRefs';

const HeaderNavMenuList = React.forwardRef<HTMLUListElement, HeaderNavMenuListProps>(
  ({ mode = 'router', activeTab, className, asChild = false, children, ...props }, ref) => {
    const Comp = asChild ? Slot : 'ul';
    const listRef = React.useRef<HTMLUListElement>(null);
    const combinedRef = useCombinedRefs(ref, listRef);
    const indicatorRef = React.useRef<HTMLDivElement>(null);
    const { pathname } = useLocation();

    React.useLayoutEffect(() => {
      if (mode !== 'router' || !listRef.current || !indicatorRef.current) return;

      const activeItemElement = listRef.current.querySelector(
        `[aria-current="page"][data-slot="header-navigation-menu-item"]`,
      );
      if (activeItemElement && activeItemElement instanceof HTMLElement) {
        const { clientWidth, offsetLeft } = activeItemElement;
        indicatorRef.current.style.left = `${offsetLeft}px`;
        indicatorRef.current.style.width = `${clientWidth}px`;
      } else {
        indicatorRef.current.style.left = '0px';
        indicatorRef.current.style.width = '0px';
      }
    }, [pathname, mode]);

    React.useLayoutEffect(() => {
      if (mode !== 'tabs' || !listRef.current || !indicatorRef.current) return;

      const activeItemElement = listRef.current.querySelector(
        `[data-state="active"][data-slot="header-navigation-menu-item"]`,
      );
      if (activeItemElement && activeItemElement instanceof HTMLElement) {
        const { clientWidth, offsetLeft } = activeItemElement;
        indicatorRef.current.style.left = `${offsetLeft}px`;
        indicatorRef.current.style.width = `${clientWidth}px`;
      } else {
        indicatorRef.current.style.left = '0px';
        indicatorRef.current.style.width = '0px';
      }
    }, [activeTab, mode]);

    return (
      <Comp
        data-slot="header-navigation-menu-list"
        className={cn('relative flex h-13.5 flex-1 list-none items-stretch justify-start gap-0', className)}
        {...props}
        ref={combinedRef}
      >
        {children}

        <div
          data-slot="header-navigation-menu-indicator"
          className="bg-foreground absolute bottom-0 h-0.75"
          ref={indicatorRef}
          style={{
            transition: 'width 0.3s, left 0.3s',
          }}
        />
      </Comp>
    );
  },
);

interface HeaderNavMenuItemProps extends React.ComponentProps<'li'> {
  asChild?: boolean;
}

const HeaderNavMenuItem = React.forwardRef<HTMLLIElement, HeaderNavMenuItemProps>(
  ({ className, asChild = false, ...props }, ref) => {
    const Comp = asChild ? Slot : 'li';
    return (
      <Comp
        data-slot="header-navigation-menu-item"
        className={cn(
          'text-foreground hover:border-border flex cursor-default items-center justify-center gap-1.5 border-b-3 border-solid border-transparent px-5 text-sm shadow-none transition-colors outline-none hover:cursor-pointer',
          'aria-[current=page]:text-shadow-[0_0_0.05px_currentcolor] data-[state=active]:text-shadow-[0_0_0.05px_currentcolor]',
          className,
        )}
        {...props}
        ref={ref}
      />
    );
  },
);

type NavMode =
  | {
      mode?: 'router';
      activeTab?: undefined;
    }
  | {
      mode: 'tabs';
      activeTab: string;
    };

type HeaderNavMenuListProps = React.ComponentProps<'ul'> &
  NavMode & {
    asChild?: boolean;
  };

export { HeaderNavMenuList, HeaderNavMenuItem };
