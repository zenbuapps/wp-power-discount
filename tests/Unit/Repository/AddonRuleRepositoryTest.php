<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\AddonRule;
use PowerDiscount\Repository\AddonRuleRepository;
use PowerDiscount\Tests\Stub\InMemoryDatabaseAdapter;

final class AddonRuleRepositoryTest extends TestCase
{
    private AddonRuleRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new AddonRuleRepository(new InMemoryDatabaseAdapter());
    }

    private function make(array $overrides = []): AddonRule
    {
        return new AddonRule(array_merge([
            'title'              => 'Test rule',
            'status'             => 1,
            'priority'           => 10,
            'addon_items'        => [['product_id' => 101, 'special_price' => 90]],
            'target_product_ids' => [12, 34],
        ], $overrides));
    }

    public function testInsertAndFindById(): void
    {
        $id = $this->repo->insert($this->make());
        self::assertGreaterThan(0, $id);
        $rule = $this->repo->findById($id);
        self::assertNotNull($rule);
        self::assertSame('Test rule', $rule->getTitle());
        self::assertSame([12, 34], $rule->getTargetProductIds());
    }

    public function testFindAllOrdersByPriority(): void
    {
        $this->repo->insert($this->make(['title' => 'B', 'priority' => 20]));
        $this->repo->insert($this->make(['title' => 'A', 'priority' => 10]));
        $this->repo->insert($this->make(['title' => 'C', 'priority' => 30]));
        $all = $this->repo->findAll();
        self::assertCount(3, $all);
        self::assertSame('A', $all[0]->getTitle());
        self::assertSame('B', $all[1]->getTitle());
        self::assertSame('C', $all[2]->getTitle());
    }

    public function testFindActiveForTargetFiltersDisabledAndNonMatching(): void
    {
        $this->repo->insert($this->make(['title' => 'enabled match', 'target_product_ids' => [12]]));
        $this->repo->insert($this->make(['title' => 'disabled match', 'status' => 0, 'target_product_ids' => [12]]));
        $this->repo->insert($this->make(['title' => 'enabled no match', 'target_product_ids' => [99]]));
        $matched = $this->repo->findActiveForTarget(12);
        self::assertCount(1, $matched);
        self::assertSame('enabled match', $matched[0]->getTitle());
    }

    public function testFindContainingAddon(): void
    {
        $this->repo->insert($this->make(['title' => 'has 101', 'addon_items' => [['product_id' => 101, 'special_price' => 90]]]));
        $this->repo->insert($this->make(['title' => 'has 200', 'addon_items' => [['product_id' => 200, 'special_price' => 50]]]));
        $rules = $this->repo->findContainingAddon(101);
        self::assertCount(1, $rules);
        self::assertSame('has 101', $rules[0]->getTitle());
    }

    public function testFindContainingTarget(): void
    {
        $this->repo->insert($this->make(['title' => 'targets 12', 'target_product_ids' => [12]]));
        $this->repo->insert($this->make(['title' => 'targets 99', 'target_product_ids' => [99]]));
        $rules = $this->repo->findContainingTarget(12);
        self::assertCount(1, $rules);
        self::assertSame('targets 12', $rules[0]->getTitle());
    }

    public function testUpdate(): void
    {
        $id = $this->repo->insert($this->make());
        $rule = $this->repo->findById($id);
        $itemsArr = array_map(static fn ($i) => $i->toArray(), $rule->getAddonItems());
        $updated = new AddonRule([
            'id'                 => $id,
            'title'              => 'Renamed',
            'status'             => 0,
            'priority'           => 50,
            'addon_items'        => $itemsArr,
            'target_product_ids' => $rule->getTargetProductIds(),
        ]);
        $this->repo->update($updated);
        $reloaded = $this->repo->findById($id);
        self::assertSame('Renamed', $reloaded->getTitle());
        self::assertFalse($reloaded->isEnabled());
        self::assertSame(50, $reloaded->getPriority());
    }

    public function testDelete(): void
    {
        $id = $this->repo->insert($this->make());
        $this->repo->delete($id);
        self::assertNull($this->repo->findById($id));
    }

    public function testReorderAssignsPriorityByPosition(): void
    {
        $a = $this->repo->insert($this->make(['title' => 'A']));
        $b = $this->repo->insert($this->make(['title' => 'B']));
        $c = $this->repo->insert($this->make(['title' => 'C']));
        $this->repo->reorder([$c, $a, $b]);
        $all = $this->repo->findAll();
        self::assertSame('C', $all[0]->getTitle());
        self::assertSame(1, $all[0]->getPriority());
        self::assertSame('A', $all[1]->getTitle());
        self::assertSame(2, $all[1]->getPriority());
        self::assertSame('B', $all[2]->getTitle());
        self::assertSame(3, $all[2]->getPriority());
    }
}
