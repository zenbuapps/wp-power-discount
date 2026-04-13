<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\DiscountResult;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Strategy\DiscountStrategyInterface;
use PowerDiscount\Strategy\StrategyRegistry;

final class StrategyRegistryTest extends TestCase
{
    public function testRegisterAndResolve(): void
    {
        $registry = new StrategyRegistry();
        $stub = $this->makeStub('simple');

        $registry->register($stub);

        self::assertSame($stub, $registry->resolve('simple'));
        self::assertNull($registry->resolve('bulk'));
    }

    public function testAllReturnsAllRegistered(): void
    {
        $registry = new StrategyRegistry();
        $registry->register($this->makeStub('simple'));
        $registry->register($this->makeStub('bulk'));

        self::assertCount(2, $registry->all());
    }

    public function testRegisterOverridesExistingType(): void
    {
        $registry = new StrategyRegistry();
        $first  = $this->makeStub('simple');
        $second = $this->makeStub('simple');

        $registry->register($first);
        $registry->register($second);

        self::assertSame($second, $registry->resolve('simple'));
        self::assertCount(1, $registry->all());
    }

    private function makeStub(string $type): DiscountStrategyInterface
    {
        return new class($type) implements DiscountStrategyInterface {
            private string $type;
            public function __construct(string $type) { $this->type = $type; }
            public function type(): string { return $this->type; }
            public function apply(Rule $rule, CartContext $context): ?DiscountResult { return null; }
        };
    }
}
