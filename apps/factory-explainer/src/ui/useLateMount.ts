import { useEffect, useState } from 'react';

/**
 * False until one animation frame after mount. drei's Html renders its own React root; on the first mount, StrictMode's
 * effect replay makes it unmount that root mid-render, which React logs as an error. Mounting a frame late avoids it.
 */
export function useLateMount(): boolean {
  const [ ready, setReady ] = useState( false );
  useEffect( () => {
    const id = requestAnimationFrame( () => setReady( true ) );
    return () => cancelAnimationFrame( id );
  }, [] );
  return ready;
}
