<?php

use PHPUnit\Framework\TestCase;

/**
 * Covers the three features documented in README.md section 6 that were
 * previously verified only manually: the token-boundary prefix bonus,
 * short-circuiting (both the always-on LCS overlap check and the opt-in
 * Levenshtein threshold check), and learned weights loaded from a JSON
 * file. Also covers a couple of edge cases (empty query, non-string field
 * value) not exercised by FRASTest.php.
 */
class FRASAdvancedTest extends TestCase
{
    /** @var string[] temp files created during a test, removed in tearDown */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    private function makeWeightsFile(array $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fras_weights_');
        file_put_contents($path, json_encode($contents));
        $this->tempFiles[] = $path;
        return $path;
    }

    // --- Token-boundary prefix bonus ------------------------------------

    /**
     * FEATURE VERIFICATION: a query matching the start of a later word
     * (not just position 0 of the whole field) now gets partial credit,
     * so "John Smith" scores meaningfully above a field with no matching
     * word at all -- instead of the two tying at a prefixScore of 0.
     */
    public function testTokenBoundaryBonusRanksMatchingLaterWordAboveNoMatch(): void
    {
        $records = [
            ['name' => 'John Smith'],
            ['name' => 'Random Unrelated Text'],
        ];

        $ranked = (new FRAS('smith', $records))->look('name');

        $this->assertSame('John Smith', $ranked[0]['name']);
        $this->assertGreaterThan($ranked[1]['similarityScore'], $ranked[0]['similarityScore']);
    }

    /**
     * FEATURE VERIFICATION: the token bonus decays with how far into the
     * field the matching word appears (1 / (1 + tokenIndex)), so a match
     * on the 2nd word outranks the same match on the 3rd word.
     */
    public function testTokenBoundaryBonusDecaysWithPosition(): void
    {
        $records = [
            ['name' => 'xx ab'],       // "ab" is token index 1 -> decay 1/2
            ['name' => 'xx yy ab'],    // "ab" is token index 2 -> decay 1/3
        ];

        $ranked = (new FRAS('ab', $records))->look('name');

        $this->assertSame('xx ab', $ranked[0]['name']);
        $this->assertSame('xx yy ab', $ranked[1]['name']);
    }

    /**
     * FEATURE VERIFICATION: a match on the first word is not double-counted.
     * The bonus loop includes token 0 (so a field with a leading delimiter
     * still gets credit for its first word), but it is combined with the
     * literal prefix score by max(), never added to it.
     */
    public function testFirstTokenMatchIsNotDoubleCounted(): void
    {
        // "Smith" is token index 0 here and also a literal full-length
        // prefix match, so both checks give 1.0 -- and the resulting
        // similarityScore must never exceed what full weight allows.
        $records = [['name' => 'Smith Institute']];

        $fras = new FRAS('smith', $records);
        $ranked = $fras->look('name');

        [$weightPrefix, $weightLCS, $weightLevenshtein] = $fras->getResolvedWeights();
        $maxPossibleScore = $weightPrefix + $weightLCS + $weightLevenshtein; // == 1.0

        $this->assertLessThanOrEqual($maxPossibleScore + 0.0001, $ranked[0]['similarityScore']);
    }

    // --- Short-circuiting: LCS character-overlap (always on, lossless) --

    /**
     * FEATURE VERIFICATION: when the query and field share no characters
     * at all, LCSlength is guaranteed 0 via the always-on overlap check
     * rather than running the DP -- the observable result is identical to
     * running the DP, so this pins down correctness of that guarantee.
     */
    public function testLcsShortCircuitProducesZeroForDisjointCharacterSets(): void
    {
        $records = [['name' => 'xyz']];
        $ranked = (new FRAS('abc', $records))->look('name');

        $this->assertSame(0, $ranked[0]['LCSlength']);
    }

    // --- Short-circuiting: Levenshtein threshold (opt-in, approximate) --

    /**
     * FEATURE VERIFICATION: with the short-circuit enabled, a pair whose
     * best-case Levenshtein score (based purely on the length difference)
     * already falls below the threshold skips the DP and is recorded as
     * an approximation using the worst-case distance (maxLen).
     */
    public function testLevenshteinShortCircuitApproximatesFarPairs(): void
    {
        $query = 'a'; // length 1
        $field = str_repeat('z', 100); // length 100, no shared characters
        $records = [['name' => $field]];

        // bestPossibleLevScore = 1 - (99/100) = 0.01, which is below the
        // default threshold of 0.05, so this should trigger the short-circuit.
        $fras = new FRAS($query, $records, null, null, null, null, null, true, 0.05);
        $ranked = $fras->look('name');

        $this->assertTrue($ranked[0]['levenshteinApproximated']);
        $this->assertSame(100, $ranked[0]['levenshteinDistance']);
    }

    /**
     * FEATURE VERIFICATION: with the short-circuit enabled but the pair's
     * best-case score does NOT fall below the threshold, the exact DP
     * still runs and the distance is not approximated.
     */
    public function testLevenshteinShortCircuitDoesNotFireForCloseCandidates(): void
    {
        $records = [['name' => 'sitting']];

        // bestPossibleLevScore = 1 - (1/7) ≈ 0.857, well above the default
        // 0.05 threshold, so the exact DP should run.
        $fras = new FRAS('kitten', $records, null, null, null, null, null, true, 0.05);
        $ranked = $fras->look('name');

        $this->assertFalse($ranked[0]['levenshteinApproximated']);
        $this->assertSame(3, $ranked[0]['levenshteinDistance']); // classic kitten/sitting distance
    }

    /**
     * FEATURE VERIFICATION: the short-circuit is opt-in. With
     * enableShortCircuit left at its default (false), even a pair that
     * *would* qualify for approximation is computed exactly instead.
     */
    public function testLevenshteinShortCircuitIsOffByDefault(): void
    {
        $query = 'a';
        $field = str_repeat('z', 100);
        $records = [['name' => $field]];

        $ranked = (new FRAS($query, $records))->look('name');

        $this->assertFalse($ranked[0]['levenshteinApproximated']);
    }

    // --- Learned weights ---------------------------------------------------

    /**
     * FEATURE VERIFICATION: when a weights file defines a "profiles" entry
     * matching the requested profile, it takes priority over the built-in
     * PROFILES table.
     */
    public function testLearnedProfileWeightsOverrideBuiltInProfile(): void
    {
        $file = $this->makeWeightsFile([
            'profiles' => [
                'sku' => ['prefix' => 0.10, 'lcs' => 0.10, 'levenshtein' => 0.80],
            ],
        ]);

        $fras = new FRAS('ABC123', [['sku' => 'ABC124']], null, null, null, 'sku', $file);
        $fras->look('sku');

        [$prefix, $lcs, $lev] = $fras->getResolvedWeights();
        $this->assertEqualsWithDelta(0.10, $prefix, 0.0001);
        $this->assertEqualsWithDelta(0.10, $lcs, 0.0001);
        $this->assertEqualsWithDelta(0.80, $lev, 0.0001);
        $this->assertSame('learned:profile', $fras->getResolvedWeightSource());
    }

    /**
     * FEATURE VERIFICATION: when no profile is given (or it matches
     * nothing), a "global" entry in the weights file is used ahead of the
     * query-length heuristic.
     */
    public function testLearnedGlobalWeightsOverrideHeuristic(): void
    {
        $file = $this->makeWeightsFile([
            'global' => ['prefix' => 0.05, 'lcs' => 0.05, 'levenshtein' => 0.90],
        ]);

        // Short query would normally trigger the prefix-heavy heuristic.
        $fras = new FRAS('Jo', [['name' => 'John']], null, null, null, null, $file);
        $fras->look('name');

        [$prefix, $lcs, $lev] = $fras->getResolvedWeights();
        $this->assertEqualsWithDelta(0.05, $prefix, 0.0001);
        $this->assertEqualsWithDelta(0.05, $lcs, 0.0001);
        $this->assertEqualsWithDelta(0.90, $lev, 0.0001);
        $this->assertSame('learned:global', $fras->getResolvedWeightSource());
    }

    /**
     * FEATURE VERIFICATION: full priority order end-to-end. Explicit
     * constructor weights beat everything, including a learned profile
     * entry for the same profile name.
     */
    public function testExplicitWeightsBeatLearnedProfile(): void
    {
        $file = $this->makeWeightsFile([
            'profiles' => [
                'sku' => ['prefix' => 0.10, 'lcs' => 0.10, 'levenshtein' => 0.80],
            ],
        ]);

        $fras = new FRAS('ABC123', [['sku' => 'ABC124']], 0.9, 0.05, 0.05, 'sku', $file);
        $fras->look('sku');

        [$prefix, $lcs, $lev] = $fras->getResolvedWeights();
        $this->assertEqualsWithDelta(0.9, $prefix, 0.0001);
        $this->assertSame('explicit', $fras->getResolvedWeightSource());
    }

    /**
     * FEATURE VERIFICATION: a profile that matches the built-in PROFILES
     * table but has no corresponding entry in the learned-weights file
     * falls through to the built-in table rather than to learned:global
     * or the heuristic.
     */
    public function testBuiltInProfileUsedWhenLearnedFileHasNoMatchingProfile(): void
    {
        $file = $this->makeWeightsFile([
            'global' => ['prefix' => 0.05, 'lcs' => 0.05, 'levenshtein' => 0.90],
        ]);

        // 'sku' is not in the file's "profiles", so this should fall
        // through to the built-in PROFILES['sku'], not to learned:global.
        $fras = new FRAS('ABC123', [['sku' => 'ABC124']], null, null, null, 'sku', $file);
        $fras->look('sku');

        [$prefix, $lcs, $lev] = $fras->getResolvedWeights();
        $this->assertEqualsWithDelta(0.60, $prefix, 0.0001);
        $this->assertEqualsWithDelta(0.25, $lcs, 0.0001);
        $this->assertEqualsWithDelta(0.15, $lev, 0.0001);
        $this->assertSame('profile', $fras->getResolvedWeightSource());
    }

    /**
     * FEATURE VERIFICATION: a learned-weights entry missing one of the
     * required keys (prefix/lcs/levenshtein) throws, rather than silently
     * using a partial or zeroed-out weight tuple.
     */
    public function testLearnedWeightsEntryMissingKeyThrows(): void
    {
        $file = $this->makeWeightsFile([
            'profiles' => [
                'sku' => ['prefix' => 0.10, 'lcs' => 0.10], // missing "levenshtein"
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        (new FRAS('ABC123', [['sku' => 'ABC124']], null, null, null, 'sku', $file))->look('sku');
    }

    /**
     * FEATURE VERIFICATION: validation rejects malformed values without
     * rejecting well-formed ones -- integers, a zero in one slot, and the
     * exact shape fras-weight-trainer writes (four-decimal numbers).
     */
    public function testWellFormedLearnedWeightsAreAccepted(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fras_weights_');
        file_put_contents($path, "{\n  \"global\": {\n    \"prefix\": 0.4500,\n    \"lcs\": 0.0000,\n    \"levenshtein\": 0.5500\n  },\n"
            . "  \"profiles\": {\n    \"sku\": {\"prefix\": 3, \"lcs\": 1, \"levenshtein\": 0}\n  }\n}\n");
        $this->tempFiles[] = $path;

        $global = new FRAS('Jo', [['name' => 'John']], null, null, null, null, $path);
        $global->look('name');
        $this->assertEqualsWithDelta([0.45, 0.0, 0.55], $global->getResolvedWeights(), 0.0001);

        $sku = new FRAS('AB', [['sku' => 'AB1']], null, null, null, 'sku', $path);
        $sku->look('sku');
        $this->assertEqualsWithDelta([0.75, 0.25, 0.0], $sku->getResolvedWeights(), 0.0001);
    }

    /**
     * FEATURE VERIFICATION: a $weightsFile path that can't be read throws
     * a clear InvalidArgumentException instead of a raw PHP warning.
     */
    public function testUnreadableWeightsFileThrows(): void
    {
        $missingPath = sys_get_temp_dir() . '/fras_does_not_exist_' . uniqid() . '.json';

        $this->expectException(InvalidArgumentException::class);
        (new FRAS('Jo', [['name' => 'John']], null, null, null, null, $missingPath))->look('name');
    }

    /**
     * FEATURE VERIFICATION: a $weightsFile that exists but isn't valid
     * JSON throws, rather than silently falling back to the heuristic.
     */
    public function testInvalidJsonWeightsFileThrows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fras_weights_');
        file_put_contents($path, 'not valid json {{{');
        $this->tempFiles[] = $path;

        $this->expectException(InvalidArgumentException::class);
        (new FRAS('Jo', [['name' => 'John']], null, null, null, null, $path))->look('name');
    }

    /**
     * FEATURE VERIFICATION: getResolvedWeightSource() correctly reports
     * 'heuristic' when no explicit weights, profile, or weights file
     * produce a match.
     */
    public function testResolvedWeightSourceIsHeuristicWithNoOverrides(): void
    {
        $fras = new FRAS('Jo', [['name' => 'John']]);
        $fras->look('name');

        $this->assertSame('heuristic', $fras->getResolvedWeightSource());
    }

    // --- Edge cases not covered by FRASTest.php -------------------------

    /**
     * EDGE CASE: an empty query string should not throw, and should
     * produce a similarityScore of 0 for every record (no prefix, LCS, or
     * Levenshtein signal can be positive against an empty query).
     */
    public function testEmptyQueryDoesNotThrowAndScoresZero(): void
    {
        $records = [
            ['name' => 'John'],
            ['name' => 'Jane'],
        ];

        $ranked = (new FRAS('', $records))->look('name');

        foreach ($ranked as $record) {
            $this->assertEqualsWithDelta(0.0, $record['similarityScore'], 0.0001);
        }
    }

    /**
     * EDGE CASE: non-string field values (e.g. an integer SKU/code) are
     * cast to string rather than causing a type error.
     */
    public function testNonStringFieldValueIsCastToString(): void
    {
        $records = [
            ['code' => 12345],
            ['code' => 99999],
        ];

        $ranked = (new FRAS('123', $records))->look('code');

        $this->assertSame(12345, $ranked[0]['code']);
    }

    /**
     * EDGE CASE: a negative individual weight is not special-cased -- as
     * long as the three weights don't sum to <= 0, normalize() just
     * divides each by the sum, same as any other explicit weights. A
     * negative *normalized* weight means that signal actively penalizes
     * (rather than rewards) a strong match on that dimension. This test
     * documents that behavior rather than asserting it's "correct" --
     * there's no validation in FRAS preventing a caller from doing this.
     */
    public function testNegativeExplicitWeightNormalizesWithoutSpecialHandling(): void
    {
        $fras = new FRAS('Jo', [['name' => 'John']], -0.5, 1.0, 1.0);
        $fras->look('name');

        [$prefix, $lcs, $lev] = $fras->getResolvedWeights();
        // Sum is 1.5, so each weight is divided by 1.5.
        $this->assertEqualsWithDelta(-1 / 3, $prefix, 0.0001);
        $this->assertEqualsWithDelta(2 / 3, $lcs, 0.0001);
        $this->assertEqualsWithDelta(2 / 3, $lev, 0.0001);
        // Sanity check they still sum to 1.0 after normalization.
        $this->assertEqualsWithDelta(1.0, $prefix + $lcs + $lev, 0.0001);
    }

    /**
     * EDGE CASE: this is distinct from testDegenerateZeroWeightsFallBackToEqualWeighting
     * in FRASTest.php -- that test uses all-zero weights (sum == 0). Here
     * all three weights are negative, so the sum is also <= 0 and should
     * hit the same equal-weighting fallback in normalize(), not produce
     * negative normalized weights.
     */
    public function testAllNegativeExplicitWeightsFallBackToEqualWeighting(): void
    {
        $fras = new FRAS('Jo', [['name' => 'John']], -1.0, -1.0, -1.0);
        $fras->look('name');

        [$prefix, $lcs, $lev] = $fras->getResolvedWeights();
        $this->assertEqualsWithDelta(1 / 3, $prefix, 0.0001);
        $this->assertEqualsWithDelta(1 / 3, $lcs, 0.0001);
        $this->assertEqualsWithDelta(1 / 3, $lev, 0.0001);
    }

    /**
     * EDGE CASE: the token-boundary bonus's tokenizer splits on
     * whitespace/-_,./ with the 'u' (unicode) regex modifier, and
     * mb_str_split() is script-agnostic, so the bonus works the same way
     * for a space-separated non-Latin script (Cyrillic here) as it does
     * for Latin text -- matching the start of a later word gets credit.
     */
    public function testTokenBoundaryBonusWorksForNonLatinScript(): void
    {
        $records = [
            ['name' => 'Иван Смит'],       // "Смит" (Smith) is the 2nd word
            ['name' => 'Случайный Текст'], // no matching word at all
        ];

        $ranked = (new FRAS('смит', $records))->look('name');

        $this->assertSame('Иван Смит', $ranked[0]['name']);
        $this->assertGreaterThan($ranked[1]['similarityScore'], $ranked[0]['similarityScore']);
    }

    /**
     * EDGE CASE: continuous (non-space-delimited) scripts like Japanese
     * don't get any token-boundary bonus, because the tokenizer only
     * splits on whitespace/-_,./ -- a CJK string with no such separators
     * is a single token, so any match that isn't at position 0 relies
     * entirely on the LCS/Levenshtein signals, not the prefix bonus. This
     * test documents that limitation rather than asserting it's ideal.
     */
    public function testTokenBoundaryBonusDoesNotApplyToUnsegmentedScripts(): void
    {
        $records = [['name' => '田中太郎']]; // "Tanaka Tarou", no spaces

        $ranked = (new FRAS('太郎', $records))->look('name');

        // "太郎" ("Tarou") is present in order but not at position 0, and
        // the field has no word separators for the token bonus to latch
        // onto, so LCSlength should still find it (order-preserving
        // subsequence) even though the prefix signal contributes nothing.
        $this->assertSame(2, $ranked[0]['LCSlength']);
        $this->assertEqualsWithDelta(0.25, $ranked[0]['similarityScore'], 0.0001);
    }
}
