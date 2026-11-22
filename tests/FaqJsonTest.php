<?php
/**
 * Robust JSON extraction from model output.
 *
 * @package GEO_Engine
 */

use PHPUnit\Framework\TestCase;

/**
 * Class FaqJsonTest
 */
class FaqJsonTest extends TestCase {

	/**
	 * Plain JSON object.
	 *
	 * @return void
	 */
	public function test_parses_plain_object() {
		$items = GEO_FAQ_Generator::parse_json( '{"faqs":[{"question":"Q1？","answer":"A1。"}]}' );

		$this->assertIsArray( $items );
		$this->assertCount( 1, $items );
		$this->assertSame( 'Q1？', $items[0]['question'] );
	}

	/**
	 * Fenced JSON.
	 *
	 * @return void
	 */
	public function test_parses_fenced_json() {
		$raw = "```json\n{\"faqs\":[{\"question\":\"Q\",\"answer\":\"A\"}]}\n```";

		$items = GEO_FAQ_Generator::parse_json( $raw );

		$this->assertIsArray( $items );
		$this->assertSame( 'Q', $items[0]['question'] );
	}

	/**
	 * Prose before and after the payload.
	 *
	 * @return void
	 */
	public function test_parses_json_embedded_in_prose() {
		$raw = 'Sure! Here are the FAQs: {"faqs":[{"question":"Q","answer":"A"}]} Let me know if you need more.';

		$items = GEO_FAQ_Generator::parse_json( $raw );

		$this->assertIsArray( $items );
		$this->assertSame( 'Q', $items[0]['question'] );
	}

	/**
	 * A bare array is accepted too.
	 *
	 * @return void
	 */
	public function test_parses_root_array() {
		$items = GEO_FAQ_Generator::parse_json( '[{"question":"Q","answer":"A"},{"question":"Q2","answer":"A2"}]' );

		$this->assertIsArray( $items );
		$this->assertCount( 2, $items );
	}

	/**
	 * Alternate key names seen in the wild.
	 *
	 * @return void
	 */
	public function test_parses_alias_keys() {
		$items = GEO_FAQ_Generator::parse_json( '{"items":[{"q":"Q","a":"A"}]}' );

		$this->assertIsArray( $items );
		$this->assertSame( 'Q', $items[0]['q'] );
	}

	/**
	 * A BOM must not break decoding.
	 *
	 * @return void
	 */
	public function test_strips_bom() {
		$items = GEO_FAQ_Generator::parse_json( "\xEF\xBB\xBF" . '{"faqs":[{"question":"Q","answer":"A"}]}' );

		$this->assertIsArray( $items );
	}

	/**
	 * Non-JSON output is an error, never an exception.
	 *
	 * @return void
	 */
	public function test_non_json_returns_error() {
		$result = GEO_FAQ_Generator::parse_json( 'I cannot help with that.' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_json', $result->get_error_code() );
	}

	/**
	 * Empty output is a distinct error code.
	 *
	 * @return void
	 */
	public function test_empty_output_returns_error() {
		$result = GEO_FAQ_Generator::parse_json( "   \n " );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'empty_response', $result->get_error_code() );
	}

	/**
	 * JSON without any Q/A list is rejected.
	 *
	 * @return void
	 */
	public function test_json_without_items_returns_error() {
		$result = GEO_FAQ_Generator::parse_json( '{"summary":"no faqs here"}' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
