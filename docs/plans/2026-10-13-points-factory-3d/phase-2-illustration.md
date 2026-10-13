# Phase 2: The product-illustration look

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 1, Tasks 1–5: `<Station>` props, `<Stage>`, the timeline poses, the debug handle and `shoot`
**Delivers:** the scene looks like a premium product illustration.
- Kit models give each machine a distinct silhouette.
- Studio lighting and post-processing are on.
- The floor and props add life, and the workers walk.
- Small labels highlight their station on hover, with an outline and the rest of the scene dimmed.

**Exit criterion:**
- `pnpm --filter @yayloyalty/factory-explainer shoot` passes at 1440×900, including the label overlap assertions.
- The first critique round against the spec's checklist (criterion 17) is written into this file under `## Critique log`, with each item marked pass or fail.

### Task 1: Assets

**Files:**
- Create: `apps/factory-explainer/public/models/*.glb`, `public/models/LICENSE-kenney-factory-kit.txt`, `public/models/LICENSE-quaternius.txt`, `src/models.ts`

**Interfaces:**
- Produces: `MODELS: Record<ModelKey, string>`, the URL under `/models/` for each model key, and `useModel(key: ModelKey): GLTF | null`, which returns `null` when loading fails.

Pick the models from these sources:
- **Kenney Factory Kit 3.0** (`kenney_factory-kit_3.0.zip`, kenney.nl): models for the conveyors, rollers, machines and props.
- **Quaternius:** one CC0 humanoid `.glb` whose clips match `/walk/i` and `/idle/i`.

Download into a fresh scratch directory, not the repo, and copy only the models the scene uses. List each file's name and size to the user before committing. The total must be 8 MB or less.

- [x] **Step 1: Kenney models.** From the kit's `Models/GLB format/`, copy into `public/models/`:
  - stations: `scanner-high`, `hopper-square` (intake); `machine`, `cog-a` (scoring); `warning-traffic` (gate); `piston-square` (ledger); `structure-medium` (warehouse racks); `machine-fortified` (vault); `machine-window`, `conveyor-stripe` (return);
  - belts and props: `conveyor`, `conveyor-bars`, `cone`, `box-small`, and `screen-small` for the admin's console.

  The models load `Textures/colormap.png` by relative URL, so copy it to `public/models/Textures/`. Copy `License.txt` to `LICENSE-kenney-factory-kit.txt`.
- [x] **Step 2: Worker.** The free tier of the Quaternius Universal Animation Library, `Universal Animation Library[Standard].zip`, downloads only through itch.io's browser dialog. Its `UAL1_Standard.glb` is 7.6 MB with 43 clips. Trim it with `@gltf-transform` in scratch to `Idle_Loop` and `Walk_Loop`, disposing each dropped clip's channels and samplers before `prune()`, and save it as `public/models/worker.glb` (about 0.85 MB). Copy `License.txt` to `LICENSE-quaternius.txt`. Both licenses are CC0.
- [x] **Step 3: `src/models.ts`.** `MODELS` maps each `ModelKey` to `${BASE_URL}models/<key>.glb`. `loadModel(key)` caches one `GLTFLoader` promise per key, and a failed load resolves to `null` with a `console.warn`. `useModel(key)` is React 19's `use(loadModel(key))`: it suspends while loading, then returns the model or `null`. Callers clone the scene, using `SkeletonUtils.clone` for the worker.
- [x] **Step 4: Verify.** `pnpm --filter @yayloyalty/factory-explainer exec tsc --noEmit` exits 0. In the dev page, a one-off Playwright script calls `loadModel` for every key: each returns a scene, with no 404 for the texture, `worker` has the clips `Idle_Loop+Walk_Loop`, and a key pointing at a missing file returns `null`.
- [x] **Step 5: Commit.** List each file's name and size to the user, then stage `public/models/` and `src/models.ts` by name: `feat(factory-explainer): add the kit models and worker`.

### Task 2: Station silhouettes

**Files:**
- Create: `apps/factory-explainer/src/scene/stations/Intake.tsx`, `Scoring.tsx`, `Gate.tsx`, `Ledger.tsx`, `Warehouse.tsx`, `Vault.tsx`, `Return.tsx`, `Blueprint.tsx`, `src/scene/materials.ts`
- Modify: `src/scene/Station.tsx` (dispatch by `type`)

**Interfaces:**
- Consumes: `useModel` and `propPose`.
- Produces:
  - `ACCENTS: Record<StationType, string>`, at HSL saturation 60 % or less:
    - teal, violet, amber, indigo, neutral, neutral and red for the built types, in the order of `StationType`;
    - blueprint blue for unbuilt.
  - `material(kind: 'graphite' | 'grey' | 'metal' | 'accent' | 'cardboard' | 'floor')`, which applies the roughness and metalness rule from Global constraints.

Each component draws the silhouette in spec criterion 4. Unbuilt stations are blue wireframes over a dashed floor zone.

- [x] **Step 1: Shared parts.** `materials.ts` holds `ACCENTS`, moved from `Station.tsx`, and `material(kind, color?)`, which returns a fresh material on each call, so Task 6 can dim one station without dimming the others. `metal` is the only kind with metalness. `stations/Kit.tsx`, a file this task adds, holds:
  - `Part`, a `RoundedBox` in one of those kinds;
  - `Plinth`, the graphite base under every built station;
  - `Kit`, which fits a kit model inside a `fit` box, uniformly or with `stretch`, and replaces its materials. It sits in a `Suspense` whose fallback is a `Part` of the `fit` size, and it draws the same `Part` when `useModel` returns `null`.
- [x] **Step 2: Silhouettes.** Each type draws its machine on the back (−z) half, so the box lane through the centre stays clear:
  - intake: `hopper-square`, plus a `scanner-high` arch over the belt with a teal cap;
  - scoring: a stretched `machine`, with a violet `cog-a` dial on its front face that turns by `propPose().dial`;
  - gate: a `warning-traffic` post, and an amber arm that lifts by `gateArm` and flashes `#b84a3d` by `gateDeny`;
  - ledger: two graphite columns and a beam with an indigo cap, and a metal `piston-square` head on a rod that drops by `pressHead`;
  - warehouse: `structure-medium` uprights, three shelves and `box-small` cartons;
  - vault: a metal `machine-fortified`, with a round metal door and a spoked handle;
  - return: a `machine-window` with a red `conveyor-stripe` chute;
  - unbuilt: a dashed drei `Line` zone and a translucent `RoundedBox` with `Edges`.

  `Station.tsx` keeps the hover and click handlers and picks the component from a `Record<StationType, …>`.
- [x] **Step 3: Verify.** `tsc --noEmit` exits 0, and `shoot` passes with no console errors. A 2× shot of the floor shows each silhouette, with no machine across the belt lane except the scanner arch and the press beam, which span it. Grep the station files for `boxGeometry`: there are no matches. Every hex colour in `stations/` and `materials.ts` is at HSL saturation 60 % or less; the highest is the unbuilt blue, at 57 %.
- [x] **Step 4: Commit.** Stage `src/scene/materials.ts`, `src/scene/Station.tsx`, `src/scene/stations/*.tsx` and this file: `feat(factory-explainer): give each station its own silhouette`.

### Task 3: Lighting and post-processing

**Files:**
- Create: `apps/factory-explainer/src/scene/Lighting.tsx`, `src/scene/Effects.tsx`
- Modify: `src/scene/Stage.tsx`

**Interfaces:**
- Produces: `<Lighting />` and `<Effects />`.

Lighting and effects follow spec criterion 7:
- a hemisphere light, and a directional key light with shadow map 2048;
- `ContactShadows`, and `Environment preset="city"` at an intensity of 0.4 or less;
- N8AO, SMAA and ACES tone mapping;
- bloom at `luminanceThreshold` 1, where only the LED materials reach it (`toneMapped={false}`, emissive intensity 3 or more).

Steps: expand after Phase 1 lands.

### Task 4: Floor, props and conveyor motion

**Files:**
- Create: `apps/factory-explainer/src/scene/Props.tsx`
- Modify: `src/scene/Floor.tsx`, `src/scene/Conveyor.tsx`, `src/factory.config.ts` (add `PROPS: Prop[]` with `Prop = { kind: 'pallet' | 'cone' | 'forklift' | 'cable-tray'; position: Vec2; rotation: number }`)

**Interfaces:**
- Produces: `PROPS` in the config, as the README contract notes.

**Floor:** a warm off-white surface with:
- a tile grid and painted lane lines;
- yellow and black stripes at the gate;
- zone-name decals.

**Props:** low contrast, with the forklift parked by the warehouse.

**Conveyors:**
- Rollers spin and the belt texture scrolls in proportion to `time`, so they stand still while paused.
- Under reduced motion, they never move.

Steps: expand after Phase 1 lands.

### Task 5: Walking workers

**Files:**
- Create: `apps/factory-explainer/src/scene/Worker.tsx`
- Modify: `src/scene/Actor.tsx` (use `<Worker>` when the model loads, and keep the capsule as the fallback)

**Interfaces:**
- Consumes: `actorPose` and `useModel`.

The worker cross-fades between walk and idle on `actorPose().walking`, turns to `heading`, and plays the clips from `time` rather than wall-clock time, so a seek shows the right frame. The admin idles at a console prop.

Steps: expand after Phase 1 lands.

### Task 6: Labels, hover and overlap assertions

**Files:**
- Create: `apps/factory-explainer/src/ui/StationLabel.tsx`
- Modify:
  - `src/scene/Station.tsx`: mount the label, wrap the station in `<Select>`, and dim it when another station is hovered;
  - `src/scene/Effects.tsx`: add `<Selection>` and `<Outline>`;
  - `src/debug.ts`: implement `labelRects()`;
  - `scripts/shoot.spec.ts`: add the overlap assertions.

**Interfaces:**
- Produces: `.station-label[data-station=<id>]`, and a real `labelRects()`.

**Label:** drei `<Html occlude>`, anchored at the top face plus `labelHeight`, per spec criterion 10. It shows the name, and on hover adds the type, the class and the description.

**Dimming:** each mesh's material colour lerps to 55 % of its base, and back.

**`shoot` assertions,** for every pair of labels and at each shot:
- the label rectangles don't intersect;
- no label rectangle intersects its own `machineRects()` entry.

Steps: expand after Phase 1 lands.

## Critique log

Filled in at the Phase 2 exit.
