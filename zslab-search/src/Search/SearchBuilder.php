<?php

namespace Zslab\Search\Search;

use Zslab\Search\Client\ElasticsearchClient;
use Zslab\Search\Utils\JamoConverter;

class SearchBuilder
{
    private string   $indexName  = '';
    private array    $fields     = [];
    private string   $keyword    = '';
    private string   $fuzzyField = '';
    private array    $filters    = [];
    private int      $currentPage = 1;
    private int      $perPage    = 20;
    private mixed $fallback  = null;

    public function __construct(private ElasticsearchClient $client) {}

    // index() 호출 시 이전 상태를 초기화한다 (싱글턴 안전)
    public function index(string $name): static
    {
        $this->indexName   = $name;
        $this->fields      = [];
        $this->keyword     = '';
        $this->fuzzyField  = '';
        $this->filters     = [];
        $this->currentPage = 1;
        $this->perPage     = 20;
        $this->fallback    = null;
        return $this;
    }

    public function fields(array $fields): static
    {
        $this->fields = $fields;
        return $this;
    }

    public function query(string $keyword): static
    {
        $this->keyword = $keyword;
        return $this;
    }

    public function filter(array $filters): static
    {
        $this->filters = $filters;
        return $this;
    }

    public function page(int $page, int $size = 20): static
    {
        $this->currentPage = max(1, $page);
        $this->perPage     = max(1, $size);
        return $this;
    }

    public function fuzzyField(string $field): static
    {
        $this->fuzzyField = $field;
        return $this;
    }

    public function fallback(callable $fn): static
    {
        $this->fallback = $fn;
        return $this;
    }

    /**
     * ES 쿼리 배열 반환.
     * bool > should > [multi_match(nori), multi_match(ngram)] + filter
     */
    public function build(): array
    {
        $should = [];

        if ($this->keyword !== '') {
            $should[] = [
                'multi_match' => [
                    'query'               => $this->keyword,
                    'fields'              => $this->fields,
                    'analyzer'            => 'nori_search_analyzer',
                    'type'                => 'best_fields',
                    'operator'            => 'and',
                    'fuzziness'           => 'AUTO',
                    'prefix_length'       => 1,
                    'fuzzy_transpositions' => true,
                ],
            ];
            $should[] = [
                'multi_match' => [
                    'query'  => $this->keyword,
                    'fields' => $this->fields,
                    'type'   => 'phrase_prefix',
                ],
            ];
            if ($this->fuzzyField !== '') {
                $jamoKeyword = JamoConverter::convert($this->keyword);
                $tokens      = array_values(array_filter(explode(' ', $jamoKeyword), fn($t) => $t !== ''));

                if (count($tokens) <= 1) {
                    $should[] = [
                        'match_phrase_prefix' => [
                            $this->fuzzyField => $jamoKeyword,
                        ],
                    ];
                } else {
                    $mustClauses = [];
                    foreach ($tokens as $token) {
                        $mustClauses[] = ['match' => [$this->fuzzyField => $token]];
                    }
                    $should[] = ['bool' => ['must' => $mustClauses]];
                }
            }
        }

        $bool = [];
        if (!empty($should)) {
            $bool['should']               = $should;
            $bool['minimum_should_match'] = 1;
        }
        if (!empty($this->filters)) {
            $bool['filter'] = $this->buildFilters();
        }

        return [
            'from'  => ($this->currentPage - 1) * $this->perPage,
            'size'  => $this->perPage,
            'query' => ['bool' => $bool ?: ['match_all' => (object) []]],
        ];
    }

    public function search(): PaginatedResult
    {
        $response = $this->client->search($this->indexName, ['body' => $this->build()]);

        if (($response['_fallback'] ?? false) && $this->fallback !== null) {
            return ($this->fallback)();
        }

        $hits  = $response['hits'] ?? [];
        $total = $hits['total']['value'] ?? 0;
        $rows  = $hits['hits'] ?? [];

        return new PaginatedResult(
            total:       $total,
            currentPage: $this->currentPage,
            perPage:     $this->perPage,
            data:        array_map(fn($h) => $h['_source'] ?? [], $rows),
            ids:         array_map(fn($h) => $h['_id'], $rows),
        );
    }

    private function buildFilters(): array
    {
        $filters = [];
        foreach ($this->filters as $field => $value) {
            $filters[] = is_array($value)
                ? ['terms' => [$field => $value]]
                : ['term'  => [$field => $value]];
        }
        return $filters;
    }
}
