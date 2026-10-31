<?php
/**
 * Plugin settings (one autoloaded option) and API keys (encrypted, not autoloaded).
 *
 * API keys can also be defined in wp-config.php, which is the recommended way:
 *   define( 'GAIML_ANTHROPIC_API_KEY', 'sk-ant-...' );
 *   define( 'GAIML_OPENAI_API_KEY', 'sk-...' );
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual;

use GustinAI\Multilingual\Translation\Models;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION  = 'gaiml_settings';
	public const SECRETS = 'gaiml_secrets';

	public const PROVIDERS = array( 'anthropic', 'openai' );

	/** @var array<string, mixed>|null */
	private static ?array $cache = null;

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'default_lang'       => '',
			// Ordered list of language entries, see Languages::entry().
			'languages'          => array(),
			'hide_default'       => true,
			'post_types'         => array( 'post', 'page' ),
			'taxonomies'         => array( 'category', 'post_tag' ),
			'provider'           => 'anthropic',
			'anthropic_model'    => Models::RECOMMENDED['anthropic'],
			'openai_model'       => Models::RECOMMENDED['openai'],
			'effort'             => 'low',
			'context'            => '',
			'glossary'           => '',
			'formality'          => 'auto',
			'translate_slugs'    => true,
			'auto_update'        => false,
			'new_status'         => 'same',
			'hide_untranslated'  => true,
			'switcher_menu'      => '',
			'switcher_show'      => 'name',
			'switcher_style'     => 'dropdown',
			'x_default'          => true,
			'llms_txt'           => true,
			'schema'             => true,
			'link_localization'  => true,
			'delete_data'        => false,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	/**
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public static function update( array $values ): void {
		$new = array_merge( self::all(), $values );
		$new = array_intersect_key( $new, self::defaults() );
		update_option( self::OPTION, $new, true );
		self::$cache = $new;
		Languages::reset();
	}

	public static function reset_cache(): void {
		self::$cache = null;
		Languages::reset();
	}

	public static function provider(): string {
		$provider = (string) self::get( 'provider' );
		return in_array( $provider, self::PROVIDERS, true ) ? $provider : 'anthropic';
	}

	public static function model( ?string $provider = null ): string {
		$provider = $provider ?? self::provider();
		$model    = trim( (string) self::get( $provider . '_model' ) );
		return '' !== $model ? $model : Models::RECOMMENDED[ $provider ];
	}

	/* ---------------------------------------------------------------------
	 * API keys
	 * ------------------------------------------------------------------ */

	public static function key_constant( string $provider ): string {
		return 'anthropic' === $provider ? 'GAIML_ANTHROPIC_API_KEY' : 'GAIML_OPENAI_API_KEY';
	}

	public static function has_constant_key( string $provider ): bool {
		$constant = self::key_constant( $provider );
		return defined( $constant ) && '' !== (string) constant( $constant );
	}

	public static function api_key( string $provider ): string {
		if ( self::has_constant_key( $provider ) ) {
			return trim( (string) constant( self::key_constant( $provider ) ) );
		}
		$secrets = get_option( self::SECRETS, array() );
		if ( ! is_array( $secrets ) || empty( $secrets[ $provider ] ) ) {
			return '';
		}
		return Support\Crypto::decrypt( (string) $secrets[ $provider ] );
	}

	public static function set_api_key( string $provider, string $key ): void {
		if ( ! in_array( $provider, self::PROVIDERS, true ) ) {
			return;
		}
		$secrets = get_option( self::SECRETS, array() );
		if ( ! is_array( $secrets ) ) {
			$secrets = array();
		}
		$key = trim( $key );
		if ( '' === $key ) {
			unset( $secrets[ $provider ] );
		} else {
			$secrets[ $provider ] = Support\Crypto::encrypt( $key );
		}
		if ( false === get_option( self::SECRETS ) ) {
			add_option( self::SECRETS, $secrets, '', false );
		} else {
			update_option( self::SECRETS, $secrets, false );
		}
	}

	/**
	 * Masked hint of the stored key ("sk-ant-…a1b2"), never the key itself.
	 */
	public static function key_hint( string $provider ): string {
		$key = self::api_key( $provider );
		if ( '' === $key ) {
			return '';
		}
		return substr( $key, 0, 6 ) . '…' . substr( $key, -4 );
	}
}
