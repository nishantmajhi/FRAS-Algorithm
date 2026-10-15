<?php

/**
 * Standalone demo comparing legacy FRAS vs the rewritten FRAS on the same
 * inputs. Run with: php demo.php
 * Output is captured in README.md so this is illustrative, not a test.
 */

require_once __DIR__ . '/legacy/Levenshtein.php';
require_once __DIR__ . '/legacy/LCS.php';
require_once __DIR__ . '/legacy/FRAS.php';

require_once __DIR__ . '/src/Levenshtein.php';
require_once __DIR__ . '/src/LCS.php';
require_once __DIR__ . '/src/FRAS.php';

function printRanked(array $ranked, string $field): void
{
    foreach ($ranked as $r) {
        printf("  %-12s score=%.4f\n", $r[$field], $r['similarityScore']);
    }
}

echo "=== Demo 1: batch-dependence bug ===\n";
$query = 'John';
$small = [['name' => 'John'], ['name' => 'Jon']];
$large = [['name' => 'John'], ['name' => 'Jon'], ['name' => 'Supercalifragilisticexpialidocious Extra Padding Words']];

echo "-- Legacy FRAS, small batch --\n";
printRanked((new \Legacy\FRAS($query, $small))->look('name'), 'name');
echo "-- Legacy FRAS, same query/records + 1 unrelated outlier record --\n";
printRanked((new \Legacy\FRAS($query, $large))->look('name'), 'name');
echo "(Notice \"Jon\"'s score changes between runs even though nothing about it changed.)\n\n";

echo "-- Improved FRAS, small batch --\n";
printRanked((new FRAS($query, $small))->look('name'), 'name');
echo "-- Improved FRAS, same query/records + 1 unrelated outlier record --\n";
printRanked((new FRAS($query, $large))->look('name'), 'name');
echo "(\"Jon\"'s score is now identical in both runs.)\n\n";

echo "=== Demo 2: multibyte handling ===\n";
echo "Legacy Levenshtein('café', 'cafe')  = " . (new \Legacy\Levenshtein('café', 'cafe'))->dynamic() . " (byte-based, inflated)\n";
echo "Improved Levenshtein('café', 'cafe') = " . (new Levenshtein('café', 'cafe'))->dynamic() . " (character-based, correct)\n\n";

echo "=== Demo 3: adaptive weighting ===\n";
$records = [['sku' => 'ABC124'], ['sku' => 'ABX999']];

$shortQueryFras = new FRAS('AB', $records);
$shortQueryFras->look('sku');
echo "Query 'AB' (short, no profile)   -> weights = " . implode(', ', $shortQueryFras->getResolvedWeights()) . "\n";

$longQueryFras = new FRAS('ABC one two three four', $records);
$longQueryFras->look('sku');
echo "Query 'ABC one two three four' (long) -> weights = " . implode(', ', $longQueryFras->getResolvedWeights()) . "\n";

$skuProfileFras = new FRAS('AB', $records, null, null, null, 'sku');
$skuProfileFras->look('sku');
echo "Query 'AB' with profile='sku'    -> weights = " . implode(', ', $skuProfileFras->getResolvedWeights()) . "\n";
