<?php
namespace Reaper\Cli;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PurgeCommand extends Command
{
    public function __construct() { parent::__construct('purge'); }

    protected function configure(): void
    {
        $this
            ->setDescription('Delete files containing high-confidence dead code (safe by default; dry-run unless --apply).')
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Path to dead_code.json (from reaper scan)')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Deletion rule: all|any (all = safer)', 'all')
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED, 'Min confidence to consider a symbol dead', 5)
            ->addOption('include-glob', null, InputOption::VALUE_IS_ARRAY|InputOption::VALUE_REQUIRED, 'Only delete files matching these globs (repeatable)')
            ->addOption('exclude-glob', null, InputOption::VALUE_IS_ARRAY|InputOption::VALUE_REQUIRED, 'Never delete files matching these globs (repeatable)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually delete files (otherwise dry-run)')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'If in a git repo, switch/create this branch before deleting')
            ->addOption('commit-message', null, InputOption::VALUE_REQUIRED, 'If set (and in git), commit after deletion with this message')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation (non-interactive)')
            ->addOption('root', 'r', InputOption::VALUE_REQUIRED, 'Project root for resolving file paths (defaults to CWD)')
            ->addOption('debug', 'd', InputOption::VALUE_NONE, 'Verbose debug output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // ===== Parse options
        $reportPath = (string)$input->getOption('report');
        if ($reportPath === '' || !is_file($reportPath)) {
            $output->writeln('<error>--report is required and must point to dead_code.json</error>');
            return Command::FAILURE;
        }
        $root = (string)($input->getOption('root') ?: getcwd());
        $root = $this->norm($root);

        $mode = strtolower((string)$input->getOption('mode'));
        if (!in_array($mode, ['all','any'], true)) {
            $output->writeln('<error>--mode must be "all" or "any"</error>');
            return Command::FAILURE;
        }

        $threshold = (int)$input->getOption('threshold');
        $includes  = array_map([$this,'norm'], (array)$input->getOption('include-glob'));
        $excludes  = array_map([$this,'norm'], (array)$input->getOption('exclude-glob'));
        $apply     = (bool)$input->getOption('apply');
        $branch    = (string)($input->getOption('branch') ?: '');
        $commitMsg = (string)($input->getOption('commit-message') ?: '');
        $yes       = (bool)$input->getOption('yes');
        $debug     = (bool)$input->getOption('debug');

        // ===== Load report
        $json = json_decode(file_get_contents($reportPath), true);
        if (!is_array($json) || !isset($json['items']) || !is_array($json['items'])) {
            $output->writeln('<error>Invalid report: missing "items"</error>');
            return Command::FAILURE;
        }
        $items = $json['items'];

        if ($debug) {
            $output->writeln("Root: {$root}");
            $output->writeln("Report: ".$this->norm($reportPath));
            $output->writeln("Mode: {$mode}  Threshold: {$threshold}");
            if ($includes) $output->writeln("Include globs: ".implode(', ', $includes));
            if ($excludes) $output->writeln("Exclude globs: ".implode(', ', $excludes));
            $output->writeln('Items in report: '.count($items));
        }

        // ===== Group symbols by file
        $byFile = []; // file => [confidence, ...]
        foreach ($items as $it) {
            $file = isset($it['file']) ? $this->norm((string)$it['file']) : '';
            if ($file === '') continue;
            $byFile[$file][] = (int)($it['confidence'] ?? 0);
        }

        // ===== Select candidate files based on mode/threshold
        $candidates = []; // relative file paths (as in report)
        foreach ($byFile as $file => $scores) {
            if (!$scores) continue;
            $ok = $mode === 'all'
                ? (min($scores) >= $threshold)
                : (count(array_filter($scores, fn($c)=> $c >= $threshold)) > 0);
            if ($ok) $candidates[] = $file;
        }
        $candidates = array_values(array_unique($candidates));

        // ===== Include/Exclude filtering via fnmatch (path aware)
        $candidates = array_values(array_filter($candidates, function ($path) use ($includes, $excludes) {
            $path = $this->norm($path);
            $incOk = empty($includes) || $this->globMatchesAny($path, $includes);
            $excOk = empty($excludes) || !$this->globMatchesAny($path, $excludes);
            return $incOk && $excOk;
        }));

        // ===== Resolve to absolute paths under root (skip missing)
        $toDelete = []; // rel => abs
        foreach ($candidates as $rel) {
            $abs = rtrim($root, '/').'/'.ltrim($rel, '/');
            if (is_file($abs)) $toDelete[$rel] = $abs;
        }

        if ($debug) {
            $output->writeln('Candidate files after filters: '.count($toDelete));
            foreach (array_slice(array_keys($toDelete), 0, 20) as $rel) {
                $output->writeln(" - {$rel}");
            }
            if (count($toDelete) > 20) $output->writeln(' ...');
        }

        if (!$toDelete) {
            $output->writeln('<info>No files qualify for deletion.</info>');
            return Command::SUCCESS;
        }

        // ===== Dry-run summary
        $output->writeln("<comment>Dry-run plan:</comment> delete ".count($toDelete)." files");
        $shown = 0;
        foreach ($toDelete as $rel => $abs) {
            if ($shown++ >= 50) { $output->writeln(' ...'); break; }
            $output->writeln(" - {$rel}");
        }
        if (!$apply) {
            $output->writeln('<info>Use --apply to perform the deletion.</info>');
            return Command::SUCCESS;
        }

        // ===== Confirm unless --yes
        if (!$yes) {
            $output->writeln('<question>Proceed with deletion? (y/N)</question>');
            $answer = strtolower(trim((string)fgets(STDIN)));
            if (!in_array($answer, ['y','yes'], true)) {
                $output->writeln('<comment>Aborted.</comment>');
                return Command::SUCCESS;
            }
        }

        // ===== Git integration (best-effort, optional)
        $inGit = is_dir($root.'/.git');
        if ($inGit && $branch !== '') {
            $rc = 0; $_o = [];
            exec('git -C '.escapeshellarg($root).' checkout -B '.escapeshellarg($branch), $_o, $rc);
            if ($rc !== 0) {
                $output->writeln('<error>Failed to switch/create branch "'.$branch.'".</error>');
                return Command::FAILURE;
            }
        }

        // ===== Delete
        $failures = [];
        if ($inGit) {
            foreach ($toDelete as $rel => $abs) {
                $rc = 0; $_o = [];
                exec('git -C '.escapeshellarg($root).' rm -f -- '.escapeshellarg($rel), $_o, $rc);
                if ($rc !== 0) $failures[] = $rel;
            }
            if ($commitMsg !== '' && empty($failures)) {
                exec('git -C '.escapeshellarg($root).' add -A');
                $rc = 0; $_o = [];
                exec('git -C '.escapeshellarg($root).' commit -m '.escapeshellarg($commitMsg), $_o, $rc);
                if ($rc !== 0) $output->writeln('<comment>Note: commit failed; please commit manually.</comment>');
            }
        } else {
            foreach ($toDelete as $rel => $abs) {
                // Canonicalize for Windows; forward slashes are fine but realpath helps
                $absReal = realpath($abs) ?: $abs;

                // Try to ensure writable (Windows can be touchy with attributes)
                @chmod($absReal, 0666);

                // First attempt
                $ok = @unlink($absReal);

                // If still there, try once more after short gc/nudge
                if (!$ok && is_file($absReal)) {
                    // some environments need an extra nudge; try again
                    clearstatcache(true, $absReal);
                    @chmod($absReal, 0666);
                    $ok = @unlink($absReal);
                }

                // Final check — if it still exists, record failure
                if (!$ok && is_file($absReal)) {
                    $failures[] = $rel;
                }
            }
        }

        if ($failures) {
            $output->writeln('<error>Failed to delete '.count($failures).' file(s):</error>');
            foreach ($failures as $rel) $output->writeln(" - {$rel}");
            return Command::FAILURE;
        }

        $output->writeln('<info>Deleted '.count($toDelete).' file(s).</info>');
        return Command::SUCCESS;
    }

    // ===== Helpers
    /**
     * Check if $path matches any of the given globs (with ** support)
     * @param string $path
     * @param string[] $globs
     * @return bool
     */
    private function globMatchesAny(string $path, array $globs): bool
    {
        foreach ($globs as $g) {
            if ($this->globMatch($path, $g)) return true;
        }

        return false;
    }

    /**
     * Glob matcher with ** support:
     *  - **  => .*
     *  - *   => [^/]* 
     *  - ?   => [^/]
     * Anchored to full string.
     */
    private function globMatch(string $path, string $glob): bool
    {
        $path = $this->norm($path);
        $g = $this->norm($glob);

        // Escape regex specials, then restore our tokens
        $re = preg_quote($g, '#');

        // Turn '/**/' or '**' into '.*' (cross-directory)
        $re = str_replace('\\*\\*', '<<DSTAR>>', $re);
        // Single-star and question-mark (non-directory)
        $re = str_replace('\\*', '[^/]*', $re);
        $re = str_replace('\\?', '[^/]', $re);
        // Now restore ** as .*
        $re = str_replace('<<DSTAR>>', '.*', $re);

        // Anchor the regex to match the whole path
        $re = '#^' . $re . '$#';

        return (bool)preg_match($re, $path);
    }

    /**
     * Normalize path to use forward slashes
     * @param string $p
     * @return string
     */
    private function norm(string $p): string 
    { 
        return str_replace('\\', '/', $p); 
    }
}
