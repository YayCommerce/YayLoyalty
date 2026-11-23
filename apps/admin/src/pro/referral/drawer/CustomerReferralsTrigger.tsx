import { DrawerTab } from '@/pages/customers/drawer/tabs/DrawerTab';
import { TAB_REFERRALS } from '@/pages/customers/drawer/customer-drawer.copy';
import { useCustomerReferralsQuery } from '../referral.queries';

/** The drawer's Referrals tab trigger, counting the customer's referrals from the tab's own first page. */
export function CustomerReferralsTrigger({ userId }: { userId: number }) {
  const query = useCustomerReferralsQuery(userId, 1);

  return <DrawerTab value="referrals" label={TAB_REFERRALS()} count={query.data?.counts.referred ?? 0} />;
}
