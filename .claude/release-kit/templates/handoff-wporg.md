# Release handoff: {{label}} {{version}} → WordPress.org

{{resubmission}}

| | |
|---|---|
| Plugin / edition | {{plugin}} / {{edition}} (slug `{{slug}}`) |
| Version | {{version}} |
| Zip | `{{zip}}` ({{size_mb}} MB) |
| sha256 | `{{sha256}}` |
| Built from | `{{git_sha}}` on {{branch}} |
| Shared code | {{shared_code}} |
| QA gates | {{gates}} |

Check the checksum before you start: `shasum -a 256 {{zip}}` must print the sha256 above.

## Upload steps (SVN, done by you)

1. Update your checkout: `svn up` in the `{{slug}}` SVN folder.
2. Replace trunk with the zip's contents: delete everything in `trunk/`, unzip `{{zip}}`, and copy the inside of its `{{slug}}/` folder into `trunk/`.
3. `svn status`: `svn add` new files (`?`) and `svn rm` removed ones (`!`).
4. Copy trunk to the tag: `svn cp trunk tags/{{version}}`.
5. Check `trunk/readme.txt` says `Stable tag: {{version}}` (the build already set it).
6. Assets: if the banner, icon or screenshots changed, update `assets/` too (they are not in the zip).
7. Commit with a message like `Release {{version}}`.
8. Open https://wordpress.org/plugins/{{slug}}/ after a few minutes and check the version and changelog.

## Changelog (already in readme.txt; paste-ready)

```
{{changelog}}
```

{{changes_since}}
## Notes

{{notes}}

When it is live, tell the developer so they can run `/yayfinalize` (that is when the release tag is created).
