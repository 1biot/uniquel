<?php

namespace UQL\Traits;

use PHPUnit\Framework\TestCase;
use UQL\Enum\Operator;
use UQL\Stream\Json;
use UQL\Stream\Xml;

class JoinableTest extends TestCase
{
    private Json $json;
    private Xml $xml;

    protected function setUp(): void
    {
        $this->json = Json::open(realpath(__DIR__ . '/../../examples/data/users.json'));
        $this->xml = Xml::open(realpath(__DIR__ . '/../../examples/data/orders.xml'));
    }

    public function testInnerJoin(): void
    {
        $orders = $this->xml->query()
            ->select('id')->as('orderId')
            ->select('user_id')->as('userId')
            ->select('total_price')->as('totalPrice')
            ->from('orders.order');

        $query = $this->json->query()
            ->select('id, name')
            ->select('o.orderId')->as('orderId')
            ->select('o.totalPrice')->as('totalPrice')
            ->from('data.users')
            ->innerJoin($orders, 'o')
                ->on('id', Operator::EQUAL, 'userId')
            ->having('totalPrice', Operator::GREATER_THAN, 200)
            ->orderBy('totalPrice')->desc();

        $result = $query->fetchAll();
        $count = $query->count();

        self::assertSame(count(iterator_to_array($result)), $count);
    }
}
