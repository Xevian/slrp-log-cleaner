<?php

declare(strict_types=1);

require_once __DIR__ . '/LogEntry.php';

class LogParser
{
    private const TIMESTAMP_RE = '/^\[(\d{4}\/\d{2}\/\d{2} \d{2}:\d{2})\]  (.+?): (.*)$/u';
    private const SPEAKER_WITH_USER_RE = '/^(.+?) \(([^)]+)\)$/u';
    private const ACTION_RE = '/^\/me\s+(.*)$/su';

    private int $maxBytes;

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

        $groups  = $this->splitIntoGroups($rawText);
        $entries = $this->buildEntries($groups);

        if ($mergeSplits) {
            $entries = $this->mergeSplitPosts($entries);
        }

        return $entries;
    }

    /**
     * Phase 1: split raw text into groups of [timestampLine, speaker, initialContent, continuations[]]
     * @return array<array{string, string, string, string[]}>
     */
    private function splitIntoGroups(string $rawText): array
    {
        $rawText = str_replace(["\r\n", "\r"], "\n", $rawText);
        $lines   = explode("\n", $rawText);
        $groups  = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match(self::TIMESTAMP_RE, $line, $m)) {
                if ($current !== null) {
                    $groups[] = $current;
                }
                $current = [$m[1], $m[2], $m[3], []];
            } elseif ($current !== null) {
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
     * Phase 2: build LogEntry objects from groups
     * @param  array<array{string, string, string, string[]}> $groups
     * @return LogEntry[]
     */
    private function buildEntries(array $groups): array
    {
        $entries = [];

        foreach ($groups as [$rawTs, $rawSpeaker, $initialContent, $continuations]) {
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

            [$isAction, $content] = $this->detectAction($content);

            $isSystem = ($rawSpeaker === 'Second Life');

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
     * Phase 3: merge split posts — same speaker, same minute bucket
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
                    !self::isOocContent($current->content) &&    // don't merge OOC entries
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
        // Full OOC block: (( ... ))
        if (preg_match('/^\s*\(\(.*\)\)\s*$/su', $content)) return true;
        // Truncated OOC: ends with )) but has no opening ((
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
