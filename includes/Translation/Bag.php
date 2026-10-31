<?php
/**
 * Unique texts to translate for one job.
 *
 * Extractors add texts and keep closures that rebuild the content from a map
 * id => translated text. Identical texts are sent once.
 *
 * Kinds: text (content), label (short UI text, alt), title (page/SEO title),
 * description (meta description), slug (URL slug).
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Translation;

defined( 'ABSPATH' ) || exit;

final class Bag {

	public const KINDS = array( 'text', 'label', 'title', 'description', 'slug' );

	/** @var array<string, array{text: string, kind: string}> */
	private array $items = array();

	/** @var array<string, string> */
	private array $index = array();

	public function add( string $text, string $kind = 'text' ): string {
		$kind = in_array( $kind, self::KINDS, true ) ? $kind : 'text';
		$key  = $kind . "\n" . $text;
		if ( isset( $this->index[ $key ] ) ) {
			return $this->index[ $key ];
		}
		$id                  = 's' . ( count( $this->items ) + 1 );
		$this->items[ $id ]  = array(
			'text' => $text,
			'kind' => $kind,
		);
		$this->index[ $key ] = $id;
		return $id;
	}

	/**
	 * @return array<string, array{text: string, kind: string}>
	 */
	public function items(): array {
		return $this->items;
	}

	public function count(): int {
		return count( $this->items );
	}

	/**
	 * Characters to translate (cost estimates).
	 */
	public function chars(): int {
		$total = 0;
		foreach ( $this->items as $item ) {
			$total += function_exists( 'mb_strlen' ) ? mb_strlen( $item['text'] ) : strlen( $item['text'] );
		}
		return $total;
	}
}
