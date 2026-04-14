<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Admin\RuleFormMapper;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Domain\RuleStatus;

final class RuleFormMapperTest extends TestCase
{
    public function testFromFormDataMinimal(): void
    {
        $rule = RuleFormMapper::fromFormData([
            'title' => 'Test',
            'type' => 'simple',
            'config_json' => '{"method":"percentage","value":10}',
        ]);

        self::assertSame('Test', $rule->getTitle());
        self::assertSame('simple', $rule->getType());
        self::assertSame(RuleStatus::ENABLED, $rule->getStatus());
        self::assertSame(10, $rule->getPriority());
        self::assertFalse($rule->isExclusive());
        self::assertSame(['method' => 'percentage', 'value' => 10], $rule->getConfig());
        self::assertSame([], $rule->getFilters());
        self::assertSame([], $rule->getConditions());
    }

    public function testFromFormDataFull(): void
    {
        $rule = RuleFormMapper::fromFormData([
            'id' => '42',
            'title' => 'Full',
            'type' => 'bulk',
            'status' => '0',
            'priority' => '5',
            'exclusive' => '1',
            'starts_at' => '2026-04-01 00:00:00',
            'ends_at' => '2026-04-30 23:59:59',
            'usage_limit' => '100',
            'label' => 'Big sale',
            'notes' => 'Internal note',
            'config_json' => '{"count_scope":"cumulative","ranges":[]}',
            'filters_json' => '{"items":[]}',
            'conditions_json' => '{"logic":"and","items":[]}',
        ]);

        self::assertSame(42, $rule->getId());
        self::assertSame('Full', $rule->getTitle());
        self::assertSame('bulk', $rule->getType());
        self::assertSame(RuleStatus::DISABLED, $rule->getStatus());
        self::assertSame(5, $rule->getPriority());
        self::assertTrue($rule->isExclusive());
        self::assertSame('2026-04-01 00:00:00', $rule->getStartsAt());
        self::assertSame('2026-04-30 23:59:59', $rule->getEndsAt());
        self::assertSame(100, $rule->getUsageLimit());
        self::assertSame('Big sale', $rule->getLabel());
        self::assertSame('Internal note', $rule->getNotes());
    }

    public function testRejectsMissingTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/title/i');
        RuleFormMapper::fromFormData([
            'title' => '',
            'type' => 'simple',
            'config_json' => '{}',
        ]);
    }

    public function testRejectsInvalidType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/type/i');
        RuleFormMapper::fromFormData([
            'title' => 'X',
            'type' => 'nonsense',
            'config_json' => '{}',
        ]);
    }

    public function testRejectsInvalidConfigJson(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/config/i');
        RuleFormMapper::fromFormData([
            'title' => 'X',
            'type' => 'simple',
            'config_json' => '{not json',
        ]);
    }

    public function testEmptyDateFieldsAcceptedAsNull(): void
    {
        $rule = RuleFormMapper::fromFormData([
            'title' => 'X',
            'type' => 'simple',
            'config_json' => '{}',
            'starts_at' => '',
            'ends_at' => '',
            'usage_limit' => '',
        ]);
        self::assertNull($rule->getStartsAt());
        self::assertNull($rule->getEndsAt());
        self::assertNull($rule->getUsageLimit());
    }

    public function testToFormDataRoundTrip(): void
    {
        $original = RuleFormMapper::fromFormData([
            'title' => 'Round',
            'type' => 'cart',
            'priority' => '15',
            'config_json' => '{"method":"percentage","value":10}',
            'conditions_json' => '{"logic":"and","items":[{"type":"cart_subtotal","operator":">=","value":500}]}',
        ]);

        $formData = RuleFormMapper::toFormData($original);

        self::assertSame('Round', $formData['title']);
        self::assertSame('cart', $formData['type']);
        self::assertSame(15, $formData['priority']);

        // config_json should pretty-print or at least round-trip via decode
        $configBack = json_decode($formData['config_json'], true);
        self::assertSame(['method' => 'percentage', 'value' => 10], $configBack);

        $conditionsBack = json_decode($formData['conditions_json'], true);
        self::assertSame('and', $conditionsBack['logic']);
    }

    public function testAcceptsAllValidStrategyTypes(): void
    {
        $types = ['simple', 'bulk', 'cart', 'set', 'buy_x_get_y', 'nth_item', 'cross_category', 'free_shipping'];
        foreach ($types as $type) {
            $rule = RuleFormMapper::fromFormData([
                'title' => 't',
                'type' => $type,
                'config_json' => '{}',
            ]);
            self::assertSame($type, $rule->getType());
        }
    }

    public function testNonObjectJsonRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RuleFormMapper::fromFormData([
            'title' => 'X',
            'type' => 'simple',
            'config_json' => '"a string"',
        ]);
    }

    public function testRejectsBadDateFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/starts_at/i');
        RuleFormMapper::fromFormData([
            'title' => 'X',
            'type' => 'simple',
            'config_json' => '{}',
            'starts_at' => 'tomorrow',
        ]);
    }

    public function testAcceptsValidDateFormat(): void
    {
        $rule = RuleFormMapper::fromFormData([
            'title' => 'X',
            'type' => 'simple',
            'config_json' => '{}',
            'starts_at' => '2026-04-15 10:00:00',
            'ends_at' => '2026-04-30 23:59:59',
        ]);
        self::assertSame('2026-04-15 10:00:00', $rule->getStartsAt());
    }
}
