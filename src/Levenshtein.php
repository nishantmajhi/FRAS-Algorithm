<?php

/**
 * Levenshtein distance calculator.
 *
 * Improvement over legacy version: operates on mb_str_split() character
 * arrays instead of raw byte offsets, so multibyte UTF-8 input (accented
 * letters, non-Latin scripts, emoji, etc.) is compared character-by-character
 * rather than byte-by-byte.
 */
class Levenshtein
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

        if ($lenA === 0) {
            return $lenB;
        }
        if ($lenB === 0) {
            return $lenA;
        }

        // Single-row DP to keep memory O(min(lenA, lenB)) instead of O(lenA*lenB).
        if ($lenA < $lenB) {
            [$a, $b] = [$b, $a];
            [$lenA, $lenB] = [$lenB, $lenA];
        }

        $prevRow = range(0, $lenB);

        for ($i = 1; $i <= $lenA; $i++) {
            $currRow = [$i];
            for ($j = 1; $j <= $lenB; $j++) {
                $cost = ($a[$i - 1] === $b[$j - 1]) ? 0 : 1;
                $currRow[$j] = min(
                    $prevRow[$j] + 1,       // deletion
                    $currRow[$j - 1] + 1,   // insertion
                    $prevRow[$j - 1] + $cost // substitution
                );
            }
            $prevRow = $currRow;
        }

        return $prevRow[$lenB];
    }
}
