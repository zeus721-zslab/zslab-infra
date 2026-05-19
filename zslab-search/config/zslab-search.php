<?php

return [
    'enabled'         => env('ELASTICSEARCH_ENABLED', true),
    'host'            => env('ELASTICSEARCH_HOST', 'localhost'),
    'port'            => (int) env('ELASTICSEARCH_PORT', 9200),
    'scheme'          => env('ELASTICSEARCH_SCHEME', 'http'),
    // 분석기 프리셋: nori_ngram | ngram | standard
    'analyzer_preset' => env('ELASTICSEARCH_ANALYZER_PRESET', 'nori_ngram'),
];
