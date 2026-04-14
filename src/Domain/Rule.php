<?php
declare(strict_types=1);

namespace PowerDiscount\Domain;

final class Rule
{
    private int $id;
    private string $title;
    private string $type;
    private int $status;
    private int $priority;
    private bool $exclusive;
    private ?int $startsAt;
    private ?int $endsAt;
    private ?string $startsAtRaw;
    private ?string $endsAtRaw;
    private ?int $usageLimit;
    private int $usedCount;
    private array $filters;
    private array $conditions;
    private array $config;
    private ?string $label;
    private ?string $notes;
    /** @var array<string, mixed> */
    private array $scheduleMeta;

    public function __construct(array $data)
    {
        $this->id         = (int) ($data['id'] ?? 0);
        $this->title      = (string) ($data['title'] ?? '');
        $this->type       = (string) ($data['type'] ?? '');
        $this->status     = (int) ($data['status'] ?? RuleStatus::ENABLED);
        $this->priority   = (int) ($data['priority'] ?? 10);
        $this->exclusive  = (bool) ($data['exclusive'] ?? false);
        $this->startsAt   = self::parseDate($data['starts_at'] ?? null);
        $this->endsAt     = self::parseDate($data['ends_at'] ?? null);
        $this->startsAtRaw = self::normaliseRawDate($data['starts_at'] ?? null);
        $this->endsAtRaw = self::normaliseRawDate($data['ends_at'] ?? null);
        $this->usageLimit = isset($data['usage_limit']) && $data['usage_limit'] !== null
            ? (int) $data['usage_limit']
            : null;
        $this->usedCount  = (int) ($data['used_count'] ?? 0);
        $this->filters    = (array) ($data['filters'] ?? []);
        $this->conditions = (array) ($data['conditions'] ?? []);
        $this->config     = (array) ($data['config'] ?? []);
        $this->label      = isset($data['label']) && $data['label'] !== '' ? (string) $data['label'] : null;
        $this->notes      = isset($data['notes']) && $data['notes'] !== '' ? (string) $data['notes'] : null;
        $this->scheduleMeta = is_array($data['schedule_meta'] ?? null) ? $data['schedule_meta'] : [];
    }

    public function getId(): int            { return $this->id; }
    public function getTitle(): string      { return $this->title; }
    public function getType(): string       { return $this->type; }
    public function getStatus(): int        { return $this->status; }
    public function getPriority(): int      { return $this->priority; }
    public function isEnabled(): bool       { return $this->status === RuleStatus::ENABLED; }
    public function isExclusive(): bool     { return $this->exclusive; }
    public function getFilters(): array     { return $this->filters; }
    public function getConditions(): array  { return $this->conditions; }
    public function getConfig(): array      { return $this->config; }
    public function getLabel(): ?string     { return $this->label; }
    public function getNotes(): ?string     { return $this->notes; }
    public function getUsageLimit(): ?int   { return $this->usageLimit; }
    public function getUsedCount(): int     { return $this->usedCount; }
    public function getStartsAt(): ?string    { return $this->startsAtRaw; }
    public function getEndsAt(): ?string      { return $this->endsAtRaw; }
    /** @return array<string, mixed> */
    public function getScheduleMeta(): array  { return $this->scheduleMeta; }

    public function isActiveAt(int $timestamp): bool
    {
        if ($this->startsAt !== null && $timestamp < $this->startsAt) {
            return false;
        }
        if ($this->endsAt !== null && $timestamp > $this->endsAt) {
            return false;
        }
        $type = (string) ($this->scheduleMeta['type'] ?? '');
        if ($type === 'monthly') {
            $day = (int) gmdate('j', $timestamp);
            $from = (int) ($this->scheduleMeta['day_from'] ?? 1);
            $to = (int) ($this->scheduleMeta['day_to'] ?? 31);
            if ($from < 1) $from = 1;
            if ($to > 31) $to = 31;
            if ($from <= $to) {
                if ($day < $from || $day > $to) {
                    return false;
                }
            } else {
                // wrap-around: e.g. 28..3 means 28,29,30,31,1,2,3
                if ($day < $from && $day > $to) {
                    return false;
                }
            }
        }
        return true;
    }

    public function isUsageLimitExhausted(): bool
    {
        if ($this->usageLimit === null) {
            return false;
        }
        return $this->usedCount >= $this->usageLimit;
    }

    private static function normaliseRawDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return gmdate('Y-m-d H:i:s', $value);
        }
        return (string) $value;
    }

    private static function parseDate($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        $ts = strtotime((string) $value);
        return $ts === false ? null : $ts;
    }
}
