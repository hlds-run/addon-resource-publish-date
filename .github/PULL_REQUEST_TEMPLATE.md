## What this changes

<!-- One or two sentences. If it fixes an issue, "Fixes #123". -->

## Why

<!--
The reasoning, not the diff. What breaks without it, or what is confusing.
Saying what the alternatives were is usually the most useful part.
-->

## How it was verified

<!--
Be specific, because this is what gets asked. For a behaviour change, the
checklist item from docs/TESTING.md that covers it, and what you observed.
"php tools/check.php passes" is necessary and not sufficient - say what else.
-->

- [ ] `php tools/check.php` passes
- [ ] CI is green
- [ ] Tested on a staging XenForo 2.3 forum (which check from docs/TESTING.md: <!-- ... -->)

## Things a reviewer will check

- [ ] **Option and phrase ids are prefixed.** New options start with `hldsRunRpd`, new phrases with `hlds_run_rpd_`. `xf_option` and `xf_phrase` are flat tables shared with every other add-on.
- [ ] **A new phrase exists in `_data/phrases.xml` *and* every file in `_translations/`.** `tools/check.php` fails if the key sets drift apart.
- [ ] **No vendor files were edited.** Everything goes through `_data/`, never through a modified copy of XenForo or XFRM.
- [ ] **`hashes.json` is regenerated and committed.** `php tools/build.php`, then commit the result. CI fails if it is stale.
- [ ] **Logic is not in a class extension.** Class extensions call the parent and delegate. Anything that has to be re-verified against a XenForo upgrade belongs in `Service\PublishDateManager`.
- [ ] **No PHP 8-only syntax.** XenForo 2.3 runs on PHP 7.4, and CI lints every file against it, so this is enforced rather than requested. Note that arrow functions are *not* covered - they are valid 7.4 and are banned here only for consistency with vendor code.
- [ ] **A behaviour change is reflected in the docs.** The relevant file in `docs/`, and an entry in `CHANGELOG.md` under Unreleased.

## Notes for the reviewer

<!-- Anything that deserves discussion before merge, stated plainly. -->