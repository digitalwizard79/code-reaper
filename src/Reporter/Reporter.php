<?php
namespace Reaper\Reporter;

use Reaper\Graph\Graph;

final class Reporter
{
    /**
     * Generate reports of dead code findings
     * @param \Reaper\Graph\Graph $g
     * @param string $outDir
     * @param int $deleteThreshold
     * @param array $keepPatterns
     * @return array{dead_symbols: int, high_confidence: int, scanned_files: int, symbols_total: int}
     */
    public static function writeReports(Graph $g, string $outDir, int $deleteThreshold, array $keepPatterns): array
    {
        if (!is_dir($outDir)) mkdir($outDir, 0777, true);

        $dead = [];
        $high = 0;

        foreach ($g->nodes as $sym => $meta) {
            if (isset($g->reachable[$sym])) continue;

            $confidence = 0;
            $reasons = [];

            $confidence += 4;
            $reasons[] = 'no inbound calls';
            if ($meta['kind'] !== 'function') {
                $confidence += 1;
                $reasons[] = 'non-function symbol';
            }

            foreach ($keepPatterns as $pat) {
                if (@preg_match($pat, $sym) && preg_match($pat, $sym)) {
                    $confidence -= 3;
                    $reasons[] = 'matches keep pattern';
                }
            }

            if ($confidence >= $deleteThreshold) $high++;

            $dead[] = [
                'symbol' => $sym,
                'kind' => $meta['kind'],
                'file' => $meta['file'],
                'confidence' => $confidence,
                'reasons' => $reasons,
                'lines' => $meta['lines']
            ];
        }

        usort($dead, fn($a,$b) => $b['confidence'] <=> $a['confidence']);

        $json = [
            'summary' => [
                'scanned_files'   => self::countUniqueFiles($g),
                'symbols_total'   => count($g->nodes),
                'dead_symbols'    => count($dead),
                'high_confidence' => $high
            ],
            'items' => $dead
        ];

        // Write JSON
        file_put_contents($outDir.'/dead_code.json', json_encode($json, JSON_PRETTY_PRINT));

        // Deduplicate file paths for delete list
        $uniqueFiles = array_unique(array_map(fn($i) => $i['file'], $dead));
        sort($uniqueFiles);
        file_put_contents($outDir.'/delete_list.txt', implode(PHP_EOL, $uniqueFiles));

        // Files where *all* symbols are high-confidence dead
        $filesHigh = [];
        $byFile = [];
        foreach ($dead as $item) {
            $byFile[$item['file']][] = $item;
        }
        foreach ($byFile as $file => $symbols) {
            $allHigh = true;
            foreach ($symbols as $sym) {
                if ($sym['confidence'] < $deleteThreshold) {
                    $allHigh = false;
                    break;
                }
            }
            if ($allHigh) {
                $filesHigh[] = $file;
            }
        }
        sort($filesHigh);
        file_put_contents($outDir.'/delete_list_high.txt', implode(PHP_EOL, $filesHigh));

        return $json['summary'];
    }

    /**
     * Count unique files in the graph nodes
     * @param Graph $g
     * @return int
     */
    private static function countUniqueFiles(Graph $g): int
    {
        $set = [];
        foreach ($g->nodes as $n) {
            $set[$n['file']] = true;
        }
        return count($set);
    }
}