<?php

use PHPUnit\Framework\TestCase;

/**
 * Asserts the scoring vectors shared with fras-weight-trainer.
 *
 * The trainer reimplements look()'s three components in C in order to fit
 * weights. If the two ever disagree, it fits weights for a function nobody
 * runs and nothing reports an error. tests/fixtures/scoring-vectors.json is
 * a copy of the trainer's tests/vectors.json, which that project generates
 * from this library and asserts from its own side; this is the PHP half.
 *
 * To regenerate, run `make vectors` in fras-weight-trainer and copy the
 * result here.
 */
class SharedVectorsTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/scoring-vectors.json';
    private const SIBLING = __DIR__ . '/../../fras-weight-trainer/tests/vectors.json';

    /** Six decimals are stored; this is half the last one. */
    private const TOLERANCE = 5e-7;

    private static function load(string $path): array
    {
        $doc = json_decode((string) file_get_contents($path), true);
        if (!is_array($doc)) {
            throw new RuntimeException("Could not read vectors from {$path}");
        }
        return $doc;
    }

    /** @return array<string, array{string, string, array}> */
    public function agreedVectors(): array
    {
        $cases = [];
        foreach (self::load(self::FIXTURE)['agreed'] as $i => $v) {
            $label = sprintf('#%d %s / %s', $i, json_encode($v['query'], JSON_UNESCAPED_UNICODE), json_encode($v['field'], JSON_UNESCAPED_UNICODE));
            $cases[$label] = [$v['query'], $v['field'], $v['expected']];
        }
        return $cases;
    }

    /**
     * @dataProvider agreedVectors
     */
    public function testLookProducesTheSharedComponents(string $query, string $field, array $expected): void
    {
        foreach (['prefix' => [1.0, 0.0, 0.0], 'lcs' => [0.0, 1.0, 0.0], 'levenshtein' => [0.0, 0.0, 1.0]] as $name => $w) {
            $ranked = (new FRAS($query, [['v' => $field]], ...$w))->look('v');
            $this->assertEqualsWithDelta((float) $expected[$name], $ranked[0]['similarityScore'], self::TOLERANCE, $name);
        }
    }

    public function testTheVectorsRecordNoDivergence(): void
    {
        $doc = self::load(self::FIXTURE);

        // Guards against the fixture being emptied, which would make the
        // data provider above assert nothing.
        $this->assertGreaterThanOrEqual(40, count($doc['agreed']));
        $this->assertSame([], $doc['divergent']);
    }

    public function testTheCopyMatchesTheTrainersWhenBothAreCheckedOut(): void
    {
        if (!is_file(self::SIBLING)) {
            $this->markTestSkipped('fras-weight-trainer is not checked out beside this project');
        }

        $this->assertSame(
            self::load(self::SIBLING),
            self::load(self::FIXTURE),
            'tests/fixtures/scoring-vectors.json differs from fras-weight-trainer/tests/vectors.json; copy it across'
        );
    }
}
