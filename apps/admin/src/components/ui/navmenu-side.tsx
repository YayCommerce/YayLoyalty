import * as React from 'react';
import { Slot } from '@radix-ui/react-slot';
import { useLocation } from 'react-router';

import { cn } from '@/lib/utils';
import { useCombinedRefs } from '@/hooks/useCombinedRefs';

const SideNavMenuList = React.forwardRef<HTMLUListElement, SideNavMenuListProps>(
  ({ mode = 'router', className, asChild = false, children, ...props }, ref) => {
    const Comp = asChild ? Slot : 'ul';
    const listRef = React.useRef<HTMLUListElement>(null);
    const combinedRef = useCombinedRefs(ref, listRef);
    const { pathname } = useLocation();

    React.useLayoutEffect(() => {
      if (mode !== 'router' || !listRef.current) return;

      const activeItemElement = listRef.current.querySelector(
        `[aria-current="page"][data-slot="side-navigation-menu-item"]`,
      );

      if (activeItemElement && activeItemElement instanceof HTMLElement) {
        activeItemElement.classList.add('bg-background', 'text-primary', 'hover:text-primary-accent');
      } else {
        listRef.current.querySelectorAll('[data-slot="side-navigation-menu-item"]').forEach((item) => {
          if (item instanceof HTMLElement) {
            item.classList.remove('bg-background', 'text-primary', 'hover:text-primary-accent');
          }
        });
      }
    }, [pathname, mode]);

    return (
      <Comp
        data-slot="side-navigation-menu-list"
        className={cn('flex list-none flex-row items-center justify-center gap-1 sm:flex-col', className)}
        {...props}
        ref={combinedRef}
      >
        {children}
      </Comp>
    );
  },
);

interface SideNavMenuItemProps extends React.ComponentProps<'li'> {
  asChild?: boolean;
}

const SideNavMenuItem = React.forwardRef<HTMLLIElement, SideNavMenuItemProps>(
  ({ className, asChild = false, ...props }, ref) => {
    const Comp = asChild ? Slot : 'li';
    return (
      <Comp
        data-slot="side-navigation-menu-item"
        className={cn(
          'hover:text-primary text-foreground transition-background flex h-10 w-full cursor-default items-center gap-2 rounded-md pl-3 text-sm shadow-none transition-colors outline-none focus-visible:ring-[1.5px]',
          'data-[state=active]:text-primary data-[state=active]:hover:text-primary-accent data-[state=active]:bg-background data-[state=active]:focus-visible:text-primary-accent',
          'data-[active=true]:text-primary data-[active=true]:hover:text-primary-accent data-[active=true]:bg-background data-[active=true]:focus-visible:text-primary-accent',
          className,
        )}
        {...props}
        ref={ref}
      />
    );
  },
);

type SideNavMenuListProps = React.ComponentProps<'ul'> & {
  mode?: 'tabs' | 'router';
  asChild?: boolean;
};

export { SideNavMenuList, SideNavMenuItem };
