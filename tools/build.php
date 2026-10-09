<?php

/**
 * Builds the installable release ZIP.
 *
 *   php tools/build.php
 *
 * The result lands in _releases/HldsRun-ResourcePublishDate-<version>.zip and is
 * what you upload through Admin CP -> Add-ons -> Install Add-on -> Upload ZIP.
 *
 * XenForo has `php cmd.php xf-addon:build-release` for this, and where a
 * XenForo installation is available that is the better command: it runs the real
 * exporter, so anything this add-on ships via _data/ is exported by XenForo
 * itself rather than by our reading of the same rules. Use this script when
 * there is no XenForo to hand - a release built from a laptop, or from CI - and
 * be aware of the difference.
 *
 * What it reproduces, verified byte-for-byte against hashes.json shipped with
 * XFRM 2.3.2:
 *
 *   * every file under upload/ is copied to <build>/upload/, so the archive
 *     contains an `upload/` prefix - XF\Service\AddOnArchive\ExtractorService
 *     strips exactly that prefix when installing;
 *   * hashes.json covers every file except itself, keyed by path relative to
 *     upload/, sha256, sorted with ksort(SORT_NATURAL|SORT_FLAG_CASE), encoded
 *     with JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES and no trailing newline;
 *   * dotfiles are skipped, as in XF\Service\AddOn\HashGeneratorService.
 *
 * Requires ext-zip. Everything else is core PHP.
 */

declare(strict_types=1);

const ADDON_ID = 'HldsRun/ResourcePublishDate';

$repoRoot = dirname(__DIR__);
$uploadRoot = $repoRoot . '/upload';
$addonDir = $uploadRoot . '/src/addons/' . ADDON_ID;
$buildDir = $repoRoot . '/_build';
$releasesDir = $repoRoot . '/_releases';

if (!is_dir($addonDir)) {
	fwrite(STDERR, "Add-on directory not found: $addonDir\n");
	exit(1);
}

// ext-zip is only needed for the archive, not for hashes.json. The check lives
// next to the zip step rather than at the top so that regenerating hashes.json
// - a committed source file that CI verifies - works on a PHP without it.

$jsonPath = $addonDir . '/addon.json';
if (!is_file($jsonPath)) {
	fwrite(STDERR, "addon.json not found at $jsonPath\n");
	exit(1);
}

$manifest = json_decode((string) file_get_contents($jsonPath), true);
if (!is_array($manifest)) {
	fwrite(STDERR, "addon.json is not valid JSON: " . json_last_error_msg() . "\n");
	exit(1);
}

foreach (['title', 'version_id', 'version_string'] as $required) {
	if (empty($manifest[$required])) {
		fwrite(STDERR, "addon.json: '$required' is required by XenForo's own validator\n");
		exit(1);
	}
}

$versionString = $manifest['version_string'];

/**
 * The archive name, derived the way XenForo derives it.
 *
 * XF\AddOn\AddOn::getReleasePath() is "<addOnId>-<version_string>.zip", with the
 * id having '/' replaced by '-' and the version sanitised. Reproducing that
 * exactly is the point: an archive built here and one built by
 * `xf-addon:build-release` have to be the same file name, or the two build paths
 * are not interchangeable and nobody can tell which one an artefact came from.
 *
 * Note the absence of a "v". There is nowhere for one to come from - the version
 * comes from version_string, which is "1.0.0" - so the archive is named
 * HldsRun-ResourcePublishDate-1.0.0.zip even though the git tag is v1.0.0. That
 * is XenForo's convention, not ours to change; see the versioning section of
 * docs/DEVELOPING.md.
 */
function releaseFileName(string $addOnId, string $versionString): string
{
	$addOnFileName = strpos($addOnId, '/') !== false ? str_replace('/', '-', $addOnId) : $addOnId;

	// XF\AddOn\AddOn::prepareVersionForFilename()
	$versionFileName = preg_replace('/[^a-z0-9-_. ]/i', '', $versionString);
	$versionFileName = trim(preg_replace('/\s{2,}/', ' ', $versionFileName));

	return $addOnFileName . '-' . $versionFileName . '.zip';
}

// ------------------------------------------------------------------ copy files

if (is_dir($buildDir)) {
	removeTree($buildDir);
}
// The archive mirrors upload/ verbatim, so the path inside the zip is
// upload/src/addons/<id>/<file>. That prefix is not decoration:
// XF\Service\AddOnArchive\ExtractorService::open() locates
// upload/src/addons/<id>/addon.json to decide the archive is an add-on at all,
// and getFsFileNameFromZipName() strips exactly 'upload/'. Get this wrong and
// XenForo rejects the archive with "Zip isn't an add-on".
$stagedUploadDir = $buildDir . '/upload';
$stagedAddonDir = $stagedUploadDir . '/src/addons/' . ADDON_ID;
mkdir($stagedAddonDir, 0755, true);

/** Directories XenForo excludes when it copies an add-on for release. */
$excludedDirectories = ['_build', '_files', '_no_upload', '_output', '_releases', '.git', '.svn'];

$copied = 0;
foreach (listFiles($uploadRoot) as $relativePath) {
	$segments = explode('/', $relativePath);
	foreach ($excludedDirectories as $excluded) {
		if (in_array($excluded, $segments, true)) {
			continue 2;
		}
	}

	// Hashed fresh below; a committed copy must never end up in the archive.
	if (basename($relativePath) === 'hashes.json') {
		continue;
	}

	$target = $stagedUploadDir . '/' . $relativePath;
	if (!is_dir(dirname($target))) {
		mkdir(dirname($target), 0755, true);
	}
	if (!copy($uploadRoot . '/' . $relativePath, $target)) {
		fwrite(STDERR, "Failed to copy $relativePath\n");
		exit(1);
	}
	$copied++;
}

// --------------------------------------------------------------- hashes.json

$hashes = [];
foreach (listFiles($stagedUploadDir) as $relativePath) {
	if (basename($relativePath) === 'hashes.json') {
		continue;
	}
	$hashes[$relativePath] = hash_file('sha256', $stagedUploadDir . '/' . $relativePath);
}

// ksort() rather than an approximation of it: this script runs on the same PHP
// that XenForo runs on, so calling the function directly is both simpler and
// exact. An attempt to reimplement the sort in userland got the ordering of '_'
// versus letters wrong, which silently produced a hashes.json with every key in
// a different position from the one XenForo would generate.
ksort($hashes, SORT_NATURAL | SORT_FLAG_CASE);

$hashesJson = json_encode($hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// Into the archive, and into the working tree: hashes.json is committed source,
// because XenForo uses it for File Check and for update detection.
file_put_contents($stagedAddonDir . '/hashes.json', $hashesJson);
file_put_contents($addonDir . '/hashes.json', $hashesJson);
echo 'hashes.json: ' . count($hashes) . " files\n";

// ------------------------------------------------------------------------- zip

if (!class_exists(ZipArchive::class)) {
	removeTree($buildDir);
	echo "\nhashes.json was regenerated, but the archive was not built: ext-zip is missing.\n";
	echo "  locally: apt install php-zip\n";
	echo "  in CI:   shivammathur/setup-php with extensions: zip\n";
	echo "  or build on a machine that has XenForo:\n";
	echo "    php cmd.php xf-addon:build-release " . ADDON_ID . "\n";
	exit(0);
}

$releaseName = releaseFileName(ADDON_ID, $versionString);
if (!is_dir($releasesDir)) {
	mkdir($releasesDir, 0755, true);
}
$releasePath = $releasesDir . '/' . $releaseName;
@unlink($releasePath);

$zip = new ZipArchive();
if ($zip->open($releasePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
	fwrite(STDERR, "Could not create $releasePath\n");
	exit(1);
}

// Directory entries too, matching what XenForo's own builder emits. The
// installer skips them, but their absence is a needless difference from every
// other add-on archive.
foreach (listDirectories($buildDir) as $relativePath) {
	$zip->addEmptyDir($relativePath . '/');
}
foreach (listFiles($stagedUploadDir) as $relativePath) {
	$zip->addFile($stagedUploadDir . '/' . $relativePath, 'upload/' . $relativePath);
	$zip->setCompressionName('upload/' . $relativePath, ZipArchive::CM_DEFLATE);
}
$zip->close();

// -------------------------------------------------------------- verify archive

// A malformed archive fails on the forum, not here, and the failure message
// ("Zip isn't an add-on") does not say which part of the layout is wrong. Check
// it now instead. The prefix bug this catches shipped once: the archive was
// built from the add-on directory rather than from upload/, so every entry was
// missing src/addons/<id>/ and XenForo would have rejected it outright.
$verify = new ZipArchive();
if ($verify->open($releasePath) !== true) {
	fwrite(STDERR, "Verification failed: could not reopen $releasePath\n");
	exit(1);
}

$required = 'upload/src/addons/' . ADDON_ID . '/addon.json';
if ($verify->locateName($required) === false) {
	fwrite(STDERR, "Verification failed: $required is not in the archive.\n");
	fwrite(STDERR, "XenForo would reject this with \"Zip isn't an add-on\".\n");
	exit(1);
}

$problems = [];
foreach (array_keys($hashes) as $key) {
	$name = 'upload/' . $key;
	if ($verify->locateName($name) === false) {
		$problems[] = 'listed in hashes.json but absent from the archive: ' . $key;
		continue;
	}
	if (hash_file('sha256', $stagedUploadDir . '/' . $key) !== $hashes[$key]) {
		$problems[] = 'content does not match its hash: ' . $key;
	}
}
$verify->close();

if ($problems) {
	fwrite(STDERR, "Verification failed:\n");
	foreach ($problems as $problem) {
		fwrite(STDERR, "  $problem\n");
	}
	exit(1);
}

removeTree($buildDir);

echo 'release: ' . $releasePath . "\n";
echo '        ' . number_format((int) filesize($releasePath)) . " bytes, $copied files\n";
echo "\nUpload it through Admin CP -> Add-ons -> Install Add-on -> Upload ZIP.\n";

// ------------------------------------------------------------------- helpers

/**
 * Every file below $root, as paths relative to $root, sorted for reproducibility.
 *
 * @return string[]
 */
function listFiles(string $root): array
{
	$found = [];
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
	);

	foreach ($iterator as $file) {
		if ($file->isFile()) {
			$found[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
		}
	}

	sort($found);
	return $found;
}

/**
 * Every directory below $root, as paths relative to $root, parents first.
 *
 * @return string[]
 */
function listDirectories(string $root): array
{
	$found = [];
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ($iterator as $item) {
		if ($item->isDir()) {
			$found[] = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
		}
	}

	sort($found);
	return $found;
}

function removeTree(string $path): void
{
	if (!is_dir($path)) {
		return;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ($iterator as $item) {
		$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
	}

	rmdir($path);
}