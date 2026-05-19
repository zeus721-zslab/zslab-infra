# zslab-search

Zslab 공용 Elasticsearch 검색 패키지 (Laravel 전용).  
LMS·shop 등 복수 프로젝트에서 공유하며, `sync-search.sh`로 동기화한다.

---

## 패키지 개요

| 항목 | 값 |
|------|-----|
| Composer name | `zslab/search` |
| PHP 요구 버전 | `>=8.1` |
| 의존 라이브러리 | `elasticsearch/elasticsearch ^8.0` |
| ServiceProvider | `Zslab\Search\ZslabSearchServiceProvider` |

Laravel Package Auto-discovery를 지원하므로 별도 등록 불필요.

---

## 구조

```
zslab-search/
├── composer.json
├── config/
│   └── zslab-search.php          # 설정 기본값
└── src/
    ├── ZslabSearchServiceProvider.php
    ├── Client/
    │   └── ElasticsearchClient.php   # ES HTTP 래퍼
    ├── Contracts/
    │   └── Searchable.php            # 모델 인터페이스
    ├── Index/
    │   ├── AnalyzerPresets.php       # 분석기 설정 모음
    │   └── IndexManager.php          # 인덱스 생성·재색인
    ├── Observer/
    │   └── SearchableObserver.php    # 모델 saved/deleted 훅
    ├── Search/
    │   ├── SearchBuilder.php         # 전문 검색 빌더
    │   ├── SuggestBuilder.php        # 자동완성 빌더
    │   └── PaginatedResult.php       # 검색 결과 VO
    └── Utils/
        └── JamoConverter.php         # 한글 → 자모 변환
```

---

## 분석기 프리셋 (AnalyzerPresets)

`config/zslab-search.php`의 `analyzer_preset` 값으로 선택한다.

| 프리셋 | 인덱싱 분석기 | 검색 분석기 | 용도 |
|--------|--------------|------------|------|
| `nori_ngram` (기본값) | nori 형태소 → edge ngram(1~10) | nori 형태소 | 한국어 형태소 + 부분 일치 (LMS) |
| `ngram` | ngram(2~10) | standard | 영문·상품명 부분 일치 (shop 기존 방식) |
| `standard` | standard | standard | 최소 설정 |

`nori_ngram` 프리셋은 자모 검색을 위한 분석기도 함께 등록한다.

| 분석기 이름 | tokenizer | filter | 역할 |
|-------------|-----------|--------|------|
| `nori_ngram_analyzer` | nori | nori_posfilter, nori_readingform, lowercase, edge_ngram | 인덱싱용 |
| `nori_search_analyzer` | nori | nori_posfilter, nori_readingform, lowercase | 검색용 |
| `jamo_analyzer` | whitespace | lowercase | 자모 인덱싱용 |
| `jamo_search_analyzer` | standard | lowercase | 자모 검색용 |

---

## 주요 클래스 사용법

### SearchBuilder — 전문 검색

```php
use Zslab\Search\Search\SearchBuilder;

/** @var SearchBuilder $search */
$search = app(SearchBuilder::class);

$result = $search
    ->index('lms_courses')
    ->fields(['title^3', 'description'])
    ->query($keyword)
    ->filter(['status' => 'published'])
    ->fuzzyField('title_jamo')   // 자모 검색 필드 (선택)
    ->fallback(fn() => Course::paginate())
    ->page($page, 20)
    ->search();

// $result: PaginatedResult
// $result->total        전체 건수
// $result->data         _source 배열의 배열
// $result->ids          _id 배열 (DB 추가 조회용)
// $result->currentPage
// $result->lastPage
```

**쿼리 전략 (should)**
1. nori multi_match — `operator: and`, `fuzziness: AUTO`
2. phrase_prefix multi_match — 접두사 일치
3. `fuzzyField` 지정 시 자모 변환 후 `match_phrase_prefix` 또는 토큰 분리 `must` 절 추가

### SuggestBuilder — 자동완성

```php
use Zslab\Search\Search\SuggestBuilder;

/** @var SuggestBuilder $suggest */
$suggest = app(SuggestBuilder::class);

$items = $suggest
    ->index('zslab_products')
    ->field('name')              // search_as_you_type 필드
    ->fuzzyField('name_jamo')   // 자모 검색 필드 (선택)
    ->query($keyword)
    ->size(10)
    ->suggest();

// $items: _source 배열의 배열
```

`search_as_you_type` 필드는 `_2gram`, `_3gram` 서브필드를 포함하며 `bool_prefix`로 쿼리한다.  
`fuzzyField` 지정 시 자모 변환 후 마지막 토큰에 `match_phrase_prefix`, 앞 토큰에 `match`를 적용한다.

### IndexManager — 인덱스 관리

```php
use Zslab\Search\Index\IndexManager;

/** @var IndexManager $manager */
$manager = app(IndexManager::class);

$mapping = [
    'properties' => [
        'title' => ['type' => 'text', 'analyzer' => 'nori_ngram_analyzer', 'search_analyzer' => 'nori_search_analyzer'],
        'title_jamo' => ['type' => 'text', 'analyzer' => 'jamo_analyzer', 'search_analyzer' => 'jamo_search_analyzer'],
    ],
];

// 없으면 생성, 있으면 무시
$manager->ensureIndex('lms_courses', $mapping);

// 무중단 재색인 (기존 → _old 백업 → 삭제 → 신규 생성 → 색인 → 백업 삭제)
$manager->reindex('lms_courses', $mapping, function () {
    return Course::all()->map->toSearchArray();
});
```

### Searchable 인터페이스

모델에 `Searchable`을 구현하면 `SearchableObserver`가 `saved`/`deleted` 이벤트에 반응한다.

```php
use Zslab\Search\Contracts\Searchable;

class Course extends Model implements Searchable
{
    public function toSearchArray(): array
    {
        return ['id' => $this->id, 'title' => $this->title, /* ... */];
    }

    public static function getSearchIndex(): string { return 'lms_courses'; }
    public static function getSearchFields(): array { return ['title^3', 'description']; }
}

// AppServiceProvider 등에서 옵저버 등록
Course::observe(SearchableObserver::class);
```

---

## JamoConverter

한글 음절을 초성·중성·종성 자모로 분해한다. 비한글 문자는 그대로 유지한다.

```php
use Zslab\Search\Utils\JamoConverter;

JamoConverter::convert('파이썬');  // → 'ㅍㅏㅇㅣㅆㅓㄴ'
JamoConverter::convert('셔츠');   // → 'ㅅㅕㅊㅡ'
JamoConverter::convert('React');  // → 'React'
```

자모 검색 필드는 인덱싱 시 `JamoConverter::convert()` 결과를 저장하고,  
`SearchBuilder::fuzzyField()` / `SuggestBuilder::fuzzyField()`로 연결한다.

---

## 프로젝트 연동 방법

### 1. composer.json — path 레포지토리 등록

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "/home/zslab-infra/zslab-search",
      "options": { "symlink": true }
    }
  ],
  "require": {
    "zslab/search": "^1.0"
  }
}
```

개발 환경에서는 심볼릭 링크로 마운트되므로 `sync-search.sh` 없이도 즉시 반영된다.

### 2. Dockerfile — 배포 이미지용 COPY

로컬 심볼릭 링크는 Docker 빌드 컨텍스트 밖을 참조하지 못하므로,  
빌드 전 `sync-search.sh`로 패키지를 `packages/zslab-search`에 복사한 뒤 COPY한다.

```dockerfile
# 빌드 스테이지
COPY packages/zslab-search /home/zslab-infra/zslab-search

# 실행 스테이지
COPY --from=build /home/zslab-infra/zslab-search /home/zslab-infra/zslab-search
```

### 3. 설정값 (.env)

```dotenv
ELASTICSEARCH_ENABLED=true
ELASTICSEARCH_HOST=elasticsearch
ELASTICSEARCH_PORT=9200
ELASTICSEARCH_SCHEME=http
ELASTICSEARCH_ANALYZER_PRESET=nori_ngram
```

`ELASTICSEARCH_ENABLED=false`로 설정하면 모든 ES 호출을 건너뛰고 fallback을 반환한다.

---

## sync-search.sh 사용법

`/home/zslab-infra/sync-search.sh`는 infra 소스를 LMS·shop의 `packages/zslab-search`에 동기화한다.

```bash
# 실행
/home/zslab-infra/sync-search.sh
```

- `src/`와 `composer.json`을 `rsync --delete`로 덮어쓴다.
- LMS: `/home/zslab-lms/backend/packages/zslab-search`
- shop: `/home/zslab/backend/packages/zslab-search`
- 경로가 없으면 경고만 출력하고 계속 진행한다.

패키지 수정 후 반드시 실행하고, docker restart로 변경사항을 적용한다.

```bash
/home/zslab-infra/sync-search.sh
docker restart lms_php zslab_api
```
