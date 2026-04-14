<?php
declare(strict_types=1);

namespace PowerDiscount\Admin;

use InvalidArgumentException;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Domain\RuleStatus;

final class RuleFormMapper
{
    private const VALID_TYPES = [
        'simple', 'bulk', 'cart', 'set',
        'buy_x_get_y', 'nth_item', 'cross_category', 'free_shipping',
    ];

    /**
     * @param array<string, mixed> $post
     */
    public static function fromFormData(array $post): Rule
    {
        $title = trim((string) ($post['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Rule title is required.');
        }

        $type = (string) ($post['type'] ?? '');
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new InvalidArgumentException(sprintf('Invalid rule type: %s', $type));
        }

        $config = self::decodeJsonField($post['config_json'] ?? '', 'config');
        $filters = self::decodeJsonField($post['filters_json'] ?? '', 'filters');
        $conditions = self::decodeJsonField($post['conditions_json'] ?? '', 'conditions');

        $usageLimitRaw = trim((string) ($post['usage_limit'] ?? ''));
        $usageLimit = $usageLimitRaw === '' ? null : (int) $usageLimitRaw;

        $startsAt = trim((string) ($post['starts_at'] ?? ''));
        $endsAt = trim((string) ($post['ends_at'] ?? ''));

        return new Rule([
            'id'          => (int) ($post['id'] ?? 0),
            'title'       => $title,
            'type'        => $type,
            'status'      => isset($post['status']) ? (int) $post['status'] : RuleStatus::ENABLED,
            'priority'    => isset($post['priority']) ? (int) $post['priority'] : 10,
            'exclusive'   => !empty($post['exclusive']),
            'starts_at'   => $startsAt === '' ? null : $startsAt,
            'ends_at'     => $endsAt === '' ? null : $endsAt,
            'usage_limit' => $usageLimit,
            'used_count'  => (int) ($post['used_count'] ?? 0),
            'config'      => $config,
            'filters'     => $filters,
            'conditions'  => $conditions,
            'label'       => isset($post['label']) ? (string) $post['label'] : null,
            'notes'       => isset($post['notes']) ? (string) $post['notes'] : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function toFormData(Rule $rule): array
    {
        return [
            'id'              => $rule->getId(),
            'title'           => $rule->getTitle(),
            'type'            => $rule->getType(),
            'status'          => $rule->getStatus(),
            'priority'        => $rule->getPriority(),
            'exclusive'       => $rule->isExclusive() ? 1 : 0,
            'starts_at'       => $rule->getStartsAt() ?? '',
            'ends_at'         => $rule->getEndsAt() ?? '',
            'usage_limit'    => $rule->getUsageLimit() === null ? '' : (string) $rule->getUsageLimit(),
            'used_count'      => $rule->getUsedCount(),
            'label'           => $rule->getLabel() ?? '',
            'notes'           => $rule->getNotes() ?? '',
            'config_json'     => self::encodePretty($rule->getConfig()),
            'filters_json'    => self::encodePretty($rule->getFilters()),
            'conditions_json' => self::encodePretty($rule->getConditions()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJsonField($value, string $field): array
    {
        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException(sprintf('Invalid JSON in %s field.', $field));
        }
        return $decoded;
    }

    private static function encodePretty(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return $json === false ? '{}' : $json;
    }
}
