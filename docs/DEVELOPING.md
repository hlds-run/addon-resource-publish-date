# Development conventions

Everything in this file exists so that the next person - including future us -
can change the add-on without guessing.

---

## Repository layout

```
.
├── README.md                     entry point, quick start
├── CHANGELOG.md                  every released version, newest first
├── CONTRIBUTING.md               short; points here
├── LICENSE.md
├── docs/                         the documentation set (see README table)
├── .github/workflows/ci.yml     check on push, release archive on tag
├── tools/
│   ├── check.php                 the checks XenForo does not do
│   ├── class_check.php           loads every class against stubbed parents
│   ├── build.php                 release ZIP + hashes.json
│   └── release_notes.php         the changelog section, as release notes
└── upload/                       mirrors the forum's own src/ layout
    └── src/addons/HldsRun/ResourcePublishDate/
        ├── addon.json
        ├── Setup.php
        ├── PublishDateAddOn.php              constants, paths
        ├── Service/
        │   ├── BumpResult.php                outcome object
        │   ├── PublishDateManager.php        all decisions and all writes
        │   └── TranslationInstaller.php      imports _translations/*.xml
        ├── Option/ExcludedCategories.php     dynamic option rendering
        ├── XFRM/Service/ResourceItem/Approve.php
        ├── XF/Service/Thread/ApproverService.php
        ├── Cli/Command/
        │   ├── ImportTranslation.php
        │   └── BackfillPublishDates.php
        ├── _data/                            addon.json-declared data
        └── _translations/                    per-language phrase files
```

`upload/` is the deployable unit. `docs/`, `tools/`, `README.md` and the rest are
repository-only and are **not** copied to the forum. That mirrors the XenForo
convention used by the official `xenforo.com` add-on repositories, and it keeps
the forum directory free of documentation nobody serves.

## Two-layer architecture, and why

The XenForo-facing surface is exactly two method overrides:

```php
XFRM\Service\ResourceItem\Approve::onApprove()
XF\Service\Thread\ApproverService::onApprove()
```

Everything else lives in `Service\PublishDateManager`. Rules:

- **Class extensions must stay trivial.** Their only job is to call the parent and
  hand over. Logic in a class extension is logic we have to re-verify on every
  XenForo upgrade, and there is no unit-test harness for class extensions.
- **Never edit a vendor file.** The add-on registers through
  `_data/class_extensions.xml`; vendor files stay untouched, so File Check stays
  clean and the forum's own source tree can be redeployed or rebuilt at any time.
- **Never call a `protected` method of the extended class** from elsewhere. That
  is precisely what `parent::onApprove()` is for.

## Why `onApprove()` and not `approve()`

`onApprove()` is only reached after a successful state change, so the semantics
cannot drift. `approve()` would let us fold the date write into XFRM's single
`save()`, but it would also mean copying XFRM's internal
`if ($this->resource->resource_state == 'moderated')` guard into our code - and
that guard is the kind of thing XenForo is free to refactor. The cost of our
choice is one extra `UPDATE` per approved resource, which is nothing next to the
notification emails XFRM already queues.

## Code style

Match XenForo and XFRM 2.3, not PHP-modern preferences. Concretely:

| Rule | Example |
|---|---|
| No constructor property promotion | `private $status;` + assignment in the constructor |
| No arrow functions | `static function ($id) { return $id > 0; }` |
| No `readonly`, no enums | `final class` + `public const` strings |
| 4 spaces, braces on their own line for classes and methods | see any file |
| One class per file, file named after the class | |
| Docblocks on anything non-obvious, explaining *why*, not restating the signature | |
| Static imports (`use function ...`) only when the vendor does | XFRM does, e.g. `use function intval;` |
| Typed properties only where the vendor is already typed | XFRM 2.3 mostly is; keep it consistent inside a class |

Why: this code lives inside a vendor codebase and is read next to it. A file that
looks foreign is a file nobody maintains during a XenForo upgrade.

The rules split into two groups, and only one of them is machine-checked:

* **Version-sensitive.** Property promotion, `readonly`, enums, `match`, nullsafe
  operator, union types, typed constants and `#\Override]` are all newer than
  7.4, so `php -l` on PHP 7.4 rejects them. That is why the 7.4 job exists, and
  why one version is enough - verified by running each construct through it.
* **Style only.** Arrow functions are valid PHP 7.4, so no version of `php -l`
  will ever reject them. Neither would the five-version matrix this replaced. They
  are banned because XenForo 2.3's own code does not use them, and that is a
  review rule, not something a lint can enforce. Do not expect CI to catch one.

## Naming

| Thing | Convention | Example |
|---|---|---|
| Add-on id | `Vendor/Name` | `HldsRun/ResourcePublishDate` |
| Namespace | mirrors the path under `src/addons/` | `HldsRun\ResourcePublishDate\Service` |
| Option ids | `hldsRunRpd` + CamelCase | `hldsRunRpdMinimumModerationMinutes` |
| Phrase titles | XenForo's own scheme | `option.<option_id>`, `option_explain.<option_id>`, `option_group.<group>`, `mod_log.<type>_<action>` |
| Our phrase prefix | `hlds_run_rpd_` | `hlds_run_rpd_reason_no_first_post` |
| Translation files | language code, lowercase | `ru.xml` |

Option ids are prefixed because `xf_option` is one flat table shared with core and
every other add-on. `tools/check.php` fails the build on an unprefixed one.

## Adding a feature

1. Decide whether it belongs in the manager or in a class extension. Rule of thumb:
   if it is a decision or a write, it belongs in `Service\PublishDateManager`.
2. Add a `BumpResult` status if you need a new "why not" state, and add the
   matching `hlds_run_rpd_reason_*` phrase to `_data/phrases.xml` **and** every
   translation. The validator enforces the latter.
3. If you add an option: add it to `_data/options.xml`, add `option.<id>` and
   `option_explain.<id>` phrases, and read it through a manager accessor, never
   `$options()->foo` inline.
4. If you add a CLI option: give it a default that cannot destroy data, and make
   sure `--dry-run` covers it.
5. Bump `version_id` / `version_string` in `addon.json`, update `CHANGELOG.md`.
6. `php tools/check.php && php tools/build.php`, and commit the regenerated
   `hashes.json`.
7. Test on the test forum per [TESTING.md](TESTING.md).

## Adding a database change

Version 1.x deliberately has no schema. If you need one:

1. Add `StepRunnerInstallTrait`, `StepRunnerUpgradeTrait` and
   `StepRunnerUninstallTrait` to `Setup\Setup`, following
   `XF\AddOn\AbstractSetup`. Write only the **deltas** in `upgradeX()` - never
   repeat `installX()`.

   `AbstractSetup` declares install/upgrade/uninstall as abstract, so all three
   traits are required even with no schema step at all - omitting them is a fatal
   error when the data-rebuild job loads the class, not at install time.

   `Setup` is also where `postInstall()` lives, which is not about the schema: it
   is the only install-time hook an add-on has, and this one imports translations.
   Note that `AbstractSetup` inherits `InstallHelperTrait`, which has no
   `service()` helper - use `\XF::service()`.
2. Bump `version_id`.
3. Record the step in `CHANGELOG.md` and in `docs/UPGRADE.md`, including what
   happens to the data (kept, dropped, backfilled).
4. Implement `uninstallX()` that reverses it. An add-on that leaves foreign objects
   behind after uninstall is a support ticket waiting to happen.
5. Never modify an existing column's meaning. Adding a column to `xf_rm_resource`
   is acceptable; repurposing `resource_date` is not (see
   [BEHAVIOR.md](BEHAVIOR.md) §9).

## Versions, tags and file names

**The rule is one sentence: every name for a release is the same string, with no
`v` anywhere.**

| Thing | Value |
|---|---|
| `version_string` in `addon.json` | `1.0.1` |
| git tag | `1.0.1` |
| GitHub release title | `1.0.1` |
| release URL | `.../releases/tag/1.0.1` |
| release archive | `HldsRun-ResourcePublishDate-1.0.1.zip` |

Two consequences of that rule, both of which have to be held:

**Do not put `v` in `version_string`.** The archive name is not a choice
available to this project. `XF\AddOn\AddOn::getReleasePath()` builds it as:

```php
return $this->releasesDir . \XF::$DS . "$addOnId-$versionString.zip";
```

A `v` there would also render in the Admin CP add-on list as "v1.0.1", which is
not a version anyone wants to read.

**The tag is `1.0.1`, not `v1.0.1`.** The `v` prefix is a common semver
convention and XenForo add-ons on GitHub use both: `btcpayserver/xenforo` tags
`v2.0.3` and releases `BS-BtcPayProvider-2.0.3.zip`, while
`CleanTalk/xenforo-antispam` tags `2.6` and releases
`xenforo-antispam-2.6.zip`. Both work. This project takes the second because a
release is referred to by three different names - tag, title, file - and having
the prefix on one or two of them while the archive lacks it reads as drift even
when it is not.

CI therefore triggers on tags matching `[0-9]*`. A `v`-prefixed tag will not
start a build, which is deliberate: a release is cut by tagging, and tagging
wrongly should fail loudly rather than produce a second, differently-named
release.

`tools/build.php` therefore derives the name through the same two steps XenForo
does - `prepareAddOnIdForFilename()` and `prepareVersionForFilename()`, the latter
stripping anything outside `[a-z0-9-_. ]` - so the two build paths cannot drift
apart. Verified against XenForo's implementation, including versions with
characters the sanitiser removes.

## Building a release

For the full procedure - including how to ship a bug fix and what each check
catches - see [RELEASING.md](RELEASING.md). This section is the mechanical part.

```bash
# 1. bump the version (also updates version_id/version_string on every phrase)
php cmd.php xf-addon:bump-version HldsRun/ResourcePublishDate

# 2. validate
php tools/check.php

# 3. build
php tools/build.php
```

Note the command names. XF 2.3.2 mixes two spellings and both are real:
`xf:addon-install`, `xf:addon-uninstall`, `xf:addon-upgrade` and
`xf:addon-rebuild` use a hyphen, while `xf-addon:build-release`,
`xf-addon:bump-version` and `xf-addon:export` use a colon. Do not normalise them
by eye - `php cmd.php list | grep addon` prints the truth.

Where a XenForo installation is available, its own builder is available:

```bash
php cmd.php xf-addon:build-release HldsRun/ResourcePublishDate
```

It runs the real exporter, so anything the add-on ships through `_data/` is
exported by XenForo rather than by our reading of the same rules - and it edits
the add-on to do it. `xf-addon:export` rewrites `_data/*.xml` from the database,
and the JSON validator "repairs" `addon.json` by adding `legacy_addon_id` and
`icon` and reformatting the `require` block; both were done to a file that was
already correct. Measured on XenForo 2.3.7, its archive holds 57 files where
`tools/build.php` holds 16 - about forty of them empty `_data` stubs for data
types this add-on does not use - and it is written to
`src/addons/HldsRun/ResourcePublishDate/_releases` rather than to the
installation's `_releases`.

`tools/build.php` is therefore the builder, not a reproduction of this one: it
exists for when there is no XenForo to hand, such as in CI or on a laptop, and
it does not touch a single tracked file except `hashes.json`. It needs `ext-zip`;
the XenForo command needs it too.

Both write `hashes.json` into the add-on directory. **Commit it.** XenForo uses
it for the file integrity check and for update detection, so an out-of-date copy
makes File Check report every changed file as inconsistent.

`_build/`, `_output/` and `_releases/` are build artefacts and are ignored.

Then tag and push:

```bash
git tag -a 1.0.1 -m "Resource publish date 1.0.1"
git push origin 1.0.1
```

The tag starts the `release` job, which publishes the already-verified archive
against a GitHub release. See [above](#versions-tags-and-file-names) for the
naming rule.

The release body is the `## [<version>]` section of `CHANGELOG.md`, extracted by
`tools/release_notes.php`. It is deliberately **not** generated from the commit
log: generated notes are a list of commits, which describes what changed in the
repository rather than what somebody installing needs to know, and cannot be read
before it ships. Because the notes come from the changelog, the two cannot
disagree - and the extractor fails the release rather than publishing an empty
body if the section is missing.

So: add the changelog section before tagging. The tag will not do it for you.

## Checks and CI

```bash
php tools/check.php
php tools/class_check.php
php tools/build.php
```

`check.php` covers only what XenForo does not already do for itself. `php -l`,
XML well-formedness and `addon.json` validity are left to CI.

**A prose-only push does not run CI.** The workflow is filtered to three paths -
`upload/**`, `tools/**` and `.github/workflows/**` - which are exactly the three
things any job in it reads. A commit that touches only README, `docs/`,
`CHANGELOG.md`, the issue templates or the licence cannot fail a build, so it
costs nothing to skip one. Most commits here are documentation, so this is the
difference between CI being a signal and CI being noise.

Two deliberate exceptions, both of which are the wrong thing to "optimise":

- **Pull requests are not filtered.** GitHub's guidance is not to path-filter a
  workflow that must pass before merging: a pull request whose files are all
  filtered out produces no check result at all, and a required check that never
  reports blocks the merge permanently with nothing to click.
- **Tag pushes are never filtered**, because GitHub does not evaluate path
  filters for tags at all. That is the behaviour to rely on, not a gap to work
  around: a tag is an explicit statement that the commit is being released, and
  the last few commits before it are usually documentation.

CI lints on two PHP versions, 7.4 and the runner's own. One is enough to enforce
the version-sensitive half of the style rules, because PHP syntax features are
additive: anything newer than 7.4 fails to parse on 7.4. Confirmed by running
twelve post-7.4 constructs through the 7.4 job - property promotion, `match`,
nullsafe, union types, DNF types, `readonly`, enums, first-class callables, typed
constants, `readonly class` and `#\Override]` were all rejected, and arrow
functions were not, because they are 7.4 syntax. The newest version is checked
too, for the opposite direction: syntax deprecated or removed in current PHP.

`class_check.php` is a separate check because `php -l` cannot do this job. `php -l`
parses a file and says nothing about inheritance, so a class that extends an
abstract parent without implementing its abstract methods is syntactically perfect
and dies at runtime. This add-on shipped that bug once: `Setup.php` extended
`XF\AddOn\AbstractSetup` without the three step-runner traits, and the fatal
surfaced on a forum from the data-rebuild job, after an install that had already
reported success. `class_check.php` loads every add-on class against stubbed
vendor parents, so PHP performs the same check it would perform there.

It also resolves every `$this->method()` call against the class and its parents.
That catches a call to a helper the vendor parent does not have - `$this->app()`
on `AbstractService`, which has a protected `$app` property and no `app()` method.
Such a call parses fine and fails only when that line runs, which for a service
called from an approval handler means it fails when a moderator approves
something.

The stubs mirror the abstract surface of XenForo 2.3.2 and must be revisited when
XenForo is upgraded - `docs/UPGRADE.md` lists them.

What `check.php` does catch is the class of mistake that installs cleanly and
then does nothing:

- a class extension pointing at a `to_class` with no file;
- a namespace that does not match its path;
- a phrase used from PHP that no `_data/phrases.xml` entry defines;
- a `BumpResult` constant with no matching `hlds_run_rpd_reason_*` phrase;
- a translation that drifted out of sync with the master list, in either
  direction;
- an option referenced in PHP but not defined in `options.xml`, or defined
  without its title phrases.

`.github/workflows/ci.yml` runs the syntax matrix and `check.php` on every push,
rebuilds `hashes.json` and fails if it differs from the committed copy, and
publishes the release ZIP as a build artifact. On a version tag it additionally
attaches the archive to a draft GitHub release, so releasing is: tag, review the
draft, publish.

There is deliberately no test suite. See [TESTING.md](TESTING.md) for why, and
for the manual checklist that stands in its place.

## Commit messages

Conventional Commits, in English, with an imperative subject:

```
fix(xfrm): do not shift the thread date when it already has replies
docs(behaviour): explain the production_queue mechanism
feat(cli): add --threads flag to backfill
```

The subject line stays under 72 characters. Explain *why* in the body, in
paragraphs of at most 72 characters, and wrap code at colons, not mid-token.

## Verifying assumptions against vendor code

Several behaviours this add-on depends on are not part of XenForo's public API
contract, and are listed with the file to read in [UPGRADE.md](UPGRADE.md). When
one of them looks wrong, read it in the XenForo source rather than guessing - a
XenForo install, or the release archive, is all you need:

```bash
unzip -q xf-2.3.x-release.zip -d /tmp/xf
grep -rn "function onApprove" /tmp/xf/upload/src/XFRM/Service/ResourceItem/Approve.php
```
