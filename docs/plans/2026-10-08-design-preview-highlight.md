# Hide the panel layout and highlight storefront text in the preview

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. There is no spec; the Requirements below are the source.

**Status:** done 2026-10-08
**Goal:** Remove the Panel section's Layout picker from the Design tab, and outline the matching part of the launcher preview while the merchant hovers or focuses a Storefront text input.
**Spec:** none, planned from the request
**Branch:** `trace/feat/editor-skeletons`
**Architecture:** The preview is the server's own launcher markup in an iframe. The templates tag each storefront text with `data-yl-text="<key>"`, and the preview page's own CSS outlines `[data-yl-highlighted]`. The iframe moves from `sandbox=""` to `sandbox="allow-same-origin"`, still with no scripts, so the admin can set that attribute inside the document without reloading it. The alternative was to rebuild `srcDoc` with an injected style on every hover, which reloads the frame and its external stylesheet each time, so the preview flickers.
**Touches:** `apps/admin/src/pro/design/DesignPanelSection.tsx`, `apps/admin/src/pages/settings/` (`design-settings.copy.ts`, `design-settings.option-labels.ts`, `DesignSettingsPage.tsx`, `DesignStorefrontTextSection.tsx`, `DesignLauncherPreview.tsx`, `LauncherPreviewFrame.tsx`, new `design-settings.preview-highlight.tsx`), `includes/Engine/Storefront/LauncherPreview.php`, `includes/Engine/Storefront/templates/` (`launcher.php`, `panel-member.php`, `panel-guest.php`) (shared: the live storefront draws these templates too, and gains inert data attributes), `docs/architecture.md`.
**Tests:** none. This isn't money or ledger logic and doesn't fix a bug, so each task ends in a manual check.

## Requirements

- **Goal:** the merchant no longer sees the Layout setting, and can tell which part of the launcher each Storefront text input changes.
- **Out of scope:** `panel.layout` in the schema, the REST shape and `DesignSchema.php`, which stay so a stored value survives. Any storefront rendering of the layout. Drawing the redeem button anywhere. The live storefront's look.
- **Acceptance criteria:**
  1. Design tab (Pro): the Panel card shows only the "Show reward catalog" switch, and its description reads "The panel’s optional blocks." Saving the tab still posts `panel.layout` with its loaded value.
  2. Hovering a Storefront text field (label or input), or focusing its input, outlines its text in the preview: `launcher_label` on the collapsed button and in the expanded panel header, `balance_heading` in the member view, `earn_rate_line` in the member's "Make a purchase" row or the guest's earn step, and `signed_out_prompt` in the guest view.
  3. Hover wins while it lasts. When the pointer leaves, the outline returns to the focused field, or goes away if nothing is focused.
  4. A field whose text isn't in the current view (`balance_heading` for a guest, `signed_out_prompt` for a member, `redeem_button` always) outlines nothing. The Signed in/Guest toggle never changes by itself.
  5. Typing in a focused field redraws the preview (300 ms debounce), and the outline is still on the new document.
  6. End to end: on the Design tab, focus "Launcher label" and both frames outline the label. Hover "Signed-out prompt" in the Signed in view and nothing is outlined. Switch to Guest and the prompt is outlined. Move the pointer away and the outline goes back to the launcher label.
- **Decided with the user:**
  - Hide the Layout UI only, or remove the setting? Hide the UI only.
  - What happens for texts that aren't in the current view? Outline only when visible; never switch the viewer, and show no hint.
  - How does the highlight reach the iframe? `sandbox="allow-same-origin"` with scripts still blocked, toggling an attribute inside the document.

## Global constraints

- Product PHP must run on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types.
- SPA Pro code lives in `apps/admin/src/pro/` and is imported only through `apps/admin/src/pro/index.ts`. Lite names Pro nowhere else.
- `apps/admin` uses pnpm only. Call the tools directly from `apps/admin` (`./node_modules/.bin/eslint`, `./node_modules/.bin/prettier`, `./node_modules/.bin/tsc`) so pnpm doesn't run an install first.
- Failing checks today: admin lint, Prettier on existing files, `tsc --noEmit` (3 missing-module errors in `emoji-picker-content.tsx` and `progress.tsx`), and `composer lint` (warnings only). Add no new error or warning to the files you touch.
- Comments: one-line docblocks, plus a why only where the code would look wrong without it.
- Commit only when asked. Commit messages follow Conventional Commits and end with `Co-Authored-By: Claude <model> <noreply@anthropic.com>`.

## Review focus

- A preview redraw while a field is focused: each keystroke replaces `srcDoc`, which drops the attribute. Re-apply it on the iframe's `load` (Task 3, Step 5).
- `register()`'s own `onBlur`: replacing it instead of chaining it stops react-hook-form from marking the field touched (Task 3, Step 3).
- The same text in two places: `launcher_label` is on the collapsed button and in the expanded header, so both frames must outline it (Task 2, Step 4).
- `sandbox="allow-same-origin"` without `allow-scripts`: the preview must still run no script. The page is server markup and CSS only, and `launcher.js` is never included (Task 3, Step 6).

---

### Task 1: Hide the Panel layout picker

**Files:**
- Modify: `apps/admin/src/pro/design/DesignPanelSection.tsx`
- Modify: `apps/admin/src/pages/settings/design-settings.copy.ts:83-84`
- Modify: `apps/admin/src/pages/settings/design-settings.option-labels.ts:3,27-35`

**Interfaces:**
- Consumes: nothing
- Produces: nothing other tasks use

- [ ] **Step 1: Remove the Layout field from `DesignPanelSection`**

Delete `PanelLayoutPreview` and the `<Field>` holding the `panel.layout` `Controller` and `RadioGroup`. The `CardContent` keeps only the `PanelToggleRow` stack. Drop the imports nothing uses any more: `designPanelLayoutSchema`, `DesignPanelLayout`, `RadioGroup`, `RadioGroupItem`, `Field`, `FieldContent`, `FieldLabel`, `PANEL_LAYOUT_LABEL`, `panelLayoutDescription` and `panelLayoutLabel`. Change the component docblock to "Pro. The panel's optional blocks. Mounted through `@/pro`, never imported directly."

- [ ] **Step 2: Remove the now-unused copy and labels**

In `design-settings.copy.ts`, delete `PANEL_LAYOUT_LABEL`, and set `PANEL_SECTION_DESCRIPTION` to `__('The panel’s optional blocks.', 'yayloyalty')`. In `design-settings.option-labels.ts`, delete `panelLayoutLabel` and `panelLayoutDescription`, and drop `DesignPanelLayout` from the type import. `designPanelLayoutSchema` stays in `design.schema.ts`, because the form still parses `panel.layout`.

- [ ] **Step 3: Verify**

Run `git grep -n "PANEL_LAYOUT_LABEL\|panelLayoutLabel\|panelLayoutDescription\|PanelLayoutPreview" -- apps/admin/src`. Expected: no output.
Run eslint and Prettier on the three files from `apps/admin`. Expected: no errors. Run `./node_modules/.bin/tsc --noEmit -p .`. Expected: only the 3 known errors.
In the browser, go to Design tab (Pro), Panel card. Expected: only the "Show reward catalog" switch, under the description "The panel’s optional blocks." Toggle the switch and save. The request body still has `panel.layout`, with its loaded value, under Network → `design`.

- [ ] **Step 4: Commit**

Stage: `apps/admin/src/pro/design/DesignPanelSection.tsx`, `apps/admin/src/pages/settings/design-settings.copy.ts`, `apps/admin/src/pages/settings/design-settings.option-labels.ts`, `docs/plans/2026-10-08-design-preview-highlight.md`
Message: `refactor(design): hide the panel layout setting`

### Task 2: Tag storefront texts in the launcher markup

**Files:**
- Modify: `includes/Engine/Storefront/templates/launcher.php:40,65`
- Modify: `includes/Engine/Storefront/templates/panel-member.php:26,45`
- Modify: `includes/Engine/Storefront/templates/panel-guest.php:24-40,65,81-84`
- Modify: `includes/Engine/Storefront/LauncherPreview.php` (`FRAME_CSS`)

**Interfaces:**
- Consumes: nothing
- Produces: the attribute `data-yl-text="<storefront_text key>"` on the element that prints that text, and in preview documents only, the CSS rule `[data-yl-highlighted]{outline:2px solid #f97316;outline-offset:2px;border-radius:4px}`. Task 3 sets `data-yl-highlighted` on those elements.

- [ ] **Step 1: Tag the launcher label**

In `launcher.php`, add `data-yl-text="launcher_label"` to the `span.yl-launcher-label` (line 40) and to the `h2#yayloyalty-launcher-title` (line 65).

- [ ] **Step 2: Tag the member panel**

In `panel-member.php`, add `data-yl-text="balance_heading"` to the balance-heading `<p>` (line 26). Add `data-yl-text="earn_rate_line"` to the rate `<span>` in the "Make a purchase" row (line 45).

- [ ] **Step 3: Tag the guest panel**

In `panel-guest.php`, add `data-yl-text="signed_out_prompt"` to the prompt `<p>` (line 65). For the earn rate, set `$yayloyalty_earn_rate_step = count( $yayloyalty_steps );` just before the purchase step is appended. Initialise it to `null` beside `$yayloyalty_steps = [];`. In the steps loop, print ` data-yl-text="earn_rate_line"` on the `<li>` whose `$yayloyalty_index === $yayloyalty_earn_rate_step`. The steps stay plain strings, and the whole step row is outlined, because the rate is part of a translated sentence.

- [ ] **Step 4: Add the highlight rule to the preview page**

Append `[data-yl-highlighted]{outline:2px solid #f97316;outline-offset:2px;border-radius:4px}` to `LauncherPreview::FRAME_CSS`. Orange stands apart from most store primaries. The live storefront never loads `FRAME_CSS`, so the attributes are inert there.

- [ ] **Step 5: Verify**

Run `vendor/bin/phpcs includes/Engine/Storefront/LauncherPreview.php includes/Engine/Storefront/templates/launcher.php includes/Engine/Storefront/templates/panel-member.php includes/Engine/Storefront/templates/panel-guest.php`. Expected: no errors or warnings. Compare with `dev/edd` if any appear.
In the browser, on the Design tab, inspect the preview frames in DevTools. Signed in: collapsed has 1 `[data-yl-text="launcher_label"]`; expanded has `launcher_label`, `balance_heading` and `earn_rate_line`. Guest: expanded has `launcher_label`, `signed_out_prompt` and, when a purchase campaign is active, `earn_rate_line` on a "How it works" `<li>`. On the storefront, the launcher looks unchanged.

- [ ] **Step 6: Commit**

Stage: the four PHP files above
Message: `feat(storefront): tag launcher texts for the design preview highlight`

### Task 3: Highlight the hovered or focused text in the preview

**Files:**
- Create: `apps/admin/src/pages/settings/design-settings.preview-highlight.tsx`
- Modify: `apps/admin/src/pages/settings/DesignSettingsPage.tsx`
- Modify: `apps/admin/src/pages/settings/DesignStorefrontTextSection.tsx`
- Modify: `apps/admin/src/pages/settings/DesignLauncherPreview.tsx`
- Modify: `apps/admin/src/pages/settings/LauncherPreviewFrame.tsx`
- Modify: `docs/architecture.md:138`

**Interfaces:**
- Consumes: Task 2's `data-yl-text` attributes and `[data-yl-highlighted]` rule
- Produces, in `design-settings.preview-highlight.tsx`:
  - `export type StorefrontTextKey = keyof Design['storefront_text'];`, moved here from `DesignStorefrontTextSection.tsx`
  - `export function PreviewHighlightProvider({ children }: { children: ReactNode }): ReactElement`
  - `export function usePreviewHighlight(): { highlighted: StorefrontTextKey | null; setHovered: (key: StorefrontTextKey | null) => void; setFocused: (key: StorefrontTextKey | null) => void }`, where `highlighted = hovered ?? focused`. Outside a provider it returns `highlighted: null` and no-op setters.

- [ ] **Step 1: Create the highlight context**

Write `design-settings.preview-highlight.tsx` with the three exports above. It holds two `useState`s (`hovered`, `focused`), and the value is memoised on them. The sections take no props (see `DesignSettingsPage`'s docblock), so the shared state goes through context.

- [ ] **Step 2: Provide it on the Design tab**

In `DesignSettingsPage.tsx`, wrap the `<form>` inside `<FormProvider>` with `<PreviewHighlightProvider>`.

- [ ] **Step 3: Report hover and focus from each field**

In `StorefrontTextField`, import `StorefrontTextKey` from the new module and delete the local type. Set `onMouseEnter={() => setHovered(name)}` and `onMouseLeave={() => setHovered(null)}` on the `<Field>`, so hovering the label counts too. On the `<Input>`, spread `register(...)` into a `const field`, then pass `onFocus={() => setFocused(name)}` and an `onBlur` that calls `field.onBlur(event)` before `setFocused(null)`. Replacing `field.onBlur` instead of chaining it would stop the field being marked touched.

- [ ] **Step 4: Pass the key to both frames**

In `DesignLauncherPreview.tsx`, read `highlighted` from `usePreviewHighlight()`. Pass it to each `<LauncherPreviewFrame>` as `highlightTextKey={highlighted}`.

- [ ] **Step 5: Mark the element inside the frame**

In `LauncherPreviewFrame.tsx`, add the prop `highlightTextKey: string | null` and an iframe `ref`. Add `function markHighlight(frame: HTMLIFrameElement | null, key: string | null): void`. It removes `data-yl-highlighted` from every element in `frame?.contentDocument` that has it, then, when `key` is set, adds `data-yl-highlighted=""` to each `[data-yl-text="<key>"]`. Call it in a `useEffect` on `[highlightTextKey, html]`, and in the iframe's `onLoad`. The `onLoad` call is the one that survives a redraw, because a new `srcDoc` loads after the effect has run.

- [ ] **Step 6: Open the sandbox to the admin, scripts still blocked**

Change the iframe to `sandbox="allow-same-origin"`. Update the component docblock: the frame still runs no script, and `allow-same-origin` only lets the admin reach into it to mark the highlighted text. Never add `allow-scripts` next to it, because with both a framed page can remove its own sandbox. In `docs/architecture.md:138`, replace "in an `<iframe sandbox="">`, so it runs no script and can't reach the admin" with "in an `<iframe sandbox="allow-same-origin">`, so it runs no script; the admin reaches in only to mark the text a Storefront text field edits (`data-yl-text`)".

- [ ] **Step 7: Verify**

From `apps/admin`, run eslint and Prettier on the five SPA files. Expected: no errors. Run `./node_modules/.bin/tsc --noEmit -p .`. Expected: only the 3 known errors.
In the browser, with Design tab → Preview set to Signed in, Desktop:
1. Focus "Launcher label". Expected: an orange outline on the collapsed button's label and on the expanded panel's title.
2. Type a letter. Expected: after the redraw, the new text is still outlined.
3. Hover "Balance heading". Expected: the outline moves to the balance heading. Move the pointer away, and it goes back to the launcher label.
4. Hover "Signed-out prompt" and "Redeem button". Expected: no outline while hovering; it returns to the focused field when the pointer leaves.
5. Switch to Guest and hover "Signed-out prompt". Expected: the prompt is outlined, and the toggle stayed on Guest the whole time.
6. Blur every field and move the pointer away. Expected: no outline anywhere. Switch to Mobile and repeat step 1. Expected: the same outlines, scaled.
7. In the frame, DevTools Console shows no script error, and no `<script>` element in either document.

- [ ] **Step 8: Commit**

Stage: `apps/admin/src/pages/settings/design-settings.preview-highlight.tsx`, `apps/admin/src/pages/settings/DesignSettingsPage.tsx`, `apps/admin/src/pages/settings/DesignStorefrontTextSection.tsx`, `apps/admin/src/pages/settings/DesignLauncherPreview.tsx`, `apps/admin/src/pages/settings/LauncherPreviewFrame.tsx`, `docs/architecture.md`
Message: `feat(design): highlight the edited storefront text in the preview`
