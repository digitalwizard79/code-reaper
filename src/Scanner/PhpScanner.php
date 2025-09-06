<?php
namespace Reaper\Scanner;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\NodeVisitorAbstract;
use Reaper\Graph\Graph;

final class PhpScanner
{
    private \PhpParser\Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
    }

    /**
     * Scan PHP files to build a call/dependency graph
     * @param string[] $files List of PHP file paths to scan
     * @return \Reaper\Graph\Graph
     */
    public function scan(array $files): Graph
    {
        $graph = new Graph();

        foreach ($files as $file) {
            $code = @file_get_contents($file);
            if ($code === false) continue;

            try {
                $ast = $this->parser->parse($code) ?? [];
            } catch (\Throwable $e) {
                // Skip unparsable files
                continue;
            }

            $visitor = new class extends NodeVisitorAbstract {
                public string $ns = '';
                /** @var array<string,string> symbol => "start-end" */
                public array $classes = [];
                /** @var array<string,string> */
                public array $methods = [];
                /** @var array<string,string> */
                public array $functions = [];
                /** @var string[] fully-qualified callee names or "::method" suffixes */
                public array $calls = [];
                /** @var string[] fully-qualified class names instantiated */
                public array $instantiates = [];
                private ?string $currentClass = null;

                private function lines(Node $n): string {
                    return $n->getStartLine() . '-' . $n->getEndLine();
                }
                private function qualify(string $name): string {
                    if ($this->ns === '' || str_contains($name, '\\')) return $name;
                    return $this->ns . '\\' . $name;
                }

                public function enterNode(Node $node)
                {
                    if ($node instanceof Node\Stmt\Namespace_) {
                        $this->ns = $node->name ? $node->name->toString() : '';
                    }

                    if ($node instanceof Node\Stmt\Class_) {
                        $this->currentClass = $node->name ? $node->name->toString() : null;
                        if ($this->currentClass) {
                            $sym = $this->qualify($this->currentClass);
                            $this->classes[$sym] = $this->lines($node);
                        }
                    }

                    if ($node instanceof Node\Stmt\Function_) {
                        $fname = $node->name->toString();
                        $sym = $this->qualify($fname);
                        $this->functions[$sym] = $this->lines($node);
                    }

                    if ($node instanceof Node\Stmt\ClassMethod && $this->currentClass) {
                        $mname = $node->name->toString();
                        $sym = $this->qualify($this->currentClass) . '::' . $mname;
                        $this->methods[$sym] = $this->lines($node);
                    }

                    if ($node instanceof Node\Expr\FuncCall) {
                        $name = $node->name instanceof Node\Name ? $node->name->toString() : null;
                        if ($name) $this->calls[] = $this->qualify($name);
                    }

                    if ($node instanceof Node\Expr\MethodCall) {
                        $m = $node->name instanceof Node\Identifier ? $node->name->toString() : null;
                        if ($m) $this->calls[] = '::' . $m; // resolve by suffix later
                    }

                    if ($node instanceof Node\Expr\StaticCall) {
                        $class = $node->class instanceof Node\Name ? $node->class->toString() : null;
                        $m = $node->name instanceof Node\Identifier ? $node->name->toString() : null;
                        if ($class && $m) $this->calls[] = $this->qualify($class) . '::' . $m;
                    }

                    if ($node instanceof Node\Expr\New_) {
                        $class = $node->class instanceof Node\Name ? $node->class->toString() : null;
                        if ($class) $this->instantiates[] = $this->qualify($class);
                    }
                }

                public function leaveNode(Node $node)
                {
                    if ($node instanceof Node\Stmt\Class_) {
                        $this->currentClass = null;
                    }
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            // Register nodes
            foreach ($visitor->functions as $sym => $lns) {
                $graph->addNode($sym, 'function', $file, $lns);
            }
            foreach ($visitor->classes as $sym => $lns) {
                $graph->addNode($sym, 'class', $file, $lns);
            }
            foreach ($visitor->methods as $sym => $lns) {
                $graph->addNode($sym, 'method', $file, $lns);
            }

            // Build edges (approximate "from": any symbol declared in this file may call)
            $froms = array_merge(
                array_keys($visitor->functions),
                array_keys($visitor->methods),
                array_keys($visitor->classes)
            );

            // Direct matches (function, static calls)
            foreach ($visitor->calls as $callee) {
                foreach (array_keys($graph->nodes) as $maybe) {
                    if ($callee === $maybe) {
                        foreach ($froms as $f) $graph->addEdge($f, $maybe);
                    } elseif (str_starts_with($callee, '::') && str_ends_with($maybe, $callee)) {
                        foreach ($froms as $f) $graph->addEdge($f, $maybe);
                    }
                }
            }

            // Class instantiation → class reachable
            foreach ($visitor->instantiates as $cls) {
                if (isset($graph->nodes[$cls])) {
                    foreach ($froms as $f) $graph->addEdge($f, $cls);
                }
            }
        }

        return $graph;
    }
}
