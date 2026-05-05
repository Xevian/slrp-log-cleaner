<?php

declare(strict_types=1);

class LogEntry
{
    public string $rawTimestamp;
    public ?DateTimeImmutable $time;
    public string $speaker;
    public string $displayName;
    public string $username;
    public string $content;
    public bool $isAction;
    public bool $isSystem;
    public bool $isStar;

    public function __construct(
        string $rawTimestamp,
        string $speaker,
        string $content,
        string $displayName = '',
        string $username = '',
        bool $isAction = false,
        bool $isSystem = false,
        bool $isStar = false,
        ?DateTimeImmutable $time = null
    ) {
        $this->rawTimestamp = $rawTimestamp;
        $this->speaker      = $speaker;
        $this->content      = $content;
        $this->displayName  = $displayName ?: $speaker;
        $this->username     = $username;
        $this->isAction     = $isAction;
        $this->isSystem     = $isSystem;
        $this->isStar       = $isStar;
        $this->time         = $time;
    }

    public function formatTime(): string
    {
        return $this->time ? $this->time->format('H:i') : substr($this->rawTimestamp, 11, 5);
    }

    public function minuteKey(): string
    {
        // Returns "YYYY/MM/DD HH:MM" — used to detect same-minute split posts
        return $this->rawTimestamp;
    }

    public function speakerKey(): string
    {
        $key = $this->username ?: $this->displayName;
        return mb_strtolower($key, 'UTF-8');
    }
}
