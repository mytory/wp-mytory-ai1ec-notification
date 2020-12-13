<?php

namespace Mytory\Ai1ecNotification;

use Ai1ec_Date_Time;
use Ai1ec_Event;
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
			'이벤트 알림',
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
		$ai1ec_registry             = $ai1ec_front_controller->return_registry( true );
		$event                      = $ai1ec_registry->get( 'model.event', $post_id );
		$start                      = $event->get( 'start' );
		$start_datetime_for_compare = ( ( $event->is_allday() ) ? $start->format( 'Y-m-d 09:00:00' ) : $start->format( 'Y-m-d H:i:s' ) );
		$notification_time          = strtotime( $start_datetime_for_compare ) - ( 60 * 60 );

		// 일정 시작 한 시간 전으로 예약을 건다. 이렇게 하면 알림 시작 시각이 지났으면 바로 알림이 가게 된다.
		wp_schedule_single_event( $notification_time, 'sendPush', [ $post_id ] );
	}

	/**
	 * 푸시 알림을 보낼 필요가 있는지 판단한다.
	 *
	 * @param WP_Post $post
	 *
	 * @return bool
	 */
	private function needToPush( WP_Post $post ) {
		if ( ! defined( 'FCM_TOKEN' ) ) {
			return false;
		}
		if ( empty( FCM_TOKEN ) ) {
			return false;
		}
		if ( $post->post_status != 'publish' ) {
			return false;
		}
		if ( empty( $_POST['ai1ec_notification']['medium'] ) ) {
			return false;
		}
		if ( $_POST['ai1ec_notification']['medium'] != 'push' ) {
			return false;
		}


		return true;
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
		$ai1ec_registry             = $ai1ec_front_controller->return_registry( true );
		$event                      = $ai1ec_registry->get( 'model.event', $post_id );
		$start                      = $event->get( 'start' );
		$start_datetime_for_compare = ( ( $event->is_allday() ) ? $start->format( 'Y-m-d 09:00:00' ) : $start->format( 'Y-m-d H:i:s' ) );
		$notification_time          = strtotime( $start_datetime_for_compare ) - ( 60 * 60 );

		/*
		 * 일정 시각에 알림을 보낸다.
		 * 일정 시각이 오후 8시에서 오전 8시 사이라면 오후 7시 반에 알림을 보내는 게 좋을 것이다.
		 */
		$hour = $start->format( 'H' );
		if ( $hour < 8 ) {
			// 일정 시각이 자정에서 오전 8시 사이라면
			$time = strtotime( date( 'Y-m-d 19:30:00', strtotime( '-1 day ' . $start_datetime_for_compare ) ) );
		} elseif ( $hour >= 20 ) {
			// 일정 시각이 오후 8시에서 자정 사이라면 보낼 시각은 직전인 오후 7시 반
			$time = strtotime( date( 'Y-m-d 19:30:00', strtotime( $start_datetime_for_compare ) ) );
		} else {
			// 일정 시각이 오전 8시에서 오후 20시 사이라면 $notification_time에 보낸다.
			$time = strtotime( $notification_time );
		}
		update_post_meta( $post_id, '_ai1ec_kakao_notified', '예약' );
		update_post_meta( $post_id, '_ai1ec_kakao_reserved_time', $time );
		wp_schedule_single_event( $time, 'sendKakao', [ $post_id ] );
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
		if ( $_POST['ai1ec_notification']['medium'] != 'kakao' ) {
			return false;
		}

		return true;
	}

	public function sendKakao( $post_id ) {

		$post = get_post( $post_id );

		$contact_query = new WP_Query( [
			'post_type'      => 'mytory_contact',
			'posts_per_page' => - 1,
			'post_status'    => 'any',
		] );

		$success_count = 0;
		$fail_count    = 0;

		foreach ( $contact_query->posts as $contact ) {
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
		if ( ! empty( get_post_meta( $post_id, '_ai1ec_kakao_reserved_time' ) ) ) {
			delete_post_meta( $post_id, '_ai1ec_kakao_reserved_time' );
		}
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

		$fcm      = new FCMSimple( FCM_TOKEN );
		$response = $fcm->sendToTopic(
			'/topics/notice',
			$data,
			[
				'title' => $title,
				'body'  => $data['body'],
				'sound' => 'default',
			]
		);
		update_post_meta( $post_id, '_ai1ec_push_notified', true );
		update_post_meta( $post_id, '_ai1ec_push_datetime', 'YmdHis' );
		update_post_meta( $post_id, '_ai1ec_push_response', $response );
	}
}

