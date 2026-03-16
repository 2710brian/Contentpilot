<?php

namespace AEBG\EmailMarketing\Utils;

/**
 * Template Processor
 * 
 * Processes email templates and replaces variables
 * 
 * @package AEBG\EmailMarketing\Utils
 */
class TemplateProcessor {
	/**
	 * Replace variables in template
	 * 
	 * @param string $template Template string with variables
	 * @param array $variables Variables array
	 * @return string Processed template
	 */
	public static function replace_variables( $template, $variables ) {
		if ( empty( $variables ) || empty( $template ) ) {
			return $template;
		}
		
		// Replace {variable} with values
		foreach ( $variables as $key => $value ) {
			$placeholder = '{' . $key . '}';
			
			// Handle arrays (e.g., product_list)
			if ( is_array( $value ) ) {
				$value = self::format_array_value( $value, $key );
			}
			
			// Escape value for HTML context
			$value = esc_html( $value );
			
			$template = str_replace( $placeholder, $value, $template );
		}
		
		return $template;
	}
	
	/**
	 * Format array value for template
	 * 
	 * @param array $value Array value
	 * @param string $key Variable key
	 * @return string Formatted string
	 */
	private static function format_array_value( $value, $key ) {
		// Special handling for product_list
		if ( $key === 'product_list' ) {
			return self::format_product_list( $value );
		}
		
		// Default: join with commas
		return implode( ', ', array_map( 'esc_html', $value ) );
	}
	
	/**
	 * Format product list for email
	 * 
	 * @param array $products Products array
	 * @return string Formatted HTML
	 */
	private static function format_product_list( $products ) {
		if ( empty( $products ) || ! is_array( $products ) ) {
			return '';
		}
		
		$html = '<ul style="list-style: none; padding: 0;">';
		
		foreach ( $products as $product ) {
			$name = is_array( $product ) ? ( $product['name'] ?? $product['title'] ?? '' ) : $product;
			$url = is_array( $product ) ? ( $product['url'] ?? $product['affiliate_url'] ?? '' ) : '';
			
			$html .= '<li style="margin-bottom: 10px;">';
			if ( $url ) {
				$html .= '<a href="' . esc_url( $url ) . '" style="color: #667eea; text-decoration: none;">' . esc_html( $name ) . '</a>';
			} else {
				$html .= esc_html( $name );
			}
			$html .= '</li>';
		}
		
		$html .= '</ul>';
		
		return $html;
	}
	
	/**
	 * Get available template variables
	 * 
	 * @return array Array of variable descriptions
	 */
	public static function get_available_variables() {
		return [
			'post_title' => __( 'Post title', 'aebg' ),
			'post_url' => __( 'Post permalink URL', 'aebg' ),
			'post_excerpt' => __( 'Post excerpt', 'aebg' ),
			'post_content' => __( 'Post content (truncated)', 'aebg' ),
			'site_name' => __( 'Site name', 'aebg' ),
			'site_url' => __( 'Site URL', 'aebg' ),
			'unsubscribe_url' => __( 'Unsubscribe link', 'aebg' ),
			'subscriber_name' => __( 'Subscriber first name', 'aebg' ),
			'subscriber_email' => __( 'Subscriber email address', 'aebg' ),
			'product_list' => __( 'List of products (HTML formatted)', 'aebg' ),
		];
	}
}

