<?php

namespace Zslab\Search;

use Illuminate\Support\ServiceProvider;
use Zslab\Search\Client\ElasticsearchClient;
use Zslab\Search\Index\IndexManager;
use Zslab\Search\Search\SearchBuilder;
use Zslab\Search\Search\SuggestBuilder;

class ZslabSearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/zslab-search.php', 'zslab-search');

        $this->app->singleton(ElasticsearchClient::class, function ($app) {
            return new ElasticsearchClient($app['config']['zslab-search']);
        });

        $this->app->singleton(IndexManager::class, function ($app) {
            $preset = $app['config']['zslab-search']['analyzer_preset'] ?? 'nori_ngram';
            return new IndexManager($app->make(ElasticsearchClient::class), $preset);
        });

        $this->app->singleton(SearchBuilder::class, function ($app) {
            return new SearchBuilder($app->make(ElasticsearchClient::class));
        });

        $this->app->singleton(SuggestBuilder::class, function ($app) {
            return new SuggestBuilder($app->make(ElasticsearchClient::class));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/zslab-search.php' => config_path('zslab-search.php'),
            ], 'zslab-search-config');
        }
    }
}
