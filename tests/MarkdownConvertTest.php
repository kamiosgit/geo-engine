<?php
/**
 * Markdown rendering and index-description cleanup.
 *
 * @package GEO_Engine
 */

use PHPUnit\Framework\TestCase;

/**
 * Class MarkdownConvertTest
 */
class MarkdownConvertTest extends TestCase {

	/**
	 * List items must carry exactly one bullet.
	 *
	 * @return void
	 */
	public function test_unordered_list_has_single_bullet() {
		$html = '<ul><li><strong>课程名称</strong>：起个名字；</li><li>购买次数：0 则仅展示</li></ul>';
		$md   = GEO_LLMS_Feed::html_to_markdown( $html );

		$this->assertStringContainsString( '- **课程名称**：起个名字；', $md );
		$this->assertStringContainsString( '- 购买次数：0 则仅展示', $md );
		$this->assertStringNotContainsString( '- - ', $md );
	}

	/**
	 * Ordered lists keep their numbers, once.
	 *
	 * @return void
	 */
	public function test_ordered_list_numbers_once() {
		$md = GEO_LLMS_Feed::html_to_markdown( '<ol><li>第一步</li><li>第二步</li></ol>' );

		$this->assertStringContainsString( '1. 第一步', $md );
		$this->assertStringContainsString( '2. 第二步', $md );
		$this->assertStringNotContainsString( '1. - ', $md );
	}

	/**
	 * A paragraph inside an item is flattened into the same line.
	 *
	 * @return void
	 */
	public function test_paragraph_inside_item_is_flattened() {
		$md = GEO_LLMS_Feed::html_to_markdown( '<ul><li><p>会员卡号可随机生成</p></li></ul>' );

		$this->assertStringContainsString( '- 会员卡号可随机生成', $md );
		$this->assertStringNotContainsString( "\n\n", trim( $md ) );
	}

	/**
	 * Page-builder shortcodes never leak into an index description.
	 *
	 * @return void
	 */
	public function test_description_strips_page_builder_shortcodes() {
		$post = new WP_Post(
			array(
				'post_excerpt' => '',
				'post_content' => '[vc_section el_class="pricing-area"][vc_column_text]卡米星校教培机构运营管理系统，提供排课与学员管理。[/vc_column_text][/vc_section]',
			)
		);

		$desc = GEO_LLMS_Feed::entry_description( $post );

		$this->assertStringContainsString( '卡米星校教培机构运营管理系统', $desc );
		$this->assertStringNotContainsString( '[', $desc );
		$this->assertStringNotContainsString( 'vc_section', $desc );
	}

	/**
	 * A description that is nothing but a shortcode is dropped.
	 *
	 * @return void
	 */
	public function test_description_is_empty_when_only_shortcode() {
		$post = new WP_Post(
			array(
				'post_excerpt' => '',
				'post_content' => '[newsletter]',
			)
		);

		$this->assertSame( '', GEO_LLMS_Feed::entry_description( $post ) );
	}

	/**
	 * The index advertises a URL pattern, not one arbitrary article.
	 *
	 * @return void
	 */
	public function test_llms_txt_shows_markdown_pattern() {
		$post = new WP_Post(
			array(
				'ID'           => 5,
				'post_title'   => '示例',
				'post_name'    => 'sample',
				'post_type'    => 'post',
				'post_excerpt' => '摘要',
				'post_content' => '<p>正文</p>',
			)
		);

		$out = GEO_LLMS_Feed::build_llms( array( $post ), '站点', '描述' );

		$this->assertStringContainsString( '/geo-md/{post_type}/{slug}.md', $out );
	}
}
