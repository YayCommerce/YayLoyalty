# `.claude/release.config.json` reference

<!-- Purpose: every field the release kit reads from a repo's release config. Update when: a field is
added, renamed or changes meaning (and bump the kit VERSION). -->

One file per repo, written by `yayinit`, committed **identically on every long-lived edition dev
branch** (yaystatus flags drift). Skills read it and never re-ask for these values. Validate with
`yr config validate`.

```jsonc
{
  "schema": 1,
  "settings": {
    "dist": "dist",            // output root, git-ignored; zips go to dist/<plugin>/<edition>/
    "size_jump_pct": 25,       // size gate warns above this change
    "remote": "origin"
  },
  "plugins": {
    "<plugin-key>": {                       // lowercase-dashes; used in names, tags and branches
      "name": "YayPricing",
      "version_policy": "independent",      // or "shared": a bump in one edition proposes it for all
      "propagation": {
        "method": "cherry-pick",            // cherry-pick | merge | manual: how fixes move between editions
        "flow": [["pro-edd", "free"], ["free", "woo-free"], ["pro-edd", "woo-pro"]]  // source → target pairs
      },
      "group_names": { "dev/edd": "yayloyalty" },   // optional: release-branch stem for editions sharing a dev branch
      "omit_edition_suffix": false,         // single-edition plugins may drop "-<edition>" from names
      "tag_format": "{plugin}-{edition}/v{version}",
      "shared_code": [ { "package": "yaycommerce/admin-shell" } ],
      "editions": {
        "<edition-key>": {
          "label": "YayPricing Lite (WordPress.org)",
          "target": "wporg",                // wporg | edd | woo
          "dev_branch": "dev/free",
          "released_branch": "production/free",   // or null; yayfinalize merges releases here
          "branch_group": "dev/free",       // default = dev_branch; editions with the same dev branch form a group
          "slug": "yaypricing",             // the zip's internal folder: must match the target's slug
          "main_file": "yaypricing.php",
          "version_files": [
            { "path": "yaypricing.php", "pattern": "^[ \\t/*#@]*Version:\\s*(?<v>[0-9][0-9.]*)" },
            { "path": "yaypricing.php", "pattern": "define\\(\\s*'YAYDP_VERSION',\\s*'(?<v>[^']+)'" },
            { "path": "readme.txt", "pattern": "^Stable tag:\\s*(?<v>\\S+)" },
            { "path": "README.md", "pattern": "\\*\\*Version:\\*\\*\\s*(?<v>\\S+)", "optional": true, "all": true }
          ],
          "prepare": "",                    // run in the build worktree before the build (deps the build script does not install)
          "build": {
            "cmd": "bash release.sh",       // the repo's own release command, run with stdin closed and CI=1
            "env": {},
            "output": "release/yaypricing.zip"   // path or glob, relative to the repo root; newest new match wins
          },
          "changelog": {                    // see reference/changelog-style.md
            "file": "readme.txt",           // wporg: readme.txt; edd/woo: CHANGELOG.md
            "section": "== Changelog ==",
            "heading": "= {date:M j, Y} - Version {version} =",
            "line": "- {prefix}: {text}",
            "prefixes": { "new": "Added", "improved": "Improved", "fixed": "Fixed", "security": "Security" }
          },
          "required_headers": ["Tested up to", "Requires PHP", "Requires at least"],
          "zip_rules": {
            "must_not_contain": [ { "path": "^blocks/", "why": "Pro-only" }, { "content": "LicenseConfigAdapter", "files": "\\.php$" } ],
            "must_contain": [ { "path": "^readme\\.txt$" } ],
            "allow": [ "lockfiles" ]
          },
          "extra_gates": [ { "id": "validate", "cmd": "php scripts/validate-build.php {zip} lite", "label": "repo validator" } ],
          "phpcs": { "cmd": "vendor/bin/phpcs --standard=phpcs.xml {dir}", "severity": "warn" },
          "plugin_check": true,             // default: true for wporg
          "gate_severity": { "headers": "fail" },
          "store": { "item": "Download #1234", "url": "https://yaycommerce.com/…" },   // for HANDOFF.md
          "support_log": "support/yaypricing-free.md",   // default
          "baseline": { "version": "3.6.0", "sha": "…", "tag": "yaypricing-free/v3.6.0", "confirmed": true, "source": "SVN tags/3.6.0" }
        }
      }
    }
  }
}
```

**Regex patterns** have no delimiters and run multi-line; capture the version as `(?<v>…)`. `all: true`
replaces every match (badges); `optional: true` lets the file be absent.

**Names derived from the config**
- zips: `<plugin>-<edition>-X.Y.Z-test.<n>-<sha>.zip`, `<plugin>-<edition>-X.Y.Z[-r<n>].zip`, client builds `<plugin>-<edition>-X.Y.Z-hotfix.<n>-<sha>.zip` (hotfix) and `<plugin>-<edition>-X.Y.Z-hotfix.<n>-support-<ticket>-<sha>.zip` (support), whose plugin version is X.Y.Z-hotfix.<n>
- release branch: `release/<plugin>-<edition>-X.Y.Z` (editions sharing a dev branch: `release/<group name>-X.Y.Z`)
- support branch: `support/<plugin>-<edition>-<ticket>`
- tag: `tag_format`, created only by yayfinalize when the release is live

**User-level registry** `~/.config/yayrelease/registry.json` (not committed): each plugin's repo path
and `config_ref` (branch to read the config from when the checkout lacks it), the shared-code pin,
`concurrency` (default 3), `worktree_root` (default `~/.yayrelease/worktrees`).
