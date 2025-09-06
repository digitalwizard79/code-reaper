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
            ->addOption('entry', null, InputOption::VALUE_IS_ARRAY|InputOption::VALUE_REQUIRED, 'Extra entry file(s)')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Project root directory (for relative paths)', '.');
    }

    /**
     * Execute the scan command
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Load config
        $configPath = (string)$input->getOption('config');
        $cfg = Config::load($configPath);

        // Resolve baseDir (project root): --root wins; else directory of config file
        $baseDir = $input->getOption('root') ?: dirname(realpath($configPath));
        $baseDir = self::normalizePath((string)$baseDir);

        // Resolve output directory (absolute)
        $outDir = (string)($input->getOption('out') ?? $cfg->outputDir);
        if (!self::isAbsolutePath($outDir)) {
            $outDir = self::join($baseDir, $outDir);
        }

        // Resolve files to scan relative to baseDir (returns RELATIVE paths)
        $files = $this->resolveFiles($cfg->include, $cfg->exclude, $baseDir);

        // Debug info
        if ($input->getOption('debug')) {
            $output->writeln("Root: " . $baseDir);
            $output->writeln("Found " . count($files) . " PHP files");
            foreach (array_slice($files, 0, 100) as $f) {
                $output->writeln(" - " . $f);
            }
        }

        // If nothing to scan, still write empty reports and exit cleanly
        if (count($files) === 0) {
            @mkdir($outDir, 0777, true);
            file_put_contents($outDir.'/dead_code.json', json_encode([
                'summary' => [
                    'scanned_files'   => 0,
                    'symbols_total'   => 0,
                    'dead_symbols'    => 0,
                    'high_confidence' => 0,
                ],
                'items' => [],
            ], JSON_PRETTY_PRINT));
            file_put_contents($outDir.'/delete_list.txt', '');
            file_put_contents($outDir.'/delete_list_high.txt', '');
            $output->writeln("Code Reaper finished.\n\nScanned 0 files.\n  0 symbols\n  0 dead (0 high-confidence).\nOutput -> ".$outDir);
            return Command::SUCCESS;
        }

        // Make absolute file paths for the scanner
        $absFiles = array_map(fn($rel) => self::join($baseDir, $rel), $files);

        // Scan
        $scanner = new PhpScanner();
        $graph = $scanner->scan($absFiles);

        // Entry files: merge config + CLI, make absolute
        $entryFiles = array_merge($cfg->entryFiles, (array)$input->getOption('entry'));
        $entryFiles = array_values(array_filter($entryFiles, fn($p) => strlen((string)$p) > 0));
        $entryFiles = array_map(function ($p) use ($baseDir) {
            $p = self::normalizePath((string)$p);
            return self::isAbsolutePath($p) ? $p : self::join($baseDir, $p);
        }, $entryFiles);

        // Seed reachability from symbols defined in entry files
        $start = $this->symbolsFromFiles($graph->nodes, $entryFiles);

        // Heuristic: if nothing seeded, include symbols from directories that contain entry files
        if (!$start && $entryFiles) {
            $entryDirs = array_unique(array_map(static fn($f) => rtrim(dirname(self::normalizePath($f)), '/'), $entryFiles));
            foreach ($graph->nodes as $sym => $meta) {
                foreach ($entryDirs as $dir) {
                    if (str_starts_with(self::normalizePath($meta['file']), $dir . '/')) {
                        $start[] = $sym;
                        break;
                    }
                }
            }
            $start = array_values(array_unique($start));
        }

        Reachability::markFrom($graph, $start);

        // Write reports
        $summary = Reporter::writeReports($graph, $outDir, $cfg->deleteThreshold, $cfg->keepPatterns);

        // Human summary
        $output->writeln(sprintf(
            "Code Reaper finished.\n\nScanned %d files.\n  %d symbols\n  %d dead (%d high-confidence).\nOutput -> %s",
            $summary['scanned_files'],
            $summary['symbols_total'],
            $summary['dead_symbols'],
            $summary['high_confidence'],
            $outDir
        ));

        return Command::SUCCESS;
    }

    /**
     * @param string[] $includes  e.g. ["src", "public"] or file paths
     * @param string[] $excludes  e.g. ["vendor", "tests", "migrations/**"]
     * @param string   $baseDir   directory of the config (or --root)
     * @return string[]           relative paths from $baseDir (de-duped)
     */
    private function resolveFiles(array $includes, array $excludes, string $baseDir): array
    {
        $finder = new Finder();
        $finder->files()->name('*.php');

        $roots = $includes ?: []; // ← do NOT default to '.'
        $roots = array_map(fn($p) => rtrim(self::normalizePath($p), '/'), $roots);

        foreach ($roots as $root) {
            $abs = self::isAbsolutePath($root) ? $root : self::join($baseDir, $root);
            if (is_dir($abs)) {
                $finder->in($abs);
            } elseif (is_file($abs)) {
                $finder->append([$abs]);
            } else {
                // Skip invalid include entries; don't silently scan '.'
                continue;
            }
        }

        // Exclusions
        foreach ($excludes as $ex) {
            $ex = rtrim(self::normalizePath($ex), '/');
            if ($ex === 'vendor' || $ex === 'vendor/**') { $finder->exclude('vendor'); continue; }
            if ($ex === 'tests'  || $ex === 'tests/**')  { $finder->exclude('tests');  continue; }
            // Generic path/pattern relative to baseDir
            $finder->notPath(self::isAbsolutePath($ex) ? $ex : self::join($baseDir, $ex));
        }

        // Collect as canonical, de-duped, relative-to-baseDir
        $seen = [];
        $files = [];
        foreach ($finder as $file) {
            $abs = realpath($file->getPathname()) ?: $file->getPathname();
            $abs = self::normalizePath($abs);
            if (isset($seen[$abs])) continue;
            $seen[$abs] = true;
            $files[] = self::toRelative($abs, $baseDir);
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

    private static function toRelative(string $absPath, string $baseDir): string
    {
        $absPath = self::normalizePath($absPath);
        $baseDir = rtrim(self::normalizePath(realpath($baseDir) ?: $baseDir), '/');
        if (str_starts_with($absPath, $baseDir . '/')) {
            return substr($absPath, strlen($baseDir) + 1); // no leading "./"
        }
        return $absPath; // fallback: absolute
    }

    private static function normalizePath(string $p): string {
        return str_replace('\\', '/', $p);
    }

    private static function isAbsolutePath(string $p): bool {
        $p = self::normalizePath($p);
        return preg_match('#^([A-Za-z]:/|//|/)#', $p) === 1; // C:/, //server/, or /root
    }

    private static function join(string ...$parts): string {
        $parts = array_map(fn($x) => trim(self::normalizePath($x), '/'), $parts);
        $first = array_shift($parts);
        return $first . (count($parts) ? '/' . implode('/', $parts) : '');
    }
}