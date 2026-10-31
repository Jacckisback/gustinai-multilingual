<?php
/**
 * Language filtering of front-end queries.
 *
 * Posts and terms are filtered with one indexed LEFT JOIN on the translations
 * table (no meta query, no extra request). Like other multilingual plugins,
 * queries with suppress_filters (get_posts() default) are not filtered: pass
 * 'suppress_filters' => false or 'gaiml_lang' => 'en' to filter them.
 *
 * Content without a language row is treated as default-language content.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Core;

use GustinAI\Multilingual\Languages;

defined( 'ABSPATH' ) || exit;

final class Query {

	private static int $suspended = 0;

	public static function init(): void {
		add_filter( 'posts_clauses', array( self::class, 'posts_clauses' ), 20, 2 );
		add_filter( 'the_posts', array( self::class, 'prime_posts' ), 10, 2 );
		add_filter( 'get_the_terms', array( self::class, 'prime_terms' ) );
		add_filter( 'terms_clauses', array( self::class, 'terms_clauses' ), 20, 3 );
		add_filter( 'request', array( self::class, 'resolve_request' ), 20 );
		add_filter( 'option_page_on_front', array( self::class, 'translate_page_option' ) );
		add_filter( 'option_page_for_posts', array( self::class, 'translate_page_option' ) );
		add_filter( 'option_sticky_posts', array( self::class, 'sticky_posts' ) );
		foreach ( array( 'previous', 'next' ) as $direction ) {
			add_filter( "get_{$direction}_post_join", array( self::class, 'adjacent_join' ), 10, 5 );
			add_filter( "get_{$direction}_post_where", array( self::class, 'adjacent_where' ), 10, 5 );
		}
		add_filter( 'getarchives_join', array( self::class, 'archives_join' ), 10, 2 );
		add_filter( 'getarchives_where', array( self::class, 'archives_where' ), 10, 2 );
		add_action( 'pre_get_posts', array( self::class, 'admin_list_filter' ) );
	}

	public static function suspend(): void {
		++self::$suspended;
	}

	public static function resume(): void {
		self::$suspended = max( 0, self::$suspended - 1 );
	}

	/**
	 * SQL condition "object is in $lang" on a translations table alias.
	 */
	public static function lang_condition( string $alias, string $lang ): string {
		global $wpdb;
		if ( Languages::is_default( $lang ) ) {
			return $wpdb->prepare( "( {$alias}.lang = %s OR {$alias}.lang IS NULL )", $lang ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $wpdb->prepare( "{$alias}.lang = %s", $lang ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @param string[] $values
	 */
	private static function sql_in( array $values ): string {
		return implode( ',', array_map( static fn( $v ) => "'" . esc_sql( $v ) . "'", $values ) );
	}

	/**
	 * Language a query must be filtered by, null for no filtering.
	 */
	public static function query_lang( \WP_Query $query ): ?string {
		if ( self::$suspended ) {
			return null;
		}
		$explicit = $query->get( 'gaiml_lang' );
		if ( 'all' === $explicit ) {
			return null;
		}
		if ( is_string( $explicit ) && Languages::exists( $explicit ) ) {
			return $explicit;
		}
		$lang = Router::current();
		if ( null === $lang || Router::is_neutral() ) {
			return null;
		}
		if ( $query->get( 'post__in' ) || $query->get( 'attachment_id' ) ) {
			return null;
		}
		// A single post requested by ID; the posts page keeps its page_id but lists posts.
		if ( ( $query->get( 'p' ) || $query->get( 'page_id' ) ) && ! $query->is_posts_page ) {
			return null;
		}
		return $lang;
	}

	/**
	 * @param array<string, string> $clauses
	 * @param \WP_Query             $query
	 * @return array<string, string>
	 */
	public static function posts_clauses( $clauses, $query ) {
		if ( ! $query instanceof \WP_Query ) {
			return $clauses;
		}
		$lang = self::query_lang( $query );
		if ( null === $lang ) {
			return $clauses;
		}
		$types = Content::post_types();
		if ( ! $types ) {
			return $clauses;
		}
		$post_type = $query->get( 'post_type' );
		if ( is_string( $post_type ) && '' !== $post_type && 'any' !== $post_type && ! in_array( $post_type, $types, true ) ) {
			return $clauses;
		}
		if ( is_array( $post_type ) && $post_type && ! array_intersect( $post_type, $types ) ) {
			return $clauses;
		}
		global $wpdb;
		$table              = Translations::table();
		$clauses['join']   .= " LEFT JOIN {$table} AS gaiml_t ON ( gaiml_t.object_id = {$wpdb->posts}.ID AND gaiml_t.object_type = 'post' )";
		$condition          = self::lang_condition( 'gaiml_t', $lang );
		$only_translatable  = ( is_string( $post_type ) && in_array( $post_type, $types, true ) )
			|| ( is_array( $post_type ) && $post_type && ! array_diff( $post_type, $types ) );
		$clauses['where']  .= $only_translatable
			? " AND {$condition}"
			: " AND ( {$wpdb->posts}.post_type NOT IN (" . self::sql_in( $types ) . ") OR {$condition} )";
		return $clauses;
	}

	/**
	 * Loads the languages of the posts of a query in one request (permalinks, switcher).
	 *
	 * @param \WP_Post[] $posts
	 * @return \WP_Post[]
	 */
	public static function prime_posts( $posts, $query = null ) {
		if ( is_array( $posts ) && $posts ) {
			$ids = array();
			foreach ( $posts as $post ) {
				if ( $post instanceof \WP_Post ) {
					$ids[] = $post->ID;
				}
			}
			Translations::prime( 'post', $ids );
		}
		return $posts;
	}

	/**
	 * Loads the languages of a post's terms in one request (term links).
	 *
	 * @param \WP_Term[]|false|\WP_Error $terms
	 * @return \WP_Term[]|false|\WP_Error
	 */
	public static function prime_terms( $terms ) {
		if ( is_array( $terms ) && $terms ) {
			Translations::prime( 'term', wp_list_pluck( $terms, 'term_id' ) );
		}
		return $terms;
	}

	/**
	 * @param array<string, string> $clauses
	 * @param string[]              $taxonomies
	 * @param array<string, mixed>  $args
	 * @return array<string, string>
	 */
	public static function terms_clauses( $clauses, $taxonomies, $args ) {
		if ( self::$suspended ) {
			return $clauses;
		}
		$lang     = null;
		$explicit = $args['gaiml_lang'] ?? '';
		if ( 'all' === $explicit ) {
			return $clauses;
		}
		if ( is_string( $explicit ) && Languages::exists( $explicit ) ) {
			$lang = $explicit;
		} else {
			$lang = Router::current();
			if ( null === $lang || Router::is_neutral() ) {
				return $clauses;
			}
			if ( ! empty( $args['object_ids'] ) || ! empty( $args['include'] ) || ! empty( $args['term_taxonomy_id'] ) ) {
				return $clauses;
			}
		}
		$taxonomies   = array_values( (array) $taxonomies );
		$translatable = array_values( array_intersect( $taxonomies, Content::taxonomies() ) );
		if ( ! $translatable ) {
			return $clauses;
		}
		$table             = Translations::table();
		$clauses['join']  .= " LEFT JOIN {$table} AS gaiml_tt ON ( gaiml_tt.object_id = t.term_id AND gaiml_tt.object_type = 'term' )";
		$condition         = self::lang_condition( 'gaiml_tt', $lang );
		$clauses['where'] .= count( $translatable ) === count( $taxonomies )
			? " AND {$condition}"
			: ' AND ( tt.taxonomy NOT IN (' . self::sql_in( $translatable ) . ") OR {$condition} )";
		return $clauses;
	}

	/**
	 * Pages and hierarchical post types are requested by path: resolve the path in
	 * the current language (several languages can share the same slug).
	 *
	 * @param array<string, mixed> $query_vars
	 * @return array<string, mixed>
	 */
	public static function resolve_request( $query_vars ) {
		$lang = Router::current();
		if ( ! is_array( $query_vars ) || null === $lang || Router::is_neutral() || 'front' !== Router::context() ) {
			return $query_vars;
		}
		if ( ! empty( $query_vars['pagename'] ) && Content::is_translatable_post_type( 'page' ) ) {
			$id = self::post_by_path( (string) $query_vars['pagename'], $lang, 'page' );
			if ( $id ) {
				$query_vars['page_id'] = $id;
				unset( $query_vars['pagename'] );
			}
			return $query_vars;
		}
		foreach ( Content::post_types() as $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( ! $object || ! $object->hierarchical || 'page' === $post_type || ! $object->query_var || empty( $query_vars[ $object->query_var ] ) ) {
				continue;
			}
			$id = self::post_by_path( (string) $query_vars[ $object->query_var ], $lang, $post_type );
			if ( $id ) {
				$query_vars['p']         = $id;
				$query_vars['post_type'] = $post_type;
				unset( $query_vars[ $object->query_var ], $query_vars['name'] );
			}
			break;
		}
		return $query_vars;
	}

	/**
	 * Like get_page_by_path(), restricted to one language.
	 */
	public static function post_by_path( string $path, string $lang, string $post_type ): int {
		global $wpdb;
		$path  = str_replace( array( '%2F', '%20' ), array( '/', ' ' ), rawurlencode( urldecode( $path ) ) );
		$parts = array_values( array_filter( array_map( 'sanitize_title_for_query', explode( '/', trim( $path, '/' ) ) ), 'strlen' ) );
		if ( ! $parts ) {
			return 0;
		}
		$table = Translations::table();
		$in    = implode( ',', array_fill( 0, count( $parts ), '%s' ) );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID, p.post_name, p.post_parent FROM {$wpdb->posts} p
				LEFT JOIN {$table} gaiml_t ON ( gaiml_t.object_type = 'post' AND gaiml_t.object_id = p.ID )
				WHERE p.post_name IN ($in) AND p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft','inherit')
				AND " . self::lang_condition( 'gaiml_t', $lang ), // phpcs:ignore WordPress.DB.PreparedSQL
				array_merge( $parts, array( $post_type ) )
			),
			OBJECT_K
		);
		if ( ! $rows ) {
			return 0;
		}
		$reversed = array_reverse( $parts );
		foreach ( $rows as $row ) {
			if ( strtolower( (string) $row->post_name ) !== strtolower( $reversed[0] ) ) {
				continue;
			}
			$count = 0;
			$page  = $row;
			while ( 0 !== (int) $page->post_parent && isset( $rows[ $page->post_parent ] ) ) {
				++$count;
				$parent = $rows[ $page->post_parent ];
				if ( ! isset( $reversed[ $count ] ) || strtolower( (string) $parent->post_name ) !== strtolower( $reversed[ $count ] ) ) {
					break;
				}
				$page = $parent;
			}
			if ( 0 === (int) $page->post_parent && count( $reversed ) === $count + 1 && strtolower( (string) $page->post_name ) === strtolower( $reversed[ $count ] ) ) {
				return (int) $row->ID;
			}
		}
		return 0;
	}

	/**
	 * Front page and posts page follow the current language.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public static function translate_page_option( $value ) {
		$lang = Router::current();
		if ( ! $value || null === $lang || Router::is_neutral() || self::$suspended ) {
			return $value;
		}
		$translation = Translations::translation( 'post', (int) $value, $lang );
		return $translation ? $translation : $value;
	}

	/**
	 * Sticky posts of the current language only.
	 *
	 * @param mixed $ids
	 * @return mixed
	 */
	public static function sticky_posts( $ids ) {
		$lang = Router::current();
		if ( ! is_array( $ids ) || ! $ids || null === $lang || Router::is_neutral() || self::$suspended ) {
			return $ids;
		}
		Translations::prime( 'post', array_map( 'intval', $ids ) );
		return array_values( array_filter( $ids, static fn( $id ) => Translations::lang_or_default( 'post', (int) $id ) === $lang ) );
	}

	/**
	 * @param string        $join
	 * @param bool          $in_same_term
	 * @param mixed         $excluded_terms
	 * @param string        $taxonomy
	 * @param \WP_Post|null $post
	 */
	public static function adjacent_join( $join, $in_same_term = false, $excluded_terms = '', $taxonomy = '', $post = null ): string {
		if ( ! $post instanceof \WP_Post || ! Content::is_translatable_post_type( $post->post_type ) || self::$suspended ) {
			return (string) $join;
		}
		return $join . ' LEFT JOIN ' . Translations::table() . " AS gaiml_t ON ( gaiml_t.object_id = p.ID AND gaiml_t.object_type = 'post' )";
	}

	/**
	 * @param string        $where
	 * @param bool          $in_same_term
	 * @param mixed         $excluded_terms
	 * @param string        $taxonomy
	 * @param \WP_Post|null $post
	 */
	public static function adjacent_where( $where, $in_same_term = false, $excluded_terms = '', $taxonomy = '', $post = null ): string {
		if ( ! $post instanceof \WP_Post || ! Content::is_translatable_post_type( $post->post_type ) || self::$suspended ) {
			return (string) $where;
		}
		return $where . ' AND ' . self::lang_condition( 'gaiml_t', Translations::lang_or_default( 'post', $post->ID ) );
	}

	/**
	 * @param string               $join
	 * @param array<string, mixed> $args
	 */
	public static function archives_join( $join, $args = array() ): string {
		if ( null === self::archives_lang( $args ) ) {
			return (string) $join;
		}
		global $wpdb;
		return $join . ' LEFT JOIN ' . Translations::table() . " AS gaiml_t ON ( gaiml_t.object_id = {$wpdb->posts}.ID AND gaiml_t.object_type = 'post' )";
	}

	/**
	 * @param string               $where
	 * @param array<string, mixed> $args
	 */
	public static function archives_where( $where, $args = array() ): string {
		$lang = self::archives_lang( $args );
		return null === $lang ? (string) $where : $where . ' AND ' . self::lang_condition( 'gaiml_t', $lang );
	}

	/**
	 * @param array<string, mixed> $args
	 */
	private static function archives_lang( $args ): ?string {
		$lang = Router::current();
		if ( null === $lang || Router::is_neutral() || self::$suspended ) {
			return null;
		}
		$post_type = is_array( $args ) && ! empty( $args['post_type'] ) ? (string) $args['post_type'] : 'post';
		return Content::is_translatable_post_type( $post_type ) ? $lang : null;
	}

	/**
	 * Language filter of the admin post lists (?gaiml_lang=en).
	 *
	 * @param \WP_Query $query
	 */
	public static function admin_list_filter( $query ): void {
		global $pagenow;
		if ( ! is_admin() || ! $query instanceof \WP_Query || ! $query->is_main_query() || 'edit.php' !== $pagenow ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- List filter.
		$lang = isset( $_GET['gaiml_lang'] ) ? sanitize_key( wp_unslash( $_GET['gaiml_lang'] ) ) : '';
		if ( Languages::exists( $lang ) ) {
			$query->set( 'gaiml_lang', $lang );
		}
	}
}
