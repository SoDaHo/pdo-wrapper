<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Query;

/**
 * A float as the shortest decimal text that reads back as the same float - what it was written
 * as (0.1, not 0.1000000000000000055...; 0.1 + 0.2 as 0.30000000000000004; 2^-44 as
 * 5.684341886080802e-14) -, whatever PHP's `precision` setting (which PDO would use, and which
 * cuts after 14 digits) or the locale say. Without an exponent: MariaDB reads such a text back
 * exactly, from 5e-324 to 1.7976931348623157e308 (measured on 10.11, 11.4 and 12.3).
 *
 * @internal
 */
final class FloatText
{
    /**
     * @param float $value A finite float (INF and NAN have no decimal text)
     */
    public static function of(float $value): string
    {
        // PHP's own shortest round-trip writer (zend_gcvt, mode 0) at precision -1, without a
        // locale (%H) and without an ini setting: "1.0E+21", "5.684341886080802E-14", "100", "-0"
        $written = sprintf('%.*H', -1, $value);
        [$mantissa, $exponent] = array_pad(explode('E', ltrim($written, '-')), 2, '0');
        [$whole, $part] = array_pad(explode('.', $mantissa), 2, '');
        $digits = $whole . $part;
        $point = strlen($whole) + (int) $exponent; // how many of the digits stand before the decimal point
        $integer = $point <= 0 ? '0' : str_pad(substr($digits, 0, $point), $point, '0');
        $fraction = rtrim($point <= 0 ? str_repeat('0', -$point) . $digits : substr($digits, $point), '0');

        return ($value < 0 ? '-' : '') . $integer . ($fraction === '' ? '' : '.' . $fraction);
    }
}
