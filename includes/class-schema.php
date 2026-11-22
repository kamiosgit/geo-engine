<?php
/**
 * JSON-LD output and SEO-plugin conflict avoidance.
 *
 * @package GEO_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Emits a single @graph block on every singular view, generated on the fly from
 * the post content, so no backfill or post meta is required.
 */
class GEO_Schema {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'wp_head', array( $this, 'output' ), 5 );
	}

	/**
	 * Detect active SEO plugins that already emit structured data.
	 *
	 * @return array<string,string>
	 */
	public static function seo_plugins() {
		$found = array();

		if ( defined( 'WPSEO_VERSION' ) ) {
			$found['yoast'] = 'Yoast SEO';
		}

		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			$found['rankmath'] = 'Rank Math';
		}

		if ( defined( 'AIOSEO_VERSION' ) ) {
			$found['aioseo'] = 'All in One SEO';
		}

		if ( defined( 'SEOPRESS_VERSION' ) ) {
			$found['seopress'] = 'SEOPress';
		}

		return $found;
	}

	/**
	 * Decide whether a type should be printed.
	 *
	 * @param string $type website|organization|article|webpage|breadcrumb|faq.
	 * @return bool
	 */
	public static function should_output( $type ) {
		$mode = (string) GEO_Settings::get( 'schema_' . $type, 'auto' );

		if ( 'off' === $mode ) {
			return false;
		}

		if ( 'force' === $mode ) {
			return true;
		}

		// auto: yield to SEO plugins for the types they already cover.
		if ( 'faq' === $type ) {
			return true;
		}

		$seo = self::seo_plugins();

		return empty( $seo );
	}

	/**
	 * ISO-8601 timestamp helper.
	 *
	 * @param string $local Local date string.
	 * @return string
	 */
	public static function iso_date( $local ) {
		$local = trim( (string) $local );

		if ( '' === $local || '0000-00-00 00:00:00' === $local ) {
			return gmdate( 'c' );
		}

		// Incoming values are already GMT (post_date_gmt / post_modified_gmt), so
		// they must not be reinterpreted in the site timezone.
		$timestamp = strtotime( $local . ' UTC' );

		if ( false === $timestamp ) {
			return gmdate( 'c' );
		}

		return gmdate( 'c', (int) $timestamp );
	}

	/**
	 * Website entity.
	 *
	 * @return array<string,mixed>
	 */
	public static function build_website() {
		$home = untrailingslashit( home_url( '/' ) );

		$node = array(
			'@type'       => 'WebSite',
			'@id'         => $home . '/#website',
			'url'         => $home . '/',
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'publisher'   => array( '@id' => $home . '/#organization' ),
			'inLanguage'  => get_bloginfo( 'language' ),
		);

		$node['potentialAction'] = array(
			'@type'       => 'SearchAction',
			'target'      => array(
				'@type'       => 'EntryPoint',
				'urlTemplate' => $home . '/?s={search_term_string}',
			),
			'query-input' => 'required name=search_term_string',
		);

		return $node;
	}

	/**
	 * Organization entity.
	 *
	 * @return array<string,mixed>
	 */
	public static function build_organization() {
		$home = untrailingslashit( home_url( '/' ) );
		$name = (string) GEO_Settings::get( 'org_name', '' );

		if ( '' === $name ) {
			$name = get_bloginfo( 'name' );
		}

		$node = array(
			'@type' => 'Organization',
			'@id'   => $home . '/#organization',
			'name'  => $name,
			'url'   => $home . '/',
		);

		$logo = (string) GEO_Settings::get( 'org_logo', '' );

		if ( '' !== $logo ) {
			$node['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => $logo,
			);
		}

		$same_as = (string) GEO_Settings::get( 'org_same_as', '' );

		if ( '' !== trim( $same_as ) ) {
			$urls = array();

			foreach ( preg_split( '/\r\n|\r|\n/', $same_as ) as $line ) {
				$line = trim( $line );

				if ( '' !== $line ) {
					$urls[] = esc_url_raw( $line );
				}
			}

			if ( ! empty( $urls ) ) {
				$node['sameAs'] = array_values( array_filter( $urls ) );
			}
		}

		return $node;
	}

	/**
	 * Description used by WebPage / Article.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function description_for( $post ) {
		$excerpt = (string) $post->post_excerpt;

		if ( '' === trim( $excerpt ) ) {
			$excerpt = GEO_FAQ_Generator::truncate( GEO_FAQ_Generator::build_body_text( $post ), 160 );
		}

		return trim( wp_strip_all_tags( $excerpt ) );
	}

	/**
	 * WebPage entity.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string,mixed>
	 */
	public static function build_webpage( $post ) {
		$url  = get_permalink( $post );
		$home = untrailingslashit( home_url( '/' ) );

		return array(
			'@type'         => 'WebPage',
			'@id'           => $url . '#webpage',
			'url'           => $url,
			'name'          => get_the_title( $post ),
			'isPartOf'      => array( '@id' => $home . '/#website' ),
			'datePublished' => self::iso_date( $post->post_date_gmt ),
			'dateModified'  => self::iso_date( $post->post_modified_gmt ),
			'description'   => self::description_for( $post ),
			'inLanguage'    => get_bloginfo( 'language' ),
		);
	}

	/**
	 * Article / BlogPosting entity.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string,mixed>
	 */
	public static function build_article( $post ) {
		$url  = get_permalink( $post );
		$home = untrailingslashit( home_url( '/' ) );

		$node = array(
			'@type'            => 'post' === $post->post_type ? 'BlogPosting' : 'Article',
			'@id'              => $url . '#article',
			'isPartOf'         => array( '@id' => $url . '#webpage' ),
			'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
			'headline'         => GEO_FAQ_Generator::truncate( get_the_title( $post ), 110 ),
			'description'      => self::description_for( $post ),
			'datePublished'    => self::iso_date( $post->post_date_gmt ),
			'dateModified'     => self::iso_date( $post->post_modified_gmt ),
			'publisher'        => array( '@id' => $home . '/#organization' ),
			'inLanguage'       => get_bloginfo( 'language' ),
		);

		$author_id = (int) $post->post_author;

		if ( $author_id > 0 ) {
			$node['author'] = array(
				'@type' => 'Person',
				'name'  => get_the_author_meta( 'display_name', $author_id ),
				'url'   => get_author_posts_url( $author_id ),
			);
		}

		$thumb = get_the_post_thumbnail_url( $post, 'full' );

		if ( $thumb ) {
			$node['image'] = array( $thumb );
		}

		if ( 'post' === $post->post_type ) {
			$categories = get_the_category( $post->ID );

			if ( ! empty( $categories ) ) {
				$node['articleSection'] = $categories[0]->name;
			}
		}

		return $node;
	}

	/**
	 * BreadcrumbList entity.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string,mixed>|null
	 */
	public static function build_breadcrumb( $post ) {
		$items = array(
			array(
				'@type'    => 'ListItem',
				'position' => 1,
				'name'     => get_bloginfo( 'name' ),
				'item'     => untrailingslashit( home_url( '/' ) ) . '/',
			),
		);

		if ( 'post' === $post->post_type ) {
			$categories = get_the_category( $post->ID );

			if ( ! empty( $categories ) ) {
				$items[] = array(
					'@type'    => 'ListItem',
					'position' => 2,
					'name'     => $categories[0]->name,
					'item'     => get_category_link( $categories[0]->term_id ),
				);
			}
		}

		$items[] = array(
			'@type'    => 'ListItem',
			'position' => count( $items ) + 1,
			'name'     => get_the_title( $post ),
			'item'     => get_permalink( $post ),
		);

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => get_permalink( $post ) . '#breadcrumb',
			'itemListElement' => $items,
		);
	}

	/**
	 * FAQPage entity.
	 *
	 * @param array<int,array<string,string>> $items Parsed shortcode items.
	 * @param string                          $url   Page URL.
	 * @return array<string,mixed>|null
	 */
	public static function build_faq_page( array $items, $url ) {
		if ( empty( $items ) ) {
			return null;
		}

		$entities = array();

		foreach ( $items as $item ) {
			if ( '' === trim( (string) $item['question'] ) || '' === trim( (string) $item['answer'] ) ) {
				continue;
			}

			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $item['question'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $item['answer'],
				),
			);
		}

		if ( empty( $entities ) ) {
			return null;
		}

		return array(
			'@type'      => 'FAQPage',
			'@id'        => $url . '#faq',
			'mainEntity' => $entities,
		);
	}

	/**
	 * Assemble the graph for the current request.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function build_graph() {
		$graph = array();
		$post  = null;

		if ( is_singular() ) {
			$post = get_queried_object();

			if ( ! $post instanceof WP_Post || '' !== (string) $post->post_password ) {
				return null;
			}

			// Attachment pages are not article-like content.
			if ( 'attachment' === $post->post_type ) {
				$post = null;
			}
		}

		if ( self::should_output( 'website' ) ) {
			$graph[] = self::build_website();
		}

		if ( self::should_output( 'organization' ) ) {
			$graph[] = self::build_organization();
		}

		if ( ! $post ) {
			return empty( $graph ) ? null : array( '@context' => 'https://schema.org', '@graph' => $graph );
		}

		if ( self::should_output( 'webpage' ) ) {
			$graph[] = self::build_webpage( $post );
		}

		if ( self::should_output( 'article' ) ) {
			$graph[] = self::build_article( $post );
		}

		if ( self::should_output( 'breadcrumb' ) ) {
			$breadcrumb = self::build_breadcrumb( $post );

			if ( $breadcrumb ) {
				$graph[] = $breadcrumb;
			}
		}

		if ( self::should_output( 'faq' ) && ! GEO_Automation::has_seo_faq_block( (string) $post->post_content ) ) {
			$items = GEO_Shortcode::parse_items( (string) $post->post_content );
			$faq   = self::build_faq_page( $items, get_permalink( $post ) );

			if ( $faq ) {
				$graph[] = $faq;
			}
		}

		if ( empty( $graph ) ) {
			return null;
		}

		return array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);
	}

	/**
	 * Print the JSON-LD block.
	 *
	 * @return void
	 */
	public function output() {
		if ( is_admin() || is_feed() || is_robots() || is_trackback() ) {
			return;
		}

		$graph = self::build_graph();

		if ( empty( $graph ) ) {
			return;
		}

		$json = wp_json_encode( $graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			return;
		}

		// Prevent a premature </script> inside a string value.
		$json = str_replace( '</', '<\/', $json );

		echo "\n" . '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
