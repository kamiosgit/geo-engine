<?php
/**
 * Protocol driver contract.
 *
 * @package GEO_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * A driver knows how one wire protocol authenticates and formats a chat request.
 *
 * Business code (FAQ generator) never sees provider specifics: it hands over a
 * neutral message list and receives assistant text.
 */
interface GEO_LLM_Driver {

	/**
	 * Build the HTTP request description.
	 *
	 * @param array<int,array<string,string>> $messages Neutral message list.
	 * @param array<string,mixed>             $config   Resolved connection config.
	 * @return array{url:string,headers:array<string,string>,body:array<string,mixed>}
	 */
	public function build_request( array $messages, array $config );

	/**
	 * Turn a raw HTTP response into assistant text.
	 *
	 * @param int    $status HTTP status code.
	 * @param string $body   Raw response body.
	 * @return string|WP_Error
	 */
	public function parse_response( $status, $body );

	/**
	 * Whether this protocol understands response_format=json_object.
	 *
	 * @return bool
	 */
	public function supports_response_format();
}
