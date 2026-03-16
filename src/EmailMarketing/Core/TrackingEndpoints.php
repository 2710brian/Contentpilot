<?php

namespace AEBG\EmailMarketing\Core;

use AEBG\EmailMarketing\Utils\OpenTracker;
use AEBG\EmailMarketing\Utils\ClickTracker;
use AEBG\EmailMarketing\Utils\OptInManager;
use AEBG\EmailMarketing\Utils\UnsubscribeManager;
use AEBG\EmailMarketing\Repositories\TrackingRepository;
use AEBG\EmailMarketing\Repositories\SubscriberRepository;
use AEBG\EmailMarketing\Repositories\QueueRepository;

/**
 * Tracking Endpoints
 * 
 * Handles email tracking endpoints (opens, clicks, opt-in, unsubscribe)
 * 
 * @package AEBG\EmailMarketing\Core
 */
class TrackingEndpoints {
	/**
	 * @var OpenTracker
	 */
	private $open_tracker;
	
	/**
	 * @var ClickTracker
	 */
	private $click_tracker;
	
	/**
	 * @var OptInManager
	 */
	private $opt_in_manager;
	
	/**
	 * @var UnsubscribeManager
	 */
	private $unsubscribe_manager;
	
	/**
	 * Constructor
	 */
	public function __construct() {
		$tracking_repository = new TrackingRepository();
		$subscriber_repository = new SubscriberRepository();
		
		$this->open_tracker = new OpenTracker( $tracking_repository );
		$this->click_tracker = new ClickTracker( $tracking_repository );
		$this->opt_in_manager = new OptInManager( $subscriber_repository );
		$this->unsubscribe_manager = new UnsubscribeManager( $subscriber_repository, $tracking_repository );
		
		$this->register_endpoints();
	}
	
	/**
	 * Register tracking endpoints
	 * 
	 * @return void
	 */
	private function register_endpoints() {
		// Email tracking (opens, clicks)
		add_action( 'init', [$this, 'handle_tracking_request'] );
		
		// Opt-in confirmation
		add_action( 'init', [$this, 'handle_opt_in_confirmation'] );
		
		// Unsubscribe
		add_action( 'init', [$this, 'handle_unsubscribe'] );
	}
	
	/**
	 * Handle tracking request
	 * 
	 * @return void
	 */
	public function handle_tracking_request() {
		if ( ! isset( $_GET['aebg_track'] ) ) {
			return;
		}
		
		$track_type = sanitize_text_field( $_GET['aebg_track'] );
		$queue_id = isset( $_GET['queue_id'] ) ? (int) $_GET['queue_id'] : 0;
		
		if ( ! $queue_id ) {
			return;
		}
		
		if ( $track_type === 'open' ) {
			$token = isset( $_GET['token'] ) ? sanitize_text_field( $_GET['token'] ) : '';
			$this->open_tracker->track_open( $queue_id, $token );
		} elseif ( $track_type === 'click' ) {
			$url = isset( $_GET['url'] ) ? urldecode( sanitize_text_field( $_GET['url'] ) ) : '';
			$token = isset( $_GET['token'] ) ? sanitize_text_field( $_GET['token'] ) : '';
			$this->click_tracker->track_click( $queue_id, $url, $token );
		}
	}
	
	/**
	 * Handle opt-in confirmation
	 * 
	 * @return void
	 */
	public function handle_opt_in_confirmation() {
		if ( ! isset( $_GET['aebg_confirm'] ) ) {
			return;
		}
		
		$token = sanitize_text_field( $_GET['aebg_confirm'] );
		$email = isset( $_GET['email'] ) ? sanitize_email( urldecode( $_GET['email'] ) ) : '';
		
		if ( ! $token || ! $email ) {
			wp_die( __( 'Invalid confirmation link', 'aebg' ), __( 'Error', 'aebg' ), ['response' => 400] );
		}
		
		$result = $this->opt_in_manager->confirm_opt_in( $token, $email );
		
		if ( is_wp_error( $result ) ) {
			wp_die( $result->get_error_message(), __( 'Error', 'aebg' ), ['response' => 400] );
		}
		
		// Show success page
		$this->render_confirmation_page( true );
		exit;
	}
	
	/**
	 * Handle unsubscribe
	 * 
	 * @return void
	 */
	public function handle_unsubscribe() {
		if ( ! isset( $_GET['aebg_unsubscribe'] ) ) {
			return;
		}
		
		$token = sanitize_text_field( $_GET['aebg_unsubscribe'] );
		$email = isset( $_GET['email'] ) ? sanitize_email( urldecode( $_GET['email'] ) ) : '';
		
		if ( ! $token || ! $email ) {
			wp_die( __( 'Invalid unsubscribe link', 'aebg' ), __( 'Error', 'aebg' ), ['response' => 400] );
		}
		
		$result = $this->unsubscribe_manager->process_unsubscribe( $token, $email );
		
		if ( is_wp_error( $result ) ) {
			wp_die( $result->get_error_message(), __( 'Error', 'aebg' ), ['response' => 400] );
		}
		
		// Show success page
		$this->render_unsubscribe_page( true );
		exit;
	}
	
	/**
	 * Render confirmation page
	 * 
	 * @param bool $success Whether confirmation was successful
	 * @return void
	 */
	private function render_confirmation_page( $success = false ) {
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title><?php esc_html_e( 'Email Confirmation', 'aebg' ); ?></title>
			<style>
				body {
					font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
					display: flex;
					align-items: center;
					justify-content: center;
					min-height: 100vh;
					margin: 0;
					background: #f5f5f5;
				}
				.container {
					background: white;
					padding: 40px;
					border-radius: 8px;
					box-shadow: 0 2px 10px rgba(0,0,0,0.1);
					max-width: 500px;
					text-align: center;
				}
				.success {
					color: #10b981;
					font-size: 48px;
					margin-bottom: 20px;
				}
				h1 {
					color: #333;
					margin-bottom: 20px;
				}
				p {
					color: #666;
					line-height: 1.6;
				}
			</style>
		</head>
		<body>
			<div class="container">
				<?php if ( $success ): ?>
					<div class="success">✓</div>
					<h1><?php esc_html_e( 'Email Confirmed!', 'aebg' ); ?></h1>
					<p><?php esc_html_e( 'Thank you for confirming your email address. You are now subscribed to our mailing list.', 'aebg' ); ?></p>
				<?php else: ?>
					<div class="success" style="color: #ef4444;">✗</div>
					<h1><?php esc_html_e( 'Confirmation Failed', 'aebg' ); ?></h1>
					<p><?php esc_html_e( 'The confirmation link is invalid or has expired. Please try subscribing again.', 'aebg' ); ?></p>
				<?php endif; ?>
			</div>
		</body>
		</html>
		<?php
	}
	
	/**
	 * Render unsubscribe page
	 * 
	 * @param bool $success Whether unsubscribe was successful
	 * @return void
	 */
	private function render_unsubscribe_page( $success = false ) {
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title><?php esc_html_e( 'Unsubscribe', 'aebg' ); ?></title>
			<style>
				body {
					font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
					display: flex;
					align-items: center;
					justify-content: center;
					min-height: 100vh;
					margin: 0;
					background: #f5f5f5;
				}
				.container {
					background: white;
					padding: 40px;
					border-radius: 8px;
					box-shadow: 0 2px 10px rgba(0,0,0,0.1);
					max-width: 500px;
					text-align: center;
				}
				.success {
					color: #10b981;
					font-size: 48px;
					margin-bottom: 20px;
				}
				h1 {
					color: #333;
					margin-bottom: 20px;
				}
				p {
					color: #666;
					line-height: 1.6;
				}
			</style>
		</head>
		<body>
			<div class="container">
				<?php if ( $success ): ?>
					<div class="success">✓</div>
					<h1><?php esc_html_e( 'Unsubscribed', 'aebg' ); ?></h1>
					<p><?php esc_html_e( 'You have been successfully unsubscribed from our mailing list. You will no longer receive emails from us.', 'aebg' ); ?></p>
				<?php else: ?>
					<div class="success" style="color: #ef4444;">✗</div>
					<h1><?php esc_html_e( 'Unsubscribe Failed', 'aebg' ); ?></h1>
					<p><?php esc_html_e( 'The unsubscribe link is invalid or has expired. Please contact us if you need assistance.', 'aebg' ); ?></p>
				<?php endif; ?>
			</div>
		</body>
		</html>
		<?php
	}
}

