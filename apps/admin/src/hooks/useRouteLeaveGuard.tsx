import { useEffect, useState } from 'react';
import { matchPath, useBlocker } from 'react-router';

/** Blocks in-app navigation while a form has unsaved changes and hands the blocked transition to the caller. */
export function useRouteLeaveGuard(shouldBlock: boolean, excludedRoutes: string[] = []) {
  const [showDialog, setShowDialog] = useState(false);

  const blocker = useBlocker(
    ({ nextLocation }) => shouldBlock && !excludedRoutes.some((route) => matchPath(route, nextLocation.pathname)),
  );
  useEffect(() => {
    if (blocker.state === 'blocked') {
      setShowDialog(true);
    }
  }, [blocker]);

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
