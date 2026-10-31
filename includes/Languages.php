<?php
/**
 * Language catalog and active languages.
 *
 * A language entry is:
 *   array( 'code' => 'pt-br', 'locale' => 'pt_BR', 'name' => 'Português (Brasil)',
 *          'english' => 'Portuguese (Brazil)', 'slug' => 'pt-br', 'hreflang' => 'pt-BR', 'rtl' => false )
 * The code is the internal identifier stored in the translations table; the slug
 * is the URL prefix (/pt-br/) and can be customised.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual;

defined( 'ABSPATH' ) || exit;

final class Languages {

	/** @var array<string, array<string, mixed>>|null code => entry */
	private static ?array $active = null;

	/** @var array<string, string>|null slug => code */
	private static ?array $slugs = null;

	/**
	 * Supported languages: code => array( locale, native name, English name, hreflang, rtl ).
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3?: string, 4?: bool}>
	 */
	public static function catalog(): array {
		return array(
			'af'    => array( 'af', 'Afrikaans', 'Afrikaans' ),
			'ar'    => array( 'ar', 'العربية', 'Arabic', 'ar', true ),
			'bg'    => array( 'bg_BG', 'Български', 'Bulgarian' ),
			'bn'    => array( 'bn_BD', 'বাংলা', 'Bengali' ),
			'ca'    => array( 'ca', 'Català', 'Catalan' ),
			'cs'    => array( 'cs_CZ', 'Čeština', 'Czech' ),
			'cy'    => array( 'cy', 'Cymraeg', 'Welsh' ),
			'da'    => array( 'da_DK', 'Dansk', 'Danish' ),
			'de'    => array( 'de_DE', 'Deutsch', 'German' ),
			'de-at' => array( 'de_AT', 'Deutsch (Österreich)', 'German (Austria)', 'de-AT' ),
			'de-ch' => array( 'de_CH', 'Deutsch (Schweiz)', 'German (Switzerland)', 'de-CH' ),
			'el'    => array( 'el', 'Ελληνικά', 'Greek' ),
			'en'    => array( 'en_US', 'English', 'English' ),
			'en-gb' => array( 'en_GB', 'English (UK)', 'English (UK)', 'en-GB' ),
			'es'    => array( 'es_ES', 'Español', 'Spanish' ),
			'es-mx' => array( 'es_MX', 'Español (México)', 'Spanish (Mexico)', 'es-MX' ),
			'et'    => array( 'et', 'Eesti', 'Estonian' ),
			'eu'    => array( 'eu', 'Euskara', 'Basque' ),
			'fa'    => array( 'fa_IR', 'فارسی', 'Persian', 'fa', true ),
			'fi'    => array( 'fi', 'Suomi', 'Finnish' ),
			'fr'    => array( 'fr_FR', 'Français', 'French' ),
			'fr-be' => array( 'fr_BE', 'Français (Belgique)', 'French (Belgium)', 'fr-BE' ),
			'fr-ca' => array( 'fr_CA', 'Français (Canada)', 'French (Canada)', 'fr-CA' ),
			'ga'    => array( 'ga', 'Gaeilge', 'Irish' ),
			'gl'    => array( 'gl_ES', 'Galego', 'Galician' ),
			'he'    => array( 'he_IL', 'עברית', 'Hebrew', 'he', true ),
			'hi'    => array( 'hi_IN', 'हिन्दी', 'Hindi' ),
			'hr'    => array( 'hr', 'Hrvatski', 'Croatian' ),
			'hu'    => array( 'hu_HU', 'Magyar', 'Hungarian' ),
			'id'    => array( 'id_ID', 'Bahasa Indonesia', 'Indonesian' ),
			'is'    => array( 'is_IS', 'Íslenska', 'Icelandic' ),
			'it'    => array( 'it_IT', 'Italiano', 'Italian' ),
			'ja'    => array( 'ja', '日本語', 'Japanese' ),
			'ko'    => array( 'ko_KR', '한국어', 'Korean' ),
			'lt'    => array( 'lt_LT', 'Lietuvių', 'Lithuanian' ),
			'lv'    => array( 'lv', 'Latviešu', 'Latvian' ),
			'ms'    => array( 'ms_MY', 'Bahasa Melayu', 'Malay' ),
			'nb'    => array( 'nb_NO', 'Norsk bokmål', 'Norwegian (Bokmål)' ),
			'nl'    => array( 'nl_NL', 'Nederlands', 'Dutch' ),
			'nl-be' => array( 'nl_BE', 'Nederlands (België)', 'Dutch (Belgium)', 'nl-BE' ),
			'pl'    => array( 'pl_PL', 'Polski', 'Polish' ),
			'pt'    => array( 'pt_PT', 'Português', 'Portuguese' ),
			'pt-br' => array( 'pt_BR', 'Português (Brasil)', 'Portuguese (Brazil)', 'pt-BR' ),
			'ro'    => array( 'ro_RO', 'Română', 'Romanian' ),
			'ru'    => array( 'ru_RU', 'Русский', 'Russian' ),
			'sk'    => array( 'sk_SK', 'Slovenčina', 'Slovak' ),
			'sl'    => array( 'sl_SI', 'Slovenščina', 'Slovenian' ),
			'sq'    => array( 'sq', 'Shqip', 'Albanian' ),
			'sr'    => array( 'sr_RS', 'Српски', 'Serbian' ),
			'sv'    => array( 'sv_SE', 'Svenska', 'Swedish' ),
			'th'    => array( 'th', 'ไทย', 'Thai' ),
			'tr'    => array( 'tr_TR', 'Türkçe', 'Turkish' ),
			'uk'    => array( 'uk', 'Українська', 'Ukrainian' ),
			'ur'    => array( 'ur', 'اردو', 'Urdu', 'ur', true ),
			'vi'    => array( 'vi', 'Tiếng Việt', 'Vietnamese' ),
			'zh'    => array( 'zh_CN', '简体中文', 'Chinese (Simplified)', 'zh-CN' ),
			'zh-tw' => array( 'zh_TW', '繁體中文', 'Chinese (Traditional)', 'zh-TW' ),
		);
	}

	/**
	 * Builds a full entry for a catalog code.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function entry( string $code ): ?array {
		$catalog = self::catalog();
		if ( ! isset( $catalog[ $code ] ) ) {
			return null;
		}
		$row = $catalog[ $code ];
		return array(
			'code'     => $code,
			'locale'   => $row[0],
			'name'     => $row[1],
			'english'  => $row[2],
			'slug'     => $code,
			'hreflang' => $row[3] ?? $code,
			'rtl'      => (bool) ( $row[4] ?? false ),
		);
	}

	/**
	 * Best catalog code for a WordPress locale (fr_FR => fr, pt_BR => pt-br).
	 */
	public static function code_from_locale( string $locale ): string {
		$exact = '';
		$lang  = strtolower( (string) strtok( $locale, '_' ) );
		foreach ( self::catalog() as $code => $row ) {
			if ( $row[0] === $locale ) {
				$exact = $code;
				break;
			}
		}
		if ( '' !== $exact ) {
			return $exact;
		}
		return isset( self::catalog()[ $lang ] ) ? $lang : 'en';
	}

	/**
	 * Active languages keyed by code, default language first.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		if ( null !== self::$active ) {
			return self::$active;
		}
		$list    = array();
		$default = (string) Settings::get( 'default_lang' );
		foreach ( (array) Settings::get( 'languages' ) as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['code'] ) ) {
				continue;
			}
			$code          = (string) $entry['code'];
			$base          = self::entry( $code ) ?? array();
			$list[ $code ] = array_merge(
				$base,
				array(
					'code'     => $code,
					'locale'   => (string) ( $entry['locale'] ?? ( $base['locale'] ?? 'en_US' ) ),
					'name'     => (string) ( $entry['name'] ?? ( $base['name'] ?? $code ) ),
					'english'  => (string) ( $entry['english'] ?? ( $base['english'] ?? $code ) ),
					'slug'     => (string) ( $entry['slug'] ?? $code ),
					'hreflang' => (string) ( $entry['hreflang'] ?? ( $base['hreflang'] ?? $code ) ),
					'rtl'      => (bool) ( $entry['rtl'] ?? ( $base['rtl'] ?? false ) ),
				)
			);
		}
		if ( isset( $list[ $default ] ) ) {
			$list = array( $default => $list[ $default ] ) + $list;
		}
		self::$active = $list;
		return $list;
	}

	public static function reset(): void {
		self::$active = null;
		self::$slugs  = null;
	}

	/**
	 * @return string[]
	 */
	public static function codes(): array {
		return array_keys( self::all() );
	}

	public static function is_ready(): bool {
		$all = self::all();
		return '' !== self::default_code() && isset( $all[ self::default_code() ] );
	}

	/**
	 * At least two languages: the site is multilingual.
	 */
	public static function is_multilingual(): bool {
		return self::is_ready() && count( self::all() ) > 1;
	}

	public static function default_code(): string {
		return (string) Settings::get( 'default_lang' );
	}

	public static function is_default( string $code ): bool {
		return $code === self::default_code();
	}

	public static function exists( ?string $code ): bool {
		return null !== $code && '' !== $code && isset( self::all()[ $code ] );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( string $code ): ?array {
		return self::all()[ $code ] ?? null;
	}

	/**
	 * Other active languages than $code.
	 *
	 * @return string[]
	 */
	public static function others( string $code ): array {
		return array_values( array_diff( self::codes(), array( $code ) ) );
	}

	public static function name( string $code ): string {
		$lang = self::get( $code );
		return $lang ? (string) $lang['name'] : $code;
	}

	public static function english_name( string $code ): string {
		$lang = self::get( $code ) ?? self::entry( $code );
		return $lang ? (string) $lang['english'] : $code;
	}

	public static function locale( string $code ): string {
		$lang = self::get( $code );
		return $lang ? (string) $lang['locale'] : 'en_US';
	}

	public static function hreflang( string $code ): string {
		$lang = self::get( $code );
		return $lang ? (string) $lang['hreflang'] : $code;
	}

	/**
	 * Value for the HTML lang attribute (BCP 47), e.g. "pt-BR".
	 */
	public static function html_lang( string $code ): string {
		return str_replace( '_', '-', self::locale( $code ) );
	}

	/**
	 * URL prefix of a language: '' for the default language when it is hidden.
	 */
	public static function url_slug( string $code ): string {
		$lang = self::get( $code );
		if ( ! $lang ) {
			return '';
		}
		if ( self::is_default( $code ) && Settings::get( 'hide_default' ) ) {
			return '';
		}
		return (string) $lang['slug'];
	}

	/**
	 * Language code for a URL slug (any active language, hidden default included).
	 */
	public static function by_slug( string $slug ): ?string {
		if ( null === self::$slugs ) {
			self::$slugs = array();
			foreach ( self::all() as $code => $lang ) {
				self::$slugs[ strtolower( (string) $lang['slug'] ) ] = $code;
			}
		}
		return self::$slugs[ strtolower( $slug ) ] ?? null;
	}

	/**
	 * Whether a slug is reserved as a language prefix (a page cannot use it at the root).
	 */
	public static function is_reserved_slug( string $slug ): bool {
		return null !== self::by_slug( $slug );
	}

	/**
	 * Formality instruction for the translation prompt.
	 */
	public static function formality( string $code ): string {
		$setting = (string) Settings::get( 'formality' );
		if ( 'formal' === $setting || 'informal' === $setting ) {
			return $setting;
		}
		return 'auto';
	}
}
