<?php
declare(strict_types=1);

namespace Reaper\Tests;

use PHPUnit\Framework\TestCase;
use Reaper\Graph\Graph;
use Reaper\Graph\Reachability;

final class ReachabilityTest extends TestCase
{
    public function testMarkFrom(): void
    {
        $g = new Graph();
        $g->addNode('A\\C', 'class', 'a.php', '1-10');
        $g->addNode('A\\C::m', 'method', 'a.php', '2-5');
        $g->addNode('B\\U::dead', 'method', 'b.php', '1-3');

        $g->addEdge('A\\C::m', 'B\\U::dead'); // reachable via m

        Reachability::markFrom($g, ['A\\C::m']);
        $this->assertArrayHasKey('A\\C::m', $g->reachable);
        $this->assertArrayHasKey('B\\U::dead', $g->reachable);
        $this->assertArrayNotHasKey('A\\C', $g->reachable); // class alone not auto-marked
    }
}
