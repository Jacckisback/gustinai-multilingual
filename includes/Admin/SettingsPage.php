<?php
/**
 * Admin pages: settings (languages, AI translation, SEO & display), bulk
 * translation and strings.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Admin;

use GustinAI\Multilingual\Core\Content;
use GustinAI\Multilingual\Core\Links;
use GustinAI\Multilingual\Core\Translations;
use GustinAI\Multilingual\Frontend\Strings;
use GustinAI\Multilingual\Install;
use GustinAI\Multilingual\Languages;
use GustinAI\Multilingual\Settings;
use GustinAI\Multilingual\Translation\Memory;
use GustinAI\Multilingual\Translation\Models;
use GustinAI\Multilingual\Translation\Queue;
use GustinAI\Multilingual\Translation\Usage;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	public static function init(): void {
		add_action( 'admin_post_gaiml_save_settings', array( self::class, 'save' ) );
		add_action( 'admin_post_gaiml_save_strings', array( self::class, 'save_strings' ) );
	}

	private static function header( string $title, string $tab = '' ): void {
		echo '<div class="wrap gaiml-wrap"><h1>' . esc_html( $title ) . '</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = isset( $_GET['gaiml_notice'] ) ? sanitize_key( wp_unslash( $_GET['gaiml_notice'] ) ) : '';
		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'gustinai-multilingual' ) . '</p></div>';
		}
		$messages = get_transient( 'gaiml_admin_messages' );
		if ( is_array( $messages ) ) {
			delete_transient( 'gaiml_admin_messages' );
			foreach ( $messages as $message ) {
				echo '<div class="notice notice-' . esc_attr( $message[0] ) . ' is-dismissible"><p>' . esc_html( $message[1] ) . '</p></div>';
			}
		}
		if ( '' !== $tab ) {
			$tabs = array(
				'languages'   => __( 'Languages', 'gustinai-multilingual' ),
				'translation' => __( 'AI translation', 'gustinai-multilingual' ),
				'seo'         => __( 'SEO, GEO & display', 'gustinai-multilingual' ),
			);
			echo '<nav class="nav-tab-wrapper">';
			foreach ( $tabs as $key => $label ) {
				printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( admin_url( 'admin.php?page=gaiml&tab=' . $key ) ), $key === $tab ? ' nav-tab-active' : '', esc_html( $label ) );
			}
			echo '</nav>';
		}
	}

	private static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'languages';
		return in_array( $tab, array( 'languages', 'translation', 'seo' ), true ) ? $tab : 'languages';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab = self::current_tab();
		self::header( __( 'Multilingual', 'gustinai-multilingual' ), $tab );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="gaiml-form">';
		echo '<input type="hidden" name="action" value="gaiml_save_settings"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		wp_nonce_field( 'gaiml_save_settings' );
		if ( 'translation' === $tab ) {
			self::tab_translation();
		} elseif ( 'seo' === $tab ) {
			self::tab_seo();
		} else {
			self::tab_languages();
		}
		submit_button();
		echo '</form></div>';
	}

	private static function tab_languages(): void {
		$settings = Settings::all();
		$default  = Languages::default_code();
		echo '<h2>' . esc_html__( 'Site languages', 'gustinai-multilingual' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'The default language is the language of your existing content. Each other language gets its own URLs: /en/, /es/…', 'gustinai-multilingual' ) . '</p>';
		echo '<table class="widefat striped gaiml-languages"><thead><tr><th>' . esc_html__( 'Default', 'gustinai-multilingual' ) . '</th><th>' . esc_html__( 'Language', 'gustinai-multilingual' ) . '</th><th>' . esc_html__( 'URL prefix', 'gustinai-multilingual' ) . '</th><th>' . esc_html__( 'WordPress locale', 'gustinai-multilingual' ) . '</th><th>' . esc_html__( 'Content', 'gustinai-multilingual' ) . '</th><th>' . esc_html__( 'Remove', 'gustinai-multilingual' ) . '</th></tr></thead><tbody>';
		$counts = array();
		foreach ( Content::post_types() as $post_type ) {
			foreach ( Translations::counts( 'post', $post_type ) as $lang => $n ) {
				$counts[ $lang ] = ( $counts[ $lang ] ?? 0 ) + $n;
			}
		}
		foreach ( Languages::all() as $code => $language ) {
			echo '<tr>';
			printf( '<td><input type="radio" name="default_lang" value="%s"%s aria-label="%s"></td>', esc_attr( $code ), checked( $code, $default, false ), esc_attr__( 'Default language', 'gustinai-multilingual' ) );
			printf( '<td><strong>%s</strong> <code>%s</code><input type="hidden" name="languages[%s][code]" value="%s"></td>', esc_html( $language['name'] ), esc_html( $code ), esc_attr( $code ), esc_attr( $code ) );
			printf( '<td>/<input type="text" class="small-text" name="languages[%s][slug]" value="%s" pattern="[a-z0-9-]{2,12}">/</td>', esc_attr( $code ), esc_attr( $language['slug'] ) );
			printf( '<td><input type="text" class="regular-text" style="width:8em" name="languages[%s][locale]" value="%s"></td>', esc_attr( $code ), esc_attr( $language['locale'] ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( $counts[ $code ] ?? 0 ) ) );
			printf( '<td>%s</td>', $code === $default ? '—' : '<input type="checkbox" name="remove[]" value="' . esc_attr( $code ) . '" aria-label="' . esc_attr__( 'Remove', 'gustinai-multilingual' ) . '">' );
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p><label for="gaiml-add">' . esc_html__( 'Add languages:', 'gustinai-multilingual' ) . '</label> <select name="add[]" id="gaiml-add" multiple size="6" class="gaiml-add">';
		foreach ( Languages::catalog() as $code => $row ) {
			if ( ! Languages::exists( $code ) ) {
				printf( '<option value="%s">%s — %s (%s)</option>', esc_attr( $code ), esc_html( $row[1] ), esc_html( $row[2] ), esc_html( $code ) );
			}
		}
		echo '</select><br><span class="description">' . esc_html__( 'Ctrl/Cmd + click to select several. WordPress translations of each new language are downloaded automatically when possible.', 'gustinai-multilingual' ) . '</span></p>';

		echo '<table class="form-table" role="presentation">';
		self::checkbox_row( 'hide_default', __( 'URLs of the default language', 'gustinai-multilingual' ), __( 'No prefix for the default language (recommended: your current URLs do not change)', 'gustinai-multilingual' ), (bool) $settings['hide_default'] );

		echo '<tr><th scope="row">' . esc_html__( 'Translated content', 'gustinai-multilingual' ) . '</th><td><fieldset>';
		$types = get_post_types( array( 'public' => true ), 'objects' );
		foreach ( array( 'wp_block', 'elementor_library' ) as $extra ) {
			$object = get_post_type_object( $extra );
			if ( $object ) {
				$types[ $extra ] = $object;
			}
		}
		unset( $types['attachment'] );
		foreach ( $types as $type ) {
			printf( '<label><input type="checkbox" name="post_types[]" value="%s"%s> %s <code>%s</code></label><br>', esc_attr( $type->name ), checked( in_array( $type->name, (array) $settings['post_types'], true ), true, false ), esc_html( $type->labels->name ), esc_html( $type->name ) );
		}
		echo '</fieldset></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Translated taxonomies', 'gustinai-multilingual' ) . '</th><td><fieldset>';
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			if ( 'post_format' === $taxonomy->name ) {
				continue;
			}
			printf( '<label><input type="checkbox" name="taxonomies[]" value="%s"%s> %s <code>%s</code></label><br>', esc_attr( $taxonomy->name ), checked( in_array( $taxonomy->name, (array) $settings['taxonomies'], true ), true, false ), esc_html( $taxonomy->labels->name ), esc_html( $taxonomy->name ) );
		}
		echo '</fieldset></td></tr>';
		echo '</table>';
	}

	private static function tab_translation(): void {
		$settings = Settings::all();
		$provider = Settings::provider();
		echo '<h2>' . esc_html__( 'AI provider', 'gustinai-multilingual' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__( 'Translate with', 'gustinai-multilingual' ) . '</th><td><fieldset>';
		foreach ( array(
			'anthropic' => __( 'Claude (Anthropic)', 'gustinai-multilingual' ),
			'openai'    => __( 'ChatGPT models (OpenAI)', 'gustinai-multilingual' ),
		) as $key => $label ) {
			printf( '<label><input type="radio" name="provider" value="%s"%s> %s</label><br>', esc_attr( $key ), checked( $provider, $key, false ), esc_html( $label ) );
		}
		echo '</fieldset></td></tr>';

		foreach ( Settings::PROVIDERS as $key ) {
			$name     = 'anthropic' === $key ? 'Claude' : 'OpenAI';
			$console  = 'anthropic' === $key ? 'https://platform.claude.com/settings/keys' : 'https://platform.openai.com/api-keys';
			$hint     = Settings::key_hint( $key );
			$constant = Settings::has_constant_key( $key );
			echo '<tr class="gaiml-provider" data-provider="' . esc_attr( $key ) . '"><th scope="row">' . esc_html( sprintf( /* translators: %s: provider name. */ __( '%s API key', 'gustinai-multilingual' ), $name ) ) . '</th><td>';
			if ( $constant ) {
				echo '<p>' . esc_html( sprintf( /* translators: %s: constant name. */ __( 'Defined in wp-config.php (%s).', 'gustinai-multilingual' ), Settings::key_constant( $key ) ) ) . ' <code>' . esc_html( $hint ) . '</code></p>';
			} else {
				printf(
					'<input type="password" class="regular-text gaiml-key" name="key_%1$s" value="" autocomplete="new-password" placeholder="%2$s"> ',
					esc_attr( $key ),
					esc_attr( '' !== $hint ? $hint : __( 'Paste your key', 'gustinai-multilingual' ) )
				);
				if ( '' !== $hint ) {
					printf( '<label><input type="checkbox" name="delete_key_%s" value="1"> %s</label>', esc_attr( $key ), esc_html__( 'Delete the saved key', 'gustinai-multilingual' ) );
				}
				echo '<p class="description">' . wp_kses(
					sprintf(
						/* translators: 1: link to the provider console, 2: constant name. */
						__( 'Get a key in the %1$s. Stored encrypted. Even safer: define %2$s in wp-config.php.', 'gustinai-multilingual' ),
						/* translators: %s: provider name. */
						'<a href="' . esc_url( $console ) . '" target="_blank" rel="noopener">' . esc_html( sprintf( __( '%s console', 'gustinai-multilingual' ), $name ) ) . '</a>',
						'<code>' . esc_html( Settings::key_constant( $key ) ) . '</code>'
					),
					array(
						'a'    => array(
							'href'   => true,
							'target' => true,
							'rel'    => true,
						),
						'code' => array(),
					)
				) . '</p>';
			}
			echo '</td></tr>';

			$model  = Settings::model( $key );
			$known  = Models::known( $key );
			$custom = ! isset( $known[ $model ] );
			echo '<tr class="gaiml-provider" data-provider="' . esc_attr( $key ) . '"><th scope="row">' . esc_html( sprintf( /* translators: %s: provider name. */ __( '%s model', 'gustinai-multilingual' ), $name ) ) . '</th><td>';
			echo '<select name="' . esc_attr( $key ) . '_model_select" class="gaiml-model-select" data-provider="' . esc_attr( $key ) . '">';
			foreach ( $known as $id => $info ) {
				/* translators: 1: model name, 2: input price, 3: output price (US dollars per million tokens). */
				$label = sprintf( __( '%1$s — $%2$s in / $%3$s out per 1M tokens', 'gustinai-multilingual' ), $info['label'], $info['input'], $info['output'] );
				if ( Models::RECOMMENDED[ $key ] === $id ) {
					$label = '★ ' . $label . ' — ' . __( 'recommended', 'gustinai-multilingual' );
				}
				printf( '<option value="%s"%s>%s</option>', esc_attr( $id ), selected( $model, $id, false ), esc_html( $label ) );
			}
			printf( '<option value="__custom"%s>%s</option>', selected( $custom, true, false ), esc_html__( 'Other model (enter its ID)…', 'gustinai-multilingual' ) );
			echo '</select> ';
			printf( '<input type="text" class="regular-text gaiml-model-custom" name="%s_model_custom" value="%s" placeholder="%s"%s> ', esc_attr( $key ), esc_attr( $custom ? $model : '' ), esc_attr__( 'model ID', 'gustinai-multilingual' ), $custom ? '' : ' hidden' );
			printf( '<button type="button" class="button gaiml-load-models" data-provider="%s">%s</button>', esc_attr( $key ), esc_html__( 'Load the models of my account', 'gustinai-multilingual' ) );
			echo '<ul class="gaiml-model-notes">';
			foreach ( $known as $id => $info ) {
				echo '<li><strong>' . esc_html( $info['label'] ) . '</strong> — ' . esc_html( $info['note'] ) . '</li>';
			}
			echo '</ul>';
			/* translators: %s: date. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Prices verified on %s. Any model of your account can be used.', 'gustinai-multilingual' ), Models::VERIFIED ) ) . '</p>';
			echo '</td></tr>';
		}

		echo '<tr><th scope="row"><label for="gaiml-effort">' . esc_html__( 'Reasoning effort', 'gustinai-multilingual' ) . '</label></th><td><select name="effort" id="gaiml-effort">';
		foreach ( array(
			'low'    => __( 'Low (recommended for translation: fast, cheaper)', 'gustinai-multilingual' ),
			'medium' => __( 'Medium', 'gustinai-multilingual' ),
			'high'   => __( 'High (slower, more expensive)', 'gustinai-multilingual' ),
		) as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $settings['effort'], $key, false ), esc_html( $label ) );
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Connection', 'gustinai-multilingual' ) . '</th><td><button type="button" class="button gaiml-test">' . esc_html__( 'Test with a sample sentence', 'gustinai-multilingual' ) . '</button> <span class="gaiml-test-result" aria-live="polite"></span><p class="description">' . esc_html__( 'Save the settings before testing.', 'gustinai-multilingual' ) . '</p></td></tr>';
		echo '</table>';

		echo '<h2>' . esc_html__( 'Translation style', 'gustinai-multilingual' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="gaiml-context">' . esc_html__( 'About your site', 'gustinai-multilingual' ) . '</label></th><td><textarea name="context" id="gaiml-context" rows="4" class="large-text" placeholder="' . esc_attr__( 'Example: AI training company in Madrid for SMEs. Professional but warm tone. Target audience: business owners.', 'gustinai-multilingual' ) . '">' . esc_textarea( (string) $settings['context'] ) . '</textarea><p class="description">' . esc_html__( 'Helps the AI choose terminology and tone.', 'gustinai-multilingual' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="gaiml-glossary">' . esc_html__( 'Glossary', 'gustinai-multilingual' ) . '</label></th><td><textarea name="glossary" id="gaiml-glossary" rows="6" class="large-text code" placeholder="' . esc_attr( "GustinAI\natelier = workshop\ncocktail = es: cóctel | de: Cocktail" ) . '">' . esc_textarea( (string) $settings['glossary'] ) . '</textarea><p class="description">' . esc_html__( 'One term per line. "Brand" alone: never translated. "term = translation": same translation in every language. "term = es: … | de: …": per language.', 'gustinai-multilingual' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="gaiml-formality">' . esc_html__( 'Form of address', 'gustinai-multilingual' ) . '</label></th><td><select name="formality" id="gaiml-formality">';
		foreach ( array(
			'auto'     => __( 'Automatic (follows the original)', 'gustinai-multilingual' ),
			'formal'   => __( 'Formal (vous, usted, Sie)', 'gustinai-multilingual' ),
			'informal' => __( 'Informal (tu, tú, du)', 'gustinai-multilingual' ),
		) as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $settings['formality'], $key, false ), esc_html( $label ) );
		}
		echo '</select></td></tr>';
		self::checkbox_row( 'translate_slugs', __( 'URLs', 'gustinai-multilingual' ), __( 'Translate slugs (/en/summer-cocktails/ instead of /en/cocktails-ete/)', 'gustinai-multilingual' ), (bool) $settings['translate_slugs'] );
		self::checkbox_row( 'auto_update', __( 'Updates', 'gustinai-multilingual' ), __( 'Update translations automatically when the original changes (translations edited by hand are kept)', 'gustinai-multilingual' ), (bool) $settings['auto_update'] );
		echo '<tr><th scope="row"><label for="gaiml-status">' . esc_html__( 'New translations', 'gustinai-multilingual' ) . '</label></th><td><select name="new_status" id="gaiml-status">';
		foreach ( array(
			'same'  => __( 'Same status as the original (published if the original is)', 'gustinai-multilingual' ),
			'draft' => __( 'Draft, to review before publishing', 'gustinai-multilingual' ),
		) as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $settings['new_status'], $key, false ), esc_html( $label ) );
		}
		echo '</select></td></tr></table>';

		$usage = Usage::month();
		if ( $usage ) {
			echo '<h2>' . esc_html__( 'Usage this month', 'gustinai-multilingual' ) . '</h2><table class="widefat striped" style="max-width:720px"><thead><tr><th>' . esc_html__( 'Model', 'gustinai-multilingual' ) . '</th><th>' . esc_html__( 'Requests', 'gustinai-multilingual' ) . '</th><th>' . esc_html__( 'Input tokens', 'gustinai-multilingual' ) . '</th><th>' . esc_html__( 'Output tokens', 'gustinai-multilingual' ) . '</th><th>' . esc_html__( 'Estimated cost', 'gustinai-multilingual' ) . '</th></tr></thead><tbody>';
			foreach ( $usage as $row ) {
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_html( $row['model'] ),
					esc_html( number_format_i18n( $row['requests'] ) ),
					esc_html( number_format_i18n( $row['input'] ) ),
					esc_html( number_format_i18n( $row['output'] ) ),
					null === $row['cost'] ? '—' : esc_html( '$' . number_format_i18n( $row['cost'], 2 ) )
				);
			}
			echo '</tbody></table>';
		}
	}

	private static function tab_seo(): void {
		$settings = Settings::all();
		echo '<h2>' . esc_html__( 'SEO and GEO', 'gustinai-multilingual' ) . '</h2><table class="form-table" role="presentation">';
		self::checkbox_row( 'x_default', __( 'hreflang', 'gustinai-multilingual' ), __( 'Add x-default (the default language version) to the hreflang links', 'gustinai-multilingual' ), (bool) $settings['x_default'] );
		self::checkbox_row( 'schema', __( 'Structured data', 'gustinai-multilingual' ), __( 'Language and translation links in the schema.org graph (Yoast SEO, Rank Math, or a minimal graph without SEO plugin)', 'gustinai-multilingual' ), (bool) $settings['schema'] );
		$llms = Links::raw_home() . '/llms.txt';
		self::checkbox_row( 'llms_txt', 'llms.txt', __( 'Publish an llms.txt per language for AI assistants', 'gustinai-multilingual' ) . ' — ' . $llms, (bool) $settings['llms_txt'] );
		self::checkbox_row( 'link_localization', __( 'Internal links', 'gustinai-multilingual' ), __( 'Point the links of translated content to the translated pages', 'gustinai-multilingual' ), (bool) $settings['link_localization'] );
		self::checkbox_row( 'hide_untranslated', __( 'Menus', 'gustinai-multilingual' ), __( 'Hide menu items whose page is not translated (no mixed languages)', 'gustinai-multilingual' ), (bool) $settings['hide_untranslated'] );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Language switcher', 'gustinai-multilingual' ) . '</h2>';
		echo '<p>' . wp_kses(
			__( 'Add it anywhere with the <strong>Language switcher</strong> block, the shortcode <code>[gaiml_switcher]</code> (Elementor, Divi: shortcode or text module) or <code>gaiml_language_switcher()</code> in a theme.', 'gustinai-multilingual' ),
			array(
				'strong' => array(),
				'code'   => array(),
			)
		) . '</p><table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="gaiml-menu">' . esc_html__( 'Add to a menu', 'gustinai-multilingual' ) . '</label></th><td><select name="switcher_menu" id="gaiml-menu"><option value="">' . esc_html__( 'No', 'gustinai-multilingual' ) . '</option>';
		foreach ( get_registered_nav_menus() as $location => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $location ), selected( $settings['switcher_menu'], $location, false ), esc_html( $label ) );
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="gaiml-style">' . esc_html__( 'Style', 'gustinai-multilingual' ) . '</label></th><td><select name="switcher_style" id="gaiml-style">';
		foreach ( array(
			'dropdown' => __( 'Dropdown', 'gustinai-multilingual' ),
			'list'     => __( 'List', 'gustinai-multilingual' ),
		) as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $settings['switcher_style'], $key, false ), esc_html( $label ) );
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="gaiml-show">' . esc_html__( 'Show', 'gustinai-multilingual' ) . '</label></th><td><select name="switcher_show" id="gaiml-show">';
		foreach ( array(
			'name' => __( 'Language name (Français, English)', 'gustinai-multilingual' ),
			'code' => __( 'Code (FR, EN)', 'gustinai-multilingual' ),
			'both' => __( 'Code and name', 'gustinai-multilingual' ),
		) as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $settings['switcher_show'], $key, false ), esc_html( $label ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Flags are not offered on purpose: a flag is a country, not a language.', 'gustinai-multilingual' ) . '</p></td></tr>';
		echo '</table>';

		echo '<h2>' . esc_html__( 'Uninstall', 'gustinai-multilingual' ) . '</h2><table class="form-table" role="presentation">';
		self::checkbox_row( 'delete_data', __( 'Data', 'gustinai-multilingual' ), __( 'Delete the settings, language links and translation memory when the plugin is deleted (translated pages are kept)', 'gustinai-multilingual' ), (bool) $settings['delete_data'] );
		echo '</table>';
	}

	private static function checkbox_row( string $name, string $title, string $label, bool $checked ): void {
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="hidden" name="%2$s" value="0"><input type="checkbox" name="%2$s" value="1"%3$s> %4$s</label></td></tr>',
			esc_html( $title ),
			esc_attr( $name ),
			checked( $checked, true, false ),
			esc_html( $label )
		);
	}

	/* ---------------------------------------------------------------------
	 * Saving
	 * ------------------------------------------------------------------ */

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'gustinai-multilingual' ), 403 );
		}
		check_admin_referer( 'gaiml_save_settings' );
		$tab      = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'languages';
		$values   = array();
		$messages = array();
		$bools    = array(
			'languages'   => array( 'hide_default' ),
			'translation' => array( 'translate_slugs', 'auto_update' ),
			'seo'         => array( 'x_default', 'schema', 'llms_txt', 'link_localization', 'hide_untranslated', 'delete_data' ),
		);
		foreach ( $bools[ $tab ] ?? array() as $key ) {
			$values[ $key ] = ! empty( $_POST[ $key ] );
		}

		if ( 'languages' === $tab ) {
			$values   = array_merge( $values, self::sanitize_languages( $messages ) );
			$public   = array_keys( get_post_types( array( 'public' => true ) ) );
			$allowed  = array_merge( $public, array( 'wp_block', 'elementor_library' ) );
			$types    = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ) ) : array();
			$taxes    = isset( $_POST['taxonomies'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['taxonomies'] ) ) : array();
			$values['post_types'] = array_values( array_intersect( $types, $allowed ) );
			$values['taxonomies'] = array_values( array_intersect( $taxes, get_taxonomies( array( 'public' => true ) ) ) );
		} elseif ( 'translation' === $tab ) {
			$provider           = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : 'anthropic';
			$values['provider'] = in_array( $provider, Settings::PROVIDERS, true ) ? $provider : 'anthropic';
			foreach ( Settings::PROVIDERS as $key ) {
				$select = isset( $_POST[ $key . '_model_select' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key . '_model_select' ] ) ) : '';
				$custom = isset( $_POST[ $key . '_model_custom' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key . '_model_custom' ] ) ) : '';
				$model  = '__custom' === $select ? $custom : $select;
				$model  = (string) preg_replace( '~[^A-Za-z0-9._:/@-]~', '', $model );
				$values[ $key . '_model' ] = '' !== $model ? $model : Models::RECOMMENDED[ $key ];
				if ( ! empty( $_POST[ 'delete_key_' . $key ] ) ) {
					Settings::set_api_key( $key, '' );
				}
				$new_key = isset( $_POST[ 'key_' . $key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ 'key_' . $key ] ) ) ) : '';
				if ( '' !== $new_key && ! Settings::has_constant_key( $key ) ) {
					Settings::set_api_key( $key, $new_key );
				}
			}
			$effort              = isset( $_POST['effort'] ) ? sanitize_key( wp_unslash( $_POST['effort'] ) ) : 'low';
			$values['effort']    = in_array( $effort, array( 'low', 'medium', 'high' ), true ) ? $effort : 'low';
			$values['context']   = isset( $_POST['context'] ) ? sanitize_textarea_field( wp_unslash( $_POST['context'] ) ) : '';
			$values['glossary']  = isset( $_POST['glossary'] ) ? sanitize_textarea_field( wp_unslash( $_POST['glossary'] ) ) : '';
			$formality           = isset( $_POST['formality'] ) ? sanitize_key( wp_unslash( $_POST['formality'] ) ) : 'auto';
			$values['formality'] = in_array( $formality, array( 'auto', 'formal', 'informal' ), true ) ? $formality : 'auto';
			$status              = isset( $_POST['new_status'] ) ? sanitize_key( wp_unslash( $_POST['new_status'] ) ) : 'same';
			$values['new_status'] = 'draft' === $status ? 'draft' : 'same';
		} elseif ( 'seo' === $tab ) {
			$menu                     = isset( $_POST['switcher_menu'] ) ? sanitize_key( wp_unslash( $_POST['switcher_menu'] ) ) : '';
			$values['switcher_menu']  = isset( get_registered_nav_menus()[ $menu ] ) ? $menu : '';
			$style                    = isset( $_POST['switcher_style'] ) ? sanitize_key( wp_unslash( $_POST['switcher_style'] ) ) : 'dropdown';
			$values['switcher_style'] = 'list' === $style ? 'list' : 'dropdown';
			$show                     = isset( $_POST['switcher_show'] ) ? sanitize_key( wp_unslash( $_POST['switcher_show'] ) ) : 'name';
			$values['switcher_show']  = in_array( $show, array( 'name', 'code', 'both' ), true ) ? $show : 'name';
		}

		Settings::update( $values );
		if ( 'languages' === $tab ) {
			Install::backfill();
			delete_transient( 'gaiml_backfill' );
		}
		if ( $messages ) {
			set_transient( 'gaiml_admin_messages', $messages, 60 );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=gaiml&tab=' . $tab . '&gaiml_notice=saved' ) );
		exit;
	}

	/**
	 * @param array<int, array{0: string, 1: string}> $messages
	 * @return array<string, mixed>
	 */
	private static function sanitize_languages( array &$messages ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in save().
		$posted  = isset( $_POST['languages'] ) && is_array( $_POST['languages'] ) ? wp_unslash( $_POST['languages'] ) : array();
		$remove  = isset( $_POST['remove'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['remove'] ) ) : array();
		$add     = isset( $_POST['add'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['add'] ) ) : array();
		$default = isset( $_POST['default_lang'] ) ? sanitize_key( wp_unslash( $_POST['default_lang'] ) ) : Languages::default_code();
		// phpcs:enable
		$list     = array();
		$slugs    = array();
		$reserved = self::reserved_slugs();
		foreach ( $posted as $code => $row ) {
			$code = sanitize_key( (string) $code );
			if ( ! Languages::exists( $code ) ) {
				continue;
			}
			if ( in_array( $code, $remove, true ) && $code !== $default ) {
				$messages[] = array(
					'warning',
					/* translators: %s: language name. */
					sprintf( __( '%s removed. Its content stays in the database but is no longer shown on the site, in the sitemaps or in the language switcher. Add the language again to bring it back.', 'gustinai-multilingual' ), Languages::name( $code ) ),
				);
				continue;
			}
			$entry  = Languages::get( $code );
			$asked  = strtolower( (string) preg_replace( '~[^a-z0-9-]~i', '', (string) ( $row['slug'] ?? $code ) ) );
			$slug   = self::free_slug( $asked, $code, $slugs, $reserved );
			$locale = (string) preg_replace( '~[^A-Za-z0-9_]~', '', (string) ( $row['locale'] ?? $entry['locale'] ) );
			if ( $slug !== $asked && '' !== $asked ) {
				$messages[] = array(
					'warning',
					/* translators: 1: requested URL prefix, 2: URL prefix used instead. */
					sprintf( __( 'The URL prefix "%1$s" is already used by WordPress or another language: "%2$s" is used instead.', 'gustinai-multilingual' ), $asked, $slug ),
				);
			}
			$slugs[ $slug ] = true;
			$list[ $code ]  = array_merge(
				$entry,
				array(
					'slug'   => $slug,
					'locale' => '' !== $locale ? $locale : $entry['locale'],
				)
			);
		}
		$downloaded = array();
		foreach ( $add as $code ) {
			$entry = Languages::entry( $code );
			if ( ! $entry || isset( $list[ $code ] ) ) {
				continue;
			}
			$entry['slug']           = self::free_slug( (string) $entry['slug'], $code, $slugs, $reserved );
			$slugs[ $entry['slug'] ] = true;
			$list[ $code ]           = $entry;
			$downloaded[]            = $entry['locale'];
		}
		if ( ! isset( $list[ $default ] ) ) {
			$default = (string) array_key_first( $list );
		}
		foreach ( $downloaded as $locale ) {
			if ( 'en_US' === $locale ) {
				continue;
			}
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
			if ( current_user_can( 'install_languages' ) && wp_can_install_language_pack() ) {
				$result = wp_download_language_pack( $locale );
				if ( false === $result ) {
					/* translators: %s: locale. */
					$messages[] = array( 'warning', sprintf( __( 'WordPress translations for %s could not be downloaded: themes and plugins will show their texts in English in that language until you install them (Dashboard > Updates).', 'gustinai-multilingual' ), $locale ) );
				}
			}
		}
		return array(
			'default_lang' => $default,
			'languages'    => array_values( $list ),
		);
	}

	/**
	 * First URL segments used by WordPress routes: a language prefix cannot take them.
	 *
	 * @return string[]
	 */
	private static function reserved_slugs(): array {
		global $wp_rewrite;
		$reserved = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login', 'wp-sitemap', 'xmlrpc', 'feed', 'rss', 'rss2', 'atom', 'rdf', 'page', 'comments', 'comment-page', 'search', 'category', 'tag', 'author', 'date', 'embed', 'trackback', 'attachment', 'type', 'llms', 'robots', 'favicon' );
		$bases    = array( (string) get_option( 'category_base' ), (string) get_option( 'tag_base' ) );
		if ( $wp_rewrite instanceof \WP_Rewrite ) {
			$bases[] = (string) $wp_rewrite->front;
			$bases[] = (string) $wp_rewrite->search_base;
			$bases[] = (string) $wp_rewrite->author_base;
		}
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $object ) {
			if ( is_array( $object->rewrite ) && ! empty( $object->rewrite['slug'] ) ) {
				$bases[] = (string) $object->rewrite['slug'];
			}
			if ( is_string( $object->has_archive ) ) {
				$bases[] = $object->has_archive;
			}
		}
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $object ) {
			if ( is_array( $object->rewrite ) && ! empty( $object->rewrite['slug'] ) ) {
				$bases[] = (string) $object->rewrite['slug'];
			}
		}
		foreach ( $bases as $base ) {
			$first = strtolower( (string) strtok( trim( $base, '/' ), '/' ) );
			if ( '' !== $first && false === strpos( $first, '%' ) ) {
				$reserved[] = $first;
			}
		}
		return array_values( array_unique( $reserved ) );
	}

	/**
	 * $slug when it is free, otherwise the language code (or code-2, code-3…).
	 *
	 * @param array<string, true> $taken
	 * @param string[]            $reserved
	 */
	private static function free_slug( string $slug, string $code, array $taken, array $reserved ): string {
		$free = static fn( string $s ): bool => strlen( $s ) >= 2 && ! isset( $taken[ $s ] ) && ! in_array( $s, $reserved, true );
		if ( $free( $slug ) ) {
			return $slug;
		}
		$base = trim( (string) preg_replace( '~[^a-z0-9-]+~', '-', strtolower( $code ) ), '-' );
		if ( $free( $base ) ) {
			return $base;
		}
		for ( $i = 2; ; $i++ ) {
			if ( $free( $base . '-' . $i ) ) {
				return $base . '-' . $i;
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Bulk translation
	 * ------------------------------------------------------------------ */

	public static function render_bulk(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::header( __( 'Bulk translation', 'gustinai-multilingual' ) );
		if ( ! Languages::is_multilingual() ) {
			echo '<p>' . esc_html__( 'Add at least one language first.', 'gustinai-multilingual' ) . '</p></div>';
			return;
		}
		$default = Languages::default_code();
		echo '<div class="gaiml-bulk"><p>' . esc_html__( 'Translates every original that has no translation yet. The work runs in the background: you can leave this page, it continues every minute (WP-Cron) until it is done.', 'gustinai-multilingual' ) . '</p>';
		echo '<table class="widefat striped gaiml-coverage"><thead><tr><th>' . esc_html__( 'Content', 'gustinai-multilingual' ) . '</th>';
		foreach ( Languages::all() as $language ) {
			echo '<th>' . esc_html( $language['name'] ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( Content::post_types() as $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( ! $object ) {
				continue;
			}
			$counts = Translations::counts( 'post', $post_type );
			echo '<tr><td>' . esc_html( $object->labels->name ) . '</td>';
			foreach ( Languages::codes() as $code ) {
				echo '<td>' . esc_html( number_format_i18n( $counts[ $code ] ?? 0 ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<form class="gaiml-bulk-form"><table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__( 'Into', 'gustinai-multilingual' ) . '</th><td>';
		foreach ( Languages::all() as $code => $language ) {
			if ( $code !== $default ) {
				printf( '<label><input type="checkbox" name="langs[]" value="%s" checked> %s</label><br>', esc_attr( $code ), esc_html( $language['name'] ) );
			}
		}
		echo '</td></tr><tr><th scope="row">' . esc_html__( 'Content', 'gustinai-multilingual' ) . '</th><td>';
		foreach ( Content::post_types() as $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( $object ) {
				printf( '<label><input type="checkbox" name="types[]" value="%s"%s> %s</label><br>', esc_attr( $post_type ), checked( in_array( $post_type, array( 'post', 'page' ), true ), true, false ), esc_html( $object->labels->name ) );
			}
		}
		echo '<label><input type="checkbox" name="strings" value="1" checked> ' . esc_html__( 'Site title, tagline and menus', 'gustinai-multilingual' ) . '</label><br>';
		echo '<label><input type="checkbox" name="outdated" value="1"> ' . esc_html__( 'Also update translations whose original changed', 'gustinai-multilingual' ) . '</label>';
		echo '</td></tr></table>';
		echo '<p><button type="button" class="button gaiml-estimate">' . esc_html__( 'Estimate the cost', 'gustinai-multilingual' ) . '</button> <button type="button" class="button button-primary gaiml-bulk-start">' . esc_html__( 'Start the translation', 'gustinai-multilingual' ) . '</button> <button type="button" class="button gaiml-bulk-stop" hidden>' . esc_html__( 'Pause', 'gustinai-multilingual' ) . '</button></p>';
		echo '<p class="gaiml-estimate-result" aria-live="polite"></p>';
		echo '<div class="gaiml-bar" hidden><div class="gaiml-bar__fill"></div></div><p class="gaiml-progress" aria-live="polite"></p><ol class="gaiml-log"></ol></form>';

		$stats = Queue::stats();
		echo '<h2>' . esc_html__( 'Queue', 'gustinai-multilingual' ) . '</h2><p class="gaiml-queue-stats">';
		/* translators: 1: waiting jobs, 2: failed jobs. */
		echo esc_html( sprintf( __( '%1$d waiting, %2$d failed.', 'gustinai-multilingual' ), $stats['pending'] + $stats['running'], $stats['failed'] ) );
		echo '</p><p><button type="button" class="button gaiml-queue" data-do="retry">' . esc_html__( 'Retry failed translations', 'gustinai-multilingual' ) . '</button> <button type="button" class="button gaiml-queue" data-do="clear">' . esc_html__( 'Empty the queue', 'gustinai-multilingual' ) . '</button></p>';
		$failed = Queue::failed( 30 );
		if ( $failed ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Content', 'gustinai-multilingual' ) . '</th><th>' . esc_html__( 'Language', 'gustinai-multilingual' ) . '</th><th>' . esc_html__( 'Reason', 'gustinai-multilingual' ) . '</th></tr></thead><tbody>';
			foreach ( $failed as $job ) {
				$title = 'post' === $job->object_type ? get_the_title( (int) $job->object_id ) : ( 'term' === $job->object_type ? (string) get_term_field( 'name', (int) $job->object_id ) : __( 'Site strings', 'gustinai-multilingual' ) );
				printf( '<tr><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( (string) $title ), esc_html( Languages::name( (string) $job->lang ) ), esc_html( (string) $job->message ) );
			}
			echo '</tbody></table>';
		}
		echo '</div></div>';
	}

	/* ---------------------------------------------------------------------
	 * Strings
	 * ------------------------------------------------------------------ */

	public static function render_strings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::header( __( 'Strings', 'gustinai-multilingual' ) );
		$default = Languages::default_code();
		$others  = Languages::others( $default );
		if ( ! $others ) {
			echo '<p>' . esc_html__( 'Add at least one language first.', 'gustinai-multilingual' ) . '</p></div>';
			return;
		}
		echo '<p>' . esc_html__( 'Site title, tagline and custom menu labels. Translated texts of pages are edited directly in the translated pages.', 'gustinai-multilingual' ) . '</p>';
		printf( '<p><button type="button" class="button button-primary gaiml-strings-translate" data-langs="%s">%s</button> <span class="gaiml-progress" aria-live="polite"></span></p>', esc_attr( implode( ',', $others ) ), esc_html__( 'Translate missing strings with AI', 'gustinai-multilingual' ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="gaiml_save_strings">';
		wp_nonce_field( 'gaiml_save_strings' );
		echo '<table class="widefat striped gaiml-strings"><thead><tr><th>' . esc_html( Languages::name( $default ) ) . '</th>';
		foreach ( $others as $code ) {
			echo '<th>' . esc_html( Languages::name( $code ) ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$index = 0;
		foreach ( Strings::groups() as $group => $texts ) {
			echo '<tr class="gaiml-strings__group"><th colspan="' . esc_attr( (string) ( count( $others ) + 1 ) ) . '">' . esc_html( (string) $group ) . '</th></tr>';
			$found = array();
			foreach ( $others as $code ) {
				$found[ $code ] = Memory::lookup( $texts, $default, $code );
			}
			foreach ( $texts as $text ) {
				echo '<tr><td>' . esc_html( $text ) . '<input type="hidden" name="strings[' . esc_attr( (string) $index ) . '][source]" value="' . esc_attr( $text ) . '"></td>';
				foreach ( $others as $code ) {
					$value = $found[ $code ][ $text ] ?? '';
					printf(
						'<td><input type="text" class="widefat" name="strings[%1$d][%2$s]" value="%3$s"><input type="hidden" name="strings[%1$d][was_%2$s]" value="%3$s"></td>',
						(int) $index,
						esc_attr( $code ),
						esc_attr( $value )
					);
				}
				echo '</tr>';
				++$index;
			}
		}
		echo '</tbody></table>';
		submit_button( __( 'Save the strings', 'gustinai-multilingual' ) );
		echo '</form></div>';
	}

	public static function save_strings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'gustinai-multilingual' ), 403 );
		}
		check_admin_referer( 'gaiml_save_strings' );
		$default = Languages::default_code();
		$rows    = isset( $_POST['strings'] ) && is_array( $_POST['strings'] ) ? wp_unslash( $_POST['strings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field below.
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['source'] ) ) {
				continue;
			}
			$source = sanitize_text_field( (string) $row['source'] );
			foreach ( Languages::others( $default ) as $code ) {
				$value = sanitize_text_field( (string) ( $row[ $code ] ?? '' ) );
				$was   = sanitize_text_field( (string) ( $row[ 'was_' . $code ] ?? '' ) );
				if ( $value !== $was ) {
					Memory::set_manual( $default, $source, $code, $value );
				}
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=gaiml-strings&gaiml_notice=saved' ) );
		exit;
	}
}
