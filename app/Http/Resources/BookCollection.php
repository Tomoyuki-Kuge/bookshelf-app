<?php

/** 一覧のページ情報を公開契約のキーだけへ絞る。 */

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class BookCollection extends ResourceCollection
{
    public $collects = BookResource::class;

    /** 内部のページリンク一覧は公開せず、確定した7項目を返す。 */
    public function paginationInformation($request, $paginated, $default): array
    {
        unset($default['meta']['links']);

        return $default;
    }
}
