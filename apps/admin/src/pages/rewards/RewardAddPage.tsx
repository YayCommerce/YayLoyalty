import { Navigate } from 'react-router';

/** rewards/new: the modal picks the type itself, so it opens on the first one. Lite's list ignores child routes. */
export default function RewardAddPage() {
  return <Navigate to="/rewards/new/fixed_coupon" replace />;
}
