<?php

use Carbon\Carbon;
use Mytory\Ai1ecNotification\Ai1ecNotification;

$push_response       = json_decode( get_post_meta( get_the_ID(), '_ai1ec_push_response', true ), true );
$push_notified       = get_post_meta( get_the_ID(), '_ai1ec_push_notified', true );
$push_reserved_time  = get_post_meta( get_the_ID(), '_ai1ec_push_reserved_time', true );
$kakao_result        = get_post_meta( get_the_ID(), '_ai1ec_kakao_result', true );
$kakao_notified      = get_post_meta( get_the_ID(), '_ai1ec_kakao_notified', true );
$kakao_reserved_time = get_post_meta( get_the_ID(), '_ai1ec_kakao_reserved_time', true );

global $ai1ec_front_controller;

try {
	/**
	 * @var Ai1ec_Event $event
	 * @var Ai1ec_Date_Time $start
	 */
	$ai1ec_registry         = $ai1ec_front_controller->return_registry( true );
	$event                  = $ai1ec_registry->get( 'model.event', get_the_ID() );
	$start_carbon           = Carbon::createFromFormat( 'Y-m-d H:i:s', $event->get( 'start' )->format( 'Y-m-d H:i:s' ), wp_timezone()->getName() );
	$can_kakao_notification = Ai1ecNotification::canKakaoNotification( $event->is_allday(), $start_carbon );
	$can_push_notification  = Ai1ecNotification::canPushNotification( $event->is_allday(), $start_carbon );
} catch ( \Exception $e ) {

}
?>

<p>
  <label style="margin-right: .5rem;">
	  <?php if ( ! empty( $can_push_notification ) and ! $can_push_notification['result'] ) { ?>
        <input type="checkbox" disabled> 푸시 알림:
		  <?php echo $can_push_notification['message']; ?>
	  <?php } else { ?>
        <input type="checkbox" name="ai1ec_notification[medium][]" value="push">
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

	<?php
	// disabled인 경우. 예약이 이미 된 경우, 시작 시간이 지난 경우.
	if ( ! empty( $can_kakao_notification ) and ! $can_kakao_notification['result'] ) { ?>
      <input type="checkbox" disabled> 카카오:
		<?php echo $can_kakao_notification['message']; ?>
	<?php } else { ?>
      <label>
        <input type="checkbox" name="ai1ec_notification[medium][]" value="kakao">
		  <?php if ( $kakao_notified == '1' ) { ?>
            카카오 <b>다시 보내기</b> <small>(<?php echo $kakao_result ?>)</small>
		  <?php } else { ?>
            카카오
		  <?php } ?>
      </label>
	<?php } ?>
</p>
