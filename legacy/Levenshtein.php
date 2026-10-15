<?php

namespace Legacy;

class Levenshtein
{
    private string $str1;
    private string $str2;

    public function __construct(string $str1, string $str2)
    {
        $this->str1 = $str1;
        $this->str2 = $str2;
    }

    public function recursive(): int
    {
        return $this->computeRecursive(strlen($this->str1), strlen($this->str2));
    }

    private function computeRecursive(int $len1, int $len2): int
    {
        if ($len1 == 0) return $len2;
        if ($len2 == 0) return $len1;

        if ($this->str1[$len1 - 1] === $this->str2[$len2 - 1]) {
            return $this->computeRecursive($len1 - 1, $len2 - 1);
        }

        $insert = $this->computeRecursive($len1, $len2 - 1);
        $remove = $this->computeRecursive($len1 - 1, $len2);
        $replace = $this->computeRecursive($len1 - 1, $len2 - 1);

        return 1 + min($insert, $remove, $replace);
    }

    public function memoized(): int
    {
        $memo = [];
        return $this->computeMemoized(strlen($this->str1), strlen($this->str2), $memo);
    }

    private function computeMemoized(int $len1, int $len2, array &$memo): int
    {
        if (isset($memo[$len1][$len2])) {
            return $memo[$len1][$len2];
        }

        if ($len1 == 0) return $memo[$len1][$len2] = $len2;
        if ($len2 == 0) return $memo[$len1][$len2] = $len1;

        if ($this->str1[$len1 - 1] === $this->str2[$len2 - 1]) {
            return $memo[$len1][$len2] = $this->computeMemoized($len1 - 1, $len2 - 1, $memo);
        }

        $insert = $this->computeMemoized($len1, $len2 - 1, $memo);
        $remove = $this->computeMemoized($len1 - 1, $len2, $memo);
        $replace = $this->computeMemoized($len1 - 1, $len2 - 1, $memo);

        return $memo[$len1][$len2] = 1 + min($insert, $remove, $replace);
    }

    public function dynamic(): int
    {
        $len1 = strlen($this->str1);
        $len2 = strlen($this->str2);
        $dp = [];

        for ($i = 0; $i <= $len1; $i++) {
            $dp[$i][0] = $i;
        }

        for ($j = 0; $j <= $len2; $j++) {
            $dp[0][$j] = $j;
        }

        for ($i = 1; $i <= $len1; $i++) {
            for ($j = 1; $j <= $len2; $j++) {
                if ($this->str1[$i - 1] === $this->str2[$j - 1]) {
                    $dp[$i][$j] = $dp[$i - 1][$j - 1];
                } else {
                    $dp[$i][$j] = 1 + min(
                        $dp[$i - 1][$j],
                        $dp[$i][$j - 1],
                        $dp[$i - 1][$j - 1]
                    );
                }
            }
        }

        return $dp[$len1][$len2];
    }
}

/*
Explanation:

Class: Levenshtein
- Accepts two strings upon instantiation.
- Provides three public methods to compute Levenshtein distance.

Method: recursive()
- Pure recursive implementation.
- Exponential time complexity.
- Educational, not practical for large strings.

Method: memoized()
- Recursive approach with memoization (caching).
- Time complexity: O(m × n), where m and n are string lengths.
- Memory usage is higher but performance is much better.

Method: dynamic()
- Bottom-up dynamic programming.
- Uses a 2D table to iteratively build the solution.
- Most efficient and stable approach in practice.
- Time and space complexity: O(m × n).

All three approaches compute the same metric: the minimum number of insertions, deletions, or substitutions required to transform one string into another.
*/
