# What to put in HANDOFF.md notes

<!-- Purpose: the "Notes" section of a release handoff, per target. Update when: a store's upload process changes. -->

The manager uploads by hand and is not a developer. Notes say only what they need to act on, in
plain words, most important first. "Nothing unusual." when there is nothing.

**Always mention**
- Gate warnings or skipped gates, and why it is still OK to ship.
- Commits on the dev branch newer than the approved test build (not in this release).
- A sibling edition left at an older version under a shared version policy.
- A shared-code pin warning (e.g. the admin-shell tag reports a different VERSION than its name).
- Resubmission: what changed since the previous zip (the handoff's "Changes since previous submission" section).

**wporg**
- Assets (banner, icon, screenshots) changed? They live in SVN `assets/`, not in the zip.
- `Tested up to` raised? Say so: WordPress.org shows it on the plugin page.
- Requires PHP / Requires at least raised: customers on older versions stop getting the update.

**edd**
- The store product (config `store.item`) and whether the download file name should stay the same.
- License-related changes (activation, expiry behaviour): support should know before customers ask.

**woo**
- The `Woo:` header: present or missing in the main file (Woo matches the product by it; missing has caused review questions).
- QIT: if the repo has `qit.json`, Woo runs those tests; mention known QIT warnings.
- Whether this zip is a response to Woo review feedback, and which points it addresses.
