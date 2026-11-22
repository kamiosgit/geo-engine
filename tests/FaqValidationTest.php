<?php
/**
 * Item validation, cleaning and de-duplication.
 *
 * @package GEO_Engine
 */

use PHPUnit\Framework\TestCase;

/**
 * Class FaqValidationTest
 */
class FaqValidationTest extends TestCase {

	/**
	 * Valid items are normalised to q/a keys.
	 *
	 * @return void
	 */
	public function test_normalises_valid_items() {
		$items = GEO_FAQ_Generator::normalize_items(
			array(
				array(
					'question' => '  问题一？  ',
					'answer'   => "答案一。\n第二行。",
				),
				array(
					'question' => '问题二？',
					'answer'   => '答案二。',
				),
			)
		);

		$this->assertIsArray( $items );
		$this->assertCount( 2, $items );
		$this->assertSame( '问题一？', $items[0]['q'] );
		$this->assertSame( '答案一。 第二行。', $items[0]['a'] );
	}

	/**
	 * Fewer than two usable items is an error: nothing may be written back.
	 *
	 * @return void
	 */
	public function test_single_item_is_rejected() {
		$result = GEO_FAQ_Generator::normalize_items(
			array(
				array(
					'question' => '只有一个？',
					'answer'   => '只有一个答案。',
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_items', $result->get_error_code() );
	}

	/**
	 * Duplicates of existing questions are dropped.
	 *
	 * @return void
	 */
	public function test_deduplicates_against_existing() {
		$items = GEO_FAQ_Generator::normalize_items(
			array(
				array(
					'question' => '价格是多少',
					'answer'   => '按年付费。',
				),
				array(
					'question' => '支持试用吗？',
					'answer'   => '支持 14 天试用。',
				),
				array(
					'question' => '如何部署？',
					'answer'   => '上传插件并启用。',
				),
			),
			array( '价格是多少？' )
		);

		$this->assertIsArray( $items );
		$this->assertCount( 2, $items );
		$this->assertSame( '支持试用吗？', $items[0]['q'] );
	}

	/**
	 * Duplicates inside the same response are dropped.
	 *
	 * @return void
	 */
	public function test_deduplicates_within_response() {
		$items = GEO_FAQ_Generator::normalize_items(
			array(
				array(
					'question' => '同一个问题？',
					'answer'   => '答案甲。',
				),
				array(
					'question' => '同一个问题',
					'answer'   => '答案乙。',
				),
				array(
					'question' => '另一个问题？',
					'answer'   => '答案丙。',
				),
			)
		);

		$this->assertIsArray( $items );
		$this->assertCount( 2, $items );
	}

	/**
	 * Markup and shortcodes are stripped from both fields.
	 *
	 * @return void
	 */
	public function test_strips_markup() {
		$items = GEO_FAQ_Generator::normalize_items(
			array(
				array(
					'question' => '<b>为什么</b>？',
					'answer'   => '<p>因为<em>如此</em>。</p>',
				),
				array(
					'question' => '还有呢？',
					'answer'   => '还有这些。',
				),
			)
		);

		$this->assertSame( '为什么？', $items[0]['q'] );
		$this->assertSame( '因为如此。', $items[0]['a'] );
	}

	/**
	 * Empty or too-short answers are dropped before the count check.
	 *
	 * @return void
	 */
	public function test_drops_degenerate_answers() {
		$items = GEO_FAQ_Generator::normalize_items(
			array(
				array(
					'question' => '有答案吗？',
					'answer'   => '好',
				),
				array(
					'question' => '空答案？',
					'answer'   => '   ',
				),
				array(
					'question' => '正常问题？',
					'answer'   => '正常答案。',
				),
				array(
					'question' => '第二个正常问题？',
					'answer'   => '第二个正常答案。',
				),
			)
		);

		$this->assertIsArray( $items );
		$this->assertCount( 2, $items );
	}

	/**
	 * The dedupe key ignores width, case and punctuation.
	 *
	 * @return void
	 */
	public function test_dedupe_key_normalisation() {
		$this->assertSame(
			GEO_FAQ_Generator::dedupe_key( 'How much is it?' ),
			GEO_FAQ_Generator::dedupe_key( 'how much is it' )
		);

		$this->assertSame(
			GEO_FAQ_Generator::dedupe_key( '价格是多少？' ),
			GEO_FAQ_Generator::dedupe_key( '价格是多少' )
		);
	}

	/**
	 * Truncation never splits a multibyte character.
	 *
	 * @return void
	 */
	public function test_truncate_is_multibyte_safe() {
		$text = str_repeat( '测', 10 );

		$result = GEO_FAQ_Generator::truncate( $text, 5 );

		$this->assertSame( '测测测测测…', $result );
		$this->assertSame( 'short', GEO_FAQ_Generator::truncate( 'short', 50 ) );
	}

	/**
	 * Headings become topical anchors for the prompt.
	 *
	 * @return void
	 */
	public function test_extract_headings() {
		$html = '<h2>第一段</h2><p>x</p><h3>子标题</h3><h4>忽略</h4><h2>第一段</h2>';

		$headings = GEO_FAQ_Generator::extract_headings( $html );

		$this->assertSame( array( '第一段', '子标题' ), $headings );
	}
}
