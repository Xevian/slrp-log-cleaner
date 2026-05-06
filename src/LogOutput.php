<?php

declare(strict_types=1);

require_once __DIR__ . '/LogEntry.php';

class LogOutput
{
    /**
     * @param  LogEntry[] $entries
     * @return array{summary: string, cleaned_log: string, stats: array}
     */
    public function generate(array $entries, int $minPosts = 2): array
    {
        $stats      = $this->calculateStats($entries, $minPosts);
        $summary    = $this->buildSummary($stats);
        $cleanedLog = $this->buildLog($entries);

        return [
            'summary'     => $summary,
            'cleaned_log' => $cleanedLog,
            'stats'       => $stats,
        ];
    }

    private function calculateStats(array $entries, int $minPosts = 2): array
    {
        if (empty($entries)) {
            return [
                'start'            => null,
                'end'              => null,
                'duration_minutes' => 0,
                'participants'     => [],
            ];
        }

        $participants = [];
        $allTimes     = [];

        foreach ($entries as $entry) {
            if ($entry->time) {
                $allTimes[] = $entry->time;
            }

            $key = $entry->speakerKey();
            if (!isset($participants[$key])) {
                $participants[$key] = [
                    'display_name'     => $entry->displayName,
                    'username'         => $entry->username,
                    'post_count'       => 0,
                    'total_chars'      => 0,
                    'first_post_time'  => $entry->time,
                    'last_post_time'   => $entry->time,
                    'duration_minutes' => 0,
                ];
            }

            $p = &$participants[$key];
            if (!$this->isOoc($entry->content)) {
                $p['post_count']++;
                $p['total_chars'] += mb_strlen($entry->content, 'UTF-8');
            }

            if ($entry->time) {
                if (!$p['first_post_time'] || $entry->time < $p['first_post_time']) {
                    $p['first_post_time'] = $entry->time;
                }
                if (!$p['last_post_time'] || $entry->time > $p['last_post_time']) {
                    $p['last_post_time'] = $entry->time;
                }
            }
        }

        // Calculate durations and format times
        foreach ($participants as $key => &$p) {
            if ($p['first_post_time'] && $p['last_post_time']) {
                $diff                  = $p['last_post_time']->diff($p['first_post_time']);
                $p['duration_minutes'] = abs($diff->h * 60 + $diff->i + $diff->days * 1440);
                $p['first_post']       = $p['first_post_time']->format(\DateTimeInterface::ATOM);
                $p['last_post']        = $p['last_post_time']->format(\DateTimeInterface::ATOM);
            } else {
                $p['duration_minutes'] = 0;
                $p['first_post']       = null;
                $p['last_post']        = null;
            }
            unset($p['first_post_time'], $p['last_post_time']);
        }

        // Drop participants below the minimum post threshold
        $participants = array_filter($participants, fn($p) => $p['post_count'] >= $minPosts);

        // Sort participants by post count descending
        uasort($participants, fn($a, $b) => $b['post_count'] <=> $a['post_count']);

        $start = !empty($allTimes) ? min($allTimes) : null;
        $end   = !empty($allTimes) ? max($allTimes) : null;

        $durationMinutes = 0;
        if ($start && $end) {
            $diff            = $end->diff($start);
            $durationMinutes = abs($diff->h * 60 + $diff->i + $diff->days * 1440);
        }

        return [
            'start'            => $start?->format(\DateTimeInterface::ATOM),
            'end'              => $end?->format(\DateTimeInterface::ATOM),
            'date_display'     => $start?->format('d/m/Y'),
            'duration_minutes' => $durationMinutes,
            'participants'     => array_values($participants),
        ];
    }

    private function buildSummary(array $stats): string
    {
        $lines = [];

        // date_display is null for short-format logs (no date in timestamps)
        $startDt = ($stats['start'] ?? null) ? new DateTimeImmutable($stats['start']) : null;
        if ($startDt && $startDt->format('Y') !== '2000') {
            $lines[] = 'DATE: ' . $startDt->format('d/m/Y');
        }

        $endDt = ($stats['end'] ?? null) ? new DateTimeImmutable($stats['end']) : null;
        if ($startDt && $endDt) {
            $lines[] = 'TIME: ' . $startDt->format('H:i') . ' – ' . $endDt->format('H:i');
        }

        $lines[] = 'DURATION: ' . $this->formatDuration($stats['duration_minutes']);
        $lines[] = '';

        // Build participant rows first so we can measure column widths
        $rows = [];
        foreach ($stats['participants'] as $p) {
            $name    = $p['display_name'];
            if ($p['username']) $name .= ' (' . $p['username'] . ')';
            $posts   = (string) $p['post_count'];
            $est     = $this->formatDurationShort($p['duration_minutes']);
            $arrived = $p['first_post']
                ? (new DateTimeImmutable($p['first_post']))->format('H:i')
                : '—';
            $rows[] = [$name, $posts, $est, $arrived];
        }

        $lines[] = 'PARTICIPANTS:';

        if (empty($rows)) {
            $lines[] = '  (none above minimum post threshold)';
        } else {
            // Column widths
            $colName = max(4, ...array_map(fn($r) => mb_strlen($r[0]), $rows));
            $colPost = max(5, ...array_map(fn($r) => strlen($r[1]), $rows));
            $colEst  = max(3, ...array_map(fn($r) => strlen($r[2]), $rows));

            $pad = fn(string $s, int $w) => $s . str_repeat(' ', max(0, $w - mb_strlen($s)));

            $lines[] = '  ' . $pad('Name', $colName) . '  ' . str_pad('Posts', $colPost, ' ', STR_PAD_LEFT) . '  ' . $pad('Est.', $colEst) . '  Arrived';
            $lines[] = '  ' . str_repeat('-', $colName + $colPost + $colEst + 18);

            foreach ($rows as [$name, $posts, $est, $arrived]) {
                $lines[] = '  ' . $pad($name, $colName) . '  ' . str_pad($posts, $colPost, ' ', STR_PAD_LEFT) . '  ' . $pad($est, $colEst) . '  ' . $arrived;
            }
        }

        $lines[] = '';
        $lines[] = str_repeat('-', 40);
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function buildLog(array $entries): string
    {
        $lines = [];

        foreach ($entries as $entry) {
            $time    = $entry->formatTime();
            $name    = $entry->displayName;
            $content = $entry->content;

            if ($entry->isAction) {
                $lines[] = '[' . $time . '] *' . $name . ' ' . $content;
            } else {
                $lines[] = '[' . $time . '] ' . $name . ': ' . $content;
            }
        }

        return implode("\n", $lines);
    }

    private function isOoc(string $content): bool
    {
        if (preg_match('/^\s*\(\(.*\)\)\s*$/su', $content)) {
            return true;
        }
        // Truncated OOC: ends with )) but has no opening ((
        if (preg_match('/\)\)\s*$/', $content) && !str_contains($content, '((')) {
            return true;
        }
        return false;
    }

    private function formatDurationShort(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $mins  = $minutes % 60;
        if ($hours === 0) return $mins . 'm';
        if ($mins  === 0) return $hours . 'h';
        return $hours . 'h ' . $mins . 'm';
    }

    public function formatDuration(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $mins  = $minutes % 60;

        $hourLabel = $hours === 1 ? 'hour' : 'hours';
        $minLabel  = $mins === 1 ? 'minute' : 'minutes';

        if ($hours === 0) {
            return $mins . ' ' . $minLabel;
        }
        if ($mins === 0) {
            return $hours . ' ' . $hourLabel;
        }
        return $hours . ' ' . $hourLabel . ' ' . $mins . ' ' . $minLabel;
    }
}
