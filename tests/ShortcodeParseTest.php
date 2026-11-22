<?php
/**
 * Shortcode parsing and rendering.
 *
 * @package GEO_Engine
 */

use PHPUnit\Framework\TestCase;

/**
 * Class ShortcodeParseTest
 */
class ShortcodeParseTest extends TestCase {

	/**
	 * Standard two-item block.
	 *
	 * @return void
	 */
	public function test_parses_two_items() {
		$content = "[geo_faqs]\n[geo_faq q=\"价格是多少？\"]按年付费，具体见报价页。[/geo_faq]\n[geo_faq q=\"支持试用吗？\"]支持 14 天试用。[/geo_faq]\n[/geo_faqs]";

		$items = GEO_Shortcode::parse_items( $content );

		$this->assertCount( 2, $items );
		$this->assertSame( '价格是多少？', $items[0]['question'] );
		$this->assertSame( '按年付费，具体见报价页。', $items[0]['answer'] );
		$this->assertSame( '支持试用吗？', $items[1]['question'] );
	}

	/**
	 * An item without the q attribute is invalid and must be dropped.
	 *
	 * @return void
	 */
	public function test_item_without_question_is_dropped() {
		$content = "[geo_faqs][geo_faq]没有问题的答案[/geo_faq][geo_faq q=\"有效的？\"]有效答案。[/geo_faq][/geo_faqs]";

		$items = GEO_Shortcode::parse_items( $content );

		$this->assertCount( 1, $items );
		$this->assertSame( '有效的？', $items[0]['question'] );
	}

	/**
	 * HTML inside the answer is stripped.
	 *
	 * @return void
	 */
	public function test_answer_html_is_stripped() {
		$content = "[geo_faqs][geo_faq q=\"怎么用？\"]<strong>直接</strong>打开即可。<br />无需安装。[/geo_faq][/geo_faqs]";

		$items = GEO_Shortcode::parse_items( $content );

		$this->assertSame( '直接打开即可。无需安装。', $items[0]['answer'] );
	}

	/**
	 * Entities written by the writeback layer are decoded for output.
	 *
	 * @return void
	 */
	public function test_entities_are_decoded() {
		$content = '[geo_faqs][geo_faq q="支持 &quot;自定义&quot; 吗？"]支持。[/geo_faq][/geo_faqs]';

		$items = GEO_Shortcode::parse_items( $content );

		$this->assertSame( '支持 "自定义" 吗？', $items[0]['question'] );
	}

	/**
	 * Single quoted attributes are accepted.
	 *
	 * @return void
	 */
	public function test_single_quoted_attribute() {
		$atts = GEO_Shortcode::parse_atts( " q='带空格的 问题' id=3 " );

		$this->assertSame( '带空格的 问题', $atts['q'] );
		$this->assertSame( '3', $atts['id'] );
	}

	/**
	 * Wrapper balance detection.
	 *
	 * @return void
	 */
	public function test_is_balanced() {
		$balanced = "[geo_faqs][geo_faq q=\"A？\"]a[/geo_faq][geo_faq q=\"B？\"]b[/geo_faq][/geo_faqs]";
		$open     = "[geo_faqs][geo_faq q=\"A？\"]a[/geo_faq]";
		$double   = "[geo_faqs][geo_faq q=\"A？\"]a[/geo_faq][/geo_faqs][geo_faqs][/geo_faqs]";

		$this->assertTrue( GEO_Shortcode::is_balanced( $balanced ) );
		$this->assertFalse( GEO_Shortcode::is_balanced( $open ) );
		$this->assertFalse( GEO_Shortcode::is_balanced( $double ) );
	}

	/**
	 * has_block is a cheap pre-check.
	 *
	 * @return void
	 */
	public function test_has_block() {
		$this->assertTrue( GEO_Shortcode::has_block( 'text [geo_faqs] more' ) );
		$this->assertFalse( GEO_Shortcode::has_block( 'plain text' ) );
	}

	/**
	 * Rendering produces semantic, JS-free markup.
	 *
	 * @return void
	 */
	public function test_render_block_output() {
		update_option( GEO_Settings::OPTION, array_merge( GEO_Settings::defaults(), array( 'faq_heading' => '常见问题' ) ) );

		$html = GEO_Shortcode::render_block(
			array(
				array(
					'question' => '问题一？',
					'answer'   => '答案一。',
				),
			)
		);

		$this->assertStringContainsString( '<section class="geo-faq">', $html );
		$this->assertStringContainsString( '<h2 class="geo-faq-title">常见问题</h2>', $html );
		$this->assertStringContainsString( '<details class="geo-faq-item">', $html );
		$this->assertStringContainsString( '<summary class="geo-faq-q">问题一？</summary>', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	/**
	 * The content filter replaces the wrapper and leaves the rest untouched.
	 *
	 * @return void
	 */
	public function test_filter_content_preserves_surrounding_text() {
		update_option( GEO_Settings::OPTION, array_merge( GEO_Settings::defaults(), array( 'faq_heading' => '常见问题' ) ) );

		$shortcode = new GEO_Shortcode();

		$content = "开头段落。\n\n[geo_faqs][geo_faq q=\"A？\"]答案。[/geo_faq][/geo_faqs]\n\n结尾段落。";
		$result  = $shortcode->filter_content( $content );

		$this->assertStringContainsString( '开头段落。', $result );
		$this->assertStringContainsString( '结尾段落。', $result );
		$this->assertStringNotContainsString( '[geo_faqs]', $result );
		$this->assertStringContainsString( 'class="geo-faq"', $result );
	}
}
