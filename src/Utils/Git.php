<?php
namespace Reaper\Utils;

/**
 * Utility class for Git operations
 */
class Git
{
    /**
     * Check if a directory is a git repository
     * @param string $root
     * @return bool
     */
    public static function isRepo(string $root): bool
    {
        return is_dir($root . '/.git');
    }

    /**
     * Check if there are uncommitted changes in the git repository
     * @param string $root
     * @return bool
     * @throws \RuntimeException on git error
     */
    public static function isDirty(string $root): bool
    {
        exec('git -C ' . escapeshellarg($root) . ' status --porcelain', $output, $rc);
        if ($rc !== 0) {
            throw new \RuntimeException('Failed to check git status.');
        }
        return !empty($output);
    }

    /**
     * Get the current git branch name
     * @param string $root
     * @return string|null
     * @throws \RuntimeException on git error
     */
    public static function currentBranch(string $root): ?string
    {
        exec('git -C ' . escapeshellarg($root) . ' rev-parse --abbrev-ref HEAD', $output, $rc);
        if ($rc !== 0) {
            throw new \RuntimeException('Failed to get current git branch.');
        }
        return $output[0] ?? null;
    }

    /**
     * Checkout an existing git branch
     * @param string $root
     * @param string $branch
     * @throws \RuntimeException on git error
     */
    public static function checkoutBranch(string $root, string $branch): void
    {
        exec('git -C ' . escapeshellarg($root) . ' checkout ' . escapeshellarg($branch), $output, $rc);
        if ($rc !== 0) {
            throw new \RuntimeException('Failed to checkout git branch: ' . $branch);
        }
    }

    /**
     * Remove files via git
     * @param string $root
     * @param string[] $files
     * @return string[] List of files that failed to be removed
     */
    public static function rm(string $root, array $files): array
    {
        $failures = [];
        foreach ($files as $file) {
            exec('git -C ' . escapeshellarg($root) . ' rm -rf -- ' . escapeshellarg($file), $_o, $rc);
            if ($rc !== 0) {
                $failures[] = $file;
            }
        }
        return $failures;
    }

    /**
     * Commit staged changes
     * @param string $root
     * @param string $msg
     * @throws \RuntimeException on git error
     */
    public static function commit(string $root, string $msg): void
    {
        exec('git -C ' . escapeshellarg($root) . ' add -A');
        exec('git -C ' . escapeshellarg($root) . ' commit -m ' . escapeshellarg($msg), $_o, $rc);
        if ($rc !== 0) {
            throw new \RuntimeException('Failed to commit changes.');
        }
    }

    /**
     * Create and/or switch to a git branch
     * @param string $root
     * @param string $branch
     * @throws \RuntimeException on git error
     */
    public static function createBranch(string $root, string $branch): void
    {
        exec('git -C ' . escapeshellarg($root) . ' checkout -B ' . escapeshellarg($branch), $output, $rc);
        if ($rc !== 0) {
            throw new \RuntimeException('Failed to create/switch to git branch: ' . $branch);
        }
    }
}