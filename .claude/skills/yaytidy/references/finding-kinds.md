# Finding kinds, owners and the report

What the own pass looks for, how it decides who owns a fact, and how it reports. A **fact** is a key, an enum value, a constant, a bound or default, a message, a shape or a rule. A **marker** is a comment that claims a copy mirrors something, such as "Mirrors Settings::defaults()".

## Kinds

1. **copy:** a fact repeated where an owner exists. Example: a client-side `MAX_ITEMS = 50` next to a server constant `Limits::MAX_ITEMS`, with no marker.
2. **drifted mirror:** a marked copy that no longer matches its owner. Example: a TypeScript array marked "Mirrors OrderStatus::all()" that lacks a value the PHP method returns. Show the differing values from each side.
3. **unowned copy:** two or more copies, none of which names an owner. Example: the same error codes as string literals in a server controller and in a client access helper.
4. **owner dispute:** markers that point at each other, or a marker that contradicts a repo rule. Example: a PHP class says it mirrors a TypeScript file, and that file says it defers to PHP.
5. **stale marker:** a marker that doesn't name a symbol holding the same fact. Examples: it names a method that was renamed; it names a file that holds no keys; it says "mirrors the server" without naming anything.
6. **duplicated logic:** the same function or class written twice. Example: two condition-set classes that differ only in the enum they read.
7. **redundant code:** code the change left unused, or a second implementation of something that already exists. Example: a new helper that rebuilds a placeholder list an existing utility already builds.

## Owners

- A marker counts only if it names a symbol that holds the same fact. The wording doesn't matter, but a marker must name something:
  - negated wording, such as "nothing to keep in sync", names no owner;
  - vague wording, such as "mirrored server-side" or "snake_case to mirror PHP", names no owner;
  - a marker in negated or vague wording that claims a mirror is a stale marker.
- Without a marker that counts, the repo's rules decide, from `CLAUDE.md` and the docs it links. For example, a rule may say which language's copy owns a fact.
- When nothing decides, the finding says "no owner:" followed by the question, and asks.
- A copy that a language boundary forces, such as one in server code and one in client code, is fine when it is marked and matches its owner. Shared test fixtures that are marked as mirrors and match are not findings either.

## Contract

A finding is labelled `contract` when its fix would change something other code depends on:
- a hook name or its arguments;
- a REST route or field;
- an option or meta key;
- a table column;
- a shape shared by server and client code;
- a string that another file reads.

A `contract` finding is fixed only when the developer picks it by its ID.

## Report

```
<scope line>

## Changed by /simplify
- <path> — <one line on what changed>
- outside the target: <path> — clean before; `git restore <path>` offered

## Findings (<n>)

### <id>. <kind>[ · contract]
- Copies: <path:line>, <path:line>
- Owner: <path> <Symbol>    or    no owner: <the rule, or the question>
- Differs: owner <values>; copy <values>
- Fix: <the proposed fix>
```

- "Changed by /simplify" reads "none" when nothing changed, "skipped" when the developer chose to skip it, or "/simplify not available: <reason>".
- IDs are plain numbers, from 1.
- "Differs" appears only on a drifted mirror.
- With more than 20 findings, show the 20 that matter most, give the count of the rest, and offer to narrow the scope. Rank by kind: drifted mirror, owner dispute, unowned copy, copy, stale marker, duplicated logic, redundant code. Within a kind, `contract` findings come first.
- When nothing is found, the findings section is replaced by "No duplicates or source-of-truth problems in <scope>."
