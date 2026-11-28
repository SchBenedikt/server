<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Server\Support;

use function array_key_exists;
use function count;
use function explode;
use function floor;
use function implode;
use function ksort;
use function preg_match;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;

/**
 * In-process Prometheus-style metrics registry.
 *
 * Cardinality is bounded by construction: label values pass through
 * {@see sanitizeLabelValue} and every metric name is a fixed constant chosen
 * by the caller — never a raw URL, user id or post content. The registry is
 * per-process; scrape-time gauges are added separately by the reporter.
 */
final class Metrics
{
    /** @var array<string, array<string, float>> metric => labelKey => value */
    private array $counters = [];

    /** @var array<string, array{sum: float, count: int}> */
    private array $observations = [];

    /** @var array<string, array<string, float>> */
    private array $gauges = [];

    private const MAX_SERIES = 500;

    /**
     * @param array<string, string> $labels
     */
    public function increment(string $name, array $labels = [], float $by = 1.0): void
    {
        $this->add($this->counters, $name, $labels, $by);
    }

    /**
     * @param array<string, string> $labels
     */
    public function observe(string $name, float $value, array $labels = []): void
    {
        $key = $this->seriesKey($labels);
        if (!array_key_exists($name, $this->observations)) {
            $this->observations[$name] = [];
        }
        $series = $this->observations[$name][$key] ?? ['sum' => 0.0, 'count' => 0];
        $series['sum'] += $value;
        $series['count']++;
        $this->observations[$name][$key] = $series;
    }

    /**
     * @param array<string, string> $labels
     */
    public function set(string $name, float $value, array $labels = []): void
    {
        $this->add($this->gauges, $name, $labels, $value, true);
    }

    /**
     * @param array<string, array<string, float>> $store
     * @param array<string, string> $labels
     */
    private function add(array &$store, string $name, array $labels, float $value, bool $assign = false): void
    {
        if (!array_key_exists($name, $store)) {
            $store[$name] = [];
        }
        $key = $this->seriesKey($labels);
        $total = count($store[$name]);
        if (!$assign && $total >= self::MAX_SERIES && !array_key_exists($key, $store[$name])) {
            // Cardinality guard: unknown label combinations collapse into a
            // single overflow series rather than growing without bound.
            $key = $this->seriesKey(['overflow' => 'true']);
        } elseif ($assign && $total >= self::MAX_SERIES && !array_key_exists($key, $store[$name])) {
            return;
        }
        $store[$name][$key] = $assign ? $value : ($store[$name][$key] ?? 0.0) + $value;
    }

    /**
     * @param array<string, string> $labels
     */
    private function seriesKey(array $labels): string
    {
        ksort($labels);
        $parts = [];
        foreach ($labels as $name => $value) {
            $parts[] = $name . '=' . self::sanitizeLabelValue($value);
        }

        return implode(',', $parts);
    }

    public static function sanitizeLabelValue(string $value): string
    {
        $clean = '';
        $length = strlen($value);
        for ($i = 0; $i < $length && strlen($clean) < 64; $i++) {
            $char = $value[$i];
            if (preg_match('/[a-zA-Z0-9_.\-\/{}]/', $char) === 1) {
                $clean .= $char;
            } else {
                $clean .= '_';
            }
        }

        return $clean === '' ? 'unknown' : $clean;
    }

    /**
     * Maps a concrete request path to a low-cardinality route template:
     * long id-like segments collapse to `{id}` so `/api/v1/posts/abc123…`
     * and `/api/v1/posts/def456…` share one series.
     */
    public static function routeTemplate(string $path): string
    {
        $segments = explode('/', trim($path, '/'));
        foreach ($segments as $index => $segment) {
            if ($segment === '') {
                continue;
            }
            $looksLikeId = strlen($segment) > 24
                || preg_match('/^\d+$/', $segment) === 1
                || preg_match('/^[0-9a-f]{8}-[0-9a-f-]{8,}$/i', $segment) === 1
                || str_contains($segment, '@')
                || preg_match('/^[A-Za-z0-9_-]{20,}$/', $segment) === 1;
            if ($looksLikeId) {
                $segments[$index] = '{id}';
            }
        }

        $template = '/' . implode('/', $segments);

        return strlen($template) > 120 ? substr($template, 0, 120) : $template;
    }

    /**
     * Prometheus text exposition for all recorded series plus the supplied
     * scrape-time gauges.
     *
     * @param array<string, float> $gauges name => value (already labelled by caller if needed)
     */
    public function render(array $gauges = []): string
    {
        $out = '';
        ksort($this->counters);
        foreach ($this->counters as $name => $series) {
            $out .= $this->renderSeries($name, $series, 'counter') . "\n";
        }
        ksort($this->observations);
        foreach ($this->observations as $name => $series) {
            $rendered = [];
            foreach ($series as $key => $stats) {
                $rendered[$key] = ['sum' => $stats['sum'], 'count' => $stats['count']];
            }
            ksort($rendered);
            foreach ($rendered as $key => $stats) {
                $labels = $this->labelsFromKey($key);
                $suffix = $labels === '' ? '' : '{' . $labels . '}';
                $out .= $name . '_sum' . $suffix . ' ' . $this->number($stats['sum']) . "\n";
                $out .= $name . '_count' . $suffix . ' ' . $this->number((float) $stats['count']) . "\n";
            }
        }
        ksort($this->gauges);
        foreach ($this->gauges as $name => $series) {
            $out .= $this->renderSeries($name, $series, 'gauge') . "\n";
        }
        ksort($gauges);
        foreach ($gauges as $name => $value) {
            $out .= $name . ' ' . $this->number($value) . "\n";
        }

        return $out;
    }

    /**
     * @param array<string, float> $series
     */
    private function renderSeries(string $name, array $series, string $type): string
    {
        ksort($series);
        $out = '# TYPE ' . $name . ' ' . $type . "\n";
        foreach ($series as $key => $value) {
            $labels = $this->labelsFromKey($key);
            $suffix = $labels === '' ? '' : '{' . $labels . '}';
            $out .= $name . $suffix . ' ' . $this->number($value) . "\n";
        }

        return $out;
    }

    private function labelsFromKey(string $key): string
    {
        if ($key === '') {
            return '';
        }

        $parts = [];
        foreach (explode(',', $key) as $pair) {
            [$name, $value] = explode('=', $pair, 2) + [1 => ''];
            $parts[] = $name . '="' . str_replace('"', '\\"', $value) . '"';
        }

        return implode(',', $parts);
    }

    private function number(float $value): string
    {
        if (is_int($value) || $value === floor($value)) {
            return (string) (int) $value;
        }

        return sprintf('%.6F', $value);
    }
}
