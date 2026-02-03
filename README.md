# Rhymix/XE → GNUBoard 마이그레이션 스크립트 🔧

**요약:** Rhymix/XE의 게시판(boards), 게시물(posts), 회원(members), 메뉴(menu) 및 기본 사이트 설정을 GNUBoard(g5)로 이전하는 소형 PHP 스크립트 모음입니다.

---

## 파일 구성

- `migrate2gb.php` — 메인 마이그레이션 스크립트. `rhymix_` 또는 `xe_` 같은 테이블 접두사를 자동으로 감지하고 다음 작업을 수행합니다:
  - `g5_board`에 보드 항목 생성, `g5_write_{bo_table}`이 없으면 최소 스키마로 생성
  - 게시물(문서) 마이그레이션, 간단한 파일 경로 치환, 회원을 `g5_member`에 삽입(임시 비밀번호 생성, 원본 해시는 `mb_memo`에 보관)
  - 간단한 메뉴 항목(`g5_menu`) 추가 및 `g5_config.cf_title` 갱신
- `config.template.php` — **원본**(Rhymix/XE) 및 **대상**(GNUBoard) 데이터베이스 연결 정보. 실행 전에 반드시 수정하셔서 `config.php` 파일로 저장하고 사용하세요.
  - 선택적으로 `prefix` 항목을 추가할 수 있습니다(예: `'rx_'`). `prefix`가 미설정이면 스크립트가 자동으로 원본 DB에서 접두사를 감지합니다. 대상 DB 접두사(`gn_config['prefix']`)를 지정하면 기본 `g5_` 대신 해당 접두사를 사용합니다.

---

## 빠른 시작 ✅

1. **원본/대상 데이터베이스를 반드시 백업**하세요. (항상) ⚠️
2. **반드시 별도 테스트서버에서 실행하여 이전 결과를 확인한 후에 실제 적용하세요. 데이터 손상은 본인 책임입니다.**
3. `config.php`에서 `$src_config`와 `$gn_config`에 올바른 접속 정보를 입력합니다.
4. 프로젝트 루트(또는 migration 디렉토리)에서 스크립트를 실행합니다. 변경을 가하지 않고 동작을 확인하려면 `--dry-run`을 사용하고 로그를 남기려면 `--log=파일`을 지정하세요.

```bash
# 실제 실행 (대상 DB에 쓰기)
php migrate2gb.php

# Dry-run (쓰기 없이 실행 계획만 출력)
php migrate2gb.php --dry-run

# Dry-run 및 로그 파일 기록
php migrate2gb.php --dry-run --log=migration.log
```

4. 콘솔 출력(또는 로그 파일)을 확인하여 생성된 보드, 게시물, 회원 및 경고 사항을 검토하세요.

---

## 멤버(회원) 마이그레이션 제어 옵션 🔧

대상 DB에 회원이 많을 경우(예: 100k+) 테스트 시 일부만 이전하는 것이 안전합니다. 아래 옵션을 추가로 지원합니다:

- `--max-members=N` — UID 기준으로 처음 N명의 회원만 이전합니다.
- `--member-batch-size=N` — 회원을 배치 단위로 처리합니다(메모리 사용 최소화).
- `--members=uid1,uid2,...` — 특정 UID 목록만 이전합니다.
- `--members-sample=P` — 무작위 샘플로 P%만 이전합니다(대표성 있는 테스트에 유용).

예시:
```bash
# 처음 500명만 dry-run으로 테스트 (100개 단위로 처리)
php migrate2gb.php --dry-run --max-members=500 --member-batch-size=100 --log=migration.log

# 특정 UID들만 테스트
php migrate2gb.php --dry-run --members=12,34,56 --log=migration.log
```

> 참고: 위 옵션들은 `--dry-run`과 함께 사용하면 실제 쓰기 없이 동작을 검토할 수 있습니다.

---

## 안전성 및 제약 사항 ⚠️

- 스크립트는 커스텀 필드, 권한 설정, 고급 모듈 구성 등을 완벽하게 변환하지 않습니다.
- 회원 비밀번호는 재설정됩니다: `mb_password`에 랜덤 비밀번호가 들어가고 원본 해시는 `mb_memo`에 보관됩니다. 사용자에게 비밀번호 재설정을 안내해야 합니다.
- 파일 경로 치환은 단순 치환을 사용합니다(예: `/storage/app/public/` → `/data/file/{bo_table}/`). 필요 시 수동 검토가 필요합니다.
- 대용량 데이터는 메모리/DB 제한에 영향을 줄 수 있으니 `--batch-size`, `--max-boards`, `--max-members` 등을 조절하여 소규모로 테스트하세요.
- 실제 실행 전, 항상 스테이징 환경에서 `--dry-run`으로 충분히 검증하세요.

---

## 문제 해결 팁 💡

- `rhymix_modules` 또는 `xe_modules`가 없다는 메시지가 나오면 `config.php`의 접속 정보와 소스 DB 구조를 확인하세요.
- 스키마 차이로 인서트가 실패하면 (`NOT NULL` 컬럼, 길이 제한 등) 로그를 확인하고 스크립트의 필드 매핑이나 대상 DB 스키마를 조정하세요.

---

## 제안 개선사항

- 첨부파일(attachments), 댓글(comments) 등의 추가 마이그레이션 지원
- 마이그레이션 요약 리포트 (dry-run 후 건수/문제 통계)
- 롤백 계획 또는 트랜잭션 기반의 부분 복구 기능

---

## 라이선스

자신의 책임 하에 사용하세요. 보증 없음. 반드시 백업을 해두고 테스트 서버에서 실행하셔서 마이그레이션 결과를 검증한 후 사용하세요.

---