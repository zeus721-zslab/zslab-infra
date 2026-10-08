# zslab-infra

공용 인프라 서비스 관리 레포지토리. MariaDB, Redis, Elasticsearch 스택을 단일 Docker Compose로 운영하며, 연결된 프로젝트들(zslab-shop, zslab-lms, crawl-blog)이 이 인프라를 공유한다.

---

## 서비스 구성

| 서비스 | 이미지 | 컨테이너명 | 역할 |
|---|---|---|---|
| MariaDB | `mariadb:10.11` | `zslab_mariadb` | 공용 관계형 DB |
| Redis | `redis:7-alpine` | `zslab_redis` | 캐시 / 세션 |
| Elasticsearch | `zslab-elasticsearch:8.13.0-nori` (빌드) | `zslab_elasticsearch` | 로그 저장 및 검색 |
| Logstash | `logstash:8.13.0` | `zslab_logstash` | 로그 수집 및 파이프라인 |
| Kibana | `kibana:8.13.0` | `zslab_kibana` | 로그 시각화 |
| Filebeat | `filebeat:8.13.0` | `zslab_filebeat` | 로그 파일 수집 에이전트 |

Filebeat가 수집하는 로그 경로:
- `/home/zslab/backend/storage/logs` — Laravel 애플리케이션 로그
- `/home/gateway/logs/nginx` — Nginx 액세스/에러 로그

---

## 네트워크 구성

모든 네트워크는 `external: true`로 사전 생성되어 있어야 한다.

| 네트워크 | 용도 | 연결 서비스 |
|---|---|---|
| `infra_net` | 사이트 ↔ 공유 인프라 표준 네트워크 (인프라 서비스 간 통신 포함) | 전체 서비스 |
| `gateway_net` | gateway ↔ 사이트 (gateway에서 realtime 접근) | zslab-realtime |
| `portfolio_portfolio_net` | realtime이 portfolio에 접근할 때 사용 | zslab-realtime |

네트워크 생성 (최초 1회):

```bash
docker network create infra_net
docker network create gateway_net
docker network create portfolio_portfolio_net
```

---

## 볼륨 구성

볼륨도 `external: true`로 사전 생성되어 있어야 한다.

| 볼륨명 | 마운트 경로 |
|---|---|
| `zslab_mariadb_data` | MariaDB 데이터 |
| `zslab_redis_data` | Redis AOF 데이터 |
| `zslab_elasticsearch_data` | Elasticsearch 인덱스 데이터 |
| `zslab_kibana_data` | Kibana 설정/대시보드 |

볼륨 생성 (최초 1회):

```bash
docker volume create zslab_mariadb_data
docker volume create zslab_redis_data
docker volume create zslab_elasticsearch_data
docker volume create zslab_kibana_data
```

---

## 환경변수 (.env)

`.env` 파일을 `docker-compose.infra.yml`과 같은 위치에 생성한다.

| 변수 | 설명 |
|---|---|
| `DB_ROOT_PASSWORD` | MariaDB root 계정 비밀번호 |
| `DB_DATABASE` | 기본 생성 데이터베이스명 |
| `DB_USERNAME` | 애플리케이션용 DB 계정명 |
| `DB_PASSWORD` | 애플리케이션용 DB 비밀번호 |
| `REDIS_PASSWORD` | Redis 인증 비밀번호 (`requirepass`) |
| `ELASTICSEARCH_PASSWORD` | Elasticsearch `elastic` 계정 비밀번호 |

---

## 기동 / 종료

```bash
# 기동
docker compose -f docker-compose.infra.yml up -d

# 상태 확인
docker compose -f docker-compose.infra.yml ps

# 로그 확인
docker compose -f docker-compose.infra.yml logs -f [서비스명]

# 종료
docker compose -f docker-compose.infra.yml down
```

---

## Elasticsearch (nori)

- 이미지는 `docker/elasticsearch/Dockerfile`로 빌드한다(`elasticsearch:8.13.0` + `analysis-nori` 플러그인 → `zslab-elasticsearch:8.13.0-nori`). 기동 시 `--build`를 붙이면 이미지가 없을 때 빌드된다.
- 서버(Linux 호스트)는 `vm.max_map_count=262144`가 필요하다. `/etc/sysctl.d/99-elasticsearch.conf`에 설정되어 있다.
- 플러그인 확인: `docker exec zslab_elasticsearch curl -s localhost:9200/_cat/plugins` → `analysis-nori` 포함

---

## 운영 반영 방식

CI/CD는 없다. 서버는 https origin(읽기 전용)이며, 수정은 PC → PR → 머지로만 한다.

1. 서버에서 `zslab-infra` 유저로 INFRA_DIR에서 `git pull`
2. 인프라 변경: 바뀐 서비스만 이름을 지정해 재생성한다. `my.cnf`·`logstash.yml`·`kibana.yml`·`filebeat.yml` 같은 단일 파일 마운트를 바꾼 경우도 해당 서비스를 재생성하면 반영된다.
   ```bash
   docker compose -f docker-compose.infra.yml up -d --no-deps <서비스명>
   ```
3. gateway 변경: `scripts/deploy-gateway.sh`로 반영한다. conf는 같은 inode에 덮어쓰기 → `nginx -t` → reload, 실패 시 자동 원복한다.
   ```bash
   sudo GATEWAY_DIR=<경로> bash scripts/deploy-gateway.sh
   ```

### Kibana 접속

공개 경로는 없다(gateway에 `/kibana` 없음). 필요할 때만 Kibana를 기동하고 SSH 터널로 접속한다.

```bash
ssh -L 5601:127.0.0.1:5601 <서버>
# 접속: http://localhost:5601/kibana  (kibana.yml server.basePath: "/kibana")
```

---

## PC 로컬 사용법

PC는 MariaDB·Elasticsearch·Redis만 띄운다. 서버 전용 서비스(zslab-realtime, kibana, logstash, filebeat)는 기동하지 않으므로 항상 서비스 이름을 지정한다.

```bash
# 최초 1회: 네트워크·볼륨
docker network create infra_net
docker volume create zslab_mariadb_data
docker volume create zslab_redis_data
docker volume create zslab_elasticsearch_data

# 기동 (.env 필요: DB_ROOT_PASSWORD, DB_DATABASE, DB_USERNAME, DB_PASSWORD, REDIS_PASSWORD)
# docker-compose.local.yml: PC에서 호스트 포트로 접속하기 위한 override (서버에는 없는 파일)
docker compose -f docker-compose.infra.yml -f docker-compose.local.yml up -d --build mariadb elasticsearch redis

# 확인
docker exec zslab_mariadb sh -c 'mariadb-admin -uroot -p"$MYSQL_ROOT_PASSWORD" ping'
docker exec zslab_elasticsearch curl -s 'localhost:9200/_cluster/health?filter_path=status'
docker exec zslab_elasticsearch curl -s localhost:9200/_cat/plugins
```

서버 compose(`docker-compose.infra.yml`)에는 MariaDB 호스트 포트 공개가 없다. PC에서는 `docker-compose.local.yml`로 `127.0.0.1:3306`만 공개해 호스트 DB 클라이언트 접속을 허용한다. 컨테이너 간 통신은 `infra_net` 안에서 `zslab_mariadb` 이름으로 한다.

---

## 재기동 순서 주의사항

**인프라를 먼저 기동한 뒤 각 프로젝트를 기동해야 한다.**

프로젝트 컨테이너들이 `infra_net`을 통해 MariaDB·Redis·Elasticsearch에 접속하므로, 인프라가 준비되지 않은 상태에서 프로젝트를 먼저 올리면 DB 연결 오류가 발생한다.

```
1. zslab-infra 기동  →  docker compose -f docker-compose.infra.yml up -d
2. zslab-shop 기동
3. zslab-lms 기동
4. crawl-blog 기동
```

Elasticsearch 초기 기동은 수 초~수십 초 소요될 수 있다. Kibana와 Logstash는 `depends_on: elasticsearch`로 선언되어 있으나, Elasticsearch가 완전히 준비되기 전에 요청을 보내면 재시도가 발생할 수 있다.

---

## 디렉토리 구조

```
.
├── docker-compose.infra.yml
├── .env
├── sync-search.sh              # zslab-search → 각 프로젝트 동기화 스크립트
├── docker/
│   ├── elasticsearch/
│   │   └── Dockerfile          # elasticsearch:8.13.0 + analysis-nori
│   ├── mariadb/
│   │   └── my.cnf              # MariaDB 커스텀 설정
│   ├── logstash/
│   │   ├── config/
│   │   │   └── logstash.yml    # Logstash 기본 설정
│   │   └── pipeline/
│   │       └── main.conf       # 로그 파이프라인 정의
│   ├── kibana/
│   │   └── kibana.yml          # Kibana 연결 설정
│   └── filebeat/
│       └── filebeat.yml        # 수집 경로 및 output 설정
├── gateway/                    # gateway_nginx 리버스 프록시 (서버 /home/gateway)
│   ├── docker-compose.yml
│   ├── README.md
│   └── nginx/
│       └── nginx.conf
├── zslab-realtime/             # 실시간 인프라 상태 알림 서비스 (Node.js)
│   ├── Dockerfile
│   ├── package.json
│   ├── package-lock.json
│   ├── server.js
│   └── handlers/
│       └── portfolio/
│           └── infra.js        # 인프라 상태 수집 핸들러
└── zslab-search/               # 공용 Elasticsearch 검색 패키지 (Laravel)
    ├── composer.json
    ├── config/
    │   └── zslab-search.php    # 설정 기본값 (host, index prefix 등)
    └── src/
        ├── ZslabSearchServiceProvider.php
        ├── Client/
        │   └── ElasticsearchClient.php   # ES 클라이언트 래퍼
        ├── Contracts/
        │   └── Searchable.php            # 검색 가능 모델 인터페이스
        ├── Index/
        │   ├── AnalyzerPresets.php       # nori / jamo 분석기 설정
        │   └── IndexManager.php          # 인덱스 생성·삭제·재색인
        ├── Observer/
        │   └── SearchableObserver.php    # 모델 이벤트 → ES 동기화
        ├── Search/
        │   ├── SearchBuilder.php         # 검색 쿼리 빌더
        │   ├── SuggestBuilder.php        # 자동완성 쿼리 빌더
        │   └── PaginatedResult.php       # 페이지네이션 결과 래퍼
        └── Utils/
            └── JamoConverter.php         # 한글 자모 분해 유틸
```

---

## zslab-search

복수 Laravel 프로젝트(zslab-shop, zslab-lms)에서 공유하는 Elasticsearch 검색 패키지.

| 항목 | 값 |
|---|---|
| Composer name | `zslab/search` |
| PHP | `>=8.1` |
| 의존 라이브러리 | `elasticsearch/elasticsearch ^8.0` |
| ServiceProvider | `Zslab\Search\ZslabSearchServiceProvider` |

**주요 기능:**
- nori 형태소 분석기 기반 한국어 검색 (`SearchBuilder`)
- 한글 자모 분해 검색 (`JamoConverter` + `jamo_analyzer`)
- 자동완성 (`SuggestBuilder`, bool_prefix + 자모 매칭)
- 모델 이벤트 자동 색인 (`SearchableObserver`)

**패키지 동기화:**

`sync-search.sh`로 `zslab-search/src/`를 각 프로젝트의 패키지 경로에 복사한다.

```bash
bash sync-search.sh
```

---

## gateway/

서버 `/home/gateway`의 리버스 프록시(`gateway_nginx`, 80/443 진입점) 설정.

- 들어 있는 것: `docker-compose.yml`(nginx, `gateway_net` 생성), `nginx/nginx.conf`, `README.md`
- 저장소 밖: 인증서(`certs/`), 로그(`logs/`), certbot webroot(`webroot/`), `.env`, `nginx.conf` 백업(`*.bak*`, `.gitignore` 대상)
- nginx conf 편집 규칙: `sed -i` 금지(파일을 새로 만들어 inode가 바뀌면 bind mount된 컨테이너가 옛 파일을 계속 본다). 같은 inode에 덮어쓰기(예 `cat new.conf > nginx/nginx.conf`) → `docker exec gateway_nginx nginx -t` → `docker exec gateway_nginx nginx -s reload`

---

## 연결된 프로젝트

| 프로젝트 | 사용 서비스 | 접속 네트워크 |
|---|---|---|
| zslab-shop | MariaDB, Redis, Elasticsearch | `infra_net` |
| zslab-lms | MariaDB, Redis | `infra_net` |
| crawl-blog | Elasticsearch | `infra_net` |
