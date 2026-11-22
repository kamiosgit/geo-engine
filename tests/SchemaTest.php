<?php
/**
 * JSON-LD entity construction.
 *
 * @package GEO_Engine
 */

use PHPUnit\Framework\TestCase;

/**
 * Class SchemaTest
 */
class SchemaTest extends TestCase {

	/**
	 * Reset settings.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		update_option( GEO_Settings::OPTION, GEO_Settings::defaults() );
	}

	/**
	 * FAQPage exposes one Question per parsed item.
	 *
	 * @return void
	 */
	public function test_faq_page_maps_items() {
		$node = GEO_Schema::build_faq_page(
			array(
				array(
					'question' => '问题一？',
					'answer'   => '答案一。',
				),
				array(
					'question' => '问题二？',
					'answer'   => '答案二。',
				),
			),
			'https://example.test/a'
		);

		$this->assertSame( 'FAQPage', $node['@type'] );
		$this->assertSame( 'https://example.test/a#faq', $node['@id'] );
		$this->assertCount( 2, $node['mainEntity'] );
		$this->assertSame( 'Question', $node['mainEntity'][0]['@type'] );
		$this->assertSame( '问题一？', $node['mainEntity'][0]['name'] );
		$this->assertSame( '答案一。', $node['mainEntity'][0]['acceptedAnswer']['text'] );
		$this->assertSame( 'Answer', $node['mainEntity'][0]['acceptedAnswer']['@type'] );
	}

	/**
	 * No items means no schema at all (AC-10 inverse).
	 *
	 * @return void
	 */
	public function test_faq_page_returns_null_without_items() {
		$this->assertNull( GEO_Schema::build_faq_page( array(), 'https://example.test/a' ) );
	}

	/**
	 * Items with an empty side are dropped.
	 *
	 * @return void
	 */
	public function test_faq_page_skips_incomplete_items() {
		$node = GEO_Schema::build_faq_page(
			array(
				array(
					'question' => '',
					'answer'   => '答案。',
				),
				array(
					'question' => '有效问题？',
					'answer'   => '有效答案。',
				),
			),
			'https://example.test/a'
		);

		$this->assertCount( 1, $node['mainEntity'] );
	}

	/**
	 * Article uses BlogPosting for posts and Article for other types.
	 *
	 * @return void
	 */
	public function test_article_type_per_post_type() {
		$post    = new WP_Post(
			array(
				'ID'           => 7,
				'post_title'   => '文章标题',
				'post_name'    => 'post-seven',
				'post_type'    => 'post',
				'post_excerpt' => '摘要',
			)
		);
		$article = GEO_Schema::build_article( $post );

		$this->assertSame( 'BlogPosting', $article['@type'] );
		$this->assertSame( 'https://example.test/post-seven#article', $article['@id'] );
		$this->assertSame( 'https://example.test/post-seven#webpage', $article['mainEntityOfPage']['@id'] );
		$this->assertSame( '摘要', $article['description'] );
		$this->assertSame( 'Person', $article['author']['@type'] );

		$page = new WP_Post(
			array(
				'ID'         => 8,
				'post_title' => '页面标题',
				'post_name'  => 'page-eight',
				'post_type'  => 'page',
			)
		);

		$this->assertSame( 'Article', GEO_Schema::build_article( $page )['@type'] );
	}

	/**
	 * WebPage links back to the site entity.
	 *
	 * @return void
	 */
	public function test_webpage_links_to_website() {
		$post = new WP_Post(
			array(
				'ID'         => 3,
				'post_title' => '标题',
				'post_name'  => 'slug',
			)
		);

		$webpage = GEO_Schema::build_webpage( $post );

		$this->assertSame( 'WebPage', $webpage['@type'] );
		$this->assertSame( 'https://example.test/#website', $webpage['isPartOf']['@id'] );
	}

	/**
	 * Breadcrumb always starts at the site root.
	 *
	 * @return void
	 */
	public function test_breadcrumb_starts_at_home() {
		$post = new WP_Post(
			array(
				'ID'         => 4,
				'post_title' => '标题',
				'post_name'  => 'crumb',
			)
		);

		$breadcrumb = GEO_Schema::build_breadcrumb( $post );

		$this->assertSame( 'BreadcrumbList', $breadcrumb['@type'] );
		$this->assertSame( 1, $breadcrumb['itemListElement'][0]['position'] );
		$this->assertSame( 'https://example.test/', $breadcrumb['itemListElement'][0]['item'] );
		$this->assertSame( '标题', $breadcrumb['itemListElement'][1]['name'] );
	}

	/**
	 * ISO dates are UTC formatted.
	 *
	 * @return void
	 */
	public function test_iso_date() {
		$this->assertSame( '2026-01-01T02:00:00+00:00', GEO_Schema::iso_date( '2026-01-01 02:00:00' ) );
	}

	/**
	 * Organization falls back to the site name.
	 *
	 * @return void
	 */
	public function test_organization_fallback_name() {
		$organization = GEO_Schema::build_organization();

		$this->assertSame( 'Organization', $organization['@type'] );
		$this->assertSame( 'Example Site', $organization['name'] );
		$this->assertArrayNotHasKey( 'logo', $organization );
	}

	/**
	 * Explicit organization values are used and parsed.
	 *
	 * @return void
	 */
	public function test_organization_uses_configured_values() {
		update_option(
			GEO_Settings::OPTION,
			array_merge(
				GEO_Settings::defaults(),
				array(
					'org_name'    => '示例公司',
					'org_logo'    => 'https://example.test/logo.png',
					'org_same_as' => "https://x.test/a\n\nhttps://x.test/b",
				)
			)
		);

		$organization = GEO_Schema::build_organization();

		$this->assertSame( '示例公司', $organization['name'] );
		$this->assertSame( 'https://example.test/logo.png', $organization['logo']['url'] );
		$this->assertSame( array( 'https://x.test/a', 'https://x.test/b' ), $organization['sameAs'] );
	}

	/**
	 * Output modes are honoured.
	 *
	 * @return void
	 */
	public function test_should_output_modes() {
		$this->assertTrue( GEO_Schema::should_output( 'article' ) );

		update_option(
			GEO_Settings::OPTION,
			array_merge( GEO_Settings::defaults(), array( 'schema_article' => 'off' ) )
		);

		$this->assertFalse( GEO_Schema::should_output( 'article' ) );

		update_option(
			GEO_Settings::OPTION,
			array_merge( GEO_Settings::defaults(), array( 'schema_article' => 'force' ) )
		);

		$this->assertTrue( GEO_Schema::should_output( 'article' ) );
	}

	/**
	 * Encoded output cannot break out of the script tag.
	 *
	 * @return void
	 */
	public function test_json_cannot_close_script_tag() {
		$node = GEO_Schema::build_faq_page(
			array(
				array(
					'question' => '</script><script>alert(1)</script>',
					'answer'   => 'safe',
				),
			),
			'https://example.test/a'
		);

		$json = str_replace( '</', '<\/', (string) wp_json_encode( $node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

		$this->assertStringNotContainsString( '</script>', $json );
	}
}
