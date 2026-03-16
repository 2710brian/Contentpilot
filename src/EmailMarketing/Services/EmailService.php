<?php

namespace AEBG\EmailMarketing\Services;

use AEBG\EmailMarketing\Utils\OpenTracker;
use AEBG\EmailMarketing\Utils\ClickTracker;
use AEBG\EmailMarketing\Utils\UnsubscribeManager;
use AEBG\Core\Logger;

/**
 * Email Service
 * 
 * Handles email sending
 * 
 * @package AEBG\EmailMarketing\Services
 */
class EmailService {
	/**
	 * @var OpenTracker
	 */
	private $open_tracker;
	
	/**
	 * @var ClickTracker
	 */
	private $click_tracker;
	
	/**
	 * @var UnsubscribeManager
	 */
	private $unsubscribe_manager;
	
	/**
	 * Constructor
	 * 
	 * @param OpenTracker $open_tracker Open tracker
	 * @param ClickTracker $click_tracker Click tracker
	 * @param UnsubscribeManager $unsubscribe_manager Unsubscribe manager
	 */
	public function __construct( OpenTracker $open_tracker, ClickTracker $click_tracker, UnsubscribeManager $unsubscribe_manager ) {
		$this->open_tracker = $open_tracker;
		$this->click_tracker = $click_tracker;
		$this->unsubscribe_manager = $unsubscribe_manager;
	}
	
	/**
	 * Send email
	 * 
	 * @param int $queue_id Queue ID
	 * @param string $to Email address
	 * @param string $subject Subject line
	 * @param string $content_html HTML content
	 * @param string $content_text Plain text content (optional)
	 * @param array $headers Additional headers
	 * @return bool True on success, false on failure
	 */
	public function send_email( $queue_id, $to, $subject, $content_html, $content_text = null, $headers = [] ) {
		// Add tracking pixel for opens
		$content_html = $this->open_tracker->add_tracking_pixel( $content_html, $queue_id );
		
		// Add click tracking to links
		$content_html = $this->click_tracker->add_click_tracking( $content_html, $queue_id );
		
		// Add unsubscribe link if not present
		$subscriber_repository = new \AEBG\EmailMarketing\Repositories\SubscriberRepository();
		$queue_repository = new \AEBG\EmailMarketing\Repositories\QueueRepository();
		$queue = $queue_repository->get( $queue_id );
		
		if ( $queue ) {
			$subscriber = $subscriber_repository->get( $queue->subscriber_id );
			if ( $subscriber && strpos( $content_html, 'unsubscribe' ) === false ) {
				$unsubscribe_url = $this->unsubscribe_manager->generate_unsubscribe_url( $subscriber->id );
				$unsubscribe_link = '<p style="font-size: 12px; color: #999; text-align: center; margin-top: 30px;"><a href="' . esc_url( $unsubscribe_url ) . '" style="color: #999;">' . __( 'Unsubscribe', 'aebg' ) . '</a></p>';
				$content_html = str_replace( '</body>', $unsubscribe_link . '</body>', $content_html );
			}
		}
		
		// Prepare headers
		$email_headers = array_merge( [
			'Content-Type: text/html; charset=UTF-8',
		], $headers );
		
		// Send email
		$sent = wp_mail( $to, $subject, $content_html, $email_headers );
		
		if ( ! $sent ) {
			Logger::error( 'Email send failed', [
				'queue_id' => $queue_id,
				'to' => $to,
			] );
		}
		
		return $sent;
	}
	
	/**
	 * Send batch of emails
	 * 
	 * @param array $emails Array of email data
	 * @param int $batch_size Batch size
	 * @return array Results array
	 */
	public function send_batch( $emails, $batch_size = 50 ) {
		$results = [
			'sent' => 0,
			'failed' => 0,
		];
		
		$chunks = array_chunk( $emails, $batch_size );
		
		foreach ( $chunks as $chunk ) {
			foreach ( $chunk as $email ) {
				$sent = $this->send_email(
					$email['queue_id'],
					$email['to'],
					$email['subject'],
					$email['content_html'],
					$email['content_text'] ?? null,
					$email['headers'] ?? []
				);
				
				if ( $sent ) {
					$results['sent']++;
				} else {
					$results['failed']++;
				}
			}
			
			// Small delay between chunks
			usleep( 100000 ); // 0.1 seconds
		}
		
		return $results;
	}
}

