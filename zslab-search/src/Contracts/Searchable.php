<?php

namespace Zslab\Search\Contracts;

interface Searchable
{
    public function toSearchArray(): array;
    public static function getSearchIndex(): string;
    public static function getSearchFields(): array;
}
