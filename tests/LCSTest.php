<?php

use PHPUnit\Framework\TestCase;

class LCSTest extends TestCase
{
    public function testIdenticalStrings(): void
    {
        $this->assertSame(4, (new LCS('test', 'test'))->dynamic());
    }

    public function testClassicAbcdGbcd(): void
    {
        $this->assertSame(3, (new LCS('ABCD', 'GBCD'))->dynamic());
    }

    public function testNoCommonCharacters(): void
    {
        $this->assertSame(0, (new LCS('abc', 'xyz'))->dynamic());
    }

    public function testEmptyString(): void
    {
        $this->assertSame(0, (new LCS('', 'abc'))->dynamic());
        $this->assertSame(0, (new LCS('abc', ''))->dynamic());
    }

    public function testMultibyteCharactersCountAsSingleUnits(): void
    {
        $this->assertSame(3, (new LCS('café', 'cafe'))->dynamic());
    }
}
