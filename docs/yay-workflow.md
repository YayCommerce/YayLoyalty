<!-- Kit-managed by the yay plugin 0.8.0. Run /yay:harness-update instead of editing; put repo-specific notes in another docs/ file. -->
# From roadmap to release

A worked example: shipping **v1.0.0** of this plugin with three features, from the roadmap to the release tag. Lines starting with `/yay:` are typed into Claude Code; lines starting with `yayh` are run in the terminal.

The skill table and the git rules are in the "How we work here" block of [`CLAUDE.md`](../CLAUDE.md) and in `.claude/rules/yay-git.md`. Every skill shows its options as you type it.

```
/yay:roadmap --add="X; Y; Z" --target=1.0.0  →  /yay:roadmap --target=1.0.0  →  /yay:autopilot --target=1.0.0
→ merge the PRs  →  /yay:release --bundle  →  /yay:release 1.0.0  →  merge  →  /yay:release --tag-only  →  /yay:archive
```

Don't remember a command? Type `/yay:go` followed by what you want in plain words, and it runs the right skill.

## 0. Before you start

```
/yay:setup                 # checks the machine and the repo, fixes what's safe
```

## 1. Put the release's features on the roadmap

```
/yay:roadmap --add="Feature X [M]; Feature Y [S]; Feature Z [L]" --target=1.0.0   # → R-1, R-2, R-3, one PR
/yay:roadmap --target=1.0.0          # fits 1.0.0 into the sprints against capacity; says the earliest release sprint
                                     # and writes docs/roadmap/release-1.0.0.md, the release brief
```

- Each item gets an `acceptance:` line: what a user can see or do when it's done. Its plan's Goal and Verify start from it.
- The release brief is written by hand and read by people: the goal, the release criteria `/yay:release` checks, what's out of scope (and what gets cut first if it runs late), the risks, and open questions with the proposal used until they're answered.

- Plain words work too: `/yay:roadmap add X, Y and Z for 1.0.0`. `--target=minor` (or `patch`, `major`) means the next such version.
- Sizes: S = 1.5 dev-days, M = 4, L = 8. Capacity defaults to 8 dev-days per 2-week sprint. Once 3 items are built, `yayh roadmap pace` compares their sizes with the working days they took and suggests a capacity, and `/yay:roadmap` plans with it.
- Each command opens one `docs(roadmap): …` PR. Merge it.
- An item reads `done` once its plans are merged (`yayh roadmap sync`), so it isn't reported as slipped while it waits for the release. The release turns it into `shipped:<version>`.
- When a sprint ends, `/yay:roadmap --close` writes its review (done, slipped, keep and change), carries over what's unfinished, and records the sprint's velocity. After 2 closed sprints, the next plan uses that velocity as the capacity.
- `/yay:roadmap --status --target=1.0.0` shows what's left for the version, and `/yay:roadmap --status` the current sprint; neither changes anything.
- Moved a feature to the next version? `/yay:roadmap --retarget=R-3:1.1.0`.

## 2. Build each item

Two ways. Pick one.

**A. Step by step, when you want control over each decision**

```
/yay:brainstorm --from=R-1       # 2-4 approaches and a recommendation → BS-1 (you choose)
/yay:design --from=BS-1          # only when users will see new UI → UX-1
/yay:plan --from=BS-1            # tasks, tests, verify checks → P-1
/yay:implement P-1               # its own worktree and branch, a commit per task, tests, review, PR
```

Do the same for R-2 and R-3. A small item can go straight to `/yay:implement R-2`; it picks the lane (tiny, small or standard) and says which.

**B. The whole sprint in one run**

```
/yay:autopilot --target=1.0.0    # (or --sprint=1) brainstorms and plans every item, asks ONE decision sheet, then builds each on its own branch with a PR
```

If it stops, run the same command again: it resumes from each item's brainstorm and plan. Variants:

```
/yay:autopilot --sprint=1 --plan-only   # plans only; answer the sheet now, build later
/yay:autopilot --ready                  # build every plan that's ready
/yay:autopilot R-1 R-3 --parallel=2     # independent items two at a time
```

## 3. The developer's part

- **Review and merge each PR** into `dev` on GitHub. Nothing merges on its own.
- **Where am I?**
  ```
  yayh status          # everything in flight, most urgent first, with the command that moves each on
  yayh next            # just the next command
  yayh next R-2        # the next step for one item
  ```
- **A bug along the way:**
  ```
  /yay:debug "what you see, in plain words"   # root cause with file:line evidence → B-1
  /yay:fix B-1                                # minimal fix + regression test + PR
  ```

## 4. Release v1.0.0

```
/yay:release --bundle            # QA build (1.0.0-rc.1): zips and screenshots in release/bundles/
/yay:release 1.0.0 --dry-run     # preview the version, changelog, checks and build
/yay:release 1.0.0               # release PR; marks the roadmap items shipped, ticks the brief's release criteria it can verify and asks about the rest
# merge the release PR, then:
/yay:release --tag-only          # tag it and build the final zip from the tag
```

Send the `release/bundles/<slug>-<version>/` folder to QA or the store. It holds the zips, `MANIFEST.txt` (sha256), a tester `README.md` and the `ui/` screenshots.

## What each step leaves behind

| ID | What | Where |
|---|---|---|
| `R-<n>` | Roadmap item | `docs/roadmap/sprint-<n>.md` (one file per sprint; `v<x.y.z>.md` lists each version) |
| `BS-<n>` | Brainstorm | `docs/brainstorms/` |
| `UX-<n>` | UI/UX design | `docs/designs/` |
| `P-<n>` | Plan | `docs/plans/` |
| `B-<n>` | Bug report | `docs/bugs/` |
| `BL-<n>` | Backlog idea, open decision or deferred work | `docs/backlog/` |

Each artifact is a markdown file, so the next step (or the next person) picks up where the last one stopped.
