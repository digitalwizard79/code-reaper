<?php

namespace Reaper\Cli;

use Reaper\Config\Config;
use Reaper\Scanner\PHPScanner;
use Reaper\Graph\Reachability;
use Reaper\Reporter\Reporter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

final class ScanCommand extends Command
{
    protected static $defaultName = 'scan';

    public function __construct()
    {
        // Explicitly set the comand name
        parent::__construct('scan');
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Code Reaper: Scan your PHP project for dead code (static).')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to reaper.yaml', 'reaper.yaml')
            ->addOption('out', 'o', InputOption::VALUE_REQUIRED, 'Output directory (overrides config)')
            ->addOption('debug', 'd', InputOption::VALUE_NONE, 'Debug output')
            ->addOption('entry', null, InputOption::VALUE_IS_ARRAY|InputOption::VALUE_REQUIRED, 'Extra entry file(s)');
    }

    /**
     * Execute the scan command
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cfg = Config::load($input->getOption('config'));
        $outDir = $input->getOption('out') ?? $cfg->outputDir;

        $entryFiles = array_merge($cfg->entryFiles, $input->getOption('entry') ?? []);
        if (!$entryFiles) {
            $output->writeln('<comment>No entry points specified. Use reaper.yaml entry_points.files or --entry.</comment>');
        }

        $files = $this->resolveFiles($cfg->include, $cfg->exclude);

        if ($input->getOption('debug')) {
            $output->writeln("Found ".count($files)." PHP files");
            foreach (array_slice($files, 0, 20) as $f) $output->writeln(" - $f");
        }

        $scanner = new PhpScanner();
        $graph = $scanner->scan($files);

        // Seed reachability from all symbols defined in entry files
        $start = $this->symbolsFromFiles($graph->nodes, $entryFiles);

        if (!$start && $entryFiles) {
            $byFile = array_flip($this->normalize($entryFiles));
            foreach ($graph->nodes as $sym => $meta) {
                foreach ($byFile as $ef => $_) {
                    if (strpos($meta['file'], dirname($ef)) === 0) {
                        $start[] = $sym;
                    }
                }
            }
            $start = array_values(array_unique($start));
        }

        Reachability::markFrom($graph, $start);

        $summary = Reporter::writeReports($graph, $outDir, $cfg->deleteThreshold, $cfg->keepPatterns);

        $output->writeln(
            sprintf(
            "Code Reaper finished.\n\nScanned %d files.\n  %d symbols\n  %d dead (%d high-confidence).\nOutput -> %s",
            $summary['scanned_files'], $summary['symbols_total'], $summary['dead_symbols'], $summary['high_confidence'], $outDir
            )
        );

        return Command::SUCCESS;
    }

    /**
     * Resolve PHP files to scan based on include/exclude patterns
     * @param string[] $includes // Prefer directory roots like "src", "app", "public"
     * @param string[] $excludes // Directories or path globs like "vendor", "tests"
     * @return string[]
     */
    private function resolveFiles(array $includes, array $excludes): array
    {
        $finder = new Finder();
        $finder->files()->name('*.php');

        // If no includes provided, default to current dir
        $roots = $includes ?: ['.'];

        // Normalize to directory roots (if someone passes "src/**", trim to "src")
        $roots = array_map(function ($p) {
            $p = rtrim(str_replace('\\', '/', $p), '/');
            if (substr($p, -3) === '/**') {
                $p = substr($p, 0, -3);
            }
            return $p;
        }, $roots);

        foreach ($roots as $root) {
            if (is_dir($root)) {
                $finder->in($root);
            } elseif (is_file($root)) {
                // Single file include
                $finder->append([$root]);
            } else {
                // If it's a pattern, search from cwd and filter later
                $finder->in('.');
            }
        }

        foreach ($excludes as $ex) {
            $ex = rtrim(str_replace('\\', '/', $ex), '/');
            // Support common patterns like "vendor", "tests"
            if ($ex === 'vendor' || $ex === 'vendor/**') {
                $finder->exclude('vendor');
            } elseif ($ex === 'tests' || $ex === 'tests/**') {
                $finder->exclude('tests');
            } else {
                // Generic path-not-match
                $finder->notPath($ex);
            }
        }

        $files = [];
        foreach ($finder as $file) {
            $files[] = str_replace('\\', '/', $file->getPathname());
        }
        sort($files);
        return $files;
    }

    /**
     * Get all symbols defined in the given files
     * @param array<string, array{kind:string,file:string,lines:string}> $nodes
     * @param string[] $files
     * @return string[]
     */
    private function symbolsFromFiles(array $nodes, array $files): array
    {
        $set = [];
        $fileSet = array_flip($this->normalize($files));
        foreach ($nodes as $sym => $meta) {
            if (isset($fileSet[$meta['file']])) $set[] = $sym;
        }
        return $set;
    }

    /**
     * Normalize file paths to use forward slashes and no trailing slash
     * @param array $files
     * @return string[]
     */
    private function normalize(array $files): array
    {
        return array_map(static fn($p) => rtrim(str_replace(['\\'], ['/'], $p), '/'), $files);
    }
}