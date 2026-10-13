# Points Factory 3D Implementation Plan

> **For agentic workers:** execute phases in order. Read this file, then the current phase file. Do not start a phase until the previous phase's exit criterion has been verified and `/yayplancheck` has passed on it.

**Status:** active
**Goal:** Rebuild the points factory explainer as a real-time 3D react-three-fiber app in `apps/factory-explainer/`, replacing the 2D page.
**Spec:** [docs/specs/2026-10-13-points-factory-3d-design.md](../../specs/2026-10-13-points-factory-3d-design.md)
**Architecture:** One config file holds the layout and the story. One store owns a scripted playhead, and every moving thing derives its pose from that playhead, so a seek renders the same frame as playback does. The first phase draws everything from beveled primitives so the story and controls work end to end. The second phase swaps in kit models and the lighting, and the third makes it responsive and retires the 2D page.
**Touches:**
- `apps/factory-explainer/` (new)
- `pnpm-workspace.yaml` (shared: every pnpm install reads it)
- `docs/architecture.md`
- `docs/explainers/points-factory.html` (deleted)
- `docs/specs/2026-10-13-points-factory-explainer-design.md` (status only)

**Tests:** none. CLAUDE.md warrants tests only for money or ledger logic, or for a fixed bug. Each task verifies with the `shoot` run or an exact manual check.

## Global constraints

- **Toolchain:**
  - Run `nvm use` first: the repo needs Node 22 (`.nvmrc`), and this shell defaults to Node 20.
  - Use pnpm only: the repo's pnpm 11.
- **Registration:** the package is `@yayloyalty/factory-explainer`, registered in `pnpm-workspace.yaml`.
- **Dependency majors:**
  - React 19, `three`, `@react-three/fiber` 9, `@react-three/drei` 10 and `@react-three/postprocessing` 3;
  - `zustand` 5, Vite 6 and TypeScript 5.9;
  - `@playwright/test` 1.63.0, the same as `apps/e2e`.

  Pin exact versions in `package.json` (`pnpm add --save-exact`). The frame-rate meter is drei's `<StatsGl>`, mounted only behind `?perf`. `r3f-perf` is not used: it requires React 18 and fiber 8.
- **Isolation:** import nothing from `apps/admin`, and add nothing to `includes/`, `assets/` or the release zip.
- **Never committed:** `dist/`, `shots/`, `test-results/`, `playwright-report/` and the lockfile (hard rule 1).
- **Code names:** class names, methods, hooks, meta keys and enum values are written exactly as in the 2D page's `STATIONS` and `SCENES`.
- **Assets:** CC0 only, with each pack's license file committed beside its models. The `.glb` files in `public/models/` total 8 MB or less. List each file's name and size to the user before committing.
- **Commits:**
  - Conventional Commits, with scope `factory-explainer`, ending with the trailers CLAUDE.md and the session require.
  - Stage files by name.
  - Commit on `trace/docs/points-factory-explainer`, which is unpushed. The user publishes it with GitHub Desktop.
- **Shading:** materials are `MeshStandardMaterial` with roughness 0.5–0.8. Metalness is above 0 only on the press, the vault and the rollers. Each machine has at most one accent, at HSL saturation 60 % or less.

## Review focus

| Risk | Expected behaviour | Checked in |
|---|---|---|
| Seek, scene jump or loop wrap | The scene is exactly what playback shows at that time: no box left visible from an earlier scene, no stale tint, no gate stuck open | Phase 1 Task 2, Step 3 |
| Pause precedence: Space or the Play button during a hover; a panel opened during a hover | The user's own choice wins over the hover. A panel opened during a hover resumes the loop on close | Phase 1 Task 4, Step 3 |
| Tab hidden, then shown | The playhead resumes where it was, with no jump: `tick` clamps `dt` to 0.1 s | Phase 1 Task 2, Step 3 |
| Labels after a layout change | `shoot` fails on any overlap or on a label covering its own machine | Phase 2 Task 6 |
| A model fails to load | The `RoundedBox` fallback renders in its place | Phase 3 Task 1 |
| No WebGL | A static notice appears | Phase 3 Task 1 |
| Card text drifts from the 2D page | Every `fn`, `lines` and `out` matches the 2D `SCENES` | Phase 1 Task 2, Step 2 |

## Phases

| Phase | Delivers (user-visible) | Exit criterion (exact check) | Status |
|---|---|---|---|
| 1 | The full story plays in 3D with beveled primitives: belts, boxes, toolbar, HUD card, side panel and every control | `pnpm --filter @yayloyalty/factory-explainer exec tsc --noEmit` exits 0. `pnpm --filter @yayloyalty/factory-explainer shoot` passes, writing 3 screenshots at 1440×900 with no console errors | implemented 2026-10-13, awaiting `/yayplancheck` |
| 2 | The product-illustration look: kit models, distinct silhouettes, lighting and post-processing, floor and props, walking workers, labels with hover outline and dimming | `shoot` passes at 1440×900, including the label overlap assertions, and one critique round against the spec's checklist is recorded | pending |
| 3 | 390×844 layout, reduced motion, fallbacks, docs, and the 2D page removed | Spec criterion 17: `shoot` passes at both viewports, the checklist passes within 3 rounds, and `./scripts/check-harness.sh` passes | pending |

## Interface contract

**Units:**
- 1 world unit = 40 floor units of the 2D page.
- World `x` is floor `x / 40`, world `z` is floor `y / 40`, and `y` is up.
- Belt top height `BELT_Y = 0.25`.

**`src/factory.config.ts`** (produced by Phase 1 Task 2, read by every scene and UI component):

```ts
export type StationId = 'order-listener' | 'signup-listener' | 'review-listener' | 'reversal-listener' | 'earning-service' | 'program-gate' | 'pending-points' | 'ledger-service' | 'vault' | 'redemption' | 'expiry' | 'tiers';
export type StationType = 'intake' | 'scoring' | 'gate' | 'ledger' | 'warehouse' | 'vault' | 'return' | 'unbuilt';
export type PackageId = 'alice' | 'bob' | 'cara' | 'admin';
export type Vec2 = [x: number, z: number];

export interface Station {
  id: StationId; type: StationType; role: string; label: string;
  description: string;            // one line, shown on label hover
  summary: string; file: string;  // side panel
  hooks: string[]; methods: string[]; inputs: string[]; outputs: string[];
  position: Vec2; size: [w: number, d: number, h: number];
  labelHeight: number;            // label anchor above the top face, world units
  built: boolean;
}
export type Endpoint = StationId | `dock-${PackageId}` | 'reject-bin';
export interface Conveyor { id: string; from: Endpoint; to: Endpoint; via: Vec2[]; kind: 'main' | 'reject' | 'return'; } // ends resolve to positions, so moving a station moves its belts
export const REJECT_BIN: Vec2;
export function conveyorPoints(c: Conveyor): Vec2[]; // [from position, ...via, to position]
export interface Actor { id: PackageId; name: string; color: string; home: Vec2; dock: Vec2; console?: boolean; }
export interface Card { fn: string; lines: string[]; out: string; }
export interface Step {
  label: string; card: Card; at?: StationId; // ride and work steps; pulse steps name their station in pulse
  pkg?: PackageId; conveyor?: string;   // a ride; otherwise...
  pulse?: StationId;                    // ...a station acts on its own
  gate?: 'lift' | 'deny'; press?: boolean;
  settle?: [x: number, y: number, z: number]; // a row on the front of the vault plinth
  tint?: 'rejected' | 'refund';
}
export interface Scene { id: `scene-${1|2|3|4|5|6}`; caption: string; show?: PackageId; steps: Step[]; }
export interface Box { id: PackageId; event: 'ORDER' | 'SIGNUP' | 'REVIEW' | 'MANUAL'; points: string; color: 'earn' | 'adjust'; }

export const STATIONS: Station[]; export const CONVEYORS: Conveyor[]; export const ACTORS: Actor[];
export const BOXES: Box[]; export const SCENES: Scene[]; export const PROPS: Prop[]; // Prop: Phase 2 Task 4
export const TIMING: { INTRO: 1.6; WALK: 2.0; HOP: 2.4; SPEED: 5.5; WORK: 3.4; HOLD: 2.4 }; // seconds, world units/s
```

**`src/timeline.ts`** (Phase 1 Task 2):

```ts
export interface Entry { start: number; end: number; scene: Scene; step: Step | null; phase: 'intro' | 'walk' | 'ride' | 'work' | 'hold'; }
export function compileTimeline(scenes?: Scene[]): Compiled; // { entries, duration, sceneStart, plus the box, actor and prop tracks }
export const COMPILED: Compiled;
export function activeStation(e: Entry): StationId | null;
export function entryAt(entries: Entry[], time: number): Entry;
export function boxPose(id: PackageId, time: number): { position: [number, number, number]; visible: boolean; tint: 'earn' | 'adjust' | 'rejected' | 'refund'; tag: string };
export function actorPose(id: PackageId, time: number): { position: Vec2; heading: number; walking: boolean };
export function propPose(time: number): { gateArm: number /* radians, 0 closed */; gateDeny: number /* 0..1 flash */; pressHead: number /* 0..1 down */; dial: number /* radians */ };
```

All of these are pure functions of `time`, so a seek and playback agree.

**`src/store.ts`** (Phase 1 Task 2):

```ts
export const useFactory: UseBoundStore<StoreApi<{
  time: number; duration: number; paused: boolean;
  hoverHeld: boolean; resumeAfterPanel: boolean;
  selected: StationId | null; hovered: StationId | null;
  tick(dt: number): void;          // clamps dt to 0.1, wraps at duration
  toggle(): void; restart(): void; seekScene(id: Scene['id']): void; seekTime(t: number): void;
  hoverHold(on: boolean): void;    // pause rules, spec criterion 12
  openPanel(id: StationId): void; closePanel(): void; setHovered(id: StationId | null): void;
}>>;
```

**Debug handle** (Phase 1 Task 5): in dev only (`import.meta.env.DEV`), `window.__factory = { seekScene, seekTime, pause, state(): { time, paused, entry: string }, labelRects(): Record<StationId, DOMRect>, machineRects(): Record<StationId, DOMRect> }`. `labelRects` returns `{}` until Phase 2 Task 6.

**DOM hooks:**
- `[data-testid="toolbar"]`, `play`, `restart`, `scene-1`…`scene-6`, `caption`, `step-card`, `panel`, `panel-close`;
- `.station-label[data-station=<id>]`.
