<?php
/**
 * Language and translation groups of posts and terms.
 *
 * One row per object in {prefix}gaiml_translations: (object_type, object_id) =>
 * (lang, group_id). All the translations of a content share the same group_id
 * (the ID of the original). A group has at most one object per language.
 *
 * Lookups are cached in memory for the request and in the object cache, and
 * WP_Query results are primed in one query (see Query::prime_posts()).
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Core;

use GustinAI\Multilingual\Languages;

defined( 'ABSPATH' ) || exit;

final class Translations {

	private const CACHE_GROUP = 'gaiml';

	private static ?string $salt = null;

	/** @var array<string, array<int, array{lang: string, group: int}|false>> */
	private static array $objects = array(
		'post' => array(),
		'term' => array(),
	);

	/** @var array<string, array<int, array<string, int>>> type => group => lang => id */
	private static array $groups = array(
		'post' => array(),
		'term' => array(),
	);

	private static function key( string $key ): string {
		if ( null === self::$salt ) {
			self::$salt = (string) wp_cache_get_last_changed( self::CACHE_GROUP );
		}
		return $key . ':' . self::$salt;
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'gaiml_translations';
	}

	/**
	 * @return array{lang: string, group: int}|null
	 */
	public static function get( string $type, int $id ): ?array {
		if ( $id <= 0 || ! isset( self::$objects[ $type ] ) ) {
			return null;
		}
		if ( ! array_key_exists( $id, self::$objects[ $type ] ) ) {
			$cached = wp_cache_get( self::key( $type . ':' . $id ), self::CACHE_GROUP );
			if ( false !== $cached && is_array( $cached ) ) {
				self::$objects[ $type ][ $id ] = $cached['row'] ?? false;
			} else {
				self::prime( $type, array( $id ) );
			}
		}
		$row = self::$objects[ $type ][ $id ] ?? false;
		return false === $row ? null : $row;
	}

	/**
	 * Language of an object, or null when it has none (not translatable or created
	 * before the plugin was activated: callers usually treat it as the default).
	 */
	public static function lang( string $type, int $id ): ?string {
		$row = self::get( $type, $id );
		return $row ? $row['lang'] : null;
	}

	/**
	 * Language of an object, the default language when it has none.
	 */
	public static function lang_or_default( string $type, int $id ): string {
		return self::lang( $type, $id ) ?? Languages::default_code();
	}

	public static function group( string $type, int $id ): int {
		$row = self::get( $type, $id );
		return $row ? $row['group'] : 0;
	}

	/**
	 * Loads many objects with their whole translation group in one query.
	 *
	 * @param int[] $ids
	 */
	public static function prime( string $type, array $ids ): void {
		global $wpdb;
		$ids = array_values(
			array_filter(
				array_unique( array_map( 'intval', $ids ) ),
				static fn( $id ) => $id > 0 && ! array_key_exists( $id, self::$objects[ $type ] )
			)
		);
		if ( ! $ids ) {
			return;
		}
		$table = self::table();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT t2.object_id, t2.lang, t2.group_id FROM {$table} t1 INNER JOIN {$table} t2 ON ( t2.object_type = t1.object_type AND t2.group_id = t1.group_id ) WHERE t1.object_type = %s AND t1.object_id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					array_merge( array( $type ), $chunk )
				)
			);
			$groups = array();
			foreach ( (array) $rows as $row ) {
				$id                            = (int) $row->object_id;
				$value                         = array(
					'lang'  => (string) $row->lang,
					'group' => (int) $row->group_id,
				);
				self::$objects[ $type ][ $id ] = $value;
				$groups[ (int) $row->group_id ][ (string) $row->lang ] = $id;
				wp_cache_set( self::key( $type . ':' . $id ), array( 'row' => $value ), self::CACHE_GROUP );
			}
			foreach ( $groups as $group => $members ) {
				self::$groups[ $type ][ $group ] = $members;
				wp_cache_set( self::key( 'g' . $type . ':' . $group ), $members, self::CACHE_GROUP );
			}
			foreach ( $chunk as $id ) {
				if ( ! array_key_exists( $id, self::$objects[ $type ] ) ) {
					self::$objects[ $type ][ $id ] = false;
					wp_cache_set( self::key( $type . ':' . $id ), array( 'row' => false ), self::CACHE_GROUP );
				}
			}
		}
	}

	/**
	 * All the members of an object's translation group: lang => object ID.
	 * An object without a row is returned alone, in the default language.
	 *
	 * @return array<string, int>
	 */
	public static function members( string $type, int $id ): array {
		$row = self::get( $type, $id );
		if ( ! $row ) {
			return $id > 0 ? array( Languages::default_code() => $id ) : array();
		}
		return self::group_members( $type, $row['group'] );
	}

	/**
	 * @return array<string, int>
	 */
	public static function group_members( string $type, int $group ): array {
		global $wpdb;
		if ( $group <= 0 ) {
			return array();
		}
		if ( isset( self::$groups[ $type ][ $group ] ) ) {
			return self::$groups[ $type ][ $group ];
		}
		$cached = wp_cache_get( self::key( 'g' . $type . ':' . $group ), self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			self::$groups[ $type ][ $group ] = $cached;
			return $cached;
		}
		$rows    = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT object_id, lang FROM ' . self::table() . ' WHERE object_type = %s AND group_id = %d', $type, $group ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$members = array();
		foreach ( (array) $rows as $row ) {
			$members[ (string) $row->lang ] = (int) $row->object_id;
			if ( ! array_key_exists( (int) $row->object_id, self::$objects[ $type ] ) ) {
				self::$objects[ $type ][ (int) $row->object_id ] = array(
					'lang'  => (string) $row->lang,
					'group' => $group,
				);
			}
		}
		self::$groups[ $type ][ $group ] = $members;
		wp_cache_set( self::key( 'g' . $type . ':' . $group ), $members, self::CACHE_GROUP );
		return $members;
	}

	/**
	 * Translation of an object in a language (the object itself when it is in that
	 * language), 0 when there is none.
	 */
	public static function translation( string $type, int $id, string $lang ): int {
		$members = self::members( $type, $id );
		return $members[ $lang ] ?? 0;
	}

	/**
	 * Translations of many objects at once: id => translated id (0 when missing).
	 *
	 * @param int[] $ids
	 * @return array<int, int>
	 */
	public static function translations_of( string $type, array $ids, string $lang ): array {
		self::prime( $type, $ids );
		$out = array();
		foreach ( $ids as $id ) {
			$out[ (int) $id ] = self::translation( $type, (int) $id, $lang );
		}
		return $out;
	}

	/**
	 * Assigns a language (and optionally a group) to an object.
	 * Returns false when the group already has an object in that language.
	 */
	public static function set( string $type, int $id, string $lang, int $group = 0 ): bool {
		global $wpdb;
		if ( $id <= 0 || ! Languages::exists( $lang ) ) {
			return false;
		}
		$current = self::get( $type, $id );
		if ( $group <= 0 ) {
			$group = $current ? $current['group'] : $id;
		}
		$members = self::group_members( $type, $group );
		if ( isset( $members[ $lang ] ) && $members[ $lang ] !== $id ) {
			return false;
		}
		if ( $current ) {
			$written = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::table(),
				array(
					'lang'     => $lang,
					'group_id' => $group,
				),
				array(
					'object_type' => $type,
					'object_id'   => $id,
				),
				array( '%s', '%d' ),
				array( '%s', '%d' )
			);
		} else {
			$written = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::table(),
				array(
					'object_type' => $type,
					'object_id'   => $id,
					'lang'        => $lang,
					'group_id'    => $group,
				),
				array( '%s', '%d', '%s', '%d' )
			);
		}
		self::forget( $type, $id, $current ? $current['group'] : 0 );
		self::forget( $type, $id, $group );
		if ( false === $written ) {
			// Unique key (group, language) taken by a concurrent request.
			return false;
		}
		/**
		 * Fires when the language or translation group of an object changes.
		 */
		do_action( 'gaiml_translations_changed', $type, $id, $lang, $group );
		return true;
	}

	/**
	 * Moves an object out of its translation group (it keeps its language).
	 */
	public static function unlink( string $type, int $id ): void {
		$row = self::get( $type, $id );
		if ( ! $row || count( self::group_members( $type, $row['group'] ) ) <= 1 ) {
			return;
		}
		// The object's own ID is used as group ID unless another object already uses it.
		$taken = array_diff( self::group_members( $type, $id ), array( $id ) );
		$group = ( $row['group'] === $id || $taken ) ? self::free_group( $type ) : $id;
		self::set( $type, $id, $row['lang'], $group );
	}

	/**
	 * Synthetic group IDs live above 10^15 so they never collide with future object IDs.
	 */
	private static function free_group( string $type ): int {
		global $wpdb;
		$max = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(group_id) FROM ' . self::table() . ' WHERE object_type = %s', $type ) ); // phpcs:ignore WordPress.DB
		return max( $max + 1, 1000000000000000 );
	}

	public static function delete( string $type, int $id ): void {
		global $wpdb;
		$row = self::get( $type, $id );
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'object_type' => $type,
				'object_id'   => $id,
			),
			array( '%s', '%d' )
		);
		self::forget( $type, $id, $row ? $row['group'] : 0 );
		do_action( 'gaiml_translations_changed', $type, $id, '', 0 );
	}

	/**
	 * Assigns a language to every object of the given subtypes that has none.
	 *
	 * @param string[] $subtypes Post types or taxonomies.
	 */
	public static function assign_missing( string $type, array $subtypes, string $lang ): int {
		global $wpdb;
		$subtypes = array_values( array_filter( array_map( 'sanitize_key', $subtypes ) ) );
		if ( ! $subtypes || ! Languages::exists( $lang ) ) {
			return 0;
		}
		$table = self::table();
		$in    = implode( ',', array_fill( 0, count( $subtypes ), '%s' ) );
		if ( 'post' === $type ) {
			$sql = "INSERT INTO {$table} (object_type, object_id, lang, group_id)
				SELECT 'post', p.ID, %s, p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$table} t ON ( t.object_type = 'post' AND t.object_id = p.ID )
				WHERE t.object_id IS NULL AND p.post_type IN ($in) AND p.post_status NOT IN ('auto-draft','inherit','trash')";
		} else {
			$sql = "INSERT INTO {$table} (object_type, object_id, lang, group_id)
				SELECT DISTINCT 'term', tt.term_id, %s, tt.term_id FROM {$wpdb->term_taxonomy} tt
				LEFT JOIN {$table} t ON ( t.object_type = 'term' AND t.object_id = tt.term_id )
				WHERE t.object_id IS NULL AND tt.taxonomy IN ($in)";
		}
		$count = (int) $wpdb->query( $wpdb->prepare( $sql, array_merge( array( $lang ), $subtypes ) ) ); // phpcs:ignore WordPress.DB
		if ( $count > 0 ) {
			self::flush();
		}
		return $count;
	}

	/**
	 * Number of objects per language for a subtype.
	 *
	 * @return array<string, int>
	 */
	public static function counts( string $type, string $subtype ): array {
		global $wpdb;
		$table = self::table();
		if ( 'post' === $type ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT t.lang, COUNT(*) AS n FROM {$table} t INNER JOIN {$wpdb->posts} p ON ( p.ID = t.object_id )
					WHERE t.object_type = 'post' AND p.post_type = %s AND p.post_status NOT IN ('auto-draft','trash','inherit') GROUP BY t.lang",
					$subtype
				)
			);
		} else {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT t.lang, COUNT(*) AS n FROM {$table} t INNER JOIN {$wpdb->term_taxonomy} tt ON ( tt.term_id = t.object_id )
					WHERE t.object_type = 'term' AND tt.taxonomy = %s GROUP BY t.lang",
					$subtype
				)
			);
		}
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->lang ] = (int) $row->n;
		}
		return $out;
	}

	private static function forget( string $type, int $id, int $group ): void {
		unset( self::$objects[ $type ][ $id ] );
		wp_cache_delete( self::key( $type . ':' . $id ), self::CACHE_GROUP );
		if ( $group > 0 ) {
			unset( self::$groups[ $type ][ $group ] );
			wp_cache_delete( self::key( 'g' . $type . ':' . $group ), self::CACHE_GROUP );
		}
	}

	public static function flush(): void {
		self::$objects = array(
			'post' => array(),
			'term' => array(),
		);
		self::$groups  = array(
			'post' => array(),
			'term' => array(),
		);
		wp_cache_set_last_changed( self::CACHE_GROUP );
		self::$salt = null;
	}
}
