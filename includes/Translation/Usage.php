<?php
/**
 * Token usage per month and model, to keep an eye on the API bill.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Translation;

use GustinAI\Multilingual\Translation\Providers\Provider;

defined( 'ABSPATH' ) || exit;

final class Usage {

	private const OPTION = 'gaiml_usage';

	/**
	 * @param array{input: int, output: int} $usage
	 */
	public static function record( Provider $provider, array $usage ): void {
		$all   = get_option( self::OPTION, array() );
		$all   = is_array( $all ) ? $all : array();
		$month = gmdate( 'Y-m' );
		$key   = $provider->id() . '|' . $provider->model();
		$row   = $all[ $month ][ $key ] ?? array(
			'input'    => 0,
			'output'   => 0,
			'requests' => 0,
		);
		$row['input']            += (int) $usage['input'];
		$row['output']           += (int) $usage['output'];
		$row['requests']         += 1;
		$all[ $month ][ $key ]    = $row;
		krsort( $all );
		update_option( self::OPTION, array_slice( $all, 0, 12, true ), false );
	}

	/**
	 * Usage of a month: list of array( provider, model, input, output, requests, cost|null ).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function month( string $month = '' ): array {
		$month = '' !== $month ? $month : gmdate( 'Y-m' );
		$all   = get_option( self::OPTION, array() );
		$out   = array();
		foreach ( (array) ( $all[ $month ] ?? array() ) as $key => $row ) {
			list( $provider, $model ) = array_pad( explode( '|', (string) $key, 2 ), 2, '' );
			$price                    = Models::price( $provider, $model );
			$out[]                    = array(
				'provider' => $provider,
				'model'    => $model,
				'input'    => (int) $row['input'],
				'output'   => (int) $row['output'],
				'requests' => (int) $row['requests'],
				'cost'     => $price ? round( ( $row['input'] * $price['input'] + $row['output'] * $price['output'] ) / 1000000, 2 ) : null,
			);
		}
		return $out;
	}
}
