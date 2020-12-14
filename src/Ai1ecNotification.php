<?php

namespace Mytory\Ai1ecNotification;

use Ai1ec_Date_Time;
use Ai1ec_Event;
use Carbon\Carbon;
use FCMSimple;
use WP_Error;
use WP_Post;
use WP_Query;

class Ai1ecNotification {
	public function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'registerMetabox' ] );
		add_action( 'save_post', [ $this, 'pushSaveHook' ], 10, 3 );
		add_action( 'save_post', [ $this, 'kakaoSaveHook' ], 10, 3 );
		add_action( 'mytory_ai1ec_send_push', [ $this, 'sendPush' ] );
		add_action( 'mytory_ae1ec_send_kkao', [ $this, 'sendKakao' ] );
	}

	public function registerMetabox() {
		add_meta_box(
			'ai1ec-notification',
			'일정 알림',
			[ $this, 'metaboxHtml' ],
			[ 'ai1ec_event' ]
		);
	}

	public function metaboxHtml() {
		include 'metabox.php';
	}

	public function pushSaveHook( $post_id, $post, $is_update ) {
		/**
		 * @var Ai1ec_Event $event
		 * @var Ai1ec_Date_Time $start
		 */

		if ( ! $this->needToPush( $post ) ) {
			return;
		}

		global $ai1ec_front_controller;

		date_default_timezone_set( wp_timezone()->getName() );
		$ai1ec_registry = $ai1ec_front_controller->return_registry( true );
		$event          = $ai1ec_registry->get( 'model.event', $post_id );
		$start_carbon   = Carbon::createFromFormat( 'Y-m-d H:i:s', $event->get( 'start' )->format( 'Y-m-d H:i:s' ), wp_timezone()->getName() );

		// 기본 알림 시각은 시작 시각 1시간 전
		$notification_time = $start_carbon->clone()->subHour();
		$now               = Carbon::now();

		if ( $event->is_allday() and $start_carbon->isSameDay( $now ) ) {
			// 하루종일 일정이고 같은 날이면 바로 보낸다.
			$notification_time = $now->addMinute();
		}

		if ( $event->is_allday() and $start_carbon->gt( $now ) ) {
			// 하루종일 일정이고 지금보다 뒤면 당일 오전 8시에 알림을 보내다.
			$notification_time = $start_carbon->clone()->setHour( 8 )->setMinute( 0 )->setSecond( 0 );
		}

		if ( ! $event->is_allday() and $start_carbon->gt( $now ) and $notification_time->lt( $now ) ) {
			// 하루종일 일정이 아니고 시작 시각이 현재 시각보다 뒤지만, 알림 시각은 지난 경우라면 바로 알림을 보낸다.
			$notification_time = $now->addMinute();
		}

		$response = self::canPushNotification( $event->is_allday(), $start_carbon );
		if ( ! $response['result'] ) {
			return;
		}

		update_post_meta( $post_id, '_ai1ec_push_notified', '예약' );
		update_post_meta( $post_id, '_ai1ec_push_reserved_time', $notification_time->format( 'U' ) );

		// 일정 시작 한 시간 전으로 예약을 건다. 이렇게 하면 알림 시작 시각이 지났으면 바로 알림이 가게 된다.
		wp_schedule_single_event( $notification_time->format( 'U' ), 'sendPush', [ $post_id ] );
	}

	/**
	 * 푸시 알림을 보낼 필요가 있는지 판단한다.
	 *
	 * @param WP_Post $post
	 *
	 * @return bool
	 */
	private function needToPush( WP_Post $post ) {
		if ( ! defined( 'FCM_TOKEN' ) or empty( FCM_TOKEN ) ) {
			return false;
		}
		if ( $post->post_status != 'publish' ) {
			return false;
		}
		if ( empty( $_POST['ai1ec_notification']['medium'] ) ) {
			return false;
		}
		if ( ! in_array( 'push', $_POST['ai1ec_notification']['medium'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @param bool $is_allday
	 * @param Carbon $start_carbon
	 *
	 * @return array [ 'result': 결과. true/false, 'message': result가 false인 경우 이유. ]
	 */
	public static function canPushNotification( bool $is_allday, Carbon $start_carbon ): array {
		date_default_timezone_set( wp_timezone()->getName() );

		$now = Carbon::now();

		if ( $is_allday and $start_carbon->format( 'Y-m-d' ) < $now->format( 'Y-m-d' ) ) {
			return [
				'result'  => false,
				'message' => '날짜가 지났습니다.',
			];
		}

		if ( ! $is_allday and $start_carbon->lt( $now ) ) {
			return [
				'result'  => false,
				'message' => '일정 시작 시각이 지났습니다.',
			];
		}

		$push_notified = get_post_meta( get_the_ID(), '_ai1ec_push_notified', true );
		if ( $push_notified == '예약' ) {
			$push_reserved_time = get_post_meta( get_the_ID(), '_ai1ec_push_reserved_time', true );
			$reserved_carbon    = Carbon::createFromFormat( 'U', $push_reserved_time, wp_timezone()->getName() );
			$reserved_carbon->setTimezone( wp_timezone()->getName() );

			return [
				'result'  => false,
				'message' => $reserved_carbon->format( 'n월 j일 G시 i분 발송으로 예약되었습니다.' ),
			];
		}

		return [
			'result'  => true,
			'message' => '',
		];
	}

	public function kakaoSaveHook( $post_id, $post, $is_update ) {
		/**
		 * @var Ai1ec_Event $event
		 * @var Ai1ec_Date_Time $start
		 */
		if ( ! $this->needToKakao( $post ) ) {
			return;
		}
		global $ai1ec_front_controller;

		date_default_timezone_set( wp_timezone()->getName() );
		$ai1ec_registry = $ai1ec_front_controller->return_registry( true );
		$event          = $ai1ec_registry->get( 'model.event', $post_id );
		$start_carbon   = Carbon::createFromFormat( 'Y-m-d H:i:s', $event->get( 'start' )->format( 'Y-m-d H:i:s' ), wp_timezone()->getName() );

		// 기본 알림 시각은 시작 시각 1시간 전
		$notification_time = $start_carbon->clone()->subHour();
		$now               = Carbon::now();

		$response = self::canKakaoNotification( $event->is_allday(), $start_carbon );
		if ( ! $response['result'] ) {
			return;
		}

		if ( $event->is_allday() and $start_carbon->isSameDay( $now ) and $start_carbon->format( 'H' ) < 20 ) {
			// 하루종일 일정이고 같은 날이고 오후 8시 전이면 바로 보낸다.
			$notification_time = $now->addMinute();
		}

		if ( $event->is_allday() and $start_carbon->gt( $now ) ) {
			// 하루종일 일정이고 지금보다 뒤면 당일 오전 8시에 알림을 보내다.
			$notification_time = $start_carbon->clone()->setHour( 8 )->setMinute( 0 )->setSecond( 0 );
		}

		if ( ! $event->is_allday() and $start_carbon->format( 'Y-m-d' ) > $now->format( 'Y-m-d' ) ) {
			// 하루종일 일정이 아니고 시작 날짜가 내일 이후인 경우
			if ( $notification_time->format( 'H' ) >= 20 ) {
				// 시작 시각 한 시간 전인 알림 시각이 오후 8시 이후가 된 경우에는 알림 시각을 강제로 오후 8시로 조정한다.
				$notification_time->setHour( 20 )->setMinute( 0 )->setSecond( 0 );
			} elseif ( $notification_time->format( 'H' ) < 8 ) {
				// 시작 시각 한 시간 전인 알림 시각이 오전 8시 이전인 경우에는 알림 시각을 강제로 오전 8시로 조정한다.
				$notification_time->setHour( 8 )->setMinute( 0 )->setSecond( 0 );
			}
			// 그 밖의 경우에는 현재 알림 시각을 유지한다.
		}

		if ( ! $event->is_allday() and $start_carbon->gt( $now ) and $notification_time->lt( $now ) ) {
			// 하루종일 일정이 아니고 시작 시각이 현재 시각보다 뒤지만, 알림 시각은 지난 경우라면 바로 알림을 보낸다.
			$notification_time = $now->addMinute();
		}

		update_post_meta( $post_id, '_ai1ec_kakao_notified', '예약' );
		update_post_meta( $post_id, '_ai1ec_kakao_reserved_time', $notification_time->format( 'U' ) );
		wp_schedule_single_event( $notification_time->format( 'U' ), 'sendKakao', [ $post_id ] );
	}

	/**
	 * 푸시 알림을 보낼 필요가 있는지 판단한다.
	 *
	 * @param WP_Post $post
	 *
	 * @return bool
	 */
	private function needToKakao( WP_Post $post ) {
		if ( ! defined( 'KAKAO_SENDERKEY' ) or empty( KAKAO_SENDERKEY ) ) {
			return false;
		}
		if ( ! defined( 'KAKAO_FROM' ) or empty( KAKAO_FROM ) ) {
			return false;
		}
		if ( ! defined( 'KAKAO_ACCOUNT' ) or empty( KAKAO_ACCOUNT ) ) {
			return false;
		}
		if ( $post->post_status != 'publish' ) {
			return false;
		}
		if ( empty( $_POST['ai1ec_notification']['medium'] ) ) {
			return false;
		}
		if ( ! in_array( 'kakao', $_POST['ai1ec_notification']['medium'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @param bool $is_allday
	 * @param Carbon $start_carbon
	 *
	 * @return array [ 'result': 결과. true/false, 'message': result가 false인 경우 이유. ]
	 */
	public static function canKakaoNotification( bool $is_allday, Carbon $start_carbon ): array {
		date_default_timezone_set( wp_timezone()->getName() );

		$now = Carbon::now();
		if ( $is_allday and $start_carbon->format( 'Y-m-d' ) < $now->format( 'Y-m-d' ) ) {
			return [
				'result'  => false,
				'message' => '날짜가 지났습니다.',
			];
		}

		if ( $now->format( 'H' ) >= 20 and $start_carbon->isSameDay( $now ) ) {
			return [
				'result'  => false,
				'message' => '카카오톡은 오후 8시 이후에 알림을 보낼 수 없습니다.',
			];
		}

		if ( ! $is_allday and $start_carbon->lt( $now ) ) {
			return [
				'result'  => false,
				'message' => '일정 시작 시각이 지났습니다.',
			];
		}

		if ( $start_carbon->gt( $now ) and $start_carbon->format( 'H' ) < 8 and $start_carbon->clone()->subDay()->setHour( 20 )->setMinute( 0 )->setSecond( 0 )->lt( $now ) ) {
			// 시작 시각이 현재보다 뒤고, 시작 시각이 오전 8시 전이고, 시작 시각 하루 전날 오후 8시가 이미 지났다면 알림을 보내지 않는다.
			return [
				'result'  => false,
				'message' => '카카오톡은 오전 8시 전에 알림을 보낼 수 없습니다.',
			];
		}

		$kakao_notified = get_post_meta( get_the_ID(), '_ai1ec_kakao_notified', true );
		if ( $kakao_notified == '예약' ) {
			$kakao_reserved_time = get_post_meta( get_the_ID(), '_ai1ec_kakao_reserved_time', true );
			$reserved_carbon     = Carbon::createFromFormat( 'U', $kakao_reserved_time, wp_timezone()->getName() );
			$reserved_carbon->setTimezone( wp_timezone()->getName() );

			return [
				'result'  => false,
				'message' => $reserved_carbon->format( 'n월 j일 G시 i분 발송으로 예약되었습니다.' ),
			];
		}

		return [
			'result'  => true,
			'message' => '',
		];
	}

	public function sendKakao( $post_id ) {

		$post = get_post( $post_id );

		$contacts = [];

		if ( defined( 'NOTIFICATION_TEST' ) and NOTIFICATION_TEST and defined( 'FCM_TEST_TARGET' ) and FCM_TEST_TARGET ) {
			// 테스트 모드
			$contact_query = new WP_Query( [
				'post_type'      => 'mytory_contact',
				'posts_per_page' => - 1,
				'post_status'    => 'any',
				'meta_query'     => [
					[
						'key'   => 'phone',
						'value' => '01044533153',
					]
				]
			] );
			$contacts      = $contact_query->posts;
		}

		if ( defined( 'NOTIFICATION_TEST' ) and NOTIFICATION_TEST === false ) {
			// NOTIFICATION_TEST가 설정돼 있고 false여야 실제 알림을 보낸다.
			$contact_query = new WP_Query( [
				'post_type'      => 'mytory_contact',
				'posts_per_page' => - 1,
				'post_status'    => 'any',
			] );
			$contacts      = $contact_query->posts;
		}

		$success_count = 0;
		$fail_count    = 0;

		foreach ( $contacts as $contact ) {
			$body_php = [
				'account' => KAKAO_ACCOUNT,
				'refkey'  => 'koreamsc_' . $post_id,
				'type'    => 'ft',
				'from'    => KAKAO_FROM,
				'to'      => get_post_meta( $contact->ID, 'phone', true ),
				'content' => [
					'ft' => [
						'senderkey' => KAKAO_SENDERKEY,
						'message'   => html_entity_decode( get_the_title( $post ) ) . "\n\n" . html_entity_decode( get_the_excerpt( $post ) ),
						'adflag'    => 'N',
						'button'    => [
							[
								'name'       => '바로 가기',
								'type'       => 'WL',
								'url_pc'     => get_permalink( $post ),
								'url_mobile' => get_permalink( $post ),
							],
						],
					],
				],
			];

			$body     = json_encode( $body_php );
			$url      = 'https://api.bizppurio.com/v2/message';
			$response = wp_remote_post( $url, [
				'body'    => $body,
				'headers' => [
					'Content-Type' => 'application/json',
				]
			] );

			if ( is_wp_error( $response ) ) {
				/**
				 * @var WP_Error $response
				 */
				$wp_error = $response;
				add_post_meta( $contact->ID, '_ai1ec_kakao_' . $post_id, $wp_error );
				$fail_count ++;
			} else {
				/**
				 * $response['body'] is the json string has code, desctiption keys.
				 * @var array $response
				 */
				add_post_meta( $contact->ID, '_ai1ec_kakao_' . $post_id, $response['body'] );
				if ( $response['response']['code'] == 200 ) {
					$success_count ++;
				} else {
					$fail_count ++;
				}
			}
		}

		update_post_meta( $post_id, '_ai1ec_kakao_notified', '1' );
		update_post_meta( $post_id, '_ai1ec_kakao_datetime', date( 'YmdHis' ) );
		update_post_meta( $post_id, '_ai1ec_kakao_result', "{$success_count}건 전송 완료" . ( $fail_count ? ", {$fail_count}건 전송 실패" : '' ) );
	}

	public function sendPush( $post_id ) {
		$post = get_post( $post_id );
		// 알림을 보낸다
		$title = '일정 알림';
		$data  = array(
			'title' => $title,
			'body'  => html_entity_decode( get_the_title( $post ) ),
			'url'   => get_permalink( $post ),
		);

		$fcm = new FCMSimple( FCM_TOKEN );

		if ( defined( 'NOTIFICATION_TEST' ) and NOTIFICATION_TEST and defined( 'FCM_TEST_TARGET' ) and FCM_TEST_TARGET ) {
			// 테스트 모드
			$fcm->setTokens( [ FCM_TEST_TARGET ] );
			$response = $fcm->sendToUsers( $data );
		}

		if ( defined( 'NOTIFICATION_TEST' ) and NOTIFICATION_TEST === false ) {
			// NOTIFICATION_TEST가 false로 돼 있어야 실제 알림을 보낸다.
			$response = $fcm->sendToTopic(
				'/topics/notice',
				$data,
				[
					'title' => $title,
					'body'  => $data['body'],
					'sound' => 'default',
				]
			);
		}

		update_post_meta( $post_id, '_ai1ec_push_notified', true );
		update_post_meta( $post_id, '_ai1ec_push_datetime', 'YmdHis' );
		update_post_meta( $post_id, '_ai1ec_push_response', $response );
	}
}

