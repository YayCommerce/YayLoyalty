# Release handoff: {{label}} {{version}} → EDD store

{{resubmission}}

| | |
|---|---|
| Plugin / edition | {{plugin}} / {{edition}} (folder `{{slug}}`) |
| Version | {{version}} |
| Zip | `{{zip}}` ({{size_mb}} MB) |
| sha256 | `{{sha256}}` |
| Store product | {{store_item}} {{store_url}} |
| Built from | `{{git_sha}}` on {{branch}} |
| Shared code | {{shared_code}} |
| QA gates | {{gates}} |

Check the checksum before you start: `shasum -a 256 {{zip}}` must print the sha256 above.

## Upload steps (EDD store, done by you)

1. Open the product in the store admin (Downloads → {{store_item}}).
2. Under Download Files, upload `{{zip}}` as the new file. The file name customers download can stay as it is; the plugin folder inside the zip is `{{slug}}/`.
3. Under Software Licensing, set Version to `{{version}}` and paste the changelog below into the changelog field.
4. Save, then on a test site with an active license check that "update available" shows {{version}}.

## Changelog (paste-ready)

```
{{changelog}}
```

{{changes_since}}
## Notes

{{notes}}

When it is live, tell the developer so they can run `/yayfinalize` (that is when the release tag is created).
