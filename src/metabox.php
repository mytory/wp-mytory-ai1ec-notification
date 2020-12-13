<?php
$push_response       = json_decode( get_post_meta( get_the_ID(), '_ai1ec_push_response', true ), true );
$push_notified       = get_post_meta( get_the_ID(), '_ai1ec_push_notified', true );
$push_reserved_time  = get_post_meta( get_the_ID(), '_ai1ec_push_reserved_time', true );
$kakao_result        = get_post_meta( get_the_ID(), '_ai1ec_kakao_result', true );
$kakao_notified      = get_post_meta( get_the_ID(), '_ai1ec_kakao_notified', true );
$kakao_reserved_time = get_post_meta( get_the_ID(), '_ai1ec_kakao_reserved_time', true );
date_default_timezone_set( 'Asia/Seoul' );
?>

<p>
  <label style="margin-right: .5rem;">
	  <?php if ( $push_notified == '예약' ) { ?>
        <input type="checkbox" disabled> 푸시 알림:
		  <?php echo( !empty($push_reserved_time) ? date( 'n월 j일 G시 발송으로 예약되었습니다.', $push_reserved_time ) : '알림 예약되었습니다.' ); ?>
	  <?php } else { ?>
        <input type="checkbox" name="ai1ec_notification[medium]" value="push">
		  <?php if ( $push_notified ) {
			  if ( ! empty( $push_response['message_id'] ) ) {
				  ?>
                푸시 알림 <b>다시 보내기</b> <small>(전송 성공)</small>
				  <?php
			  } else {
				  ?>
                푸시 알림 <b>다시 보내기</b> <small>(전송 실패)</small>
				  <?php
			  }
		  } else { ?>
          푸시 알림
		  <?php } ?>
	  <?php } ?>
  </label>

	<?php if ( $kakao_notified == '예약' ) { ?>
      <input type="checkbox" disabled> 카카오:
		<?php echo( $kakao_reserved_time ? date( 'n월 j일 G시 발송으로 예약되었습니다.', $kakao_reserved_time ) : '오전 8시로 알림 예약되었습니다.' ); ?>
	<?php } else { ?>
      <label>
        <input type="checkbox" name="ai1ec_notification[medium]" value="kakao">
		  <?php if ( $kakao_notified == '1' ) { ?>
            카카오 <b>다시 보내기</b> <small>(<?php echo $kakao_result ?>)</small>
		  <?php } else { ?>
            카카오
		  <?php } ?>
      </label>
	<?php } ?>
</p>
