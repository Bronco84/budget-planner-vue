# User journeys

A version-controlled catalog of what users can do in the app, connected to the
code that implements each journey and the tests that verify it. Tooling turns
the catalog into a dependency graph that answers "what does this change
affect, and which tests prove it still works?" in hooks, CI and on demand.

The catalog describes **observed implementation**. Product requirements and
tests define **intended behaviour**. A bug recorded in a journey's
`known_issues` is still a bug. Fix the code; don't treat the catalog as a spec.

## Layout

| Path | What | Edited by |
|---|---|---|
| `journeys/<domain>/<ID>-<slug>.yaml` | One journey per file | humans / agents |
| `config.yaml` | Shared infrastructure, ignore lists, excluded routes | humans / agents |
| `INDEX.md` | Catalog + coverage tables | **generated** (`php bin/journeys index`) |
| `coverage-baseline.json` | Recorded scenario coverage, used to catch regressions | **generated** (`index --update-baseline`) |
| `tooling/journeys/` | Analyzer source (PHP, plus a Node helper for Vue/JS) | developers |
| `tests/Unit/Journeys/` | Analyzer tests (temporary git repos) | developers |

The generated files depend only on journey YAML, so they change only when a
definition changes. The dependency graph is never committed: it is rebuilt on
demand from source and cached per file content.

## Commands

```bash
php bin/journeys validate                   # schema, references, generated files (working tree)
php bin/journeys validate --staged          # same, against the staged index
php bin/journeys validate --strict --with-app   # CI: unmapped routes are errors; cross-check route:list
php bin/journeys coverage [--gaps] [--status at_risk]
php bin/journeys impacted                   # all uncommitted changes (HEAD -> working tree)
php bin/journeys impacted --staged          # index vs HEAD   (what pre-commit sees)
php bin/journeys impacted --unstaged        # working tree vs index
php bin/journeys impacted --base origin/main    # merge-base..HEAD (what CI / pre-push see)
php bin/journeys impacted --files app/Services/BudgetService.php
php bin/journeys impacted --staged --run    # ...and run the affected PHP tests
php bin/journeys impacted --json            # machine-readable report
php bin/journeys show BUDGET-001            # journey: routes, derived files, scenarios, gaps
php bin/journeys show app/Services/PlaidService.php   # reverse lookup: journeys + tests + why
php bin/journeys show budgets.show          # a route
php bin/journeys show PlaidService          # a class (basename or FQCN)
php bin/journeys find plaid                 # search ids, names, routes and dependency paths
php bin/journeys index [--update-baseline]  # regenerate INDEX.md (and baseline)
php bin/journeys graph [--json]             # graph stats / full export
```

`composer journeys -- <command>` works too. Add `-v` to `impacted` or `show`
for every dependency path.

## Journey schema (version 1)

The authoritative rules live in `tooling/journeys/src/Catalog/Schema.php`;
`auth/AUTH-001-passkey-login.yaml` is a complete example.

| Key | Required | Notes |
|---|---|---|
| `schema` | yes | `1` |
| `id` | yes | `DOMAIN-000`, stable forever. The file name must start with it. Never reuse an id. |
| `name`, `domain`, `summary` | yes | `summary` says what the user achieves |
| `status` | yes | `implemented`, `unverified` (believed to work, not confirmed), `planned`, `deprecated` |
| `priority` | yes | `critical`, `high`, `medium`, `low`, based on user/business risk |
| `actors` | yes | e.g. `guest`, `budget_owner`, `admin` |
| `routes` | implemented* | Route names, or `METHOD /uri` for unnamed routes |
| `depends_on` | implemented* | `{path, reason}` for things static analysis can't see; globs allowed |
| `preconditions`, `postconditions`, `business_rules`, `integrations`, `known_issues`, `evidence` | no | lists of strings |
| `steps` | no | `{action, expected}` |
| `scenarios` | yes | see below |
| `confidence` | no | `high`/`medium`/`low`: how sure the author is that the journey reflects the code |
| `last_verified` | no | `YYYY-MM-DD` or `null`: when someone last checked it end to end |

\* An implemented journey needs `routes` or `depends_on`, plus a `happy_path` scenario.

Do **not** list frontend components, services or models by hand. They are
derived from the routes (see below), so they can't go stale.

### Scenarios and coverage

```yaml
scenarios:
  - id: other_users_budget_denied        # snake_case, unique in the journey
    kind: authorization                  # happy_path | validation | authorization | authentication |
                                         # alternate | edge | empty_state | integration | recovery
    description: Another user's budget returns 403.
    coverage: covered                    # covered | partial | uncovered | not_applicable
    tests:
      - tests/Feature/SecurityAuthorizationTest.php::user cannot view another users budget
    note: ...                            # required for partial and not_applicable
```

A test reference is `path` (every test in the file) or `path::name`, where the
name is the exact Pest `test()`/`it()` description (`describe` blocks are
joined with ` → `) or the PHPUnit method name. `validate` fails if the file
or test doesn't exist, or if the test makes no assertions. It warns when a
referenced test never requests one of the journey's routes or uses one of its
controllers/services (a stale mapping).

**Coverage is scenario coverage, not line coverage.** Mark a scenario
`covered` only when a test asserts that behaviour. A test that merely runs the
controller doesn't count. The rollups in `INDEX.md`:

| Column | Scenario kinds |
|---|---|
| Happy | `happy_path` |
| Negative | `validation`, `edge`, `empty_state` |
| Authz | `authorization`, `authentication` |
| Alternate | `alternate` |
| Integration | `integration`, `recovery` |
| E2E | any scenario citing a `tests/playwright/` spec |

Each column is `yes` when all scenarios of that kind are covered, `partial`
when some are, `no` when none are, `n/a` when all are `not_applicable`, and
`missing` when none are declared. Happy, Negative and Authz are required. Use
an explicit `not_applicable` scenario with a note when one genuinely doesn't
apply. Journey status:

- **covered**: every scenario covered or n/a, and the required dimensions are satisfied
- **at risk**: critical/high priority, and the happy path isn't fully covered or authorization is untested
- **uncovered**: no scenario has a test
- **partial**: everything else

## How dependencies are tracked

`php bin/journeys graph` builds a graph from the analysed snapshot:

| Edge | Source of truth | Evidence label |
|---|---|---|
| route → controller action | AST of `routes/*.php` (groups, prefixes, name prefixes, resources) | ast |
| action → classes, Inertia pages, Blade views | AST of that controller method (plus `$this->helper()` calls and constructor deps) | ast |
| class → classes it references | AST with full name resolution | ast |
| model → related model | references between `app/Models/*` | relation (followed one hop) |
| model → policy, migrations for its table | Laravel conventions | convention |
| Vue/JS → imports | `@vue/compiler-sfc` + `@babel/parser` | ast |
| test → classes / routes / literal URIs | AST of the test file | ast |
| journey → routes, `depends_on` | the YAML | declared |

A journey's dependencies are the transitive closure from its routes and
`depends_on` entries. Controllers are analysed per action, so a journey only
depends on the pages and services its own actions use. Model relationships are
followed one hop, otherwise every journey touching `User` would depend on the
whole domain.

`route()` calls in Vue and PHP are recorded as soft links. They're shown by
`show`, but not traversed for impact, because every layout links to most pages.

**Shared infrastructure** (`config.yaml`) covers files whose effect is global
(middleware, providers, `bootstrap/`, `config/`, `app.js`, the lock files). A
change there affects every journey in its scope, and for `all`/`backend` scope
the full suite is recommended.

**Limitations.** Container bindings by string, dynamic `Inertia::render($var)`,
event/listener wiring and anything resolved at runtime aren't visible. They
show up as `unresolved_dependency` warnings, or as `unreached` files during
impact analysis, never as silent omissions. `validate --with-app` checks the
static route extraction against `php artisan route:list`.

## How impact analysis works

1. List the changed files for an explicit mode (`--staged`, `--unstaged`,
   `--working`, `--base`, `--files`), including added, deleted and renamed files
   (`git diff -M`).
2. Build the graph for **both** snapshots, base and target, so deleted and
   renamed files are resolved through the version where they existed.
3. Classify every changed file exactly once:

| Classification | Meaning | Effect |
|---|---|---|
| `mapped` | Reached from at least one journey | Those journeys plus their tests |
| `routes` | Route file: changed lines are attributed to the routes they touch (a group attribute change hits every route in the group) | Journeys using those routes |
| `shared_infrastructure` | Matches `config.yaml` | Every journey in scope (+ full suite for all/backend) |
| `journey_definition` | Journey YAML | That journey |
| `test` | Test file | Run it; journeys citing it |
| `non_journey` | Operator tooling, fixtures, seeders (config) | Tests that reach it still run |
| `journey_tooling` | `tooling/journeys/`, `bin/journeys` | Run `tests/Unit/Journeys` |
| `ignored` | Docs, screenshots, CI config (config) | None |
| `unreached` / `unmapped` / `unanalyzable` | The graph can't place it | **Uncertain**: full suite recommended |

4. Relevant tests = tests cited by affected journeys, plus every test whose own
   dependency closure reaches a changed node (whether or not a journey cites it),
   plus changed test files.
5. Report coverage gaps for the affected journeys, new routes no journey claims,
   and scenarios whose coverage regressed relative to the base.

Impact analysis narrows what to run first. It doesn't prove the rest is
unaffected. CI still runs the complete suite on every push.

## Git hooks

Installed automatically by `composer install` (it sets
`core.hooksPath=.githooks`). It skips CI, `--no-dev` installs, and repos that
already use a custom hooks path. Install manually with
`php tooling/journeys/install-hooks.php`. Hooks that already existed in
`.git/hooks/` still run first.

| Hook | Blocks on | Warns on |
|---|---|---|
| `pre-commit` | invalid journey YAML, broken route/test references, stale `INDEX.md`, coverage regression vs baseline, failing affected tests | coverage gaps and stale mappings for affected journeys, unstaged edits that make test results differ from the staged snapshot |
| `pre-push` | all of the above in `--strict` mode (unmapped routes are errors), new routes without a journey, coverage regressions in the outgoing range, failing affected tests | affected E2E specs not run, dirty working tree |

Pre-commit analyses the **staged index**, not the working tree. Tests,
however, execute against the working tree; the hook says so when that
matters. Hooks never modify files or the index.

Switches: `git commit --no-verify` (or `JOURNEYS_SKIP=1`) bypasses a hook;
`JOURNEYS_SKIP_TESTS=1` validates and analyses but runs no tests;
`JOURNEYS_E2E=1` makes pre-push run the affected Playwright specs (they need the
local Herd site).

## CI

The `journeys` job in `.github/workflows/ci.yml`:

- runs `validate --strict --with-app`
- runs the analyzer tests with Node required
- runs impact analysis against the merge base for pull requests, or the previous commit on push
- uploads `impact.json`, `coverage.json` and `graph.json` as artifacts

Coverage regressions fail the build. The backend job keeps running the full
PHP suite on every push.

## Everyday workflows

**Adding a journey.** Pick the next free id in the domain, copy
`auth/AUTH-001-passkey-login.yaml`, list its routes, write scenarios with
honest coverage, then run `php bin/journeys validate` and
`php bin/journeys index`. Commit the YAML, `INDEX.md` and (if coverage
changed) `index --update-baseline` output together.

**Changing behaviour.** Run `php bin/journeys impacted` before you start, to
see the affected journeys and their tests. Update the journey's
steps/business rules/scenarios to match the new behaviour, update or add
tests, then `index`.

**Adding a route.** Add it to an existing journey's `routes`, or a new journey.
If it really isn't user-facing, add it to `excluded_routes` in `config.yaml`
with a reason. Pre-push and CI fail on unmapped new routes.

**Improving coverage.** Write the test, cite it in the scenario, flip
`coverage`, run `php bin/journeys index --update-baseline`. The baseline only
ratchets when you ask it to.

**Removing a journey.** Set `status: deprecated` rather than deleting it, so
the id isn't reused and the baseline doesn't report a regression.

## When impact analysis is uncertain

`uncertain` entries mean the graph couldn't place a file. Typical causes and fixes:

| Symptom | Cause | Fix |
|---|---|---|
| `unreached`: no journey depends on this file | New code not wired in yet, dead code, or reached at runtime only (container binding, event listener, job) | Wire it into a journey with `depends_on` (with a reason), or add it to `non_journey` |
| `unmapped`: outside the analysis roots | A new top-level directory or file type | Add it to `analysis_roots`, `shared_infrastructure` or `ignore` |
| `unanalyzable` | Syntax error, or Node unavailable for `.vue`/`.js` | Fix the file; install Node + `npm ci` |
| `unresolved_dependency` warning | Missing class/page/view, non-literal `Inertia::render`, unresolvable import | Usually a real bug; otherwise add a `depends_on` |
| Journey you expected isn't flagged | The dependency goes through something static analysis can't see | Run `php bin/journeys show <file>` to see the path; add `depends_on` to the journey |
| Too many journeys flagged | A shared file or broad model relation | `show <file> -v` to see why; narrow `shared_infrastructure` if the pattern is too wide |

Whenever the analysis is uncertain, run the full suite: `php artisan test`.

## Caching

Per-file facts are cached in `storage/framework/cache/journeys/`, keyed by the
git blob hash of the file contents and the analyzer version. Changed content
gets a new key, so the cache never needs invalidating. Delete the directory, or
set `JOURNEYS_NO_CACHE=1`, to force a cold run. Bump `FactStore::VERSION`
whenever extraction logic changes.
