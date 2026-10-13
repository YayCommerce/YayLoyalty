# Phase 3: Responsive, fallbacks and docs

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 2, Tasks 1–6
**Delivers:**
- the 390×844 layout;
- reduced motion;
- fallbacks for a missing model and for no WebGL;
- the app's README;
- the architecture link;
- the 2D page removed.

**Exit criterion:** spec criterion 17.
- `SHOOT_VIEWPORTS=1440x900,390x844 pnpm --filter @yayloyalty/factory-explainer shoot` passes, writing 6 screenshots, with the overlap assertions and no console errors.
- The checklist passes within 3 critique rounds, logged in this file. The 60 fps item is read in a headed run, or by the user.
- `./scripts/check-harness.sh` passes.

### Task 1: Responsive layout, reduced motion and fallbacks

**Files:**
- Modify: `apps/factory-explainer/src/ui/ui.css`, `src/ui/StepCard.tsx`, `src/ui/StationPanel.tsx`, `src/scene/Stage.tsx`, `src/scene/Station.tsx`, `src/scene/Actor.tsx`, `src/App.tsx`

**Interfaces:**
- Consumes: `useModel` (`null` on failure) and `useFactory`.

**At 390 px:**
- the toolbar wraps;
- the step card is a bottom sheet;
- the panel is full width;
- the fit zoom covers the floor;
- the page has no horizontal scroll.

**Reduced motion:** the scene is paused at the end. The rollers, belt and dial are static, and the CSS transitions are off.

**Fallbacks:**
- When a model fails, the `RoundedBox` body renders in its place.
- With no WebGL context, a notice reads "This explainer needs WebGL."

Verify each one:
- **Reduced motion:** Playwright with `reducedMotion: 'reduce'`.
- **A missing model:** rename one `.glb` temporarily.
- **No WebGL:** launch with `--disable-webgl`.

Steps: expand after Phase 2 lands.

### Task 2: Docs and retiring the 2D page

**Files:**
- Modify: `apps/factory-explainer/README.md` (Phase 1's stub), filled in: what the app is, `nvm use`, `pnpm install`, the `dev`, `shoot` and `?perf` commands, and the asset credits
- Modify:
  - `docs/architecture.md`: replace the line that links `explainers/points-factory.html` with one that links `../apps/factory-explainer/README.md`;
  - `docs/plans/2026-10-13-points-factory-explainer.md`: Status becomes `superseded by` this plan.
- Delete: `docs/explainers/points-factory.html`

**Verify:**
- `./scripts/check-harness.sh` passes.
- `git grep -n "explainers/points-factory.html"` finds only history: specs and plans.

Steps: expand after Phase 2 lands.

### Task 3: Final shoot, critique and close-out

**Files:**
- Modify: `docs/plans/2026-10-13-points-factory-3d/phase-3-responsive-and-docs.md` (critique log), `docs/plans/2026-10-13-points-factory-3d/README.md` (phase table, then Status `done YYYY-MM-DD`)

1. Run the exit criterion.
2. Critique the 6 screenshots against the checklist, then fix and re-shoot, for up to 3 rounds.
3. Measure frames per second over 5 s at 1440×900 in a headed run with `?perf`. Expected: 55 or more on average.

Steps: expand after Phase 2 lands.

## Critique log

Filled in during Task 3.
