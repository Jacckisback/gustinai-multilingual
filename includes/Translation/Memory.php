<?php
/**
 * Translation memory: every validated translation is stored once per language.
 *
 * - Identical texts (buttons, footers, repeated blocks) are translated once.
 * - When a page changes, only the modified texts are sent again.
 * - Interrupted jobs resume where they stopped.
 * - Site strings (title, tagline, menu labels) live here too, and can be
 *   corrected by hand (manual entries are never overwritten).
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Translation;

defined( 'ABSPATH' ) || exit;

final class Memory {

	/** @var array<string, array<string, string>> lang => hash => translation (request cache) */
	private static array $cache = array();

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'gaiml_strings';
	}

	public static function hash( string $source_lang, string $kind, string $text ): string {
		$group = in_array( $kind, array( 'slug', 'description' ), true ) ? $kind : 'text';
		return md5( $source_lang . '|' . $group . '|' . $text );
	}

	/**
	 * @param string[] $hashes
	 * @return array<string, string> hash => translation
	 */
	public static function get_many( array $hashes, string $lang ): array {
		global $wpdb;
		$hashes = array_values( array_unique( array_filter( $hashes ) ) );
		$found  = array();
		$todo   = array();
		foreach ( $hashes as $hash ) {
			if ( isset( self::$cache[ $lang ][ $hash ] ) ) {
				$found[ $hash ] = self::$cache[ $lang ][ $hash ];
			} else {
				$todo[] = $hash;
			}
		}
		foreach ( array_chunk( $todo, 500 ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( 'SELECT hash, translation FROM ' . self::table() . " WHERE lang = %s AND hash IN ($in)", array_merge( array( $lang ), $chunk ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			foreach ( (array) $rows as $row ) {
				$found[ $row->hash ]                  = (string) $row->translation;
				self::$cache[ $lang ][ $row->hash ] = (string) $row->translation;
			}
		}
		return $found;
	}

	/**
	 * Stores translations (existing manual entries are kept).
	 *
	 * @param array<string, array{source: string, translation: string, context?: string}> $rows hash => row
	 */
	public static function put_many( array $rows, string $lang ): void {
		global $wpdb;
		if ( ! $rows ) {
			return;
		}
		$existing = array();
		foreach ( array_chunk( array_keys( $rows ), 500 ) as $chunk ) {
			$in      = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( 'SELECT hash, manual FROM ' . self::table() . " WHERE lang = %s AND hash IN ($in)", array_merge( array( $lang ), $chunk ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			foreach ( (array) $results as $row ) {
				$existing[ $row->hash ] = (int) $row->manual;
			}
		}
		$now    = current_time( 'mysql', true );
		$values = array();
		$args   = array();
		foreach ( $rows as $hash => $row ) {
			self::$cache[ $lang ][ $hash ] = $row['translation'];
			if ( isset( $existing[ $hash ] ) ) {
				if ( 0 === $existing[ $hash ] ) {
					$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						self::table(),
						array(
							'translation' => $row['translation'],
							'updated'     => $now,
						),
						array(
							'hash' => $hash,
							'lang' => $lang,
						)
					);
				} else {
					unset( self::$cache[ $lang ][ $hash ] );
				}
				continue;
			}
			$values[] = '(%s,%s,%s,%s,%s,0,%s)';
			array_push( $args, $hash, $lang, $row['source'], $row['translation'], (string) ( $row['context'] ?? '' ), $now );
		}
		foreach ( array_chunk( $values, 100, true ) as $i => $chunk ) {
			$chunk_args = array_slice( $args, $i * 100 * 6, count( $chunk ) * 6 );
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( 'INSERT IGNORE INTO ' . self::table() . ' (hash, lang, source, translation, context, manual, updated) VALUES ' . implode( ',', $chunk ), $chunk_args ) // phpcs:ignore WordPress.DB.PreparedSQL
			);
		}
	}

	/**
	 * Saves a translation edited by hand (never overwritten by the AI).
	 */
	public static function set_manual( string $source_lang, string $text, string $lang, string $translation, string $context = 'string' ): void {
		global $wpdb;
		$text = trim( Placeholders::decode( $text ) );
		$hash = self::hash( $source_lang, 'text', $text );
		$now  = current_time( 'mysql', true );
		if ( '' === trim( $translation ) ) {
			$wpdb->delete( self::table(), array( 'hash' => $hash, 'lang' => $lang ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			unset( self::$cache[ $lang ][ $hash ] );
			return;
		}
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE hash = %s AND lang = %s', $hash, $lang ) ); // phpcs:ignore WordPress.DB
		if ( $exists ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::table(),
				array(
					'translation' => $translation,
					'manual'      => 1,
					'updated'     => $now,
				),
				array( 'id' => (int) $exists )
			);
		} else {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::table(),
				array(
					'hash'        => $hash,
					'lang'        => $lang,
					'source'      => $text,
					'translation' => $translation,
					'context'     => $context,
					'manual'      => 1,
					'updated'     => $now,
				)
			);
		}
		self::$cache[ $lang ][ $hash ] = $translation;
	}

	/**
	 * Translations of plain texts (site strings, menu labels) at render time.
	 *
	 * @param string[] $texts
	 * @return array<string, string> text => translation (only found ones)
	 */
	public static function lookup( array $texts, string $source_lang, string $lang ): array {
		$by_hash = array();
		foreach ( $texts as $text ) {
			$text  = (string) $text;
			$clean = trim( Placeholders::decode( $text ) );
			if ( '' !== $clean ) {
				$by_hash[ self::hash( $source_lang, 'text', $clean ) ] = $text;
			}
		}
		$out = array();
		foreach ( self::get_many( array_keys( $by_hash ), $lang ) as $hash => $translation ) {
			$out[ $by_hash[ $hash ] ] = $translation;
		}
		return $out;
	}

	public static function count( string $lang = '' ): int {
		global $wpdb;
		if ( '' === $lang ) {
			return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ); // phpcs:ignore WordPress.DB
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE lang = %s', $lang ) ); // phpcs:ignore WordPress.DB
	}

	public static function flush(): void {
		self::$cache = array();
	}
}
