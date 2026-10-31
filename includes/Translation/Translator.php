<?php
/**
 * Translates the texts of a bag through the translation memory and the provider.
 *
 * Each call sends at most one request (a batch of ~6,000 characters) so a job
 * step stays short on any hosting; the memory makes steps resumable.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Translation;

use GustinAI\Multilingual\Translation\Providers\Provider;

defined( 'ABSPATH' ) || exit;

final class Translator {

	public const BATCH_CHARS = 6000;

	public const MAX_ITEMS = 120;

	/**
	 * Hash of every bag item.
	 *
	 * @return array<string, string> id => hash
	 */
	public static function hashes( Bag $bag, string $source ): array {
		$out = array();
		foreach ( $bag->items() as $id => $item ) {
			$out[ $id ] = Memory::hash( $source, $item['kind'], $item['text'] );
		}
		return $out;
	}

	/**
	 * Validated translations available in memory: id => text.
	 *
	 * @return array<string, string>
	 */
	public static function translations( Bag $bag, string $source, string $target ): array {
		$hashes = self::hashes( $bag, $source );
		$found  = Memory::get_many( array_values( $hashes ), $target );
		$out    = array();
		foreach ( $hashes as $id => $hash ) {
			if ( isset( $found[ $hash ] ) ) {
				$out[ $id ] = $found[ $hash ];
			}
		}
		return $out;
	}

	/**
	 * Translates the next batch of missing items.
	 *
	 * @param array<string, mixed> $state Persistent job state (batch size, failed items).
	 * @return array{done: bool, missing: int, translated: int, error: \WP_Error|null}
	 */
	public static function step( Bag $bag, string $source, string $target, array &$state ): array {
		$hashes  = self::hashes( $bag, $source );
		$found   = Memory::get_many( array_values( $hashes ), $target );
		$failed  = array_flip( (array) ( $state['failed'] ?? array() ) );
		$missing = array();
		foreach ( $bag->items() as $id => $item ) {
			$hash = $hashes[ $id ];
			if ( ! isset( $found[ $hash ] ) && ! isset( $failed[ $hash ] ) && ! isset( $missing[ $hash ] ) ) {
				$missing[ $hash ] = $item + array( 'id' => $id );
			}
		}
		if ( ! $missing ) {
			return array(
				'done'       => true,
				'missing'    => 0,
				'translated' => 0,
				'error'      => null,
			);
		}

		$provider = Provider::current();
		if ( null === $provider ) {
			return array(
				'done'       => false,
				'missing'    => count( $missing ),
				'translated' => 0,
				'error'      => new \WP_Error( 'gaiml_no_provider', __( 'Add an API key (Claude or OpenAI) in Multilingual > Settings > AI translation.', 'gustinai-multilingual' ) ),
			);
		}

		$limit     = max( 200, (int) ( $state['batch'] ?? self::BATCH_CHARS ) );
		$max_items = max( 1, (int) ( $state['items'] ?? self::MAX_ITEMS ) );
		$batch     = array();
		$chars     = 0;
		foreach ( $missing as $hash => $item ) {
			$length = strlen( $item['text'] );
			if ( $batch && ( $chars + $length > $limit || count( $batch ) >= $max_items ) ) {
				break;
			}
			$batch[ $hash ] = $item;
			$chars         += $length;
		}

		$items = array();
		$ids   = array();
		$n     = 0;
		foreach ( $batch as $hash => $item ) {
			$key           = (string) ++$n;
			$items[ $key ] = array(
				'text' => $item['text'],
				'kind' => $item['kind'],
			);
			$ids[ $key ]   = $hash;
		}

		$result = $provider->complete( Prompt::system( $source, $target ), Prompt::user( $items ) );
		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			if ( 'gaiml_truncated' === $code || 'gaiml_refusal' === $code ) {
				$state[ $code ] = (int) ( $state[ $code ] ?? 0 ) + 1;
				if ( count( $batch ) > 1 && $state[ $code ] < 8 ) {
					// Smaller batches: shorter answers, and a refused item gets isolated.
					$state['batch'] = max( 200, intdiv( $chars, 2 ) );
					$state['items'] = max( 1, intdiv( count( $batch ), 2 ) );
				} else {
					foreach ( array_keys( $batch ) as $hash ) {
						$state['failed'][] = (string) $hash;
					}
				}
				$result = null;
			}
			return array(
				'done'       => false,
				'missing'    => count( $missing ),
				'translated' => 0,
				'error'      => $result,
			);
		}

		Usage::record( $provider, $result['usage'] );
		$answers = Prompt::parse( $result['text'] );
		if ( null === $answers ) {
			$state['retries'] = (int) ( $state['retries'] ?? 0 ) + 1;
			if ( $state['retries'] >= 3 ) {
				$state['batch'] = max( 200, intdiv( $chars, 2 ) );
				$state['items'] = max( 1, intdiv( count( $batch ), 2 ) );
			}
			return array(
				'done'       => false,
				'missing'    => count( $missing ),
				'translated' => 0,
				'error'      => $state['retries'] >= 6 ? new \WP_Error( 'gaiml_bad_response', __( 'The model answer could not be read.', 'gustinai-multilingual' ) ) : null,
			);
		}

		$rows = array();
		foreach ( $ids as $key => $hash ) {
			$item   = $batch[ $hash ];
			$answer = isset( $answers[ $key ] ) ? (string) $answers[ $key ] : '';
			if ( 'slug' === $item['kind'] ) {
				$answer = Placeholders::slug( $answer );
			}
			if ( '' !== $answer && Placeholders::valid( $item['text'], $answer ) ) {
				$rows[ $hash ] = array(
					'source'      => $item['text'],
					'translation' => 'slug' === $item['kind'] ? $answer : trim( $answer ),
					'context'     => $item['kind'],
				);
				continue;
			}
			$state['invalid'][ $hash ] = (int) ( $state['invalid'][ $hash ] ?? 0 ) + 1;
			if ( $state['invalid'][ $hash ] >= 2 ) {
				$state['failed'][] = $hash;
			}
		}
		Memory::put_many( $rows, $target );
		if ( count( $rows ) === count( $batch ) && isset( $state['batch'] ) && empty( $state['gaiml_truncated'] ) && empty( $state['gaiml_refusal'] ) ) {
			// Back to normal batches once the problem is behind.
			$state['batch'] = min( self::BATCH_CHARS, (int) $state['batch'] * 2 );
			$state['items'] = min( self::MAX_ITEMS, (int) ( $state['items'] ?? self::MAX_ITEMS ) * 2 );
		}
		$left = count( $missing ) - count( $rows ) - count( array_intersect( array_keys( $batch ), (array) ( $state['failed'] ?? array() ) ) );
		return array(
			'done'       => $left <= 0,
			'missing'    => max( 0, $left ),
			'translated' => count( $rows ),
			'error'      => null,
		);
	}
}
