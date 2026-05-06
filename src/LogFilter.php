<?php

declare(strict_types=1);

require_once __DIR__ . '/LogEntry.php';

class LogFilter
{
    const FILTER_STATUS     = 'status';
    const FILTER_SYSTEM     = 'system';
    const FILTER_MUSIC      = 'music';
    const FILTER_DELIVERIES = 'deliveries';
    const FILTER_COMBAT     = 'combat';
    const FILTER_RPTOOL     = 'rptool';
    const FILTER_OOC_FULL   = 'ooc_full';
    const FILTER_OOC_INLINE = 'ooc_inline';
    const FILTER_OBJECTS    = 'objects';

    const ALL_FILTERS = [
        self::FILTER_STATUS,
        self::FILTER_SYSTEM,
        self::FILTER_MUSIC,
        self::FILTER_DELIVERIES,
        self::FILTER_COMBAT,
        self::FILTER_RPTOOL,
        self::FILTER_OOC_FULL,
        self::FILTER_OOC_INLINE,
        self::FILTER_OBJECTS,
    ];

    const PRESETS = [
        'light'  => [self::FILTER_STATUS, self::FILTER_MUSIC],
        'medium' => [self::FILTER_STATUS, self::FILTER_SYSTEM, self::FILTER_MUSIC,
                     self::FILTER_DELIVERIES, self::FILTER_COMBAT, self::FILTER_RPTOOL,
                     self::FILTER_OBJECTS],
        'full'   => [self::FILTER_STATUS, self::FILTER_SYSTEM, self::FILTER_MUSIC,
                     self::FILTER_DELIVERIES, self::FILTER_COMBAT, self::FILTER_RPTOOL,
                     self::FILTER_OOC_FULL, self::FILTER_OOC_INLINE, self::FILTER_OBJECTS],
        'custom' => [],
    ];

    private const OBJECT_SPEAKER_KEYWORDS = [
        'Redelivery', 'CasperTech', 'Allomancy', 'Vendor', 'Magic Box',
        'Dispenser', 'Unpacker', 'Crate', 'Marketplace', 'Server',
        'Drop Box', 'DropBox', 'Gift', 'Store',
    ];

    /**
     * @param  LogEntry[]  $entries
     * @param  string[]    $activeFilters
     * @param  string[]    $customPatterns
     * @param  string[]    $ignoredSpeakers  speakerKey values to remove entirely
     * @return LogEntry[]
     */
    public function apply(array $entries, array $activeFilters, array $customPatterns = [], array $ignoredSpeakers = []): array
    {
        // Validate filter keys against whitelist
        $activeFilters = array_intersect($activeFilters, self::ALL_FILTERS);

        // Normalise ignored speaker keys
        $ignoredSpeakers = array_map('mb_strtolower', $ignoredSpeakers);

        // Pass 1 — mutation: strip inline OOC from content without removing entries
        if (in_array(self::FILTER_OOC_INLINE, $activeFilters, true)) {
            foreach ($entries as $entry) {
                $this->stripInlineOoc($entry);
            }
        }

        // Pass 2 — removal
        $filtered = [];
        foreach ($entries as $entry) {
            if (!empty($ignoredSpeakers) && in_array($entry->speakerKey(), $ignoredSpeakers, true)) {
                continue;
            }
            if ($this->shouldRemove($entry, $activeFilters, $customPatterns)) {
                continue;
            }
            // Skip entries that became empty after inline OOC stripping
            if (trim($entry->content) === '') {
                continue;
            }
            $filtered[] = $entry;
        }

        return $filtered;
    }

    private function shouldRemove(LogEntry $entry, array $activeFilters, array $customPatterns): bool
    {
        foreach ($activeFilters as $filter) {
            $remove = match ($filter) {
                self::FILTER_STATUS     => $this->isStatusMessage($entry),
                self::FILTER_MUSIC      => $this->isMusicMessage($entry),
                self::FILTER_SYSTEM     => $this->isSystemMessage($entry),
                self::FILTER_DELIVERIES => $this->isDeliveryMessage($entry),
                self::FILTER_COMBAT     => $this->isCombatMessage($entry),
                self::FILTER_RPTOOL     => $this->isRpToolMessage($entry),
                self::FILTER_OOC_FULL   => $this->isOocFull($entry),
                self::FILTER_OBJECTS    => $this->isObjectNotice($entry),
                default                 => false,
            };
            if ($remove) {
                return true;
            }
        }

        foreach ($customPatterns as $pattern) {
            if ($this->matchesCustomPattern($entry, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function isStatusMessage(LogEntry $entry): bool
    {
        return (bool) preg_match('/^is (online|offline)\.\s*$/u', $entry->content);
    }

    private function isMusicMessage(LogEntry $entry): bool
    {
        return $entry->isSystem &&
               (bool) preg_match('/^Now playing:/iu', $entry->content);
    }

    private function isSystemMessage(LogEntry $entry): bool
    {
        if (!$entry->isSystem) {
            return false;
        }
        // Let music filter handle music-specific lines independently
        if ($this->isMusicMessage($entry)) {
            return false;
        }
        return true;
    }

    private function isDeliveryMessage(LogEntry $entry): bool
    {
        // Second Life system delivery lines
        if ($entry->isSystem) {
            return (bool) preg_match('/(gave you|has been redelivered|secondlife:\/\/)/iu', $entry->content);
        }
        // Third-party vendor delivery bots (CasperVend, etc.)
        if (preg_match('/^CasperVend/iu', $entry->speaker)) {
            return true;
        }
        return false;
    }

    private function isCombatMessage(LogEntry $entry): bool
    {
        return $entry->isStar &&
               (bool) preg_match('/\*(CCS|MTR|METER)/iu', $entry->speaker);
    }

    private function isRpToolMessage(LogEntry $entry): bool
    {
        return (bool) preg_match('/^RP\s*Tool/iu', $entry->speaker);
    }

    private function isOocFull(LogEntry $entry): bool
    {
        $content = $entry->content;

        // Entire content is (( ... ))
        if (preg_match('/^\s*\(\(.*\)\)\s*$/su', $content)) {
            return true;
        }

        // Truncated OOC: ends with )) but has no opening ((
        if (preg_match('/\)\)\s*$/', $content) && !str_contains($content, '((')) {
            return true;
        }

        return false;
    }

    private function stripInlineOoc(LogEntry $entry): void
    {
        $entry->content = preg_replace('/\(\(.*?\)\)/su', '', $entry->content);
        $entry->content = trim($entry->content);
    }

    private function isObjectNotice(LogEntry $entry): bool
    {
        // System objects with known keyword patterns in their speaker name
        if ($entry->isSystem || $entry->isStar) {
            return false; // handled by other filters
        }
        $speaker = $entry->speaker;
        foreach (self::OBJECT_SPEAKER_KEYWORDS as $kw) {
            if (stripos($speaker, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    private function matchesCustomPattern(LogEntry $entry, string $pattern): bool
    {
        $haystack = $entry->speaker . ' ' . $entry->content;

        // Detect /regex/flags format
        if (preg_match('/^\/(.+)\/([gimsuy]*)$/', $pattern, $m)) {
            $regex = '/' . $m[1] . '/' . str_replace('g', '', $m[2]) . 'u';
            // Suppress errors from malformed user regex
            return @preg_match($regex, $haystack) === 1;
        }

        return stripos($haystack, $pattern) !== false;
    }
}
