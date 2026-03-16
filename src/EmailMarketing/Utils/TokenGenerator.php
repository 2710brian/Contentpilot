<?php

namespace AEBG\EmailMarketing\Utils;

/**
 * Token Generator
 * 
 * Generates cryptographically secure tokens
 * 
 * @package AEBG\EmailMarketing\Utils
 */
class TokenGenerator {
	/**
	 * Generate secure token
	 * 
	 * @param int $length Token length in bytes (default: 32)
	 * @return string Hex-encoded token
	 */
	public static function generate( $length = 32 ) {
		return bin2hex( random_bytes( $length ) );
	}
	
	/**
	 * Generate HMAC token
	 * 
	 * @param string $data Data to sign
	 * @param string $secret Secret key
	 * @return string HMAC token
	 */
	public static function generate_hmac( $data, $secret ) {
		return hash_hmac( 'sha256', $data, $secret );
	}
	
	/**
	 * Verify HMAC token
	 * 
	 * @param string $token Token to verify
	 * @param string $data Original data
	 * @param string $secret Secret key
	 * @return bool True if valid
	 */
	public static function verify_hmac( $token, $data, $secret ) {
		$expected = self::generate_hmac( $data, $secret );
		return hash_equals( $expected, $token );
	}
}

