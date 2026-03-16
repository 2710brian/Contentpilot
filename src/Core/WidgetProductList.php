<?php
namespace Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Elementor\Core\Kits\Documents\Tabs\Global_Colors;
use Elementor\Core\Kits\Documents\Tabs\Global_Typography;
use Elementor\Controls_Manager;
use AEBG\Core\ProductManager;
use AEBG\Core\CurrencyManager;

/**
 * Elementor Product List Widget
 *
 * Elementor widget that displays a list of products associated with the current post.
 *
 * @since 1.0.0
 */
class AEBG_Product_List extends \Elementor\Widget_Base {

	/**
	 * Get widget name.
	 *
	 * Retrieve product list widget name.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @return string Widget name.
	 */
	public function get_name() {
		return 'aebg-product-list';
	}

	/**
	 * Get widget title.
	 *
	 * Retrieve product list widget title.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @return string Widget title.
	 */
	public function get_title() {
		return esc_html__( 'AEBG Product List', 'aebg' );
	}

	/**
	 * Get widget icon.
	 *
	 * Retrieve product list widget icon.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @return string Widget icon.
	 */
	public function get_icon() {
		return 'eicon-product-list';
	}

	/**
	 * Get widget keywords.
	 *
	 * Retrieve the list of keywords the widget belongs to.
	 *
	 * @since 2.1.0
	 * @access public
	 *
	 * @return array Widget keywords.
	 */
	public function get_keywords() {
		return [ 'product', 'list', 'products', 'aebg' ];
	}

	/**
	 * Get widget categories.
	 *
	 * Retrieve the list of categories the widget belongs to.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @return array Widget categories.
	 */
	public function get_categories() {
		return [ 'general' ];
	}

	/**
	 * Get style dependencies.
	 *
	 * Retrieve the list of style dependencies the widget requires.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @return array Widget style dependencies.
	 */
	public function get_style_depends(): array {
		return [ 'aebg-product-list-widget' ];
	}

	/**
	 * Register product list widget controls.
	 *
	 * Adds different input fields to allow the user to change and customize the widget settings.
	 *
	 * @since 1.0.0
	 * @access protected
	 */
	protected function register_controls() {
		// Content Section
		$this->start_controls_section(
			'section_content',
			[
				'label' => esc_html__( 'Product List', 'aebg' ),
			]
		);

		$this->add_control(
			'preset_style',
			[
				'label' => esc_html__( 'Preset Style', 'aebg' ),
				'type' => Controls_Manager::SELECT,
				'default' => 'numbered-list',
				'options' => [
					'list' => esc_html__( 'List', 'aebg' ),
					'numbered-list' => esc_html__( 'Numbered List', 'aebg' ),
					'modern-card' => esc_html__( 'Modern Card', 'aebg' ),
				],
				'prefix_class' => 'aebg-product-list--preset-',
			]
		);

		$this->add_control(
			'limit',
			[
				'label' => esc_html__( 'Limit Products', 'aebg' ),
				'type' => Controls_Manager::NUMBER,
				'default' => 0,
				'min' => 0,
				'description' => esc_html__( '0 = Show all products', 'aebg' ),
			]
		);

		$this->add_control(
			'review_link_text',
			[
				'label' => esc_html__( 'Review Link Text', 'aebg' ),
				'type' => Controls_Manager::TEXT,
				'default' => esc_html__( 'LÆS ANMELDELSEN', 'aebg' ),
				'placeholder' => esc_html__( 'Enter review link text', 'aebg' ),
			]
		);

		$this->add_control(
			'show_discount',
			[
				'label' => esc_html__( 'Show Discount', 'aebg' ),
				'type' => Controls_Manager::SWITCHER,
				'label_on' => esc_html__( 'Show', 'aebg' ),
				'label_off' => esc_html__( 'Hide', 'aebg' ),
				'return_value' => 'yes',
				'default' => 'yes',
			]
		);

		$this->end_controls_section();

		// Badge Section
		$this->start_controls_section(
			'section_badges',
			[
				'label' => esc_html__( 'Badges', 'aebg' ),
			]
		);

		$this->add_control(
			'show_badges',
			[
				'label' => esc_html__( 'Show Badges', 'aebg' ),
				'type' => Controls_Manager::SWITCHER,
				'label_on' => esc_html__( 'Show', 'aebg' ),
				'label_off' => esc_html__( 'Hide', 'aebg' ),
				'return_value' => 'yes',
				'default' => 'yes',
			]
		);

		$this->add_control(
			'badge_assignment_method',
			[
				'label' => esc_html__( 'Badge Assignment', 'aebg' ),
				'type' => Controls_Manager::SELECT,
				'default' => 'position',
				'options' => [
					'position' => esc_html__( 'Position-Based (Automatic)', 'aebg' ),
					'manual' => esc_html__( 'Manual (From Product Data)', 'aebg' ),
				],
				'condition' => [
					'show_badges' => 'yes',
				],
			]
		);

		$this->add_control(
			'badge_position_1',
			[
				'label' => esc_html__( '1st Product Badge', 'aebg' ),
				'type' => Controls_Manager::SELECT,
				'default' => 'testvinder',
				'options' => $this->get_badge_type_options(),
				'condition' => [
					'show_badges' => 'yes',
					'badge_assignment_method' => 'position',
				],
			]
		);

		$this->add_control(
			'badge_position_2',
			[
				'label' => esc_html__( '2nd Product Badge', 'aebg' ),
				'type' => Controls_Manager::SELECT,
				'default' => 'bedste-budget',
				'options' => $this->get_badge_type_options(),
				'condition' => [
					'show_badges' => 'yes',
					'badge_assignment_method' => 'position',
				],
			]
		);

		$this->add_control(
			'badge_position_3',
			[
				'label' => esc_html__( '3rd Product Badge', 'aebg' ),
				'type' => Controls_Manager::SELECT,
				'default' => 'premium',
				'options' => $this->get_badge_type_options(),
				'condition' => [
					'show_badges' => 'yes',
					'badge_assignment_method' => 'position',
				],
			]
		);

		$this->end_controls_section();

		// Style Section - Container
		$this->start_controls_section(
			'section_container_style',
			[
				'label' => esc_html__( 'Container', 'aebg' ),
				'tab' => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			[
				'name' => 'container_background',
				'label' => esc_html__( 'Background', 'aebg' ),
				'types' => [ 'classic', 'gradient' ],
				'selector' => '{{WRAPPER}} .aebg-product-list',
			]
		);

		$this->add_responsive_control(
			'container_padding',
			[
				'label' => esc_html__( 'Padding', 'aebg' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', 'rem', 'vw', 'custom' ],
				'selectors' => [
					'{{WRAPPER}} .aebg-product-list' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'container_margin',
			[
				'label' => esc_html__( 'Margin', 'aebg' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', 'rem', 'vw', 'custom' ],
				'selectors' => [
					'{{WRAPPER}} .aebg-product-list' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			[
				'name' => 'container_border',
				'selector' => '{{WRAPPER}} .aebg-product-list',
			]
		);

		$this->add_responsive_control(
			'container_border_radius',
			[
				'label' => esc_html__( 'Border Radius', 'aebg' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em', 'rem', 'custom' ],
				'selectors' => [
					'{{WRAPPER}} .aebg-product-list' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			[
				'name' => 'container_box_shadow',
				'selector' => '{{WRAPPER}} .aebg-product-list',
			]
		);

		$this->end_controls_section();

		// Style Section - List Item
		$this->start_controls_section(
			'section_item_style',
			[
				'label' => esc_html__( 'List Item', 'aebg' ),
				'tab' => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			[
				'name' => 'item_background',
				'label' => esc_html__( 'Background', 'aebg' ),
				'types' => [ 'classic', 'gradient' ],
				'selector' => '{{WRAPPER}} .aebg-product-item',
			]
		);

		$this->add_responsive_control(
			'item_padding',
			[
				'label' => esc_html__( 'Padding', 'aebg' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', 'rem', 'vw', 'custom' ],
				'default' => [
					'top' => '20',
					'right' => '20',
					'bottom' => '20',
					'left' => '20',
					'unit' => 'px',
				],
				'tablet_default' => [
					'top' => '18',
					'right' => '18',
					'bottom' => '18',
					'left' => '18',
					'unit' => 'px',
				],
				'mobile_default' => [
					'top' => '15',
					'right' => '15',
					'bottom' => '15',
					'left' => '15',
					'unit' => 'px',
				],
				'selectors' => [
					'{{WRAPPER}} .aebg-product-item' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'item_margin',
			[
				'label' => esc_html__( 'Margin', 'aebg' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', 'rem', 'vw', 'custom' ],
				'selectors' => [
					'{{WRAPPER}} .aebg-product-item' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			[
				'name' => 'item_border',
				'selector' => '{{WRAPPER}} .aebg-product-item',
				'fields_options' => [
					'border' => [
						'default' => 'solid',
					],
					'width' => [
						'default' => [
							'top' => '0',
							'right' => '0',
							'bottom' => '1',
							'left' => '0',
						],
					],
					'color' => [
						'default' => '#e0e0e0',
					],
				],
			]
		);

		$this->add_responsive_control(
			'item_border_radius',
			[
				'label' => esc_html__( 'Border Radius', 'aebg' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em', 'rem', 'custom' ],
				'selectors' => [
					'{{WRAPPER}} .aebg-product-item' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'item_gap',
			[
				'label' => esc_html__( 'Gap Between Elements', 'aebg' ),
				'type' => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem', 'custom' ],
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 100,
					],
				],
				'default' => [
					'size' => '20',
					'unit' => 'px',
				],
				'tablet_default' => [
					'size' => '15',
					'unit' => 'px',
				],
				'mobile_default' => [
					'size' => '15',
					'unit' => 'px',
				],
				'selectors' => [
					'{{WRAPPER}} .aebg-product-item' => 'gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();

		// Style Section - Number
		$this->start_controls_section(
			'section_number_style',
			[
				'label' => esc_html__( 'Number', 'aebg' ),
				'tab' => Controls_Manager::TAB_STYLE,
				'condition' => [
					'preset_style' => 'numbered-list',
				],
			]
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[
				'name' => 'number_typography',
				'selector' => '{{WRAPPER}} .aebg-product-number',
				'global' => [
					'default' => Global_Typography::TYPOGRAPHY_PRIMARY,
				],
			]
		);

		$this->add_control(
			'number_color',
			[
				'label' => esc_html__( 'Color', 'aebg' ),
				'type' => Controls_Manager::COLOR,
				'default' => '#333333',
				'selectors' => [
					'{{WRAPPER}} .aebg-product-number' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_responsive_control(
			'number_size',
			[
				'label' => esc_html__( 'Size', 'aebg' ),
				'type' => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem', 'custom' ],
				'range' => [
					'px' => [
						'min' => 20,
						'max' => 100,
					],
				],
				'default' => [
					'size' => 48,
					'unit' => 'px',
				],
				'tablet_default' => [
					'size' => 36,
					'unit' => 'px',
				],
				'mobile_default' => [
					'size' => 28,
					'unit' => 'px',
				],
				'selectors' => [
					'{{WRAPPER}} .aebg-product-number' => 'font-size: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'number_width',
			[
				'label' => esc_html__( 'Width', 'aebg' ),
				'type' => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem', 'custom' ],
				'range' => [
					'px' => [
						'min' => 30,
						'max' => 100,
					],
				],
				'default' => [
					'size' => 50,
					'unit' => 'px',
				],
				'tablet_default' => [
					'size' => 45,
					'unit' => 'px',
				],
				'mobile_default' => [
					'size' => 35,
					'unit' => 'px',
				],
				'selectors' => [
					'{{WRAPPER}} .aebg-product-number' => 'width: {{SIZE}}{{UNIT}}; max-width: {{SIZE}}{{UNIT}}; flex-shrink: 0;',
				],
			]
		);

		$this->end_controls_section();

		// Style Section - Image
		$this->start_controls_section(
			'section_image_style',
			[
				'label' => esc_html__( 'Image', 'aebg' ),
				'tab' => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_responsive_control(
			'image_width',
			[
				'label' => esc_html__( 'Width', 'aebg' ),
				'type' => Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%', 'em', 'rem', 'vw' ],
				'range' => [
					'px' => [
						'min' => 50,
						'max' => 500,
					],
					'%' => [
						'min' => 10,
						'max' => 100,
					],
				],
				'default' => [
					'size' => 200,
					'unit' => 'px',
				],
				'mobile_default' => [
					'size' => 100,
					'unit' => '%',
				],
				'selectors' => [
					'{{WRAPPER}} .aebg-product-image' => 'width: {{SIZE}}{{UNIT}}; max-width: 100%; flex-shrink: 0;',
					'{{WRAPPER}} .aebg-product-image img' => 'width: 100%; max-width: 100%; height: auto;',
				],
			]
		);

		$this->add_responsive_control(
			'image_border_radius',
			[
				'label' => esc_html__( 'Border Radius', 'aebg' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em', 'rem', 'custom' ],
				'selectors' => [
					'{{WRAPPER}} .aebg-product-image img' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			[
				'name' => 'image_border',
				'selector' => '{{WRAPPER}} .aebg-product-image img',
			]
		);

		$this->end_controls_section();

		// Style Section - Title
		$this->start_controls_section(
			'section_title_style',
			[
				'label' => esc_html__( 'Title', 'aebg' ),
				'tab' => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[
				'name' => 'title_typography',
				'selector' => '{{WRAPPER}} .aebg-product-title',
				'global' => [
					'default' => Global_Typography::TYPOGRAPHY_PRIMARY,
				],
			]
		);

		$this->add_control(
			'title_color',
			[
				'label' => esc_html__( 'Color', 'aebg' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .aebg-product-title' => 'color: {{VALUE}};',
				],
				'global' => [
					'default' => Global_Colors::COLOR_PRIMARY,
				],
			]
		);

		$this->add_responsive_control(
			'title_margin',
			[
				'label' => esc_html__( 'Margin', 'aebg' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', 'rem', 'vw', 'custom' ],
				'selectors' => [
					'{{WRAPPER}} .aebg-product-title' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();

		// Style Section - Discount
		$this->start_controls_section(
			'section_discount_style',
			[
				'label' => esc_html__( 'Discount', 'aebg' ),
				'tab' => Controls_Manager::TAB_STYLE,
				'condition' => [
					'show_discount' => 'yes',
				],
			]
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[
				'name' => 'discount_typography',
				'selector' => '{{WRAPPER}} .aebg-product-discount',
				'global' => [
					'default' => Global_Typography::TYPOGRAPHY_PRIMARY,
				],
			]
		);

		$this->add_control(
			'discount_color',
			[
				'label' => esc_html__( 'Color', 'aebg' ),
				'type' => Controls_Manager::COLOR,
				'default' => '#e53935',
				'selectors' => [
					'{{WRAPPER}} .aebg-product-discount' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_responsive_control(
			'discount_margin',
			[
				'label' => esc_html__( 'Margin', 'aebg' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', 'rem', 'vw', 'custom' ],
				'selectors' => [
					'{{WRAPPER}} .aebg-product-discount' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();

		// Style Section - Review Link
		$this->start_controls_section(
			'section_review_link_style',
			[
				'label' => esc_html__( 'Review Link', 'aebg' ),
				'tab' => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[
				'name' => 'review_link_typography',
				'selector' => '{{WRAPPER}} .aebg-product-review-link',
				'global' => [
					'default' => Global_Typography::TYPOGRAPHY_PRIMARY,
				],
			]
		);

		$this->start_controls_tabs( 'review_link_style_tabs' );

		$this->start_controls_tab(
			'review_link_normal',
			[
				'label' => esc_html__( 'Normal', 'aebg' ),
			]
		);

		$this->add_control(
			'review_link_color',
			[
				'label' => esc_html__( 'Color', 'aebg' ),
				'type' => Controls_Manager::COLOR,
				'default' => '#1976d2',
				'selectors' => [
					'{{WRAPPER}} .aebg-product-review-link' => 'color: {{VALUE}};',
				],
			]
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'review_link_hover',
			[
				'label' => esc_html__( 'Hover', 'aebg' ),
			]
		);

		$this->add_control(
			'review_link_color_hover',
			[
				'label' => esc_html__( 'Color', 'aebg' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .aebg-product-review-link:hover' => 'color: {{VALUE}};',
				],
			]
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_responsive_control(
			'review_link_margin',
			[
				'label' => esc_html__( 'Margin', 'aebg' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', 'rem', 'vw', 'custom' ],
				'selectors' => [
					'{{WRAPPER}} .aebg-product-review-link' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();

		// Style Section - Badge
		$this->start_controls_section(
			'section_badge_style',
			[
				'label' => esc_html__( 'Badge', 'aebg' ),
				'tab' => Controls_Manager::TAB_STYLE,
				'condition' => [
					'show_badges' => 'yes',
				],
			]
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[
				'name' => 'badge_typography',
				'selector' => '{{WRAPPER}} .aebg-product-badge',
				'global' => [
					'default' => Global_Typography::TYPOGRAPHY_PRIMARY,
				],
			]
		);

		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			[
				'name' => 'badge_background',
				'label' => esc_html__( 'Background', 'aebg' ),
				'types' => [ 'classic', 'gradient' ],
				'default' => '#e91e63',
				'selector' => '{{WRAPPER}} .aebg-product-badge',
			]
		);

		$this->add_control(
			'badge_text_color',
			[
				'label' => esc_html__( 'Text Color', 'aebg' ),
				'type' => Controls_Manager::COLOR,
				'default' => '#ffffff',
				'selectors' => [
					'{{WRAPPER}} .aebg-product-badge' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_responsive_control(
			'badge_padding',
			[
				'label' => esc_html__( 'Padding', 'aebg' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', 'rem', 'custom' ],
				'default' => [
					'top' => '10',
					'right' => '20',
					'bottom' => '10',
					'left' => '20',
					'unit' => 'px',
				],
				'selectors' => [
					'{{WRAPPER}} .aebg-product-badge' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'badge_border_radius',
			[
				'label' => esc_html__( 'Border Radius', 'aebg' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em', 'rem', 'custom' ],
				'default' => [
					'top' => '25',
					'right' => '25',
					'bottom' => '25',
					'left' => '25',
					'unit' => 'px',
				],
				'selectors' => [
					'{{WRAPPER}} .aebg-product-badge' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'badge_width',
			[
				'label' => esc_html__( 'Width', 'aebg' ),
				'type' => Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%', 'em', 'rem', 'vw', 'custom' ],
				'range' => [
					'px' => [
						'min' => 100,
						'max' => 500,
					],
					'%' => [
						'min' => 10,
						'max' => 100,
					],
				],
				'default' => [
					'size' => 250,
					'unit' => 'px',
				],
				'tablet_default' => [
					'size' => 200,
					'unit' => 'px',
				],
				'mobile_default' => [
					'size' => 100,
					'unit' => '%',
				],
				'selectors' => [
					'{{WRAPPER}} .aebg-product-badge' => 'min-width: {{SIZE}}{{UNIT}}; max-width: 100%;',
				],
			]
		);

		$this->add_responsive_control(
			'badge_align',
			[
				'label' => esc_html__( 'Alignment', 'aebg' ),
				'type' => Controls_Manager::CHOOSE,
				'options' => [
					'left' => [
						'title' => esc_html__( 'Left', 'aebg' ),
						'icon' => 'eicon-text-align-left',
					],
					'center' => [
						'title' => esc_html__( 'Center', 'aebg' ),
						'icon' => 'eicon-text-align-center',
					],
					'right' => [
						'title' => esc_html__( 'Right', 'aebg' ),
						'icon' => 'eicon-text-align-right',
					],
				],
				'default' => 'center',
				'selectors' => [
					'{{WRAPPER}} .aebg-product-badge' => 'text-align: {{VALUE}};',
				],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Get badge type options.
	 *
	 * @return array Badge type options.
	 */
	protected function get_badge_type_options() {
		return [
			'' => esc_html__( 'None', 'aebg' ),
			'testvinder' => esc_html__( 'Testvinder', 'aebg' ),
			'bedst-i-test' => esc_html__( 'Bedst i test', 'aebg' ),
			'bedste-budget' => esc_html__( 'Bedste budget', 'aebg' ),
			'premium' => esc_html__( 'Premium', 'aebg' ),
			'anbefalet' => esc_html__( 'Anbefalet', 'aebg' ),
			'bedste-vaerdi' => esc_html__( 'Bedste værdi', 'aebg' ),
			'nyhed' => esc_html__( 'Nyhed', 'aebg' ),
			'populaer' => esc_html__( 'Populær', 'aebg' ),
			'tilbud' => esc_html__( 'Tilbud', 'aebg' ),
			'custom' => esc_html__( 'Custom', 'aebg' ),
		];
	}

	/**
	 * Get badge label by type.
	 *
	 * @param string $type Badge type.
	 * @return string Badge label.
	 */
	protected function get_badge_label( $type ) {
		$labels = [
			'testvinder' => 'Testvinder',
			'bedst-i-test' => 'Bedst i test',
			'bedste-budget' => 'Bedste budget',
			'premium' => 'Premium',
			'anbefalet' => 'Anbefalet',
			'bedste-vaerdi' => 'Bedste værdi',
			'nyhed' => 'Nyhed',
			'populaer' => 'Populær',
			'tilbud' => 'Tilbud',
		];

		return isset( $labels[ $type ] ) ? $labels[ $type ] : '';
	}

	/**
	 * Get badge for product.
	 *
	 * @param array  $product Product data.
	 * @param int    $index Product index (0-based).
	 * @param array  $settings Widget settings.
	 * @return array|null Badge data or null.
	 */
	protected function get_product_badge( $product, $index, $settings ) {
		if ( empty( $settings['show_badges'] ) || $settings['show_badges'] !== 'yes' ) {
			return null;
		}

		if ( ! is_array( $product ) || ! is_array( $settings ) ) {
			return null;
		}

		$index = max( 0, intval( $index ) );

		// Manual assignment from product data
		if ( isset( $settings['badge_assignment_method'] ) && $settings['badge_assignment_method'] === 'manual' ) {
			if ( ! empty( $product['badges'] ) && is_array( $product['badges'] ) && isset( $product['badges'][0] ) ) {
				$badge_data = $product['badges'][0];
				if ( ! empty( $badge_data['type'] ) && is_string( $badge_data['type'] ) ) {
					return [
						'type' => sanitize_key( $badge_data['type'] ),
						'text' => ! empty( $badge_data['text'] ) && is_string( $badge_data['text'] ) ? sanitize_text_field( $badge_data['text'] ) : $this->get_badge_label( $badge_data['type'] ),
						'rating' => ! empty( $badge_data['rating'] ) ? floatval( $badge_data['rating'] ) : null,
					];
				}
			}
			return null;
		}

		// Position-based assignment
		$position = $index + 1;
		$badge_type = '';

		if ( $position === 1 && ! empty( $settings['badge_position_1'] ) ) {
			$badge_type = sanitize_key( $settings['badge_position_1'] );
		} elseif ( $position === 2 && ! empty( $settings['badge_position_2'] ) ) {
			$badge_type = sanitize_key( $settings['badge_position_2'] );
		} elseif ( $position === 3 && ! empty( $settings['badge_position_3'] ) ) {
			$badge_type = sanitize_key( $settings['badge_position_3'] );
		}

		if ( empty( $badge_type ) ) {
			return null;
		}

		// Get rating from product if available
		$rating = null;
		if ( ! empty( $product['rating'] ) && is_numeric( $product['rating'] ) ) {
			$rating = floatval( $product['rating'] );
		}

		return [
			'type' => $badge_type,
			'text' => $this->get_badge_label( $badge_type ),
			'rating' => $rating,
		];
	}

	/**
	 * Render badge HTML.
	 *
	 * @param array  $badge_data Badge data.
	 * @param array  $settings Widget settings.
	 * @return string Badge HTML.
	 */
	protected function render_badge( $badge_data, $settings ) {
		if ( empty( $badge_data ) || ! is_array( $badge_data ) || empty( $badge_data['type'] ) ) {
			return '';
		}

		$valid_badge_types = [ 'testvinder', 'bedst-i-test', 'bedste-budget', 'premium', 'anbefalet', 'bedste-vaerdi', 'nyhed', 'populaer', 'tilbud', 'custom' ];
		$badge_type = in_array( $badge_data['type'], $valid_badge_types ) ? $badge_data['type'] : 'custom';
		
		$badge_text = ! empty( $badge_data['text'] ) ? sanitize_text_field( $badge_data['text'] ) : $this->get_badge_label( $badge_type );
		if ( empty( $badge_text ) ) {
			$badge_text = $this->get_badge_label( $badge_type );
		}

		// Add rating if available
		$rating_text = '';
		if ( ! empty( $badge_data['rating'] ) && is_numeric( $badge_data['rating'] ) ) {
			$rating = min( 10.0, max( 0.0, floatval( $badge_data['rating'] ) ) );
			$rating_text = ' - ' . number_format( $rating, 1 ) . '/10';
		}

		$classes = [
			'aebg-product-badge',
			'aebg-product-badge--' . esc_attr( $badge_type ),
		];

		$badge_html = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';
		$badge_html .= esc_html( strtoupper( $badge_text ) ) . $rating_text;
		$badge_html .= '</div>';

		return $badge_html;
	}

	/**
	 * Calculate discount amount.
	 *
	 * @param array $product Product data.
	 * @return string Discount text or empty string.
	 */
	protected function get_discount_text( $product ) {
		if ( empty( $product['price'] ) || empty( $product['original_price'] ) ) {
			return '';
		}

		$price = floatval( $product['price'] );
		$original_price = floatval( $product['original_price'] );

		if ( $original_price <= $price ) {
			return '';
		}

		$discount = $original_price - $price;
		$currency = ! empty( $product['currency'] ) ? $product['currency'] : 'DKK';
		
		// Format discount amount - Use CurrencyManager directly
		$normalized_discount = CurrencyManager::normalizePrice( $discount, $currency );
		$formatted_discount = CurrencyManager::formatPrice( $normalized_discount, $currency );
		
		return sprintf( esc_html__( 'Spar %s lige nu!', 'aebg' ), $formatted_discount );
	}

	/**
	 * Render product list widget output on the frontend.
	 *
	 * Written in PHP and used to generate the final HTML.
	 *
	 * @since 1.0.0
	 * @access protected
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		// Get current post ID
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				$post_id = \Elementor\Plugin::$instance->editor->get_post_id();
			}
		}

		if ( ! $post_id ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="aebg-product-list-empty">' . esc_html__( 'No post ID found. This widget will display products associated with the current post.', 'aebg' ) . '</div>';
			}
			return;
		}

		// Get products for this post
		$products = ProductManager::getPostProducts( $post_id );

		if ( empty( $products ) ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="aebg-product-list-empty">' . esc_html__( 'No products found for this post.', 'aebg' ) . '</div>';
			}
			return;
		}

		// Apply limit if set
		if ( ! empty( $settings['limit'] ) && $settings['limit'] > 0 ) {
			$products = array_slice( $products, 0, (int) $settings['limit'] );
		}

		// Get preset style
		$preset_style = ! empty( $settings['preset_style'] ) ? $settings['preset_style'] : 'numbered-list';
		$is_numbered = $preset_style === 'numbered-list';
		$is_modern_card = $preset_style === 'modern-card';

		// Add render attributes
		$this->add_render_attribute( 'wrapper', 'class', 'aebg-product-list' );
		$this->add_render_attribute( 'wrapper', 'class', 'aebg-product-list--preset-' . $preset_style );
		$this->add_render_attribute( 'wrapper', 'style', 'width: 100%; max-width: 100%; overflow-x: hidden; box-sizing: border-box;' );

		// Determine wrapper tag
		$wrapper_tag = $is_numbered ? 'ol' : 'div';

		?>
		<<?php echo esc_attr( $wrapper_tag ); ?> <?php $this->print_render_attribute_string( 'wrapper' ); ?>>
			<?php foreach ( $products as $index => $product ) : ?>
				<?php
				if ( ! is_array( $product ) || empty( $product ) ) {
					continue;
				}

				$product_number = $index + 1;
				$badge_data = $this->get_product_badge( $product, $index, $settings );
				$discount_text = $this->get_discount_text( $product );
				$review_link_text = ! empty( $settings['review_link_text'] ) ? $settings['review_link_text'] : esc_html__( 'LÆS ANMELDELSEN', 'aebg' );
				$review_link_href = '#' . 'p' . $product_number;

				$item_key = 'item_' . $index;
				$this->add_render_attribute( $item_key, 'class', 'aebg-product-item' );
				if ( $is_numbered ) {
					$this->add_render_attribute( $item_key, 'class', 'aebg-row' );
					$this->add_render_attribute( $item_key, 'class', 'aebg-align-items-center' );
				}
				
				// Calculate discount percentage
				$discount_percent = '';
				if ( ! empty( $product['price'] ) && ! empty( $product['original_price'] ) ) {
					$price = floatval( $product['price'] );
					$original_price = floatval( $product['original_price'] );
					if ( $original_price > $price ) {
						$discount_percent = round( ( ( $original_price - $price ) / $original_price ) * 100 );
					}
				}
				
				// Get discount code if available
				$discount_code = ! empty( $product['discount_code'] ) ? $product['discount_code'] : '';
				
				// Check for limited stock
				$is_limited_stock = ! empty( $product['limited_stock'] ) || ( ! empty( $product['stock_status'] ) && strpos( strtolower( $product['stock_status'] ), 'begrænset' ) !== false );
				
				// Get CTA badge text (for inline badges like "Gulvvask", "Bedst til prisen", etc.)
				// But exclude "Anbefalet" and "Populær" as they go in promotion-line
				$cta_badge_text = '';
				$top_right_badge_text = '';
				if ( ! empty( $badge_data ) && ! empty( $badge_data['type'] ) ) {
					$badge_type = $badge_data['type'];
					// Check if it's a top-right badge type
					if ( in_array( $badge_type, [ 'anbefalet', 'populaer' ] ) ) {
						$top_right_badge_text = ! empty( $badge_data['text'] ) ? $badge_data['text'] : $this->get_badge_label( $badge_type );
					} else {
						// Regular inline CTA badge
						$cta_badge_text = ! empty( $badge_data['text'] ) ? $badge_data['text'] : '';
					}
				}
				
				// Number badge class (lbb for top 3, plain for 4+)
				$number_class = $product_number <= 3 ? 'aebg-num aebg-lbb' : 'aebg-num';
				
				// Affiliate URL and merchant
				$affiliate_url = ! empty( $product['affiliate_url'] ) ? $product['affiliate_url'] : ( ! empty( $product['url'] ) ? $product['url'] : '' );
				$merchant_name = ! empty( $product['merchant'] ) ? $product['merchant'] : '';
				$merchant_logo_url = ! empty( $product['merchant_logo_url'] ) ? $product['merchant_logo_url'] : '';
				
				// Currency and price formatting - Use CurrencyManager directly
				// formatPrice() already calls normalizePrice() internally, so don't normalize twice
				$currency = ! empty( $product['currency'] ) ? $product['currency'] : 'DKK';
				// Use finalprice if available (sale price), otherwise use price (regular price)
				$price_value = 0;
				if ( ! empty( $product['finalprice'] ) ) {
					$price_value = floatval( $product['finalprice'] );
				} elseif ( ! empty( $product['final_price'] ) ) {
					$price_value = floatval( $product['final_price'] );
				} elseif ( ! empty( $product['price'] ) ) {
					$price_value = floatval( $product['price'] );
				}
				// formatPrice() handles normalization internally, so pass raw price
				$formatted_price = $price_value > 0 ? sprintf( __( 'Set til %s', 'aebg' ), CurrencyManager::formatPrice( $price_value, $currency ) ) : '';
				// Price without "Set til" for final price display
				$formatted_price_display = $price_value > 0 ? CurrencyManager::formatPrice( $price_value, $currency ) : '';
				
				// Image URL
				$image_url = '';
				if ( ! empty( $product['featured_image_url'] ) ) {
					$image_url = $product['featured_image_url'];
				} elseif ( ! empty( $product['image_url'] ) ) {
					$image_url = $product['image_url'];
				}
				?>
				
				<?php if ( $is_numbered ) : ?>
					<li <?php $this->print_render_attribute_string( $item_key ); ?>>
						<?php if ( ! empty( $affiliate_url ) ) : ?>
							<a href="<?php echo esc_url( $affiliate_url ); ?>" target="_blank" rel="nofollow noopener" class="aebg-fi-xd aebg-z6" aria-label="<?php echo esc_attr( sprintf( __( 'Gå til %s', 'aebg' ), $product['name'] ?? '' ) ); ?>"></a>
						<?php endif; ?>
						
						<?php if ( $product_number === 1 && $settings['show_discount'] === 'yes' ) : ?>
							<!-- "Vi anbefaler" badge for first item -->
							<span class="aebg-promotion-line">
								<span class="aebg-winner">
									<span><?php echo esc_html__( 'Vi anbefaler', 'aebg' ); ?></span>
								</span>
							</span>
						<?php elseif ( ! empty( $top_right_badge_text ) || ( $settings['show_discount'] === 'yes' && ( ! empty( $discount_code ) || ! empty( $discount_percent ) || $is_limited_stock ) ) ) : ?>
							<!-- Promotion badges for other items -->
							<span class="aebg-promotion-line">
								<?php if ( ! empty( $top_right_badge_text ) ) : ?>
									<span class="aebg-winner">
										<span><?php echo esc_html( $top_right_badge_text ); ?></span>
									</span>
								<?php endif; ?>
								<?php if ( ! empty( $discount_code ) ) : ?>
									<span class="aebg-promotion aebg-code aebg-short"><?php echo esc_html( sprintf( __( 'Rabatkode: %s', 'aebg' ), $discount_code ) ); ?></span>
								<?php endif; ?>
								<?php if ( $is_limited_stock ) : ?>
									<span class="aebg-promotion aebg-short"><?php echo esc_html__( 'Begrænset lager', 'aebg' ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $discount_percent ) ) : ?>
									<span class="aebg-discount-percent"><?php echo esc_html( sprintf( __( 'Spar %d%%', 'aebg' ), $discount_percent ) ); ?></span>
								<?php endif; ?>
							</span>
						<?php endif; ?>
						
						<!-- Number badge -->
						<div class="aebg-col-auto aebg-order-sm-1 aebg-order-1 aebg-number-col">
							<span class="<?php echo esc_attr( $number_class ); ?>"><?php echo esc_html( $product_number ); ?></span>
						</div>
						
						<!-- Product image -->
						<?php if ( ! empty( $image_url ) ) : ?>
							<div class="aebg-col-auto aebg-col-md-auto aebg-order-sm-2 aebg-order-2 aebg-img">
								<picture class="aebg-sm-al-img">
									<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $product['name'] ?? '' ); ?>" width="64" height="64" loading="lazy" />
								</picture>
							</div>
						<?php endif; ?>
						
						<!-- Product content -->
						<div class="aebg-col aebg-col-md-5 aebg-order-4 aebg-order-sm-3 aebg-desc-r">
							<?php if ( ! empty( $product['name'] ) ) : ?>
								<span class="aebg-d-block aebg-overflow-txt"><?php echo esc_html( $product['name'] ); ?></span>
							<?php endif; ?>
							
							<span class="aebg-d-flex aebg-mo-r-inf-o">
								<?php if ( ! empty( $formatted_price ) ) : ?>
									<span><?php echo esc_html( $formatted_price ); ?></span>
								<?php endif; ?>
								
								<span class="aebg-stock-status aebg-in-stock">
									<span><?php echo esc_html__( 'På lager', 'aebg' ); ?></span>
								</span>
								
								<?php if ( ! empty( $cta_badge_text ) ) : ?>
									<span class="aebg-cta"><?php echo esc_html( $cta_badge_text ); ?></span>
								<?php endif; ?>
							</span>
						</div>
						
						<!-- Buy button / Dealer (hidden on mobile, shown on desktop) -->
						<?php if ( ! empty( $affiliate_url ) ) : ?>
							<div class="aebg-col aebg-px-1 aebg-d-none aebg-offer aebg-d-md-flex aebg-justify-content-center aebg-order-sm-4 aebg-order-3">
								<span class="aebg-dealer-btn aebg-d-flex aebg-align-items-center aebg-f-5 aebg-fb">
									* <?php echo esc_html__( 'Køb hos', 'aebg' ); ?>
									<?php if ( ! empty( $merchant_logo_url ) ) : ?>
										<span class="aebg-ml-1">
											<img src="<?php echo esc_url( $merchant_logo_url ); ?>" alt="<?php echo esc_attr( $merchant_name ); ?>" width="86" height="26" loading="lazy" />
										</span>
									<?php elseif ( ! empty( $merchant_name ) ) : ?>
										<span class="aebg-ml-1"><?php echo esc_html( $merchant_name ); ?></span>
									<?php endif; ?>
								</span>
							</div>
						<?php endif; ?>
						
						
				<?php else : ?>
					<div <?php $this->print_render_attribute_string( $item_key ); ?>>
						<?php if ( $is_modern_card && ! empty( $badge_data ) ) : ?>
							<?php echo $this->render_badge( $badge_data, $settings ); ?>
						<?php endif; ?>

						<?php if ( ! empty( $image_url ) ) : ?>
							<div class="aebg-product-image">
								<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $product['name'] ?? '' ); ?>" loading="lazy" />
							</div>
						<?php endif; ?>

						<div class="aebg-product-content">
							<?php if ( ! empty( $product['name'] ) ) : ?>
								<h3 class="aebg-product-title"><?php echo esc_html( $product['name'] ); ?></h3>
							<?php endif; ?>

							<?php if ( $settings['show_discount'] === 'yes' && ! empty( $discount_text ) ) : ?>
								<div class="aebg-product-discount"><?php echo esc_html( $discount_text ); ?></div>
							<?php endif; ?>

							<a href="<?php echo esc_attr( $review_link_href ); ?>" class="aebg-product-review-link">
								<?php echo esc_html( $review_link_text ); ?>
							</a>
						</div>

						<?php if ( ! $is_modern_card && ! empty( $badge_data ) ) : ?>
							<?php echo $this->render_badge( $badge_data, $settings ); ?>
						<?php endif; ?>
				<?php endif; ?>

					<?php if ( $is_numbered ) : ?>
					</li>
				<?php else : ?>
					</div>
				<?php endif; ?>
			<?php endforeach; ?>
		</<?php echo esc_attr( $wrapper_tag ); ?>>
		<?php
	}

	/**
	 * Render product list widget output in the editor.
	 *
	 * Written as a Backbone JavaScript template and used to generate the live preview.
	 *
	 * @since 2.9.0
	 * @access protected
	 */
	protected function content_template() {
		?>
		<#
		var presetStyle = settings.preset_style || 'numbered-list';
		var isNumbered = presetStyle === 'numbered-list';
		var isModernCard = presetStyle === 'modern-card';
		var wrapperTag = isNumbered ? 'ol' : 'div';
		var reviewLinkText = settings.review_link_text || 'LÆS ANMELDELSEN';
		#>
		<{{ wrapperTag }} class="aebg-product-list aebg-product-list--preset-{{ presetStyle }}">
			<# if ( isNumbered ) { #>
				<li class="aebg-product-item aebg-row aebg-align-items-center">
					<a href="#" class="aebg-fi-xd aebg-z6" aria-label="<?php esc_attr_e( 'Gå til produkt', 'aebg' ); ?>"></a>
					
					<span class="aebg-promotion-line">
						<span class="aebg-winner">
							<span><?php esc_html_e( 'Vi anbefaler', 'aebg' ); ?></span>
						</span>
					</span>
					
					<div class="aebg-col-auto aebg-order-sm-1 aebg-order-1">
						<span class="aebg-num aebg-lbb">1</span>
					</div>
					
					<div class="aebg-col-auto aebg-col-md-auto aebg-order-sm-2 aebg-order-2 aebg-img">
						<picture class="aebg-sm-al-img">
							<img src="https://via.placeholder.com/64" alt="Product" width="64" height="64" />
						</picture>
					</div>
					
					<div class="aebg-col aebg-col-md-5 aebg-order-4 aebg-order-sm-3 aebg-desc-r">
						<span class="aebg-d-block aebg-overflow-txt"><?php esc_html_e( 'Sample Product Name', 'aebg' ); ?></span>
						<span class="aebg-d-flex aebg-mo-r-inf-o">
							<span>Set til 4.999 kr.</span>
							<span class="aebg-stock-status aebg-in-stock">
								<span><?php esc_html_e( 'På lager', 'aebg' ); ?></span>
							</span>
							<# if ( settings.show_badges === 'yes' ) { #>
								<span class="aebg-cta"><?php esc_html_e( 'Gulvvask', 'aebg' ); ?></span>
							<# } #>
						</span>
					</div>
					
					<div class="aebg-col aebg-px-1 aebg-d-none aebg-offer aebg-d-md-flex aebg-justify-content-center aebg-order-sm-4 aebg-order-3">
						<span class="aebg-dealer-btn aebg-d-flex aebg-align-items-center aebg-f-5 aebg-fb">
							* <?php esc_html_e( 'Køb hos', 'aebg' ); ?>
							<span class="aebg-ml-1">Merchant</span>
						</span>
					</div>
			<# } else { #>
				<div class="aebg-product-item">
					<# if ( isModernCard && settings.show_badges === 'yes' ) { #>
						<div class="aebg-product-badge"><?php esc_html_e( 'BEDST I TEST - 9,9/10', 'aebg' ); ?></div>
					<# } #>

					<div class="aebg-product-image">
						<img src="https://via.placeholder.com/200" alt="Product" />
					</div>

					<div class="aebg-product-content">
						<h3 class="aebg-product-title"><?php esc_html_e( 'Sample Product Name', 'aebg' ); ?></h3>
						
						<# if ( settings.show_discount === 'yes' ) { #>
							<div class="aebg-product-discount"><?php esc_html_e( 'Spar 26.000 kr lige nu!', 'aebg' ); ?></div>
						<# } #>
						<a href="#p1" class="aebg-product-review-link">{{{ reviewLinkText }}}</a>
					</div>

					<# if ( ! isModernCard && settings.show_badges === 'yes' ) { #>
						<div class="aebg-product-badge"><?php esc_html_e( 'BEDST I TEST - 9,9/10', 'aebg' ); ?></div>
					<# } #>
			<# } #>

			<# if ( isNumbered ) { #>
				</li>
			<# } else { #>
				</div>
			<# } #>
		</{{ wrapperTag }}>
		<?php
	}
}
