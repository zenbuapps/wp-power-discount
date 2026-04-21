<?php
declare(strict_types=1);

namespace PowerDiscount\Repository;

use PowerDiscount\Domain\AddonRule;
use PowerDiscount\Persistence\DatabaseAdapter;
use PowerDiscount\Persistence\JsonSerializer;

final class AddonRuleRepository
{
    private const TABLE = 'pd_addon_rules';

    private DatabaseAdapter $db;

    public function __construct(DatabaseAdapter $db)
    {
        $this->db = $db;
    }

    public function insert(AddonRule $rule): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $row = $this->toRow($rule);
        $row['created_at'] = $now;
        $row['updated_at'] = $now;
        return $this->db->insert($this->table(), $row);
    }

    public function update(AddonRule $rule): int
    {
        $row = $this->toRow($rule);
        $row['updated_at'] = gmdate('Y-m-d H:i:s');
        return $this->db->update($this->table(), $row, ['id' => $rule->getId()]);
    }

    public function delete(int $id): int
    {
        return $this->db->delete($this->table(), ['id' => $id]);
    }

    public function findById(int $id): ?AddonRule
    {
        $row = $this->db->findById($this->table(), $id);
        return $row === null ? null : $this->hydrate($row);
    }

    /** @return AddonRule[] */
    public function findAll(): array
    {
        $rows = $this->db->findWhere($this->table(), [], ['priority' => 'ASC', 'id' => 'ASC']);
        return array_map([$this, 'hydrate'], $rows);
    }

    /** @return AddonRule[] */
    public function findActiveForTarget(int $productId): array
    {
        $matched = [];
        foreach ($this->findAll() as $rule) {
            if ($rule->isEnabled() && $rule->matchesTarget($productId)) {
                $matched[] = $rule;
            }
        }
        return $matched;
    }

    /** @return AddonRule[] */
    public function findContainingAddon(int $addonProductId): array
    {
        $matched = [];
        foreach ($this->findAll() as $rule) {
            if ($rule->containsAddon($addonProductId)) {
                $matched[] = $rule;
            }
        }
        return $matched;
    }

    /** @return AddonRule[] */
    public function findContainingTarget(int $targetProductId): array
    {
        $matched = [];
        foreach ($this->findAll() as $rule) {
            if ($rule->matchesTarget($targetProductId)) {
                $matched[] = $rule;
            }
        }
        return $matched;
    }

    public function getMaxPriority(): int
    {
        $max = 0;
        foreach ($this->findAll() as $rule) {
            if ($rule->getPriority() > $max) {
                $max = $rule->getPriority();
            }
        }
        return $max;
    }

    /** @param int[] $orderedIds */
    public function reorder(array $orderedIds): void
    {
        $position = 1;
        foreach ($orderedIds as $id) {
            $id = (int) $id;
            if ($id <= 0) continue;
            $this->db->update(
                $this->table(),
                ['priority' => $position, 'updated_at' => gmdate('Y-m-d H:i:s')],
                ['id' => $id]
            );
            $position++;
        }
    }

    private function table(): string
    {
        return $this->db->table(self::TABLE);
    }

    /** @return array<string, mixed> */
    private function toRow(AddonRule $rule): array
    {
        $addonArray = array_map(
            static fn ($item) => $item->toArray(),
            $rule->getAddonItems()
        );
        return [
            'title'                  => $rule->getTitle(),
            'status'                 => $rule->getStatus(),
            'priority'               => $rule->getPriority(),
            'addon_items'            => JsonSerializer::encode($addonArray),
            'target_product_ids'     => JsonSerializer::encode($rule->getTargetProductIds()),
            'exclude_from_discounts' => $rule->isExcludeFromDiscounts() ? 1 : 0,
        ];
    }

    private function hydrate(array $row): AddonRule
    {
        return new AddonRule([
            'id'                     => (int) ($row['id'] ?? 0),
            'title'                  => (string) ($row['title'] ?? ''),
            'status'                 => (int) ($row['status'] ?? 1),
            'priority'               => (int) ($row['priority'] ?? 10),
            'addon_items'            => JsonSerializer::decode((string) ($row['addon_items'] ?? '')),
            'target_product_ids'     => JsonSerializer::decode((string) ($row['target_product_ids'] ?? '')),
            'exclude_from_discounts' => (bool) ($row['exclude_from_discounts'] ?? false),
        ]);
    }
}
