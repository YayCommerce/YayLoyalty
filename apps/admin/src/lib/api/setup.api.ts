import { api } from '@/lib/api/api';
import type { DesignPreviewViewer } from '@/lib/api/design.api';
import { DesignPreview, designPreviewSchema } from '@/lib/schema/design.schema';
import {
  setupCompleteResponseSchema,
  setupDismissResponseSchema,
  SetupDraft,
  setupDraftSchema,
  setupSavedStateSchema,
  SetupState,
  setupStateSchema,
} from '@/lib/schema/setup.schema';

/** Responses are parsed, not cast — see customer.api.ts. */

export async function getSetup(): Promise<SetupState> {
  const state = setupStateSchema.parse(await api.get('setup').json());
  const draft = setupDraftSchema.safeParse(state.draft);

  return { ...state, draft: draft.success ? draft.data : null };
}

/** Saves the step to reopen on and the draft. Live campaigns, the reward and settings are untouched. */
export async function saveSetupDraft(step: number, draft: SetupDraft) {
  return setupSavedStateSchema.parse(await api.post('setup/draft', { json: { step, draft } }).json());
}

/** Writes every answer in one transaction and turns the program on; 409 once setup is complete. */
export async function completeSetup(draft: SetupDraft) {
  return setupCompleteResponseSchema.parse(await api.post('setup/complete', { json: { draft } }).json());
}

/** The launcher drawn with the draft's look, labels and earn rates; nothing is saved. */
export async function previewSetup(draft: SetupDraft, viewer: DesignPreviewViewer): Promise<DesignPreview> {
  return designPreviewSchema.parse(await api.post('setup/preview', { json: { draft, viewer } }).json());
}

export async function dismissFirstRun() {
  return setupDismissResponseSchema.parse(await api.post('setup/dismiss-first-run').json());
}
