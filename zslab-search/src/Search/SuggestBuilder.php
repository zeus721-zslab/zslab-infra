<?php

namespace Zslab\Search\Search;

use Zslab\Search\Client\ElasticsearchClient;
use Zslab\Search\Utils\JamoConverter;

class SuggestBuilder
{
    private string $indexName  = '';
    private string $field      = '';
    private string $fuzzyField = '';
    private string $keyword    = '';
    private int    $size       = 10;

    public function __construct(private ElasticsearchClient $client) {}

    // index() 호출 시 상태 초기화 (싱글턴 안전)
    public function index(string $name): static
    {
        $this->indexName  = $name;
        $this->field      = '';
        $this->fuzzyField = '';
        $this->keyword    = '';
        $this->size       = 10;
        return $this;
    }

    public function field(string $field): static
    {
        $this->field = $field;
        return $this;
    }

    public function fuzzyField(string $field): static
    {
        $this->fuzzyField = $field;
        return $this;
    }

    public function query(string $keyword): static
    {
        $this->keyword = $keyword;
        return $this;
    }

    public function size(int $n): static
    {
        $this->size = max(1, $n);
        return $this;
    }

    /**
     * search_as_you_type + bool_prefix 방식 자동완성 (shop 기존 방식).
     * 결과: _source 배열의 배열.
     */
    public function suggest(): array
    {
        // search_as_you_type 필드는 _2gram, _3gram 서브필드를 자동 생성한다
        $fields = [
            $this->field,
            $this->field . '._2gram',
            $this->field . '._3gram',
        ];

        $shouldClauses = [
            [
                'multi_match' => [
                    'query'    => $this->keyword,
                    'type'     => 'bool_prefix',
                    'fields'   => $fields,
                    'operator' => 'and',
                ],
            ],
        ];

        if ($this->fuzzyField !== '') {
            $jamoKeyword = JamoConverter::convert($this->keyword);
            $tokens      = array_values(array_filter(explode(' ', $jamoKeyword), fn($t) => $t !== ''));

            if (count($tokens) <= 1) {
                $shouldClauses[] = [
                    'match_phrase_prefix' => [
                        $this->fuzzyField => $jamoKeyword,
                    ],
                ];
            } else {
                $mustClauses = [];
                foreach (array_slice($tokens, 0, -1) as $token) {
                    $mustClauses[] = ['match' => [$this->fuzzyField => $token]];
                }
                $mustClauses[] = [
                    'match_phrase_prefix' => [
                        $this->fuzzyField => end($tokens),
                    ],
                ];
                $shouldClauses[] = ['bool' => ['must' => $mustClauses]];
            }
        }

        $response = $this->client->search($this->indexName, [
            'body' => [
                'size'  => $this->size,
                'query' => [
                    'bool' => [
                        'should' => $shouldClauses,
                    ],
                ],
            ],
        ]);

        if ($response['_fallback'] ?? false) {
            return [];
        }

        return array_map(
            fn($hit) => $hit['_source'] ?? [],
            $response['hits']['hits'] ?? []
        );
    }
}
