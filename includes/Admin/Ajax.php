<?php
/**
 * Admin AJAX endpoints and admin-post actions. Every action checks a nonce
 * and a capability.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Admin;

use GustinAI\Multilingual\Core\Content;
use GustinAI\Multilingual\Core\Translations;
use GustinAI\Multilingual\Languages;
use GustinAI\Multilingual\Settings;
use GustinAI\Multilingual\Translation\Engine;
use GustinAI\Multilingual\Translation\Memory;
use GustinAI\Multilingual\Translation\Models;
use GustinAI\Multilingual\Translation\Prompt;
use GustinAI\Multilingual\Translation\Providers\Provider;
use GustinAI\Multilingual\Translation\Queue;

defined( 'ABSPATH' ) || exit;

final class Ajax {

	public static function init(): void {
		foreach ( array( 'translate', 'process', 'models', 'test', 'bulk', 'estimate', 'queue', 'strings' ) as $action ) {
			add_action( 'wp_ajax_gaiml_' . $action, array( self::class, $action ) );
		}
		add_action( 'admin_post_gaiml_translate', array( self::class, 'translate_redirect' ) );
	}

	private static function check( string $capability = 'edit_posts' ): void {
		check_ajax_referer( 'gaiml_admin', 'nonce' );
		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'gustinai-multilingual' ) ), 403 );
		}
	}

	/**
	 * @return string[]
	 */
	private static function langs_param(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in self::check().
		$raw = isset( $_POST['langs'] ) ? wp_unslash( $_POST['langs'] ) : '';
		$raw = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		return array_values( array_filter( array_map( 'sanitize_key', $raw ), array( Languages::class, 'exists' ) ) );
	}

	/**
	 * Whether the current user may translate an object.
	 */
	private static function can_translate( string $type, int $id ): bool {
		if ( 'term' === $type ) {
			$term = get_term( $id );
			return $term instanceof \WP_Term && Content::is_translatable_taxonomy( $term->taxonomy ) && current_user_can( 'edit_term', $id );
		}
		$post = get_post( $id );
		if ( ! $post || ! Content::is_translatable_post( $post ) || ! current_user_can( 'edit_post', $id ) ) {
			return false;
		}
		$object = get_post_type_object( $post->post_type );
		return 'publish' !== $post->post_status || ( $object && current_user_can( $object->cap->publish_posts ) );
	}

	/**
	 * Queues translations of one post or term.
	 */
	public static function translate(): void {
		self::check();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in self::check().
		$type  = isset( $_POST['type'] ) && 'term' === $_POST['type'] ? 'term' : 'post';
		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$force = ! empty( $_POST['force'] );
		// phpcs:enable
		$id = Engine::source_of( $type, $id );
		if ( ! $id || ! self::can_translate( $type, $id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to translate this content.', 'gustinai-multilingual' ) ), 403 );
		}
		$count = Queue::enqueue( $type, $id, self::langs_param(), $force );
		wp_send_json_success(
			array(
				'queued' => $count,
				'id'     => $id,
			)
		);
	}

	/**
	 * Runs the queue for a few seconds (jobs of one object first).
	 */
	public static function process(): void {
		self::check();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in self::check().
		$type = isset( $_POST['type'] ) && in_array( $_POST['type'], array( 'post', 'term' ), true ) ? sanitize_key( $_POST['type'] ) : '';
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		// phpcs:enable
		$filter = ( '' !== $type && $id ) ? array(
			'type' => $type,
			'id'   => $id,
		) : array();
		$result = Queue::run( 8.0, $filter );
		$out    = array(
			'stats'   => $result['stats'],
			'results' => array(),
			'object'  => array(),
		);
		foreach ( $result['results'] as $row ) {
			$row['language']  = Languages::name( $row['lang'] );
			$row['edit']      = ( $row['target'] && 'post' === $row['type'] ) ? (string) get_edit_post_link( $row['target'], 'url' ) : '';
			$out['results'][] = $row;
		}
		if ( $filter ) {
			foreach ( Queue::jobs_for( $type, $id ) as $lang => $job ) {
				$out['object'][ $lang ] = array(
					'status'  => $job->status,
					'message' => (string) $job->message,
				);
			}
		}
		wp_send_json_success( $out );
	}

	/**
	 * Models available with a key.
	 */
	public static function models(): void {
		self::check( 'manage_options' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in self::check().
		$provider = isset( $_POST['provider'] ) && 'openai' === $_POST['provider'] ? 'openai' : 'anthropic';
		$key      = isset( $_POST['key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['key'] ) ) ) : '';
		// phpcs:enable
		$key = '' !== $key ? $key : Settings::api_key( $provider );
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Enter an API key first.', 'gustinai-multilingual' ) ) );
		}
		$models = Provider::make( $provider, $key )->models();
		if ( is_wp_error( $models ) ) {
			wp_send_json_error( array( 'message' => $models->get_error_message() ) );
		}
		$known = Models::known( $provider );
		$list  = array();
		foreach ( $models as $id => $label ) {
			$list[] = array(
				'id'    => $id,
				'label' => isset( $known[ $id ] ) ? $known[ $id ]['label'] . ' — $' . $known[ $id ]['input'] . ' / $' . $known[ $id ]['output'] : $label,
			);
		}
		wp_send_json_success( array( 'models' => $list ) );
	}

	/**
	 * Translates a sample sentence with the saved settings.
	 */
	public static function test(): void {
		self::check( 'manage_options' );
		$provider = Provider::current();
		if ( null === $provider ) {
			wp_send_json_error( array( 'message' => __( 'Save an API key first.', 'gustinai-multilingual' ) ) );
		}
		$source = Languages::default_code();
		$target = Languages::others( $source )[0] ?? ( 'en' === $source ? 'fr' : 'en' );
		if ( ! Languages::exists( $target ) ) {
			wp_send_json_error( array( 'message' => __( 'Add a second language first.', 'gustinai-multilingual' ) ) );
		}
		$sample = 'fr' === $source ? 'Bienvenue sur notre site : découvrez <g1>nos services</g1> en quelques minutes.' : 'Welcome to our website: discover <g1>our services</g1> in a few minutes.';
		$start  = microtime( true );
		$result = $provider->complete(
			Prompt::system( $source, $target ),
			Prompt::user(
				array(
					'1' => array(
						'text' => $sample,
						'kind' => 'text',
					),
				)
			)
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		$parsed = Prompt::parse( $result['text'] );
		wp_send_json_success(
			array(
				'model'       => $provider->model(),
				'translation' => wp_strip_all_tags( (string) ( $parsed['1'] ?? $result['text'] ) ),
				'seconds'     => round( microtime( true ) - $start, 1 ),
			)
		);
	}

	/**
	 * Originals of the selected types still missing (or outdated in) a language.
	 *
	 * @param string[] $types
	 * @param string[] $langs
	 * @return array<int, string[]> post ID => languages
	 */
	private static function candidates( array $types, array $langs, bool $outdated ): array {
		global $wpdb;
		$types = array_values( array_filter( $types, array( Content::class, 'is_translatable_post_type' ) ) );
		if ( ! $types || ! $langs ) {
			return array();
		}
		$default = Languages::default_code();
		$table   = Translations::table();
		$in      = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$ids     = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$table} t ON ( t.object_type = 'post' AND t.object_id = p.ID )
				WHERE p.post_type IN ($in) AND p.post_status IN ('publish','future','private','draft','pending')
				AND ( t.object_id IS NULL OR ( t.lang = %s AND t.group_id = p.ID ) ) ORDER BY p.post_type = 'page' DESC, p.ID ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( $types, array( $default ) )
			)
		);
		$out = array();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			if ( ! Content::is_translatable_post( $id ) ) {
				continue;
			}
			$members = Translations::members( 'post', $id );
			foreach ( $langs as $lang ) {
				if ( ! isset( $members[ $lang ] ) ) {
					$out[ $id ][] = $lang;
				} elseif ( $outdated && in_array( Engine::status( $id, $lang ), array( 'outdated', 'failed' ), true ) ) {
					$out[ $id ][] = $lang;
				}
			}
		}
		return $out;
	}

	/**
	 * @return array{types: string[], langs: string[], outdated: bool}
	 */
	private static function bulk_params(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in self::check().
		$types = isset( $_POST['types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['types'] ) ) : array();
		$out   = ! empty( $_POST['outdated'] );
		// phpcs:enable
		return array(
			'types'    => $types,
			'langs'    => self::langs_param(),
			'outdated' => $out,
		);
	}

	public static function estimate(): void {
		self::check( 'manage_options' );
		$params     = self::bulk_params();
		$candidates = self::candidates( $params['types'], $params['langs'], $params['outdated'] );
		$chars      = 0;
		$jobs       = 0;
		$sample     = 0;
		foreach ( $candidates as $id => $langs ) {
			$jobs += count( $langs );
			if ( $sample < 40 ) {
				$chars += Engine::chars( $id, $langs[0] ) * count( $langs );
				++$sample;
			}
		}
		if ( $sample > 0 && count( $candidates ) > $sample ) {
			$chars = (int) round( $chars / $sample * count( $candidates ) );
		}
		$provider = Settings::provider();
		$model    = Settings::model( $provider );
		$cost     = Models::estimate( $provider, $model, $chars );
		wp_send_json_success(
			array(
				'contents' => count( $candidates ),
				'jobs'     => $jobs,
				'chars'    => $chars,
				'model'    => $model,
				'cost'     => null === $cost ? null : number_format_i18n( max( $cost, $jobs ? 0.01 : 0 ), 2 ),
			)
		);
	}

	public static function bulk(): void {
		self::check( 'manage_options' );
		$params = self::bulk_params();
		$count  = 0;
		foreach ( self::candidates( $params['types'], $params['langs'], $params['outdated'] ) as $id => $langs ) {
			$count += Queue::enqueue( 'post', $id, $langs );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in self::check().
		if ( ! empty( $_POST['strings'] ) ) {
			foreach ( $params['langs'] as $lang ) {
				$count += Queue::enqueue( 'strings', 0, array( $lang ) );
			}
		}
		wp_send_json_success( array( 'queued' => $count ) );
	}

	public static function queue(): void {
		self::check( 'manage_options' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in self::check().
		$action = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		if ( 'retry' === $action ) {
			Queue::retry_failed();
			Queue::schedule();
		} elseif ( 'clear' === $action ) {
			Queue::clear();
		} elseif ( 'clear_failed' === $action ) {
			Queue::clear( true );
		}
		wp_send_json_success( array( 'stats' => Queue::stats() ) );
	}

	public static function strings(): void {
		self::check( 'manage_options' );
		$count = 0;
		foreach ( self::langs_param() as $lang ) {
			if ( ! Languages::is_default( $lang ) ) {
				$count += Queue::enqueue( 'strings', 0, array( $lang ), true );
			}
		}
		Memory::flush();
		wp_send_json_success( array( 'queued' => $count ) );
	}

	/**
	 * Toolbar link "Translate with AI": queues and opens the editor, where the
	 * translation runs with a progress indicator.
	 */
	public static function translate_redirect(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified below.
		$id   = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
		// phpcs:enable
		check_admin_referer( 'gaiml_translate_' . $id );
		if ( ! $id || ! self::can_translate( 'post', $id ) || ! Languages::exists( $lang ) ) {
			wp_die( esc_html__( 'You are not allowed to translate this content.', 'gustinai-multilingual' ), 403 );
		}
		Queue::enqueue( 'post', $id, array( $lang ) );
		wp_safe_redirect( add_query_arg( 'gaiml_run', '1', (string) get_edit_post_link( $id, 'url' ) ) );
		exit;
	}
}
