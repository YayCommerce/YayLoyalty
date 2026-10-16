import { forwardRef, type AnchorHTMLAttributes, type MouseEvent } from 'react';

import { cn } from '@/lib/utils';
import { useLocation, useNavigate, useResolvedPath, type To } from './router-hooks';

type LinkProps = Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
  to: To;
  replace?: boolean;
  state?: unknown;
};

/** An `<a>` to `#<path>` that navigates in-app on a plain left click, so blockers see it. */
export const Link = forwardRef<HTMLAnchorElement, LinkProps>(({ to, replace, state, onClick, ...props }, ref) => {
  const path = useResolvedPath(to);
  const navigate = useNavigate();

  const handleClick = (e: MouseEvent<HTMLAnchorElement>) => {
    onClick?.(e);
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.altKey || e.ctrlKey || e.shiftKey) return;
    if (props.target && props.target !== '_self') return;
    e.preventDefault();
    navigate(path, { replace, state });
  };

  return <a ref={ref} href={`#${path}`} onClick={handleClick} {...props} />;
});
Link.displayName = 'Link';

/** A Link marked `aria-current="page"` and `active` while the location is at or under it. */
export const NavLink = forwardRef<HTMLAnchorElement, LinkProps>(({ className, ...props }, ref) => {
  const target = useResolvedPath(props.to).split('?')[0].replace(/\/$/, '');
  const { pathname } = useLocation();
  const active = pathname === target || pathname.startsWith(`${target}/`);
  return (
    <Link
      ref={ref}
      aria-current={active ? 'page' : undefined}
      className={cn(className, active && 'active')}
      {...props}
    />
  );
});
NavLink.displayName = 'NavLink';
