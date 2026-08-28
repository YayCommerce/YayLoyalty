'use client';

import { createContext, useContext, useEffect, useState } from 'react';

type Theme = 'dark' | 'light' | 'system';

type ThemeProviderProps = {
  children: React.ReactNode;
  defaultTheme?: Theme;
  storageKey?: string;
};

type ThemeProviderState = {
  theme: Theme;
  setTheme: (theme: Theme) => void;
};

const initialState: ThemeProviderState = {
  theme: 'system',
  setTheme: () => null,
};

const ThemeProviderContext = createContext<ThemeProviderState>(initialState);

export function ThemeProvider({
  children,
  defaultTheme = 'system',
  storageKey = 'yay-ui-theme',
  ...props
}: ThemeProviderProps) {
  const [theme, setTheme] = useState<Theme>(() => (localStorage.getItem(storageKey) as Theme) || defaultTheme);

  useEffect(() => {
    const root = window.document.documentElement;
    root.classList.remove('yay-light', 'yay-dark');

    if (theme === 'system') {
      const mediaQueryIsDark = window.matchMedia('(prefers-color-scheme: dark)');
      if (mediaQueryIsDark.matches) {
        root.classList.add('yay-dark');
      } else {
        root.classList.add('yay-light');
      }

      // Listen for system theme changes
      const handleChange = (e: MediaQueryListEvent) => {
        root.classList.remove('yay-light', 'yay-dark');
        root.classList.add(e.matches ? 'yay-dark' : 'yay-light');
      };

      mediaQueryIsDark.addEventListener('change', handleChange);
      return () => mediaQueryIsDark.removeEventListener('change', handleChange);
    }

    if (theme === 'dark') {
      root.classList.add('yay-dark');
    } else {
      root.classList.add('yay-light');
    }
  }, [theme]);

  const value = {
    theme,
    setTheme: (theme: Theme) => {
      localStorage.setItem(storageKey, theme);
      setTheme(theme);
    },
  };

  return (
    <ThemeProviderContext.Provider {...props} value={value}>
      {children}
    </ThemeProviderContext.Provider>
  );
}

export const useTheme = () => {
  const context = useContext(ThemeProviderContext);

  if (context === undefined) throw new Error('useTheme must be used within a ThemeProvider');

  return context;
};
