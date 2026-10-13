# Points Factory Explainer Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. Read the spec linked below before starting; the plan argues from it.

**Status:** superseded by [the points factory 3D plan](2026-10-13-points-factory-3d/README.md)
**Goal:** Build `docs/explainers/points-factory.html`, a self-playing animated isometric factory scene that shows the team how points move through the earning path today.
**Spec:** [docs/specs/2026-10-13-points-factory-explainer-design.md](../specs/2026-10-13-points-factory-explainer-design.md)
**Architecture:** One HTML file with inline SVG, CSS and JS, animated by GSAP 3.12.5 and MotionPathPlugin from cdnjs. The static floor and the station panels are built first and work without GSAP. The scene timelines are then layered on top, so the offline fallback is the base state rather than a special case.
**Touches:** `docs/explainers/points-factory.html` (new), `docs/architecture.md`, and this spec and plan. No shared code; nothing under `includes/` or `apps/` changes.
**Tests:** none. CLAUDE.md's test rule warrants none, because this is no money or ledger logic and no bug fix. Each task ends in a browser check instead.

## Global constraints

- One file with inline CSS, SVG and JS. The only external resources are these two scripts, pinned:
  - `https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js`
  - `https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/MotionPathPlugin.min.js`
- No build step, no npm dependency, and nothing added to `apps/` or `includes/`.
- `docs/` stays out of `build_package` in `scripts/run.sh`, so the release zip is unchanged.
- **Layout:** works at 1024 px wide and up. Below that, the floor scrolls horizontally inside its own container, and the page itself never scrolls horizontally.
- **Names:** class names, methods, hooks, meta keys and enum values are written exactly as in the code at the stamped commit. The spec's Context section lists every one used.
- **Scope:** light theme only, English only, no browser storage.
- **Git:** branch `trace/docs/points-factory-explainer` from `dev/edd`. Commits follow Conventional Commits and end with the CLAUDE.md trailer. Stage files by name.

## Review focus

Each item below is a likely failure mode and the behaviour that should hold.

- **The GSAP scripts don't load (offline, or cdnjs blocked).** The floor, labels and panels still render, the notice reads "Animation needs network access", and the console shows nothing except the two failed script loads. Checked in Task 1, Step 3.
- **The loop wraps from scene 6 back to scene 1.** Every package returns to its start: none stays in the vault or warehouse, and no copies pile up. Checked in Task 2, Step 4.
- **A user jumps to scene 3 directly.** Alice's package must be in the warehouse already, since scene 3 moves the package that scene 1 staged. Checked in Task 2, Step 4.
- **A station panel is opened while the loop is paused.** Closing it must leave the loop paused. Only a panel that paused the loop resumes it. Checked in Task 2, Step 4.
- **Reduced motion.** The page loads paused, with every package at its end position:
  - Alice's package is out of the vault, after the refund.
  - Bob's and the admin's packages are in the vault.
  - Cara's package is in the reject bin.

  Checked in Task 2, Step 5.

---

### Task 1: Static factory floor and station panels

**Files:**
- Create: `docs/explainers/points-factory.html`
- Stage as well: `docs/specs/2026-10-13-points-factory-explainer-design.md`, `docs/plans/2026-10-13-points-factory-explainer.md`

**Interfaces:**
- Consumes: nothing.
- Produces, for Task 2:
  - **`STATIONS`:** an array of `{ id, label, file, methods: string[], summary, built: boolean }`. There are 12 ids:
    - `order-listener`, `signup-listener`, `review-listener`, `reversal-listener`
    - `earning-service`, `program-gate`, `pending-points`, `ledger-service`
    - `vault`, `redemption`, `expiry`, `tiers`
  - **Station elements:** each station is an SVG `<g id="station-<id>">`.
  - **Non-clickable spots:** an SVG `<g>` each for `dock-alice`, `dock-bob`, `dock-cara` and `desk-admin`, plus `reject-bin`.
  - **Conveyors:** SVG `<path id="belt-<from>-<to>">` elements, using the station or spot ids above, for every hop the spec's acceptance criterion 1 names. Scene 6 runs on `belt-vault-reversal-listener`, the return conveyor drawn underneath the floor.
  - **`openPanel( id ): void`** and **`closePanel(): void`**.
  - **Hooks for Task 2:** `onPanelOpen` and `onPanelClose` callbacks, both no-ops in this task.

- [x] **Step 1: Branch**

Run: `git switch -c trace/docs/points-factory-explainer dev/edd`, then `git branch --unset-upstream`. Per memory, new branches must not track `dev/edd`. If git says there is no upstream, that is fine.
Expected: `git branch --show-current` prints `trace/docs/points-factory-explainer`.

- [x] **Step 2: Write the page skeleton, floor and panel in `docs/explainers/points-factory.html`**

**Page content:**
- `<title>Points Factory</title>`.
- A header with the title and the stamp "Accurate as of `f92b317` (2026-10-13)". Update the stamp to the commit the page is verified against in Task 3.
- The SVG floor, laid out left to right as the spec's Approach § Architecture describes.
- Each station's `label` drawn on it. The 3 stations with `built: false` are drawn with a hatched SVG `<pattern>` fill and a "not built yet" tag. No conveyor enters them.

**Station panel content:**
- Clicking a station (`cursor: pointer`, and keyboard focusable through `tabindex="0"` with Enter) calls `openPanel(id)`.
- The right-hand panel then shows `label`, `summary`, `file` (repo-relative, in `<code>`) and `methods`.
- For an unbuilt station, `summary` says what is missing, using the facts in the spec's "Not built yet" list.
- The panel has a close button that calls `closePanel()`.

**`file` values:**

| Station | `file` |
|---|---|
| The four listeners | `includes/Engine/Earning/Listeners/<Class>.php` |
| `earning-service` | `includes/Engine/Earning/EarningService.php` (its summary also names `PointsEngine::evaluate()`) |
| `program-gate` | `includes/Engine/Earning/ProgramGate.php` |
| `pending-points` | `includes/Engine/Earning/PendingPoints.php` |
| `ledger-service` | `includes/Services/LedgerService.php` |
| `vault` | `includes/Database/Schema/CustomersTable.php` |
| `redemption` | `includes/Engine/Redemption/RedemptionService.php` |
| `expiry` | `includes/Services/LedgerService.php` |
| `tiers` | `includes/Database/Schema/CustomersTable.php` |

Confirm each path with `git ls-files` before writing it.

**Loading GSAP:**
- Load the two scripts with plain `<script src>` tags before the inline script.
- The inline script checks `typeof window.gsap === 'undefined' || typeof window.MotionPathPlugin === 'undefined'`. If either is missing, it shows the notice "Animation needs network access" and stops before any animation code runs.

- [x] **Step 3: Verify the static page in Chrome**

Do these checks through claude-in-chrome.

1. Open `file://<repo>/docs/explainers/points-factory.html` at 1440×900.
   Expected: all 12 stations are drawn and labelled, and `redemption`, `expiry` and `tiers` are hatched and tagged "not built yet".
2. Click each station.
   Expected: the panel shows that station's file and methods. Running `git ls-files <file>` from the repo root prints each path.
3. Resize to 1024 px and to 800 px.
   Expected: at 1024 px the floor fits. At 800 px only the floor's container scrolls horizontally: `document.documentElement.scrollWidth <= window.innerWidth` is true.
4. Copy the file to the scratchpad, swap both cdnjs URLs for `https://invalid.invalid/…`, and open that copy.
   Expected: the notice "Animation needs network access" shows, the panels still open, and the console has no errors besides the two failed script loads.

- [x] **Step 4: Commit**

Stage: `docs/explainers/points-factory.html`, `docs/specs/2026-10-13-points-factory-explainer-design.md`, `docs/plans/2026-10-13-points-factory-explainer.md`
Message: `docs(explainers): add points factory floor and station panels`

### Task 2: Scenes, master timeline and controls

**Files:**
- Modify: `docs/explainers/points-factory.html`

**Interfaces:**
- Consumes: Task 1's `STATIONS`, the `station-*` / `dock-*` / `desk-admin` / `reject-bin` ids, the `belt-*` paths, `openPanel` / `closePanel` and `onPanelOpen` / `onPanelClose`.
- Produces:
  - **`SCENES`:** an array of `{ id: 'scene-1'…'scene-6', caption, steps: { belt, label }[] }`. `label` is the hook or `Class::method()` the caption bar shows during that hop.
  - **`master`:** a `gsap.timeline({ repeat: -1 })` with one label per scene id.

- [x] **Step 1: Write `SCENES` and the package elements**

**Scenes:** add the six scenes exactly as the spec's acceptance criterion 1 lists them, in that order, with its hops and step labels.

**Packages:**
- There are 4 package `<g>` elements: Alice, Bob, Cara and Admin. Each is a rounded rect with text showing its points and its `LedgerSource`.
- Points shown: Alice 120 (`ORDER`), Bob 50 (`SIGNUP`), Cara 20 (`REVIEW`), Admin +50 (`MANUAL`).
- Each package is a single element that persists across scenes: scene 3 moves the package that scene 1 left in the warehouse, and scene 6 moves Alice's package out of the vault.
- Fill colour changes with the flow: earn `#16a34a`, adjust `#2563eb`, refund `#dc2626`, rejected `#9ca3af`.
  - Alice's package is green until scene 6, when it turns red.
  - Cara's package turns grey at `program-gate`, and its caption reads "opted out".

- [x] **Step 2: Build `master` from `SCENES`**

- Register the plugin with `gsap.registerPlugin(MotionPathPlugin)`.
- Build `master` so that `master.addLabel(scene.id)` precedes each scene.
- Each hop is a `to()` call with `motionPath: { path: '#' + belt, align: '#' + belt, alignOrigin: [0.5, 0.5] }`. A `call()` at the start of each hop sets the caption text and highlights the active scene in the scene list.
- The first tween at time 0 uses `gsap.set` to put every package back at its dock, with its starting colour and visibility. Every scene is then built from that state, which is what makes the loop wrap and scene jumps correct.
- Hold the end of each scene for 1.5 s, so the full loop runs about 60 s.

- [x] **Step 3: Wire the controls**

**Buttons:**
- **Play/Pause** toggles `master.paused()`, and the button text follows the state.
- **Space** toggles it too. Call `preventDefault` when focus isn't on a button.
- **Restart** calls `master.restart()`.

**Scene list:** each caption in the list is a button that calls `master.seek(scene.id)` and keeps the current play state.

**Panels:**
- `onPanelOpen` remembers whether the loop was playing, then pauses it.
- `onPanelClose` resumes the loop only if it was playing when the panel opened.

**Reduced motion:** when `matchMedia('(prefers-reduced-motion: reduce)').matches`, call `master.seek(master.duration()).pause()` on load.

- [x] **Step 4: Verify playback in Chrome**

Open the page at 1440×900. Read the console with the `pattern` filter left empty.

1. Watch for about 65 s.
   Expected: scenes 1–6 play in order, each caption shows the labels from the spec's criterion 1, and the loop then starts scene 1 again with every package back at its dock. There are no console errors.
2. Click scene 3 in the list.
   Expected: Alice's package starts from the warehouse and travels to the vault.
3. Press Space twice.
   Expected: the loop pauses, then resumes.
4. Pause, open a station panel, then close it.
   Expected: the loop is still paused.
5. While playing, open a panel, then close it.
   Expected: the loop pauses while the panel is open and resumes once it closes.
6. Click Restart.
   Expected: the loop restarts at scene 1.

- [x] **Step 5: Verify reduced motion**

Use DevTools Rendering, "Emulate CSS prefers-reduced-motion: reduce". In claude-in-chrome you can instead check the media query from `javascript_tool` after reloading with the emulation on.
Expected:
- The page loads paused.
- Bob's and the admin's packages are in the vault, and Cara's is in the reject bin.
- Alice's package is red and at the end of the return conveyor.

- [x] **Step 6: Commit**

Stage: `docs/explainers/points-factory.html`
Message: `docs(explainers): animate the six points factory scenes`

### Task 3: Link from the architecture doc and final verification

**Files:**
- Modify: `docs/architecture.md`, at the end of the `## Server layers and write ownership` section (lines 39–89)
- Modify: `docs/explainers/points-factory.html`, the header stamp only
- Modify: `docs/plans/2026-10-13-points-factory-explainer.md`, the Status line

**Interfaces:**
- Consumes: the finished page from Task 2.
- Produces: nothing.

- [x] **Step 1: Add the link**

Add one bullet at the end of that section:

A bullet whose link text "The points factory explainer" points to `explainers/points-factory.html`, followed by: "animates this earning path, from listener to LedgerService, and is accurate as of the commit stamped in its header."

- [x] **Step 2: Run the harness checks**

Run: `./scripts/check-harness.sh`
Expected: `Harness checks passed.` It passes on `dev/edd` today, so any failure is new.

- [x] **Step 3: Stamp the page**

Set the header stamp to the output of `git rev-parse --short HEAD` and today's date. The end-to-end check and the plan close-out moved to Task 5, after the spec amendment of 2026-10-13.

- [x] **Step 4: Commit**

Stage: `docs/architecture.md`, `docs/explainers/points-factory.html`, `docs/plans/2026-10-13-points-factory-explainer.md`
Message: `docs(architecture): link the points factory explainer`

### Task 4: Isometric scene

The spec's amendment of 2026-10-13 (criteria 4 and 10, Approach § Architecture and UI) redraws the floor as an isometric illustration. Behaviour stays as it is: the same 12 stations and panels, 6 scenes, captions, controls, reduced motion and offline fallback.

**Files:**
- Modify: `docs/explainers/points-factory.html`
- Stage as well: the review fixes already in the working tree (the `title`→`caption` rename and the spec's criterion 1.3 order), plus the amended spec

**Interfaces:**
- Consumes: Task 1's ids and functions and Task 2's `SCENES` and `master`. Every one keeps its name: `STATIONS`, `station-<id>`, `dock-*`, `desk-admin`, `reject-bin`, `belt-<from>-<to>`, `openPanel` / `closePanel`, `onPanelOpen` / `onPanelClose`, `SCENES { id, caption, steps }`, and `master` with labels `scene-1`…`scene-6`.
- Produces:
  - **`iso( x, y, z ): { x: number, y: number }`**, with `sx = ( x − y ) · 0.866`, `sy = ( x + y ) · 0.5 − z`.
  - **`STATIONS` gains** `d` (footprint depth, in floor units) and `hgt` (block height). Its existing `x` and `y` become the footprint centre in floor coordinates.
  - **`BELTS` values stay plan-space** `M/H/V` strings in floor coordinates. A `projectPath( d, z ): string` turns each into the screen-space `d` of `<path id="belt-…">`.

- [x] **Step 1: Re-lay the floor in floor coordinates**

**Coordinates:**
- Keep the same left-to-right order: docks, intake, scoring, gate, warehouse, press, vault, wing.
- Floor x runs along the belts, and floor y is depth.
- Pick spacing so the projected scene fits a 16:9-ish `viewBox`, fitted to `iso()` of the platform's four corners plus 60 units of padding.
- Belts keep their ids and the same hops, so every scene step still names a belt that exists.

**The platform** is the floor rectangle projected into a diamond, with a light tile grid drawn every 80 floor units.

- [x] **Step 2: Draw stations as isometric blocks**

**Faces and shading:**
- Each station is a `<g id="station-<id>">` holding:
  - the top, left and right face polygons from `iso()` of its footprint corners at `z = 0` and `z = hgt`;
  - its role detail (spec criterion 10);
  - an upright sign: a rounded rect above the block with the role and the label, plus the "not built yet" sign for unbuilt stations.
- Faces use one hue per role:

| Role | Hue |
|---|---|
| Intake | teal |
| Scoring | violet |
| Gate | amber |
| Warehouse | sky |
| Ledger press | indigo |
| Vault | slate |
| Return | rose |

- The top face is the lightest shade, the left face mid, and the right face dark.

**Unbuilt stations:** faces filled with `url(#hatch)` and edges dashed in `--unbuilt`.

**Selection highlight:** `.is-active` and `.is-selected` now restyle the top face (accent fill and stroke), because the station `<rect>` is gone.

**Depth and hit area:** sort the station and spot groups by ascending `x + y` before appending. The whole group stays the click target, so any face opens the panel.

- [x] **Step 3: Customers, desk, bin and belts**

- **Figures:** each `dock-*` is a small figure (a body ellipse plus a head circle) with an upright name sign.
- **Desk:** `desk-admin` is a small block with a figure behind it.
- **Reject bin:** `reject-bin` is an open isometric box.
- **Belts:** each belt draws a side band (`z` 0 → 10) under a top band at `z = 10`. The flowing dashed path is the projected `belt-…` path at `z = 10`, which is the path packages ride.
- **Return conveyor:** keeps its rose tint.
- **Stub conveyors:** the dashed stubs to the wing stay, and are projected.

- [x] **Step 4: Cube packages and the scene props**

**Packages:**
- Each package `<g id="pkg-<id>">` is a cube 26 units on a side, with its three faces drawn around its own origin at the cube's bottom centre.
- Its tag (points · `LedgerSource`) floats above the cube as upright text on a small pill.
- The flow colour changes through a single `fill` on a `<g class="pkg-body">`. The three faces apply the light, mid and dark shades as overlays of `rgba(255,255,255,.25)`, no overlay, and `rgba(0,0,0,.2)`.
- Task 2's colour `set`s therefore target `.pkg-body`, not `firstChild`.

**Gate barrier:**
- `ProgramGate` has a barrier arm `<g id="gate-arm">`.
- At the start of each hop into `program-gate`, a tween rotates the arm up 70° and back down. Cara's reject hop instead keeps it down and flashes its stroke red twice.

**Piston:** `LedgerService`'s piston `<g id="press-piston">` drops 12 units and returns at each `LedgerService::earn()`, `adjust()` and `refund_earn()` step.

**Reset:** both props are tweens inside `master`, and the time-0 reset puts them back. They need no extra seek handling.

- [x] **Step 5: Verify in Chrome**

Serve the folder: `python3 -m http.server 8765 --bind 127.0.0.1` in `docs/explainers/`. Then open `http://127.0.0.1:8765/points-factory.html` at 1440×900.

1. **Screenshot.**
   Expected: the platform diamond, 12 blocks with readable upright signs, the figures and desk, the bin, and the raised belts. Nearer blocks overlap farther ones with no z-fighting.
2. **Stations.** Click the top, left and right face of 3 different stations.
   Expected: each opens that station's panel.
3. **Scenes.** Step the timeline through `gsap.globalTimeline.getChildren(false, false, true)[0]`, as in Task 2.
   Expected: scenes 1–6 keep their order, captions and end positions, with every package on its belt at each step. The gate arm lifts in scenes 1, 2 and 4, and stays down and red at Cara's reject. The piston stamps in scenes 2, 3, 5 and 6.
4. **Repeat Task 2's checks:** the loop wrap, the scene-3 jump, the panel pause rules, Space, Restart and reduced motion.
   Expected: the same results as in Task 2.
5. **Offline copy and widths:** repeat Task 1 Step 3's offline copy and its 1024 px and 800 px checks.
   Expected: the same results as in Task 1.

- [x] **Step 6: Commit**

Stage: `docs/explainers/points-factory.html`, `docs/specs/2026-10-13-points-factory-explainer-design.md`, `docs/plans/2026-10-13-points-factory-explainer.md`
Message: `docs(explainers): redraw the points factory as an isometric scene`

- [x] **Step 7: Visual polish (requested 2026-10-13, visuals only)**

This step changes no behaviour, ids or scenes. It adds:
- **Blocks:** face sheens, bevelled edges and soft drop shadows; windows, vents and status lights on the built blocks; scaffolding poles on the unbuilt ones.
- **Belts:** roller conveyors.
- **People:** better figures on coloured dock pads, and a monitor on the admin's desk.
- **Packages:** taped cubes with floor shadows.
- **Machine details:** a refined gear, a piston frame, a gate beacon, vault bolts and a shed door.
- **Signs:** a dot in each role's colour.
- **Platform and toolbar:** a thicker platform with a ground shadow, and a loop progress bar in the caption.

Re-run Step 5's checks.
Expected: the same results.

- [x] **Step 8: Slower loop with process cards (requested 2026-10-13, spec criterion 2 amended)**

- Timing: `HOP = 2.4`, `SPEED = 220` floor units a second, `INTRO = 1.6`, `HOLD = 2.4`. Each step ends with a `WORK = 3.4` s dwell, so the loop runs about 149 s.
- Each step carries `card: { fn, lines[], out }`. During the dwell, `render()` draws it as an SVG callout, `#callout`, above the package or the station, clamped to the viewBox.
- The gate lifts after its check is shown. The piston stamps and the deny flash play during the dwell.
- Hovering a package or its card, `#callout` (`pointerenter`), pauses a playing loop, and `pointerleave` resumes it. Space, the Play button or a station panel takes over from the hover; a panel opened during a hover resumes the loop when it closes.

Re-run Step 5's checks, with one more: seek every step and confirm that each card's text fits its box and its box fits the viewBox.
Expected: the same results, with all 23 cards fitting.

### Task 5: Close-out

**Files:**
- Modify: `docs/explainers/points-factory.html`, the header stamp only
- Modify: `docs/plans/2026-10-13-points-factory-explainer.md`, the Status line

- [x] **Step 1: Stamp and run the end-to-end check**

1. Set the header stamp to the output of `git rev-parse --short HEAD` and today's date.
2. Run the spec's acceptance criterion 11. The user watches this live, because the Chrome window is hidden from the extension.
   - Open `docs/explainers/points-factory.html` in Chrome at 1440×900 and watch a full loop.
   - Expected: all 6 scenes play as in criterion 1, with no console errors.
   - Click all 12 stations, and run `git ls-files <path>` for each file shown.
   - Expected: `git ls-files` prints every path.
3. Set this plan's Status to `done YYYY-MM-DD`.

- [x] **Step 2: Commit**

Stage: `docs/explainers/points-factory.html`, `docs/plans/2026-10-13-points-factory-explainer.md`
Message: `docs(explainers): close out the points factory plan`
