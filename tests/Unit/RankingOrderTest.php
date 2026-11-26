<?php

/** PHP整数範囲を超える集計でもランキング順を維持できることを検証する。 */

namespace Tests\Unit;

use App\Models\Book;
use App\Support\RankingOrder;
use PHPUnit\Framework\TestCase;

class RankingOrderTest extends TestCase
{
    /** 巨大な交差積と、浮動小数点では消える平均差・件数差・ID差を比較する。 */
    public function test_large_integer_cross_products_and_tiebreaks(): void
    {
        $order = new RankingOrder;
        $higher = new Book;
        $higher->setRawAttributes(['id' => '18446744073709551615', 'rating_sum' => '40000000000000000001', 'reviews_count' => '10000000000000000000']);
        $lower = new Book;
        $lower->setRawAttributes(['id' => '1', 'rating_sum' => '40000000000000000000', 'reviews_count' => '10000000000000000000']);
        $this->assertLessThan(0, $order($higher, $lower));
        $this->assertGreaterThan(0, $order($lower, $higher));
        $more = new Book;
        $more->setRawAttributes(['id' => '2', 'rating_sum' => '40000000000000000004', 'reviews_count' => '10000000000000000001']);
        $this->assertLessThan(0, $order($more, $lower));
        $small = clone $lower;
        $small->setRawAttributes(['id' => '9223372036854775808', 'rating_sum' => '40000000000000000000', 'reviews_count' => '10000000000000000000']);
        $large = clone $small;
        $large->setRawAttributes(array_replace($small->getAttributes(), ['id' => '9223372036854775809']));
        $this->assertLessThan(0, $order($small, $large));
        $this->assertSame(0, $order($small, $small));
    }
}
