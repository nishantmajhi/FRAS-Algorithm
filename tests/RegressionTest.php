<?php

use PHPUnit\Framework\TestCase;

/**
 * Defects in src/ as first committed. Every test here fails against that
 * code; tests/against-original.php checks that it still does.
 *
 * @group regression
 */
class RegressionTest extends TestCase
{
    /** @var string[] */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempPaths = [];
    }

    /**
     * The three components for one pair, extracted the way
     * fras-weight-trainer's tools/gen_vectors.php does: with the weights
     * [1,0,0], [0,1,0] and [0,0,1], similarityScore is exactly one component.
     *
     * @return array{prefix:float,lcs:float,levenshtein:float}
     */
    private function components(string $query, string $field): array
    {
        $out = [];
        foreach (['prefix' => [1.0, 0.0, 0.0], 'lcs' => [0.0, 1.0, 0.0], 'levenshtein' => [0.0, 0.0, 1.0]] as $name => $w) {
            $ranked = (new FRAS($query, [['v' => $field]], ...$w))->look('v');
            $out[$name] = $ranked[0]['similarityScore'];
        }
        return $out;
    }

    private function weightsFile(string $json): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fras_weights_');
        file_put_contents($path, $json);
        $this->tempPaths[] = $path;
        return $path;
    }

    private function lookWithWeightsFile(string $json, ?string $profile = null): void
    {
        $file = $this->weightsFile($json);
        (new FRAS('jo', [['v' => 'john']], null, null, null, $profile, $file))->look('v');
    }

    // --- Case folding -----------------------------------------------------

    /**
     * look() lower-cased both strings for the prefix comparison but handed the
     * original-case strings to LCS and Levenshtein, so a query that differed
     * from the field only in case scored 0 on two of the three components.
     */
    public function testLcsAndLevenshteinIgnoreCaseLikePrefixDoes(): void
    {
        $this->assertEqualsWithDelta(
            ['prefix' => 1.0, 'lcs' => 1.0, 'levenshtein' => 1.0],
            $this->components('JOHN', 'john'),
            1e-9
        );
    }

    public function testComponentsAreTheSameWhateverTheCaseOfEitherSide(): void
    {
        $lower = $this->components('acme', 'acme-corp');

        $this->assertEqualsWithDelta($lower, $this->components('ACME', 'acme-corp'), 1e-9);
        $this->assertEqualsWithDelta($lower, $this->components('acme', 'ACME-Corp'), 1e-9);
        $this->assertEqualsWithDelta($lower, $this->components('Acme', 'aCME-cORP'), 1e-9);
    }

    /**
     * The consequence for a caller: an exact match typed in the wrong case lost
     * to a record that merely shares a prefix.
     */
    public function testExactMatchInDifferentCaseOutranksAPartialMatch(): void
    {
        $ranked = (new FRAS('john', [['name' => 'Jonathan'], ['name' => 'JOHN']]))->look('name');

        $this->assertSame('JOHN', $ranked[0]['name']);
    }

    public function testCaseFoldingCoversNonLatinScripts(): void
    {
        $this->assertEqualsWithDelta(
            ['prefix' => 1.0, 'lcs' => 1.0, 'levenshtein' => 1.0],
            $this->components('ΣΟΦΙΑ', 'σοφια'),
            1e-9
        );
    }

    // --- Token-boundary bonus with a leading delimiter ----------------------

    /**
     * Token 0 was skipped on the grounds that the literal prefix check already
     * covers it. That holds only when the field starts with token 0. A field
     * that starts with a delimiter - a space, ".NET", "-foo" - has its first
     * word somewhere else, so neither check saw it and the prefix scored 0.
     */
    public function testFirstWordAfterALeadingDelimiterEarnsPrefixCredit(): void
    {
        $this->assertEqualsWithDelta(1.0, $this->components('smith', ' smith')['prefix'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $this->components('acme', '-acme corp')['prefix'], 1e-9);
    }

    /**
     * The consequence: for "net", ".NET Framework" scored 0 on prefix while
     * "Microsoft .NET" scored 0.5 for the same word in second place.
     */
    public function testLeadingDelimiterDoesNotRankAFirstWordBelowASecondWord(): void
    {
        $ranked = (new FRAS('net', [['name' => 'Microsoft .NET'], ['name' => '.NET Framework']]))->look('name');

        $this->assertSame('.NET Framework', $ranked[0]['name']);
    }

    // --- Learned weights are validated, not only their keys ------------------

    public function testNegativeLearnedWeightIsRejected(): void
    {
        // Accepted before: normalised to [-5, 3, 3], which put "xjo" above
        // "john" for the query "jo" with a similarityScore of 5.0.
        $this->expectException(InvalidArgumentException::class);
        $this->lookWithWeightsFile('{"global":{"prefix":-5,"lcs":3,"levenshtein":3}}');
    }

    public function testNonNumericLearnedWeightIsRejected(): void
    {
        // Accepted before: (float) "abc" is 0.
        $this->expectException(InvalidArgumentException::class);
        $this->lookWithWeightsFile('{"global":{"prefix":"abc","lcs":0.5,"levenshtein":0.5}}');
    }

    public function testNullLearnedWeightIsRejected(): void
    {
        // Accepted before: (float) null is 0.
        $this->expectException(InvalidArgumentException::class);
        $this->lookWithWeightsFile('{"global":{"prefix":null,"lcs":0.5,"levenshtein":0.5}}');
    }

    public function testNonFiniteLearnedWeightIsRejected(): void
    {
        // json_decode reads 1e400 as INF; INF / INF made every score NAN.
        $this->expectException(InvalidArgumentException::class);
        $this->lookWithWeightsFile('{"global":{"prefix":1e400,"lcs":1,"levenshtein":1}}');
    }

    public function testAllZeroLearnedWeightsAreRejected(): void
    {
        // Accepted before, and silently replaced by equal weighting.
        $this->expectException(InvalidArgumentException::class);
        $this->lookWithWeightsFile('{"global":{"prefix":0,"lcs":0,"levenshtein":0}}');
    }

    public function testGlobalEntryThatIsNotAnObjectIsRejected(): void
    {
        // Ignored before: resolution fell through to the heuristic.
        $this->expectException(InvalidArgumentException::class);
        $this->lookWithWeightsFile('{"global":"oops"}');
    }

    public function testProfileEntryThatIsNotAnObjectIsRejected(): void
    {
        // Ignored before: resolution fell through to the built-in profile.
        $this->expectException(InvalidArgumentException::class);
        $this->lookWithWeightsFile('{"profiles":{"sku":"fast"}}', 'sku');
    }

    /**
     * is_readable() is true for a directory, so file_get_contents() raised a
     * warning and the exception that followed said the file was not valid JSON.
     */
    public function testDirectoryAsWeightsFileThrowsWithoutAWarning(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not found or not readable');
        (new FRAS('jo', [['v' => 'john']], null, null, null, null, sys_get_temp_dir()))->look('v');
    }
}
