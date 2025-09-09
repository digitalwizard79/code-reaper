<?php
declare(strict_types=1);

namespace Reaper\Tests;

use PHPUnit\Framework\TestCase;
use Reaper\Cli\PurgeCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeCommandTest extends TestCase
{
    private function makeTempDir(): string
    {
        $base = sys_get_temp_dir() . '/reaper-purge-' . bin2hex(random_bytes(4));
        $this->assertTrue(@mkdir($base, 0777, true));
        return str_replace('\\', '/', realpath($base) ?: $base);
    }

    private function write(string $path, string $contents = ''): string
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            $this->assertTrue(@mkdir($dir, 0777, true), "Failed to create $dir");
        }
        $this->assertNotFalse(@file_put_contents($path, $contents), "Failed to write $path");
        return str_replace('\\', '/', realpath($path) ?: $path);
    }

    private function writeReport(string $dir, array $items): string
    {
        $report = [
            'summary' => ['scanned_files'=>0,'symbols_total'=>0,'dead_symbols'=>count($items),'high_confidence'=>0],
            'items'   => $items,
        ];
        $path = $dir . '/dead_code.json';
        $this->write($path, json_encode($report, JSON_PRETTY_PRINT));
        return $path;
    }

    private function makeApp(): Application
    {
        $app = new Application();
        $app->add(new PurgeCommand());
        return $app;
    }

    public function testDryRunSelectionAllThreshold(): void
    {
        $root = $this->makeTempDir();
        // Files
        $a = $this->write("$root/src/A.php", "<?php\n");
        $b = $this->write("$root/src/B.php", "<?php\n");
        // Report: A has [6,7] (all >=5) ; B has [6,3] (not all >=5)
        $report = $this->writeReport($root, [
            ['file' => 'src/A.php', 'symbol' => 'X\\A::m1', 'confidence' => 6],
            ['file' => 'src/A.php', 'symbol' => 'X\\A::m2', 'confidence' => 7],
            ['file' => 'src/B.php', 'symbol' => 'X\\B::m1', 'confidence' => 6],
            ['file' => 'src/B.php', 'symbol' => 'X\\B::m2', 'confidence' => 3],
        ]);

        $app = $this->makeApp();
        $tester = new CommandTester($app->find('purge'));
        $code = $tester->execute([
            '--report'   => $report,
            '--root'     => $root,
            '--mode'     => 'all',
            '--threshold'=> 5,
            '--debug'    => true,
            // no --apply (dry-run)
        ]);
        $this->assertSame(0, $code);
        $out = $tester->getDisplay();

        $this->assertStringContainsString('delete 1 files', $out);
        $this->assertStringContainsString('src/A.php', $out);
        $this->assertStringNotContainsString('src/B.php', $out);

        // Dry-run: nothing deleted
        $this->assertFileExists($a);
        $this->assertFileExists($b);
    }

    public function testApplyNoGitDeletesFiles(): void
    {
        $root = $this->makeTempDir();
        $c = $this->write("$root/lib/C.php", "<?php\n");
        $report = $this->writeReport($root, [
            ['file' => 'lib/C.php', 'symbol' => 'X\\C::dead', 'confidence' => 9],
        ]);

        $app = $this->makeApp();
        $tester = new CommandTester($app->find('purge'));
        $code = $tester->execute([
            '--report' => $report,
            '--root'   => $root,
            '--apply'  => true,
            '--yes'    => true,
        ]);
        $this->assertSame(0, $code);
        $this->assertFileDoesNotExist($c);
    }

    public function testIncludeExcludeAndAnyMode(): void
    {
        $root = $this->makeTempDir();
        $keep = $this->write("$root/src/Keep/Keep.php", "<?php\n");
        $dead = $this->write("$root/src/Dead/Dead.php", "<?php\n");
        $report = $this->writeReport($root, [
            ['file' => 'src/Keep/Keep.php', 'symbol' => 'K\\K::m', 'confidence' => 9],
            ['file' => 'src/Dead/Dead.php', 'symbol' => 'D\\D::m', 'confidence' => 9],
        ]);

        $app = $this->makeApp();
        $tester = new CommandTester($app->find('purge'));
        $code = $tester->execute([
            '--report'       => $report,
            '--root'         => $root,
            '--mode'         => 'any',
            '--threshold'    => 5,
            '--include-glob' => ['src/**'],
            '--exclude-glob' => ['src/Keep/**'],
            '--apply'        => true,
            '--yes'          => true,
        ]);
        $this->assertSame(0, $code);
        $this->assertFileExists($keep);
        $this->assertFileDoesNotExist($dead);
    }

    public function testApplyWithGitBranchAndCommit(): void
    {
        // Skip if git is unavailable
        @exec('git --version', $_, $rc);
        if ($rc !== 0) {
            $this->markTestSkipped('git not available on this environment');
        }

        $root = $this->makeTempDir();
        // init repo
        exec('git -C '.escapeshellarg($root).' init', $_o, $rc); $this->assertSame(0, $rc);
        exec('git -C '.escapeshellarg($root).' config user.email test@example.com');
        exec('git -C '.escapeshellarg($root).' config user.name  Test User');

        $d = $this->write("$root/src/ToDelete.php", "<?php\n");
        $keep = $this->write("$root/src/Keep.php", "<?php\n");
        // initial commit
        exec('git -C '.escapeshellarg($root).' add -A', $_o, $rc); $this->assertSame(0, $rc);
        exec('git -C '.escapeshellarg($root).' commit -m init', $_o, $rc); $this->assertSame(0, $rc);

        $report = $this->writeReport($root, [
            ['file' => 'src/ToDelete.php', 'symbol' => 'X\\Y::z', 'confidence' => 9],
            ['file' => 'src/Keep.php',     'symbol' => 'X\\Y::k', 'confidence' => 3], // below threshold
        ]);

        $app = $this->makeApp();
        $tester = new CommandTester($app->find('purge'));
        $code = $tester->execute([
            '--report'         => $report,
            '--root'           => $root,
            '--mode'           => 'any',
            '--threshold'      => 5,
            '--apply'          => true,
            '--yes'            => true,
            '--branch'         => 'reap/test',
            '--commit-message' => 'Reap: delete dead files',
        ]);
        $this->assertSame(0, $code);

        // File should be removed, keep should remain
        $this->assertFileDoesNotExist($d);
        $this->assertFileExists($keep);

        // Verify branch & commit
        exec('git -C '.escapeshellarg($root).' rev-parse --abbrev-ref HEAD', $out, $rc);
        $this->assertSame(0, $rc);
        $this->assertSame('reap/test', trim(implode("\n", $out)));

        exec('git -C '.escapeshellarg($root).' status --porcelain', $out2, $rc);
        $this->assertSame(0, $rc);
        $this->assertSame('', trim(implode("\n", $out2)), 'Working tree should be clean');

        exec('git -C '.escapeshellarg($root).' log -1 --pretty=%B', $out3, $rc);
        $this->assertSame(0, $rc);
        $this->assertStringContainsString('Reap: delete dead files', trim(implode("\n", $out3)));
    }
}
