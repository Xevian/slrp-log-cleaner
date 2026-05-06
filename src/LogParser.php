<?php

declare(strict_types=1);

require_once __DIR__ . '/LogEntry.php';

class LogParser
{
    // Full SL format: [YYYY/MM/DD HH:MM]  Speaker: content  (two spaces after ])
    private const TIMESTAMP_RE = '/^\[(\d{4}\/\d{2}\/\d{2} \d{2}:\d{2})\]  (.+?): (.*)$/u';

    // Short format patterns — tried in order, first match wins
    // 1. [HH:MM] Name (username): content  (speech, has colon after closing paren)
    private const SHORT_SPEECH_USER_RE = '/^\[(\d{2}:\d{2})\] (.+?) \(([^)]+)\): (.*)$/u';
    // 2. [HH:MM] Name (username) content  (action, no colon after closing paren)
    private const SHORT_ACTION_USER_RE = '/^\[(\d{2}:\d{2})\] (.+?) \(([^)]+)\) (.+)$/u';
    // 3. [HH:MM] Name: content  (speech, no username)
    private const SHORT_SPEECH_RE = '/^\[(\d{2}:\d{2})\] ([^(:\n]+?): (.*)$/u';
    // 4. [HH:MM] CapsWord+ action  (action, no username — name is 1–4 title-case words)
    private const SHORT_ACTION_RE = '/^\[(\d{2}:\d{2})\] ((?:\p{Lu}\S+)(?:\s+\p{Lu}\S+){0,3}) (.+)$/su';

    private const SPEAKER_WITH_USER_RE = '/^(.+?) \(([^)]+)\)$/u';
    private const ACTION_RE = '/^\/me\s+(.*)$/su';

    private int $maxBytes;

    // State for short-format midnight crossing (reset each parse() call)
    private int $shortLastMinutes = -1;
    private int $shortDayOffset   = 0;

    public function __construct(int $maxBytes = 5_242_880)
    {
        $this->maxBytes = $maxBytes;
    }

    /**
     * @return LogEntry[]
     */
    public function parse(string $rawText, bool $mergeSplits = true): array
    {
        if (strlen($rawText) > $this->maxBytes) {
            throw new \OverflowException('Log file exceeds maximum size of ' . ($this->maxBytes / 1048576) . 'MB');
        }

        // Reset short-format midnight tracking
        $this->shortLastMinutes = -1;
        $this->shortDayOffset   = 0;

        $groups  = $this->splitIntoGroups($rawText);
        $entries = $this->buildEntries($groups);

        if ($mergeSplits) {
            $entries = $this->mergeSplitPosts($entries);
        }

        return $entries;
    }

    /**
     * Phase 1: split raw text into groups.
     *
     * Each group: [rawTs, rawSpeaker, initialContent, continuations[], shortFormatAction]
     *   shortFormatAction = null  → full format (action detected via /me prefix in phase 2)
     *   shortFormatAction = false → short format speech
     *   shortFormatAction = true  → short format action
     *
     * @return array<array{string, string, string, string[], bool|null}>
     */
    private function splitIntoGroups(string $rawText): array
    {
        $rawText = str_replace(["\r\n", "\r"], "\n", $rawText);
        $lines   = explode("\n", $rawText);
        $groups  = [];
        $current = null;

        foreach ($lines as $line) {
            // Full format: [YYYY/MM/DD HH:MM]  Name: content
            if (preg_match(self::TIMESTAMP_RE, $line, $m)) {
                if ($current !== null) $groups[] = $current;
                $current = [$m[1], $m[2], $m[3], [], null];

            // Short: [HH:MM] Name (username): content  (speech)
            } elseif (preg_match(self::SHORT_SPEECH_USER_RE, $line, $m)) {
                if ($current !== null) $groups[] = $current;
                $ts = $this->resolveShortTs($m[1]);
                $current = [$ts, $m[2] . ' (' . $m[3] . ')', $m[4], [], false];

            // Short: [HH:MM] Name (username) content  (action)
            } elseif (preg_match(self::SHORT_ACTION_USER_RE, $line, $m)) {
                if ($current !== null) $groups[] = $current;
                $ts = $this->resolveShortTs($m[1]);
                $current = [$ts, $m[2] . ' (' . $m[3] . ')', $m[4], [], true];

            // Short: [HH:MM] Name: content  (speech, no username)
            } elseif (preg_match(self::SHORT_SPEECH_RE, $line, $m)) {
                if ($current !== null) $groups[] = $current;
                $ts = $this->resolveShortTs($m[1]);
                $current = [$ts, $m[2], $m[3], [], false];

            // Short: [HH:MM] CapsName action  (action, no username)
            } elseif (preg_match(self::SHORT_ACTION_RE, $line, $m)) {
                if ($current !== null) $groups[] = $current;
                $ts = $this->resolveShortTs($m[1]);
                $current = [$ts, $m[2], $m[3], [], true];

            } elseif ($current !== null) {
                // Continuation line — belongs to the current group
                $current[3][] = $line;
            }
            // Lines before the first timestamp are discarded
        }

        if ($current !== null) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * Resolve a [HH:MM] timestamp to a sortable "YYYY/MM/DD HH:MM" string,
     * incrementing the day offset when midnight is detected.
     */
    private function resolveShortTs(string $hhmm): string
    {
        [$h, $i] = array_map('intval', explode(':', $hhmm));
        $mins = $h * 60 + $i;

        // Detect midnight crossing: new time more than 30 min earlier than previous
        if ($this->shortLastMinutes >= 0 && $mins < $this->shortLastMinutes - 30) {
            $this->shortDayOffset++;
        }
        $this->shortLastMinutes = $mins;

        $date = (new DateTimeImmutable('2000-01-01'))
            ->modify("+{$this->shortDayOffset} days");

        return $date->format('Y/m/d') . ' ' . $hhmm;
    }

    /**
     * Phase 2: build LogEntry objects from groups.
     *
     * @param  array<array{string, string, string, string[], bool|null}> $groups
     * @return LogEntry[]
     */
    private function buildEntries(array $groups): array
    {
        $entries = [];

        foreach ($groups as $group) {
            [$rawTs, $rawSpeaker, $initialContent, $continuations] = $group;
            $shortFormatAction = $group[4] ?? null;

            [$displayName, $username, $isStar] = $this->parseSpeaker($rawSpeaker);

            // Assemble full content
            $parts = [$initialContent];
            foreach ($continuations as $line) {
                $parts[] = $line;
            }
            $content = implode("\n", $parts);
            // Collapse 3+ newlines to 2 (preserve paragraph breaks but not excessive blanks)
            $content = preg_replace('/\n{3,}/', "\n\n", $content);
            $content = trim($content);

            // Action detection
            if ($shortFormatAction !== null) {
                // Short format: action-ness determined by which regex matched
                $isAction = $shortFormatAction;
            } else {
                // Full format: detect /me prefix
                [$isAction, $content] = $this->detectAction($content);
            }

            $isSystem = ($displayName === 'Second Life' || $rawSpeaker === 'Second Life');

            $time = DateTimeImmutable::createFromFormat('Y/m/d H:i', $rawTs) ?: null;

            $entries[] = new LogEntry(
                $rawTs,
                $rawSpeaker,
                $content,
                $displayName,
                $username,
                $isAction,
                $isSystem,
                $isStar,
                $time
            );
        }

        return $entries;
    }

    /**
     * Phase 3: merge split posts — same speaker, same minute bucket.
     *
     * @param  LogEntry[] $entries
     * @return LogEntry[]
     */
    private function mergeSplitPosts(array $entries): array
    {
        $result = [];
        $count  = count($entries);
        $i      = 0;

        while ($i < $count) {
            $current = clone $entries[$i];
            $j       = $i + 1;

            while ($j < $count) {
                $candidate = $entries[$j];

                if (
                    $candidate->minuteKey() === $current->minuteKey() &&
                    $candidate->speakerKey() === $current->speakerKey() &&
                    !$candidate->isAction &&
                    !self::isStatusContent($current->content) &&
                    !self::isStatusContent($candidate->content) &&
                    !self::isOocContent($current->content) &&
                    !self::isOocContent($candidate->content)
                ) {
                    $current->content = rtrim($current->content) . ' ' . ltrim($candidate->content);
                    $j++;
                } else {
                    break;
                }
            }

            $result[] = $current;
            $i        = $j;
        }

        return $result;
    }

    private static function isStatusContent(string $content): bool
    {
        return (bool) preg_match('/^is (online|offline)\.\s*$/u', $content);
    }

    private static function isOocContent(string $content): bool
    {
        if (preg_match('/^\s*\(\(.*\)\)\s*$/su', $content)) return true;
        return (bool) preg_match('/\)\)\s*$/', $content) && !str_contains($content, '((');
    }

    /**
     * Parse "Display Name (username)" or "Display Name" or "*SystemName"
     * @return array{string, string, bool}  [displayName, username, isStar]
     */
    private function parseSpeaker(string $raw): array
    {
        $isStar = str_starts_with($raw, '*');
        $clean  = $isStar ? substr($raw, 1) : $raw;

        if (preg_match(self::SPEAKER_WITH_USER_RE, $clean, $m)) {
            return [$m[1], $m[2], $isStar];
        }

        return [$clean, '', $isStar];
    }

    /**
     * @return array{bool, string}  [isAction, cleanContent]
     */
    private function detectAction(string $content): array
    {
        if (preg_match(self::ACTION_RE, $content, $m)) {
            return [true, $m[1]];
        }
        return [false, $content];
    }
}
