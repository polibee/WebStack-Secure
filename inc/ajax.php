<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

//图片上传
// 安全修复(2026-11-26)：原代码对未登录用户开放上传，且 $extArr 定义后从未使用，
// 导致任意扩展名（含 .php/.phtml/.phar）可直达 uploads 目录 —— 这是旧站被植入
// webshell 的入口。修复点：①去掉 nopriv 钩子，要求登录；②加 nonce 校验；
// ③按扩展名白名单 + 真实图片类型双重验证；④显式拒绝 uploads 目录内的可执行后缀。
add_action('wp_ajax_img_upload', 'io_img_upload');
function io_img_upload(){  
	$extArr = array("jpg", "png", "jpeg", "gif", "webp");
	if ( ! is_user_logged_in() || ! current_user_can( 'upload_files' ) ) {
		status_header( 403 );
		echo json_encode( array( 'status' => 0, 'msg' => __( '无权上传', 'i_theme' ) ) );
		exit;
	}
	check_ajax_referer( 'io_img_upload', 'nonce' );
	$file = isset( $_FILES['files'] ) ? $_FILES['files'] : null;
	if ( !empty( $file ) ) {
	    $wp_upload_dir = wp_upload_dir();                                     // 获取上传目录信息
	    $basename = $file['name'];
	    $baseext = strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) );
	    // 扩展名白名单
	    if ( ! in_array( $baseext, $extArr, true ) ) {
	        status_header( 400 );
	        echo json_encode( array( 'status' => 0, 'msg' => __( '只允许上传图片', 'i_theme' ) ) );
	        exit;
	    }
	    // 真实图片类型校验：文件头必须是图片，防止双扩展名 / 伪造 mime
	    $imginfo = @getimagesize( $file['tmp_name'] );
	    if ( false === $imginfo || empty( $imginfo['mime'] ) || 0 !== strpos( $imginfo['mime'], 'image/' ) ) {
	        status_header( 400 );
	        echo json_encode( array( 'status' => 0, 'msg' => __( '文件不是有效图片', 'i_theme' ) ) );
	        exit;
	    }
	    $dataname = date("YmdHis_").substr(md5(time()), 0, 8) . '.' . $baseext;
	    $filename = $wp_upload_dir['path'] . '/' . $dataname;
	    // 用 move_uploaded_file 而非 rename，确保只搬运真正经 HTTP 上传的临时文件
	    if ( ! @move_uploaded_file( $file['tmp_name'], $filename ) ) {
	        status_header( 500 );
	        echo json_encode( array( 'status' => 0, 'msg' => __( '保存失败', 'i_theme' ) ) );
	        exit;
	    }
	    $attachment = array(
	        'guid'           => $wp_upload_dir['url'] . '/' . $dataname,      // 外部链接的 url
	        'post_mime_type' => $file['type'],                                // 文件 mime 类型
	        'post_title'     => preg_replace( '/\.[^.]+$/', '', $basename ),  // 附件标题，采用去除扩展名之后的文件名
	        'post_content'   => '',                                           // 文章内容，留空
	        'post_status'    => 'inherit'
	    );
	    $attach_id = wp_insert_attachment( $attachment, $filename );          // 插入附件信息
	    if($attach_id != 0){
	        require_once( ABSPATH . 'wp-admin/includes/image.php' );          // 确保包含此文件，因为wp_generate_attachment_metadata（）依赖于此文件。
	        $attach_data = wp_generate_attachment_metadata( $attach_id, $filename );
	        wp_update_attachment_metadata( $attach_id, $attach_data );        // 生成附件的元数据，并更新数据库记录。
	        print_r(json_encode(array('status'=>1,'msg'=>__('图片添加成功','i_theme'),'data'=>array('id'=>$attach_id,'src'=>wp_get_attachment_url( $attach_id ),'title'=>$basename))));
	        exit();
	    }else{
	        echo '{"status":4,"msg":"'.__('图片上传失败！','i_theme').'"}';
	        exit();
	    }
	} 
}

//删除图片
// 安全修复(2026-11-26)：原代码 nopriv + 无归属校验，任何人可删任意附件。
add_action('wp_ajax_img_remove', 'io_img_remove');
function io_img_remove(){    
	if ( ! is_user_logged_in() || ! current_user_can( 'delete_posts' ) ) {
		status_header( 403 );
		echo json_encode( array( 'status' => 0, 'msg' => __( '无权删除', 'i_theme' ) ) );
		exit;
	}
	check_ajax_referer( 'io_img_remove', 'nonce' );
	$attach_id = absint( isset( $_POST['id'] ) ? $_POST['id'] : 0 );
	if( empty( $attach_id ) ){
		echo '{"status":3,"msg":"'.__('没有上传图像！','i_theme').'"}';
		exit;
	}
	// 只能删自己上传的附件，管理员除外
	$att = get_post( $attach_id );
	if ( ! $att || 'attachment' !== $att->post_type ) {
		echo '{"status":3,"msg":"'.__('附件不存在','i_theme').'"}';
		exit;
	}
	if ( (int) $att->post_author !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
		status_header( 403 );
		echo json_encode( array( 'status' => 0, 'msg' => __( '只能删除自己上传的图片', 'i_theme' ) ) );
		exit;
	}
	if ( false === wp_delete_attachment( $attach_id ) )
		echo '{"status":4,"msg":"'.sprintf(__('图片 %s 删除失败！','i_theme'), $attach_id).'"}';
	else
		echo '{"status":1,"msg":"'.__('删除成功！','i_theme').'"}';
	exit; 
}

//提交文章
add_action('wp_ajax_nopriv_contribute_post', 'io_contribute');  
add_action('wp_ajax_contribute_post', 'io_contribute');
function io_contribute(){  
	$delay = 40; 
	if( isset($_COOKIE["tougao"]) && ( time() - $_COOKIE["tougao"] ) < $delay ){
		error('{"status":2,"msg":"'.sprintf(__('您投稿也太勤快了吧，%s秒后再试！','i_theme'), ($delay - ( time() - $_COOKIE["tougao"] )) ).'"}');
	} 
	
	//表单变量初始化
	$sites_link = isset( $_POST['tougao_sites_link'] ) ? trim(htmlspecialchars($_POST['tougao_sites_link'], ENT_QUOTES)) : '';
	$sites_sescribe = isset( $_POST['tougao_sites_sescribe'] ) ? trim(htmlspecialchars($_POST['tougao_sites_sescribe'], ENT_QUOTES)) : '';
	$title = isset( $_POST['tougao_title'] ) ? trim(htmlspecialchars($_POST['tougao_title'], ENT_QUOTES)) : '';
	$category = isset( $_POST['tougao_cat'] ) ? $_POST['tougao_cat'] : '0';
	$sites_ico = isset( $_POST['tougao_sites_ico'] ) ? trim(esc_url_raw($_POST['tougao_sites_ico'])) : '';
	$wechat_qr = isset( $_POST['tougao_wechat_qr'] ) ? trim(esc_url_raw($_POST['tougao_wechat_qr'])) : '';
	$content = isset( $_POST['tougao_content'] ) ? trim(htmlspecialchars($_POST['tougao_content'], ENT_QUOTES)) : '';
	
	// 安全修复(2026-11-26)：category 原样取自 POST 未做整数校验，可注入任意分类 ID；
	// sites_ico / wechat_qr 也未限制协议，javascript: 之流能存进数据库后被前端直接输出。
	$category = absint( $category );
	if ( ! $category ) {
		error('{"status":4,"msg":"'.__('请选择分类。','i_theme').'"}');
	}
	// 只接受 http(s)，挡住 javascript:/data: 等伪协议
	foreach ( array( 'sites_ico' => $sites_ico, 'wechat_qr' => $wechat_qr ) as $k => $v ) {
		if ( '' !== $v && ! preg_match( '#^https?://#i', $v ) ) {
			error('{"status":4,"msg":"'.__('链接必须以 http:// 或 https:// 开头。','i_theme').'"}');
		}
	}
	
	// 表单项数据验证
	if ( $category == "0" ){
		error('{"status":4,"msg":"'.__('请选择分类。','i_theme').'"}');
	}
	if ( !empty(get_term_children($category, 'favorites'))){
		error('{"status":4,"msg":"'.__('不能选用父级分类目录。','i_theme').'"}');
	}
	if ( empty($sites_sescribe) || mb_strlen($sites_sescribe) > 50 ) {
		error('{"status":4,"msg":"'.__('网站描叙必须填写，且长度不得超过50字。','i_theme').'"}');
	}
	if ( empty($sites_link) && empty($wechat_qr) ){
		error('{"status":3,"msg":"'.__('网站链接和公众号二维码至少填一项。','i_theme').'"}');
	}
	elseif ( !empty($sites_link) && !preg_match('/http(s)?:\/\/[\w.]+[\w\/]*[\w.]*\??[\w=&\+\%]*/is', $sites_link)) {
		error('{"status":4,"msg":"'.__('网站链接必须符合URL格式。','i_theme').'"}');
	}
	if ( empty($title) || mb_strlen($title) > 30 ) {
		error('{"status":4,"msg":"'.__('网站名称必须填写，且长度不得超过30字。','i_theme').'"}');
	}
	//if ( empty($content) || mb_strlen($content) > 10000 || mb_strlen($content) < 6) {
	//	error('{"status":4,"msg":"内容必须填写，且长度不得超过10000字，不得少于6字。"}');
	//}
	
	$tougao = array(
		'comment_status'   => 'closed',
		'ping_status'      => 'closed',
		//'post_author'      => 1,//用于投稿的用户ID
		'post_title'       => $title,
		'post_content'     => $content,
		'post_status'      => 'pending',
		'post_type'        => 'sites',
		//'tax_input'        => array( 'favorites' => array($category) ) //游客不可用
	);
	
	// 将文章插入数据库
	$status = wp_insert_post( $tougao );
	if ($status != 0){
		global $wpdb;
		add_post_meta($status, '_sites_sescribe', $sites_sescribe);
		add_post_meta($status, '_sites_link', $sites_link);
		add_post_meta($status, '_sites_order', '0');
		if( !empty($sites_ico))
			add_post_meta($status, '_thumbnail', $sites_ico); 
		if( !empty($wechat_qr))
			add_post_meta($status, '_wechat_qr', $wechat_qr); 
		wp_set_post_terms( $status, array($category), 'favorites'); //设置文章分类
		setcookie("tougao", time(), time()+$delay+10);
		error('{"status":1,"msg":"'.__('投稿成功！','i_theme').'"}');
	}else{
		error('{"status":4,"msg":"'.__('投稿失败！','i_theme').'"}');
	}
}
function error($ErrMsg) {
	echo $ErrMsg;
	exit;
} 
