<?php

namespace Zslab\Search\Index;

class AnalyzerPresets
{
    /**
     * 프리셋에 맞는 ES 인덱스 settings 반환.
     * IndexManager가 인덱스 생성 시 'settings' 키 값으로 사용한다.
     */
    public static function get(string $preset): array
    {
        return match ($preset) {
            'nori_ngram' => self::noriNgram(),
            'ngram'      => self::ngram(),
            default      => self::standard(),
        };
    }

    // nori 형태소 + edge ngram(1~10) 이원화
    private static function noriNgram(): array
    {
        return [
            'max_ngram_diff' => 9,
            'analysis' => [
                'filter' => [
                    'nori_posfilter' => [
                        'type'     => 'nori_part_of_speech',
                        'stoptags' => ['E', 'IC', 'J', 'MAJ', 'MM', 'SP', 'SSC', 'SSO', 'SC', 'SE', 'XPN', 'XSA', 'XSN', 'XSV', 'UNA', 'NA', 'VSV'],
                    ],
                    'edge_ngram_filter' => [
                        'type'     => 'edge_ngram',
                        'min_gram' => 1,
                        'max_gram' => 10,
                    ],
                ],
                'analyzer' => [
                    // 인덱싱용: nori 형태소 → edge ngram
                    'nori_ngram_analyzer' => [
                        'type'      => 'custom',
                        'tokenizer' => 'nori_tokenizer',
                        'filter'    => ['nori_posfilter', 'nori_readingform', 'lowercase', 'edge_ngram_filter'],
                    ],
                    // 검색용: nori 형태소만
                    'nori_search_analyzer' => [
                        'type'      => 'custom',
                        'tokenizer' => 'nori_tokenizer',
                        'filter'    => ['nori_posfilter', 'nori_readingform', 'lowercase'],
                    ],
                    'jamo_analyzer' => [
                        'type'      => 'custom',
                        'tokenizer' => 'whitespace',
                        'filter'    => ['lowercase'],
                    ],
                    'jamo_search_analyzer' => [
                        'type'      => 'custom',
                        'tokenizer' => 'standard',
                        'filter'    => ['lowercase'],
                    ],
                ],
            ],
        ];
    }

    // ngram(2~10) 인덱싱 + standard 검색 (shop 기존 방식)
    private static function ngram(): array
    {
        return [
            'max_ngram_diff' => 8,
            'analysis' => [
                'tokenizer' => [
                    'ngram_tokenizer' => [
                        'type'        => 'ngram',
                        'min_gram'    => 2,
                        'max_gram'    => 10,
                        'token_chars' => ['letter', 'digit'],
                    ],
                ],
                'analyzer' => [
                    'ngram_analyzer' => [
                        'type'      => 'custom',
                        'tokenizer' => 'ngram_tokenizer',
                        'filter'    => ['lowercase'],
                    ],
                    'ngram_search_analyzer' => [
                        'type'      => 'custom',
                        'tokenizer' => 'standard',
                        'filter'    => ['lowercase'],
                    ],
                ],
            ],
        ];
    }

    // standard + lowercase (최소 설정)
    private static function standard(): array
    {
        return [
            'analysis' => [
                'analyzer' => [
                    'standard_analyzer' => [
                        'type'      => 'custom',
                        'tokenizer' => 'standard',
                        'filter'    => ['lowercase'],
                    ],
                ],
            ],
        ];
    }
}
