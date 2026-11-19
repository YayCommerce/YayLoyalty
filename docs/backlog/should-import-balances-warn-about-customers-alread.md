---
id: BL-19
title: Should Import balances warn about customers already imported from an earlier, different file?
kind: decision
status: decided
from: P-8
affects: []
default: No warning: only the same rows (the import id hashes the parsed rows) are recognised as already imported; an edited file is a new import and its rows apply again
decision: Yes: the preview flags a customer with a row from another import as imported_before (with its date), and the SPA leaves them out unless the merchant ticks them in
created: 2026-11-19
settled: 2026-11-19
---
# BL-19 Should Import balances warn about customers already imported from an earlier, different file?

<!-- idea: why it matters, who asked, and any links. Promote it with /yay:roadmap or /yay:brainstorm --from=BL-19.
decision: the question, the options, and why it couldn't be decided when it came up. The work follows `default:` until it's decided.
deferred: the work left for later, where it would go (files, the plan it fits), and why it waited. -->
