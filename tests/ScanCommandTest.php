<?php
declare(strict_types=1);

namespace Reaper\Tests;

use PHPUnit\Framework\TestCase;
use Reaper\Cli\ScanCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ScanCommandTest extends TestCase
{
    public function testEndToEndScanOnFixtureSandbox(): void
    {
        $app = new Application();
        $cmd = new ScanCommand();
        $app->add($cmd);

        $tester = new CommandTester($app->find('scan'));
        $base = str_replace('\\','/', __DIR__ . '/Fixtures/sandbox');
        $ret = $tester->execute([
            '--config' => $base . '/reaper.yaml',
            '--root'   => $base,
            '--debug'  => true,
        ]);

        $this->assertSame(0, $ret);
        $display = $tester->getDisplay();

        $this->assertStringContainsString('Found 4 PHP files', $display);
        $this->assertStringContainsString('Code Reaper finished.', $display);

        // Outputs should exist under fixture/out
        $this->assertFileExists($base . '/out/dead_code.json');
        $this->assertFileExists($base . '/out/delete_list.txt');
        $this->assertFileExists($base . '/out/delete_list_high.txt');

        // Basic dead-code expectation: product(), Stringy class, debugDump()
        $json = json_decode(file_get_contents($base . '/out/dead_code.json'), true);
        $symbols = array_column($json['items'] ?? [], 'symbol');
        $this->assertTrue(
            count(array_filter($symbols, fn($s)=> strpos($s, 'App\\Utils\\Math::product') !== false)) === 1
        );
        $this->assertTrue(
            count(array_filter($symbols, fn($s)=> strpos($s, 'App\\Controllers\\HomeController::debugDump') !== false)) === 1
        );
        $this->assertTrue(
            in_array('App\\Utils\\Stringy', $symbols, true)
        );
    }
}
