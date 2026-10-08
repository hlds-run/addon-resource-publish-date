<?php

/**
 * Repository checks that XenForo does not perform on its own.
 *
 *   php tools/check.php
 *
 * Deliberately NOT checked here:
 *
 *   php -l                 CI runs this on every PHP file; a syntax error only
 *                          surfaces at runtime otherwise.
 *   XML well-formedness    XenForo parses _data/*.xml on install and fails loudly.
 *   addon.json validity    `php src/cmd.php xf-addon:validate-json` covers it.
 *
 * What is here are the mistakes that install cleanly and then do nothing:
 * a class extension pointing at a class that does not exist, a namespace that
 * does not match its path, a phrase referenced from PHP with no row behind it,
 * and a translation that has drifted out of sync with the master list.
 */

declare(strict_types=1);

/**
 * The add-on id, in both the path form XenForo uses on disk and the namespace
 * form PHP uses. Getting these confused is exactly the class of mistake this
 * file exists to catch, so both are derived from one constant.
 */
const ADDON_ID = 'HldsRun/ResourcePublishDate';
const ADDON_NAMESPACE = 'HldsRun\\ResourcePublishDate';

$repoRoot = dirname(__DIR__);
$addonRoot = $repoRoot . '/upload/src/addons/' . ADDON_ID;
$dataDir = $addonRoot . '/_data';
$translationsDir = $addonRoot . '/_translations';

$errors = [];
$notes = [];

function fail(string $message): void
{
	global $errors;
	$errors[] = $message;
}

function note(string $message): void
{
	global $notes;
	$notes[] = $message;
}

function readText(string $path): string
{
	$contents = file_get_contents($path);
	if ($contents === false) {
		fail('could not read ' . $path);
		return '';
	}
	return $contents;
}

/**
 * @return string[]
 */
function addonPhpFiles(string $addonRoot): array
{
	$found = [];
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($addonRoot, FilesystemIterator::SKIP_DOTS)
	);

	foreach ($iterator as $file) {
		if ($file->isFile() && $file->getExtension() === 'php') {
			$found[] = $file->getPathname();
		}
	}

	sort($found);
	return $found;
}

/**
 * The class a file is expected to declare: add-on id plus its path.
 */
function classForPath(string $path, string $addonRoot): string
{
	$relative = substr($path, strlen($addonRoot) + 1);
	$relative = substr($relative, 0, -strlen('.php'));

	return ADDON_NAMESPACE . '\\' . str_replace('/', '\\', $relative);
}

function namespaceForPath(string $path, string $addonRoot): string
{
	$relative = dirname(substr($path, strlen($addonRoot) + 1));
	if ($relative === '.' || $relative === '') {
		return ADDON_NAMESPACE;
	}
	return ADDON_NAMESPACE . '\\' . str_replace('/', '\\', $relative);
}

/**
 * Minimal reader for the flat XML XenForo data files use.
 *
 * SimpleXML and DOM are optional PHP extensions and are frequently absent from a
 * bare CLI PHP, which would make this tool unrunnable for the people most likely
 * to run it. These files are a single level of elements with attributes and
 * CDATA-only text, so a regex is genuinely sufficient here.
 *
 * It is NOT a general XML parser and must not be used as one. Use
 * XF\Service\Phrase\ImportService on the forum for that.
 *
 * @return array<int, array{attrs: array<string, string>, text: string}>
 */
function readFlatXml(string $path, string $label): array
{
	$contents = readText($path);

	if (!preg_match('#^\s*<\?xml[^>]*\?>#', $contents)) {
		fail($label . ': missing XML declaration');
	}

	// Strip comments and the surrounding root element.
	$body = preg_replace('#<!--.*?-->#s', '', $contents);
	$body = preg_replace('#^.*?<\w+[^>]*>(.*)</\w+>\s*$#s', '$1', $body);

	$elements = [];
	if (!preg_match_all(
		'#<(\w+)\b([^>]*?)(?:/>|>(.*?)</\1>)#s',
		$body,
		$matches,
		PREG_SET_ORDER
	)) {
		fail($label . ': no elements found');
		return [];
	}

	foreach ($matches as $match) {
		$attributes = [];
		if (preg_match_all('#(\w+)="([^"]*)"#', $match[2], $attributeMatches, PREG_SET_ORDER)) {
			foreach ($attributeMatches as $attribute) {
				$attributes[$attribute[1]] = $attribute[2];
			}
		}

		$text = $match[3] ?? '';
		if (preg_match('#<!\[CDATA\[(.*?)\]\]>#s', $text, $cdata)) {
			$text = $cdata[1];
		}

		$elements[] = ['name' => $match[1], 'attrs' => $attributes, 'text' => $text];
	}

	return $elements;
}

/**
 * @return array<string, string> title => text
 */
function parsePhrases(string $path, string $label, bool $requireAttributes): array
{
	$phrases = [];
	foreach (readFlatXml($path, $label) as $element) {
		if ($element['name'] !== 'phrase') {
			continue;
		}

		$title = $element['attrs']['title'] ?? '';
		if ($title === '') {
			fail($label . ': a <phrase> has no title');
			continue;
		}
		if (isset($phrases[$title])) {
			fail($label . ": duplicate phrase '$title'");
		}

		if ($requireAttributes) {
			foreach (['addon_id', 'version_id', 'version_string'] as $attribute) {
				if (($element['attrs'][$attribute] ?? '') === '') {
					fail("$label: phrase '$title' is missing $attribute");
				}
			}
			if (($element['attrs']['addon_id'] ?? '') !== ADDON_ID) {
				fail("$label: phrase '$title' has the wrong addon_id");
			}
			if (trim($element['text']) === '') {
				fail("$label: phrase '$title' is empty");
			}
		}

		$phrases[$title] = $element['text'];
	}

	return $phrases;
}

// ---------------------------------------------------------------- class extensions

function checkClassExtensions(string $dataDir, string $addonRoot, array $phpFiles): void
{
	$path = $dataDir . '/class_extensions.xml';
	if (!is_file($path)) {
		fail('_data/class_extensions.xml is missing');
		return;
	}

	$known = [];
	foreach ($phpFiles as $file) {
		$known[classForPath($file, $addonRoot)] = true;
	}

	$extensions = array_filter(
		readFlatXml($path, '_data/class_extensions.xml'),
		static fn (array $element): bool => $element['name'] === 'extension'
	);

	if (!$extensions) {
		fail('class_extensions.xml: no extensions declared');
	}

	foreach ($extensions as $extension) {
		$toClass = $extension['attrs']['to_class'] ?? '';
		$fromClass = $extension['attrs']['from_class'] ?? '';

		if ($toClass === '' || $fromClass === '') {
			fail('class_extensions.xml: an <extension> is missing from_class or to_class');
			continue;
		}

		if (!isset($known[$toClass])) {
			fail("class_extensions.xml: to_class '$toClass' has no matching file under the add-on root");
		}

		if (strpos($fromClass, ADDON_NAMESPACE . '\\') === 0) {
			fail("class_extensions.xml: from_class '$fromClass' points at our own add-on");
		}
	}

	note('class extensions: ' . count($extensions));
}

// ---------------------------------------------------------------------- namespaces

function checkNamespaces(array $phpFiles, string $addonRoot): void
{
	foreach ($phpFiles as $file) {
		$contents = readText($file);
		if (!preg_match('#^namespace\s+([^;]+);#m', $contents, $match)) {
			fail(basename($file) . ': no namespace declaration');
			continue;
		}

		$declared = trim($match[1]);
		$expected = namespaceForPath($file, $addonRoot);
		if ($declared !== $expected) {
			fail(basename($file) . ": namespace '$declared' does not match its path (expected '$expected')");
		}
	}
}

// ------------------------------------------------------------------------- phrases

function checkPhrases(string $dataDir, string $translationsDir, array $phpFiles, string $addonRoot): void
{
	$masterPath = $dataDir . '/phrases.xml';
	if (!is_file($masterPath)) {
		fail('_data/phrases.xml is missing');
		return;
	}

	$master = parsePhrases($masterPath, '_data/phrases.xml', false);
	note('master phrases: ' . count($master));

	if (!is_dir($translationsDir)) {
		fail('_translations directory is missing');
	} else {
		$files = glob($translationsDir . '/*.xml');
		if (!$files) {
			fail('_translations contains no translation files');
		}

		foreach ($files as $file) {
			$name = basename($file);
			$translated = parsePhrases($file, '_translations/' . $name, true);
			note("_translations/$name: " . count($translated) . ' phrases');

			$missing = array_diff(array_keys($master), array_keys($translated));
			if ($missing) {
				$first = reset($missing);
				fail("_translations/$name: missing " . count($missing) . " phrase(s), first: $first");
			}

			$extra = array_diff(array_keys($translated), array_keys($master));
			if ($extra) {
				$first = reset($extra);
				fail("_translations/$name: " . count($extra) . " phrase(s) not present in _data/phrases.xml, first: $first");
			}
		}
	}

	checkPhraseUsage($master, $phpFiles, $addonRoot);
}

/**
 * Every literal \XF::phrase('...') and every BumpResult status must resolve.
 */
function checkPhraseUsage(array $master, array $phpFiles, string $addonRoot): void
{
	$used = [];
	foreach ($phpFiles as $file) {
		preg_match_all("#\\\\XF::phrase\(\s*'([a-z0-9_.]+)'#", readText($file), $matches);
		foreach ($matches[1] as $title) {
			$used[$title] = true;
		}
	}

	foreach (array_keys($used) as $title) {
		if (!isset($master[$title])) {
			fail("phrase '$title' is used in PHP but missing from _data/phrases.xml");
		}
	}

	// BumpResult builds the title dynamically: 'hlds_run_rpd_reason_' . $status.
	$bumpResult = $addonRoot . '/Service/BumpResult.php';
	if (is_file($bumpResult)) {
		preg_match_all("#public const \w+ = '([a-z_]+)'#", readText($bumpResult), $matches);
		foreach ($matches[1] as $status) {
			$title = 'hlds_run_rpd_reason_' . $status;
			if (!isset($master[$title])) {
				fail("BumpResult constant '$status' has no phrase '$title'");
			}
		}
	}

	note('phrases referenced from PHP: ' . count($used));
}

// -------------------------------------------------------------------------- options

function checkOptions(string $dataDir, array $master, array $phpFiles): void
{
	$optionsPath = $dataDir . '/options.xml';
	$groupsPath = $dataDir . '/option_groups.xml';

	if (!is_file($optionsPath) || !is_file($groupsPath)) {
		fail('options.xml or option_groups.xml is missing');
		return;
	}

	$groups = [];
	foreach (readFlatXml($groupsPath, '_data/option_groups.xml') as $element) {
		if ($element['name'] === 'group' && ($element['attrs']['group_id'] ?? '') !== '') {
			$groups[$element['attrs']['group_id']] = true;
		}
	}

	$defined = [];
	foreach (readFlatXml($optionsPath, '_data/options.xml') as $option) {
		if ($option['name'] !== 'option') {
			continue;
		}

		$optionId = $option['attrs']['option_id'] ?? '';
		if ($optionId === '') {
			fail('options.xml: an <option> has no option_id');
			continue;
		}

		if (isset($defined[$optionId])) {
			fail("options.xml: duplicate option '$optionId'");
		}
		$defined[$optionId] = true;

		if (strpos($optionId, 'hldsRunRpd') !== 0) {
			fail("options.xml: option '$optionId' is not prefixed with 'hldsRunRpd'; xf_option is one flat table and unprefixed names collide");
		}

		$relations = [];
		preg_match_all('#<relation\b([^>]*?)/?>#s', $option['text'], $relationMatches);
		foreach ($relationMatches[0] as $relationXml) {
			preg_match('#group_id="([^"]*)"#', $relationXml, $groupMatch);
			$relations[] = $groupMatch[1] ?? '';
		}

		if (!$relations) {
			fail("options.xml: option '$optionId' has no <relation>");
		}
		foreach ($relations as $groupId) {
			if (!isset($groups[$groupId])) {
				fail("options.xml: option '$optionId' points at unknown option group '$groupId'");
			}
		}

		foreach (['option.' . $optionId, 'option_explain.' . $optionId] as $title) {
			if (!isset($master[$title])) {
				fail("options.xml: option '$optionId' has no '$title' phrase");
			}
		}
	}

	foreach (array_keys($groups) as $groupId) {
		foreach (['option_group.', 'option_group_description.'] as $prefix) {
			$title = $prefix . $groupId;
			if (!isset($master[$title])) {
				fail("option_groups.xml: group '$groupId' has no '$title' phrase");
			}
		}
	}

	note('options: ' . count($defined));

	$references = [];
	foreach ($phpFiles as $file) {
		preg_match_all('#options(?:\(\))?->(hldsRunRpd[A-Za-z]+)#', readText($file), $matches);
		foreach ($matches[1] as $optionId) {
			$references[$optionId] = true;
		}
	}

	foreach (array_keys($references) as $optionId) {
		if (!isset($defined[$optionId])) {
			fail("option '$optionId' is used in PHP but not defined in options.xml");
		}
	}

	if ($references) {
		note('options referenced from PHP: ' . count($references));
	}
}

// ------------------------------------------------------------------------ commands

function checkCliCommands(string $addonRoot): void
{
	$dir = $addonRoot . '/Cli/Command';
	if (!is_dir($dir)) {
		fail('Cli/Command directory is missing');
		return;
	}

	$files = glob($dir . '/*.php');
	if (!$files) {
		fail('Cli/Command contains no commands');
		return;
	}

	foreach ($files as $file) {
		$contents = readText($file);
		if (strpos($contents, 'extends AbstractCommand') === false) {
			fail('Cli/Command/' . basename($file) . ': does not extend AbstractCommand');
		}
		if (strpos($contents, '->setName(') === false) {
			fail('Cli/Command/' . basename($file) . ': never calls ->setName()');
		}

		checkCliConfigure($file, $contents);
	}

	note('CLI commands: ' . count($files));
}

/**
 * Things that only break once somebody runs the command on a live board.
 *
 * Both checks here correspond to bugs that shipped in 1.1.2 and were invisible
 * until the command was actually invoked on a forum.
 *
 *  - \XF::phrase() and friends inside configure(). XF\Cli\Runner instantiates
 *    every command while building the list, before it sets up its own
 *    XF\Cli\App. Any \XF:: call that needs an app creates an XF\App implicitly
 *    at that point, and the Runner's own setApp then throws "A second app cannot
 *    be setup" - which takes down every CLI command on the board, not just this
 *    add-on's. Core avoids this by describing its commands in plain English.
 *  - $output->table(). OutputInterface has no such method; rendering a table
 *    means instantiating Symfony's Table helper.
 *
 * @param string $file path, for the message only
 */
function checkCliConfigure(string $file, string $contents): void
{
	$name = 'Cli/Command/' . basename($file);

	// Comments are stripped first: both of these rules exist precisely because the
	// mistake is worth writing a long note about, and a note naming the offending
	// call would otherwise trip the very check that explains the note.
	$code = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $contents);

	if (preg_match('/function\s+configure\s*\([^)]*\)\s*\{(.*?)\n    \}/s', $code, $match)) {
		$body = $match[1];

		// Calls that resolve through \XF::app(). Deliberately a list rather than
		// "any \XF:: call": some are genuinely safe before the app exists.
		// \XF::getAddOnDirectory() is one - it reads a static set by
		// \XF::start() - and \XF::$version / \XF::$DS are plain properties.
		// Verified against src/XF.php in 2.3.2 rather than assumed.
		$appBackedCalls = [
			'app', 'service', 'finder', 'em', 'options', 'config', 'session',
			'phrase', 'phraseDeferred', 'language', 'repository',
		];

		foreach ($appBackedCalls as $method) {
			if (preg_match('#\\\\XF::' . $method . '\s*\(#', $body)) {
				fail("$name: configure() calls \\XF::$method(); "
					. 'the command list is built before the app exists, so this throws '
					. '"A second app cannot be setup" and breaks every CLI command on the board. '
					. 'Use a plain English string.');
			}
		}
	}

	if (preg_match('#\$output->table\s*\(#', $code)) {
		fail("$name: \$output->table() does not exist; OutputInterface has no table() method. "
			. 'Use (new Table($output))->setHeaders(...)->setRows(...)->render().');
	}
}

/**
 * Rejects the AbstractOption row helpers, which silently swallow structured
 * choices.
 *
 * `getCheckboxRow()`, `getSelectRow()` and `getRadioRow()` funnel their choices
 * through `Templater::mergeChoiceOptions()`, and that method only accepts
 * scalars: it keeps an entry when the value is a string, a number or an object
 * with `__toString()`, and drops everything else without a word. XenForo's own
 * repository methods return the structured form instead - `id => ['value' =>
 * $id, 'label' => $title]` - so passing one of those straight through renders a
 * control with no options in it, and the options page shows the row's title and
 * explanation with nothing to answer them with. That shipped as 1.1.3.
 *
 * So: if you want these helpers, pass them a scalar list of your own. If your
 * choices come from a repository, build the `$controlOptions` and call
 * `Templater::formCheckBoxRow()` / `formSelectRow()` / `formRadioRow()` directly,
 * which is what Option\ExcludedCategories does.
 */
function checkOptionRowHelpers(array $phpFiles): void
{
	$helpers = ['getCheckboxRow', 'getSelectRow', 'getRadioRow'];

	foreach ($phpFiles as $file) {
		$code = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', readText($file));

		foreach ($helpers as $helper) {
			if (!preg_match('#(?:static|self)::' . $helper . '\s*\(#', $code)) {
				continue;
			}

			fail(basename($file) . ": static::$helper() drops non-scalar choices - "
				. 'Templater::mergeChoiceOptions() keeps only strings, numbers and '
				. '__toString objects, and every repository method in XenForo returns '
				. "['value' =>, 'label' =>] rows. Call Templater::form*Row() directly.");
		}
	}
}

/**
 * XenForo's own aabbccde version_id scheme.
 *
 * `version_id` is the integer XenForo compares to decide whether an add-on is
 * out of date, and it is what it appends to a template's cache-buster, so a
 * wrong value is not cosmetic: the wrong value means stale JS/CSS in browsers
 * and upgrade steps that never fire. XenForo's own validator only checks that
 * the field is an integer - `xf-addon:validate-json` accepts every wrong
 * number this repository has ever shipped.
 *
 * The scheme is positional and the digits overlap, which is why the widths are
 * uneven:
 *
 *   a    major version, one digit - 1 for this add-on, 2 for XF 2.x
 *   bb   minor version, two digits, 00-99
 *   cc   patch version, two digits, 00-99
 *   d    state: 1 alpha, 3 beta, 5 release candidate, 7 stable
 *   e    state version, one digit, 0-9
 *
 * so the id is major*1000000 + minor*10000 + patch*100 + state*10 + stateVer.
 * A 1.x add-on therefore reads as 1|bb|cc|d|e and XF 2.x as 2|bb|cc|d|e; there
 * is no leading zero, and the whole number is seven digits for a single-digit
 * major. XenForo's documented examples all confirm this, and this repository
 * verified the formula against every one of them: 1.7.3 RC 4 is 1070354,
 * 1.5.0 Beta 3 is 1050033, XF 2.0.0 Stable is 2000070 and XF 2.2.0 Stable is
 * 2020070. The commonly repeated "eight digit" framing is a misreading of the
 * mask, not a second encoding - see docs/VERSIONING.md.
 */
function expectedVersionId(string $versionString): ?int
{
	$pattern = '/^(?<major>[0-9]+)\.(?<minor>[0-9]+)\.(?<patch>[0-9]+)'
		. '\s*(?<word>Alpha|Beta|RC|Stable)?\s*(?<number>[0-9])?$/i';

	if (preg_match($pattern, $versionString, $m) !== 1) {
		return null;
	}

	// No state word means Stable, which is what every released version_string
	// in this repository carries.
	$stages = ['alpha' => 1, 'beta' => 3, 'rc' => 5, 'stable' => 7];
	$stage = ($m['word'] ?? '') !== '' ? $stages[strtolower($m['word'])] : 7;
	$stateVersion = ($m['number'] ?? '') !== '' ? (int) $m['number'] : 0;

	$major = (int) $m['major'];
	$minor = (int) $m['minor'];
	$patch = (int) $m['patch'];

	// A single-digit major is the whole basis of the encoding: two digits here
	// would collide with minor, and the resulting id would decode to something
	// else entirely.
	if ($major < 1 || $major > 9 || $minor > 99 || $patch > 99) {
		return null;
	}

	return ($major * 1000000)
		+ ($minor * 10000)
		+ ($patch * 100)
		+ ($stage * 10)
		+ $stateVersion;
}

/**
 * An id under the scheme above. The check is on the value's shape, not on its
 * agreement with version_string: the state digit is always odd and never 0 or
 * 9, and the leading digit is a major version. 1000016 - what this repository
 * shipped through 1.2.0 - is a plain counter and fails both tests.
 */
function isWellFormedVersionId($value): bool
{
	if (!is_int($value) || $value < 1000000 || $value > 99999999) {
		return false;
	}

	// State digit: the tens place of the last two digits.
	return in_array((int) (($value % 100) / 10), [1, 3, 5, 7], true);
}

function checkVersions(string $addonRoot): void
{
	$path = $addonRoot . '/addon.json';
	$manifest = json_decode(readText($path), true);

	if (!is_array($manifest)) {
		fail('addon.json: not valid JSON, or unreadable');
		return;
	}

	$versionString = $manifest['version_string'] ?? null;
	$versionId = $manifest['version_id'] ?? null;

	if (($manifest['version_id'] ?? null) !== null && !is_int($versionId)) {
		fail('addon.json: version_id must be a JSON integer, not a string');
	}

	if (!isWellFormedVersionId($versionId)) {
		fail('addon.json: version_id ' . var_export($versionId, true) . ' is not a valid '
			. 'aabbccde id (a major, bb minor, cc patch, d state, e state version). '
			. 'A plain counter is what this repository shipped through 1.2.0.');
	} elseif (is_string($versionString)) {
		$expected = expectedVersionId($versionString);

		if ($expected === null) {
			note('addon.json: could not parse version_string "' . $versionString . '" to check '
				. 'version_id against it. Expected a form like 1.2.0 or 1.2.0 Beta 1.');
		} elseif ($expected !== $versionId) {
			fail('addon.json: version_id is ' . $versionId . ' but version_string "'
				. $versionString . '" encodes to ' . $expected
				. '. XenForo offers an upgrade by comparing these two, so a mismatch means '
				. 'either the rebuild never runs or browsers keep serving cached JS/CSS.');
		}
	}

	// A "require" floor is compared against the installed product's own
	// version_id, so it has to be that product's real id and nothing else.
	// 2030010 was here for a long time: a hand-written number that decoded as
	// nothing, one digit off the true 2.3.0 Stable id of 2030070.
	foreach (($manifest['require'] ?? []) as $product => $requirement) {
		if (!is_array($requirement) || !isset($requirement[0])) {
			fail("addon.json: require.$product must be [version_id, description]");
			continue;
		}

		$floor = $requirement[0];

		if (!isWellFormedVersionId($floor)) {
			fail("addon.json: require.$product is " . var_export($floor, true)
				. ', which is not a valid aabbccde id. XenForo compares this number against '
				. "the installed product's own version_id, so a wrong floor either blocks a "
				. 'supported forum or admits an unsupported one.');
		} elseif (((int) (($floor % 100) / 10)) !== 7) {
			// The strongest form of this check available without shipping a table
			// of product versions: a dependency floor is the oldest release that
			// works, which is always a stable one. This is the test 2030010 fails
			// - its state digit is 1, so it decodes as "2.3.0 Alpha", a floor that
			// no XenForo release has ever satisfied.
			fail("addon.json: require.$product is $floor, whose state digit is "
				. (int) (($floor % 100) / 10)
				. ' rather than 7. A dependency floor names a stable release, so the '
				. "state digit is always 7. Decoded, this claims a pre-release that the "
				. 'installed forum would never report.');
		}
	}
}

/**
 * A phrase's version_id records which add-on version last changed its text, and
 * XenForo rewrites the stored phrase when that number rises. It must therefore
 * follow the same scheme as the add-on's, and it must never fall between two
 * released add-on ids - that would silently undo a phrase change on upgrade.
 */
function checkPhraseVersions(string $dataDir, string $translationsDir, int $addonVersionId): void
{
	foreach ([
		'_data/phrases.xml' => $dataDir . '/phrases.xml',
		'_translations/ru.xml' => $translationsDir . '/ru.xml',
	] as $label => $path) {
		if (!is_file($path)) {
			continue;
		}

		foreach (readFlatXml($path, $label) as $element) {
			if ($element['name'] !== 'phrase') {
				continue;
			}

			$title = $element['attrs']['title'] ?? '(untitled)';
			$phraseVersionId = (int) ($element['attrs']['version_id'] ?? 0);

			if (!isWellFormedVersionId($phraseVersionId)) {
				fail("$label: phrase '$title' has version_id "
					. var_export($element['attrs']['version_id'] ?? null, true)
					. ', which is not a valid aabbccde id.');
			} elseif ($phraseVersionId > $addonVersionId) {
				fail("$label: phrase '$title' has version_id $phraseVersionId, higher than the "
					. "add-on's own $addonVersionId. The phrase would claim to come from a "
					. 'release that does not exist.');
			}
		}
	}
}

// --------------------------------------------------------------------------- main

$phpFiles = addonPhpFiles($addonRoot);

if (!$phpFiles) {
	fail('no PHP files found under ' . $addonRoot);
}

checkVersions($addonRoot);
checkPhraseVersions($dataDir, $translationsDir, (int) (json_decode(
	readText($addonRoot . '/addon.json'),
	true
)['version_id'] ?? 0));
checkClassExtensions($dataDir, $addonRoot, $phpFiles);
checkNamespaces($phpFiles, $addonRoot);
checkPhrases($dataDir, $translationsDir, $phpFiles, $addonRoot);
checkOptions($dataDir, parsePhrases($dataDir . '/phrases.xml', '_data/phrases.xml', false), $phpFiles);
checkCliCommands($addonRoot);
checkOptionRowHelpers($phpFiles);

foreach ($notes as $message) {
	echo "  $message\n";
}

if ($errors) {
	echo "\n";
	foreach ($errors as $message) {
		echo "  ERROR $message\n";
	}
	echo "\n" . count($errors) . " problem(s) found.\n";
	exit(1);
}

echo "  ok\n";