<?php
/**
 * FAQ generation: input building, prompt, response parsing and validation.
 *
 * @package GEO_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns a post into validated question/answer pairs using the configured LLM.
 */
class GEO_FAQ_Generator {

	/**
	 * Maximum characters of body text sent to the model.
	 */
	const CONTENT_LIMIT = 2500;

	/**
	 * Hard minimum number of items. Below this nothing is written back.
	 */
	const MIN_ITEMS = 2;

	/**
	 * Built-in prompt template. Editable from the settings screen.
	 *
	 * @return string
	 */
	public static function default_prompt_template() {
		return "你是资深内容编辑，负责为已有文章生成结构化 FAQ（问答对）。\n\n"
			. "【硬性规则】\n"
			. "1. 只能依据下面提供的文章内容提问与作答，不得引入原文没有的事实、数字、案例、承诺或外部知识。\n"
			. "2. 问题必须是真实用户会自然提出的问句，覆盖文章的核心主题；不要问「这篇文章讲了什么」这类元问题。\n"
			. "3. 答案 40–120 字，直接给结论。不要出现「根据文章」「如上所述」「本文」等元叙述。\n"
			. "4. 问题之间互不重复，也不要与「已有 FAQ」重复。\n"
			. "5. 只输出 JSON 对象，不要解释、不要 Markdown 代码块、不要任何前后缀。\n"
			. "6. 格式严格为：{\"faqs\":[{\"question\":\"问题\",\"answer\":\"答案\"}]}\n"
			. "7. 生成 {{count}} 条，不得少于 2 条；答案中使用与原文一致的术语。\n\n"
			. "【站点】{{site}}\n"
			. "【标题】{{title}}\n"
			. "【小标题】\n{{headings}}\n\n"
			. "【已有 FAQ】（不要重复）\n{{existing}}\n\n"
			. "【正文】\n{{content}}\n";
	}

	/**
	 * System message. Repeated JSON instruction is required by providers that
	 * enforce response_format=json_object.
	 *
	 * @return string
	 */
	public static function system_message() {
		return '你是严谨的内容结构化编辑。你只输出一个 JSON 对象，不输出任何解释、代码块或额外文本。'
			. 'JSON 结构必须是 {"faqs":[{"question":"...","answer":"..."}]}。';
	}

	/**
	 * Strip markup and normalise whitespace.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function to_plain_text( $text ) {
		$text = (string) $text;
		$text = preg_replace( '/<(script|style)[^>]*>.*?<\/\1>/is', ' ', $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/[ \t\x{00a0}]+/u', ' ', $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", (string) $text );

		return trim( (string) $text );
	}

	/**
	 * Cut a string without breaking multibyte characters.
	 *
	 * @param string $text  Input.
	 * @param int    $limit Character limit.
	 * @return string
	 */
	public static function truncate( $text, $limit ) {
		$limit = (int) $limit;
		$text  = (string) $text;

		if ( $limit <= 0 ) {
			return '';
		}

		if ( function_exists( 'mb_substr' ) ) {
			return mb_strlen( $text ) > $limit ? mb_substr( $text, 0, $limit ) . '…' : $text;
		}

		return strlen( $text ) > $limit ? substr( $text, 0, $limit ) . '…' : $text;
	}

	/**
	 * Extract H2/H3 texts used as topical anchors.
	 *
	 * @param string $html Rendered content.
	 * @return array<int,string>
	 */
	public static function extract_headings( $html ) {
		$out = array();

		if ( preg_match_all( '/<h([23])\b[^>]*>(.*?)<\/h\1>/is', (string) $html, $matches ) ) {
			foreach ( $matches[2] as $heading ) {
				$heading = self::to_plain_text( $heading );

				if ( '' !== $heading ) {
					$out[] = $heading;
				}
			}
		}

		return array_slice( array_values( array_unique( $out ) ), 0, 12 );
	}

	/**
	 * Plain-text body of a post, ready to send to the model.
	 *
	 * @param WP_Post|int $post Post object or ID.
	 * @return string
	 */
	public static function build_body_text( $post ) {
		$post = get_post( $post );

		if ( ! $post ) {
			return '';
		}

		$content = (string) $post->post_content;
		$content = strip_shortcodes( $content );

		if ( function_exists( 'do_blocks' ) ) {
			$content = do_blocks( $content );
		}

		return self::to_plain_text( $content );
	}

	/**
	 * Char count of the plain body, used by the "too short" skip rule.
	 *
	 * @param WP_Post|int $post Post object or ID.
	 * @return int
	 */
	public static function content_length( $post ) {
		$text = self::build_body_text( $post );

		return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
	}

	/**
	 * Questions already present in the body (our shortcode or SEO plugin blocks).
	 *
	 * @param WP_Post|int $post Post object or ID.
	 * @return array<int,string>
	 */
	public static function existing_questions( $post ) {
		$post = get_post( $post );

		if ( ! $post ) {
			return array();
		}

		$items = GEO_Shortcode::parse_items( (string) $post->post_content );
		$out   = array();

		foreach ( $items as $item ) {
			$out[] = $item['question'];
		}

		return $out;
	}

	/**
	 * Build the neutral message list for the model.
	 *
	 * @param WP_Post $post     Post.
	 * @param int     $count    Requested item count.
	 * @param array   $existing Existing questions.
	 * @return array<int,array<string,string>>
	 */
	public static function build_messages( $post, $count, array $existing = array() ) {
		$template = (string) GEO_Settings::get( 'prompt_template', '' );

		if ( '' === trim( $template ) ) {
			$template = self::default_prompt_template();
		}

		$html     = (string) $post->post_content;
		$headings = self::extract_headings( $html );
		$body     = self::truncate( self::build_body_text( $post ), self::CONTENT_LIMIT );

		$replacements = array(
			'{{title}}'    => (string) get_the_title( $post ),
			'{{content}}'  => $body,
			'{{headings}}' => $headings ? '- ' . implode( "\n- ", $headings ) : '（无）',
			'{{existing}}' => $existing ? '- ' . implode( "\n- ", $existing ) : '（无）',
			'{{count}}'    => (string) max( self::MIN_ITEMS, (int) $count ),
			'{{site}}'     => get_bloginfo( 'name' ),
		);

		$user = strtr( $template, $replacements );

		return array(
			array(
				'role'    => 'system',
				'content' => self::system_message(),
			),
			array(
				'role'    => 'user',
				'content' => $user,
			),
		);
	}

	/**
	 * Extract the raw item list from a model response.
	 *
	 * Tolerates code fences, prose around the JSON and the several shapes
	 * providers actually return in practice.
	 *
	 * @param string $raw Raw model output.
	 * @return array<int,mixed>|WP_Error
	 */
	public static function parse_json( $raw ) {
		$raw = (string) $raw;
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return new WP_Error( 'empty_response', __( '模型返回了空内容。', 'geo-engine' ) );
		}

		$candidates = array( $raw );

		if ( preg_match( '/```(?:json|JSON)?\s*(.+?)\s*```/s', $raw, $match ) ) {
			$candidates[] = trim( $match[1] );
		}

		$start = strpos( $raw, '{' );
		$end   = strrpos( $raw, '}' );

		if ( false !== $start && false !== $end && $end > $start ) {
			$candidates[] = substr( $raw, $start, $end - $start + 1 );
		}

		$start = strpos( $raw, '[' );
		$end   = strrpos( $raw, ']' );

		if ( false !== $start && false !== $end && $end > $start ) {
			$candidates[] = substr( $raw, $start, $end - $start + 1 );
		}

		foreach ( $candidates as $candidate ) {
			$decoded = json_decode( $candidate, true );

			if ( ! is_array( $decoded ) ) {
				continue;
			}

			$items = self::locate_items( $decoded );

			if ( null !== $items ) {
				return $items;
			}
		}

		return new WP_Error( 'invalid_json', __( '模型返回的内容不是合法 JSON 结构。', 'geo-engine' ) );
	}

	/**
	 * Find a list of {question, answer} pairs inside a decoded payload.
	 *
	 * @param array<mixed> $decoded Decoded JSON.
	 * @return array<int,mixed>|null
	 */
	private static function locate_items( array $decoded ) {
		if ( self::is_item_list( $decoded ) ) {
			return array_values( $decoded );
		}

		$keys = array( 'faqs', 'faq', 'items', 'data', 'result', 'results' );

		foreach ( $keys as $key ) {
			if ( ! isset( $decoded[ $key ] ) ) {
				continue;
			}

			if ( is_array( $decoded[ $key ] ) ) {
				if ( self::is_item_list( $decoded[ $key ] ) ) {
					return array_values( $decoded[ $key ] );
				}

				$nested = self::locate_items( $decoded[ $key ] );

				if ( null !== $nested ) {
					return $nested;
				}
			}
		}

		return null;
	}

	/**
	 * Is this a list of Q/A arrays?
	 *
	 * @param array<mixed> $value Candidate.
	 * @return bool
	 */
	private static function is_item_list( array $value ) {
		if ( empty( $value ) ) {
			return false;
		}

		$first = reset( $value );

		if ( ! is_array( $first ) ) {
			return false;
		}

		return isset( $first['question'] ) || isset( $first['q'] ) || isset( $first['Q'] );
	}

	/**
	 * Normalise, validate and de-duplicate items.
	 *
	 * @param array<int,mixed> $items    Raw items.
	 * @param array<int,string> $existing Existing questions to avoid repeating.
	 * @return array<int,array{q:string,a:string}>|WP_Error
	 */
	public static function normalize_items( array $items, array $existing = array() ) {
		$seen = array();

		foreach ( $existing as $question ) {
			$key = self::dedupe_key( $question );

			if ( '' !== $key ) {
				$seen[ $key ] = true;
			}
		}

		$out = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$question = '';
			$answer   = '';

			foreach ( array( 'question', 'q', 'Q', 'title' ) as $key ) {
				if ( isset( $item[ $key ] ) && is_scalar( $item[ $key ] ) ) {
					$question = (string) $item[ $key ];
					break;
				}
			}

			foreach ( array( 'answer', 'a', 'A', 'content', 'response' ) as $key ) {
				if ( isset( $item[ $key ] ) && is_scalar( $item[ $key ] ) ) {
					$answer = (string) $item[ $key ];
					break;
				}
			}

			$question = self::clean( $question );
			$answer   = self::clean( $answer );

			if ( '' === $question || '' === $answer ) {
				continue;
			}

			if ( self::text_length( $question ) < 4 || self::text_length( $answer ) < 5 ) {
				continue;
			}

			$question = self::truncate( $question, 200 );
			$answer   = self::truncate( $answer, 600 );

			$key = self::dedupe_key( $question );

			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;

			$out[] = array(
				'q' => $question,
				'a' => $answer,
			);
		}

		if ( count( $out ) < self::MIN_ITEMS ) {
			return new WP_Error(
				'insufficient_items',
				sprintf(
					/* translators: %d: minimum item count. */
					__( '可用问答不足 %d 条，已放弃写回（原文未改动）。', 'geo-engine' ),
					self::MIN_ITEMS
				)
			);
		}

		return $out;
	}

	/**
	 * Clean one field.
	 *
	 * @param string $text Raw value.
	 * @return string
	 */
	public static function clean( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = strip_shortcodes( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/[ \t\x{00a0}]+/u', ' ', (string) $text );
		$text = preg_replace( '/\s*\n\s*/u', ' ', (string) $text );

		return trim( (string) $text );
	}

	/**
	 * Length helper that prefers multibyte-safe counting.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function text_length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text ) : strlen( (string) $text );
	}

	/**
	 * Comparison key: case and punctuation insensitive.
	 *
	 * @param string $question Question text.
	 * @return string
	 */
	public static function dedupe_key( $question ) {
		$key = self::clean( $question );
		$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $key, 'UTF-8' ) : strtolower( $key );
		$key = preg_replace( '/[\s\p{P}\p{S}]+/u', '', $key );

		return (string) $key;
	}

	/**
	 * One-line diagnostic tail appended to a failure message.
	 *
	 * The log table is the only place a support conversation can look at, so
	 * the numbers that separate "the model is slow" from "the prompt is huge"
	 * ride along with the error text: prompt size, output ceiling, wall clock
	 * and the model that was actually called.
	 *
	 * @param array<int,array<string,string>> $messages Messages sent.
	 * @param array<string,mixed>             $result   Failure result.
	 * @return string
	 */
	private static function failure_digest( array $messages, array $result ) {
		$prompt  = isset( $messages[1]['content'] ) ? (string) $messages[1]['content'] : '';
		$chars   = function_exists( 'mb_strlen' ) ? mb_strlen( $prompt, 'UTF-8' ) : strlen( $prompt );
		$latency = isset( $result['latency'] ) ? (int) $result['latency'] : 0;
		$model   = (string) GEO_Settings::get( 'model', '' );
		$ceiling = (int) GEO_Settings::get( 'max_tokens', 1500 );

		if ( '' === $model ) {
			$model = '?';
		}

		return sprintf(
			/* translators: 1: model name, 2: prompt character count, 3: max tokens, 4: elapsed seconds. */
			__( '（模型 %1$s，输入 %2$s 字，上限 %3$d tokens，耗时 %4$s 秒）', 'geo-engine' ),
			$model,
			number_format_i18n( (int) $chars ),
			$ceiling,
			number_format( $latency / 1000, 1 )
		);
	}

	/**
	 * Run generation for one post.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $args    Optional: action, force, timeout, max_retries, retry_on_parse.
	 * @return array<string,mixed>|WP_Error
	 */
	public function generate( $post_id, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'force'          => false,
				'timeout'        => 0,
				'max_retries'    => -1,
				'retry_on_parse' => true,
			)
		);

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'missing_post', __( '文章不存在。', 'geo-engine' ) );
		}

		$existing = self::existing_questions( $post );
		$count    = max( self::MIN_ITEMS, min( 5, (int) GEO_Settings::get( 'faq_count', 3 ) ) );
		$messages = self::build_messages( $post, $count, $existing );

		// The batch runner passes a per-request timeout so one slow article can
		// never stretch a single admin-ajax call past the gateway limit (which
		// used to surface as a blank 504 page and a generic "network error").
		$overrides = array();

		if ( (int) $args['timeout'] > 0 ) {
			$overrides['timeout'] = (int) $args['timeout'];
		}

		if ( (int) $args['max_retries'] >= 0 ) {
			$overrides['max_retries'] = (int) $args['max_retries'];
		}

		$client = new GEO_LLM_Client();
		$result = $client->chat( $messages, $overrides );

		$tokens = isset( $result['tokens'] ) ? (int) $result['tokens'] : 0;

		if ( empty( $result['ok'] ) ) {
			// Attach the numbers that decide whether this is a model problem or
			// a timeout problem: prompt size, output ceiling, wall clock. It
			// lands in the log row, so a screenshot is enough to diagnose it.
			$message = (string) $result['message'];
			$digest  = self::failure_digest( $messages, $result );

			if ( '' !== $digest ) {
				$message .= ' ' . $digest;
			}

			return new WP_Error( $result['code'], $message, array( 'tokens' => $tokens ) );
		}

		$items = self::parse_json( $result['content'] );

		// One stricter retry when the model answered with prose or broken JSON.
		// Inside a batch there is no time budget for a second round trip, so the
		// caller can turn this off and the article is simply retried later.
		if ( is_wp_error( $items ) && ! empty( $args['retry_on_parse'] ) ) {
			$retry_messages   = $messages;
			$retry_messages[] = array(
				'role'    => 'user',
				'content' => '上一次输出无法解析。请只输出 JSON 对象，形如 {"faqs":[{"question":"...","answer":"..."}]}，不要任何其他文字。',
			);

			$retry = $client->chat( $retry_messages, array_merge( $overrides, array( 'temperature' => 0 ) ) );

			if ( ! empty( $retry['ok'] ) ) {
				$tokens += (int) $retry['tokens'];
				$items   = self::parse_json( $retry['content'] );
			}
		}

		if ( is_wp_error( $items ) ) {
			$items->add_data( array( 'tokens' => $tokens ) );

			return $items;
		}

		$normalized = self::normalize_items( $items, $existing );

		if ( is_wp_error( $normalized ) ) {
			$normalized->add_data( array( 'tokens' => $tokens ) );

			return $normalized;
		}

		return array(
			'items'   => array_slice( $normalized, 0, $count ),
			'tokens'  => $tokens,
			'usage'   => isset( $result['usage'] ) ? $result['usage'] : array(),
			'latency' => isset( $result['latency'] ) ? (int) $result['latency'] : 0,
		);
	}
}
