---
area: admin-spa
updated: 2026-11-12
updated_ref: 472668aa
covers: [apps/admin/, includes/Engine/Admin/Settings.php, includes/Engine/Register/, includes/Helpers/LocalizeHelper.php]
---

# Admin SPA

> Update when: how wp-admin loads the SPA, the API client, the edition gate, routing, the query/form pattern or the build configs change.

React 18, TypeScript, Vite, react-router (hash router), TanStack Query, react-hook-form + zod, Radix primitives wrapped shadcn-style in `src/components/ui/`, Tailwind v4. HTTP goes through ky, not `@wordpress/api-fetch`.

## How wp-admin loads it

- The menu comes from the shared AdminShell: `YayLoyaltyPluginAdapter` registers slug `yayloyalty` under the `yaycommerce` parent and prints `<div id="yayloyalty">`, which `src/main.tsx` mounts.
- `Engine/Admin/Settings.php` enqueues only on hook suffix `yaycommerce_page_yayloyalty` and adds body class `yay-ui`.
- `Engine/Register/RegisterFacade.php` picks `RegisterDev` (`http://localhost:3030/main.tsx` + React refresh preamble) when `YAYLOYALTY_IS_DEVELOPMENT === true`, else `RegisterProd` (`assets/dist/admin/js/main.js`). The dev port lives in both `RegisterDev.php` and `apps/admin/vite.config.ts`. Release builds delete `RegisterDev.php`.
- Handles are in `Engine/Register/ScriptName.php`. Any handle containing `yayloyalty/module/` gets `type="module"`; rename it and the bundle stops loading.
- PHP localizes `window.yayLoyaltyMeta` (WP URLs, REST root and nonce, plugin meta, WooCommerce currency/countries) and `window.yayLoyaltyAdmin` (settings, design, the user's capabilities) — `Helpers/LocalizeHelper.php`, typed in `src/types/localize.type.ts`. Both are filterable; Pro adds `segments`. **There is no Pro flag in localized data.**
- Script translations are served from the plugin's MO file (`pre_load_script_translations` in `RegisterFacade.php`).

## API bridge

`src/lib/api/api.ts`: ky with `prefixUrl` = `restRoot`, header `X-WP-Nonce`, 20 s timeout.
- With plain permalinks (`?rest_route=`) `createApi` returns `UglyApi`, which moves the path into the query string. Never build REST URLs by hand.
- A 403 `rest_cookie_invalid_nonce` reloads the page (unsaved form state is lost). A 403 `yayloyalty_forbidden` is toasted once by the `afterResponse` hook.
- Casing is not converted: item and settings bodies are snake_case, the paginated envelope is camelCase (`currentPage`, `totalPages`, `totalItems`, `perPage`, `data`; `src/lib/api/paginated.ts`).
- Responses are parsed with zod (`reward.api.ts`); `settings.api.ts` still casts with `.json<Settings>()`.

## Editions

- `IS_PRO_BUILD` (`src/lib/is-pro-build.ts`) is `import.meta.env.VITE_IS_PRO === 'true'`, set by `vite.config.ts` from `BUILD_TYPE` (anything but `lite` is Pro). It's an env var, not a `define`, because the dev server doesn't substitute `define`.
- Lite drops `src/pro/` by Rollup dead-code elimination, not a plugin. `src/main.tsx` reads `import.meta.env.VITE_IS_PRO` directly around `registerPro()` (same-file comparison folds most reliably) with a static import (a dynamic one would make a side chunk the validator doesn't scan).
- `src/pro/index.ts` is the only import surface for Pro code and exports `PRO_BUNDLE_SENTINEL`, which `scripts/validate-build.php` requires absent from Lite and present in Pro.
- `<ProGate>` (`src/components/pro/ProGate.tsx`, ships in both) is only the upsell overlay. JSX evaluates children eagerly, so Pro JSX must be guarded with a ternary: `{IS_PRO_BUILD ? <FraudLimitsSection /> : <FraudLimitsUpsellCard />}` (`pages/settings/GeneralSettingsPage.tsx`).
- Pro values must still parse in Lite: `lib/schema/reward.schema.ts` keeps `ALL_KNOWN_REWARD_TYPES`.

## Structure

| Path | What |
|---|---|
| `src/router.tsx` | Hash routes: dashboard, customers, ledger, campaigns, rewards, settings (general, expiry, design, emails). Each wrapped in `guarded(CAP.X, …)`, which must match the `HeaderMenu` item's cap |
| `src/lib/api/<x>.api.ts` | HTTP calls |
| `src/lib/schema/<x>.schema.ts` | zod schemas; TypeScript copies of PHP shapes, each with a "Mirrors X" docblock naming the PHP source |
| `src/lib/queries/<x>.queries.ts` | `X_QUERIES` `queryOptions` + `useXQuery` / `useXMutation`; keys `['<domain>', 'list'|'detail', params]`; mutations invalidate by prefix |
| `src/pages/<area>/` | Pages; per-page `<name>.form.ts` (schema, defaults, `build…Update` sending dirty fields only) and `<name>.copy.ts` (functions returning `__()` strings) |
| `src/components/ui/` | Design-system primitives; `cn()` in `src/lib/utils.ts` |
| `src/pro/` | Pro-only screens and sections |
| `src/blocks/` | Editor-only blocks (`ServerSideRender` preview, `save: () => null`) |

- `QueryClient`: `retry: false`, `staleTime` 5 min. The settings query is seeded from `window.yayLoyaltyAdmin.settings` and writes back to it on each fetch (`lib/queries/settings.queries.ts`).
- Capabilities in `src/lib/access.tsx` (`CAP`, `Can`, `useCapability`) are cosmetic; the server re-checks.
- The setup wizard shows from `AppLayout.tsx` while `setupCompleted` is false and the user can manage settings.
- i18n: `__` from `@wordpress/i18n`, domain `yayloyalty`. Terser keeps `__ _n _nx _x` and `translators:` comments.

## Builds

`pnpm build` (`apps/admin/package.json`) = `tsc --noEmit` → `vite build` into `assets/dist/admin/` → `vite.storefront.config.ts` (launcher CSS into `assets/dist/storefront/`, theme tokens moved off `:root`, rem → px so shop themes don't restyle it) → `vite.blocks.config.ts` (IIFE `assets/dist/blocks/editor.js`, same for both editions). In production `react`, `react-dom` and `@wordpress/*` are externals.

## Recipes

- **Settings field:** add it to `SettingsHelper` (defaults, `validate`) → mirror in `lib/schema/settings.schema.ts` → form schema, defaults and `build…Update` in `pages/settings/general-settings.form.ts` → copy in `general-settings.copy.ts` → render with `Controller` + `Field` like the cards in `GeneralSettingsPage.tsx`.
- **Page:** `pages/<x>/XPage.tsx` → route in `router.tsx` with `guarded(CAP.…)` → matching `components/layout/HeaderMenu.tsx` item → `use…Query` + `resolveQueryLoad` → `PageLayout`. Copy the rewards routes.
- **API call:** zod schema → `lib/api/<x>.api.ts` (`api.get(path, { searchParams }).json()` then `.parse`) → `lib/queries/<x>.queries.ts`. Copy `reward.api.ts` and `reward.queries.ts`.

## Tests and lint

- SPA tests live in `tests/js/` at the repo root (see [testing.md](testing.md)). Mock `@/lib/api/api` in a test, because the real client reads `window` at import.
- Lint: `eslint . && prettier --check .`; Prettier config (print width 120, import order, Tailwind class sort) is in `apps/admin/package.json`.
