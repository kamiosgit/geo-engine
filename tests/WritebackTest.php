<?php
/**
 * Write path: block building, insertion, replacement, stripping.
 *
 * @package GEO_Engine
 */

use PHPUnit\Framework\TestCase;

/**
 * Class WritebackTest
 */
class WritebackTest extends TestCase {

	/**
	 * Sample items.
	 *
	 * @return array
	 */
	private function items() {
		return array(
			array(
				'q' => '价格是多少？',
				'a' => '按年付费。',
			),
			array(
				'q' => '支持试用吗？',
				'a' => '支持 14 天试用。',
			),
		);
	}

	/**
	 * The block uses the documented shortcode format.
	 *
	 * @return void
	 */
	public function test_build_block_format() {
		$block = GEO_Writeback::build_block( $this->items() );

		$expected = "[geo_faqs]\n"
			. "[geo_faq q=\"价格是多少？\"]按年付费。[/geo_faq]\n"
			. "[geo_faq q=\"支持试用吗？\"]支持 14 天试用。[/geo_faq]\n"
			. '[/geo_faqs]';

		$this->assertSame( $expected, $block );
	}

	/**
	 * Quotes and brackets are escaped so parsing stays intact.
	 *
	 * @return void
	 */
	public function test_encode_attr_escapes_dangerous_characters() {
		$encoded = GEO_Writeback::encode_attr( '他说"可以"，见[文档]' );

		$this->assertSame( '他说&quot;可以&quot;，见&#91;文档&#93;', $encoded );
		$this->assertStringNotContainsString( ']', $encoded );
	}

	/**
	 * A round trip through encode/parse must preserve the original text.
	 *
	 * @return void
	 */
	public function test_encode_then_parse_roundtrip() {
		$question = '为什么选择"方案 A"？见[说明]';

		$block = GEO_Writeback::build_block(
			array(
				array( 'q' => $question, 'a' => '因为它更合适。' ),
				array( 'q' => '还有别的原因吗？', 'a' => '没有别的了。' ),
			)
		);

		$parsed = GEO_Shortcode::parse_items( $block );

		$this->assertCount( 2, $parsed );
		$this->assertSame( $question, $parsed[0]['question'] );
	}

	/**
	 * Appending to a post without FAQ.
	 *
	 * @return void
	 */
	public function test_apply_appends_when_missing() {
		$result = GEO_Writeback::apply( "原有正文。\n", $this->items() );

		$this->assertStringStartsWith( '原有正文。', $result );
		$this->assertStringContainsString( '[geo_faqs]', $result );
		$this->assertSame( 1, preg_match_all( '/\[geo_faqs\]/', $result ) );
	}

	/**
	 * Existing wrapper: new items go before the closing tag, wrapper is reused.
	 *
	 * @return void
	 */
	public function test_apply_inserts_into_existing_wrapper() {
		$content = "前言。\n\n[geo_faqs]\n[geo_faq q=\"旧问题？\"]旧答案。[/geo_faq]\n[/geo_faqs]";

		$result = GEO_Writeback::apply(
			$content,
			array(
				array(
					'q' => '新问题？',
					'a' => '新答案。',
				),
			)
		);

		$this->assertSame( 1, preg_match_all( '/\[geo_faqs\]/', $result ) );
		$this->assertStringContainsString( '旧问题？', $result );
		$this->assertStringContainsString( '新问题？', $result );
		$this->assertLessThan( strpos( $result, '[/geo_faqs]' ), strpos( $result, '新问题？' ) );
	}

	/**
	 * Replace mode keeps exactly one wrapper and drops the old items.
	 *
	 * @return void
	 */
	public function test_apply_replaces_existing_block() {
		$content = "前言。\n\n[geo_faqs]\n[geo_faq q=\"旧问题？\"]旧答案。[/geo_faq]\n[/geo_faqs]\n\n后记。";

		$result = GEO_Writeback::apply( $content, $this->items(), array( 'replace' => true ) );

		$this->assertSame( 1, preg_match_all( '/\[geo_faqs\]/', $result ) );
		$this->assertSame( 1, preg_match_all( '/\[\/geo_faqs\]/', $result ) );
		$this->assertStringNotContainsString( '旧问题？', $result );
		$this->assertStringContainsString( '价格是多少？', $result );
		$this->assertStringContainsString( '前言。', $result );
		$this->assertStringContainsString( '后记。', $result );
	}

	/**
	 * Block-editor posts get the shortcode block delimiters.
	 *
	 * @return void
	 */
	public function test_apply_uses_editor_delimiters_for_block_posts() {
		$content = "<!-- wp:paragraph -->\n<p>正文</p>\n<!-- /wp:paragraph -->";

		$result = GEO_Writeback::apply( $content, $this->items() );

		$this->assertStringContainsString( '<!-- wp:shortcode -->', $result );
		$this->assertStringContainsString( '<!-- /wp:shortcode -->', $result );
	}

	/**
	 * Stripping removes the block and leaves the rest.
	 *
	 * @return void
	 */
	public function test_strip_blocks() {
		$content = "保留这段。\n\n[geo_faqs]\n[geo_faq q=\"Q？\"]A。[/geo_faq]\n[/geo_faqs]\n";

		$result = GEO_Writeback::strip_blocks( $content );

		$this->assertStringNotContainsString( 'geo_faq', $result );
		$this->assertStringContainsString( '保留这段。', $result );
	}

	/**
	 * Item-only output never includes the wrapper.
	 *
	 * @return void
	 */
	public function test_build_items_only() {
		$items_only = GEO_Writeback::build_items_only( $this->items() );

		$this->assertStringNotContainsString( '[geo_faqs]', $items_only );
		$this->assertSame( 2, preg_match_all( '/\[geo_faq /', $items_only ) );
	}
}
