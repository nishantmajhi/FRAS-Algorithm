<?php

namespace Legacy;

class LCS
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
        if ($len1 == 0 || $len2 == 0) {
            return 0;
        }

        if ($this->str1[$len1 - 1] === $this->str2[$len2 - 1]) {
            return 1 + $this->computeRecursive($len1 - 1, $len2 - 1);
        }

        return max(
            $this->computeRecursive($len1 - 1, $len2),
            $this->computeRecursive($len1, $len2 - 1)
        );
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

        if ($len1 == 0 || $len2 == 0) {
            return $memo[$len1][$len2] = 0;
        }

        if ($this->str1[$len1 - 1] === $this->str2[$len2 - 1]) {
            return $memo[$len1][$len2] = 1 + $this->computeMemoized($len1 - 1, $len2 - 1, $memo);
        }

        return $memo[$len1][$len2] = max(
            $this->computeMemoized($len1 - 1, $len2, $memo),
            $this->computeMemoized($len1, $len2 - 1, $memo)
        );
    }

    public function dynamic(): int
    {
        $len1 = strlen($this->str1);
        $len2 = strlen($this->str2);
        $dp = [];

        for ($i = 0; $i <= $len1; $i++) {
            $dp[$i][0] = 0;
        }

        for ($j = 0; $j <= $len2; $j++) {
            $dp[0][$j] = 0;
        }

        for ($i = 1; $i <= $len1; $i++) {
            for ($j = 1; $j <= $len2; $j++) {
                if ($this->str1[$i - 1] === $this->str2[$j - 1]) {
                    $dp[$i][$j] = 1 + $dp[$i - 1][$j - 1];
                } else {
                    $dp[$i][$j] = max($dp[$i - 1][$j], $dp[$i][$j - 1]);
                }
            }
        }

        return $dp[$len1][$len2];
    }
}

/*
Explanation:

Class: LCS
- Accepts two strings upon instantiation.
- Provides three public methods to compute Longest Common Subsequence.

Method: recursive()
- Pure recursive implementation.
- Time complexity is exponential (O(2^n)).
- Not suitable for large strings, but illustrates the basic principle.

Method: memoized()
- Recursive with memoization.
- Caches intermediate results to avoid redundant computations.
- Time and space complexity: O(m × n), where m and n are lengths of input strings.

Method: dynamic()
- Bottom-up dynamic programming.
- Constructs a 2D table with subproblem solutions.
- Most efficient and preferred in practice.
- Time and space complexity: O(m × n).

All three methods return the length of the longest sequence of characters that appear left-to-right (not necessarily contiguously) in both strings.
*/
