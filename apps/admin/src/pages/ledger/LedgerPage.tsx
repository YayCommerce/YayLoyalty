import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { StoreLedger } from '@/pro';
import NotFoundPage from '../404';

/** Lite-safe entry for `ledger`: Lite has no store-wide ledger, so the route 404s there. */
export default function LedgerPage() {
  return IS_PRO_BUILD ? <StoreLedger /> : <NotFoundPage />;
}
