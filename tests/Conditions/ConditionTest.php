<?php

namespace Conditions;

use FQL\Conditions\ConditionEnvelope;
use FQL\Exceptions\UnexpectedValueException;
use PHPUnit\Framework\TestCase;
use FQL\Conditions\GroupCondition;
use FQL\Conditions\SimpleCondition;
use FQL\Enum\Operator;
use FQL\Enum\LogicalOperator;

class ConditionTest extends TestCase
{
    public function testStructure(): void
    {
        $group = new GroupCondition(LogicalOperator::AND);

        $group->addCondition(
            LogicalOperator::AND,
            new SimpleCondition(LogicalOperator::AND, 'age', Operator::GREATER_THAN, 18)
        );
        $group->addCondition(
            LogicalOperator::OR,
            new SimpleCondition(LogicalOperator::OR, 'status', Operator::EQUAL, 'active')
        );

        $nestedGroup = new GroupCondition(LogicalOperator::AND);
        $nestedGroup->addCondition(
            LogicalOperator::AND,
            new SimpleCondition(LogicalOperator::AND, 'country', Operator::EQUAL, 'US')
        );
        $group->addCondition(LogicalOperator::OR, $nestedGroup);

        $this->assertCount(3, $group->getConditions());
        $this->assertInstanceOf(ConditionEnvelope::class, $group->getConditions()[2]);
    }

    public function testSimpleConditionEvaluation(): void
    {
        $condition = new SimpleCondition(LogicalOperator::AND, 'age', Operator::GREATER_THAN, 18);

        $data = ['age' => 20];
        $this->assertTrue($condition->evaluate($data, false));

        $data = ['age' => 18];
        $this->assertFalse($condition->evaluate($data, false));
    }

    public function testGroupConditionEvaluation(): void
    {
        $group = new GroupCondition(LogicalOperator::AND);

        $group->addCondition(
            LogicalOperator::AND,
            new SimpleCondition(LogicalOperator::AND, 'age', Operator::GREATER_THAN, 18)
        );
        $group->addCondition(
            LogicalOperator::AND,
            new SimpleCondition(LogicalOperator::AND, 'status', Operator::EQUAL, 'active')
        );

        $data = ['age' => 20, 'status' => 'active'];
        $this->assertTrue($group->evaluate($data, false));

        $data = ['age' => 20, 'status' => 'inactive'];
        $this->assertFalse($group->evaluate($data, false));
    }

    public function testNestedGroupConditionEvaluation(): void
    {
        $group = new GroupCondition(LogicalOperator::AND);

        $group->addCondition(
            LogicalOperator::AND,
            new SimpleCondition(LogicalOperator::AND, 'age', Operator::GREATER_THAN, 18)
        );

        $nestedGroup = new GroupCondition(LogicalOperator::OR);
        $nestedGroup->addCondition(
            LogicalOperator::AND,
            new SimpleCondition(LogicalOperator::AND, 'status', Operator::EQUAL, 'active')
        );
        $nestedGroup->addCondition(
            LogicalOperator::OR,
            new SimpleCondition(LogicalOperator::AND, 'country', Operator::EQUAL, 'US')
        );

        $group->addCondition(LogicalOperator::AND, $nestedGroup);

        $data = ['age' => 20, 'status' => 'inactive', 'country' => 'US'];
        $this->assertTrue($group->evaluate($data, false));

        $data = ['age' => 20, 'status' => 'inactive', 'country' => 'Canada'];
        $this->assertFalse($group->evaluate($data, false));
    }

    public function testEmptyGroupEvaluation(): void
    {
        $group = new GroupCondition(LogicalOperator::AND);
        $this->assertTrue($group->evaluate([], false)); // Empty group should return true.
    }

    public function testNonExistentKeyThrowsException(): void
    {
        $this->expectException(UnexpectedValueException::class);

        $condition = new SimpleCondition(LogicalOperator::AND, 'nonexistent', Operator::EQUAL, 'value');
        $condition->evaluate([], false);
    }

    public function testAccessNestedValue(): void
    {
        $condition = new SimpleCondition(LogicalOperator::AND, 'user.age', Operator::GREATER_THAN, 18);

        $data = ['user' => ['age' => 20]];
        $this->assertTrue($condition->evaluate($data, true));

        $data = ['user' => ['age' => 17]];
        $this->assertFalse($condition->evaluate($data, true));
    }
}
