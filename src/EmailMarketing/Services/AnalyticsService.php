<?php

namespace AEBG\EmailMarketing\Services;

use AEBG\EmailMarketing\Repositories\CampaignRepository;
use AEBG\EmailMarketing\Repositories\TrackingRepository;
use AEBG\EmailMarketing\Repositories\QueueRepository;
use AEBG\EmailMarketing\Repositories\SubscriberRepository;
use AEBG\EmailMarketing\Repositories\ListRepository;

/**
 * Analytics Service
 * 
 * Calculates email analytics and metrics
 * 
 * @package AEBG\EmailMarketing\Services
 */
class AnalyticsService {
	/**
	 * @var CampaignRepository
	 */
	private $campaign_repository;
	
	/**
	 * @var TrackingRepository
	 */
	private $tracking_repository;
	
	/**
	 * @var QueueRepository
	 */
	private $queue_repository;
	
	/**
	 * @var SubscriberRepository
	 */
	private $subscriber_repository;
	
	/**
	 * @var ListRepository
	 */
	private $list_repository;
	
	/**
	 * Constructor
	 * 
	 * @param CampaignRepository|null $campaign_repository Campaign repository
	 * @param TrackingRepository|null $tracking_repository Tracking repository
	 * @param QueueRepository|null $queue_repository Queue repository
	 * @param SubscriberRepository|null $subscriber_repository Subscriber repository
	 * @param ListRepository|null $list_repository List repository
	 */
	public function __construct( 
		CampaignRepository $campaign_repository = null, 
		TrackingRepository $tracking_repository = null, 
		QueueRepository $queue_repository = null,
		SubscriberRepository $subscriber_repository = null,
		ListRepository $list_repository = null
	) {
		$this->campaign_repository = $campaign_repository ?: new CampaignRepository();
		$this->tracking_repository = $tracking_repository ?: new TrackingRepository();
		$this->queue_repository = $queue_repository ?: new QueueRepository();
		$this->subscriber_repository = $subscriber_repository ?: new SubscriberRepository();
		$this->list_repository = $list_repository ?: new ListRepository();
	}
	
	/**
	 * Get campaign statistics
	 * 
	 * @param int $campaign_id Campaign ID
	 * @return array Statistics array
	 */
	public function get_campaign_stats( $campaign_id ) {
		$campaign = $this->campaign_repository->get( $campaign_id );
		
		if ( ! $campaign ) {
			return [];
		}
		
		// Get queue items for this campaign
		$queue_items = $this->queue_repository->get_by_campaign( $campaign_id );
		
		// Calculate metrics
		$sent = count( array_filter( $queue_items, function( $item ) {
			return in_array( $item->status, ['sent', 'delivered'], true );
		} ) );
		
		$delivered = count( array_filter( $queue_items, function( $item ) {
			return $item->status === 'sent';
		} ) );
		
		$bounced = count( array_filter( $queue_items, function( $item ) {
			return $item->status === 'bounced';
		} ) );
		
		$opened = $this->tracking_repository->count_by_campaign_and_type( $campaign_id, 'opened' );
		$clicked = $this->tracking_repository->count_by_campaign_and_type( $campaign_id, 'clicked' );
		$unsubscribed = $this->tracking_repository->count_by_campaign_and_type( $campaign_id, 'unsubscribed' );
		
		// Calculate rates
		$open_rate = $delivered > 0 ? ( $opened / $delivered ) * 100 : 0;
		$click_rate = $delivered > 0 ? ( $clicked / $delivered ) * 100 : 0;
		$click_to_open_rate = $opened > 0 ? ( $clicked / $opened ) * 100 : 0;
		$bounce_rate = count( $queue_items ) > 0 ? ( $bounced / count( $queue_items ) ) * 100 : 0;
		$unsubscribe_rate = $delivered > 0 ? ( $unsubscribed / $delivered ) * 100 : 0;
		
		return [
			'sent' => count( $queue_items ),
			'delivered' => $delivered,
			'opened' => $opened,
			'clicked' => $clicked,
			'bounced' => $bounced,
			'unsubscribed' => $unsubscribed,
			'open_rate' => round( $open_rate, 2 ),
			'click_rate' => round( $click_rate, 2 ),
			'click_to_open_rate' => round( $click_to_open_rate, 2 ),
			'bounce_rate' => round( $bounce_rate, 2 ),
			'unsubscribe_rate' => round( $unsubscribe_rate, 2 ),
		];
	}
	
	/**
	 * Get campaign analytics with date range
	 * 
	 * @param int $campaign_id Campaign ID
	 * @param array $date_range Date range (start_date, end_date)
	 * @return array Analytics data
	 */
	public function get_campaign_analytics( $campaign_id, $date_range = null ) {
		global $wpdb;
		
		$tracking_table = $wpdb->prefix . 'aebg_email_tracking';
		
		$where = ['campaign_id = %d'];
		$params = [$campaign_id];
		
		if ( $date_range && ! empty( $date_range['start_date'] ) ) {
			$where[] = 'created_at >= %s';
			$params[] = $date_range['start_date'];
		}
		
		if ( $date_range && ! empty( $date_range['end_date'] ) ) {
			$where[] = 'created_at <= %s';
			$params[] = $date_range['end_date'];
		}
		
		$where_clause = implode( ' AND ', $where );
		
		// Get opens over time
		$opens_over_time = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(created_at) as date, COUNT(DISTINCT subscriber_id) as count 
			 FROM {$tracking_table} 
			 WHERE {$where_clause} AND event_type = 'opened' 
			 GROUP BY DATE(created_at) 
			 ORDER BY date ASC",
			$params
		) );
		
		// Get clicks over time
		$clicks_over_time = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(created_at) as date, COUNT(DISTINCT subscriber_id) as count 
			 FROM {$tracking_table} 
			 WHERE {$where_clause} AND event_type = 'clicked' 
			 GROUP BY DATE(created_at) 
			 ORDER BY date ASC",
			$params
		) );
		
		// Get top links
		$top_links = $wpdb->get_results( $wpdb->prepare(
			"SELECT JSON_EXTRACT(event_data, '$.url') as url, COUNT(*) as clicks 
			 FROM {$tracking_table} 
			 WHERE {$where_clause} AND event_type = 'clicked' 
			 GROUP BY url 
			 ORDER BY clicks DESC 
			 LIMIT 10",
			$params
		) );
		
		// Get device breakdown
		$device_breakdown = $wpdb->get_results( $wpdb->prepare(
			"SELECT device_type, COUNT(DISTINCT subscriber_id) as count 
			 FROM {$tracking_table} 
			 WHERE {$where_clause} AND event_type = 'opened' 
			 GROUP BY device_type",
			$params
		) );
		
		// Get email client breakdown
		$email_client_breakdown = $wpdb->get_results( $wpdb->prepare(
			"SELECT email_client, COUNT(DISTINCT subscriber_id) as count 
			 FROM {$tracking_table} 
			 WHERE {$where_clause} AND event_type = 'opened' 
			 GROUP BY email_client 
			 ORDER BY count DESC",
			$params
		) );
		
		return [
			'opens_over_time' => $opens_over_time,
			'clicks_over_time' => $clicks_over_time,
			'top_links' => $top_links,
			'device_breakdown' => $device_breakdown,
			'email_client_breakdown' => $email_client_breakdown,
		];
	}
	
	/**
	 * Get overall statistics
	 * 
	 * @return array Overall stats
	 */
	public function get_overall_stats() {
		global $wpdb;
		
		$subscribers_table = $wpdb->prefix . 'aebg_email_subscribers';
		$campaigns_table = $wpdb->prefix . 'aebg_email_campaigns';
		$queue_table = $wpdb->prefix . 'aebg_email_queue';
		$tracking_table = $wpdb->prefix . 'aebg_email_tracking';
		
		// Total subscribers (confirmed only)
		$total_subscribers = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$subscribers_table} WHERE status = 'confirmed'"
		);
		
		// Total campaigns
		$total_campaigns = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$campaigns_table}"
		);
		
		// Total emails sent
		$total_emails_sent = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$queue_table} WHERE status = 'sent'"
		);
		
		// Total opens
		$total_opens = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT subscriber_id) FROM {$tracking_table} WHERE event_type = 'opened'"
		);
		
		// Total clicks
		$total_clicks = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT subscriber_id) FROM {$tracking_table} WHERE event_type = 'clicked'"
		);
		
		// Calculate average rates
		$average_open_rate = $total_emails_sent > 0 ? ( $total_opens / $total_emails_sent ) * 100 : 0;
		$average_click_rate = $total_emails_sent > 0 ? ( $total_clicks / $total_emails_sent ) * 100 : 0;
		
		return [
			'total_subscribers' => $total_subscribers,
			'total_campaigns' => $total_campaigns,
			'total_emails_sent' => $total_emails_sent,
			'total_opens' => $total_opens,
			'total_clicks' => $total_clicks,
			'average_open_rate' => round( $average_open_rate, 2 ),
			'average_click_rate' => round( $average_click_rate, 2 ),
		];
	}
	
	/**
	 * Get recent campaigns statistics
	 * 
	 * @param int $limit Number of campaigns to return
	 * @return array Recent campaigns with stats
	 */
	public function get_recent_campaigns_stats( $limit = 10 ) {
		global $wpdb;
		
		$campaigns_table = $wpdb->prefix . 'aebg_email_campaigns';
		$queue_table = $wpdb->prefix . 'aebg_email_queue';
		$tracking_table = $wpdb->prefix . 'aebg_email_tracking';
		
		// Get recent campaigns
		$campaigns = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$campaigns_table} 
				 ORDER BY created_at DESC 
				 LIMIT %d",
				$limit
			)
		);
		
		// Add stats to each campaign
		foreach ( $campaigns as $campaign ) {
			// Get emails sent for this campaign
			$campaign->emails_sent = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$queue_table} WHERE campaign_id = %d AND status = 'sent'",
					$campaign->id
				)
			);
			
			// Get opens
			$campaign->emails_opened = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT subscriber_id) FROM {$tracking_table} 
					 WHERE campaign_id = %d AND event_type = 'opened'",
					$campaign->id
				)
			);
			
			// Get clicks
			$campaign->clicks = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT subscriber_id) FROM {$tracking_table} 
					 WHERE campaign_id = %d AND event_type = 'clicked'",
					$campaign->id
				)
			);
		}
		
		return $campaigns;
	}
}

