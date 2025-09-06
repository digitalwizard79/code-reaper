<?php
declare(strict_types=1);

namespace Reaper\Tests;

use PHPUnit\Framework\TestCase;
use Reaper\Graph\Graph;
use Reaper\Reporter\Reporter;

final class ReporterTest extends TestCase
{
    public function testReportsAndDeleteLists(): void
    {
        $g = new Graph();
        // two dead symbols in same file, one in another file
        $g->addNode('X\\C', 'class', 'src/X/C.php', '1-10');
        $g->addNode('X\\C::dead1', 'method', 'src/X/C.php', '2-3');
        $g->addNode('X\\C::dead2', 'method', 'src/X/C.php', '4-5');
        $g->addNode('Y\\U::dead', 'method', 'src/Y/U.php', '1-2');
        // mark nothing reachable

        $out = sys_get_temp_dir() . '/reaper-test-' . uniqid();
        @mkdir($out);

        $summary = Reporter::writeReports($g, $out, 5, []);
        $this->assertFileExists("$out/dead_code.json");
        $this->assertFileExists("$out/delete_list.txt");
        $this->assertFileExists("$out/delete_list_high.txt");

        $list = file("$out/delete_list.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        sort($list);
        $this->assertSame(['src/X/C.php','src/Y/U.php'], $list, 'deduped, one per file');

        // High list should include both files (all symbols in both are dead and >= threshold)
        $high = file("$out/delete_list_high.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        sort($high);
        $this->assertSame(['src/X/C.php','src/Y/U.php'], $high);
    }
}
