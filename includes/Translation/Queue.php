<?php
/**
 * Background translation queue.
 *
 * One row per (object, language) in {prefix}gaiml_jobs. Jobs are processed in
 * short steps (one API request each) by the admin screens (AJAX, immediate
 * feedback), by WP-Cron (every minute while jobs are waiting) and by WP-CLI.
 * A job is claimed with an atomic UPDATE, so concurrent workers never process
 * the same job twice.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Translation;

use GustinAI\Multilingual\Core\Translations;
use GustinAI\Multilingual\Languages;

defined( 'ABSPATH' ) || exit;

final class Queue {

	/** A step makes at most one API request (150 s timeout): wide margin before another worker may take the job over. */
	private const LOCK_SECONDS = 900;

	private const MAX_ATTEMPTS = 6;

	public static function init(): void {
		add_action( 'gaiml_cron', array( self::class, 'cron' ) );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'gaiml_jobs';
	}

	/**
	 * Queues translations of an object.
	 *
	 * @param string[] $langs
	 * @return int Number of queued jobs.
	 */
	public static function enqueue( string $type, int $id, array $langs, bool $force = false ): int {
		global $wpdb;
		$source_lang = 'strings' === $type ? Languages::default_code() : Translations::lang_or_default( $type, $id );
		$now         = current_time( 'mysql', true );
		$count       = 0;
		foreach ( array_unique( $langs ) as $lang ) {
			if ( ! Languages::exists( $lang ) || $lang === $source_lang ) {
				continue;
			}
			$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( 'SELECT id, status FROM ' . self::table() . ' WHERE object_type = %s AND object_id = %d AND lang = %s', $type, $id, $lang ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			if ( $existing ) {
				if ( 'running' !== $existing->status ) {
					$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						self::table(),
						array(
							'status'       => 'pending',
							'attempts'     => 0,
							'locked_until' => null,
							'message'      => '',
							'force_update' => $force ? 1 : 0,
							'updated'      => $now,
						),
						array( 'id' => (int) $existing->id )
					);
				}
			} else {
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					self::table(),
					array(
						'object_type'  => $type,
						'object_id'    => $id,
						'lang'         => $lang,
						'status'       => 'pending',
						'force_update' => $force ? 1 : 0,
						'attempts'     => 0,
						'message'      => '',
						'data'         => '',
						'created'      => $now,
						'updated'      => $now,
					)
				);
			}
			++$count;
		}
		if ( $count ) {
			self::schedule();
		}
		return $count;
	}

	/**
	 * Queues many jobs at once (bulk translation of large sites): one INSERT per
	 * 500 jobs; jobs already in the queue are left as they are.
	 *
	 * @param array<int, string[]> $jobs Object ID => languages.
	 */
	public static function enqueue_many( string $type, array $jobs ): int {
		global $wpdb;
		$now   = current_time( 'mysql', true );
		$rows  = array();
		$count = 0;
		foreach ( $jobs as $id => $langs ) {
			foreach ( array_unique( (array) $langs ) as $lang ) {
				if ( Languages::exists( $lang ) ) {
					$rows[] = $wpdb->prepare( "(%s, %d, %s, 'pending', 0, 0, '', '', %s, %s)", $type, (int) $id, $lang, $now, $now );
				}
			}
		}
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$count += (int) $wpdb->query( 'INSERT IGNORE INTO ' . self::table() . ' (object_type, object_id, lang, status, force_update, attempts, message, data, created, updated) VALUES ' . implode( ',', $chunk ) ); // phpcs:ignore WordPress.DB
		}
		if ( $count ) {
			self::schedule();
		}
		return $count;
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( 'gaiml_cron' ) ) {
			wp_schedule_event( time() + 5, 'gaiml_minute', 'gaiml_cron' );
		}
	}

	/**
	 * Deletes the jobs of an object (deleted content).
	 */
	public static function forget( string $type, int $id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'object_type' => $type, 'object_id' => $id ), array( '%s', '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Job of an object in a language, if any.
	 */
	public static function job( string $type, int $id, string $lang ): ?object {
		$jobs = self::jobs_for( $type, $id );
		return $jobs[ $lang ] ?? null;
	}

	/** @var array<string, array<string, object>> */
	private static array $jobs_cache = array();

	/**
	 * @return array<string, object> lang => job row
	 */
	public static function jobs_for( string $type, int $id ): array {
		global $wpdb;
		$key = $type . ':' . $id;
		if ( ! isset( self::$jobs_cache[ $key ] ) ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( 'SELECT id, lang, status, attempts, message FROM ' . self::table() . ' WHERE object_type = %s AND object_id = %d', $type, $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			$out  = array();
			foreach ( (array) $rows as $row ) {
				$out[ (string) $row->lang ] = $row;
			}
			self::$jobs_cache[ $key ] = $out;
		}
		return self::$jobs_cache[ $key ];
	}

	/**
	 * Loads the jobs of many objects at once (list tables).
	 *
	 * @param int[] $ids
	 */
	public static function prime( string $type, array $ids ): void {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return;
		}
		foreach ( $ids as $id ) {
			self::$jobs_cache[ $type . ':' . $id ] = array();
		}
		$in   = implode( ',', $ids );
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare( 'SELECT id, object_id, lang, status, attempts, message FROM ' . self::table() . " WHERE object_type = %s AND object_id IN ($in)", $type ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		foreach ( (array) $rows as $row ) {
			self::$jobs_cache[ $type . ':' . (int) $row->object_id ][ (string) $row->lang ] = $row;
		}
	}

	/**
	 * @return array{pending: int, running: int, failed: int}
	 */
	public static function stats(): array {
		global $wpdb;
		$out  = array(
			'pending' => 0,
			'running' => 0,
			'failed'  => 0,
		);
		$rows = $wpdb->get_results( 'SELECT status, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY status' ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row->status ] ) ) {
				$out[ $row->status ] = (int) $row->n;
			}
		}
		return $out;
	}

	/**
	 * @return object[]
	 */
	public static function failed( int $limit = 50 ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE status = 'failed' ORDER BY updated DESC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB
	}

	public static function retry_failed(): int {
		global $wpdb;
		return (int) $wpdb->query( 'UPDATE ' . self::table() . " SET status = 'pending', attempts = 0, locked_until = NULL, message = '' WHERE status = 'failed'" ); // phpcs:ignore WordPress.DB
	}

	public static function clear( bool $failed_only = false ): int {
		global $wpdb;
		$where = $failed_only ? " WHERE status = 'failed'" : " WHERE status != 'running'";
		return (int) $wpdb->query( 'DELETE FROM ' . self::table() . $where ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Claims the next job (atomic), optionally limited to one object.
	 *
	 * @param array{type?: string, id?: int} $filter
	 */
	private static function claim( array $filter = array() ): ?object {
		global $wpdb;
		$now   = current_time( 'mysql', true );
		$where = "status IN ('pending','running') AND ( locked_until IS NULL OR locked_until < %s )";
		$args  = array( $now );
		if ( ! empty( $filter['type'] ) && ! empty( $filter['id'] ) ) {
			$where .= ' AND object_type = %s AND object_id = %d';
			$args[] = $filter['type'];
			$args[] = (int) $filter['id'];
		}
		for ( $i = 0; $i < 5; $i++ ) {
			$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE $where ORDER BY id ASC LIMIT 1", $args ) ); // phpcs:ignore WordPress.DB
			if ( ! $job ) {
				return null;
			}
			$lock    = gmdate( 'Y-m-d H:i:s', time() + self::LOCK_SECONDS );
			$claimed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'UPDATE ' . self::table() . " SET status = 'running', locked_until = %s, updated = %s WHERE id = %d AND status IN ('pending','running') AND ( locked_until IS NULL OR locked_until < %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$lock,
					$now,
					(int) $job->id,
					$now
				)
			);
			if ( 1 === (int) $claimed ) {
				$job->lock = $lock;
				return $job;
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $result
	 * @param array<string, mixed> $state
	 */
	private static function release( object $job, array $result, array $state ): void {
		global $wpdb;
		$now    = current_time( 'mysql', true );
		$status = (string) $result['status'];
		// Only while this worker still holds the job: after a lock timeout another
		// worker may own it, and its state must not be overwritten.
		$owned = array(
			'id'           => (int) $job->id,
			'locked_until' => (string) $job->lock,
		);
		if ( 'done' === $status ) {
			$wpdb->delete( self::table(), $owned, array( '%d', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		$attempts = (int) $job->attempts;
		$locked   = null;
		if ( 'retry' === $status ) {
			++$attempts;
			$status = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending';
			$delay  = max( (int) ( $result['retry_after'] ?? 0 ), min( 60 * $attempts, 600 ) );
			$locked = gmdate( 'Y-m-d H:i:s', time() + $delay );
		} elseif ( 'pending' !== $status ) {
			$status = 'failed';
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'status'       => $status,
				'attempts'     => $attempts,
				'locked_until' => $locked,
				'message'      => mb_substr( (string) ( $result['message'] ?? '' ), 0, 500 ),
				'data'         => (string) wp_json_encode( $state ),
				'updated'      => $now,
			),
			$owned,
			null,
			array( '%d', '%s' )
		);
	}

	/**
	 * Processes jobs during $budget seconds.
	 *
	 * @param array{type?: string, id?: int} $filter Process the jobs of one object first.
	 * @return array{processed: int, results: array<int, array<string, mixed>>, stats: array<string, int>}
	 */
	public static function run( float $budget = 10.0, array $filter = array() ): array {
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		$start   = microtime( true );
		$results = array();
		do {
			$job = self::claim( $filter );
			if ( ! $job && $filter ) {
				$filter = array();
				$job    = self::claim();
			}
			if ( ! $job ) {
				break;
			}
			$state = json_decode( (string) $job->data, true );
			$state = is_array( $state ) ? $state : array();
			try {
				$result = Engine::step( (string) $job->object_type, (int) $job->object_id, (string) $job->lang, (bool) $job->force_update, $state );
			} catch ( \Throwable $e ) {
				$result = array(
					'status'  => 'retry',
					'message' => $e->getMessage(),
				);
			}
			self::release( $job, $result, $state );
			$results[] = array(
				'type'    => (string) $job->object_type,
				'id'      => (int) $job->object_id,
				'lang'    => (string) $job->lang,
				'status'  => (string) $result['status'],
				'message' => (string) ( $result['message'] ?? '' ),
				'target'  => (int) ( $result['target'] ?? 0 ),
			);
		} while ( microtime( true ) - $start < $budget );

		self::$jobs_cache = array();
		return array(
			'processed' => count( $results ),
			'results'   => $results,
			'stats'     => self::stats(),
		);
	}

	/**
	 * WP-Cron worker: one worker at a time, unscheduled when the queue is empty.
	 */
	public static function cron(): void {
		$lock = (int) get_option( 'gaiml_lock', 0 );
		if ( $lock && $lock > time() - 120 ) {
			return;
		}
		update_option( 'gaiml_lock', time(), false );
		try {
			$result = self::run( 50.0 );
		} finally {
			delete_option( 'gaiml_lock' );
		}
		if ( 0 === $result['stats']['pending'] && 0 === $result['stats']['running'] ) {
			wp_clear_scheduled_hook( 'gaiml_cron' );
		}
	}
}
