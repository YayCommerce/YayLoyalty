---
paths:
  - "apps/admin/**"
---

<!--
Purpose: rules for writing the admin SPA in apps/admin/: the patterns new code follows, and its couplings to PHP and to the release validator that no check catches. Loads when Claude reads a matching file.
Update when: one of these patterns changes, a new coupling to PHP or to scripts/validate-build.php appears, or the lint, format or build config changes.
-->

# Admin SPA

How the SPA loads and calls WordPress is in [docs/architecture.md § SPA and WordPress](../../docs/architecture.md#spa-and-wordpress), how Lite drops Pro code is in [§ Pro and Lite](../../docs/architecture.md#pro-and-lite), and how Vitest runs in [§ Tests](../../docs/architecture.md#tests). Money, dates, wire casing, file names and TypeScript copies of PHP are in [docs/conventions.md](../../docs/conventions.md). Hard rule 3 in `CLAUDE.md` governs Pro code.

## Patterns for new code

- **Imports:** relative inside a page or Pro feature folder, and `@/` (for `apps/admin/src/`) for anything outside it. Prettier sorts them.
- **REST responses** are parsed with their zod schema from `apps/admin/src/lib/schema/` in the resource's `*.api.ts` module, never cast with `.json<T>()`. A cast is unchecked, and a bad shape white-screens a component.
- **Queries** are `queryOptions` in a `<RESOURCE>_QUERIES` map, beside their `useXQuery` and `useXMutation` hooks. They live in `apps/admin/src/lib/queries/`, or in a Pro feature's own folder under `apps/admin/src/pro/`. A mutation invalidates by key prefix.
- **Failed requests** are shown with `toastApiError()` from `apps/admin/src/lib/api/api.ts`. `toast.error(await getApiErrorMessage(…))` toasts a capability refusal twice, because the client has already toasted it.
- **Forms** use react-hook-form with `zodResolver` over the resource's zod schema, `values` with `resetOptions: { keepDirtyValues: true }`, and `useRouteLeaveGuard(isDirty)`. `apps/admin/src/pages/settings/DesignSettingsPage.tsx` is the model.
- **A new page** gets a route in `apps/admin/src/router.tsx` wrapped in `guarded(CAP.…)`. A top-level page also gets a menu item with the same capability in `apps/admin/src/components/layout/HeaderMenu.tsx`, and a settings tab an entry in `SETTINGS_NAV`.
- **UI** is built from `apps/admin/src/components/ui/`, using the wrapper in `apps/admin/src/components/ui/custom/` where one exists. Icons come from lucide-react, and class names are merged with `cn()`.

## Couplings no check catches

- `scripts/validate-build.php` looks for the upsell sentence of `apps/admin/src/components/pro/UpgradeToPro.tsx` in the Lite bundle. It also reads `DesignStorefrontTextSection.tsx` and `DesignVisibilitySection.tsx` in `apps/admin/src/pages/settings/` by path, for their `IS_PRO_BUILD` guards. Reword, rename or move any of them only together with the validator.
- Keep the bundle one chunk: no dynamic `import()` or `React.lazy`. The validator looks for Pro code only in `assets/dist/admin/js/main.js`.
- A new `@wordpress/*` package goes in `apps/admin/package.json` and in `externalProd` in `apps/admin/vite.config.ts`. It also goes in `externalDev` when WordPress sets up its state, as it does for i18n and hooks. Its script handle goes in `$deps` in both `includes/Engine/Register/RegisterProd.php` and `includes/Engine/Register/RegisterDev.php`.
