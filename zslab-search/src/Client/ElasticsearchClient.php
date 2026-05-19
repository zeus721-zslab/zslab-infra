<?php

namespace Zslab\Search\Client;

use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Client;

class ElasticsearchClient
{
    private bool $enabled;
    private ?Client $client = null;

    public function __construct(array $config)
    {
        $this->enabled = (bool) ($config['enabled'] ?? true);

        if ($this->enabled) {
            $host = sprintf('%s://%s:%d', $config['scheme'], $config['host'], $config['port']);
            $this->client = ClientBuilder::create()->setHosts([$host])->build();
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    // ── 문서 조작 ───────────────────────────────────────────────

    public function search(string $index, array $params): array
    {
        if (!$this->enabled) {
            return $this->emptyHits(true);
        }
        try {
            $response = $this->client->search(array_merge(['index' => $index], $params));
            return $response->asArray();
        } catch (\Throwable) {
            return $this->emptyHits(true);
        }
    }

    public function index(string $index, string|int $id, array $document): void
    {
        if (!$this->enabled) return;
        try {
            $this->client->index(['index' => $index, 'id' => $id, 'body' => $document]);
        } catch (\Throwable) {
        }
    }

    public function delete(string $index, string|int $id): void
    {
        if (!$this->enabled) return;
        try {
            $this->client->delete(['index' => $index, 'id' => $id]);
        } catch (\Throwable) {
        }
    }

    public function bulk(array $body): void
    {
        if (!$this->enabled || empty($body)) return;
        try {
            $this->client->bulk(['body' => $body]);
        } catch (\Throwable) {
        }
    }

    public function exists(string $index, string|int $id): bool
    {
        if (!$this->enabled) return false;
        try {
            return (bool) $this->client->exists(['index' => $index, 'id' => $id])->asBool();
        } catch (\Throwable) {
            return false;
        }
    }

    // ── 인덱스 조작 (IndexManager 전용) ─────────────────────────

    public function existsIndex(string $index): bool
    {
        if (!$this->enabled) return false;
        try {
            return (bool) $this->client->indices()->exists(['index' => $index])->asBool();
        } catch (\Throwable) {
            return false;
        }
    }

    public function createIndex(string $index, array $body): void
    {
        if (!$this->enabled) return;
        $this->client->indices()->create(['index' => $index, 'body' => $body]);
    }

    public function deleteIndex(string $index): void
    {
        if (!$this->enabled) return;
        try {
            $this->client->indices()->delete(['index' => $index]);
        } catch (\Throwable) {
        }
    }

    public function reindexTo(string $source, string $dest): void
    {
        if (!$this->enabled) return;
        $this->client->reindex([
            'body' => [
                'source' => ['index' => $source],
                'dest'   => ['index' => $dest],
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────

    private function emptyHits(bool $failed = false): array
    {
        return [
            'hits'      => ['total' => ['value' => 0], 'hits' => []],
            '_fallback' => $failed,
        ];
    }
}
