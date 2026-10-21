<?php
/**
 * GNUboard5: product 게시판 -> pr_detail 자동 등록
 * 
 * 파일: /extend/product_to_detail.extend.php
 * 
 * 처리:
 *   - product 게시판의 신규 글 등록 전(before)에 wr_subject(상품코드) 중복 검사
 *   - 중복인 경우 저장을 중단하고 alert 창을 띄우며 처리 안 함
 *   - product 게시판 및 pr_detail 게시판 모두 중복 체크
 */

if (!defined('_GNUBOARD_')) {
    exit;
}

if (!function_exists('product_to_detail_before_write')) {
    // write_update_before 이벤트를 사용하여 글이 저장되기 전에 가로챔 (인자 개수는 그누보드 버전/이벤트에 맞춰 조정)
    add_event('write_update_before', 'product_to_detail_before_write', 10, 4);

    /**
     * product 게시글 저장 전 실행되는 이벤트 콜백
     */
    function product_to_detail_before_write($board, $wr_id, $w, $qstr) {
        if (($board['bo_table'] ?? '') !== 'product') {
            return;
        }

        // 신규 등록($w === '')인 경우에만 중복 체크
        if ($w !== '') {
            return;
        }

        global $g5;

        // POST로 넘어온 wr_subject (고유 상품코드)
        $product_code = trim((string)($_POST['wr_subject'] ?? ''));
        if ($product_code === '') {
            return;
        }

        $source_table = $g5['write_prefix'] . 'product';
        $target_table = $g5['write_prefix'] . 'pr_detail';
        $subject_sql = sql_real_escape_string($product_code);

        // 1. product 게시판 내에 이미 동일한 상품코드(wr_subject)가 존재하는지 확인
        $existing_product = sql_fetch("
            SELECT wr_id 
            FROM {$source_table} 
            WHERE wr_subject = '{$subject_sql}' 
            LIMIT 1
        ");

        if (!empty($existing_product['wr_id'])) {
            // API 형태나 일반 에러 출력을 위한 상태값 설정
            $GLOBALS['product_to_detail_status'] = [
                'status' => 'duplicate',
                'message' => 'Duplicate product code found in product board.',
            ];
            
            // 그누보드 표준 alert 출력 후 중단
            alert('이미 존재하는 상품코드(wr_subject)입니다. (product 게시판 중복)');
        }

        // 2. pr_detail 게시판에 이미 동일한 상품코드가 존재하는지 확인
        $existing_detail = sql_fetch("
            SELECT wr_id 
            FROM {$target_table} 
            WHERE wr_subject = '{$subject_sql}' 
            LIMIT 1
        ");

        if (!empty($existing_detail['wr_id'])) {
            $GLOBALS['product_to_detail_status'] = [
                'status' => 'duplicate',
                'target_wr_id' => (int)$existing_detail['wr_id'],
                'message' => 'Duplicate product code found in pr_detail board.',
            ];

            alert('이미 존재하는 상품코드(wr_subject)입니다. (pr_detail 게시판 중복)');
        }
    }
}
