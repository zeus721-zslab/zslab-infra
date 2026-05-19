<?php

namespace Zslab\Search\Index;

use Zslab\Search\Client\ElasticsearchClient;

class IndexManager
{
    public function __construct(
        private ElasticsearchClient $client,
        private string $preset = 'nori_ngram',
    ) {}

    /**
     * 인덱스가 없으면 생성, 있으면 아무것도 하지 않는다.
     */
    public function ensureIndex(string $indexName, array $mapping): void
    {
        if (!$this->client->existsIndex($indexName)) {
            $this->createIndex($indexName, $mapping);
        }
    }

    /**
     * 인덱스를 새로 생성한다. (이미 존재하면 예외)
     */
    public function createIndex(string $indexName, array $mapping): void
    {
        $this->client->createIndex($indexName, [
            'settings' => AnalyzerPresets::get($this->preset),
            'mappings' => $mapping,
        ]);
    }

    /**
     * 인덱스를 삭제한다.
     */
    public function deleteIndex(string $indexName): void
    {
        $this->client->deleteIndex($indexName);
    }

    /**
     * 무중단 재색인.
     * 순서: 기존 → _old 백업 → 삭제 → 신규 생성 → dataProvider 색인 → _old 삭제
     *
     * @param callable $dataProvider  iterable 반환. 각 원소는 'id' 키를 포함한 배열.
     */
    public function reindex(string $indexName, array $mapping, callable $dataProvider): void
    {
        $backupName = $indexName . '_old';
        $currentExists = $this->client->existsIndex($indexName);

        // 이전 백업 잔재 제거
        if ($this->client->existsIndex($backupName)) {
            $this->client->deleteIndex($backupName);
        }

        // 현재 인덱스 백업
        if ($currentExists) {
            $this->client->createIndex($backupName, [
                'settings' => AnalyzerPresets::get($this->preset),
                'mappings' => $mapping,
            ]);
            $this->client->reindexTo($indexName, $backupName);
            $this->client->deleteIndex($indexName);
        }

        // 신규 인덱스 생성
        $this->createIndex($indexName, $mapping);

        // 데이터 색인 (배치 100건)
        $batch = [];
        foreach ($dataProvider() as $item) {
            $id = $item['id'] ?? null;
            unset($item['id']);
            $batch[] = ['index' => ['_index' => $indexName, '_id' => $id]];
            $batch[] = $item;

            if (count($batch) >= 200) { // 200 = 100 docs × 2 (header+body)
                $this->client->bulk($batch);
                $batch = [];
            }
        }
        if (!empty($batch)) {
            $this->client->bulk($batch);
        }

        // 백업 삭제
        if ($currentExists) {
            $this->client->deleteIndex($backupName);
        }
    }
}
