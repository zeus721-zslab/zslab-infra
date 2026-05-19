<?php

namespace Zslab\Search\Observer;

use Zslab\Search\Client\ElasticsearchClient;
use Zslab\Search\Contracts\Searchable;

class SearchableObserver
{
    public function __construct(private ElasticsearchClient $client) {}

    public function saved(Searchable $model): void
    {
        if (!$this->client->isEnabled()) return;

        $this->client->index(
            $model::getSearchIndex(),
            $model->getKey(),
            $model->toSearchArray()
        );
    }

    public function deleted(Searchable $model): void
    {
        if (!$this->client->isEnabled()) return;

        $this->client->delete(
            $model::getSearchIndex(),
            $model->getKey()
        );
    }
}
