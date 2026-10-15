<?php

namespace Legacy;

use InvalidArgumentException;

require_once __DIR__ . DIRECTORY_SEPARATOR . 'Levenshtein.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'LCS.php';

class FRAS
{
    private string $query;
    private array $records;
    private float $weightPrefix;
    private float $weightLCS;
    private float $weightLevenshtein;

    public function __construct(string $query, array $namedArray, float $weightPrefix = 0.5, float $weightLCS = 0.3, float $weightLevenshtein = 0.2)
    {
        $this->query = $query;
        $this->records = $namedArray;
        $this->weightPrefix = $weightPrefix;
        $this->weightLCS = $weightLCS;
        $this->weightLevenshtein = $weightLevenshtein;
    }

    public function look(string $field): array
    {
        if (empty($this->records)) {
            throw new InvalidArgumentException("The records array is empty.");
        }

        foreach ($this->records as $index => $record) {
            if (!array_key_exists($field, $record)) {
                throw new InvalidArgumentException("Field '{$field}' not found in record at index {$index}.");
            }
        }
        
        $maxLCS = 1;
        $maxLev = 1;

        foreach ($this->records as &$record) {
            $lcs = new LCS($this->query, $record[$field]);
            $record['LCSlength'] = $lcs->dynamic();

            $lev = new Levenshtein($this->query, $record[$field]);
            $record['levenshteinDistance'] = $lev->dynamic();

            $maxLCS = max($maxLCS, $record['LCSlength']);
            $maxLev = max($maxLev, $record['levenshteinDistance']);
        }
        unset($record);

        foreach ($this->records as &$record) {
            $nameLower = strtolower($record[$field]);
            $queryLower = strtolower($this->query);

            $prefixLen = 0;
            for ($i = 0; $i < min(strlen($queryLower), strlen($nameLower)); $i++) {
                if ($queryLower[$i] === $nameLower[$i]) {
                    $prefixLen++;
                } else {
                    break;
                }
            }

            $prefixScore = $prefixLen / (strlen($queryLower) ?: 1);
            $lcsScore = $record['LCSlength'] / $maxLCS;
            $levScore = 1 - ($record['levenshteinDistance'] / $maxLev);

            $record['similarityScore'] =
                ($this->weightPrefix * $prefixScore) +
                ($this->weightLCS * $lcsScore) +
                ($this->weightLevenshtein * $levScore);
        }
        unset($record);

        usort($this->records, function ($a, $b) {
            return $b['similarityScore'] <=> $a['similarityScore'];
        });

        return $this->records;
    }
}

/*
Explanation:

Class: FRAS
- Field Rank And Sort
- A modular search helper that sorts array by ranking items of a field.
- It uses 3 distinct signals: prefix match, LCS (Longest Common Subsequence), and Levenshtein distance.

Constructor Parameters:
- $query: The search string to compare against.
- $namedArray: Array of associative arrays, having the field.
- $weightPrefix, $weightLCS, $weightLevenshtein: Weights that determine the contribution of each score to final ranking.

Method: look($field)
- $field: The name of field whose records are compared against the query.
- Calculates LCSlength and Levenshtein distance for each record.
- Normalizes all three metrics.
- Applies the weighted sum to produce a 'similarityScore'.
- Sorts the array in descending order of similarity and returns it.

Usage:
    $search = new FRAS($query, $namedArray);
    $rankedResult = $search->look($field);

This keeps the search logic reusable, testable, and clean - suitable for any part of your app needing intelligent similarity matching.
*/
