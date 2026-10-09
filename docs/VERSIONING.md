# Add-on versioning

How `version_id`, `version_string`, git tags, archive names and the `Setup.php`
step methods are related, and how to compute a valid `version_id` when cutting a
release.

This document exists because getting `version_id` wrong is silent. A hand-maintained
counter looks fine everywhere - XenForo only validates that `version_id` is an
integer, so `xf-addon:validate-json` accepts any number you give it - while
leaving the add-on's real purpose unmet: XenForo compares this value to decide
whether an add-on is out of date, and appends it to template cache-busters, which
is what stops browsers serving the previous release's JS/CSS. An arbitrary number
that only increases gives you half of that and none of the meaning.

---

## Current values

The current release is **1.0.0**.

| Field | Value | Derived from |
|---|---|---|
| `version_id` in `addon.json` | `1000070` | `1.0.0` Stable under the `aabbccde` mask |
| `version_string` in `addon.json` | `1.0.0` | — |
| `require.XF` | `2030070` | XenForo 2.3.0 Stable |
| `require.XFRM` | `2030070` | XenForo Resource Manager 2.3.0 Stable |
| Phrase `version_id` (44 phrases) | `1000070` | every phrase shipped in 1.0.0 |

Every phrase carries `1000070`, because `1.0.0` is where every phrase's text was
last changed. That is the point of the per-phrase number: a phrase is re-imported
over an administrator's wording only when its own `version_id` rises, so a phrase
whose text did not change must not be bumped. Raise it only when the text changes,
and to the `version_id` of the release that changed it.

There is no state word in `version_string`: the release is stable, so the state
defaults to `Stable` and the digit `7`. Had the version been named `1.0.0 Beta 2`,
the state digit would be `3` and the state version `2`.

---

## The `aabbccde` mask

XenForo's own scheme, from
[Add-on structure](https://docs.xenforo.com/devs/add-on-structure):

```
version_id = major * 1000000
           + minor *  10000
           + patch *    100
           + state *      10
           + stateVersion
```

| Field | Range | Meaning |
|---|---|---|
| `a` | 1-9 | major version: `1` for this add-on, `2` for XF 2.x |
| `bb` | 00-99 | minor version |
| `cc` | 00-99 | patch version |
| `d` | 1, 3, 5, 7 | state: 1 Alpha, 3 Beta, 5 RC, 7 Stable |
| `e` | 0-9 | state version |

The digit widths are uneven: the major takes **one** digit, minor and patch
**two** each. The result is therefore **seven** digits for a single-digit major,
not eight. The widely repeated framing of this as a strict eight digit number is
a misreading of the mask - with `aa` given two digits the value no longer decodes
back into the same version.

| Version | `version_id` |
|---|---|
| 1.0.0 Stable (this add-on, current) | `1000070` |
| 1.2.3 Stable (this add-on) | `1020370` |

All four examples in XenForo's documentation - `1.7.3 RC 4`, `1.5.0 Beta 3`,
`2.0.0` and `2.2.0` - were verified against the formula above and every one
matches.

### Computing it by hand

1. Take `version_string` without the state word, e.g. `1.2.1`.
2. Substitute: `1` → `1000000`, `2` → `20000`, `1` → `100`, Stable → `70`, state
   version `0` → `0`.
3. Add them up: `1000000 + 20000 + 100 + 70 + 0` = `1020170`.

Or skip the arithmetic and let XenForo do it: the command below infers
`version_string` from `version_id` when only the identifier is passed.

---

## Cutting a release

```bash
# 1. compute version_id (see above), then
php cmd.php xf-addon:bump-version HldsRun/ResourcePublishDate \
    --version-id 1001070 --version-string 1.1.0

# 2. check the mask, version_string agreement, require and phrases
php tools/check.php

# 3. regenerate hashes.json and the archive
php tools/build.php
```

`xf-addon:bump-version` updates `addon.json` and the `version_id` /
`version_string` attributes on every phrase. Pass the flags explicitly: without
`--version-id` XenForo reads the current value back out of `addon.json` and fixes
nothing, which is the exact path the wrong numbers accumulated through here.

There is no XenForo in CI, so the two values are written into `addon.json` by
hand and `php tools/check.php` verifies the result.

### What `tools/check.php` actually checks

Four checks, each catching a mistake that really happened here:

- the `version_id` mask, so a plain counter cannot pass;
- `version_id` against `version_string`, so neither a counter nor a single
  mistyped digit can pass;
- the state digit of a `require` floor, which has to be `7` because a dependency
  names a stable release. This is the check that catches `2030010` specifically:
  its state digit is `1`, meaning "2.3.0 Alpha", a floor no XenForo install
  reports satisfying;
- every phrase `version_id`, which may not exceed the add-on's own.

---

## The rule for phrases

A phrase's `version_id` is the version in which **its text changed**. It does not
have to match the add-on's current `version_id`, and it moves only when the text
moves.

The practical consequence: all 44 phrases sit at `1000070`, because that is the
release they all shipped in. Raising a phrase's `version_id` makes XenForo
rewrite the stored text, which overwrites whatever an administrator customised
in Admin CP → Phrases.

The rule for a new phrase is the add-on's current `version_id`. The rule for a
changed one is the `version_id` of the release whose text changed.

---

## Step names in `Setup.php`

Migration steps are named `upgradeXXXXXStepY`, where `XXXXX` is the **target
`version_id`** with no separators and `Y` is the step's sequence number.

```php
public function upgrade1001070Step1()   // version_id 1001070 = 1.1.0 Stable
```

Not `version_string`, and not a counter. `StepRunnerUpgradeTrait` runs only the
steps whose number matches the version being upgraded **to**, so a method with
the wrong number is skipped silently - no error, no log line, the migration
simply does not happen.

This cannot be checked automatically: `tools/check.php` has no way to know which
id a step was meant for. The pairing is verified by hand at review.

There are no steps in `Setup.php` today. The add-on has no schema, so the class
is three step-runner traits plus `postInstall()`.

---

## Things not to do

- **Do not increment `version_id` by one.** That is the original mistake.
  Incrementing keeps the value monotonic but loses the link to `version_string`,
  and nothing detects the resulting disagreement.
- **Do not write a `require` floor from memory.** `2030010` looks plausible
  beside `2030070` and differs by a single digit. XF 2.3.0 Stable is `2030070`.
- **Do not bump a phrase's `version_id` when its text did not change.** It makes
  XenForo rewrite the phrase and overwrite the administrator's wording.
- **Do not rely on `xf-addon:validate-json`.** It checks the type, not the
  meaning.

---

## References

- [Add-on structure - XenForo](https://docs.xenforo.com/devs/add-on-structure) -
  the official description of the mask
- [Development tools - XenForo](https://docs.xenforo.com/devs/development-tools) -
  `xf-addon:bump-version`, `xf-addon:validate-json`
- [docs/DEVELOPING.md](DEVELOPING.md) - repository conventions
- [docs/RELEASING.md](RELEASING.md) - how to cut a release