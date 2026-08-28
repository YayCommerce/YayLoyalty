import { useEffect, useState } from 'react';
import { matchPath, useBlocker } from 'react-router';

/**
 * Hook that guards unsaved form changes.
 * Returns control for showing custom modal.
 */
export function useRouteLeaveGuard(shouldBlock: boolean, excludedRoutes: string[] = []) {
  const [showDialog, setShowDialog] = useState(false);

  const blocker = useBlocker(
    ({ nextLocation }) => shouldBlock && excludedRoutes.some((route) => !matchPath(route, nextLocation.pathname)),
  );
  useEffect(() => {
    if (blocker.state === 'blocked') {
      setShowDialog(true);
    }
  }, [blocker]);

  // Confirm Dialog actions
  const confirmLeave = () => {
    setShowDialog(false);
    blocker.proceed?.();
  };

  const cancelLeave = () => {
    setShowDialog(false);
    blocker.reset?.();
  };

  return { showDialog, confirmLeave, cancelLeave };
}
