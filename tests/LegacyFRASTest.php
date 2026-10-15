<?php

use PHPUnit\Framework\TestCase;
use Legacy\FRAS as LegacyFRAS;

/**
 * These tests exercise the ORIGINAL (naive) FRAS implementation exactly as
 * it was uploaded, with only the require_once paths adjusted and a
 * namespace added so it can run side-by-side with the rewritten version.
 * No scoring logic was changed here.
 *
 * The point of this suite is NOT to prove the legacy code is "correct" -
 * it's to pin down its actual behavior, including the bugs, so the
 * improvements in FRASTest.php can be shown against a concrete baseline.
 */
class LegacyFRASTest extends TestCase
{
    public function testBasicRankingPutsExactMatchFirst(): void
    {
        $records = [
            ['name' => 'Jonathan'],
            ['name' => 'John'],
            ['name' => 'Jon'],
        ];

        $fras = new LegacyFRAS('John', $records);
        $ranked = $fras->look('name');

        $this->assertSame('John', $ranked[0]['name']);
    }

    /**
     * BUG: the legacy implementation normalizes LCSlength/levenshteinDistance
     * against the MAX value seen across the current batch of records, not
     * against a fixed bound. That means the similarityScore for the exact
     * same query/record pair changes depending on what else is in the array.
     *
     * This test proves that behavior exists (it is not hypothetical).
     */
    public function testSimilarityScoreForSameRecordIsBatchDependent(): void
    {
        // NOTE: an exact query/record match (e.g. query "John" vs record
        // "John") always scores 1.0 regardless of batch, because it is
        // simultaneously the batch max for LCS and a zero Levenshtein
        // distance -- so it can't reveal this bug. "Jon" is a close but
        // imperfect match, which IS sensitive to what else is in the batch.
        $recordUnderTest = ['name' => 'Jon'];

        $smallBatch = [
            ['name' => 'John'],
            $recordUnderTest,
        ];

        $largeBatchWithOutlier = [
            ['name' => 'John'],
            $recordUnderTest,
            // A long, very dissimilar string inflates maxLCS/maxLev for
            // the whole batch, which changes the normalized scores of
            // every other record even though nothing about "Jon" changed.
            ['name' => 'Supercalifragilisticexpialidocious Extra Padding Words'],
        ];

        $rankedSmall = (new LegacyFRAS('John', $smallBatch))->look('name');
        $jonRowSmall = array_values(array_filter($rankedSmall, fn($r) => $r['name'] === 'Jon'))[0];
        $scoreSmall = $jonRowSmall['similarityScore'];

        $rankedLarge = (new LegacyFRAS('John', $largeBatchWithOutlier))->look('name');
        $jonRowLarge = array_values(array_filter($rankedLarge, fn($r) => $r['name'] === 'Jon'))[0];
        $scoreLarge = $jonRowLarge['similarityScore'];

        // Same query, same record, same field -> different score purely
        // because of an unrelated third record in the batch.
        $this->assertNotEquals(
            $scoreSmall,
            $scoreLarge,
            'Expected legacy FRAS to be batch-dependent (this documents the bug, it is not a desired property).'
        );
    }

    /**
     * BUG: prefix/LCS/Levenshtein all operate on strlen()/byte offsets.
     * Multibyte characters (e.g. "é") are more than one byte in UTF-8,
     * so comparisons misalign and distances are inflated versus what a
     * human reading the strings would expect.
     *
     * This test doesn't assert a "correct" value (there isn't one worth
     * hardcoding for a known-broken path) - it demonstrates that legacy
     * Levenshtein distance between two DIFFERENT multibyte strings that a
     * human would consider "one character different" is inflated well
     * beyond 1, unlike the fixed version (see FRASTest for contrast).
     */
    public function testLegacyLevenshteinIsByteBasedNotCharacterBased(): void
    {
        $lev = new \Legacy\Levenshtein('café', 'cafe');
        // "café" vs "cafe" differ by exactly one character to a human.
        // But "é" is 2 bytes in UTF-8, so byte-based Levenshtein reports
        // a distance greater than 1.
        $this->assertGreaterThan(1, $lev->dynamic());
    }

    public function testThrowsOnEmptyRecords(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new LegacyFRAS('John', []))->look('name');
    }

    public function testThrowsOnMissingField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new LegacyFRAS('John', [['other' => 'x']]))->look('name');
    }
}
