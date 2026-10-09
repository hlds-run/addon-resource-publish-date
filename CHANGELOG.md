# Changelog

All notable changes to this add-on are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
the project uses [semantic versioning](https://semver.org/).

`version_id` in `addon.json` is `version_string` encoded, not a counter, and it
must be recomputed for every release - the CLI command
`php cmd.php xf-addon:bump-version HldsRun/ResourcePublishDate --version-id XXXXXXX --version-string X.X.X`
does it for you, including updating the `version_id` / `version_string`
attributes on every phrase. See [docs/VERSIONING.md](docs/VERSIONING.md) for the
mask, and [docs/DEVELOPING.md](docs/DEVELOPING.md) for the naming rules - the git
tag, the release title and `version_string` are the same string, with no `v`
prefix anywhere.

## [1.0.0] - 2026-10-08

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
- **To switch the behaviour off, disable the add-on on Admin CP → Add-ons.**
  That unloads its class extensions, so the approval path is never entered and
  the board behaves exactly like stock XenForo - immediately, with no deploy and
  no data rebuild. There is deliberately no second switch in the options: two
  controls for one state is one more thing to explain when a date did or did not
  move.

Every decision the manager makes is a named `BumpResult` constant with a phrase
behind it - `bumped`, `disabled_for_type`, `category_excluded`,
`below_minimum_age`, `not_first_approval`, `no_visible_update`,
`thread_not_a_resource`, `thread_has_replies`, `no_first_post`,
`already_current` - so the moderator log, the CLI output and this document share
one vocabulary.

### Options

Grouped under **Options → Resource publish date**.

| Option | Default | Purpose |
|---|---|---|
| When to shift dates | only the first approval | alternative: every approval |
| Resources | on | shift `xf_rm_resource.last_update` |
| Resource discussion threads | on | shift thread and first-post dates |
| Minimum time in the moderation queue | 0 minutes | skip the shift below this threshold |
| Excluded resource categories | none | categories that keep their original dates |
| Record date shifts in the moderator log | on | audit trail next to the approval entry |

The three advanced rows are advanced because a board that wants them off has no
reason to look at them.

The category exclusion is a compact multiple select built from the live XFRM
category tree - the same component core uses for the "limit to nodes" field on
its widgets - with *All categories* preselected at the top and nesting shown with
non-breaking spaces rather than the run of hyphens the RM bakes into its labels.
It is rendered through `edit_format="callback"` with a validation class, the
mechanism core's sitemap exclusions use, so the add-on ships **no templates at
all** and there is nothing to rebuild when XFRM changes. Selecting a category that
no longer exists is discarded when the options are saved.

### CLI

- `hlds-run-rpd:import-translation [<language_id>] [--file=<code>] [--dry-run]`

  XenForo cannot ship translations out of `_data/`, so the ones in
  `_translations/` are inert XML unless something reads them. With no argument the
  command lists the board's languages next to the shipped translation that
  matches each, which is the fastest way to answer "why was nothing imported".
  Passing a language id imports that language through
  `XF\Service\Phrase\ImportService`, the same service core's language-pack
  importer uses: phrases are matched by title and updated rather than duplicated,
  and the delete-then-import is scoped by both `language_id` and `addon_id`, so a
  re-import touches only this add-on.

- `hlds-run-rpd:backfill [--dry-run] [--days=N] [--limit=N] [--threads] [--log]`

  Re-dates resources approved before the add-on was installed, using approval
  timestamps from the moderator log. `--dry-run` is the intended first use.
  `--threads` extends the same pass to discussion threads. `--log` is off by
  default because moderator log entries need an HTTP request to resolve the
  moderator's IP, and a long run would otherwise stall on it.

### Translations

Master phrases are English (`_data/phrases.xml`, 44 phrases). Russian ships in
`_translations/ru.xml`.

**Shipped translations are imported automatically when the add-on is
installed.** Each shipped file is matched against the board's existing languages
and imported into every one that matches, so installing on a Russian board leaves
it fully Russian rather than English until somebody reads the manual. Matching is
on the language code and its region suffix, because XenForo's own language packs
use the suffixed form - the Russian pack ships `ru-RU`, not `ru`. Only languages
that already exist are touched: a translation cannot create one.

Two deliberate limits. A translation is imported on **install only, never on
upgrade** - re-importing on upgrade would overwrite wording an administrator has
customised in Admin CP → Phrases with no way to recover it, and the CLI command
exists for those deliberate updates. And the import is never worth failing an
install over, so per-language errors are logged and the remaining languages are
still imported.

`tools/check.php` fails if the two key sets ever drift apart in either direction.

### Notes for reading the code

- The XenForo-facing surface is exactly two `protected onApprove()` overrides.
  All logic lives in `Service\PublishDateManager`, which returns a `BumpResult`
  explaining not only what happened but why.
- `onApprove()` rather than `approve()`, because it is only reached after the
  state change has succeeded. The cost is one extra `UPDATE` per approved
  resource, next to the notification emails XFRM already queues.
- There is no `Setup` schema step and there never needs to be one.
- **CI lints every file against PHP 7.4 and against the runner's current PHP.**
  One old version is enough to catch PHP 8-only syntax, because PHP syntax
  features are additive - anything newer than the floor fails to parse there.
- **CI also loads every add-on class against stubbed vendor parents**
  (`tools/class_check.php`) and resolves every method call against them.
  `php -l` cannot see a call to a method that does not exist, nor a class that
  fails to implement its parent's abstract methods - XenForo checks the latter
  only when the data-rebuild job loads the class, which is late enough to be
  reported as a successful install. XenForo itself ships no harness for class
  extensions, so a real install on a real board remains the last check that
  matters; see [docs/TESTING.md](docs/TESTING.md).

[1.0.0]: https://github.com/hlds-run/addon-resource-publish-date/releases/tag/1.0.0