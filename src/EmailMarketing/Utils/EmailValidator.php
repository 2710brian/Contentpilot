<?php

namespace AEBG\EmailMarketing\Utils;

/**
 * Email Validator
 * 
 * Utility class for email validation
 * 
 * @package AEBG\EmailMarketing\Utils
 */
class EmailValidator {
	/**
	 * Validate email address
	 * 
	 * @param string $email Email address
	 * @return bool True if valid
	 */
	public static function is_valid( $email ) {
		return is_email( $email ) !== false;
	}
	
	/**
	 * Normalize email address
	 * 
	 * @param string $email Email address
	 * @return string Normalized email
	 */
	public static function normalize( $email ) {
		$email = trim( $email );
		$email = strtolower( $email );
		return $email;
	}
}

