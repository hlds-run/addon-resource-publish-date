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

/**
 * Expressions whose result type we know, and the stub that stands for it.
 *
 * Keyed by the source expression, because that is what the call site looks like.
 * A helper not listed here is simply not chain-checked - which is a gap, and a
 * deliberate one: guessing a return type produces false failures, and a false
 * failure teaches people to ignore the check.
 */
const HELPER_RETURN_TYPES = [
	'$this->em()'         => 'XF\\Mvc\\Entity\\Manager',
	'$this->db()'         => 'XF\\Db\\AbstractAdapter',
	'$this->finder()'     => 'XF\\Mvc\\Entity\\Finder',
	'\\XF::em()'         => 'XF\\Mvc\\Entity\\Manager',
	'\\XF::finder()'     => 'XF\\Mvc\\Entity\\Finder',
	'\\XF::app()'        => 'XF\\App',
];

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

// ---------------------------------------------------------------------------
// Stubs for the controller stack, added with the Admin CP backfill buttons.
//
// Mirrors the members Controller\Admin\Backfill actually calls, as of XenForo
// 2.3.2. The list is deliberately short: only what the class declaration and the
// $this-> pass need, for the same reason as every other stub in this file.
//
// Verified against 2.3.2 by reading src/XF/Mvc/Controller.php and
// src/XF/AdminController.php. If a 2.3.x point release renames one of these -
// or drops a route-registration hook - this file reports it, which is the
// direction that gets fixed rather than shipped.
//
// The four vendor surfaces the backfill UI rests on, and where to read them:
//
//   Setup::preRouteBuild()          src/XF/AddOn/AbstractSetup.php
//   RouteBuilder::$admin->add()     src/XF/Mvc/Router/RouteBuilder.php
//                                   (the property is an Admin instance, whose
//                                   add() lives in RouteBuilder/Admin.php)
//   AdminController::assertAdminPermission(), redirect(), getContextualRedirect()
//                                   src/XF/AdminController.php
//   App::session() / Router::buildLink()
//                                   src/XF/App.php, src/XF/Router.php
//
// These are also the assumptions to re-check first on a XenForo upgrade; they are
// listed in docs/UPGRADE.md for that reason.
// ---------------------------------------------------------------------------

eval(<<<'PHP'
namespace XF\Mvc;

class Controller
{
	public function assertPost() {}
	public function db() {}
	public function em() {}
	public function finder() {}
	public function getContextualRedirect() {}
	public function getRequest() {}
	public function getResponse() {}
	public function getUser() {}
	public function redirect() {}
	public function repository() {}
	public function service() {}
	public function setGlobal() {}
	public function setMessage() {}
	public function setView() {}
	public function view() {}
}
PHP);

eval(<<<'PHP'
namespace XF;

class AdminController extends \XF\Mvc\Controller
{
	public function assertAdminPermission() {}
}
PHP);

eval(<<<'PHP'
namespace XF\Mvc\Router;

class RouteBuilder
{
}
PHP);

// ---------------------------------------------------------------------------
// Stubs for the objects the helpers above hand back.
//
// Method lists extracted from the XenForo 2.3.2 source, not written by hand:
//   XF/Mvc/Entity/Manager.php, XF/Mvc/Entity/Finder.php,
//   XF/Db/AbstractAdapter.php, XF/Service/Phrase/ImportService.php, XF/App.php
//
// They exist so the $this-> pass can also resolve a chained call - `$this->em()->
// findAll()` is a runtime fatal just as surely as `$this->app()` is, and that is
// the bug class that got out three times: once on install and once per release
// after it. Regenerate when XenForo is upgraded; docs/UPGRADE.md lists it.
// ---------------------------------------------------------------------------

eval(<<<'PHP'
namespace XF\Mvc\Entity;

class Manager
{
	public function attachEntity() {}
	public function beginTransaction() {}
	public function clearEntityCache() {}
	public function commit() {}
	public function create() {}
	public function decodeValueFromSource() {}
	public function decodeValueFromSourceExtended() {}
	public function detachEntity() {}
	public function encodeValueForSource() {}
	public function entityIsA() {}
	public function find() {}
	public function findByIds() {}
	public function findCached() {}
	public function findOne() {}
	public function finishCascadeEvent() {}
	public function getBasicCollection() {}
	public function getBehaviors() {}
	public function getDb() {}
	public function getDeferredValue() {}
	public function getEmptyCollection() {}
	public function getEntityCacheLookupString() {}
	public function getEntityClassName() {}
	public function getEntityStructure() {}
	public function getFinder() {}
	public function getRelation() {}
	public function getRelationFinder() {}
	public function getRepository() {}
	public function getValueFormatter() {}
	public function hydrateDefaultFromRelation() {}
	public function hydrateFromGrouped() {}
	public function instantiateEntity() {}
	public function rollback() {}
	public function startCascadeEvent() {}
	public function triggerCascadeAttempt() {}
}
PHP);

eval(<<<'PHP'
namespace XF\Mvc\Entity;

class Finder
{
	public function __get($name) {}
	public function __isset($name) {}
	public function app() {}
	public function arrayRepresentsCondition() {}
	public function buildCondition() {}
	public function buildConditionFromArray() {}
	public function buildIndexHint() {}
	public function caseInsensitive() {}
	public function columnSqlName() {}
	public function columnUtf8() {}
	public function escapeExpression() {}
	public function escapeLike() {}
	public function exists() {}
	public function expression() {}
	public function fetch() {}
	public function fetchColumns() {}
	public function fetchDeferred() {}
	public function fetchOne() {}
	public function fetchProxied() {}
	public function fetchRaw() {}
	public function fetchRawEntities() {}
	public function getCollectionFromResults() {}
	public function getColumnAlias() {}
	public function getConditions() {}
	public function getHydrationMap() {}
	public function getIdsClause() {}
	public function getIterator() {}
	public function getParentFinder() {}
	public function getQuery() {}
	public function getRelationIndexHints() {}
	public function getStructure() {}
	public function indexHint() {}
	public function isColumnValid() {}
	public function isOrderMatch() {}
	public function join() {}
	public function keyedBy() {}
	public function limit() {}
	public function limitByPage() {}
	public function offset() {}
	public function order() {}
	public function orderRandom() {}
	public function pluckFrom() {}
	public function quote() {}
	public function renderToOrderSqlParts() {}
	public function resetOrder() {}
	public function resetWhere() {}
	public function resolveFieldToTableAndColumn() {}
	public function setDefaultOrder() {}
	public function setParentFinder() {}
	public function standardizeOrderingValue() {}
	public function total() {}
	public function where() {}
	public function whereAddOnActive() {}
	public function whereId() {}
	public function whereIds() {}
	public function whereIf() {}
	public function whereImpossible() {}
	public function whereOr() {}
	public function whereSql() {}
	public function with() {}
	public function withEntity() {}
	public function writeSqlCondition() {}
	public function writeSqlOrder() {}
}
PHP);

eval(<<<'PHP'
namespace XF\Db;

class AbstractAdapter
{
	public function areQueriesLogged() {}
	public function beginTransaction() {}
	public function closeConnection() {}
	public function commit() {}
	public function commitAll() {}
	public function connect() {}
	public function delete() {}
	public function emptyTable() {}
	public function escapeLike() {}
	public function executeTransaction() {}
	public function fetchAll() {}
	public function fetchAllColumn() {}
	public function fetchAllKeyed() {}
	public function fetchAllNum() {}
	public function fetchOne() {}
	public function fetchPairs() {}
	public function fetchRow() {}
	public function getConnectionForQuery() {}
	public function getIgnoreLegacyTableWriteError() {}
	public function getModifiersFromQuery() {}
	public function getQueryCount() {}
	public function getQueryLog() {}
	public function getSchemaManager() {}
	public function getUtf8Type() {}
	public function ignoreLegacyTableWriteError() {}
	public function inTransaction() {}
	public function insert() {}
	public function insertBulk() {}
	public function limit() {}
	public function logQueries() {}
	public function logQueryCompletion() {}
	public function logQueryExecution() {}
	public function logQueryStage() {}
	public function logSimpleOnly() {}
	public function prependPrefixToTables() {}
	public function processDbWriteException() {}
	public function query() {}
	public function quote() {}
	public function rawQuery() {}
	public function rollback() {}
	public function rollbackAll() {}
	public function update() {}
}
PHP);

eval(<<<'PHP'
namespace XF\Service\Phrase;

class ImportService
{
	public function deleteExistingPhrases() {}
	public function getExistingPhraseMap() {}
	public function getLanguage() {}
	public function importFromXml() {}
	public function setPhraseOptions() {}
}
PHP);

eval(<<<'PHP'
namespace XF;

class App
{
	public function __get($name) {}
	public function __set($name, $value) {}
	public function addOnDataManager() {}
	public function addOnManager() {}
	public function apiDocs() {}
	public function applyExternalDataUrl() {}
	public function applyExternalDataUrlPathed() {}
	public function applyLocalDataUrl() {}
	public function applyLocalDataUrlPathed() {}
	public function arrayValidator() {}
	public function assertConfigExists() {}
	public function auth() {}
	public function bbCode() {}
	public function bounce() {}
	public function cache() {}
	public function captcha() {}
	public function checkDbWriteForced() {}
	public function checkDebugMode() {}
	public function complete() {}
	public function config() {}
	public function container() {}
	public function controller() {}
	public function cookieConsent() {}
	public function create() {}
	public function criteria() {}
	public function cssWriter() {}
	public function data() {}
	public function db() {}
	public function debugger() {}
	public function designerOutput() {}
	public function developmentJsResponse() {}
	public function developmentOutput() {}
	public function dispatcher() {}
	public function displayFatalExceptionMessage() {}
	public function em() {}
	public function error() {}
	public function extendClass() {}
	public function extension() {}
	public function filterer() {}
	public function finalOutputFilter() {}
	public function find() {}
	public function findByContentType() {}
	public function finder() {}
	public function fire() {}
	public function formAction() {}
	public function forumType() {}
	public function fromRegistry() {}
	public function fs() {}
	public function get() {}
	public function getContentTypeEntity() {}
	public function getContentTypeField() {}
	public function getContentTypeFieldValue() {}
	public function getContentTypeIdFromString() {}
	public function getContentTypePhrase() {}
	public function getContentTypePhraseName() {}
	public function getContentTypePhrases() {}
	public function getCustomFields() {}
	public function getCustomFieldsForEdit() {}
	public function getDynamicRedirect() {}
	public function getDynamicRedirectIfNot() {}
	public function getErrorRoute() {}
	public function getFieldsForContentType() {}
	public function getGlobalTemplateData() {}
	public function getPreloadExtraKeys() {}
	public function getRedirectHash() {}
	public function getVisitorFromSession() {}
	public function giphyApi() {}
	public function helper() {}
	public function http() {}
	public function iconRenderer() {}
	public function imageManager() {}
	public function import() {}
	public function initialize() {}
	public function initializeExtra() {}
	public function inputFilterer() {}
	public function isValid() {}
	public function job() {}
	public function jobManager() {}
	public function language() {}
	public function logException() {}
	public function logger() {}
	public function mailTemplater() {}
	public function mailer() {}
	public function notifier() {}
	public function oAuth() {}
	public function oembed() {}
	public function offsetExists() {}
	public function offsetGet() {}
	public function offsetSet() {}
	public function offsetUnset() {}
	public function options() {}
	public function permissionBuilder() {}
	public function permissionCache() {}
	public function postDispatch() {}
	public function preDispatch() {}
	public function preLoadData() {}
	public function preRender() {}
	public function preloadExtraData() {}
	public function proxy() {}
	public function registry() {}
	public function renderPage() {}
	public function renderPageHtml() {}
	public function renderer() {}
	public function repository() {}
	public function request() {}
	public function response() {}
	public function router() {}
	public function run() {}
	public function search() {}
	public function searcher() {}
	public function service() {}
	public function session() {}
	public function setup() {}
	public function setupAddOnComposerAutoload() {}
	public function setupCookieConsent() {}
	public function setupTemplaterObject() {}
	public function simpleCache() {}
	public function sitemapBuilder() {}
	public function spam() {}
	public function start() {}
	public function stringFormatter() {}
	public function style() {}
	public function templateCompiler() {}
	public function templater() {}
	public function threadType() {}
	public function unsubscribe() {}
	public function updateCsrfCookie() {}
	public function userLanguage() {}
	public function validator() {}
	public function webhookCriteria() {}
	public function widget() {}
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
	// No leading namespace separator: class_exists('\\token_get_all') is false,
	// so the fallback silently ran for every file and mangled the source. The
	// symptom was the $this-> pass finding no calls at all.
	if (function_exists('token_get_all')) {
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

// ---------------------------------------------------------------- static surface

// Every one of this add-on's four runtime bugs was a call to a method that does
// not exist: $this->app(), $this->em()->findAll(), \XF::logInfo() and
// $this->service() from Setup. The first two are covered above; this covers the
// third. Method list extracted from src/XF.php in XenForo 2.3.2 - 90 public static
// methods.
eval(<<<'PHP'
namespace HldsRun\ResourcePublishDate\Tools;

class XfStaticSurface
{
	public static function accessToken() {}
	public static function apiKey() {}
	public static function app() {}
	public static function arrayValidator() {}
	public static function asPreRegActionUser() {}
	public static function asPreRegActionUserIfNeeded() {}
	public static function asVisitor() {}
	public static function bootstrap() {}
	public static function canPerformPreRegAction() {}
	public static function canonicalizeUrl() {}
	public static function classToString() {}
	public static function cleanArrayStrings() {}
	public static function cleanString() {}
	public static function config() {}
	public static function convertToAbsoluteUrl() {}
	public static function createAliasForClass() {}
	public static function db() {}
	public static function dequeueRunOnce() {}
	public static function dump() {}
	public static function dumpSimple() {}
	public static function dumpToFile() {}
	public static function em() {}
	public static function escapeString() {}
	public static function extendClass() {}
	public static function extension() {}
	public static function finder() {}
	public static function fire() {}
	public static function fs() {}
	public static function generateRandomString() {}
	public static function getAddOnDirectory() {}
	public static function getAliasForClass() {}
	public static function getAliasableNamespaces() {}
	public static function getAvailableMemory() {}
	public static function getClassForAlias() {}
	public static function getCopyrightHtml() {}
	public static function getCopyrightHtmlAcp() {}
	public static function getMemoryLimit() {}
	public static function getRootDirectory() {}
	public static function getSourceDirectory() {}
	public static function getUnaliasableNamespaces() {}
	public static function getVendorDirectory() {}
	public static function handleException() {}
	public static function handleFatalError() {}
	public static function handlePhpError() {}
	public static function helper() {}
	public static function increaseMemoryLimit() {}
	public static function isAddOnActive() {}
	public static function isApiBypassingPermissions() {}
	public static function isApiCheckingPermissions() {}
	public static function isPreEscaped() {}
	public static function isPushUsable() {}
	public static function language() {}
	public static function logError() {}
	public static function logException() {}
	public static function mailer() {}
	public static function options() {}
	public static function permissionCache() {}
	public static function phrase() {}
	public static function phraseDeferred() {}
	public static function phrasedException() {}
	public static function preRegActionUser() {}
	public static function registerComposerAutoloadData() {}
	public static function registerComposerAutoloadDir() {}
	public static function registry() {}
	public static function renderPlainString() {}
	public static function repository() {}
	public static function requestUrlMatchesApi() {}
	public static function runApp() {}
	public static function runLater() {}
	public static function runOnce() {}
	public static function service() {}
	public static function session() {}
	public static function setAccessToken() {}
	public static function setApiBypassPermissions() {}
	public static function setApiKey() {}
	public static function setApp() {}
	public static function setLanguage() {}
	public static function setMemoryLimit() {}
	public static function setVisitor() {}
	public static function setupApp() {}
	public static function setupClassAliases() {}
	public static function standardizeEnvironment() {}
	public static function start() {}
	public static function startAutoloader() {}
	public static function startSystem() {}
	public static function string() {}
	public static function stringToClass() {}
	public static function triggerRunOnce() {}
	public static function updateTime() {}
	public static function visitor() {}
}
PHP);

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
		// Source with comments and strings removed, read once for both passes.
		$code = readCode((string) file_get_contents($file));

		// Chained calls on what the helpers return. `$this->em()->findAll()` is a
		// runtime fatal for exactly the same reason `$this->app()` was, and it is
		// the third time this shape has shipped.
		foreach (HELPER_RETURN_TYPES as $expression => $stubClass) {
			preg_match_all(
				'/' . preg_quote($expression, '/') . '\\s*->\\s*([a-zA-Z_]\\w*)\\s*\\(/',
				$code,
				$chainCalls,
				PREG_SET_ORDER
			);
			$target = new \ReflectionClass($stubClass);
			foreach ($chainCalls as $chainCall) {
				if (!$target->hasMethod($chainCall[1])) {
					$errors[] = sprintf(
						'%s: %s%s() - %s has no such method',
						$relative,
						$expression,
						$chainCall[1],
						$stubClass
					);
				}
			}
		}

		// Static calls on the XF facade. \XF::logInfo() does not exist; there is
		// logError, logException, handleFatalError and handlePhpError, and nothing
		// else. The check reads plausibly and only fails when the line runs.
		preg_match_all('/\\XF::([a-zA-Z_]\\w*)\\s*\\(/', $code, $staticCalls);
		$xfSurface = new \ReflectionClass('HldsRun\\ResourcePublishDate\\Tools\\XfStaticSurface');
		foreach (array_unique($staticCalls[1]) as $staticCall) {
			if (!$xfSurface->hasMethod($staticCall)) {
				$errors[] = "$relative: \\XF::$staticCall() - no such static method on XF";
			}
		}

		if ($reflection->hasMethod('__call') || $reflection->isInterface()) {
			continue;
		}

		preg_match_all('/\$this->([a-zA-Z_]\w*)\s*\(/', $code, $calls);
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
	ADDON_NAMESPACE . '\\Option\\BackfillTools',
	ADDON_NAMESPACE . '\\Option\\ExcludedCategories',
	ADDON_NAMESPACE . '\\Service\\BackfillResult',
	ADDON_NAMESPACE . '\\Service\\BackfillService',
	ADDON_NAMESPACE . '\\Service\\BumpResult',
	ADDON_NAMESPACE . '\\Service\\PublishDateManager',
	ADDON_NAMESPACE . '\\Service\\TranslationInstaller',
	ADDON_NAMESPACE . '\\Cli\\Command\\BackfillPublishDates',
	ADDON_NAMESPACE . '\\Cli\\Command\\ImportTranslation',
	ADDON_NAMESPACE . '\\Controller\\Admin\\Backfill',
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