# Phase 1: The story plays in primitives

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** nothing
**Delivers:** `pnpm --filter @yayloyalty/factory-explainer dev` opens an isometric 3D floor drawn with beveled primitives.
- All 6 scenes play with their caption labels and HUD cards.
- Every control works: Play/Pause, Restart, scene jump, Space, hover hold, and the station panel.

**Exit criterion:**
- `pnpm --filter @yayloyalty/factory-explainer exec tsc --noEmit` exits 0.
- `pnpm --filter @yayloyalty/factory-explainer shoot` passes, writing `shots/1440-scene-1.png`, `shots/1440-scene-3.png` and `shots/1440-scene-4.png` with no console errors.

### Task 1: Package scaffold

**Files:**
- Create: `apps/factory-explainer/package.json`, `vite.config.ts`, `tsconfig.json`, `index.html`, `.gitignore`, `src/main.tsx`, `src/App.tsx`, `src/scene/Stage.tsx`, `README.md`
- Modify: `pnpm-workspace.yaml` (add `"apps/factory-explainer"` under `packages`)

**Interfaces:**
- Produces:
  - `<Stage>`: the `<Canvas>` with an orthographic iso camera and `MapControls`. Later tasks mount children inside it.
  - The npm scripts `dev`, `build`, `preview`, `shoot`.

- [x] **Step 1: Write `package.json` and register the package**

`name` is `@yayloyalty/factory-explainer`, with `"private": true` and `"type": "module"`. The scripts are:

| Script | Command |
|---|---|
| `dev` | `vite --port 3050 --strictPort` |
| `build` | `tsc --noEmit && vite build` |
| `preview` | `vite preview --port 3050` |
| `shoot` | `playwright test` |

Add dependencies with `pnpm --filter @yayloyalty/factory-explainer add --save-exact`, at the majors in Global constraints:
- **Runtime:** `react`, `react-dom`, `three`, `@react-three/fiber`, `@react-three/drei`, `@react-three/postprocessing`, `zustand`.
- **Dev:** `vite`, `@vitejs/plugin-react`, `typescript`, `@types/react`, `@types/react-dom`, `@types/three`, `@playwright/test@1.63.0`, `tsx`.

`.gitignore` lists `dist/`, `shots/`, `test-results/` and `playwright-report/`.

- [x] **Step 2: Implement `<Stage>` in `src/scene/Stage.tsx`**

- **Canvas:** `<Canvas shadows={{ type: THREE.PCFSoftShadowMap }} orthographic dpr={[1, 2]}>`.
- **Camera:**
  - It sits at `target + [d, d, d]`, looking at `target`: the true iso angle, 35.264° pitch and 45° yaw.
  - `target` is the floor centre, `[15.5, 0, 7]` world units (floor 0–1240 × 30–530 ÷ 40).
- **Controls:** drei `MapControls` with:
  - `enableRotate={false}`;
  - `minZoom` and `maxZoom` at 0.6× and 2.5× of the fit zoom;
  - the pan clamped to the floor bounds plus 4 units.
- **Fit zoom:** the zoom at which the floor's bounding box fills 90 % of the canvas. Recompute it on resize.
- **Content:** a temporary floor plane, so Step 3 has something to show.

- [x] **Step 3: Verify the scaffold**

Run:
1. `nvm use`, then `pnpm install`.
2. `pnpm --filter @yayloyalty/factory-explainer exec tsc --noEmit`.
3. `pnpm --filter @yayloyalty/factory-explainer build`.
4. `pnpm --filter @yayloyalty/factory-explainer dev`.

Then open `http://localhost:3050`.

Expected:
- `tsc` exits 0, and the build writes `apps/factory-explainer/dist/index.html`.
- The page shows the floor at an isometric angle. Dragging pans, the wheel zooms within its limits, and the camera can't rotate.
- `git status` shows no `dist/` or lockfile.

- [x] **Step 4: Commit**

Stage:
- the files above;
- `pnpm-workspace.yaml`;
- `docs/specs/2026-10-13-points-factory-3d-design.md`, `docs/specs/2026-10-13-points-factory-explainer-design.md`;
- `docs/plans/2026-10-13-points-factory-3d/README.md`, `phase-1-story-in-primitives.md`, `phase-2-illustration.md`, `phase-3-responsive-and-docs.md`;
- `docs/plans/2026-10-13-points-factory-explainer.md`, `docs/explainers/points-factory.html` (the uncommitted Step 8 work, kept in history until Phase 3 deletes the page).

Message: `feat(factory-explainer): scaffold the 3D points factory app`

### Task 2: Config, timeline and store

**Files:**
- Create: `apps/factory-explainer/src/factory.config.ts`, `src/timeline.ts`, `src/store.ts`

**Interfaces:**
- Produces: everything under `factory.config.ts`, `timeline.ts` and `store.ts` in the README contract.

- [ ] **Step 1: Write `factory.config.ts`**

**Stations and conveyors**
- Copy the 12 stations' `role`, `label`, `file`, `methods` and `summary` from the `STATIONS` in `docs/explainers/points-factory.html`.
- For each station, set:
  - `position` = its 2D `(x, y) / 40`;
  - `size` = `(w, d) / 40`, with `h` scaled from `hgt / 40`;
  - `built` as in the 2D page.
- Add `description` (one line), `hooks`, `inputs` and `outputs`, using only names already in that station's `methods` or `summary`.
- Convert the 14 `BELTS` to `CONVEYORS`:
  - `from` and `to` come from the belt id;
  - `via` holds the inner corner points ÷ 40;
  - `REJECT_BIN` is the 2D `BIN` ÷ 40;
  - `kind` is `reject` for `belt-program-gate-reject-bin`, `return` for `belt-vault-reversal-listener`, and `main` otherwise.

**Actors and boxes**
- `ACTORS` take `dock` from the 2D `SPOTS`, and `home` = `dock` minus 1.5 world units on `x`. The admin has `console: true`.
- `BOXES` are:
  - alice: `ORDER`, `120`, earn;
  - bob: `SIGNUP`, `50`, earn;
  - cara: `REVIEW`, `20`, earn;
  - admin: `MANUAL`, `+50`, adjust.

**Scenes**
- Copy the 6 `SCENES` and their 23 steps word for word: `caption`, `label`, `card.fn`, `card.lines`, `card.out`, `gate`, `press` and `at`.
- Rename `belt` to `conveyor`, `fill` to `tint`, and `settle` to the world `[x / 40, y / 40 → y-up height, z]`.

- [ ] **Step 2: Verify that the card text matches**

Run a one-off script with `pnpm --filter @yayloyalty/factory-explainer exec tsx`. It loads both files and compares:
- the `SCENES` array parsed out of `git show HEAD:docs/explainers/points-factory.html`;
- the TS `SCENES`.

Expected: all 23 `label`, `fn`, `lines` and `out` values are identical. Print `23/23 match`.

- [ ] **Step 3: Implement `timeline.ts` and `store.ts`**

**`compileTimeline`** follows the 2D page's step loop, with the README's `TIMING` and these phases:

| Phase | When | Length |
|---|---|---|
| `intro` | At each scene's start | `INTRO` |
| `walk` | When `scene.show` is set: the actor walks `home` → `dock`, and the box appears in the actor's hands | `WALK` |
| `ride` | Per belt step: arc length on a `CatmullRomCurve3` (`centripetal`, tension 0.5) through the waypoints at `BELT_Y` | `max(HOP, length / SPEED)`, plus 0.3 s to `settle` |
| `work` | After every step | `WORK` |
| `hold` | At each scene's end | `HOLD` |

- The actor walks back during the first `ride`.

**`boxPose`, `actorPose` and `propPose`** are pure functions of time. Each phase eases with `easeInOutCubic`:
- `gateArm` lifts to 70° over 0.4 s from `work.end − 0.5`, and closes 0.9 s after `work.end`.
- `gateDeny` flashes 3 times from `work.start + 1.2`.
- `pressHead` drops and returns over 0.28 s at `work.start + 1.4`.
- `dial` turns at 1 rad/s while any `work` at `earning-service` runs, and stands still otherwise.

**`store.tick(dt)`** adds `min(dt, 0.1)` when not paused, and wraps at `duration`.

**Pause rules**, matching the 2D page:
- `toggle()` clears `hoverHeld`.
- `openPanel()` sets `resumeAfterPanel = !paused || hoverHeld`, clears `hoverHeld`, then pauses.
- `closePanel()` plays if `resumeAfterPanel`.
- `hoverHold(true)` pauses only a playing loop. `hoverHold(false)` plays only when `hoverHeld`.

With `matchMedia('(prefers-reduced-motion: reduce)')`, the initial state is `paused: true` and `time: duration − 0.001`.

- [ ] **Step 4: Verify the timeline**

Run `tsc --noEmit`, then a one-off script with `pnpm --filter @yayloyalty/factory-explainer exec tsx` that asserts:
- `duration` is between 150 and 175 s;
- `sceneStart['scene-3']` lies after scene 2's last entry;
- at `time = 0.05`, every `boxPose(...).visible` is `false`;
- at scene 4's last `work`, cara's `tint` is `rejected`;
- with a copy of `STATIONS` where `ledger-service` has moved by +1 on `x`, every `conveyorPoints` that ends there ends at the new position;
- at 0.02 s steps across the loop, no visible box moves more than 0.5 world units between samples, except across the loop wrap.

Expected: every assertion passes.

- [ ] **Step 5: Commit**

Stage: `apps/factory-explainer/src/factory.config.ts`, `src/timeline.ts`, `src/store.ts`
Message: `feat(factory-explainer): add the factory config, timeline and store`

### Task 3: Scene in beveled primitives

**Files:**
- Create: `apps/factory-explainer/src/scene/Floor.tsx`, `Station.tsx`, `Conveyor.tsx`, `BoxItem.tsx`, `Actor.tsx`, `Clock.tsx`
- Modify: `src/scene/Stage.tsx` (mount them, and drop the temporary plane)

**Interfaces:**
- Consumes: README contract, `factory.config.ts` and `timeline.ts`.
- Produces:
  - `<Station station={Station} />`, which renders a body per `type`. Phase 2 Task 2 swaps the body; the props stay.
  - `<Clock />`, which calls `useFactory.getState().tick(delta)` in `useFrame`.

- [ ] **Step 1: Implement the components**

Every solid is a drei `RoundedBox`, `radius` 0.06–0.12, with `castShadow` and `receiveShadow`. Use no plain `boxGeometry`.
- **Stations:** one rounded body per station, `size` × `position`. Rough silhouettes for now:
  - the gate arm, the press head and the scoring dial move from `propPose`;
  - unbuilt stations get a translucent blue (`#60a5fa`, opacity 0.25) body with `<Edges>`.
- **Conveyors:** a rounded track along the curve. The return conveyor is red, `#dc2626` at 55 % saturation or less.
- **Boxes:** cardboard `#c8a27a` rounded cubes, 0.5 units, placed from `boxPose`.
  - The tag is a drei `<Html>` chip showing `event`, plus ` · points` once the box has left `earning-service`.
  - The admin box shows `+50 · MANUAL` from the start.
- **Actors:** a capsule figure in the actor's colour, placed from `actorPose`.
- **Lights:** temporary, an `ambientLight` plus one `directionalLight`. Phase 2 replaces them.

- [ ] **Step 2: Verify the motion**

Run `dev`, open `http://localhost:3050`, and watch scenes 1–2.

Expected:
- Alice walks to her dock, and her box rides through `OrderListener` → `EarningService` → `ProgramGate`. The arm lifts after the work phase, and the box parks at `PendingPoints`.
- In scene 2, Bob's box is stamped by the press and settles on the vault.
- No box jumps.
- The console shows no errors.

- [ ] **Step 3: Commit**

Stage: the files above.
Message: `feat(factory-explainer): draw the factory floor in beveled primitives`

### Task 4: Overlays and controls

**Files:**
- Create: `apps/factory-explainer/src/ui/Toolbar.tsx`, `StepCard.tsx`, `StationPanel.tsx`, `src/ui/ui.css`
- Modify: `src/App.tsx` (mount the overlays and the Space key handler), `src/scene/Station.tsx` (click → `openPanel`, pointer over/out → `setHovered`), `src/scene/BoxItem.tsx` (pointer enter/leave → `hoverHold`)

**Interfaces:**
- Consumes: `useFactory` and `entryAt`.
- Produces: the README's DOM hooks.

- [ ] **Step 1: Implement the overlays**

- **Toolbar** (`data-testid="toolbar"`, top, sticky):
  - Play/Pause and Restart;
  - six scene buttons, with `aria-current="step"` on the current scene;
  - the caption, with the scene caption plus the step `label` in `<code>`;
  - a progress bar.
- **StepCard** (`data-testid="step-card"`):
  - frosted, docked bottom-left: `backdrop-filter: blur(12px)`, a 1px border, 12–14px mono text;
  - it shows `fn`, `lines` and `→ out` during `work` entries, and fades out otherwise;
  - ✓ lines are green and ✗ lines red;
  - pointer enter and leave call `hoverHold`.
- **StationPanel** (`data-testid="panel"`): a right-hand drawer with the role, label, summary, file, hooks, methods, inputs and outputs. It has a close button (`panel-close`) and closes on Escape.
- **Space** toggles play, except when focus is on a button.

- [ ] **Step 2: Verify the controls**

Open `dev`, then use the mouse and keyboard to:
1. click Play/Pause and Restart;
2. hover a box, then press Space, then leave;
3. hover a box, then click a station, then close the panel;
4. pause, then open and close a panel;
5. click "4 · Cara reviews…".

Expected:

| Action | Result |
|---|---|
| Hover a box | Pauses, and the button reads "Play" |
| Leave the box | Resumes |
| Space during a hover, then leave | Stays as the user's toggle left it |
| Hover, open a panel, close it | Resumes |
| Pause, open a panel, close it | Stays paused |
| Click scene 4 | The caption reads "4 · Cara reviews, but has opted out", and no box from scenes 1–3 is in mid-ride |

- [ ] **Step 3: Commit**

Stage: the files above.
Message: `feat(factory-explainer): add the toolbar, step card and station panel`

### Task 5: Screenshot harness

**Files:**
- Create: `apps/factory-explainer/playwright.config.ts`, `scripts/shoot.spec.ts`, `src/debug.ts`
- Modify: `src/main.tsx` (import `./debug` when `import.meta.env.DEV`), `tsconfig.json` (include `scripts`, `playwright.config.ts`)

**Interfaces:**
- Produces:
  - the README's debug handle;
  - `SHOOT_VIEWPORTS`, a comma list read by the spec, defaulting to `1440x900`. Phase 3 sets `1440x900,390x844`.

- [ ] **Step 1: Implement the harness**

- **`playwright.config.ts`:**
  - `testDir: './scripts'`;
  - `webServer: { command: 'pnpm dev', url: 'http://localhost:3050', reuseExistingServer: true }`;
  - Desktop Chrome, `outputDir: 'test-results'`.
- **`shoot.spec.ts`:** for each viewport and each of `scene-1`, `scene-3` and `scene-4`:
  1. Call `__factory.seekScene(id)`, then `seekTime` to that scene's shot entry: the first `work` entry for `scene-1` and `scene-3`, and the `gate: 'deny'` work entry for `scene-4`.
  2. Pause, and wait 2 animation frames.
  3. Write `shots/<width>-<scene>.png`.
  4. Fail on any `console` error or `pageerror`.
- **`machineRects()`** projects each station's `Box3` to screen pixels.
- **`labelRects()`** returns `{}` until Phase 2 Task 6.

Browsers come from the shared Playwright cache: `pnpm --filter @yayloyalty/e2e exec playwright install chromium`.

- [ ] **Step 2: Verify the harness**

Run: `pnpm --filter @yayloyalty/factory-explainer shoot`

Expected:
- 1 passed;
- `shots/1440-scene-1.png`, `shots/1440-scene-3.png` and `shots/1440-scene-4.png` exist;
- `git status` doesn't list `shots/`.

Open the three images. Each shows the step card with that scene's first card.

- [ ] **Step 3: Commit**

Stage: the files above.
Message: `test(factory-explainer): add the screenshot harness`
