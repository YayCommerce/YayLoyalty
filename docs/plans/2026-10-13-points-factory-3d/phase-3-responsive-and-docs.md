# Phase 3: Responsive, fallbacks and docs

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 2, Tasks 1–6
**Delivers:**
- reduced motion;
- fallbacks for a missing model and for no WebGL;
- the `?perf` frame-rate meter;
- the app's README;
- the architecture link;
- the 2D page removed.

**Scope change, 2026-10-13:** the user dropped the mobile view: "no need to care mobile view". The 390×844 layout is out of scope, and spec criteria 10, 14 and 17 apply at 1440×900 only.

**Exit criterion:** spec criterion 17, at 1440×900.
- `pnpm --filter @yayloyalty/factory-explainer shoot` passes, writing 3 screenshots, with the overlap assertions and no console errors.
- The checklist passes within 3 critique rounds, logged in this file. The 60 fps item is read in a headed run, or by the user.
- `./scripts/check-harness.sh` passes.

### Task 1: Reduced motion, fallbacks and the frame-rate meter

**Files:**
- Modify: `apps/factory-explainer/src/ui/ui.css`, `src/ui/StepCard.tsx`, `src/ui/StationPanel.tsx`, `src/scene/Stage.tsx`, `src/scene/Station.tsx`, `src/scene/Actor.tsx`, `src/App.tsx`

**Interfaces:**
- Consumes: `useModel` (`null` on failure) and `useFactory`.

**At 390 px:** dropped; see the scope change above.

**Frame-rate meter:** drei's `<StatsGl>`, mounted only behind `?perf`, as the README's global constraints say. No earlier task added it, and Task 3 measures with it.

**Reduced motion:** the scene is paused at the end. The rollers, belt and dial are static, and the CSS transitions are off.

**Fallbacks:**
- When a model fails, the `RoundedBox` body renders in its place.
- With no WebGL context, a notice reads "This explainer needs WebGL."

Verify each one:
- **Reduced motion:** Playwright with `reducedMotion: 'reduce'`.
- **A missing model:** rename one `.glb` temporarily.
- **No WebGL:** launch with `--disable-webgl`.

- [x] **Step 1: Reduced motion.** The store already loads paused on the final state, the belts and rollers are already still, and the step card's transition is already off. `Scoring.tsx` now keeps the dial at 0 under `reduced`, even if the user presses Play.
- [x] **Step 2: No WebGL.** `App.tsx` probes for a `webgl2` or `webgl` context once, then releases it with `WEBGL_lose_context`. Without one, it renders only `<p class="no-webgl" role="alert">This explainer needs WebGL.</p>`.
- [x] **Step 3: Missing model.** Nothing changes: `Kit` already draws its `fit`-sized `Part` when `useModel` returns `null`, and `Actor` draws the capsule.
- [x] **Step 4: `?perf`.** `App.tsx` mounts drei's `<StatsGl />` inside the canvas only when the query has `perf`.
- [x] **Step 5: Box tag errors (a Phase 1 bug).** `BoxItem` mounted its tag's `<Html>` only while the box was visible. Seeking back past a box's appearance, or a loop wrap, unmounted an Html root mid-render, and React logged one error per box. The tag now stays mounted and hides with `display: none`. The labels' one-frame-late mount, which works around StrictMode, moves into `src/ui/useLateMount.ts`, and both the labels and the box tags use it.
- [x] **Step 6: Verify.** `tsc --noEmit` exits 0, and `shoot` passes. One-off Playwright runs at 1440×900:
  - **Reduced motion** (`reducedMotion: 'reduce'`): the app loads at `t = 157.39`, the full duration, paused in scene 6's hold, and the step card's `transitionDuration` is `0s`. Belt crop at t = 20 vs t = 23: 0 pixels change. Scoring crop at t = 1 vs the end: 0 pixels under reduced motion, against 543 with normal motion.
  - **Missing model** (`machine-fortified.glb` renamed, then restored): the vault draws as a beveled box with its door, the only console message is the `[factory] model machine-fortified failed to load` warning, and the loop still wraps.
  - **No WebGL** (`--disable-webgl --disable-webgl2`): the notice reads "This explainer needs WebGL.", there is no canvas, and the console is clean.
  - **`?perf`:** the meter mounts as a third canvas, with no errors.
  - **Seeks and wrap:** seeking to 157.2, 120, 40, 5, 0.5 and 70, then playing past the end, logs no console errors.
- [x] **Step 7: Commit.** Stage `src/App.tsx`, `src/scene/BoxItem.tsx`, `src/scene/stations/Scoring.tsx`, `src/ui/StationLabel.tsx`, `src/ui/useLateMount.ts`, `src/ui/ui.css`, the spec, the README and this file: `feat(factory-explainer): add reduced motion, the WebGL notice and the perf meter`.

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
2. Critique the 3 screenshots against the checklist, then fix and re-shoot, for up to 3 rounds.
3. Measure frames per second over 5 s at 1440×900 in a headed run with `?perf`. Expected: 55 or more on average.

Steps: expand after Phase 2 lands.

## Critique log

Filled in during Task 3.
