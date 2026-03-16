<?php

namespace AEBG\EmailMarketing\Services;

use AEBG\EmailMarketing\Utils\TemplateProcessor;
use AEBG\EmailMarketing\Repositories\TemplateRepository;

/**
 * Template Service
 * 
 * Handles email template processing
 * 
 * @package AEBG\EmailMarketing\Services
 */
class TemplateService {
	/**
	 * @var TemplateRepository
	 */
	private $template_repository;
	
	/**
	 * Constructor
	 * 
	 * @param TemplateRepository $template_repository Template repository
	 */
	public function __construct( TemplateRepository $template_repository ) {
		$this->template_repository = $template_repository;
	}
	
	/**
	 * Get template by type
	 * 
	 * @param string $template_type Template type
	 * @param bool $default_only Get only default template
	 * @return object|null Template object
	 */
	public function get_template_by_type( $template_type, $default_only = true ) {
		return $this->template_repository->get_by_type( $template_type, $default_only );
	}
	
	/**
	 * Process template with variables
	 * 
	 * @param object $template Template object
	 * @param array $variables Variables to replace
	 * @return array Processed template (subject, content_html, content_text)
	 */
	public function process_template( $template, $variables ) {
		// Escape all variables for safety
		$escaped_vars = [];
		foreach ( $variables as $key => $value ) {
			if ( is_array( $value ) ) {
				$escaped_vars[ $key ] = $value; // Arrays handled by TemplateProcessor
			} else {
				$escaped_vars[ $key ] = $value; // Will be escaped in TemplateProcessor
			}
		}
		
		// Process subject
		$subject = TemplateProcessor::replace_variables( $template->subject_template, $escaped_vars );
		
		// Process HTML content
		$content_html = TemplateProcessor::replace_variables( $template->content_html, $escaped_vars );
		
		// Process text content (if exists)
		$content_text = null;
		if ( ! empty( $template->content_text ) ) {
			$content_text = TemplateProcessor::replace_variables( $template->content_text, $escaped_vars );
		}
		
		return [
			'subject' => $subject,
			'content_html' => $content_html,
			'content_text' => $content_text,
		];
	}
	
	/**
	 * Get available template variables
	 * 
	 * @return array Array of variable descriptions
	 */
	public function get_available_variables() {
		return TemplateProcessor::get_available_variables();
	}
}

