<?php

namespace AEBG\Core;

use AEBG\Core\Logger;

/**
 * Position Tracker
 * 
 * Tracks position changes and detects significant changes
 * 
 * @package AEBG\Core
 */
class PositionTracker {
	
	/**
	 * Compare two scrapes and detect changes
	 * 
	 * @param int $competitor_id      Competitor ID
	 * @param int $current_scrape_id  Current scrape ID
	 * @param int $previous_scrape_id Previous scrape ID
	 * @return array Changes array
	 */
	public function detect_changes( $competitor_id, $current_scrape_id, $previous_scrape_id ) {
		global $wpdb;
		
		Logger::info( 'Detecting position changes', [
			'competitor_id'      => $competitor_id,
			'current_scrape_id'  => $current_scrape_id,
			'previous_scrape_id' => $previous_scrape_id,
		] );
		
		// Get current products
		$current_products = $wpdb->get_results( $wpdb->prepare(
			"SELECT product_name, position, product_url FROM {$wpdb->prefix}aebg_competitor_products 
			WHERE competitor_id = %d AND scrape_id = %d ORDER BY position ASC",
			$competitor_id,
			$current_scrape_id
		), ARRAY_A );
		
		// Get previous products
		$previous_products = $wpdb->get_results( $wpdb->prepare(
			"SELECT product_name, position, product_url FROM {$wpdb->prefix}aebg_competitor_products 
			WHERE competitor_id = %d AND scrape_id = %d ORDER BY position ASC",
			$competitor_id,
			$previous_scrape_id
		), ARRAY_A );
		
		$changes = [];
		
		// Build lookup arrays
		$current_lookup = [];
		foreach ( $current_products as $product ) {
			$current_lookup[ $product['product_name'] ] = (int) $product['position'];
		}
		
		$previous_lookup = [];
		foreach ( $previous_products as $product ) {
			$previous_lookup[ $product['product_name'] ] = (int) $product['position'];
		}
		
		// Detect new products
		foreach ( $current_products as $product ) {
			if ( ! isset( $previous_lookup[ $product['product_name'] ] ) ) {
				$changes[] = [
					'change_type'    => 'new_product',
					'product_name'   => $product['product_name'],
					'old_value'      => null,
					'new_value'      => (string) $product['position'],
					'change_severity' => $this->calculate_severity( [
						'change_type' => 'new_product',
						'position'    => $product['position'],
					] ),
				];
			}
		}
		
		// Detect removed products
		foreach ( $previous_products as $product ) {
			if ( ! isset( $current_lookup[ $product['product_name'] ] ) ) {
				$changes[] = [
					'change_type'     => 'removed_product',
					'product_name'    => $product['product_name'],
					'old_value'       => (string) $product['position'],
					'new_value'       => null,
					'change_severity' => $this->calculate_severity( [
						'change_type' => 'removed_product',
						'position'    => $product['position'],
					] ),
				];
			}
		}
		
		// Detect position changes
		foreach ( $current_products as $product ) {
			$product_name = $product['product_name'];
			$current_position = (int) $product['position'];
			
			if ( isset( $previous_lookup[ $product_name ] ) ) {
				$previous_position = $previous_lookup[ $product_name ];
				$position_change = $previous_position - $current_position;
				
				if ( $position_change !== 0 ) {
					$changes[] = [
						'change_type'     => 'position_change',
						'product_name'    => $product_name,
						'old_value'       => (string) $previous_position,
						'new_value'       => (string) $current_position,
						'change_severity' => $this->calculate_severity( [
							'change_type'     => 'position_change',
							'position_change' => abs( $position_change ),
							'old_position'    => $previous_position,
							'new_position'    => $current_position,
						] ),
					];
				}
			}
		}
		
		// Detect major reshuffle (>50% of products changed positions significantly)
		$significant_changes = array_filter( $changes, function( $change ) {
			return $change['change_type'] === 'position_change' && abs( (int) $change['old_value'] - (int) $change['new_value'] ) >= 3;
		} );
		
		if ( count( $significant_changes ) > ( count( $previous_products ) * 0.5 ) ) {
			$changes[] = [
				'change_type'     => 'major_reshuffle',
				'product_name'    => null,
				'old_value'       => null,
				'new_value'       => (string) count( $significant_changes ),
				'change_severity' => 'high',
			];
		}
		
		Logger::info( 'Position changes detected', [
			'competitor_id' => $competitor_id,
			'total_changes' => count( $changes ),
		] );
		
		return $changes;
	}
	
	/**
	 * Calculate change severity
	 * 
	 * @param array $change Change data
	 * @return string Severity level (low, medium, high, critical)
	 */
	private function calculate_severity( $change ) {
		$change_type = $change['change_type'] ?? '';
		
		switch ( $change_type ) {
			case 'new_product':
				$position = $change['position'] ?? 999;
				if ( $position <= 3 ) {
					return 'high'; // New product in top 3
				} elseif ( $position <= 10 ) {
					return 'medium'; // New product in top 10
				}
				return 'low';
				
			case 'removed_product':
				$position = $change['position'] ?? 999;
				if ( $position <= 3 ) {
					return 'critical'; // Top 3 product removed
				} elseif ( $position <= 10 ) {
					return 'high'; // Top 10 product removed
				}
				return 'medium';
				
			case 'position_change':
				$position_change = abs( $change['position_change'] ?? 0 );
				$old_position = $change['old_position'] ?? 999;
				
				if ( $position_change >= 10 ) {
					return 'high'; // Moved 10+ positions
				} elseif ( $position_change >= 5 ) {
					return 'medium'; // Moved 5-9 positions
				} elseif ( $old_position <= 3 && $position_change >= 2 ) {
					return 'high'; // Top 3 product moved significantly
				} elseif ( $old_position <= 10 && $position_change >= 3 ) {
					return 'medium'; // Top 10 product moved
				}
				return 'low';
				
			default:
				return 'medium';
		}
	}
	
	/**
	 * Save changes to database
	 * 
	 * @param int   $competitor_id Competitor ID
	 * @param int   $scrape_id     Scrape ID
	 * @param array $changes       Changes array
	 * @return bool Success
	 */
	public function save_changes( $competitor_id, $scrape_id, $changes ) {
		global $wpdb;
		
		if ( empty( $changes ) ) {
			return true;
		}
		
		foreach ( $changes as $change ) {
			$wpdb->insert(
				$wpdb->prefix . 'aebg_competitor_changes',
				[
					'competitor_id'    => $competitor_id,
					'scrape_id'        => $scrape_id,
					'change_type'      => $change['change_type'],
					'product_name'     => $change['product_name'] ?? null,
					'old_value'        => $change['old_value'] ?? null,
					'new_value'        => $change['new_value'] ?? null,
					'change_severity'  => $change['change_severity'] ?? 'medium',
					'is_notified'      => 0,
					'created_at'       => current_time( 'mysql' ),
				],
				[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
			);
		}
		
		Logger::info( 'Changes saved to database', [
			'competitor_id' => $competitor_id,
			'scrape_id'     => $scrape_id,
			'changes_count' => count( $changes ),
		] );
		
		return true;
	}
}

