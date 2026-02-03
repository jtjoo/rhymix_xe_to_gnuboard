<?php
// Rhymix/XE -> GNUBoard 마이그레이터 (개선판)
// - Rhymix(또는 XE)의 board 모듈을 찾아 GNUBoard의 `g5_board`로 등록
// - 문서들을 `g5_write_{bo_table}`로 삽입 (필요 시 테이블 생성)
// - 회원, 메뉴, 기본 설정 일부를 마이그레이션
// 안전: 이미 존재하는 엔트리는 건너뜀, 간단한 로깅 출력
include "config.php";

try {
    $src_db = new PDO(
        "mysql:host={$src_config['server']};dbname={$src_config['database']}",
        $src_config['username'],
        $src_config['password']
    );
    $gn_db = new PDO(
        "mysql:host={$gn_config['server']};dbname={$gn_config['database']}",
        $gn_config['username'],
        $gn_config['password']
    );
    $src_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $gn_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("DB 연결 오류: " . $e->getMessage() . "\n");
}

// 테이블 접두사 감지 (자동화)
// - rhymix_/xe_ 외에도 어떤 접두사든 *_modules를 찾아 자동으로 사용합니다.
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
    // 무시, 아래에서 체크
}

if (!$prefix) {
    die("원본 DB에서 모듈 테이블 접두사를 자동으로 찾을 수 없습니다. (예: rhymix_modules 또는 xe_modules)\n");
}

echo "=== {$prefix} -> GNUBoard 마이그레이션 시작 ===\n";

// 유틸리티
function sanitize_bo_table($s) {
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9_]/', '_', $s);
    $s = preg_replace('/_+/', '_', $s);
    return substr($s, 0, 20);
}

function get_next_wr_num($table, $db) {
    $row = $db->query("SELECT min(wr_num) as min_num FROM g5_write_{$table}")->fetch(PDO::FETCH_ASSOC);
    return (int)$row['min_num'] - 1;
}

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
    $gn_db->exec($sql);
    echo "+ 생성: {$tbl}\n";
}

// 유틸리티: 테이블에서 NOT NULL이고 DEFAULT가 없는 컬럼 목록을 반환
function get_required_columns($gn_db, $table) {
    $stmt = $gn_db->prepare("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL");
    $stmt->execute([$table]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 1) 모듈(게시판) 목록 읽기
$modules = $src_db->query("SELECT * FROM {$prefix}modules WHERE module = 'board'")->fetchAll(PDO::FETCH_ASSOC);
if (!$modules) {
    echo "원본 DB에서 게시판 모듈을 찾지 못했습니다. ({$prefix}modules 테이블 확인)\n";
    exit;
}

echo "발견된 게시판 수: " . count($modules) . "\n";

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
        // 필요한 NOT NULL 컬럼을 찾아 기본값으로 채웁니다.
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

        $sql = "INSERT INTO g5_board (" . implode(',', $colNames) . ") VALUES (" . implode(',', $placeholders) . ")";
        $ins = $gn_db->prepare($sql);
        $ins->execute($values);
        echo "+ 보드 생성: {$bo_table} ({$title})\n";
    } else {
        echo "- 보드 이미 존재: {$bo_table} ({$title})\n";
    }

    // 3) write 테이블 존재 확인 및 생성
    ensure_write_table($gn_db, $bo_table);

    // 4) 문서(게시글) 가져오기
    $docs_stmt = $src_db->prepare("SELECT * FROM {$prefix}documents WHERE module_srl = ? ORDER BY regdate ASC");
    $docs_stmt->execute([$module_srl]);
    $docs = $docs_stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "  -> 문서 수: " . count($docs) . "\n";

    foreach ($docs as $doc) {
        if (isset($doc['status']) && strtoupper($doc['status']) === 'DELETED') continue;

        $wr_num = get_next_wr_num($bo_table, $gn_db);

        $ins = $gn_db->prepare("INSERT INTO g5_write_{$bo_table} 
            (wr_num, wr_reply, wr_parent, wr_is_comment, wr_comment, wr_subject, wr_content, wr_hit, wr_name, wr_datetime, wr_ip, mb_id)
            VALUES (?, '', 0, 0, 0, ?, ?, ?, ?, ?, ?, ?)");

        $content = isset($doc['content']) ? $doc['content'] : '';
        $content = str_replace('/storage/app/public/', '/data/file/' . $bo_table . '/', $content);

        $author_name = isset($doc['user_name']) && $doc['user_name'] ? $doc['user_name'] : (isset($doc['nick_name']) ? $doc['nick_name'] : '');
        $mb_id = isset($doc['user_id']) ? $doc['user_id'] : '';

        $ins->execute([
            $wr_num,
            isset($doc['title']) ? $doc['title'] : '',
            $content,
            isset($doc['readed_count']) ? $doc['readed_count'] : 0,
            $author_name,
            isset($doc['regdate']) ? $doc['regdate'] : '',
            isset($doc['ipaddress']) ? $doc['ipaddress'] : '',
            $mb_id
        ]);

        $new_wr_id = $gn_db->lastInsertId();
        $gn_db->exec("UPDATE g5_write_{$bo_table} SET wr_parent = '{$new_wr_id}' WHERE wr_id = '{$new_wr_id}'");

        echo "    * 문서 " . (isset($doc['document_srl']) ? $doc['document_srl'] : '(unknown)') . " -> wr_id {$new_wr_id}\n";
    }

    // 5) 메뉴 항목 추가 (간단 매핑)
    $menu_check = $gn_db->prepare("SELECT me_id FROM g5_menu WHERE me_code = ?");
    $menu_check->execute([$bo_table]);
    if (!$menu_check->fetch()) {
        $menu_ins = $gn_db->prepare("INSERT INTO g5_menu (me_code, me_name, me_link, me_target, me_order, me_use) VALUES (?, ?, ?, ?, ?, ?)");
        $menu_link = './board.php?bo_table=' . $bo_table;
        $menu_ins->execute([$bo_table, $title, $menu_link, '_self', $menu_order++, 1]);
        echo "+ 메뉴 추가: {$title} -> {$menu_link}\n";
    }
}

// 회원 마이그레이션 (기본 필드만)
$members = $src_db->query("SELECT * FROM {$prefix}member")->fetchAll(PDO::FETCH_ASSOC);
if ($members) {
    echo "회원 수: " . count($members) . " -> 마이그레이션 시작\n";
    foreach ($members as $m) {
        $mb_id = $m['user_id'];
        $check = $gn_db->prepare("SELECT mb_id FROM g5_member WHERE mb_id = ?");
        $check->execute([$mb_id]);
        if ($check->fetch()) continue;

        $rand_pass = bin2hex(random_bytes(8));
        $orig_pw = isset($m['password']) ? $m['password'] : '';
        $memo = "migrated_from_rhymix member_srl=" . (isset($m['member_srl']) ? $m['member_srl'] : '') . " original_pass_hash=" . $orig_pw;

        $ins = $gn_db->prepare("INSERT INTO g5_member (mb_id, mb_password, mb_name, mb_nick, mb_email, mb_homepage, mb_datetime, mb_ip, mb_memo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([
            $mb_id,
            password_hash($rand_pass, PASSWORD_DEFAULT),
            isset($m['user_name']) ? $m['user_name'] : '',
            isset($m['nick_name']) ? $m['nick_name'] : '',
            isset($m['email_address']) ? $m['email_address'] : '',
            isset($m['homepage']) ? $m['homepage'] : '',
            isset($m['regdate']) ? $m['regdate'] : date('Y-m-d H:i:s'),
            isset($m['last_login']) ? $m['last_login'] : '',
            $memo
        ]);
        echo "+ 회원 생성: {$mb_id}\n";
    }
} else {
    echo "원본 회원 테이블을 찾지 못했거나 회원이 없습니다. ({$prefix}member)\n";
}

// 사이트 기본 설정(간단)
$site = $src_db->query("SELECT * FROM {$prefix}sites LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($site) {
    $title = isset($site['title']) ? $site['title'] : (isset($site['domain']) ? $site['domain'] : 'Migrated site');
    $chk = $gn_db->query("SELECT cf_id FROM g5_config LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($chk) {
        $gn_db->exec("UPDATE g5_config SET cf_title = " . $gn_db->quote($title) . " LIMIT 1");
        echo "+ 사이트 제목 업데이트: {$title}\n";
    } else {
        $gn_db->exec("INSERT INTO g5_config (cf_title) VALUES (" . $gn_db->quote($title) . ")");
        echo "+ 사이트 제목 삽입: {$title}\n";
    }
}

echo "=== 마이그레이션 완료 ===\n";

?>