# Contributing

Short version: read [docs/DEVELOPING.md](docs/DEVELOPING.md) first. It carries the
repository layout, the code style rules, the two-layer architecture rule and the
release procedure.

The short short version:

1. `php tools/check.php` must pass before you push. CI runs it on every PR.
2. Logic goes in `Service/PublishDateManager.php`. Class extensions stay trivial.
3. New user-facing strings are phrases: add to `_data/phrases.xml` **and** every
   file in `_translations/`, or `check.php` fails.
4. New options are prefixed `hldsRunRpd` and read through a manager accessor.
5. Run `php tools/build.php` and commit the regenerated `hashes.json`.
6. Add a `CHANGELOG.md` entry under **Unreleased**, and bump `version_id` only
   when cutting a release.
7. Update the relevant file in `docs/`. The documentation here is unusually
   load-bearing: most support questions are answered by it rather than by the
   author, and a stale page produces a bug report that should not have been filed.
8. Test on a staging forum following [docs/TESTING.md](docs/TESTING.md) before
   anything is considered done. Production is never the first place.

Commit messages use Conventional Commits, in English:

```
fix(xfrm): do not shift the thread date when it already has replies
feat(cli): add --threads flag to backfill
docs(behaviour): explain where last_update comes from
```

## Reporting a bug

Open an issue using the **Bug report** template and fill in the fields. The
template asks for settings and database state because "the date did not move"
has several causes that look identical from the outside, and
[docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) tells them apart with SQL.
Pasting that SQL output in the first report usually resolves it immediately.

**"How do I configure this?" is not a bug.** Use the Configuration help
template.

## Adding a translation

The add-on ships English as the master phrase list and Russian as a translation.
Other languages are welcome, and are the most straightforward contribution there
is - see [docs/TRANSLATIONS.md](docs/TRANSLATIONS.md). Keep every `title` and
`addon_id` byte-identical; `check.php` compares the key sets in both directions.

## Compatibility, honestly

The add-on is tested against XenForo 2.3.2 and XFRM 2.3.2. It also works on
earlier and later 2.3.x point releases, but those are not tested. Newer XenForo
versions are unverified until someone checks
[docs/UPGRADE.md](docs/UPGRADE.md) and reports back - that checklist exists
because an upgrade can break this add-on silently, so an unverified report is
genuinely useful rather than noise.
