# Points Factory 3D Explainer Design

**Status:** accepted 2026-10-13
**Related:** Supersedes [the 2D explainer design](2026-10-13-points-factory-explainer-design.md)

## Goal

Rebuild the points factory explainer as a real-time 3D scene that looks like a premium product illustration (Stripe, Linear or Vercel marketing quality), not a diagram. The audience is the internal team (developers and support), and the scene shows the same lifecycle, scenes and code names as the 2D page it replaces.

## Context

**Today**
- `docs/explainers/points-factory.html` is a single-file isometric SVG with GSAP from cdnjs. Its spec is [the 2D explainer design](2026-10-13-points-factory-explainer-design.md).
- It holds the data this design keeps:
  - 12 stations, each with role, label, file, methods and summary;
  - 14 belts;
  - 4 actors: Alice, Bob, Cara and the admin;
  - 6 scenes with 23 steps. Each step has a caption label and a process card (`fn`, `lines[]`, `out`). The card text was checked against the PHP at commit `2addcbf` plus the uncommitted card work.
- **Behaviour it keeps:**
  - Play/Pause, Restart, jump to a scene, and Space to toggle.
  - Clicking a station opens a details panel, which pauses the loop; closing the panel resumes it.
  - Hovering a package or the process card pauses a playing loop, and leaving resumes it. The user's own Play/Pause takes over from the hover, and a panel opened during a hover resumes the loop when it closes.
  - With reduced motion, the page loads paused on the final state.
- `docs/architecture.md` links the page from "Server layers and write ownership".

**Workspace**
- `pnpm-workspace.yaml` lists packages explicitly: `apps/admin` and `apps/e2e`.
- `pnpm-lock.yaml` is gitignored.
- `apps/` never ships: `build_package` in `scripts/run.sh` copies only `yayloyalty.php`, `index.php`, `YayLoyaltyPluginAdapter.php`, `readme.txt`, `assets/`, `includes/`, `languages/`, `vendor-prefixed/` and Composer's autoloader.
- `apps/e2e` already depends on `@playwright/test` 1.63.0.

**Assets** (all CC0)
- [Kenney Factory Kit 3.0](https://kenney.nl/assets/factory-kit) (`kenney_factory-kit_3.0.zip`), tagged conveyor, belt, industrial and warehouse. The separate Kenney "Conveyor Kit" page returns 404.
- [Quaternius](https://quaternius.com/packs/universalanimationlibrary.html) humanoid packs with glTF walk and idle animations.

## Scope

**In:**
- A new workspace package `apps/factory-explainer/` (`@yayloyalty/factory-explainer`): a Vite + React + react-three-fiber app.
- One config file that drives:
  - the layout: stations, conveyors, props, actors and label anchors;
  - the story: scenes and steps.
- The 6 scenes and 23 steps from the 2D page, with every caption label and process card copied word for word.
- Distinct station silhouettes, studio lighting, post-processing, an animated floor and props, workers who walk, and blueprint zones for the unbuilt stations.
- **Labels and panels:**
  - minimal hover labels;
  - outline-and-dim on hover;
  - a side panel on click;
  - a docked process card (HUD);
  - a toolbar with the caption.
- The controls and pause rules listed under Context.
- Layouts at 1440×900 and 390×844.
- A Playwright screenshot script with label overlap assertions, and a critique against the checklist for up to 3 rounds.
- **Docs:**
  - delete `docs/explainers/points-factory.html`;
  - point `docs/architecture.md` at the app's README;
  - mark the 2D spec `superseded by` this doc.

**Out:**
- Shipping anything in the plugin zip, a WordPress or REST call, or live store data. The data stays fixed and illustrative.
- Pro triggers, and redemption, expiry and tiers beyond their blueprint zones.
- Translation and dark mode.
- A committed static build. Teammates run the app with pnpm.
- Free camera rotation.
- Tests beyond the screenshot script: this isn't money or ledger logic.

## Acceptance criteria

1. **Commands:**
   - `pnpm --filter @yayloyalty/factory-explainer dev` serves the app.
   - `pnpm --filter @yayloyalty/factory-explainer build` writes to the gitignored `apps/factory-explainer/dist/`.
   - `pnpm --filter @yayloyalty/factory-explainer exec tsc --noEmit` passes.
2. **Story:**
   - The loop plays on load and repeats the 6 scenes in the order and with the steps of the 2D page.
   - The caption shows each step's label, and the HUD card shows its `fn`, `lines` (✓ in green, ✗ in red) and `out`, with the text identical to the 2D page's `SCENES`. *(Restyled by the user on 2026-10-13: the HUD card is a speech bubble at the working station, its lines reveal one by one as numbered steps from the playhead, each station works for 1.2 s plus 0.9 s per card step, and a tracker under the caption shows the scene's steps.)*
3. **Layout and story live in `src/factory.config.ts`.** Moving a station there moves its machine, label and conveyor ends with no other code change.
4. **Station silhouettes:**

   | Station | Silhouette | Accent |
   |---|---|---|
   | Intake (×3) | Hopper or loading dock with a scanner arch | Teal |
   | Scoring (`EarningService`) | Rotating dial | Violet |
   | Gate (`ProgramGate`) | Barrier arm that lifts for a passing box. A rejected box slides down a diverter into the reject bin. Yellow and black floor stripes at the gate | Amber |
   | Ledger | Stamping press whose head moves down on each box | Indigo |
   | Warehouse (`PendingPoints`) | Shelving racks where pending boxes stack | Neutral |
   | Vault (`customers`) | Heavy safe with a round door and handle | Neutral, metal |
   | Return (`OrderReversalListener`) | Machine with a red return chute | Red |
   | Redemption, Expiry, VIP tiers | Translucent blue wireframes on a dashed floor zone, labelled "not built yet" | Blueprint blue |

   No box ever enters an unbuilt zone.
5. **Geometry:** machines come from Kenney Factory Kit GLTF models where one fits. Anything else is built from drei `RoundedBox` with a bevel on every edge. No unbevelled box geometry appears in the scene.
6. **Colour and materials:** *(Superseded by the user's cartoon restyle on 2026-10-13: toon materials with three light bands, a bright palette with one bright accent per machine, and dark ink outlines on every solid replace the calm palette and `MeshStandardMaterial`.)*
   - Each machine has at most one accent colour, at HSL saturation 60 % or less.
   - Machines are neutral grey or graphite, and the floor is a warm off-white.
   - Materials are `MeshStandardMaterial` with roughness 0.5–0.8. Metalness is above 0 only on the press, the vault and the rollers.
7. **Rendering:** *(Superseded by the user's cartoon restyle on 2026-10-13: N8AO is dropped, since AO muddies the flat toon bands; the rest still holds.)*
   - The camera is orthographic at the isometric angle (35.264° pitch, 45° yaw).
   - Pan and zoom work within limits, and rotation is disabled.
   - Lights: a hemisphere light plus one directional key light with soft shadows (shadow map 2048, `PCFSoftShadowMap`), `ContactShadows` and `Environment preset="city"` at an intensity of 0.4 or less.
   - Post-processing: N8AO, SMAA and ACES tone mapping, with bloom only on the status LEDs.
8. **Floor:**
   - The floor has a tile grid, painted lane lines and zone-name decals.
   - Low-contrast props: pallets, cones, a forklift parked by the warehouse, and cable trays.
9. **Motion:** *(Superseded by the user's cartoon restyle on 2026-10-13: the workers are chunky yellow cartoon characters built from primitives, with a bounce, a waddle and swinging limbs from the playhead, in place of the walk animation; boxes hop and squash, the gate arm overshoots and the press head squashes.)*
   - Rollers rotate and the belt textures scroll.
   - Boxes follow `CatmullRomCurve3` paths at constant speed.
   - Boxes are cardboard with an `ORDER`, `SIGNUP`, `REVIEW` or `MANUAL` tag, and the tag adds the points value once the box leaves scoring.
   - Alice, Bob and Cara walk to their intake with the walk animation, set the box down, and walk back to idle. The admin stands at a console.
   - The refund box travels the red belt.
   - Every change of position or rotation is eased: nothing teleports.
10. **Labels:**
    - Each station has a drei `<Html occlude>` label: 12 px text on a frosted background (`backdrop-filter: blur`), with a thin border and a short leader line, placed above its machine. *(The user approved one exception on 2026-10-13: the three unbuilt stations' labels hang below their zones, because above them they landed on the vault.)*
    - By default a label shows the service name only. Hovering it adds the type, the class name and a one-line description.
    - At both viewports and at each sampled time, no two label rectangles intersect, and no label rectangle covers the screen-space bounding box of its own machine.
11. **Station interaction:**
    - Hovering a station outlines it and dims the rest of the scene.
    - Clicking it pauses the loop and opens a side panel with:
      - the class and repo-relative file;
      - the hooks;
      - the key methods;
      - inputs and outputs.
    - Closing the panel resumes the loop if it was playing.
12. **Controls:**
    - Play/Pause, Restart, scene jump and Space work as in the 2D page.
    - Hovering a box or the HUD card pauses a playing loop, and leaving resumes it.
    - The user's own Play/Pause takes over from the hover.
    - A panel opened during a hover resumes the loop when it closes.
13. With `prefers-reduced-motion: reduce`, the app loads paused on the final state, and the rollers, belt scroll and dial stand still.
14. **At 390×844:** *(dropped by the user on 2026-10-13: the explainer targets desktop only. Criteria 10 and 17 apply at 1440×900 only.)*
    - the toolbar wraps;
    - the HUD card becomes a bottom sheet;
    - the side panel goes full width;
    - the camera zoom fits the whole floor;
    - the page doesn't scroll horizontally.
15. **Assets:**
    - Only the `.glb` files the scene loads are committed, under `apps/factory-explainer/public/models/`, with each pack's license file.
    - They total 8 MB or less.
    - Each file's name and size is listed to the user before the commit.
16. **Docs:**
    - `docs/explainers/points-factory.html` is deleted.
    - `docs/architecture.md` links `apps/factory-explainer/README.md`, which gives the run command.
    - The 2D spec's Status reads `superseded by` this doc.
    - `./scripts/check-harness.sh` passes.
17. End-to-end verification: run `pnpm --filter @yayloyalty/factory-explainer shoot`.
    1. It starts the dev server.
    2. It takes Playwright screenshots at 1440×900 and 390×844, at the start of scenes 1, 3 and 4 (gate deny).
    3. It asserts criterion 10's overlap rules, and exits 0 with no console errors.

    Then critique the six screenshots against this checklist, and iterate up to 3 rounds until every item passes:
    - every machine has a distinct, recognisable silhouette;
    - soft shadows and AO are visible around every object;
    - no labels overlap and no label covers a machine;
    - at most one accent per object, and the palette is calm;
    - nothing looks like a plain unbevelled box;
    - motion holds 60 fps in drei's `<StatsGl>` (`?perf`) at 1440×900.

    The browser extension's tab is hidden, so the frame-rate item is read in a headed Playwright run, or by the user.

## Constraints

- **Node and pnpm:** Node 22 (`.nvmrc`) and pnpm only, the repo's pnpm 11. The package is registered in `pnpm-workspace.yaml`.
- **Dependencies:** majors are React 19, `three`, `@react-three/fiber` 9, `@react-three/drei` 10, `@react-three/postprocessing` 3, Vite 6 and TypeScript 5.9. Versions are pinned exactly in `package.json`. The frame-rate meter is drei's `<StatsGl>`, mounted only behind `?perf`. `r3f-perf` was dropped on 2026-10-13: its last release, 7.2.3, depends on drei 9 and supports only React 18 and fiber 8.
- **Isolation:** nothing is imported from `apps/admin`, and nothing is added to `includes/`, `assets/` or the release zip.
- **Generated files:** `dist/` and the lockfile stay uncommitted (hard rule 1).
- **Code names:** class names, methods, hooks, meta keys and enum values match the code, as the 2D page's text does.
- **Asset licences:** CC0 only, with each pack's license file committed beside its models.

## Approach

**Chosen:** a React Three Fiber app in a workspace package, with the scene data in one config file.

**Architecture:** `factory.config.ts` is the single source for layout and story. Components read the stations, conveyors, props and actors from it and place GLTF models (`useGLTF`), or `RoundedBox` fallbacks, at those positions.

A small `zustand` store holds the scripted clock:
- the playhead, the paused flag and the hover hold;
- the step list compiled from `scenes[]`, with each step's start and end.

`useFrame` advances the playhead. Each moving thing reads its own pose from the playhead:
- boxes, by arc-length progress on the conveyor's `CatmullRomCurve3`;
- workers, by a walk/idle cross-fade;
- the gate arm, the press head and the dial.

A seek therefore renders the same frame as playback does. DOM overlays (toolbar, HUD card, side panel and labels) subscribe to the store.

**Components:**
- `apps/factory-explainer/src/factory.config.ts`: stations, conveyors, props, actors, label anchors, scenes and steps.
- `src/store.ts`: the clock, step compilation, controls and pause rules.
- `src/scene/`:
  - `Floor`, `Lighting`, `Effects`;
  - one component per station type: `Intake`, `Scoring`, `Gate`, `Ledger`, `Warehouse`, `Vault`, `Return`, `Blueprint`;
  - `Conveyor`, `Box`, `Worker`, `Props`.
- `src/ui/`: `Toolbar`, `StepCard`, `StationPanel`, `StationLabel`.
- `scripts/shoot.ts`: the Playwright screenshots and overlap assertions.
- `public/models/`: the `.glb` files and license files.
- `README.md`: what the app is, the run commands, and the asset credits.

**Data and storage:** none at runtime. All data is fixed in `factory.config.ts`. Per-viewer conveniences use no browser storage.

**Integration points:** none at runtime. The page only names hooks; it calls none. The only shared file changed is `pnpm-workspace.yaml`.

**UI:** a full-viewport canvas, overlaid with:
- a top toolbar: Play/Pause, Restart, the scene buttons, and the caption with a progress bar;
- a frosted HUD card bottom-left (a bottom sheet at 390 px);
- a right side panel.

**Edge cases and failure modes:**
- A model fails to load: a `Suspense` fallback shows the `RoundedBox` version, so the scene still renders.
- WebGL is unavailable: a static notice explains it.
- The tab is hidden: `useFrame` stops, and on return the clock resumes from where it was, without jumping.
- A resize between 390 and 1440 px: the camera zoom refits the floor.
- Labels overlap after a layout change: `shoot` fails.

**Decision record:** none. This is internal tooling, and it makes no architectural choice for the plugin.

## Alternatives considered

| Option | Why rejected |
|---|---|
| Keep the single SVG + GSAP file | Can't reach the 3D, lighting and material quality the user asked for. |
| Vanilla three.js from a CDN in one HTML file | Drops the React/R3F stack the user asked for. |
| A standalone project outside the repo | The user chose a workspace package, versioned with the code it describes. |
| Commit a static build into `docs/` | Commits generated JS and must be rebuilt by hand. The user chose pnpm dev/preview. |
| Keep the 2D page as a no-build fallback | The user chose to replace it. |
| Per-step cards floating above the package | Would cover machines, which the labels rule forbids. The user chose the docked HUD. |
| Drawing machines only from primitives | The user approved downloading the CC0 kits; primitives are only the fallback. |

## Rollout

None. It's internal tooling outside the release zip, and merchants are unaffected. It lands through a PR into `dev/edd`. Teammates need `pnpm install` once to get the new package's dependencies.

## Open questions

None.
