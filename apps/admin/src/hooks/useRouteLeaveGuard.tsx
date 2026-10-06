import { useEffect, useRef, useState } from 'react';
import { matchPath, useBlocker } from 'react-router';

/** Blocks in-app navigation while a form has unsaved changes and hands the blocked transition to the caller. */
export function useRouteLeaveGuard(shouldBlock: boolean, excludedRoutes: string[] = []) {
  const [showDialog, setShowDialog] = useState(false);
  // Set right before the navigate() that follows a successful save. `shouldBlock` may not yet
  // reflect the form.reset(), so it would block that navigation.
  const bypassNextRef = useRef(false);

  const blocker = useBlocker(({ nextLocation }) => {
    if (bypassNextRef.current) {
      bypassNextRef.current = false;
      return false;
    }

    return shouldBlock && !excludedRoutes.some((route) => matchPath(route, nextLocation.pathname));
  });
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

  const bypassNext = () => {
    bypassNextRef.current = true;
  };

  return { showDialog, confirmLeave, cancelLeave, bypassNext };
}
