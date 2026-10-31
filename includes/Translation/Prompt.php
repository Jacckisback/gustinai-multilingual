<?php
/**
 * Translation instructions sent to the model.
 *
 * The system prompt only depends on the language pair and the settings, so it
 * is identical for every request of a language pair (prompt caching).
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Translation;

use GustinAI\Multilingual\Languages;
use GustinAI\Multilingual\Settings;

defined( 'ABSPATH' ) || exit;

final class Prompt {

	public static function system( string $source, string $target ): string {
		$from = Languages::english_name( $source ) . ' (' . Languages::hreflang( $source ) . ')';
		$to   = Languages::english_name( $target ) . ' (' . Languages::hreflang( $target ) . ')';

		$lines   = array();
		$lines[] = "You are a senior website translator and localization specialist, expert in SEO and in writing for AI answer engines (GEO). Translate every item from {$from} into {$to}.";
		$lines[] = '';
		$lines[] = 'Quality';
		$lines[] = "- Write natural, fluent {$to} that reads as if a native professional copywriter wrote it. Translate the meaning, not word for word. Keep the tone, register, intent and level of detail.";
		$lines[] = "- Keep facts, numbers, prices, dates, names, brands, product names, URLs, e-mail addresses and code exact. Use {$to} conventions for date and number formats, quotation marks and punctuation spacing.";
		$lines[] = "- SEO and GEO: use the words native speakers actually search for in {$to}, keep headings explicit and informative, keep key terms consistent across items. Never add, remove or invent information.";
		switch ( Languages::formality( $target ) ) {
			case 'formal':
				$lines[] = '- Address the reader formally (for example vous, usted, Sie, Lei, u).';
				break;
			case 'informal':
				$lines[] = '- Address the reader informally (for example tu, tú, du, tu, je).';
				break;
			default:
				$lines[] = '- Use the form of address that fits the source and the website; be consistent across items.';
		}
		$lines[] = '';
		$lines[] = 'Markup';
		$lines[] = '- <g1>…</g1> wraps formatted text (link, bold…) and <x2/> marks a fixed element (line break, image, icon). Keep each of them exactly once and unchanged; move them where the grammar requires; never add new ones.';
		$lines[] = '- Copy variables such as %%title%%, %title%, {name}, %s and %1$s unchanged.';
		$lines[] = '';
		$lines[] = 'Item kinds';
		$lines[] = '- "text": page content. "label": short interface text (button, menu item, alt text, field label): keep it short.';
		$lines[] = '- "title": page or SEO title: concise, under 60 characters when the source is.';
		$lines[] = '- "description": meta description: one or two sentences, under 155 characters.';
		$lines[] = '- "slug": URL slug: lowercase ASCII words separated by hyphens (transliterate, no accents), short and keyword-rich, without stop words.';

		$context = trim( (string) Settings::get( 'context' ) );
		if ( '' !== $context ) {
			$lines[] = '';
			$lines[] = 'About the website (use it to choose terminology and tone)';
			$lines[] = $context;
		}

		$glossary = self::glossary( $target );
		if ( $glossary['keep'] || $glossary['map'] ) {
			$lines[] = '';
			$lines[] = 'Glossary (mandatory)';
			foreach ( $glossary['keep'] as $term ) {
				$lines[] = '- "' . $term . '": never translate.';
			}
			foreach ( $glossary['map'] as $term => $translation ) {
				$lines[] = '- "' . $term . '" → "' . $translation . '"';
			}
		}

		$lines[] = '';
		$lines[] = 'Answer with JSON only: {"translations":[{"id":"…","text":"…"}]}, exactly one entry per input id.';

		/**
		 * Filters the system prompt sent to the model.
		 */
		return (string) apply_filters( 'gaiml_system_prompt', implode( "\n", $lines ), $source, $target );
	}

	/**
	 * @param array<string, array{text: string, kind: string}> $items id => item
	 */
	public static function user( array $items ): string {
		$list = array();
		foreach ( $items as $id => $item ) {
			$list[] = array(
				'id'   => (string) $id,
				'kind' => $item['kind'],
				'text' => $item['text'],
			);
		}
		return (string) wp_json_encode( array( 'items' => $list ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * JSON schema of the answer (structured outputs).
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'translations' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'   => array( 'type' => 'string' ),
							'text' => array( 'type' => 'string' ),
						),
						'required'             => array( 'id', 'text' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'translations' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Glossary entries for a target language.
	 *
	 * One entry per line:
	 *   GustinAI                            never translated
	 *   atelier = workshop                  same translation for every language
	 *   cocktail = es: cóctel | de: Cocktail per language
	 *
	 * @return array{keep: string[], map: array<string, string>}
	 */
	public static function glossary( string $target ): array {
		$out = array(
			'keep' => array(),
			'map'  => array(),
		);
		foreach ( preg_split( '~\R~', (string) Settings::get( 'glossary' ) ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			$parts = preg_split( '~\s*=>?\s*~', $line, 2 );
			$term  = trim( (string) $parts[0] );
			if ( '' === $term ) {
				continue;
			}
			if ( ! isset( $parts[1] ) || '' === trim( $parts[1] ) ) {
				$out['keep'][] = $term;
				continue;
			}
			$value = trim( $parts[1] );
			if ( preg_match_all( '~(?:^|\|)\s*([a-z]{2,3}(?:-[a-z0-9]{2,4})?)\s*:\s*([^|]+)~i', $value, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $entry ) {
					if ( strtolower( $entry[1] ) === $target ) {
						$out['map'][ $term ] = trim( $entry[2] );
					}
				}
				continue;
			}
			if ( $value === $term ) {
				$out['keep'][] = $term;
			} else {
				$out['map'][ $term ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Extracts the JSON object of an answer (tolerates code fences and prose).
	 *
	 * @return array<string, string>|null id => text
	 */
	public static function parse( string $answer ): ?array {
		$answer = trim( $answer );
		$start  = strpos( $answer, '{' );
		$end    = strrpos( $answer, '}' );
		if ( false === $start || false === $end || $end < $start ) {
			return null;
		}
		$data = json_decode( substr( $answer, $start, $end - $start + 1 ), true );
		if ( ! is_array( $data ) || ! isset( $data['translations'] ) || ! is_array( $data['translations'] ) ) {
			return null;
		}
		$out = array();
		foreach ( $data['translations'] as $row ) {
			if ( is_array( $row ) && isset( $row['id'], $row['text'] ) && is_scalar( $row['id'] ) && is_string( $row['text'] ) ) {
				$out[ (string) $row['id'] ] = $row['text'];
			}
		}
		return $out;
	}
}
