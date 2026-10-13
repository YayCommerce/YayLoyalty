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
  - props: `cone`, `box-small`, and `screen-small` for the admin's console.

  `conveyor` and `conveyor-bars` were copied at first, then removed in the Phase 2 review: Task 4 builds the belts and rollers as custom geometry, so nothing loads them.

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

- [x] **Step 1: Environment image.** drei's `preset="city"` fetches `potsdamer_platz_1k.hdr` from raw.githack.com on every load. Download the same file from Poly Haven: it is byte-identical to drei's copy, by SHA-1, and CC0. Commit it as `public/env/potsdamer_platz_1k.hdr` (1.5 MB) with `LICENSE-polyhaven.txt`, and load it with `<Environment files=…>`, so neither the page nor `shoot` needs the network.
- [x] **Step 2: `<Lighting />`.** It holds:
  - a hemisphere light, `#fbf8f3` over `#b9b2a6` at 0.8;
  - a directional key at 2.5, with a 2048 shadow map, bias −0.0004 and normal bias 0.05. The normal bias clears shadow acne on the belts;
  - `ContactShadows` over the floor;
  - the environment at `environmentIntensity` 0.35, inside its own `Suspense`, so the HDR load never blanks the scene.

  `Stage` drops its ambient and directional lights and mounts `<Lighting />` and `<Effects />`. It also asks for `PCFShadowMap`: three r186 removed `PCFSoftShadowMap` and swaps it for `PCFShadowMap` with a console warning.
- [x] **Step 3: `<Effects />`.** In order: N8AO (radius 0.9, `halfRes`), bloom (threshold 1, `mipmapBlur`), ACES tone mapping, then SMAA. Full-resolution N8AO at 2× DPR timed out a headless screenshot, so it stays `halfRes`.
- [x] **Step 4: Status LEDs.** `StatusLed` in `stations/Kit.tsx` puts a small sphere on a stalk at each built station's front-left plinth corner, in that station's accent, with `toneMapped={false}`. Its emissive intensity is 3 while `activeStation()` names the station, and 0.35 otherwise. The intensity is a function of `time` alone, so a seek shows the right LEDs. `Station.tsx` mounts it for every built station.
- [x] **Step 5: Verify.** `tsc --noEmit` exits 0. `shoot` passes, taking about 20 s instead of 6 s now that post-processing runs. In a 2× shot, only the working station's LED blooms, graphite still reads darker than grey, and the belts show no acne pattern. `grep -rn "githack\|preset=" src` finds nothing.
- [x] **Step 6: Commit.** Stage `public/env/*`, `src/scene/Lighting.tsx`, `Effects.tsx`, `Stage.tsx`, `Station.tsx`, `stations/Kit.tsx` and this file: `feat(factory-explainer): add studio lighting and post-processing`.

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

- [x] **Step 1: Floor.** `Floor.tsx` paints one `CanvasTexture`, 64 px per world unit, over the whole slab. It carries:
  - a 1-unit tile grid;
  - a dashed lane around the customers' walkway and a solid line around the machine floor;
  - yellow and black stripes in a 0.4-unit band around the gate's footprint;
  - each station's `role` in capitals, painted in front of the station.

  Every colour is low contrast against `#efe9df`. The texture lies on a plane inset by the slab's 0.12 bevel and is cropped to match. Canvas text uses the system font, so no font is fetched.
- [x] **Step 2: Props.** Add `Prop` and `PROPS` to the config: three pallets, a forklift beside the warehouse with its forks facing the camera, three cones by the reject bin and the red belt, and two cable trays along the floor edges. `Props.tsx` builds each kind from `Part`s and the kit's `box-small` and `cone`. `App.tsx` mounts `<Props />`.
- [x] **Step 3: Conveyors.**
  - The belt profile gets 0.04 bevels on its top edges, and UVs whose `v` counts treads along the belt.
  - One greyscale tread `CanvasTexture`, shared by every belt and tinted by each belt's colour, scrolls by `time × 0.8 / 0.18`.
  - A metal roller at each belt end spins by the same distance.
  - `store.ts` exports `reduced`, and under it the distance is always 0.
  - Each belt has its own `polygonOffset`, so the belts that overlap at a station no longer z-fight.
- [x] **Step 4: `shoot` waits for the loaded scene.** Kit models show their fallback boxes for about 3 s after load in headless Chromium, so a shot could catch them. `Kit` now counts its loading fallbacks in `kitLoading`, and the debug handle exposes it as `loading()`. `shoot` opens the page with `waitUntil: 'networkidle'`, which covers the HDR, and then waits for `loading() === 0`.
- [x] **Step 5: Verify.** `tsc --noEmit` exits 0, and `shoot` passes. A one-off Playwright script loads the page the way `shoot` does, pauses, and crops an empty stretch of belt. It counts pixels that differ by more than 3:
  - with normal motion: the first frame and a repeat are identical (0), the frame at `t = 10.5` differs from `t = 10` (1015), and seeking back to 10 is identical again (0);
  - under `reducedMotion: 'reduce'`: all three counts are 0.
- [x] **Step 6: Commit.** Stage `src/scene/Floor.tsx`, `Conveyor.tsx`, `Props.tsx`, `stations/Kit.tsx`, `src/factory.config.ts`, `src/store.ts`, `src/App.tsx`, `src/debug.ts`, `scripts/shoot.spec.ts` and this file: `feat(factory-explainer): paint the floor, add props and move the belts`.

### Task 5: Walking workers

**Files:**
- Create: `apps/factory-explainer/src/scene/Worker.tsx`
- Modify: `src/scene/Actor.tsx` (use `<Worker>` when the model loads, and keep the capsule as the fallback)

**Interfaces:**
- Consumes: `actorPose` and `useModel`.

The worker cross-fades between walk and idle on `actorPose().walking`, turns to `heading`, and plays the clips from `time` rather than wall-clock time, so a seek shows the right frame. The admin idles at a console prop.

- [x] **Step 1: `Worker.tsx`.** Clone the worker with `SkeletonUtils.clone` and scale it to 1.45 units tall. The body takes the actor's colour and the joints graphite; a mesh may hold an array of materials. Each frame:
  - sets each clip's `time` from story time: walk at `time × 0.7`, which matches the 0.75 units/s walk, and idle at `time`;
  - calls `mixer.update(0)`, so no wall clock is involved;
  - sets the walk weight to the share of six `actorPose` samples over the last 0.3 s that are walking, and the idle weight to the rest;
  - sets the heading to the circular mean of the same samples.

  The fade and the turns are therefore eased, and still pure functions of `time`.
- [x] **Step 2: `Actor.tsx`.** `Figure` calls `useModel('worker')` and draws `<Worker>`, or the Phase 1 capsule, now `Capsule`, when the model is `null`. The capsule is also the `Suspense` fallback. `Kit.tsx`'s loading counter becomes a general `Pending` wrapper, so the loading capsule counts toward `loading()` too. The admin's console gets a `screen-small` monitor.
- [x] **Step 3: Verify.** `tsc --noEmit` exits 0, and `shoot` passes with no console errors. Paused 2× crops show:
  - all four workers idling and facing the belts before scene 1's walk;
  - Bob 0.6 s and 0.8 s into scene 2's walk, in two different stride poses, facing the way he walks and carrying the box;
  - the admin at the console with its monitor.
- [x] **Step 4: Commit.** Stage `src/scene/Worker.tsx`, `Actor.tsx`, `stations/Kit.tsx` and this file: `feat(factory-explainer): walk the workers`.

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

- [x] **Step 1: `StationLabel.tsx`.** A drei `<Html occlude>` anchored over the back corner of the top face, at `[-w/2, PLINTH + h + labelHeight, -d/2]`. In the iso view that corner is the highest point of the machine's screen box, so a label sitting above it on its 10 px leader never covers its own machine, whatever the zoom. The anchor is still on the top face, as the README contract says. `labelHeight` therefore only clears parts taller than `size`: 0.25 by default, 0.45 for the intakes' scanner arch, 0.6 for the press beam and 1.2 for the gate's post.

  The label shows `label`, plus a "not built yet" tag on unbuilt stations. Hovering it, or its machine, adds `role`, `file` and `description`. It mounts one animation frame late: on the first mount, StrictMode's effect replay made drei's `Html` unmount its root mid-render, and React logged that as 12 console errors, which failed `shoot`. The styles are in `ui.css`.
- [x] **Step 2: Outline and dimming.**
  - `Station.tsx` wraps the machine and its LED in `<Select enabled={hovered}>`, and mounts the label beside them.
  - `<Selection>` lives in `Stage.tsx`, not `Effects.tsx`, because it must wrap both the stations' `Select`s and the composer.
  - `Effects.tsx` adds `<Outline>` in `#5b63b0`. It needs `<EffectComposer autoClear={false}>`; without that it logged a warning and drew nothing.
  - Every frame, each station eases `userData.dim` toward 0.55 while another station is hovered, and toward 1 otherwise, and sets each material's colour to that share of its base.
  - The gate's arm marks its material `animated` and applies `dim` to its own flash colour, so the dimming never fights the flash.
  - Only stations dim, as this task says. Spec criterion 11 says "the rest of the scene", which would include the belts, floor and props.
- [x] **Step 3: `labelRects()` and `shoot`.** `labelRects()` skips labels with zero size, which are the ones occlusion has hidden. At each shot, `shoot` asserts that at least one label is visible, that no two label rectangles intersect, and that no label intersects its own `machineRects()` entry.
- [x] **Step 4: Verify.** `tsc --noEmit` exits 0, and `shoot` passes with the overlap assertions and no console errors. All 12 labels are visible at t = 5, 70, 100 and 140. In a headed check:
  - hovering the LedgerService machine, or its label, expands the label, outlines the press and dims the other machines. The scoring machine's mean brightness drops by 14;
  - moving away clears the hover.
- [x] **Step 5: Commit.** Stage `src/ui/StationLabel.tsx`, `src/ui/ui.css`, `src/scene/Station.tsx`, `Stage.tsx`, `Effects.tsx`, `stations/Gate.tsx`, `src/factory.config.ts`, `src/debug.ts`, `scripts/shoot.spec.ts` and this file: `feat(factory-explainer): label the stations and outline the hovered one`.

## Critique log

### Round 1, 2026-10-13

These are the three 1440×900 `shoot` shots: scene 1, scene 3, and scene 4 at the gate deny. The 390×844 shots come in Phase 3.

| Checklist item | Result | Notes |
|---|---|---|
| Every machine has a distinct, recognisable silhouette | pass | Hopper and scanner arch, cog-dial housing, signal post and barrier, press frame, racks, round-door safe, red chute, blueprint wireframes. |
| Soft shadows and AO are visible around every object | pass | The key light's shadows, contact shadows and N8AO show under machines, props, workers and boxes. |
| No labels overlap and no label covers a machine | fail | `shoot`'s assertions pass: no two labels meet, and none covers its own machine. But some labels sit on other machines or the HUD:<br>• OrderListener's label is under the toolbar, with only its leader showing;<br>• EarningService's label sits on the OrderListener intake;<br>• `customers` sits on the PendingPoints racks and crowds the box tag parked there;<br>• Expiry and VIP tiers sit over the vault. |
| At most one accent per object, and the palette is calm | pass | Every machine accent is at 57 % HSL saturation or less. One note: the workers keep the 2D page's actor colours, 80–92 % saturated, and they are the loudest thing on screen. |
| Nothing looks like a plain unbevelled box | pass | Every box is a `RoundedBox` or a kit model, and the belts have beveled edges and end rollers. |
| Motion holds 60 fps at 1440×900 | fail | Measured in a headed Chromium by counting `requestAnimationFrame` calls over 5 s of playback: 60.1 fps (worst frame 17.8 ms) at DPR 1, but 23.9 fps (worst frame 100 ms) at DPR 2, which a Retina Mac uses. `<StatsGl>` behind `?perf` is not mounted: no task in Phase 1 or 2 adds it. |

Also seen: the fitted floor puts Alice's home spot and OrderListener's label under the toolbar (Phase 1 framing).

Ideas for round 2, in Phase 3:
- frame the floor below the toolbar;
- cap the DPR, at 1.5 for example, or drop N8AO's quality under load;
- check labels against every machine's rectangle, not just their own, and move the ones that collide;
- mount `<StatsGl>` behind `?perf`.
