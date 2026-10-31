<?php
/**
 * WP-CLI commands: wp gaiml <command>.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Cli;

use GustinAI\Multilingual\Core\Content;
use GustinAI\Multilingual\Core\Translations;
use GustinAI\Multilingual\Languages;
use GustinAI\Multilingual\Translation\Engine;
use GustinAI\Multilingual\Translation\Queue;
use GustinAI\Multilingual\Translation\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * Translate content and manage the translation queue.
 */
final class Commands {

	/**
	 * Translates posts (or terms) with AI and waits for the result.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Post or term IDs.
	 *
	 * [--lang=<codes>]
	 * : Comma-separated target languages. Default: every other language.
	 *
	 * [--type=<type>]
	 * : post or term. Default: post.
	 *
	 * [--force]
	 * : Overwrite translations edited by hand.
	 *
	 * ## EXAMPLES
	 *
	 *     wp gaiml translate 12 34 --lang=en,es
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function translate( $args, $assoc ): void {
		$type   = ( $assoc['type'] ?? 'post' ) === 'term' ? 'term' : 'post';
		$force  = isset( $assoc['force'] );
		$queued = 0;
		foreach ( $args as $arg ) {
			$id    = Engine::source_of( $type, (int) $arg );
			$langs = isset( $assoc['lang'] ) ? array_map( 'trim', explode( ',', $assoc['lang'] ) ) : Languages::others( Translations::lang_or_default( $type, $id ) );
			$queued += Queue::enqueue( $type, $id, $langs, $force );
		}
		\WP_CLI::log( sprintf( '%d translation(s) queued.', $queued ) );
		$this->drain();
	}

	/**
	 * Queues every original missing a translation, then processes the queue.
	 *
	 * ## OPTIONS
	 *
	 * [--lang=<codes>]
	 * : Comma-separated target languages. Default: every other language.
	 *
	 * [--post_type=<types>]
	 * : Comma-separated post types. Default: every translatable post type.
	 *
	 * [--queue-only]
	 * : Only queue (WP-Cron processes the queue).
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function bulk( $args, $assoc ): void {
		$default = Languages::default_code();
		$langs   = isset( $assoc['lang'] ) ? array_map( 'trim', explode( ',', $assoc['lang'] ) ) : Languages::others( $default );
		$types   = isset( $assoc['post_type'] ) ? array_map( 'trim', explode( ',', $assoc['post_type'] ) ) : Content::post_types();
		// Pages first: they are the usual targets of internal links.
		usort( $types, static fn( $a, $b ) => (int) ( 'page' !== $a ) - (int) ( 'page' !== $b ) );
		$queued  = 0;
		foreach ( $types as $type ) {
			if ( ! Content::is_translatable_post_type( $type ) ) {
				continue;
			}
			$ids = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => array( 'publish', 'future', 'private', 'draft', 'pending' ),
					'posts_per_page' => -1,
					'fields'           => 'ids',
					'gaiml_lang'       => $default,
					'suppress_filters' => false,
				)
			);
			Translations::prime( 'post', array_map( 'intval', $ids ) );
			$jobs = array();
			foreach ( $ids as $id ) {
				if ( Engine::source_of( 'post', (int) $id ) !== (int) $id || ! Content::is_translatable_post( (int) $id ) ) {
					continue;
				}
				$jobs[ (int) $id ] = array_values( array_diff( $langs, array_keys( Translations::members( 'post', (int) $id ) ) ) );
			}
			$queued += Queue::enqueue_many( 'post', $jobs );
		}
		foreach ( $langs as $lang ) {
			if ( $lang !== $default ) {
				$queued += Queue::enqueue( 'strings', 0, array( $lang ) );
			}
		}
		\WP_CLI::log( sprintf( '%d translation(s) queued.', $queued ) );
		if ( ! isset( $assoc['queue-only'] ) ) {
			$this->drain();
		}
	}

	/**
	 * Manages the queue.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : run, status, retry or clear.
	 *
	 * @param string[] $args
	 */
	public function queue( $args ): void {
		switch ( $args[0] ?? 'status' ) {
			case 'run':
				$this->drain();
				break;
			case 'retry':
				\WP_CLI::log( sprintf( '%d job(s) retried.', Queue::retry_failed() ) );
				$this->drain();
				break;
			case 'clear':
				\WP_CLI::log( sprintf( '%d job(s) deleted.', Queue::clear() ) );
				break;
			default:
				$stats = Queue::stats();
				\WP_CLI::log( sprintf( 'Pending: %d, running: %d, failed: %d', $stats['pending'], $stats['running'], $stats['failed'] ) );
				foreach ( Queue::failed( 20 ) as $job ) {
					\WP_CLI::log( sprintf( '  failed %s #%d → %s: %s', $job->object_type, $job->object_id, $job->lang, $job->message ) );
				}
		}
	}

	/**
	 * Shows the languages and the API usage of the month.
	 */
	public function status(): void {
		foreach ( Languages::all() as $code => $language ) {
			$count = 0;
			foreach ( Content::post_types() as $type ) {
				$count += Translations::counts( 'post', $type )[ $code ] ?? 0;
			}
			\WP_CLI::log( sprintf( '%s %-6s %-22s /%s/  %d content(s)', Languages::is_default( $code ) ? '*' : ' ', $code, $language['name'], $language['slug'], $count ) );
		}
		foreach ( Usage::month() as $row ) {
			\WP_CLI::log( sprintf( 'Usage %s: %d requests, %d input / %d output tokens%s', $row['model'], $row['requests'], $row['input'], $row['output'], null === $row['cost'] ? '' : sprintf( ' ≈ $%.2f', $row['cost'] ) ) );
		}
	}

	private function drain(): void {
		$idle = 0;
		while ( true ) {
			$result = Queue::run( 30.0 );
			foreach ( $result['results'] as $row ) {
				if ( 'pending' === $row['status'] ) {
					continue;
				}
				$line = sprintf( '%s #%d → %s: %s%s', $row['type'], $row['id'], $row['lang'], $row['status'], '' !== $row['message'] ? ' (' . $row['message'] . ')' : '' );
				'done' === $row['status'] ? \WP_CLI::success( $line ) : \WP_CLI::warning( $line );
			}
			$left = $result['stats']['pending'] + $result['stats']['running'];
			if ( 0 === $left ) {
				break;
			}
			if ( 0 === $result['processed'] ) {
				if ( ++$idle > 60 ) {
					\WP_CLI::warning( 'Jobs are waiting for a retry delay; run "wp gaiml queue run" later.' );
					break;
				}
				sleep( 5 );
			} else {
				$idle = 0;
			}
		}
		$stats = Queue::stats();
		\WP_CLI::log( sprintf( 'Queue: %d pending, %d failed.', $stats['pending'] + $stats['running'], $stats['failed'] ) );
	}
}
