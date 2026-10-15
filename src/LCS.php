<?php

/**
 * Longest Common Subsequence calculator.
 *
 * Improvement over legacy version: operates on mb_str_split() character
 * arrays instead of raw byte offsets, so multibyte UTF-8 input is compared
 * character-by-character rather than byte-by-byte.
 */
class LCS
{
    private array $a;
    private array $b;

    public function __construct(string $a, string $b)
    {
        $this->a = mb_str_split($a);
        $this->b = mb_str_split($b);
    }

    public function dynamic(): int
    {
        $a = $this->a;
        $b = $this->b;
        $lenA = count($a);
        $lenB = count($b);

        if ($lenA === 0 || $lenB === 0) {
            return 0;
        }

        // Single-row DP to keep memory O(min(lenA, lenB)) instead of O(lenA*lenB).
        if ($lenA < $lenB) {
            [$a, $b] = [$b, $a];
            [$lenA, $lenB] = [$lenB, $lenA];
        }

        $prevRow = array_fill(0, $lenB + 1, 0);

        for ($i = 1; $i <= $lenA; $i++) {
            $currRow = [0];
            for ($j = 1; $j <= $lenB; $j++) {
                if ($a[$i - 1] === $b[$j - 1]) {
                    $currRow[$j] = $prevRow[$j - 1] + 1;
                } else {
                    $currRow[$j] = max($prevRow[$j], $currRow[$j - 1]);
                }
            }
            $prevRow = $currRow;
        }

        return $prevRow[$lenB];
    }
}
