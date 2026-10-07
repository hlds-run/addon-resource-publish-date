# Translations

The add-on is multilingual in the XenForo sense: every user-facing string is a
phrase, and translations are ordinary `xf_phrase` rows in a specific language.

## How XenForo stores phrases

| Concept | Meaning |
|---|---|
| Master phrase | `xf_phrase` row with `language_id = 0`. The add-on's `_data/phrases.xml` becomes these rows on install / data rebuild. Always English in this add-on. |
| Translation | `xf_phrase` row with a real `language_id`, same `title`. Takes precedence for users on that language. |
| `title` | The stable key. Never translate it, never reuse it. |

Master phrases are installed and updated automatically by XenForo (Add-ons →
rebuild add-on data, or the one-click upgrade). Translations are **not**: XenForo
has no mechanism to ship them from `_data/`, so the add-on ships them in
`_translations/` and installs them with a CLI command.

## Files

| File | Role |
|---|---|
| `upload/src/addons/HldsRun/ResourcePublishDate/_data/phrases.xml` | Master phrases, English. The single source of truth for the set of keys. |
| `upload/src/addons/HldsRun/ResourcePublishDate/_translations/ru.xml` | Russian translation. |

A translation file is an XML document whose root is `<phrases>` and whose children
are `<phrase>` elements with **all** of these attributes, because
`XF\Service\Phrase\ImportService::importFromXml()` reads them off the node itself:

```xml
<phrase title="option.hldsRunRpdEnabled"
        addon_id="HldsRun/ResourcePublishDate"
        version_id="1000010"
        version_string="1.0.0"><![CDATA[Shift publish dates when content is approved]]></phrase>
```

A real entry from `_translations/ru.xml` differs only in the text node, which is
of course in the target language. This is the same format XenForo's own language
packs use, so a core or third-party language file makes a usable reference:
unzip any `language-*-XF.xml` and compare.

## How a file is matched to a language

`ru.xml` is matched to the board language whose `language_code` is `ru`, or whose
code begins with `ru-`. XenForo's own language packs use the suffixed form - the
Russian pack ships `ru-RU`, not `ru` - so an equality test against the file name
would match nothing at all and still report success. `pt.xml` matches `pt-BR`,
`de.xml` matches `de-DE`, and a file name is never a substring match: `ru.xml` does
not match `run`.

If a board carries both `ru` and `ru-RU`, the exact match wins.

## Automatic installation

Since 1.1.0 every shipped translation is imported into each board language it
matches, as part of installing the add-on. `Setup::postInstall()` calls
`TranslationInstaller::installForExistingLanguages()`.

Two deliberate limits:

* **Languages must already exist.** A translation cannot create one, and matching
  `ru.xml` against a board with no Russian row must do nothing rather than invent
  a language. That board gets English.
* **Install only, never on upgrade.** An upgrade re-import would overwrite phrases
  an administrator has customised in Admin CP -> Phrases, with no way to recover the
  old wording. Use `hlds-run-rpd:import-translation` to update deliberately.

A translation cannot fail an install: each language's lookup and import run inside
one `try`, the exception is caught, and the remaining languages are still imported.
That was true from 1.1.1; 1.1.0's notes claimed it and the code did not do it.

## How to tell whether it worked

Look at **Admin CP -> Phrases** for the language. The values there are the proof.

Do not rely on the log line. `postInstall()` writes what it imported with
`XF::logError()`, because XenForo has no info-level log and that is the only entry
point besides `logException()`, but the line only lands when the install ran in a
**web request** - which is what installing through the Admin CP does. Install from
`cmd.php` and XenForo discards it: `XF\Error::logException()` builds the request
state before it inserts, that throws where there is no request, and the `catch`
around it is empty. Verified on XF 2.3.7 - the identical `logError()` call writes
from the Admin CP and writes nothing from the CLI, with and without `$forceLog`.

The consequence worth remembering: **an absent log line proves nothing**, in
either direction. Check the phrases.

## Installing a translation by hand

```bash
# 1. what languages exist, and which shipped file matches each
php src/cmd.php hlds-run-rpd:import-translation

# 2. dry run: how many phrases, which language
php src/cmd.php hlds-run-rpd:import-translation 6 --file=ru --dry-run

# 3. apply
php src/cmd.php hlds-run-rpd:import-translation 6 --file=ru
```

The **Shipped translation** column in step 1 is `none` when the board's language
code matches nothing we ship - which is the usual explanation for a board that
stayed English.

The command delegates to `XF\Service\Phrase\ImportService`, the same service the
core language-pack importer uses. Consequences of that choice, in our favour:

- phrases are matched by title and updated, not duplicated;
- the phrase options (`recompile`, `check_duplicate`, …) are set the way XenForo
  expects;
- `languageRebuild` and `TemplateRebuild` jobs are enqueued, so the phrase map and
  compiled templates are refreshed;
- re-importing **replaces** all of this add-on's phrases for that language and
  leaves every other add-on's phrases untouched (the delete is scoped by
  `language_id` **and** `addon_id`).

Find the language id from step 1. Do not hard-code it: ids differ per board.

## Adding a language

1. Copy `_translations/ru.xml` to `_translations/<code>.xml`, e.g. `de.xml`.
2. Translate the text. **Keep every `title` and every `addon_id` byte-identical.**
3. Leave `version_id` / `version_string` as they are; they record which add-on
   version the text was written for, not the language.
4. Validate and commit:

```bash
php tools/check.php
```

`check.php` fails the build if a translation has missing keys, extra keys, an
empty value, or a wrong `addon_id`.

## Adding a new phrase

1. Add it to `_data/phrases.xml` (English) with the current `version_id` and
   `version_string`.
2. Bump `version_id` / `version_string` in `addon.json`.
3. Add the same `title` to **every** file in `_translations/`.
4. Add an entry to `CHANGELOG.md`.
5. `php tools/check.php`

Steps 1 and 3 together are enforced by the validator, which compares the key sets.
On the forum, run `php src/cmd.php xf:addon-upgrade HldsRun/ResourcePublishDate`
(or rebuild add-on data) so the master phrase row appears, then re-import each
translation.

## Editing phrases on the forum

Every phrase here is a normal XenForo phrase, editable in
**Admin CP → Phrases**, including the two moderator-log action phrases. Editing
them on the forum creates a "custom phrase" that XenForo will not overwrite on
upgrade - useful for wording tweaks, bad for structural changes. Prefer a release
of this repository for anything lasting.

## Word choice

Russian wording is not a literal translation of English; a few decisions worth
keeping in mind when editing:

- The RM's own Russian phrasing is used where it exists: *Обновлено* for
  `last_update`, *Первый выпуск* for `First release`.
- `Одобрение` is used for approval throughout, matching the moderation queue UI.
- The `hlds_run_rpd_reason_*` phrases are short state descriptions used in CLI
  output; they read as answers to "why did nothing happen?", so they start with the
  cause rather than with the add-on's name.
