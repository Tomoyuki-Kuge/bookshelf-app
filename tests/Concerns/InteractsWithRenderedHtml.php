<?php

/** 描画されたHTMLをDOMで解析するための共通処理を提供する。 */

namespace Tests\Concerns;

use DOMDocument;
use DOMXPath;
use Illuminate\Testing\TestResponse;

trait InteractsWithRenderedHtml
{
    /** フォーム値・欄下エラー・カードをDOM単位で照合できる形にする。 */
    private function createDomXPath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }
}
