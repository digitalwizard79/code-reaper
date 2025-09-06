<?php
declare(strict_types=1);

namespace Reaper\Tests;

use PHPUnit\Framework\TestCase;
use Reaper\Scanner\PhpScanner;

final class PhpScannerTest extends TestCase
{
    public function testScannerFindsSymbols(): void
    {
        $base = __DIR__ . '/Fixtures/sandbox';
        $files = [
            "$base/public/index.php",
            "$base/src/Controllers/HomeController.php",
            "$base/src/Utils/Math.php",
            "$base/src/Utils/Stringy.php",
        ];
        $scanner = new PhpScanner();
        $graph = $scanner->scan(array_map(fn($p)=>str_replace('\\','/',$p), $files));

        $nodes = $graph->nodes;

        // Classes
        $this->assertArrayHasKey('App\\Controllers\\HomeController', $nodes);
        $this->assertArrayHasKey('App\\Utils\\Math', $nodes);
        $this->assertArrayHasKey('App\\Utils\\Stringy', $nodes);

        // Methods
        $this->assertArrayHasKey('App\\Controllers\\HomeController::index', $nodes);
        $this->assertArrayHasKey('App\\Controllers\\HomeController::debugDump', $nodes);
        $this->assertArrayHasKey('App\\Utils\\Math::sum', $nodes);
        $this->assertArrayHasKey('App\\Utils\\Math::product', $nodes);
    }
}
