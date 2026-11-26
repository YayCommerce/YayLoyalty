import { useParams } from 'react-router';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { RewardEditor } from '@/pro';

/** Lite-safe entry for rewards/:id/edit. Lite edits its one reward in place on the Rewards page instead, so this renders nothing there. */
export default function RewardEditorPage() {
  const { id } = useParams();

  return IS_PRO_BUILD ? <RewardEditor id={Number.parseInt(id ?? '', 10)} /> : null;
}
