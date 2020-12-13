<?php

namespace Mytory\Ai1ecNotification;


class Ai1ecNotification {
	public function __construct() {
		if ( empty( FCM_TOKEN ) ) {
			return;
		}
		add_action( 'add_meta_boxes', [ $this, 'registerMetabox' ] );
		add_action( 'save_post', [ $this, 'save' ] );
		add_action( 'send', [ $this, 'send' ] );
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

	public function save() {

	}

	public function reserve() {

	}

	public function send() {

	}

	public function needToNotify() {

	}
}

