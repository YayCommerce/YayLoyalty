# Release handoff: {{label}} {{version}} → WooCommerce Marketplace

{{resubmission}}

| | |
|---|---|
| Plugin / edition | {{plugin}} / {{edition}} (folder `{{slug}}`) |
| Version | {{version}} |
| Zip | `{{zip}}` ({{size_mb}} MB) |
| sha256 | `{{sha256}}` |
| Marketplace product | {{store_item}} {{store_url}} |
| Built from | `{{git_sha}}` on {{branch}} |
| Shared code | {{shared_code}} |
| QA gates | {{gates}} |

Check the checksum before you start: `shasum -a 256 {{zip}}` must print the sha256 above.

## Upload steps (Woo vendor dashboard, done by you)

1. In the WooCommerce.com vendor dashboard, open the product → Versions → Add new version.
2. Upload `{{zip}}`, set the version to `{{version}}`, and paste the changelog below.
3. Submit. Woo runs its automated checks (QIT) and may send review feedback. If it does, give the feedback to the developer: they fix it with `/yayrelease-fix` and send you a new zip (same version if this one never went live, otherwise a patch version).

## Changelog (paste-ready)

```
{{changelog}}
```

{{changes_since}}
## Notes

{{notes}}

When it is live, tell the developer so they can run `/yayfinalize` (that is when the release tag is created).
