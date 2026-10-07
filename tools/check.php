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
	}

	note('CLI commands: ' . count($files));
}

// --------------------------------------------------------------------------- main

$phpFiles = addonPhpFiles($addonRoot);

if (!$phpFiles) {
	fail('no PHP files found under ' . $addonRoot);
}

checkClassExtensions($dataDir, $addonRoot, $phpFiles);
checkNamespaces($phpFiles, $addonRoot);
checkPhrases($dataDir, $translationsDir, $phpFiles, $addonRoot);
checkOptions($dataDir, parsePhrases($dataDir . '/phrases.xml', '_data/phrases.xml', false), $phpFiles);
checkCliCommands($addonRoot);

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