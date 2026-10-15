<?php

use PHPUnit\Framework\TestCase;

class FRASTest extends TestCase
{
    public function testBasicRankingPutsExactMatchFirst(): void
    {
        $records = [
            ['name' => 'Jonathan'],
            ['name' => 'John'],
            ['name' => 'Jon'],
        ];

        $fras = new FRAS('John', $records);
        $ranked = $fras->look('name');

        $this->assertSame('John', $ranked[0]['name']);
    }

    public function testThrowsOnEmptyRecords(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FRAS('John', []))->look('name');
    }

    public function testThrowsOnMissingField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FRAS('John', [['other' => 'x']]))->look('name');
    }

    /**
     * FIX VERIFICATION: unlike the legacy implementation, similarityScore
     * for a given query/record/field must NOT depend on what else is in
     * the batch, because normalization is now done against per-pair
     * theoretical bounds instead of the batch max.
     */
    public function testSimilarityScoreIsBatchIndependent(): void
    {
        // Mirrors LegacyFRASTest::testSimilarityScoreForSameRecordIsBatchDependent,
        // using the same imperfect match ("Jon" vs query "John") so the two
        // tests are a direct before/after comparison.
        $recordUnderTest = ['name' => 'Jon'];

        $smallBatch = [
            ['name' => 'John'],
            $recordUnderTest,
        ];

        $largeBatchWithOutlier = [
            ['name' => 'John'],
            $recordUnderTest,
            ['name' => 'Supercalifragilisticexpialidocious Extra Padding Words'],
        ];

        $rankedSmall = (new FRAS('John', $smallBatch))->look('name');
        $jonRowSmall = array_values(array_filter($rankedSmall, fn($r) => $r['name'] === 'Jon'))[0];
        $scoreSmall = $jonRowSmall['similarityScore'];

        $rankedLarge = (new FRAS('John', $largeBatchWithOutlier))->look('name');
        $jonRowLarge = array_values(array_filter($rankedLarge, fn($r) => $r['name'] === 'Jon'))[0];
        $scoreLarge = $jonRowLarge['similarityScore'];

        $this->assertEqualsWithDelta($scoreSmall, $scoreLarge, 0.0001);
    }

    /**
     * FIX VERIFICATION: multibyte characters are compared per-character,
     * so "café" vs "cafe" is exactly 1 edit apart, matching human intuition.
     */
    public function testMultibyteLevenshteinIsCharacterBased(): void
    {
        $lev = new Levenshtein('café', 'cafe');
        $this->assertSame(1, $lev->dynamic());
    }

    public function testMultibyteLcsIsCharacterBased(): void
    {
        $lcs = new LCS('café', 'cafe');
        // Shared subsequence "caf" plus either "é" or "e" aligned -> 3.
        $this->assertSame(3, $lcs->dynamic());
    }

    // --- Adaptive weight resolution -----------------------------------

    public function testHeuristicUsesPrefixHeavyWeightsForShortQuery(): void
    {
        $fras = new FRAS('Jo', [['name' => 'John']]);
        $fras->look('name');

        [$prefix, $lcs, $lev] = $fras->getResolvedWeights();
        $this->assertEqualsWithDelta(0.70, $prefix, 0.0001);
        $this->assertEqualsWithDelta(0.20, $lcs, 0.0001);
        $this->assertEqualsWithDelta(0.10, $lev, 0.0001);
    }

    public function testHeuristicUsesBalancedWeightsForMediumQuery(): void
    {
        $fras = new FRAS('Johnny', [['name' => 'John']]); // 6 chars
        $fras->look('name');

        [$prefix, $lcs, $lev] = $fras->getResolvedWeights();
        $this->assertEqualsWithDelta(0.50, $prefix, 0.0001);
        $this->assertEqualsWithDelta(0.30, $lcs, 0.0001);
        $this->assertEqualsWithDelta(0.20, $lev, 0.0001);
    }

    public function testHeuristicUsesTypoTolerantWeightsForLongQuery(): void
    {
        $fras = new FRAS('Jonathan Alexander', [['name' => 'John']]); // 19 chars
        $fras->look('name');

        [$prefix, $lcs, $lev] = $fras->getResolvedWeights();
        $this->assertEqualsWithDelta(0.30, $prefix, 0.0001);
        $this->assertEqualsWithDelta(0.40, $lcs, 0.0001);
        $this->assertEqualsWithDelta(0.30, $lev, 0.0001);
    }

    public function testNamedProfileOverridesHeuristic(): void
    {
        // Query length would normally trigger the "medium" heuristic bucket,
        // but an explicit profile should win instead.
        $fras = new FRAS('ABC123', [['sku' => 'ABC124']], null, null, null, 'sku');
        $fras->look('sku');

        [$prefix, $lcs, $lev] = $fras->getResolvedWeights();
        $this->assertEqualsWithDelta(0.60, $prefix, 0.0001);
        $this->assertEqualsWithDelta(0.25, $lcs, 0.0001);
        $this->assertEqualsWithDelta(0.15, $lev, 0.0001);
    }

    public function testUnknownProfileFallsBackToHeuristic(): void
    {
        $fras = new FRAS('Jo', [['name' => 'John']], null, null, null, 'not_a_real_profile');
        $fras->look('name');

        [$prefix, $lcs, $lev] = $fras->getResolvedWeights();
        // Falls back to the short-query heuristic, same as no profile at all.
        $this->assertEqualsWithDelta(0.70, $prefix, 0.0001);
        $this->assertEqualsWithDelta(0.20, $lcs, 0.0001);
        $this->assertEqualsWithDelta(0.10, $lev, 0.0001);
    }

    public function testExplicitWeightsOverrideProfileAndHeuristic(): void
    {
        $fras = new FRAS('Jo', [['name' => 'John']], 0.1, 0.1, 0.8, 'sku');
        $fras->look('name');

        [$prefix, $lcs, $lev] = $fras->getResolvedWeights();
        $this->assertEqualsWithDelta(0.1, $prefix, 0.0001);
        $this->assertEqualsWithDelta(0.1, $lcs, 0.0001);
        $this->assertEqualsWithDelta(0.8, $lev, 0.0001);
    }

    public function testExplicitWeightsAreNormalizedToSumOne(): void
    {
        // Caller passes un-normalized weights (sum = 10).
        $fras = new FRAS('Jo', [['name' => 'John']], 5.0, 3.0, 2.0);
        $fras->look('name');

        [$prefix, $lcs, $lev] = $fras->getResolvedWeights();
        $this->assertEqualsWithDelta(0.5, $prefix, 0.0001);
        $this->assertEqualsWithDelta(0.3, $lcs, 0.0001);
        $this->assertEqualsWithDelta(0.2, $lev, 0.0001);
    }

    public function testDegenerateZeroWeightsFallBackToEqualWeighting(): void
    {
        $fras = new FRAS('Jo', [['name' => 'John']], 0.0, 0.0, 0.0);
        $fras->look('name');

        [$prefix, $lcs, $lev] = $fras->getResolvedWeights();
        $this->assertEqualsWithDelta(1 / 3, $prefix, 0.0001);
        $this->assertEqualsWithDelta(1 / 3, $lcs, 0.0001);
        $this->assertEqualsWithDelta(1 / 3, $lev, 0.0001);
    }

    public function testGetResolvedWeightsIsNullBeforeLookIsCalled(): void
    {
        $fras = new FRAS('Jo', [['name' => 'John']]);
        $this->assertNull($fras->getResolvedWeights());
    }
}
