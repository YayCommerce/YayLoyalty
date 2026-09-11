import { ReactNode } from 'react';
import { Link } from 'react-router';

import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';

/**
 * Shared shell for the two "no rows" states: a brand-new store (2d, `links`)
 * and a filter combination that matches nobody (4a, `children` holds the
 * suggestion rows). Distinct copy per `ScenarioConfig.emptyCopy` keeps these
 * from reading as the same empty box with different text.
 */
export function CustomersEmptyState({
  title,
  description,
  links,
  children,
}: {
  title: string;
  description: string;
  links?: { label: string; to: string }[];
  children?: ReactNode;
}) {
  return (
    <Empty className="w-full border-none">
      <EmptyHeader>
        <EmptyTitle>{title}</EmptyTitle>
        <EmptyDescription>{description}</EmptyDescription>
      </EmptyHeader>
      {(links || children) && (
        <EmptyContent className={links ? 'flex-row justify-center gap-4' : 'max-w-lg'}>
          {links?.map((link) => (
            <Link key={link.to} to={link.to} className="text-primary text-sm hover:underline">
              {link.label}
            </Link>
          ))}
          {children}
        </EmptyContent>
      )}
    </Empty>
  );
}
