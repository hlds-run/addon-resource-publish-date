# Changelog

All notable changes to this add-on are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
the project uses [semantic versioning](https://semver.org/).

`version_id` in `addon.json` is `version_string` encoded, not a counter, and it
must be recomputed for every release - the CLI command
`php src/cmd.php xf-addon:bump-version HldsRun/ResourcePublishDate --version-id XXXXXXXX --version-string X.X.X`
does it for you, including updating the `version_id` / `version_string`
attributes on every phrase. See [docs/VERSIONING.md](docs/VERSIONING.md) for the
mask, and [docs/DEVELOPING.md](docs/DEVELOPING.md) for the naming rules - the git
tag, the release title and `version_string` are the same string, with no `v`
prefix anywhere.

## Unreleased

Nothing yet. Add entries here as you go, one line per user-visible change.

---

## [1.2.1] - 2026-10-08

Three numbers in `addon.json` were wrong in a way nothing was checking. Nobody
was paged by this and no forum broke; the cost was that the versioning was
fiction - an add-on whose `version_id` did not correspond to its
`version_string`, requiring a XenForo release that does not exist, and shipping
under a scheme whose whole purpose is to keep browsers off stale JS/CSS.

### For administrators

Nothing to do. This changes no behaviour and no data. There is no schema step,
so `php src/cmd.php xf:addon-upgrade HldsRun/ResourcePublishDate` only refreshes
the file hashes and the phrase metadata; running it is optional. **Clear your
browser cache** if you want to be certain you are not looking at a cached
template from 1.2.0.

### Fixed

- **`version_id` was a hand-maintained counter instead of the version it claims
  to be.** `addon.json` carried `1000016` for `1.2.0`, and each release before
  it consumed one number. XenForo compares this value to decide whether the add-on
  is out of date and appends it to template cache-busters, so the number being
  arbitrary rather than the version encoded left browsers free to keep serving
  the previous release's JS/CSS. It is now `1020170`, the correct encoding of
  `1.2.1` Stable. It is also higher than the old value, so existing installs are
  offered the upgrade rather than being told they are current.

- **The `require` floor named a XenForo release that does not exist.** Both `XF`
  and `XFRM` required `2030010`; XenForo 2.3.0 Stable is `2030070`. The bad value
  decodes as "2.3.0 Alpha", so it was a floor no installed forum could report
  satisfying. Both are now `2030070`.

- **Phrase `version_id` values used the old counter** and are re-encoded:
  `1000010` → `1000070` (46 phrases, text unchanged since 1.0.0) and `1000016` →
  `1020070` (the one phrase added in 1.2.0).

### Added

- **`tools/check.php` now validates versioning.** Three checks that each catch
  one of the errors above: the `version_id` mask, `version_id` against
  `version_string`, and the state digit of a `require` floor. XenForo's own
  `xf-addon:validate-json` cannot catch any of them - it verifies that
  `version_id` is an integer and nothing more, so every wrong number this add-on
  has ever shipped passed it cleanly.

### Changed

- **`Setup.php` documents the `upgradeXXXXXStepY` naming rule**, where `XXXXX` is
  the target `version_id` with no separators. No steps exist today; a mismatched
  number would be skipped silently, with no error and no log line.

- **[docs/VERSIONING.md](docs/VERSIONING.md)** records the mask, the current
  values and the release procedure.

---

## [1.2.0] - 2026-10-07

The category exclusion option was unusable: it rendered as a settings row with a
title, an explanation and no field to answer them with, so exclusions could not
be configured from the options page at all. Fixed, and rebuilt as the compact
component the core widget settings use for their own node limiter.

### Fixed

- **The excluded categories option had no field to select in.** The row rendered
  its title and explanation and nothing else. The choices come from
  `AbstractCategoryTree::getCategoryOptionsData()` in the structured
  `id => ['value' =>, 'label' =>]` form, but they were passed through
  `XF\Option\AbstractOption::getCheckboxRow()`, whose `mergeChoiceOptions()`
  step only accepts scalar labels and silently dropped every entry.

### Changed

- **The excluded categories option is now the compact multi-select** used by the
  core widget settings for their node limiter - scrolling list, seven rows
  visible, *All categories* pre-selected at the top - instead of a checkbox per
  category. Long category trees meant a checkbox list that ran past the fold of
  the options page. Nesting is shown with non-breaking spaces, not the run of
  hyphens `getCategoryOptionsData()` bakes into its labels, so indentation no
  longer looks like part of the category name.

### For administrators

Two things to do, both in the order given:

1. **Run the data rebuild**, because the rendering callback was renamed:

   ```bash
   php src/cmd.php xf:addon-upgrade HldsRun/ResourcePublishDate
   ```

   The old name lives in `_data/options.xml`, so deploying the new code over
   unchanged option data shows `DEBUG: hldsRunRpdExcludedCategories - Invalid
   method ...::renderCheckbox` in place of the option. Nothing else breaks; the
   rebuild fixes it. Stored exclusions are untouched.

2. **Re-import the Russian translation**, which gained one phrase:

   ```bash
   php src/cmd.php hlds-run-rpd:import-translation 6
   ```

   The list argument is your language's id; run the command with no arguments to
   see them. Until you do, the first row of the new list reads *All categories*
   in English.

---

## [1.1.3] - 2026-10-07

Three fixes to the paths that only run once. All three were found on the test
forum after a clean install of 1.1.2.

### Fixed

- **Russian phrases were never installed.** On a fresh install the add-on stayed
  half-English in every language whose code carries a region suffix - which
  includes Russian, because XenForo's own language pack ships `ru-RU` and the
  add-on ships `ru.xml`.

  Admin CP -> Phrases for that language showed the English master text for all 46
  phrases, because no translated row had been created. The importer compared the
  board's language code with `count($code)`, and `count()` on a string is an error
  on PHP 8, so the comparison threw before it could match. Install logs the
  failure and continues by design, so the add-on reported a successful install
  having imported nothing.

  Existing installs are not changed by this release: a re-import would overwrite
  phrases an administrator may have edited in Admin CP -> Phrases. If your forum
  is in this state, run `php src/cmd.php hlds-run-rpd:import-translation` (no
  argument) to see which languages match, then pass a language id to import it.

- **`php src/cmd.php` failed on every command on the board.** The message was
  `A second app cannot be setup`, and it was not limited to this add-on's two
  commands - XenForo builds its whole command list before starting its CLI app,
  and this add-on's commands created a web app while being registered, which
  left nowhere for the CLI app to go. Both commands now describe themselves in
  plain English, as XenForo's own do.

- **`hlds-run-rpd:import-translation` crashed when listing languages.** It called
  a table-rendering method that does not exist on Symfony's output interface.
  The listing now renders.

### Added

- `tools/check.php` rejects `\XF::app()`-backed calls inside a CLI command's
  `configure()`, and `$output->table()`. Both shipped in 1.1.2 and neither was
  visible until a command was run on a live board.

---

## [1.1.2] - 2026-10-07

Fixes a fourth fatal error on the install path, again found on the test forum.

### Fixed

- **`Call to undefined method XF::logInfo()`**

  `Setup::postInstall()` wrote its summary with `\XF::logInfo()`. XenForo exposes
  no such method: `XF::logError()` is the only logging entry point besides
  `logException()` and the two fatal handlers. Now `logError()`.

  It lands in Admin CP -> Logs -> Error log, which is also where 1.1.0's
  documentation wrongly said "Admin log". There is no info-level log to write to,
  and a note about translations is worth more in the error log than nowhere.

### Added

- `tools/class_check.php` now verifies **static** calls on the XF facade -
  `\XF::foo()` against the 90 public static methods of `src/XF.php` in 2.3.2.

  The previous three passes covered `$this->`, chains off a helper's return, and
  chained calls. None of them looked at a static call, which is why this one got
  through while the others were caught.

### Summary of the four runtime bugs

All four were calls to methods that read plausibly and do not exist, all found on
a live board rather than in CI, all on the approval or install path:

| Bug | Shape | Now covered by |
|---|---|---|
| `PublishDateManager::app()` | `$this->method()` | direct `$this->` pass |
| `em()->findAll()` | chained call | chained-call pass |
| `Setup::service()` | `$this->method()`, wrong parent | direct `$this->` pass |
| `XF::logInfo()` | static call | static-call pass |

None of them is reachable by `php -l`, and only the last three by running the add-on.
That is the argument for the class-loading check existing at all.

---

## [1.1.1] - 2026-10-07

Fixes a fatal error in the automatic translation import, found by installing 1.1.0
on the test forum.

### Fixed

- **`Call to undefined method XF\Mvc\Entity\Manager::findAll()`**

  `TranslationInstaller::findLanguageForCode()` collected languages with
  `$this->em()->findAll()`. The entity manager has no such method - the way to get
  a collection of every row is `XF\Finder`, which is what the CLI listing already
  used. Because this ran from `postInstall()`, it aborted the install batch.

- **A translation could still abort an install.** The per-language `try` wrapped
  only the import; the language lookup ran outside it, and the lookup is the
  database call. The 1.1.0 notes claimed a translation can never fail an install,
  which was not true of that path. The whole per-language body is now inside the
  `try`.

### Added

- `tools/class_check.php` now resolves chained calls as well as direct ones:
  `$this->em()->findAll()`, `$this->db()->`, `$this->finder()`, `\XF::em()`,
  `\XF::finder()` and `\XF::app()` are each checked against a stub whose method
  list is extracted from the XenForo 2.3.2 source.

  Two of this add-on's three runtime bugs were exactly this shape - a method that
  reads plausibly and does not exist. Only the stubs were missing before, and the
  gap was invisible until it shipped.

### Fixed in the tooling itself

- `readCode()` never took its tokenizer path. It tested
  `class_exists('\token_get_all')`, and a leading namespace separator makes that
  false - so the regex fallback ran on every file and mangled the source. Every
  `$this->` and chained check was reading damaged input.

---

## [1.1.0] - 2026-10-07

Translations are now installed with the add-on instead of needing a separate
command.

### Changed

- **Shipped translations are imported automatically on install.**

  Installing the add-on on a board with a Russian language now leaves it fully
  Russian, instead of English until somebody reads the manual. Every shipped
  translation is matched against the board's own languages and imported into each
  one that matches; the outcome is written to the Admin CP log.

  Only languages that already exist are touched - a translation cannot create a
  language. A board with no Russian row still ends up with English only, and the
  log says so rather than failing.

### Added

- `tools/class_check.php` now skips comments and string literals when looking for
  `$this->` calls.

  It was matching itself: a comment explaining that a method must *not* be called
  read as a call to it, and the check failed on correct code. Uses the tokenizer
  when it is available and falls back to a regex where it is not.

- The `import-translation` listing gained a **Shipped translation** column, so the
  most common cause of "nothing was imported" - a board whose language code no
  shipped file matches - is visible without guesswork.

### Notes

- A translation is imported on **install only**, never on upgrade. Re-importing on
  upgrade would overwrite phrases an administrator has customised in Admin CP ->
  Phrases, with no way to recover the previous wording. `hlds-run-rpd:import-translation`
  is there for deliberate updates.

- Matching is on the language code and its region suffix, because XenForo's own
  language packs use the suffixed form - the Russian pack ships `ru-RU`, not `ru`.
  An equality test against the file name would have matched nothing and reported
  success.

- A translation can never fail an install. Errors are logged per language and the
  remaining languages are still imported.

---

## [1.0.1] - 2026-10-07

Fixes found by installing 1.0.0 on a test forum and approving a resource and a
thread from the moderation queue. Both were fatal errors on the one code path the
add-on exists for.

### Fixed

- **`Call to undefined method PublishDateManager::app()`**

  `XF\Service\AbstractService` has no `app()` method. It has a protected `$app`
  property and the helpers `db()`, `em()`, `repository()`, `finder()`,
  `findOne()` and `service()`. Seven option reads called `$this->app()`, so the
  first call - `isEnabled()`, from `evaluateResource()` or `evaluateThread()` -
  threw.

  It surfaced from `onApprove()`, which means the date was never moved and the
  moderator saw a 500 from the queue job. Because 1.0.0 also shipped the resource
  state change, the resource *was* approved; only the re-dating failed, so the
  failure looked like "the add-on silently does nothing" rather than "the add-on
  is broken".

### Added

- **`tools/class_check.php` now resolves `$this->method()` calls.**

  It loads every class and checks each `$this->` call against the class and its
  parents. This is what the bug above was, and `php -l` cannot see it either:
  the file parses, the method name looks reasonable, and the call only fails when
  that line executes.

  The `AbstractService` stub was corrected alongside it. It had the `$app`
  property but none of the helper methods, so the new pass reported
  `$this->db()` as unknown until it mirrored the real class.

Phrase `version_id` values are unchanged from 1.0.0: no user-facing string changed
in this release.

---

## [1.0.0] - 2026-10-07

First release.

XenForo Resource Manager has no publish date. It decides what is "new" from
`xf_rm_resource.last_update`, and approving a resource only changes its
visibility - it never touches that field. A resource submitted on the 1st and
approved on the 15th therefore lands deep inside the latest-resources widget,
`/resources/`, *What's new*, its category list and the category sidebar, and
disappears from *What's new* entirely once it is older than *Read marking data
lifetime*.

This add-on treats approval as publication: the resource becomes new when it
actually becomes visible.

### Requirements

- XenForo 2.3.0 or newer
- XenForo Resource Manager 2.3.0 or newer

The floor is enforced by `addon.json` rather than recommended, because it is
load-bearing. XenForo 2.3 renamed `XF\Service\Thread\Approver` to
`XF\Service\Thread\ApproverService`. An add-on declaring the old class installs
without an error and then silently never runs, because nothing resolves it.
Pinning the floor turns a silent failure into a refusal to install.

Tested against XenForo 2.3.2 and XFRM 2.3.2.

### What it changes

| Field | Change | Why |
|---|---|---|
| `xf_rm_resource.last_update` | set to the approval time | the field the RM sorts by, and the one *What's new* filters on |
| `xf_rm_resource_update.post_date`, newest **visible** update | set to the approval time | `last_update` is a denormalised copy of it; changing one without the other silently reverts at the next counter rebuild |
| `xf_thread.post_date`, `xf_thread.last_post_date` | set to the approval time | makes the resource's discussion thread appear in *Latest posts* |
| `xf_post.post_date`, first post of that thread | set to the approval time | same reason; only while the thread still has a single post |

`resource_date` and resource version `release_date` are **not** touched.
`resource_date` records when the content was created and the release date is
authored content; neither means "when it went public".

No database changes. The add-on creates no tables and adds no columns.

### Behaviour

- Only the **first** approval moves a date, by default. Re-approval after an
  unapprove does not, so content cannot resurface repeatedly. Detected from
  `approve` entries in the moderator log, which requires moderator log retention.
- A discussion thread is only re-dated while `discussion_type` is `resource` and
  the thread still has a single post. Once a thread has replies it has been
  public, and moving its first post would contradict the ordering XenForo has
  already shown users.
- A resource with no visible update is left alone, and the reason is logged.

### Options

Grouped under **Options → Resource publish date**.

| Option | Default | Purpose |
|---|---|---|
| Shift publish dates when content is approved | on | master switch; off restores stock behaviour without uninstalling |
| When to shift dates | only the first approval | alternative: every approval |
| Resources | on | shift `xf_rm_resource.last_update` |
| Resource discussion threads | on | shift thread and first-post dates |
| Minimum time in the moderation queue | 0 minutes | skip the shift below this threshold |
| Excluded resource categories | none | categories that keep their original dates |
| Record date shifts in the moderator log | on | audit trail next to the approval entry |

The category exclusion renders as a real category tree using XenForo's
`edit_format="callback"` mechanism, so the add-on ships no templates at all.

### CLI

- `hlds-run-rpd:import-translation <language_id> [--file=<code>] [--dry-run]`

  XenForo cannot ship translations from `_data/`, so the ones in `_translations/`
  are inert XML unless something reads them. This imports them through
  `XF\Service\Phrase\ImportService`, the same service core's language-pack
  importer uses: phrases are matched by title and updated rather than
  duplicated, and the delete-then-import is scoped by both `language_id` and
  `addon_id`, so re-importing touches only this add-on.

- `hlds-run-rpd:backfill [--dry-run] [--days=N] [--limit=N] [--threads] [--log]`

  Re-dates resources approved before the add-on was installed, using approval
  timestamps from the moderator log. `--dry-run` is the intended first use.
  `--log` is off by default because moderator log entries need an HTTP request to
  resolve the moderator's IP.

### Translations

Master phrases are English (`_data/phrases.xml`, 46 phrases). Russian ships in
`_translations/ru.xml` and is applied with the CLI command above. `tools/check.php`
fails if the two key sets ever drift apart in either direction.

### Notes for reading the code

- The XenForo-facing surface is exactly two `protected onApprove()` overrides.
  All logic lives in `Service\PublishDateManager`, which returns a `BumpResult`
  explaining not only what happened but why, so the moderator log, the CLI output
  and the documentation share one vocabulary.
- `onApprove()` rather than `approve()`, because it is only reached after the
  state change has succeeded. The cost is one extra `UPDATE` per approved
  resource, next to the notification emails XFRM already queues.
- CI lints every file against PHP 7.4 and against the runner's current PHP. One
  version is enough to catch PHP 8-only syntax, because PHP syntax features are
  additive - anything newer than 7.4 fails to parse on 7.4.
- CI also loads every add-on class against stubbed vendor parents
  (`tools/class_check.php`). `php -l` cannot see that a class fails to implement
  its parent's abstract methods, and XenForo does not check it until the
  data-rebuild job loads the class - which is why `Setup` initially reached a
  forum without the three step-runner traits that `XF\AddOn\AbstractSetup`
  requires, and reported success on install.

[1.1.3]: https://github.com/hlds-run/addon-resource-publish-date/releases/tag/1.1.3
[1.1.2]: https://github.com/hlds-run/addon-resource-publish-date/releases/tag/1.1.2
[1.1.1]: https://github.com/hlds-run/addon-resource-publish-date/releases/tag/1.1.1
[1.1.0]: https://github.com/hlds-run/addon-resource-publish-date/releases/tag/1.1.0
[1.0.1]: https://github.com/hlds-run/addon-resource-publish-date/releases/tag/1.0.1
[1.0.0]: https://github.com/hlds-run/addon-resource-publish-date/releases/tag/1.0.0