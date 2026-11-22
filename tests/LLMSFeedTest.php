<?php
/**
 * A layer output formatting.
 *
 * @package GEO_Engine
 */

use PHPUnit\Framework\TestCase;

/**
 * Class LLMSFeedTest
 */
class LLMSFeedTest extends TestCase {

	/**
	 * Build a post fixture.
	 *
	 * @param array $props Properties.
	 * @return WP_Post
	 */
	private function post( array $props = array() ) {
		return new WP_Post(
			array_merge(
				array(
					'ID'           => 1,
					'post_title'   => '示例文章',
					'post_name'    => 'sample',
					'post_type'    => 'post',
					'post_excerpt' => '',
					'post_content' => '<p>正文内容。</p>',
				),
				$props
			)
		);
	}

	/**
	 * llms.txt groups entries by post type.
	 *
	 * @return void
	 */
	public function test_build_llms_groups_by_type() {
		$posts = array(
			$this->post(
				array(
					'post_title'   => '文章一',
					'post_name'    => 'one',
					'post_excerpt' => '摘要一',
				)
			),
			$this->post(
				array(
					'ID'           => 2,
					'post_title'   => '页面一',
					'post_name'    => 'page-one',
					'post_type'    => 'page',
					'post_excerpt' => '摘要二',
				)
			),
		);

		$output = GEO_LLMS_Feed::build_llms( $posts, '示例站点', '站点描述' );

		$this->assertStringContainsString( '# 示例站点', $output );
		$this->assertStringContainsString( '> 站点描述', $output );
		$this->assertStringContainsString( '## 文章', $output );
		$this->assertStringContainsString( '## 页面', $output );
		$this->assertStringContainsString( '- [文章一](https://example.test/one): 摘要一', $output );
		$this->assertStringContainsString( '- [页面一](https://example.test/page-one): 摘要二', $output );
	}

	/**
	 * Empty excerpt falls back to the first N characters of the body.
	 *
	 * @return void
	 */
	public function test_build_llms_falls_back_to_body() {
		$output = GEO_LLMS_Feed::build_llms( array( $this->post() ), 'S' );

		$this->assertStringContainsString( '正文内容。', $output );
	}

	/**
	 * Square brackets in a title cannot break the link syntax.
	 *
	 * @return void
	 */
	public function test_escape_link_text() {
		$this->assertSame( '\\[草稿\\] 标题', GEO_LLMS_Feed::escape_link_text( '[草稿] 标题' ) );
	}

	/**
	 * The full index caps entries and says so.
	 *
	 * @return void
	 */
	public function test_build_llms_full_truncates_with_notice() {
		$posts = array(
			$this->post( array( 'ID' => 1, 'post_title' => 'A' ) ),
			$this->post( array( 'ID' => 2, 'post_title' => 'B' ) ),
		);

		$output = GEO_LLMS_Feed::build_llms_full( $posts, 'S', 'D', 500, 1 );

		$this->assertStringContainsString( '## A', $output );
		$this->assertStringNotContainsString( '## B', $output );
		$this->assertStringContainsString( 'Truncated at 1 entries', $output );
	}

	/**
	 * Body text is truncated per entry.
	 *
	 * @return void
	 */
	public function test_build_llms_full_truncates_body() {
		$long = str_repeat( '字', 400 );

		$output = GEO_LLMS_Feed::build_llms_full( array( $this->post( array( 'post_content' => $long ) ) ), 'S', '', 50, 10 );

		$this->assertStringContainsString( '…', $output );
		$this->assertStringNotContainsString( $long, $output );
	}

	/**
	 * Markdown conversion keeps headings, links and lists.
	 *
	 * @return void
	 */
	public function test_html_to_markdown() {
		if ( ! class_exists( 'DOMDocument' ) ) {
			$this->markTestSkipped( 'DOM extension not available.' );
		}

		$html = '<h2>小标题</h2><p>段落<a href="https://x.test/a">链接</a>结束。</p><ul><li>项目一</li><li>项目二</li></ul><blockquote>引用</blockquote>';

		$markdown = GEO_LLMS_Feed::html_to_markdown( $html );

		$this->assertStringContainsString( '## 小标题', $markdown );
		$this->assertStringContainsString( '[链接](https://x.test/a)', $markdown );
		$this->assertStringContainsString( '- 项目一', $markdown );
		$this->assertStringContainsString( '> 引用', $markdown );
		$this->assertStringNotContainsString( '<h2>', $markdown );
	}

	/**
	 * Scripts are dropped from the Markdown export.
	 *
	 * @return void
	 */
	public function test_html_to_markdown_drops_scripts() {
		if ( ! class_exists( 'DOMDocument' ) ) {
			$this->markTestSkipped( 'DOM extension not available.' );
		}

		$markdown = GEO_LLMS_Feed::html_to_markdown( '<p>可见</p><script>alert(1)</script><style>.a{}</style>' );

		$this->assertStringContainsString( '可见', $markdown );
		$this->assertStringNotContainsString( 'alert', $markdown );
		$this->assertStringNotContainsString( '.a{}', $markdown );
	}

	/**
	 * The Markdown document carries front matter and the FAQ block.
	 *
	 * @return void
	 */
	public function test_build_markdown_includes_faq() {
		update_option( GEO_Settings::OPTION, array_merge( GEO_Settings::defaults(), array( 'faq_heading' => '常见问题' ) ) );

		if ( ! class_exists( 'DOMDocument' ) ) {
			$this->markTestSkipped( 'DOM extension not available.' );
		}

		$post = $this->post(
			array(
				'post_title'   => '标题',
				'post_content' => '<p>正文。</p>[geo_faqs][geo_faq q="问题？"]答案。[/geo_faq][/geo_faqs]',
			)
		);

		$markdown = GEO_LLMS_Feed::build_markdown( $post );

		$this->assertStringContainsString( '# 标题', $markdown );
		$this->assertStringContainsString( '- URL: https://example.test/sample', $markdown );
		$this->assertStringContainsString( '## 常见问题', $markdown );
		$this->assertStringContainsString( '### 问题？', $markdown );
		$this->assertStringNotContainsString( '[geo_faqs]', $markdown );
	}
}
