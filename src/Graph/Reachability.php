<?php
namespace Reaper\Graph;

final class Reachability
{
    /**
     * Mark all symbols reachable from the given start symbols
     * @param \Reaper\Graph\Graph $g
     * @param string[] $startSymbols
     * @return void
     */
    public static function markFrom(Graph $g, array $startSymbols): void
    {
        $q = [];
        foreach ($startSymbols as $s) {
            if (isset($g->nodes[$s])) {
                $g->reachable[$s] = true;
                $q[] = $s;
            }
        }
        while ($q) {
            $u = array_pop($q);
            foreach (array_keys($g->edges[$u] ?? []) as $v) {
                if (!isset($g->reachable[$v]) && isset($g->nodes[$v])) {
                    $g->reachable[$v] = true;
                    $q[] = $v;
                }
            }
        }
    }
}