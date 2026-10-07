<?php

/**
 * Loads every add-on class with the vendor classes stubbed, so PHP performs the
 * check it performs at runtime.
 *
 *   php tools/class_check.php
 *
 * Why this exists: `php -l` parses a file and says nothing about inheritance.
 * A class extending an abstract parent without implementing its abstract methods
 * is syntactically perfect and dies at runtime, with a fatal that names the
 * method but not the file that should have implemented it. That is exactly what
 * this add-on shipped once - Setup.php extended XF\AddOn\AbstractSetup without
 * the three step-runner traits, and the failure surfaced on a forum, from the
 * data-rebuild job, minutes after an apparently successful install.
 *
 * The stubs below mirror the abstract members of the classes this add-on
 * extends, as of XenForo 2.3.2. They are deliberately not a XenForo emulator:
 * only what class declaration needs. If an upgrade changes a parent's abstract
 * surface, this file must change with it - it is listed in the compatibility
 * checklist in docs/UPGRADE.md for that reason.
 *
 * A stub that goes stale produces a false failure here, which is the safe
 * direction: it gets fixed rather than shipped.
 *
 * Note on failure output: an unimplemented abstract method is raised by PHP at
 * class-declaration time and is *not* catchable, so the process dies with PHP's
 * own fatal rather than this script's report. That is deliberate. The message
 * names the class, the three methods and the parent, which is a better diagnostic
 * than anything this file could assemble, and the exit status is non-zero either
 * way.
 */

declare(strict_types=1);

const ADDON_ID = 'HldsRun/ResourcePublishDate';
const ADDON_NAMESPACE = 'HldsRun\\ResourcePublishDate';

$repoRoot = dirname(__DIR__);
// The path form uses slashes; the namespace form uses backslashes. Confusing the
// two is the mistake this file is nominally about, so both are named explicitly
// and never derived from each other at the point of use.
$addonRoot = $repoRoot . '/upload/src/addons/' . ADDON_ID;

$errors = [];

// --------------------------------------------------------------- vendor stubs

eval(<<<'PHP'
namespace XF\AddOn;

abstract class AbstractSetup
{
	abstract public function install(array $stepParams = []);
	abstract public function upgrade(array $stepParams = []);
	abstract public function uninstall(array $stepParams = []);
}

trait StepRunnerInstallTrait
{
	public function install(array $stepParams = []) {}
}

trait StepRunnerUpgradeTrait
{
	public function upgrade(array $stepParams = []) {}
}

trait StepRunnerUninstallTrait
{
	public function uninstall(array $stepParams = []) {}
}
PHP);

eval(<<<'PHP'
namespace XF\Option;

abstract class AbstractOption
{
}
PHP);

eval(<<<'PHP'
namespace XF\Service;

// Mirrors the members of XF\Service\AbstractService in 2.3.2, including the
// fact that it has no app() method - only a protected $app property. A stub that
// omitted the helper methods would report every $this->db() as unknown.
abstract class AbstractService
{
	/**
	 * @var \XF\App
	 */
	protected $app;

	public function __construct(\XF\App $app)
	{
		$this->app = $app;
		$this->setup();
	}

	protected function setup()
	{
	}

	protected function db()
	{
	}

	protected function em()
	{
	}

	protected function repository($repository)
	{
	}

	protected function finder($finder)
	{
	}

	protected function findOne($finder, array $where, $with = null)
	{
	}

	public function service($class)
	{
	}
}
PHP);

eval(<<<'PHP'
namespace XF\Cli\Command;

abstract class AbstractCommand
{
}
PHP);

eval(<<<'PHP'
namespace XF;

class App
{
}
PHP);

// XFCP_ classes are the class aliases XenForo creates for a class extension,
// named after the class being extended and living in the *extension's*
// namespace. Both are services, so both extend AbstractService and inherit
// service(), db() and the rest - which our overrides call, and which the
// $this->method() pass below needs to see.
eval(<<<'PHP'
namespace HldsRun\ResourcePublishDate\XFRM\Service\ResourceItem;

class XFCP_Approve extends \XF\Service\AbstractService
{
	protected function onApprove()
	{
	}

	public function approve()
	{
	}
}
PHP);

eval(<<<'PHP'
namespace HldsRun\ResourcePublishDate\XF\Service\Thread;

class XFCP_ApproverService extends \XF\Service\AbstractService
{
	protected function onApprove()
	{
	}

	public function approve()
	{
	}
}
PHP);

/**
 * Source with comments and string literals removed.
 *
 * Without this the $this-> pass matches itself: a comment explaining "do not call
 * $this->app() here" is a match, and the check would report a method that the file
 * only ever mentions. The tokenizer is exact; the regex fallback covers the CLI
 * builds that ship without ext-tokenizer.
 */
function readCode(string $source): string
{
	if (class_exists('\\token_get_all')) {
		$out = '';
		foreach (token_get_all($source) as $token) {
			if (is_array($token)) {
				// Keep code tokens; drop comments, and the literal contents of
				// strings so a heredoc example cannot be mistaken for a call.
				if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
					continue;
				}
				$out .= $token[0] === T_CONSTANT_ENCAPSED_STRING ? "''" : $token[1];
			} else {
				$out .= $token;
			}
		}
		return $out;
	}

	$withoutStrings = preg_replace(['/\'(?:\\\\.|[^\'\\\\])*\'/s', '/"(?:\\\\.|[^"\\\\])*"/s'], "''", $source);
	$withoutStrings = preg_replace(['#//[^\n\r]*#', '#/\*.*?\*/#s'], '', $withoutStrings);

	return (string) $withoutStrings;
}

// ------------------------------------------------------------------- our files

$files = [];
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($addonRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
	if ($file->isFile() && $file->getExtension() === 'php') {
		$files[] = $file->getPathname();
	}
}
sort($files);

$declared = 0;

foreach ($files as $file) {
	$relative = substr($file, strlen($repoRoot) + 1);

	// PHP raises "contains N abstract methods" as an Error at class-declaration
	// time, which require() cannot be wrapped around. Loading each file in a
	// child process would catch it, but that is slow and the message names the
	// class, not the file - so the declaration is attempted here and the Error
	// caught, then attributed back to this file by the class name it declared.
	$before = get_declared_classes();
	try {
		require_once $file;
	} catch (\Throwable $e) {
		$errors[] = "$relative: " . $e->getMessage();
		continue;
	}

	foreach (array_diff(get_declared_classes(), $before) as $class) {
		if (strpos($class, ADDON_NAMESPACE . '\\') !== 0) {
			continue;
		}
		$declared++;

		$reflection = new \ReflectionClass($class);
		if ($reflection->isAbstract()) {
			$errors[] = "$relative: $class is abstract, so XenForo cannot instantiate it";
		}

		// Every `$this->foo()` must resolve to something the class can actually
		// call. This catches a call to a method the vendor parent does not have -
		// `$this->app()` on AbstractService, which has a protected $app property
		// and no app() method. That is a runtime fatal on the exact code path the
		// method is on, so for a service called from an approval handler it means
		// the error only appears when a moderator approves something.
		//
		// PHP resolves `$this->foo()` against the class and its parents, plus any
		// __call. Reading the call sites out of the source is crude but the files
		// are ours and the pattern is unambiguous enough to be worth it.
		if ($reflection->hasMethod('__call') || $reflection->isInterface()) {
			continue;
		}

		preg_match_all(
			'/\$this->([a-zA-Z_]\w*)\s*\(/',
			readCode((string) file_get_contents($file)),
			$calls
		);
		foreach (array_unique($calls[1]) as $method) {
			if (!$reflection->hasMethod($method)) {
				$errors[] = "$relative: $class::\$this->$method() - no such method, and no __call() to catch it";
			}
		}
	}
}

// ---------------------------------------------------------------------- report

$expected = [
	ADDON_NAMESPACE . '\\PublishDateAddOn',
	ADDON_NAMESPACE . '\\Setup',
	ADDON_NAMESPACE . '\\Option\\ExcludedCategories',
	ADDON_NAMESPACE . '\\Service\\BumpResult',
	ADDON_NAMESPACE . '\\Service\\PublishDateManager',
	ADDON_NAMESPACE . '\\Service\\TranslationInstaller',
	ADDON_NAMESPACE . '\\Cli\\Command\\BackfillPublishDates',
	ADDON_NAMESPACE . '\\Cli\\Command\\ImportTranslation',
	ADDON_NAMESPACE . '\\XFRM\\Service\\ResourceItem\\Approve',
	ADDON_NAMESPACE . '\\XF\\Service\\Thread\\ApproverService',
];

$missing = array_diff($expected, get_declared_classes());
foreach ($missing as $class) {
	$errors[] = "expected class was not declared by loading the add-on: $class";
}

echo '  classes loaded: ' . $declared . ' of ' . count($expected) . "\n";

if ($errors) {
	echo "\n";
	foreach ($errors as $error) {
		echo "  ERROR $error\n";
	}
	echo "\n" . count($errors) . " problem(s) found.\n";
	exit(1);
}

echo "  ok\n";