<?php
/**
 * GNUboard5: product 게시판 -> pr_detail 자동 등록
 * 
 * 파일: /extend/product_to_detail.extend.php
 * 
 * 원본 게시글:
 *   product.wr_subject = 고유 상품코드
 *   product.wr_content = 상품명 및 상품 내용이 포함된 JSON
 * 
 * 대상 게시글:
 *   pr_detail.wr_subject = 원본 고유 상품코드 그대로
 *   pr_detail.wr_content = 원본 JSON 그대로
 * 
 * 처리:
 *   - product 게시판의 신규 글만 처리
 *   - product 게시판 내에 동일한 상품코드가 이미 있으면 duplicate 응답 후 처리 안 함
 *   - pr_detail.wr_subject에 동일한 상품코드가 있으면 신규 글 생성 안 함
 *   - wr_1, wr_2는 사용하지 않음
 * 
 * API 응답 연동 시 사용할 수 있는 상태:
 *   created, duplicate, failed, skipped
 */

if (!defined('_GNUBOARD_')) {
    exit;
}

if (!function_exists('product_to_detail_after_write')) {
    add_event('write_update_after', 'product_to_detail_after_write', 10, 5);

    /**
     * product 게시글 저장 후 실행되는 이벤트 콜백
     */
    function product_to_detail_after_write($board, $wr_id, $w, $qstr, $redirect_url) {
        if (($board['bo_table'] ?? '') !== 'product') {
            return;
        }

        // 신규 등록만 처리
        if ($w !== '') {
            return;
        }

        try {
            $GLOBALS['product_to_detail_status'] = product_to_detail_create_post((int)$wr_id);
        } catch (\Throwable $e) {
            $GLOBALS['product_to_detail_status'] = [
                'status' => 'failed',
                'message' => 'Failed to create the pr_detail post.',
            ];
            error_log('[product_to_detail] source_wr_id=' . (int)$wr_id . ' error=' . $e->getMessage());
        }
    }

    /**
     * 원본 상품 게시글을 확인하고 pr_detail 게시글 생성
     */
    function product_to_detail_create_post($source_wr_id) {
        global $g5;

        if ($source_wr_id < 1) {
            throw new \RuntimeException('Invalid source wr_id');
        }

        $source_table = $g5['write_prefix'] . 'product';
        $target_table = $g5['write_prefix'] . 'pr_detail';

        // 대상 게시판 존재 여부 확인
        $target_board = sql_fetch("
            SELECT bo_table 
            FROM {$g5['board_table']} 
            WHERE bo_table = 'pr_detail' 
            LIMIT 1
        ");

        if (empty($target_board['bo_table'])) {
            throw new \RuntimeException('Target board pr_detail does not exist');
        }

        // 원본 글 조회: wr_subject는 고유 상품코드, wr_content는 JSON
        $source = sql_fetch("
            SELECT * 
            FROM {$source_table} 
            WHERE wr_id = '{$source_wr_id}' 
            LIMIT 1
        ");

        if (empty($source['wr_id'])) {
            throw new \RuntimeException('Source product post not found');
        }

        // 고유 상품코드를 원본값 그대로 사용
        $product_code = (string)($source['wr_subject'] ?? '');
        if ($product_code === '') {
            throw new \RuntimeException('Source wr_subject (product code) is empty');
        }

        // JSON은 유효성만 검사하고 저장할 때는 원본 문자열 그대로 사용
        $json = (string)($source['wr_content'] ?? '');
        if ($json === '') {
            throw new \RuntimeException('Source wr_content is empty');
        }

        json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid product JSON: ' . json_last_error_msg());
        }

        if (strlen($json) > 65536) {
            throw new \RuntimeException('Product JSON exceeds 64 KiB');
        }

        $subject_sql = sql_real_escape_string($product_code);

        // [추가됨] product 게시판 내에 방금 등록된 글(현재 $source_wr_id)을 제외하고, 
        // 동일한 상품코드(wr_subject)를 가진 기존 글이 있는지 확인
        $existing_product = sql_fetch("
            SELECT wr_id 
            FROM {$source_table} 
            WHERE wr_subject = '{$subject_sql}' 
              AND wr_id != '{$source_wr_id}' 
            LIMIT 1
        ");

        if (!empty($existing_product['wr_id'])) {
            return [
                'status' => 'duplicate',
                'message' => 'Duplicate product code found in product board.',
            ];
        }

        // 동일한 상품코드가 pr_detail에 이미 있으면 신규 등록하지 않음
        $existing = sql_fetch("
            SELECT wr_id 
            FROM {$target_table} 
            WHERE wr_subject = '{$subject_sql}' 
            LIMIT 1
        ");

        if (!empty($existing['wr_id'])) {
            return [
                'status' => 'duplicate',
                'target_wr_id' => (int)$existing['wr_id'],
            ];
        }

        // SQL 저장을 위해 이스케이프만 수행; DB에는 원본 문자열이 저장됨
        $content_sql = sql_real_escape_string($json);
        $mb_id = sql_real_escape_string((string)($source['mb_id'] ?? ''));
        
        $wr_name = (string)($source['wr_name'] ?? '');
        if ($wr_name === '') {
            $wr_name = '상품 자동등록';
        }
        $wr_name_sql = sql_real_escape_string(mb_substr($wr_name, 0, 255, 'UTF-8'));
        $wr_email_sql = sql_real_escape_string((string)($source['wr_email'] ?? ''));
        $wr_homepage_sql = sql_real_escape_string((string)($source['wr_homepage'] ?? ''));
        $wr_ip_sql = sql_real_escape_string((string)($source['wr_ip'] ?? ''));
        $now_sql = sql_real_escape_string(G5_TIME_YMDHIS);

        // GNUboard 게시글 번호
        $num_row = sql_fetch("
            SELECT IFNULL(MIN(wr_num) - 1, -1) AS wr_num 
            FROM {$target_table}
        ");
        $wr_num = (int)($num_row['wr_num'] ?? -1);

        // 상품코드는 wr_subject에, 원본 JSON은 wr_content에 그대로 저장
        $insert_sql = "
            INSERT INTO {$target_table} SET 
                wr_num = '{$wr_num}', 
                wr_reply = '', 
                wr_comment = 0, 
                ca_name = '', 
                wr_option = '', 
                wr_subject = '{$subject_sql}', 
                wr_content = '{$content_sql}', 
                wr_seo_title = '', 
                wr_link1 = '', 
                wr_link2 = '', 
                wr_link1_hit = 0, 
                wr_link2_hit = 0, 
                wr_hit = 0, 
                wr_good = 0, 
                wr_nogood = 0, 
                mb_id = '{$mb_id}', 
                wr_password = '', 
                wr_name = '{$wr_name_sql}', 
                wr_email = '{$wr_email_sql}', 
                wr_homepage = '{$wr_homepage_sql}', 
                wr_datetime = '{$now_sql}', 
                wr_last = '{$now_sql}', 
                wr_ip = '{$wr_ip_sql}'
        ";

        if (!sql_query($insert_sql, false)) {
            throw new \RuntimeException('Failed to insert pr_detail post');
        }

        $target_wr_id = (int)sql_insert_id();
        if ($target_wr_id < 1) {
            throw new \RuntimeException('Failed to retrieve target wr_id');
        }

        // 부모 게시글 번호 설정
        sql_query("
            UPDATE {$target_table} 
            SET wr_parent = '{$target_wr_id}' 
            WHERE wr_id = '{$target_wr_id}'
        ");

        // 새 글 테이블 갱신
        sql_query("
            INSERT INTO {$g5['board_new_table']} (bo_table, wr_id, wr_parent, bn_datetime, mb_id) 
            VALUES ('pr_detail', '{$target_wr_id}', '{$target_wr_id}', '{$now_sql}', '{$mb_id}')
        ");

        // 게시판 글 수 증가
        sql_query("
            UPDATE {$g5['board_table']} 
            SET bo_count_write = bo_count_write + 1 
            WHERE bo_table = 'pr_detail'
        ");

        error_log('[product_to_detail] created source_wr_id=' . $source_wr_id . ' target_wr_id=' . $target_wr_id);

        return [
            'status' => 'created',
            'target_wr_id' => $target_wr_id,
        ];
    }
}
