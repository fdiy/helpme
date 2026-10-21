<?php
/**
 * GNUboard5: product 게시판 JSON -> pr_detail 자동 등록
 *
 * 파일: /extend/product_to_detail.extend.php
 *
 * 요청 및 처리:
 *   POST /api/v1/boards/product/posts (인증 필요)
 *   요청 본문의 wr_content에 상품 JSON을 넣어 product 게시글을 신규 등록합니다.
 *   저장 후 이 확장 기능이 상품명·상품 코드를 JSON에서 읽어 pr_detail 글 1건을 생성합니다.
 *   product 게시글 수정과 답글은 pr_detail 생성 대상이 아닙니다.
 *
 * API 응답:
 *   data.product_to_detail.status = created       - pr_detail 신규 생성, target_wr_id 포함
 *   data.product_to_detail.status = duplicate     - 이미 처리됨, 기존 target_wr_id 포함
 *   data.product_to_detail.status = failed        - pr_detail 생성 실패
 *   data.product_to_detail.status = skipped       - 답글이라 처리하지 않음
 *   data.product_to_detail.status = not_available - 처리 상태를 확인할 확장 기능이 실행되지 않음
 *   failed여도 원본 product 글은 이미 저장된 상태이며, API는 원본 글 정보를 성공 응답으로 반환합니다.
 *
 * 요청 서버에서 확인할 응답 JSON 예시:
 *   생성 성공:
 *   {
 *     "success": true,
 *     "data": {
 *       "wr_id": 101,
 *       "product_to_detail": {
 *         "status": "created",
 *         "target_wr_id": 205
 *       }
 *     }
 *   }
 *
 *   요청 서버는 응답 JSON의 data.product_to_detail.status를 확인합니다.
 *   created / duplicate는 상세 게시글 처리가 완료된 상태이며,
 *   target_wr_id는 pr_detail 게시글 번호입니다.
 *
 * 원본:
 *   product.wr_content = JSON
 *
 * 대상:
 *   pr_detail.wr_subject = 상품명
 *   pr_detail.wr_content = 원본 JSON
 *   pr_detail.wr_1 = 원본 product 게시글 식별자
 *   pr_detail.wr_2 = 상품 코드
 *
 */

if (!defined('_GNUBOARD_')) {
    exit;
}

if (!function_exists('product_to_detail_after_write')) {

    add_event(
        'write_update_after',
        'product_to_detail_after_write',
        10,
        5
    );

    /**
     * 게시글 저장 후 실행
     */
    function product_to_detail_after_write(
        $board,
        $wr_id,
        $w,
        $qstr,
        $redirect_url
    ) {
        // product 게시판만 처리
        if (($board['bo_table'] ?? '') !== 'product') {
            return;
        }

        // 신규 등록만 처리
        if ($w !== '') {
            return;
        }

        try {
            $GLOBALS['product_to_detail_status'] =
                product_to_detail_create_post((int)$wr_id);
        } catch (\Throwable $e) {
            $GLOBALS['product_to_detail_status'] = [
                'status' => 'failed',
                'message' => 'Failed to create the pr_detail post.',
            ];

            error_log(
                '[product_to_detail] ' .
                'source_wr_id=' . (int)$wr_id .
                ' error=' . $e->getMessage()
            );
        }
    }

    /**
     * 원본 JSON을 읽고 pr_detail 게시글 생성
     */
    function product_to_detail_create_post($source_wr_id)
    {
        global $g5, $config;

        if ($source_wr_id < 1) {
            throw new \RuntimeException('Invalid source wr_id');
        }

        $source_table = $g5['write_prefix'] . 'product';
        $target_table = $g5['write_prefix'] . 'pr_detail';

        // 대상 게시판이 실제로 존재하는지 확인
        $target_board = sql_fetch("
            SELECT bo_table, bo_subject
            FROM {$g5['board_table']}
            WHERE bo_table = 'pr_detail'
            LIMIT 1
        ");

        if (empty($target_board['bo_table'])) {
            throw new \RuntimeException(
                'Target board pr_detail does not exist'
            );
        }

        // 원본 상품 게시글 조회
        $source = sql_fetch("
            SELECT *
            FROM {$source_table}
            WHERE wr_id = '{$source_wr_id}'
            LIMIT 1
        ");

        if (empty($source['wr_id'])) {
            throw new \RuntimeException(
                'Source product post not found'
            );
        }

        $json = $source['wr_content'] ?? '';

        if (!is_string($json) || trim($json) === '') {
            throw new \RuntimeException(
                'Source wr_content is empty'
            );
        }

        // 게시글 내용은 JSON 객체여야 함
        $data = json_decode($json, true);

        if (
            json_last_error() !== JSON_ERROR_NONE ||
            !is_array($data)
        ) {
            throw new \RuntimeException(
                'Invalid product JSON: ' . json_last_error_msg()
            );
        }

        // 상품명 및 상품 코드: 공급처별 필드명 대응
        $product_name = '';

        foreach (['name', 'it_name', 'product_name', 'title'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $product_name = trim($data[$key]);

                if ($product_name !== '') {
                    break;
                }
            }
        }

        $product_code = '';

        foreach (
            ['product_code', 'it_id', 'prod_cd', 'code']
            as $key
        ) {
            if (
                isset($data[$key]) &&
                (is_string($data[$key]) || is_int($data[$key]))
            ) {
                $product_code = trim((string)$data[$key]);

                if ($product_code !== '') {
                    break;
                }
            }
        }

        if ($product_name === '') {
            throw new \RuntimeException(
                'Product name is missing from JSON'
            );
        }

        if (mb_strlen($product_name, 'UTF-8') > 250) {
            throw new \RuntimeException(
                'Product name exceeds 250 characters'
            );
        }

        if (strlen($json) > 65536) {
            throw new \RuntimeException(
                'Product JSON exceeds 64 KiB'
            );
        }

        /*
         * 중복 방지:
         * pr_detail.wr_1에 product:원본게시글번호 저장
         *
         * wr_1은 이 자동화에서 원본 식별자 전용으로 사용합니다.
         */
        $source_key = 'product:' . $source_wr_id;
        $source_key_sql = sql_real_escape_string($source_key);

        $existing = sql_fetch("
            SELECT wr_id
            FROM {$target_table}
            WHERE wr_1 = '{$source_key_sql}'
            LIMIT 1
        ");

        if (!empty($existing['wr_id'])) {
            // 이미 처리된 원본이므로 중복 등록하지 않음
            return [
                'status' => 'duplicate',
                'target_wr_id' => (int)$existing['wr_id'],
            ];
        }

        $subject = cut_str($product_name, 250, '');
        $subject_sql = sql_real_escape_string($subject);
        $content_sql = sql_real_escape_string($json);
        $product_code_sql = sql_real_escape_string(
            mb_substr($product_code, 0, 250, 'UTF-8')
        );

        $mb_id = sql_real_escape_string(
            (string)($source['mb_id'] ?? '')
        );

        $wr_name = trim((string)($source['wr_name'] ?? ''));

        if ($wr_name === '') {
            $wr_name = '상품 자동등록';
        }

        $wr_name_sql = sql_real_escape_string(
            mb_substr($wr_name, 0, 255, 'UTF-8')
        );

        $wr_email_sql = sql_real_escape_string(
            (string)($source['wr_email'] ?? '')
        );

        $wr_homepage_sql = sql_real_escape_string(
            (string)($source['wr_homepage'] ?? '')
        );

        $wr_ip_sql = sql_real_escape_string(
            (string)($source['wr_ip'] ?? '')
        );

        $now_sql = sql_real_escape_string(G5_TIME_YMDHIS);

        // GNUboard 게시글 번호 규칙
        $num_row = sql_fetch("
            SELECT IFNULL(MIN(wr_num) - 1, -1) AS wr_num
            FROM {$target_table}
        ");

        $wr_num = (int)($num_row['wr_num'] ?? -1);

        /*
         * 게시글 생성.
         * 원본 JSON을 그대로 보존합니다.
         * wr_1: 원본 게시글 식별자
         * wr_2: 상품 코드
         */
        $insert_sql = "
            INSERT INTO {$target_table}
            SET
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
                wr_ip = '{$wr_ip_sql}',
                wr_1 = '{$source_key_sql}',
                wr_2 = '{$product_code_sql}'
        ";

        if (!sql_query($insert_sql, false)) {
            throw new \RuntimeException(
                'Failed to insert pr_detail post'
            );
        }

        $target_wr_id = (int)sql_insert_id();

        if ($target_wr_id < 1) {
            throw new \RuntimeException(
                'Failed to retrieve target wr_id'
            );
        }

        // 부모 게시글 번호 설정
        sql_query("
            UPDATE {$target_table}
            SET wr_parent = '{$target_wr_id}'
            WHERE wr_id = '{$target_wr_id}'
        ");

        // 새 글 테이블 갱신
        $board_table = sql_real_escape_string('pr_detail');

        sql_query("
            INSERT INTO {$g5['board_new_table']}
                (bo_table, wr_id, wr_parent, bn_datetime, mb_id)
            VALUES (
                '{$board_table}',
                '{$target_wr_id}',
                '{$target_wr_id}',
                '{$now_sql}',
                '{$mb_id}'
            )
        ");

        // 대상 게시판의 글 수 증가
        sql_query("
            UPDATE {$g5['board_table']}
            SET bo_count_write = bo_count_write + 1
            WHERE bo_table = 'pr_detail'
        ");

        // 처리 결과 기록
        error_log(
            '[product_to_detail] created ' .
            'source_wr_id=' . $source_wr_id .
            ' target_wr_id=' . $target_wr_id .
            ' product_code=' . $product_code
        );

        return [
            'status' => 'created',
            'target_wr_id' => $target_wr_id,
        ];
    }
}
