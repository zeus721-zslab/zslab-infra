<?php

namespace Zslab\Search\Search;

class PaginatedResult
{
    public int $lastPage;

    public function __construct(
        public int   $total,
        public int   $currentPage,
        public int   $perPage,
        public array $data,  // ES _source 배열
        public array $ids,   // ES _id 배열 (DB 조회용)
    ) {
        $this->lastPage = $perPage > 0 ? (int) ceil($total / $perPage) : 1;
    }
}
