<?php
// Rhymix/XE -> GNUBoard 마이그레이터 (개선판)
// ---------------------------------------------
// 기능 요약:
// - 원본(Rhymix 또는 XE)의 `*_modules`에서 게시판(board) 모듈을 탐지
// - 각 보드를 `g5_board`에 등록하고, 글은 `g5_write_{bo_table}`에 삽입
// - 회원(`member`), 메뉴(`g5_menu`), 사이트 제목(`g5_config`)과 같은 기본 항목을 마이그레이션
// 안전 장치:
// - 이미 존재하는 항목은 건너뜀(중복확인)
// - 긴 본문은 컬럼 타입을 확장하거나 자동으로 잘라내서 실패를 방지
// - 실행 전 반드시 소스/대상 DB 백업 권장
// 사용법:
// - `config.php`에 `$src_config`(원본)와 `$gn_config`(대상)를 설정
// - `php migrate2gb.php`로 실행
// 주의: 이 스크립트는 스키마 차이(커스텀 필드, 권한 등)를 완벽히 처리하지 않습니다.

include "config.php";

// -----------------------------
// CLI options and logging
// -----------------------------
$dryRun = false;        // --dry-run : do not perform any writes
$logFile = null;        // --log=path : append log to file
$batchSize = 100;      // --batch-size=N : number of docs per DB batch (to limit memory)

// Parse command line arguments (supports php migrate2gb.php --dry-run --log=path --batch-size=N)
if (php_sapi_name() === 'cli') {
    global $argv;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run' || $arg === '-n') $dryRun = true;
        elseif (strpos($arg, '--log=') === 0) $logFile = substr($arg, 6);
        elseif (strpos($arg, '--batch-size=') === 0) $batchSize = (int)substr($arg, 13);
        elseif ($arg === '--help' || $arg === '-h') {
            echo "Usage: php migrate2gb.php [--dry-run] [--log=FILE] [--batch-size=N]\n";
            echo "  --dry-run, -n        : show what would be done without writing to DB\n";
            echo "  --log=FILE           : append logs to FILE\n";
            echo "  --batch-size=N       : number of documents to fetch per batch (default 100)\n";
            exit(0);
        }
    }

    // Normalize log file path (make absolute relative to current working directory)
    if ($logFile) {
        if ($logFile[0] !== '/') {
            $logFile = getcwd() . DIRECTORY_SEPARATOR . $logFile;
        }
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            // try to create directory if missing
            @mkdir($logDir, 0775, true);
        }
        // create or touch the log file
        $ok = @file_put_contents($logFile, "", FILE_APPEND);
        if ($ok === false || !is_writable($logFile)) {
            // warn the user that the log path may not be writable in this environment
            echo "경고: 로그 파일을 생성하거나 쓸 수 없습니다: {$logFile}\n";
        }
    }

    // 메모리 최적화: 실행 환경의 memory_limit이 낮으면 자동으로 상향을 시도합니다.
    // 기본 목표는 512M, 필요 시 환경변수 MIGRATE_MEMORY로 조정 가능합니다.
    $targetMemory = getenv('MIGRATE_MEMORY') ?: '512M';
    function memory_to_bytes($v) {
        $v = trim($v);
        $last = strtolower($v[strlen($v)-1]);
        $num = (int)$v;
        switch($last) {
            case 'g': $num *= 1024; // fallthrough
            case 'm': $num *= 1024; // fallthrough
            case 'k': $num *= 1024;
        }
        return $num;
    }
    $current = ini_get('memory_limit');
    try {
        if (memory_to_bytes($current) < memory_to_bytes($targetMemory)) {
            ini_set('memory_limit', $targetMemory);
            log_msg("메모리 제한 상향 시도: {$current} -> {$targetMemory}\n");
        }
    } catch (Exception $e) {
        log_msg("메모리 제한 변경 시도 중 오류: " . $e->getMessage() . "\n");
    }
}

/**
 * log_msg
 * - Unified logging function. In dry-run mode it prefixes messages and still writes to log file if provided.
 */
function log_msg($msg) {
    global $dryRun, $logFile;
    $prefix = $dryRun ? 'DRY RUN: ' : '';
    echo $prefix . $msg;
    if ($logFile) {
        // ensure newline at end
        file_put_contents($logFile, $prefix . $msg, FILE_APPEND);
    }
}

/**
 * run_exec
 * - Wrapper for $db->exec that respects dry-run.
 */
function run_exec($db, $sql, $desc = '') {
    global $dryRun;
    if ($dryRun) {
        log_msg("Would exec: {$desc} -- SQL: " . (strlen($sql) > 200 ? substr($sql, 0, 200) . '...' : $sql) . "\n");
        return true;
    }
    return $db->exec($sql);
}

/**
 * run_stmt
 * - Wrapper for prepared statements' execute that respects dry-run.
 */
function run_stmt($stmt, $params = [], $desc = '') {
    global $dryRun;
    if ($dryRun) {
        log_msg("Would execute prepared: {$desc} -- params: " . json_encode($params) . "\n");
        return true;
    }
    return $stmt->execute($params);
}

try {
    // 데이터베이스 연결
    // `$src_db`는 Rhymix/XE 원본 DB, `$gn_db`는 GNUBoard 대상 DB입니다.
    // 연결 정보는 `config.php`에서 불러옵니다.
    // src_db는 큰 결과셋을 스트리밍 처리하기 위해 비버퍼드 쿼리를 사용합니다.
    // (PDO::MYSQL_ATTR_USE_BUFFERED_QUERY = false)
    $src_opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false
    ];
    $src_db = new PDO(
        "mysql:host={$src_config['server']};dbname={$src_config['database']}",
        $src_config['username'],
        $src_config['password'],
        $src_opts
    );

    // gn_db can remain default-buffered for convenience
    $gn_db = new PDO(
        "mysql:host={$gn_config['server']};dbname={$gn_config['database']}",
        $gn_config['username'],
        $gn_config['password']
    );
    // 에러는 예외로 처리하도록 설정해 명확한 실패 원인을 제공합니다.
    $gn_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // src_db already set ERRMODE via options above

} catch (Exception $e) {
    // 치명적 오류: 연결 실패는 더 이상 진행할 이유가 없으므로 종료합니다.
    die("DB 연결 오류: " . $e->getMessage() . "\n");
}

// 테이블 접두사 감지 (자동화)
// 알고리즘 요약:
// 1) SHOW TABLES로 모든 테이블을 조회합니다.
// 2) `_modules`로 끝나는 테이블명을 찾아 접두사 후보를 추출합니다(ex: `rx_modules` -> `rx_`).
// 3) 같은 접두사로 `_documents`와 `_member` 테이블이 존재하면 높은 우선순위를 부여합니다.
// 4) 위 조건이 없으면 `rhymix_` 또는 `xe_`가 있으면 우선 사용, 그 외에는 첫 번째 후보를 사용합니다.
// 이 방식은 커스텀 접두사(예: `rx_`)에도 동작하도록 설계되었습니다.
$prefix = null;
try {
    $tables = $src_db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $candidates = [];
    foreach ($tables as $t) {
        if (preg_match('/^(.+)_modules$/', $t, $m)) {
            $candidate = $m[1] . '_';
            // documents와 member 테이블이 함께 있는 접두사를 우선시
            if (in_array($m[1] . '_documents', $tables) && in_array($m[1] . '_member', $tables)) {
                $prefix = $candidate;
                break;
            }
            $candidates[] = $candidate;
        }
    }

    // 알려진 이름 우선, 그 다음 후보 목록에서 선택
    if (!$prefix) {
        if (in_array('rhymix_modules', $tables)) $prefix = 'rhymix_';
        elseif (in_array('xe_modules', $tables)) $prefix = 'xe_';
        elseif (!empty($candidates)) $prefix = $candidates[0];
    }
} catch (Exception $e) {
    // 예외는 무시하지만, 이후 $prefix 체크에서 실패 시 종료하도록 합니다.
}

if (!$prefix) {
    die("원본 DB에서 모듈 테이블 접두사를 자동으로 찾을 수 없습니다. (예: rhymix_modules 또는 xe_modules)\n");
}

log_msg("=== {$prefix} -> GNUBoard 마이그레이션 시작 ===\n");

// ---------------------
// 유틸리티 함수들
// ---------------------

/**
 * sanitize_bo_table
 * - XE/Rhymix의 모듈 ID나 mid를 안전한 bo_table로 변환합니다.
 * - 허용 문자: a-z0-9_ (최대 길이 20)
 */
function sanitize_bo_table($s) {
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9_]/', '_', $s);
    $s = preg_replace('/_+/', '_', $s);
    return substr($s, 0, 20);
}

/**
 * get_next_wr_num
 * - g5_write_{bo_table}의 wr_num을 음수로 내려가게 예약합니다.
 * - 이렇게 하면 기존의 wr_num과 충돌하지 않습니다.
 */
function get_next_wr_num($table, $db) {
    // 안전성 강화: g5_write_{table}가 없으면 생성 시도 후 기본 wr_num 반환
    try {
        $row = $db->query("SELECT min(wr_num) as min_num FROM g5_write_{$table}")->fetch(PDO::FETCH_ASSOC);
        return (int)$row['min_num'] - 1;
    } catch (PDOException $e) {
        // 테이블이 존재하지 않아 발생하는 오류일 가능성 있음
        $msg = $e->getMessage();
        if (stripos($msg, 'doesn\'t exist') !== false || stripos($msg, 'no such table') !== false || stripos($msg, 'Base table or view not found') !== false) {
            // ensure_write_table를 호출하여 안전하게 테이블을 생성
            log_msg("경고: g5_write_{$table} 테이블을 찾을 수 없어 생성 시도합니다.\n");
            ensure_write_table($db, $table);
            // 테이블이 비어있으므로 예약할 wr_num은 -1
            return -1;
        }
        // 다른 예외는 그대로 던집니다.
        throw $e;
    }
}

/**
 * ensure_write_table
 * - g5_write_{bo_table}가 존재하지 않으면 최소한의 스키마로 생성합니다.
 * - 추후 댓글, 첨부 등 추가 컬럼이 필요하면 스키마를 확장하세요.
 */
function ensure_write_table($gn_db, $bo_table) {
    $tbl = "g5_write_{$bo_table}";
    // 정확한 LIKE 인자 전달
    $exists = $gn_db->query("SHOW TABLES LIKE " . $gn_db->quote($tbl))->fetch();
    if ($exists) return;

    $sql = "CREATE TABLE IF NOT EXISTS `{$tbl}` (
        `wr_id` int(11) NOT NULL AUTO_INCREMENT,
        `wr_num` int(11) NOT NULL DEFAULT '0',
        `wr_reply` varchar(255) NOT NULL DEFAULT '',
        `wr_parent` int(11) NOT NULL DEFAULT '0',
        `wr_is_comment` tinyint(1) NOT NULL DEFAULT '0',
        `wr_comment` int(11) NOT NULL DEFAULT '0',
        `wr_subject` varchar(255) NOT NULL DEFAULT '',
        `wr_content` text NOT NULL,
        `wr_hit` int(11) NOT NULL DEFAULT '0',
        `wr_name` varchar(255) NOT NULL DEFAULT '',
        `wr_password` varchar(255) NOT NULL DEFAULT '',
        `wr_email` varchar(255) NOT NULL DEFAULT '',
        `wr_homepage` varchar(255) NOT NULL DEFAULT '',
        `wr_datetime` varchar(25) NOT NULL DEFAULT '',
        `wr_last` varchar(25) NOT NULL DEFAULT '',
        `wr_ip` varchar(40) NOT NULL DEFAULT '',
        `mb_id` varchar(20) NOT NULL DEFAULT '',
        PRIMARY KEY (`wr_id`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8;";
    run_exec($gn_db, $sql, "create table {$tbl}");
    log_msg("+ 생성: {$tbl}\n");
}

// 유틸리티: 테이블에서 NOT NULL이고 DEFAULT가 없는 컬럼 목록을 반환
function get_required_columns($gn_db, $table) {
    $stmt = $gn_db->prepare("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL");
    $stmt->execute([$table]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// COLUMN 정보 조회 (DATA_TYPE, CHARACTER_MAXIMUM_LENGTH)
function get_column_info($gn_db, $table, $column) {
    $stmt = $gn_db->prepare("SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * ensure_column_is_mediumtext
 * - 종종 wr_content 컬럼이 너무 작아서 긴 글을 못넣는 경우가 있음
 * - 가능하면 MEDIUMTEXT로 변경해서 큰 본문을 허용하도록 시도합니다.
 * - 변경 실패 시 경고만 출력하고 원래 동작(잘라내기)으로 대체합니다.
 */
function ensure_column_is_mediumtext($gn_db, $table, $column) {
    $info = get_column_info($gn_db, $table, $column);
    if (!$info) return;
    $dtype = strtolower($info['DATA_TYPE']);
    if (!in_array($dtype, ['text', 'mediumtext', 'longtext'])) {
        try {
            // 가능한 경우 MEDIUMTEXT로 확장
            run_exec($gn_db, "ALTER TABLE `" . $table . "` MODIFY `" . $column . "` MEDIUMTEXT NOT NULL", "alter {$table}.{$column} to MEDIUMTEXT");
            log_msg("+ 컬럼 변경: {$table}.{$column} -> MEDIUMTEXT\n");
        } catch (Exception $e) {
            log_msg("경고: {$table}.{$column} 타입 변경 실패: " . $e->getMessage() . "\n");
        }
    }
}

/**
 * get_column_max_chars
 * - INFORMATION_SCHEMA에서 얻어진 컬럼 정보를 바탕으로 가능한 최대 글자 수를 알려줍니다.
 * - INSERT 실패 시 안전하게 잘라낼 길이를 결정하는 데 사용됩니다.
 */
function get_column_max_chars($info) {
    if (!$info) return 0;
    $dtype = strtolower($info['DATA_TYPE']);
    if ($dtype === 'longtext') return 4294967295; // practical limit
    if ($dtype === 'mediumtext') return 16777215;
    if ($dtype === 'text') return 65535;
    if (!empty($info['CHARACTER_MAXIMUM_LENGTH'])) return (int)$info['CHARACTER_MAXIMUM_LENGTH'];
    return 0;
}

// 1) 모듈(게시판) 목록 읽기
$modules = $src_db->query("SELECT * FROM {$prefix}modules WHERE module = 'board'")->fetchAll(PDO::FETCH_ASSOC);
if (!$modules) {
    log_msg("원본 DB에서 게시판 모듈을 찾지 못했습니다. ({$prefix}modules 테이블 확인)\n");
    exit;
}

log_msg("발견된 게시판 수: " . count($modules) . "\n");

$menu_order = 1;

foreach ($modules as $m) {
    $module_srl = $m['module_srl'];
    $mid = isset($m['mid']) ? $m['mid'] : 'board_' . $module_srl;
    $title = isset($m['browser_title']) && $m['browser_title'] ? $m['browser_title'] : (isset($m['title']) ? $m['title'] : $mid);

    $bo_table = sanitize_bo_table($mid);

    // 2) g5_board에 보드 정보 삽입(존재하면 건너뜀)
    $exists = $gn_db->prepare("SELECT bo_table FROM g5_board WHERE bo_table = ?");
    $exists->execute([$bo_table]);
    if (!$exists->fetch()) {
        // g5_board에 필요한 NOT NULL 컬럼을 찾아 자동으로 기본값을 채워 INSERT 합니다.
        // 이유: 실제 설치된 g5_board 스키마가 다를 수 있고, 일부 컬럼(예: bo_category_list)이 NOT NULL이지만
        // DEFAULT가 없어 INSERT가 실패할 수 있기 때문입니다. 필요한 컬럼을 자동으로 채워 안정성을 높입니다.
        $required = get_required_columns($gn_db, 'g5_board');

        // 보장을 위해 항상 포함할 컬럼
        $always = ['bo_table', 'bo_subject'];
        $req_names = array_column($required, 'COLUMN_NAME');
        foreach ($always as $a) {
            if (!in_array($a, $req_names)) {
                // 데이터 타입을 기본으로 추가
                $required[] = ['COLUMN_NAME' => $a, 'DATA_TYPE' => 'varchar'];
            }
        }

        $colNames = [];
        $placeholders = [];
        $values = [];

        // 자주 사용되는 기본값 맵
        $defaults = [
            'bo_table' => $bo_table,
            'bo_subject' => $title,
            'bo_content_head' => '',
            'bo_page_rows' => 20,
            'bo_mobile_page_rows' => 15,
            'bo_skin' => 'basic',
            'bo_category_list' => '',
            'bo_use_category' => 0
        ];

        foreach ($required as $c) {
            $name = $c['COLUMN_NAME'];
            $dtype = strtolower($c['DATA_TYPE']);
            if (isset($defaults[$name])) {
                $val = $defaults[$name];
            } else {
                // 정수형은 0, 문자형은 빈 문자열로 기본값 설정
                if (in_array($dtype, ['tinyint','smallint','mediumint','int','bigint'])) $val = 0;
                else $val = '';
            }
            $colNames[] = "`$name`";
            $placeholders[] = '?';
            $values[] = $val;
        }

        // 실행: 실패할 가능성이 있는 컬럼들에 대해 사전에 기본값을 채워 넣음으로써 호환성을 확보합니다.
        $sql = "INSERT INTO g5_board (" . implode(',', $colNames) . ") VALUES (" . implode(',', $placeholders) . ")";
        $ins = $gn_db->prepare($sql);
        run_stmt($ins, $values, "insert g5_board {$bo_table}");
        log_msg("+ 보드 생성: {$bo_table} ({$title})\n");
    } else {
        log_msg("- 보드 이미 존재: {$bo_table} ({$title})\n");
    }

    // 3) write 테이블 존재 확인 및 생성
    ensure_write_table($gn_db, $bo_table);

    // wr_content 컬럼이 작아서 긴 글이 들어가지 못하는 경우를 대비해
    // 가능한 경우 MEDIUMTEXT로 확장 시도(권한이 없거나 실패하면 로그만 남깁니다).
    ensure_column_is_mediumtext($gn_db, "g5_write_{$bo_table}", "wr_content");

    // 4) 문서(게시글) 가져오기 - 문서당 INSERT 시 Data too long 예외가 발생하면
    //    해당 컬럼의 최대 길이를 확인해 안전하게 잘라서 재시도합니다.
    //    (원본 길이는 본문에 주석 형태로 남깁니다.)

    // 3) write 테이블 존재 확인 및 생성
    ensure_write_table($gn_db, $bo_table);

    // wr_content 컬럼이 작은 타입(varchar 등)이면 MEDIUMTEXT로 변경 시도
    ensure_column_is_mediumtext($gn_db, "g5_write_{$bo_table}", "wr_content");

    // 4) 문서(게시글) 가져오기 (스트리밍 처리로 메모리 절감)
    // 전체 문서 수를 먼저 카운트하여 진행 상황을 알립니다.
    $cnt_stmt = $src_db->prepare("SELECT COUNT(*) FROM {$prefix}documents WHERE module_srl = ?");
    $cnt_stmt->execute([$module_srl]);
    $doc_total = (int)$cnt_stmt->fetchColumn();
    // close cursor to allow subsequent unbuffered queries on the same connection
    if (method_exists($cnt_stmt, 'closeCursor')) $cnt_stmt->closeCursor();
    log_msg("  -> 문서 수: {$doc_total} (streaming)\n");

    $docs_stmt = $src_db->prepare("SELECT * FROM {$prefix}documents WHERE module_srl = ? ORDER BY regdate ASC");
    $docs_stmt->execute([$module_srl]);

    $processed = 0;
    while ($doc = $docs_stmt->fetch(PDO::FETCH_ASSOC)) {
        $processed++;
        if (isset($doc['status']) && strtoupper($doc['status']) === 'DELETED') {
            unset($doc);
            continue;
        }

        $wr_num = get_next_wr_num($bo_table, $gn_db);

        $ins = $gn_db->prepare("INSERT INTO g5_write_{$bo_table} 
            (wr_num, wr_reply, wr_parent, wr_is_comment, wr_comment, wr_subject, wr_content, wr_hit, wr_name, wr_datetime, wr_ip, mb_id)
            VALUES (?, '', 0, 0, 0, ?, ?, ?, ?, ?, ?, ?)");

        $content = isset($doc['content']) ? $doc['content'] : '';
        $content = str_replace('/storage/app/public/', '/data/file/' . $bo_table . '/', $content);

        $author_name = isset($doc['user_name']) && $doc['user_name'] ? $doc['user_name'] : (isset($doc['nick_name']) ? $doc['nick_name'] : '');
        $mb_id = isset($doc['user_id']) ? $doc['user_id'] : '';

        try {
            run_stmt($ins, [
                $wr_num,
                isset($doc['title']) ? $doc['title'] : '',
                $content,
                isset($doc['readed_count']) ? $doc['readed_count'] : 0,
                $author_name,
                isset($doc['regdate']) ? $doc['regdate'] : '',
                isset($doc['ipaddress']) ? $doc['ipaddress'] : '',
                $mb_id
            ], "insert g5_write_{$bo_table}");
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'Data too long') !== false || $e->getCode() == '22001') {
                $info = get_column_info($gn_db, "g5_write_{$bo_table}", "wr_content");
                $max = get_column_max_chars($info);
                if ($max > 0 && mb_strlen($content) > $max) {
                    $trunc = mb_substr($content, 0, max(0, $max - 200));
                    $trunc .= "\n\n[...truncated... original_length=" . mb_strlen($content) . "]";
                    run_stmt($ins, [
                        $wr_num,
                        isset($doc['title']) ? $doc['title'] : '',
                        $trunc,
                        isset($doc['readed_count']) ? $doc['readed_count'] : 0,
                        $author_name,
                        isset($doc['regdate']) ? $doc['regdate'] : '',
                        isset($doc['ipaddress']) ? $doc['ipaddress'] : '',
                        $mb_id
                    ], "insert g5_write_{$bo_table} (truncated)");
                    log_msg("    ! 잘림: 문서 " . (isset($doc['document_srl']) ? $doc['document_srl'] : '(unknown)') . " (원본 길이=" . mb_strlen($content) . ")\n");
                    unset($trunc);
                } else {
                    throw $e;
                }
            } else {
                throw $e;
            }
        }

        $new_wr_id = $gn_db->lastInsertId();
        run_exec($gn_db, "UPDATE g5_write_{$bo_table} SET wr_parent = '{$new_wr_id}' WHERE wr_id = '{$new_wr_id}'", "update wr_parent {$new_wr_id}");

        log_msg("    * 문서 " . (isset($doc['document_srl']) ? $doc['document_srl'] : '(unknown)') . " -> wr_id {$new_wr_id}\n");

        // 메모리 절약: 큰 변수와 참조 해제, 가비지 콜렉션 유도
        unset($content, $doc);
        if (function_exists('gc_collect_cycles')) gc_collect_cycles();
    }

    if (method_exists($docs_stmt, 'closeCursor')) $docs_stmt->closeCursor();
    log_msg("  -> 처리 완료: {$processed} / {$doc_total}\n");

    // 5) 메뉴 항목 추가 (간단 매핑)
    $menu_check = $gn_db->prepare("SELECT me_id FROM g5_menu WHERE me_code = ?");
    $menu_check->execute([$bo_table]);
    if (!$menu_check->fetch()) {
        $menu_ins = $gn_db->prepare("INSERT INTO g5_menu (me_code, me_name, me_link, me_target, me_order, me_use) VALUES (?, ?, ?, ?, ?, ?)");
        $menu_link = './board.php?bo_table=' . $bo_table;
        run_stmt($menu_ins, [$bo_table, $title, $menu_link, '_self', $menu_order++, 1], "insert g5_menu {$bo_table}");
        log_msg("+ 메뉴 추가: {$title} -> {$menu_link}\n");
    }
}

// 회원 마이그레이션 (기본 필드만)
$members = $src_db->query("SELECT * FROM {$prefix}member")->fetchAll(PDO::FETCH_ASSOC);
if ($members) {
    log_msg("회원 수: " . count($members) . " -> 마이그레이션 시작\n");
    foreach ($members as $m) {
        $mb_id = $m['user_id'];
        $check = $gn_db->prepare("SELECT mb_id FROM g5_member WHERE mb_id = ?");
        $check->execute([$mb_id]);
        if ($check->fetch()) continue;

        $rand_pass = bin2hex(random_bytes(8));
        $orig_pw = isset($m['password']) ? $m['password'] : '';
        $memo = "migrated_from_rhymix member_srl=" . (isset($m['member_srl']) ? $m['member_srl'] : '') . " original_pass_hash=" . $orig_pw;

        $ins = $gn_db->prepare("INSERT INTO g5_member (mb_id, mb_password, mb_name, mb_nick, mb_email, mb_homepage, mb_datetime, mb_ip, mb_memo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        run_stmt($ins, [
            $mb_id,
            password_hash($rand_pass, PASSWORD_DEFAULT),
            isset($m['user_name']) ? $m['user_name'] : '',
            isset($m['nick_name']) ? $m['nick_name'] : '',
            isset($m['email_address']) ? $m['email_address'] : '',
            isset($m['homepage']) ? $m['homepage'] : '',
            isset($m['regdate']) ? $m['regdate'] : date('Y-m-d H:i:s'),
            isset($m['last_login']) ? $m['last_login'] : '',
            $memo
        ], "insert g5_member {$mb_id}");
        log_msg("+ 회원 생성: {$mb_id}\n");
    }
} else {
    log_msg("원본 회원 테이블을 찾지 못했거나 회원이 없습니다. ({$prefix}member)\n");
}

// 사이트 기본 설정(간단)
$site = $src_db->query("SELECT * FROM {$prefix}sites LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($site) {
    $title = isset($site['title']) ? $site['title'] : (isset($site['domain']) ? $site['domain'] : 'Migrated site');
    $chk = $gn_db->query("SELECT cf_id FROM g5_config LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($chk) {
        run_exec($gn_db, "UPDATE g5_config SET cf_title = " . $gn_db->quote($title) . " LIMIT 1", "update g5_config title");
        log_msg("+ 사이트 제목 업데이트: {$title}\n");
    } else {
        run_exec($gn_db, "INSERT INTO g5_config (cf_title) VALUES (" . $gn_db->quote($title) . ")", "insert g5_config title");
        log_msg("+ 사이트 제목 삽입: {$title}\n");
    }
}

log_msg("=== 마이그레이션 완료 ===\n");

?>