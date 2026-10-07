# Releasing and shipping fixes

Operating manual for this repository. Written for a maintainer, or an agent, who
has to cut a release or ship a bug fix without reading the entire history.

`DEVELOPING.md` covers conventions and layout. This file covers the two procedures
that actually recur, and - more importantly - the mistakes that have already been
made here, because all four runtime bugs this add-on shipped were the same mistake.

---

## Part 1. The rule that would have prevented every bug so far

**Do not write a call against a XenForo or XFRo API from memory. Look it up.**

Every one of these shipped and was found on a live forum:

| Bug | Call | Reality |
|---|---|---|
| 1.0.0 | `$this->app()->options()` | `AbstractService` has a protected `$app` property, no `app()` method |
| 1.0.0 | `class Setup extends AbstractSetup` | `install()`/`upgrade()`/`uninstall()` are abstract; the three step-runner traits are mandatory |
| 1.1.0 | `$this->em()->findAll()` | The entity manager has no such method; `XF\Finder` is how you get a collection |
| 1.1.1 | `\XF::logInfo()` | Does not exist. `XF::logError()` is the only logging entry point besides `logException()` |
| 1.1.2 | `count($code)` | `count()` on a string is a `TypeError` on PHP 8. Every call has a right one; `strlen()` here |
| 1.1.2 | `\XF::phrase()` in `configure()` | Legal, and fatal: `XF\Cli\Runner` builds the command list before it starts its app |
| 1.1.2 | `$output->table()` | Not on `OutputInterface`; table rendering is on Symfony's `Table` helper |

All seven read plausibly. None was reachable by `php -l`. Only the first four were
reachable by any check that existed at the time.

The last two share a cause worth naming: **both are on a path nothing in the
repository ever executes.** A clean install runs `postInstall()` and stops; a CLI
command runs when somebody types it. Development and CI do neither, so a release
could go out with both broken and every automated signal green. Part 4 is the
answer; `TESTING.md` is where the commands to run actually live.

### How to look a call up

Against a real XenForo tree. Either your install, or the release archive:

```bash
unzip -q xenforo-2.3.x-release.zip -d /tmp/xf
grep -oP '^\tpublic static function \K\w+' /tmp/xf/upload/src/XF.php | sort
grep -oP '^\tpublic function \K\w+' /tmp/xf/upload/src/XF/Mvc/Entity/Manager.php | sort
```

If you cannot check it, treat the call as unverified and say so in the commit
message. "I could not verify that `X` exists" is a useful thing to leave in a
history.

---

## Part 2. The checks, and what each one is for

```bash
php tools/check.php        # structure: namespaces, phrases, options, extensions
php tools/class_check.php  # loads every class, resolves calls
php tools/build.php        # hashes.json + release archive
```

`php tools/lint.sh` does not exist any more; neither does `tools/validate_structure.py`.
They were removed because XenForo already does what they partly did, and having
the tooling in a language the project does not otherwise use was the cost.

### `check.php`

Structural only. A namespace that does not match its path, a class extension whose
`to_class` has no file, a phrase used from PHP with no master phrase behind it, a
`BumpResult` constant with no reason phrase, a translation out of sync with the
master list in either direction, an option referenced but not defined.

### `class_check.php`

Loads every add-on class against stubbed vendor classes, so PHP performs the checks
it would perform on a forum. Four passes:

| Pass | Catches | Found |
|---|---|---|
| class declaration | unimplemented abstract methods | 1.0.0 `Setup` |
| `$this->method()` | a method the class or its parents do not have | 1.1.1 `Setup::service()` |
| chained calls off a helper's return | `$this->em()->findAll()` and the like | 1.1.0 |
| static calls on `\XF` | `\XF::logInfo()` and the like | 1.1.1 |

Method lists are extracted from the XenForo 2.3.2 source, not written by hand.
They go stale on a XenForo upgrade - `UPGRADE.md` row 9 is the reminder. A stale
stub fails loudly, which is the safe direction.

Helpers whose return type is not in `HELPER_RETURN_TYPES` are simply not
chain-checked. That gap is deliberate: guessing a return type produces false
failures, and a check people learn to ignore is worse than no check. **If you call
something on the result of a helper not in that map, add the map entry.**

### `build.php`

Writes `hashes.json` (committed) and the release archive. Needs `ext-zip`; without
it, `hashes.json` is still regenerated and the archive step is skipped.

### CI

`php -l` on PHP 7.4 and the runner's current PHP; `check.php`; `class_check.php`;
build; fail if the rebuilt `hashes.json` differs from the committed copy.

**CI has never run the add-on.** XenForo has no harness for class extensions, and
a mock of `XFRM\Entity\ResourceItem` would test the mock. This is the whole reason
a real install on a real board is still the last and most important check.

---

## Part 3. Shipping a bug fix

### 1. Reproduce, and get the stack trace

The trace names the class and line. That is usually enough to identify the bad
call - all four bugs were readable directly from the trace.

### 2. Verify the API in the trace

Per Part 1. Do not skip this; it is the step that was skipped every time.

### 3. Fix it, and make a check cover it

Ask: which of the four passes should have caught this, and why did it not? Either
the pass does not exist, or the code is in a shape it cannot see. If the fix is
outside all four shapes, add a check.

For a bad method call, `class_check.php` already covers the shape - confirm it now
does:

```bash
php tools/class_check.php          # must fail on the pre-fix source
```

If it does not, extend it before fixing. A fix without a check means the next agent
inherits the same trap.

**Verify that the check actually fails before you believe it does.** A check that
passes on the pre-fix source catches nothing, and a check nobody re-runs against
broken input is a comment with an exit code. Put the bug back, run the check,
confirm it fails, restore the fix.

### When no check can cover it

Not every bug has a sound static check, and writing one anyway is worse than
having none: a check with false positives gets disabled, and a disabled check
catches nothing while still reading as protection.

`count($code)` where `$code` is a string (1.1.2) is the example. A pass would have
to infer `$code`'s type, and the only way to do that reliably is to parse types -
at which point it fails on every correct-but-unannotated call. The honest options
are the clean-install procedure in Part 4 and reading the trace, and the changelog
should say which one carried the fix rather than implying a check did.

Two shapes from the same release *were* worth checking, and `check.php` now has
them: an `\XF::app()`-backed call inside a CLI command's `configure()`, and
`$output->table()`. Both are unambiguous text patterns with no false-positive
surface.

### 4. Decide the version

| Change | Version |
|---|---|
| A fix anyone installing would want | patch - 1.1.2 becomes 1.1.3 |
| A fix for a broken install path | patch, and say so prominently in the changelog |
| A new option, a new CLI flag, new behaviour | minor |
| Anything writing different data | minor, and an `UPGRADE.md` note |

`version_id` is a monotonically increasing integer, not derived from the version
string. Current: `1000015` for `1.1.3`. The 1.0.x/1.1.x series each consumed one.

A **phrase's** `version_id` is separate and follows XenForo's own rule: it exists so
an upgrade knows a changed text must be rewritten, so it moves only when a phrase's
text changed. A newly added phrase needs no bump. All 46 master phrases currently
sit at `1000010`, carried over from 1.0.0 - which is correct, since nothing has
changed a phrase's text since. If you edit one, bump it.

### 5. Changelog, in the shape the release body needs

The GitHub release body is the `## [<version>]` section of `CHANGELOG.md`,
extracted by `tools/release_notes.php`. So write it for a reader who has never
seen the repository: what broke, what it does now, and anything an administrator
has to do.

Two rules learned the hard way:

- **Do not claim something the code does not do.** 1.1.0's notes said "a
  translation can never fail an install", which was not true - the per-language
  `try` wrapped the import but not the lookup, which is the database call. The
  claim outlived the code.
- **Name the screen correctly.** The translation docs shipped in 1.1.0 pointed at
  "Admin CP -> Logs -> Admin log". There is no Admin log. `XF::logError()` writes to
  **Admin CP -> Logs -> Error log**.

Verify before tagging:

```bash
php tools/release_notes.php | head -20   # the section must exist and be non-empty
```

`CHANGELOG.md` only begins at 1.1.1; the 1.1.0 body was written by hand and lives
only on the GitHub release. Audit older claims there, not in the file:

```bash
gh release view 1.1.0 --json body --jq .body
```

### 6. Build, commit, tag

```bash
php tools/check.php && php tools/class_check.php && php tools/build.php
git add -A && git commit            # one logical change per commit
git tag -a 1.1.3 -m "..." && git push origin 1.1.3
```

Tagging starts the release job. There is no other release step; the archive is
built and published by CI. A `v`-prefixed tag deliberately matches nothing.

Commit the regenerated `hashes.json` with the fix, not separately - CI fails if it
is stale, and a stale one makes File Check on the forum report every changed file
as inconsistent.

### 7. Check the release, not just the run

```bash
gh release view 1.1.3 --json assets,isDraft    # asset must be present, draft false
```

A green release job is not proof an archive was attached. That was true once:
`actions/checkout` ran after `download-artifact`, and its `git clean -ffdx` deleted
the archive the download had just fetched. `action-gh-release` does not fail on an
unmatched glob, so the job went green having published notes and no archive. Both
are fixed - checkout now runs first, and `fail_on_unmatched_files: true` is set -
but verify the asset rather than trusting the job.

Then install the artefact from scratch on a test forum, with the add-on not present
first.

---

## Part 4: Why clean installs matter

Most of the bugs only occurred during `postInstall()`, which runs **once**,
on the first install, and never again, or during a CLI command, which nobody runs
during development. A forum where the add-on is already installed, and where the
CLI has never been invoked, cannot show either.

Before cutting any release that touches install-time or approval-time code:

1. **Install from scratch** on a forum where the add-on is not installed, through
   the Admin CP, from the built archive - not from a working copy.
2. **Approve something** in the moderation queue: a resource, and its thread. This
   is the other path only a real forum exercises.
3. **Run both CLI commands**, each with the flags that only write nothing:
   `php src/cmd.php hlds-run-rpd:import-translation` (no arguments - the language
   listing) and `php src/cmd.php hlds-run-rpd:backfill --dry-run`. Both crashed on
   a released version, in ways that a clean install and a data rebuild cannot show.
4. Read the error log afterwards. `postInstall()` writes there.

The manual checklist for the rest is `TESTING.md`.

---

## Part 5: If you are an agent working on this

- The repository is the source of truth. This file plus `DEVELOPING.md` describe
  the procedures; the changelog records what actually happened, including the bugs.
- **Verify API calls against XenForo source before writing them.** Four releases in,
  this is the single highest-value thing you can do.
- Run `tools/check.php` and `tools/class_check.php` before claiming anything works.
  They are the only automated signal, and they are deliberately narrow.
- Do not claim a behaviour in a changelog or a commit message that you have not
  read in the code. Two false claims in this history cost a debugging session each.
- If a check fails, do not relax the check. Every one of them failed for a real
  reason, including the ones that failed on correct code - see the `readCode()`
  bug in 1.1.1, where a fix was needed in the tooling rather than the add-on.
- Report what you could not verify. A note that a call is unverified is worth more
  than a confident wrong one.