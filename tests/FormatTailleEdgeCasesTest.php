<?php

use PHPUnit\Framework\TestCase;

class FormatTailleEdgeCasesTest extends TestCase
{
    /**
     * @dataProvider edgeCaseProvider
     */
    public function testFormatTailleEdgeCases(int $bytes, string $expected): void
    {
        $this->assertSame($expected, format_taille($bytes));
    }

    public static function edgeCaseProvider(): array
    {
        return [
            'negative small'    => [-500, '-500 o'],
            'negative kilo'     => [-2048, '-2048 o'],
            'negative mega'     => [-5242880, '-5242880 o'],
            'negative giga'     => [-2147483648, '-2147483648 o'],
            'one byte'          => [1, '1 o'],
            'boundary kilo-1'   => [1023, '1023 o'],
            'boundary kilo'     => [1024, '1 Ko'],
            'boundary mega-1'   => [1048575, '1024 Ko'],
            'boundary mega'     => [1048576, '1 Mo'],
            'boundary giga-1'   => [1073741823, '1024 Mo'],
            'boundary giga'     => [1073741824, '1 Go'],
            'large 10 Go'       => [10737418240, '10 Go'],
            'large 100 Go'      => [107374182400, '100 Go'],
            'large ~1 To'       => [1099511627776, '1024 Go'],
        ];
    }
}
