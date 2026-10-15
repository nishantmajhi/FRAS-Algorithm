<?php

use PHPUnit\Framework\TestCase;

class LevenshteinTest extends TestCase
{
    public function testIdenticalStrings(): void
    {
        $this->assertSame(0, (new Levenshtein('kitten', 'kitten'))->dynamic());
    }

    public function testClassicKittenSitting(): void
    {
        $this->assertSame(3, (new Levenshtein('kitten', 'sitting'))->dynamic());
    }

    public function testEmptyStrings(): void
    {
        $this->assertSame(4, (new Levenshtein('', 'test'))->dynamic());
        $this->assertSame(4, (new Levenshtein('test', ''))->dynamic());
        $this->assertSame(0, (new Levenshtein('', ''))->dynamic());
    }

    public function testMultibyteCharactersCountAsSingleUnits(): void
    {
        $this->assertSame(1, (new Levenshtein('café', 'cafe'))->dynamic());
        $this->assertSame(1, (new Levenshtein('naïve', 'naive'))->dynamic());
    }
}
