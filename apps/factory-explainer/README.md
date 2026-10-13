# Points Factory (3D explainer)

An internal, animated 3D explainer of how points move through YayLoyalty's earning path: six scenes in which customers' events ride belts through the listeners, `EarningService`, `ProgramGate`, `PendingPoints` and `LedgerService` into the `customers` vault. It never ships: `apps/` is outside the release zip. It targets desktop browsers with WebGL; there is no mobile layout.

The look is cartoon: toon shading, ink outlines and chunky yellow workers. When a station works, a speech bubble at the station lists its steps one by one, and the tracker under the caption shows where the scene is.

## Run it

From the repo root, on Node 22:

```sh
nvm use
pnpm install
pnpm --filter @yayloyalty/factory-explainer dev     # http://localhost:3050
```

- `http://localhost:3050/?perf` adds drei's `StatsGl` frame-rate meter.
- Space plays and pauses; the scene buttons jump to a scene; clicking a station opens its panel.

## Check it

```sh
pnpm --filter @yayloyalty/factory-explainer exec playwright install chromium   # once
pnpm --filter @yayloyalty/factory-explainer exec tsc --noEmit
pnpm --filter @yayloyalty/factory-explainer shoot
```

`shoot` starts the dev server if it isn't running, writes screenshots of scenes 1, 3 and 4 at 1440×900 to `shots/`, and fails on a console error, on two station labels overlapping, or on a label covering its own machine.

## Where things are

- `src/factory.config.ts`: the layout and the story. Moving a station there moves its machine, label and belt ends.
- `src/timeline.ts`: every pose is a pure function of the playhead, so a seek renders the same frame as playback.
- `src/scene/`: the 3D scene; `src/ui/`: the toolbar and step tracker, the step bubble, the station panel and the labels.

## Asset credits

All CC0, with each license beside its files:

- `public/models/`: [Kenney Factory Kit 3.0](https://kenney.nl/assets/factory-kit). The workers are built from primitives.
- `public/env/`: [Potsdamer Platz](https://polyhaven.com/a/potsdamer_platz) by Greg Zaal, Poly Haven, the image behind drei's `city` environment preset.

Spec: [docs/specs/2026-10-13-points-factory-3d-design.md](../../docs/specs/2026-10-13-points-factory-3d-design.md)
