<?php

/** 評価合計と件数を使い、整数範囲に依存せずランキング順を比較する。 */

namespace App\Support;

use App\Models\Book;
use Brick\Math\BigInteger;

class RankingOrder
{
    /** 平均降順、件数降順、書籍ID昇順の順に比較する。 */
    public function __invoke(Book $left, Book $right): int
    {
        $leftCount = BigInteger::of((string) $left->reviews_count);
        $rightCount = BigInteger::of((string) $right->reviews_count);
        $averageOrder = BigInteger::of((string) $right->rating_sum)->multipliedBy($leftCount)
            ->compareTo(BigInteger::of((string) $left->rating_sum)->multipliedBy($rightCount));

        return $averageOrder ?: $rightCount->compareTo($leftCount)
            ?: BigInteger::of((string) $left->getAttributes()['id'])->compareTo((string) $right->getAttributes()['id']);
    }
}
