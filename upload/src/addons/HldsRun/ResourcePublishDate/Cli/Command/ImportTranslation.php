<?php

namespace HldsRun\ResourcePublishDate\Cli\Command;

use HldsRun\ResourcePublishDate\PublishDateAddOn;
use HldsRun\ResourcePublishDate\Service\TranslationInstaller;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use XF\Cli\Command\AbstractCommand;
use XF\Entity\Language;

/**
 * Installs one of the add-on's translations into a XenForo language.
 *
 * XenForo's add-on `_data/phrases.xml` is always language_id = 0 (the "master"
 * language). Translations live in xf_phrase rows with a real language_id and have
 * to be imported explicitly - which is exactly what XF\Service\Phrase\ImportService
 * does, the same service the core language-pack importer uses. Reusing it means
 * the import honours the phrase options, avoids duplicates and enqueues the usual
 * language rebuild jobs.
 *
 * Usage:
 *   php src/cmd.php hlds-run-rpd:import-translation                 # list languages
 *   php src/cmd.php hlds-run-rpd:import-translation 6 --dry-run
 *   php src/cmd.php hlds-run-rpd:import-translation 6
 *
 * @see docs/TRANSLATIONS.md
 */
class ImportTranslation extends AbstractCommand
{
    protected function configure()
    {
        $this
            ->setName('hlds-run-rpd:import-translation')
            // A literal, not \XF::phrase() - the reason is long and load-bearing,
            // so it is written out once, in BackfillPublishDates.
            ->setDescription('Imports one of this add-on\'s translations into a XenForo language.')
            ->addArgument(
                'language_id',
                InputArgument::OPTIONAL,
                'ID of the language to import into. Omit to list the available languages.'
            )
            ->addOption(
                'file',
                'f',
                InputOption::VALUE_REQUIRED,
                'Translation file name, without the .xml extension.',
                PublishDateAddOn::DEFAULT_TRANSLATION
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Report what would be imported without writing anything.'
            )
            ->setHelp(
                'Imports <info>_translations/{file}.xml</info> from the add-on directory.'
                . "\nRe-importing replaces the previous translation of this add-on for that language."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $languageId = $input->getArgument('language_id');

        if ($languageId === null) {
            $this->listLanguages($output);

            return 0;
        }

        /** @var Language|null $language */
        $language = \XF::em()->findOne(Language::class, ['language_id' => (int) $languageId]);

        if (!$language) {
            $output->writeln('<error>' . \XF::phrase(
                'hlds_run_rpd_cli_import_translation_language_missing',
                ['id' => (int) $languageId]
            ) . '</error>');

            return 1;
        }

        $fileName = (string) $input->getOption('file');
        /** @var TranslationInstaller $installer */
        $installer = \XF::service(TranslationInstaller::class);

        $path = PublishDateAddOn::getTranslationPath($fileName);

        if ($path === null || !is_file($path)) {
            $output->writeln('<error>' . \XF::phrase(
                'hlds_run_rpd_cli_import_translation_missing_file',
                ['file' => $fileName . '.xml']
            ) . '</error>');

            return 1;
        }

        $xml = $this->loadPhraseFile($path);

        if ($xml === null) {
            $output->writeln('<error>' . \XF::phrase(
                'hlds_run_rpd_cli_import_translation_invalid_file',
                ['file' => $fileName . '.xml']
            ) . '</error>');

            return 1;
        }

        $count = count($xml->phrase);

        if ($count === 0) {
            $output->writeln(\XF::phrase(
                'hlds_run_rpd_cli_import_translation_empty',
                ['title' => $language->title]
            ));

            return 0;
        }

        if ($input->getOption('dry-run')) {
            $output->writeln(\XF::phrase('hlds_run_rpd_cli_import_translation_dry_run', [
                'count' => $count,
                'title' => $language->title,
            ]));

            return 0;
        }

        // The shared service does the write, so the automatic install-time import
        // and this command cannot drift into two implementations of "which file
        // belongs to which language".
        $installer->install($fileName, $language);

        $output->writeln(\XF::phrase('hlds_run_rpd_cli_import_translation_done', [
            'count' => $count,
            'title' => $language->title,
        ]));

        return 0;
    }

    /**
     * @return \SimpleXMLElement|null
     */
    private function loadPhraseFile(string $path)
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($contents);
        libxml_use_internal_errors($previous);

        if ($xml === false || !isset($xml->phrase)) {
            return null;
        }

        return $xml;
    }

    private function listLanguages(OutputInterface $output): void
    {
        $languages = \XF::finder(Language::class)->order('title')->fetch();

        if (!$languages->count()) {
            $output->writeln('<error>'
                . \XF::phrase('hlds_run_rpd_cli_import_translation_no_language')
                . '</error>');

            return;
        }

        // The shipped column is the point of this listing. The most common reason
        // somebody runs this command is that no translation appeared after install,
        // and the answer is usually visible here: the language exists, but the
        // board calls it something no shipped file matches.
        /** @var TranslationInstaller $installer */
        $installer = \XF::service(TranslationInstaller::class);
        $shipped = $installer->getAvailableCodes();

        $rows = [];
        foreach ($languages as $language) {
            $match = null;
            foreach ($shipped as $code) {
                if ($installer->findLanguageForCode($code) === $language) {
                    $match = $code . '.xml';
                    break;
                }
            }

            $rows[] = [
                $language->language_id,
                $language->title,
                $language->language_code ?: '-',
                $match ?: 'none',
            ];
        }

        // Table, not $output->table(): that method is on the Table helper, not on
        // OutputInterface. OutputInterface::table() does not exist and calling it
        // fatals.
        (new Table($output))
            ->setHeaders(['ID', 'Language', 'Code', 'Shipped translation'])
            ->setRows($rows)
            ->render();

        $output->writeln(
            'Re-run with a language ID, for example: '
            . '<info>php src/cmd.php hlds-run-rpd:import-translation ' . $rows[0][0] . '</info>'
        );
    }
}
