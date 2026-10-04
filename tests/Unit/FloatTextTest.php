<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\PdoWrapper\Query\FloatText;

/**
 * FloatText: the shortest decimal text that reads back as the same float, without an exponent,
 * whatever PHP's precision and serialize_precision settings say.
 */
class FloatTextTest extends TestCase
{
    /**
     * @return array<string, array{float, string}>
     */
    public static function floats(): array
    {
        return [
            'zero' => [0.0, '0'],
            'negative zero' => [-0.0, '0'],
            'a tenth' => [0.1, '0.1'],
            'a sum with its binary rest' => [0.1 + 0.2, '0.30000000000000004'],
            'negative' => [-1.25, '-1.25'],
            'sixteen digits' => [0.1234567890123456, '0.1234567890123456'],
            'seventeen digits' => [0.12345678901234568, '0.12345678901234568'],
            'whole' => [100.0, '100'],
            'beyond 2^53' => [9007199254740994.0, '9007199254740994'],
            'large' => [1e21, '1000000000000000000000'],
            'large with digits' => [1.2345678901234568e17, '123456789012345680'],
            'small' => [1.5e-7, '0.00000015'],
            'negative small' => [-2.5e-10, '-0.00000000025'],
            // A power of two: its rounding interval is lopsided, and the nearest 16-digit decimal
            // (...801) does not read back where another one (...802) does
            '2^-44' => [2 ** -44, '0.00000000000005684341886080802'],
            '2^-24' => [2 ** -24, '0.00000005960464477539063'],
            '-2^-44' => [-(2 ** -44), '-0.00000000000005684341886080802'],
            'the smallest' => [5e-324, '0.' . str_repeat('0', 323) . '5'],
            'the largest' => [1.7976931348623157e308, '17976931348623157' . str_repeat('0', 292)],
        ];
    }

    #[DataProvider('floats')]
    public function testTheShortestTextThatReadsBack(float $value, string $text): void
    {
        $this->assertSame($text, FloatText::of($value));
        $this->assertSame($value, (float) FloatText::of($value));
    }

    public function testThePrecisionSettingsDoNotCount(): void
    {
        $precision = ini_get('precision');
        $serialize = ini_get('serialize_precision');
        try {
            ini_set('precision', '5');
            ini_set('serialize_precision', '5');
            $this->assertSame('0.1234567890123456', FloatText::of(0.1234567890123456));
            ini_set('precision', '40');
            ini_set('serialize_precision', '40');
            $this->assertSame('0.1', FloatText::of(0.1));
        } finally {
            ini_set('precision', (string) $precision);
            ini_set('serialize_precision', (string) $serialize);
        }
    }

    public function testTheLocaleDoesNotCount(): void
    {
        $locale = setlocale(LC_ALL, '0');
        try {
            if (setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'de_CH.UTF-8') === false) {
                $this->markTestSkipped('no German locale on this system');
            }
            $this->assertSame('1.5', FloatText::of(1.5));
        } finally {
            setlocale(LC_ALL, (string) $locale);
        }
    }
}
