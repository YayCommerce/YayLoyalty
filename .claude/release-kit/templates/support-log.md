# Support fixes: {{plugin}} / {{edition}}

<!--
Purpose: every client build sent to a customer for this edition (yaysupport, yayhotfix), and fixes
  from sibling editions this edition decided not to take. Lives on this edition's dev branch.
Update when: never by hand. `yr support add|set-dev-commit|set-status|mark-released|port-decision`
  rewrites the tables (the yaysupport, yayhotfix and yayfinalize skills call it). Status is
  "unreleased" until the release that includes the fix is finalized; yayrelease puts unreleased
  fixes in its changelog draft. "Client build" is the version inside the zip the client got
  (X.Y.Z-hotfix.N): every release must be above it and contain the fix (`yr support check`), or the
  row must be set to wont-release.
-->

## Fixes

| Ticket | Date | Customer runs | Client build | Fix | Support commit | Dev commit | Status | Released in |
|---|---|---|---|---|---|---|---|---|

## Port decisions

| Commit | From edition | Subject | Decision | Reason | Date |
|---|---|---|---|---|---|
