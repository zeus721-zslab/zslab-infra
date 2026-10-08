# gateway

gateway_nginx 리버스 프록시 관리. 모든 도메인의 80/443 진입점으로 SSL 종단 및 각 서비스로 프록시를 담당한다.

---

## 역할

- 모든 도메인 HTTP/HTTPS 요청 수신
- Let's Encrypt SSL 인증서 종단
- 각 프로젝트 컨테이너로 리버스 프록시
- gateway_net 네트워크를 통해 각 서비스와 통신

---

## 네트워크 구성

| 네트워크 | 용도 |
|---|---|
| `gateway_net` | 각 프로젝트 컨테이너와 통신 (주 네트워크) |

---

## 관리 도메인

| 도메인 | 프록시 대상 |
|---|---|
| `zslab.duckdns.org` | 포트폴리오 |
| `zslab-shop.duckdns.org` | zslab-shop (zslab_caddy) |
| `zslab-lms.duckdns.org` | zslab-lms (lms_caddy) |
| `zslab-dev.duckdns.org` | zslab-dev |
| `crawl-blog.duckdns.org` | crawl-blog-frontend |
| `zslab-stg.duckdns.org` | 스테이징 환경 |

---

## SSL 인증서

Let's Encrypt (Certbot) 으로 발급. 인증서 위치: `./certs/{domain}/`
certs/
├── zslab.duckdns.org/
├── zslab-shop.duckdns.org/
├── zslab-lms.duckdns.org/
├── zslab-dev.duckdns.org/
├── crawl-blog.duckdns.org/
└── zslab-stg.duckdns.org/

**발급 명령어**
```bash
certbot certonly --webroot -w /home/gateway/webroot -d {domain}
# 인증서 위치: /etc/letsencrypt/live/{domain}/
# certs/ 디렉토리로 복사 후 nginx reload
```

**갱신 명령어**
```bash
certbot renew --webroot -w /home/gateway/webroot
docker exec gateway_nginx nginx -s reload
```

---

## nginx 설정 변경 절차

저장소(`zslab-infra`)의 `gateway/nginx/nginx.conf`를 고치고 PR → 머지한 뒤, 서버에서 배포 스크립트로 반영한다.

```bash
sudo GATEWAY_DIR=/home/gateway bash scripts/deploy-gateway.sh
```

스크립트가 하는 일:
- 저장소 conf와 `GATEWAY_DIR/nginx/nginx.conf`가 같으면 건너뜀
- 다르면 `nginx.conf.bak-<타임스탬프>`로 백업 후, **같은 inode에 덮어쓰기**(`sed -i`·`cp`·`git`은 inode가 바뀌어 bind mount된 컨테이너가 옛 파일을 계속 참조하게 됨)
- `docker exec gateway_nginx nginx -t`로 문법 검사 — 실패하면 백업으로 자동 원복
- 성공하면 `docker exec gateway_nginx nginx -s reload` (무중단)
- `docker-compose.yml`이 바뀐 경우는 백업 후 교체만 하고, `docker compose up -d`는 수동으로 실행해야 한다는 안내만 출력

---

## 디렉토리 구조
.
├── docker-compose.yml
├── .env
├── nginx/
│   └── nginx.conf          # 메인 nginx 설정
├── certs/                  # SSL 인증서 (도메인별)
│   └── {domain}/
│       ├── fullchain.pem
│       └── privkey.pem
├── logs/
│   └── nginx/              # nginx 액세스/에러 로그
└── webroot/                # certbot webroot 인증용

| 구분 | 경로 | 출처 |
|---|---|---|
| GATEWAY_DIR에만 존재 | `certs/`, `webroot/`, `logs/`, `.env`, `nginx/nginx.conf.bak-*` | 서버 로컬 데이터·백업 (git 미추적) |
| 저장소에서 배포 | `docker-compose.yml`, `nginx/nginx.conf` | `zslab-infra` 저장소 `gateway/` → `scripts/deploy-gateway.sh` |

---

## 기동 / 종료

```bash
# 기동
docker compose up -d

# 상태 확인
docker compose ps

# 로그 확인
docker logs gateway_nginx -f

# 설정 reload (무중단)
docker exec gateway_nginx nginx -s reload

# 종료
docker compose down
```

---

## 새 도메인 추가 절차

1. DuckDNS에서 서브도메인 등록
2. certbot으로 SSL 인증서 발급
3. 저장소 `gateway/nginx/nginx.conf`에 서버 블록 추가 → PR → 머지
4. 서버에서 `sudo GATEWAY_DIR=/home/gateway bash scripts/deploy-gateway.sh` 실행 (문법 검사·reload 포함)
