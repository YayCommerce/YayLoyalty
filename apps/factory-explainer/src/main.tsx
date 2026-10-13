import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App';

if ( import.meta.env.DEV ) {
  void import( './debug' );
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
);
