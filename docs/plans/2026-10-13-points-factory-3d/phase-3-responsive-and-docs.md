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

- [x] **Step 1: App README.** `apps/factory-explainer/README.md` now covers:
  - what the app is, and that it is desktop-only;
  - `nvm use`, `pnpm install` and `dev`;
  - `?perf` and the controls;
  - the Playwright install, `tsc` and `shoot`, and what `shoot` fails on;
  - where the config, timeline, scene and UI live;
  - the CC0 credits for Kenney, Quaternius and Poly Haven.
- [x] **Step 2: Links and status.** In `docs/architecture.md`, the explainer bullet now links `../apps/factory-explainer/README.md`. The 2D plan's Status becomes `superseded by` this plan. The 2D spec was already `superseded` in Phase 1.
- [x] **Step 3: Delete the 2D page.** `git rm docs/explainers/points-factory.html`. That leaves `docs/explainers/` empty, so git drops it.
- [x] **Step 4: Harness fix.** `./scripts/check-harness.sh` failed with `[section_citations] Binary file … matches` for `worker.glb` and `potsdamer_platz_1k.hdr`: their bytes contain `C2 A7`, the UTF-8 encoding of `§`. `check_section_citations` and `check_feature_ids` now call `git grep -I`, which skips binary files. `scripts/check-harness.sh` is outside this plan's file list; the binary assets Phase 2 added are the reason it changes.
- [x] **Step 5: Verify.** `./scripts/check-harness.sh` prints "Harness checks passed." and exits 0. `git grep -n "explainers/points-factory.html"` finds the path only in `docs/plans/` and `docs/specs/`.
- [x] **Step 6: Commit.** Stage the app README, `docs/architecture.md`, the 2D plan, the deleted page, `scripts/check-harness.sh` and this file: `docs(factory-explainer): document the 3D app and retire the 2D page`.

### Task 3: Final shoot, critique and close-out

**Files:**
- Modify: `docs/plans/2026-10-13-points-factory-3d/phase-3-responsive-and-docs.md` (critique log), `docs/plans/2026-10-13-points-factory-3d/README.md` (phase table, then Status `done YYYY-MM-DD`)

1. Run the exit criterion.
2. Critique the 3 screenshots against the checklist, then fix and re-shoot, for up to 3 rounds.
3. Measure frames per second over 5 s at 1440×900 in a headed run with `?perf`. Expected: 55 or more on average.

- [x] **Step 1: Round 2 fixes.**
  - `Stage.tsx` measures the toolbar's bottom edge, fits the zoom to the space below it, and centres the floor there with `camera.setViewOffset`. `project()`, drei's `Html` and raycasting all read the projection matrix, so labels, `machineRects()` and hover follow.
  - `dpr` is capped at `[ 1, 1.5 ]`.
  - `Worker.tsx` mixes each actor's colour 35 % toward the machine grey.
  - A new optional config field, `labelPlace: 'start'`, runs the label rightwards from its anchor. It is set on EarningService and the vault.
- [x] **Step 2: Round 3 fixes.** `labelPlace: 'below'` anchors the label at the footprint's front corner, the lowest point of the machine's screen box, and hangs it under the machine on its leader. It is set on the three unbuilt stations. The vault keeps `start`: below its front corner is the VIP tiers zone. `<StatsGl>` moves bottom-right (`.perf-stats`), because the toolbar covered it at the top-left.
- [x] **Step 3: Verify.** `tsc --noEmit` exits 0, `shoot` passes with 3 shots and no console errors, and `./scripts/check-harness.sh` passes. Frame rate is in the critique log.
- [x] **Step 4: Commit.** Stage `src/App.tsx`, `src/factory.config.ts`, `src/scene/Stage.tsx`, `src/scene/Worker.tsx`, `src/ui/StationLabel.tsx`, `src/ui/ui.css`, the README and this file: `feat(factory-explainer): frame the floor below the toolbar and place the labels clear`.

## Critique log

Round 1 is in [Phase 2's critique log](phase-2-illustration.md#critique-log). All rounds use the three 1440×900 `shoot` shots. The 390×844 shots were dropped with the mobile view.

### Round 2, 2026-10-13

Changes: the floor is framed below the toolbar, DPR is capped at 1.5, the workers' colours are mixed toward grey, and EarningService and the vault labels use `labelPlace: 'start'`.

| Checklist item | Result | Notes |
|---|---|---|
| Distinct silhouettes | pass | Unchanged. |
| Soft shadows and AO | pass | Unchanged. |
| No labels overlap and no label covers a machine | fail | Alice and OrderListener's label are no longer under the toolbar, and EarningService's label is off the intake's scanner arch. Still wrong: `customers` sits on the PendingPoints racks, and Expiry and VIP tiers sit over the vault (VIP tiers' rectangle overlaps the vault's machine rectangle by 99 %). |
| One accent per object, calm palette | pass | The workers no longer outshout the machines. |
| No plain unbevelled box | pass | Unchanged. |
| 60 fps at 1440×900 | not measured | Measured after round 3. |

### Round 3, 2026-10-13

Changes: the unbuilt labels use `labelPlace: 'below'`, and the `?perf` meter moves bottom-right.

| Checklist item | Result | Notes |
|---|---|---|
| Distinct silhouettes | pass | |
| Soft shadows and AO | pass | |
| No labels overlap and no label covers a machine | fail, one label | `shoot`'s assertions pass: no two labels meet, and none covers its own machine. Visually, every label but one sits clear of other machines:<br>• the unbuilt labels hang under their own blue boxes;<br>• `customers` still sits on the PendingPoints racks. Its rectangle overlaps that machine's rectangle by 55 %.<br><br>Measured against every other machine's conservative footprint box at t = 70: the intakes overlap their neighbours by 23–24 %, EarningService overlaps OrderListener by 34 %, LedgerService overlaps PendingPoints by 16 %, and the unbuilt labels overlap the next blue zone by 28–33 %. In this iso layout the footprint boxes of neighbours overlap one another, so this is not a pixel test. |
| One accent per object, calm palette | pass | |
| No plain unbevelled box | pass | |
| 60 fps at 1440×900 | pass at DPR 1, fail at DPR 2 | Headed Chromium with `?perf`, counting `requestAnimationFrame` calls over 5 s of playback: 60.1 fps (worst frame 17.8 ms) at DPR 1. At DPR 2, which renders at the 1.5 cap (2160×1350), it was 49.1 fps (worst frame 67.5 ms), up from 23.9 fps in round 1. That is below Task 3's 55. |

After 3 rounds, two items are open, for the user to decide:
- **`customers` on the racks.** One fix is to move the vault or the warehouse in `factory.config.ts`, which changes the layout. Another is to accept it.
- **Frame rate at DPR 2.** Options: cap DPR at 1.25, drop N8AO under load with drei's `PerformanceMonitor`, or accept 49 fps on Retina.
