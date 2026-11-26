import { useState } from 'react';
import { SearchIcon } from 'lucide-react';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/**
 * A search that opens from an icon button and folds back when left empty. A search that is already set stays open,
 * so the filter never hides behind the button.
 */
export function ExpandingSearch({
  value,
  onChange,
  placeholder,
  size = 'default',
}: {
  value: string;
  onChange: (value: string) => void;
  placeholder: string;
  size?: 'default' | 'sm';
}) {
  const [isOpen, setIsOpen] = useState(value !== '');

  if (!isOpen) {
    return (
      <Button
        variant="outline"
        size={size === 'sm' ? 'icon-sm' : 'icon'}
        aria-label={placeholder}
        onClick={() => setIsOpen(true)}
      >
        <SearchIcon />
      </Button>
    );
  }

  return (
    <Input
      autoFocus
      className={cn('min-w-0', size === 'sm' ? 'h-8 w-56' : 'w-48')}
      placeholder={placeholder}
      value={value}
      onChange={(event) => onChange(event.target.value)}
      onBlur={() => value === '' && setIsOpen(false)}
    />
  );
}
