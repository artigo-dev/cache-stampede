<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/cache-stampede package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Draws the two pictures the numbers are for, into docs/.
 *
 *   php benchmarks/charts.php
 *
 * The data below is measured output, not illustration - each block says which
 * script produced it and under what conditions. Re-run those, edit these, and
 * regenerate; nothing here is drawn from imagination.
 */

namespace Artigo\Cache\Benchmarks;

/*
 * php benchmarks/stampede.php workers=12 resolverMs=300 hosts=N
 * Twelve concurrent misses on one cold key. How many of them reached the
 * origin, by the number of application servers in the fleet.
 */
const STAMPEDE = [
    'labels' => ['1 host', '3 hosts', '6 hosts', '12 hosts'],
    'series' => [
        ['name' => 'MemoLock / FleetLock', 'colour' => '#3ddc97', 'values' => [1, 1, 1, 1]],
        ['name' => 'LockRegistry', 'colour' => '#ff6b6b', 'values' => [1, 3, 6, 12]],
    ],
];

/*
 * php benchmarks/stampede.php workers=N resolverMs=300 keys=collide
 * Distinct cold keys that share one of LockRegistry's lock files. Milliseconds
 * for the slowest of them, where each takes 300 ms to compute.
 */
const FALSE_SHARING = [
    'labels' => ['2 keys', '4 keys', '6 keys', '8 keys'],
    'series' => [
        ['name' => 'one lock per key', 'colour' => '#3ddc97', 'values' => [322, 322, 322, 321]],
        ['name' => 'LockRegistry', 'colour' => '#ff6b6b', 'values' => [707, 1508, 2309, 3111]],
    ],
];

$out = \dirname(__DIR__).'/docs';

if (!is_dir($out) && !mkdir($out, 0o777, true) && !is_dir($out)) {
    fwrite(\STDERR, 'Could not create docs/.'.\PHP_EOL);

    exit(1);
}

file_put_contents($out.'/stampede.svg', chart(
    'Requests reaching the origin, out of twelve',
    'application servers in the fleet',
    STAMPEDE,
    logarithmic: false,
    format: static fn (float $v): string => (string) (int) $v,
));

file_put_contents($out.'/false-sharing.svg', chart(
    'Unrelated keys that share a lock file, in milliseconds',
    'distinct items going cold at the same moment',
    FALSE_SHARING,
    logarithmic: false,
    format: static fn (float $v): string => number_format($v),
));

echo 'wrote docs/stampede.svg and docs/false-sharing.svg'.\PHP_EOL;

/**
 * @param array{labels: list<string>, series: list<array{name: string, colour: string, values: list<int>}>} $data
 */
function chart(string $title, string $axis, array $data, bool $logarithmic, \Closure $format): string
{
    $width = 760;
    $height = 380;
    $left = 78;
    $right = 250;
    $top = 62;
    $bottom = 64;

    $plotWidth = $width - $left - $right;
    $plotHeight = $height - $top - $bottom;

    $values = [];

    foreach ($data['series'] as $series) {
        foreach ($series['values'] as $value) {
            $values[] = (float) $value;
        }
    }

    $max = max($values);
    $min = $logarithmic ? 1.0 : 0.0;

    $scale = static function (float $value) use ($logarithmic, $min, $max, $top, $plotHeight): float {
        $position = $logarithmic
            ? (log10(max($value, $min)) - log10($min)) / (log10($max) - log10($min))
            : $value / $max;

        return $top + $plotHeight - $position * $plotHeight;
    };

    $count = \count($data['labels']);
    // an inset, so the first point does not sit on the axis and its label does
    // not land on top of a gridline number
    $inset = 30.0;
    $span = $plotWidth - $inset;
    $x = static fn (int $index): float => $left + $inset + ($count > 1 ? $index * ($span - $inset) / ($count - 1) : $span / 2);

    $svg = [];
    $svg[] = \sprintf('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" font-family="Rajdhani, system-ui, -apple-system, Segoe UI, sans-serif">', $width, $height, $width, $height);
    $svg[] = '<style>@font-face { font-family: "Rajdhani"; font-weight: 600; src: url("src/fonts/rajdhani-600.woff2") format("woff2"); }'
        .'@font-face { font-family: "Rajdhani"; font-weight: 700; src: url("src/fonts/rajdhani-700.woff2") format("woff2"); }</style>';
    $svg[] = \sprintf('<rect width="%d" height="%d" fill="#0a0a12"/>', $width, $height);
    $svg[] = \sprintf('<text x="%d" y="32" font-size="17" font-weight="600" fill="#e8e8f0">%s</text>', $left - 40, e($title));

    // gridlines
    $ticks = $logarithmic ? [1, 10, 100, 1000, 10000, 100000] : range(0, (int) $max, max(1, (int) ceil($max / 4)));

    foreach ($ticks as $tick) {
        if ($tick > $max * 1.2 || ($logarithmic && $tick < 1)) {
            continue;
        }

        $y = $scale((float) $tick);
        $svg[] = \sprintf('<line x1="%d" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#1c1c2a" stroke-width="1"/>', $left, $y, $left + $plotWidth, $y);
        $svg[] = \sprintf('<text x="%d" y="%.1f" font-size="11" fill="#8b8ba7" text-anchor="end">%s</text>', $left - 10, $y + 4, e($format((float) $tick)));
    }

    // where two series share a point their labels would sit on each other:
    // rank them at every x and send the lower one underneath
    $rank = [];

    foreach ($data['labels'] as $index => $ignored) {
        $column = [];

        foreach ($data['series'] as $position => $series) {
            $column[$position] = $series['values'][$index];
        }

        arsort($column);
        $rank[$index] = array_flip(array_keys($column));
    }

    // the series
    foreach ($data['series'] as $position => $series) {
        $points = [];

        foreach ($series['values'] as $index => $value) {
            $points[] = \sprintf('%.1f,%.1f', $x($index), $scale((float) $value));
        }

        $svg[] = \sprintf('<polyline points="%s" fill="none" stroke="%s" stroke-width="2.5" stroke-linejoin="round"/>', implode(' ', $points), $series['colour']);

        foreach ($series['values'] as $index => $value) {
            $svg[] = \sprintf('<circle cx="%.1f" cy="%.1f" r="4.5" fill="%s"/>', $x($index), $scale((float) $value), $series['colour']);
            $offset = 0 === $rank[$index][$position] ? -12.0 : 19.0;
            $svg[] = \sprintf('<text x="%.1f" y="%.1f" font-size="11.5" font-weight="600" fill="%s" text-anchor="middle">%s</text>',
                $x($index), $scale((float) $value) + $offset, $series['colour'], e($format((float) $value)));
        }

        // the line names itself at its own end, so no legend is needed
        $last = \count($series['values']) - 1;
        $svg[] = \sprintf('<text x="%.1f" y="%.1f" font-size="13" font-weight="600" fill="%s">%s</text>',
            $x($last) + 14, $scale((float) $series['values'][$last]) + 4, $series['colour'], e($series['name']));
    }

    // the x axis
    $svg[] = \sprintf('<line x1="%d" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#26263a" stroke-width="1"/>', $left, $top + $plotHeight, $left + $plotWidth, $top + $plotHeight);

    foreach ($data['labels'] as $index => $label) {
        $svg[] = \sprintf('<text x="%.1f" y="%.1f" font-size="12" fill="#b8b8c8" text-anchor="middle">%s</text>', $x($index), $top + $plotHeight + 22, e($label));
    }

    $svg[] = \sprintf('<text x="%.1f" y="%.1f" font-size="11.5" fill="#8b8ba7" text-anchor="middle">%s</text>', $left + $plotWidth / 2, $height - 16, e($axis));
    $svg[] = '</svg>';

    return implode("\n", $svg)."\n";
}

function e(string $text): string
{
    return htmlspecialchars($text, \ENT_QUOTES | \ENT_XML1);
}
