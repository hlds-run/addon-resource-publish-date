<?php

/**
 * Extracts the changelog section for the current version, for use as the body of
 * the GitHub release.
 *
 *   php tools/release_notes.php > release-notes.md
 *
 * The changelog is the single source of truth for release notes. Generating them
 * from the commit log instead would produce a list of commits, which is a
 * different and much worse document: it says what changed in the repository rather
 * than what a person installing this needs to know, and it cannot be reviewed
 * before it ships.
 */

declare(strict_types=1);

const ADDON_ID = 'HldsRun/ResourcePublishDate';

$repoRoot = dirname(__DIR__);
$changelogPath = $repoRoot . '/CHANGELOG.md';
$manifestPath = $repoRoot . '/upload/src/addons/' . ADDON_ID . '/addon.json';

if (!is_file($changelogPath)) {
	fwrite(STDERR, "CHANGELOG.md not found\n");
	exit(1);
}
if (!is_file($manifestPath)) {
	fwrite(STDERR, "addon.json not found\n");
	exit(1);
}

$manifest = json_decode((string) file_get_contents($manifestPath), true);
$version = $manifest['version_string'] ?? '';
if ($version === '') {
	fwrite(STDERR, "addon.json: version_string is missing\n");
	exit(1);
}

$lines = explode("\n", (string) file_get_contents($changelogPath));
$heading = '## [' . $version . ']';
$section = [];
$capturing = false;

foreach ($lines as $line) {
	if ($capturing) {
		// The next heading of any level ends the section.
		if (strpos($line, '## ') === 0) {
			break;
		}
		$section[] = rtrim($line);
		continue;
	}

	if (rtrim($line) === $heading || strpos($line, $heading . ' ') === 0) {
		// Keep the heading, without its date: GitHub renders the release date
		// next to the title anyway, and repeating it inside the body reads as a
		// second, possibly conflicting, date.
		$capturing = true;
		$section[] = '## ' . $version;
	}
}

if (!$section) {
	fwrite(STDERR, "No '$heading' section in CHANGELOG.md for version $version.\n");
	fwrite(STDERR, "Add it, or bump version_string, before tagging.\n");
	exit(1);
}

// Trim leading and trailing blank lines.
while ($section && trim($section[0]) === '') {
	array_shift($section);
}
while ($section && trim($section[count($section) - 1]) === '') {
	array_pop($section);
}

$body = implode("\n", $section);

// Reference-style link definitions resolve only inside their own document, so in
// a release body they render as a bare, unlabelled URL. Dropped rather than
// carried over.
//
// The bracket class excludes `]` and newline both: written as [^]] it is greedy
// across lines and swallows the paragraph above the definition.
$body = preg_replace('#\n+\[[^\]\n]+\]:\s*\S+.*$#s', '', $body);

echo rtrim($body) . "\n";