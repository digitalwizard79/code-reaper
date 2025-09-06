<?php
namespace Reaper\Graph;

final class Graph
{
    /** @var array<string, array{kind:string,file:string,lines:string}> */
    public array $nodes = []; // symbol => meta

    /** @var array<string, array<string,bool>> */
    public array $edges = []; // from => [to => true]

    /** @var array<string, bool> */
    public array $reachable = [];

    /**
     * Add a node (symbol) to the graph
     * @param string $symbol Fully-qualified symbol name
     * @param string $kind 'class', 'method', 'function', etc.
     * @param string $file File where the symbol is defined
     * @param string $lines Line range in "start-end" format
     * @return void
     */
    public function addNode(string $symbol, string $kind, string $file, string $lines): void
    {
        $this->nodes[$symbol] = ['kind' => $kind, 'file' => $file, 'lines' => $lines];
    }

    /**
     * Add a directed edge (call/dependency) from one symbol to another
     * @param string $from Caller or dependent symbol
     * @param string $to Callee or dependency symbol
     * @return void
     */
    public function addEdge(string $from, string $to): void
    {
        if (!isset($this->edges[$from])) {
            $this->edges[$from] = [];
        }
        $this->edges[$from][$to] = true;
    }
}