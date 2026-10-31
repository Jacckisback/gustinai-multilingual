<?php
/**
 * Known models, recommendations and prices (USD per million tokens).
 *
 * Prices verified on 2026-10-31 on the official pricing pages of Anthropic and
 * OpenAI. Any other model ID can be used (custom field or list loaded from the
 * provider API); prices then only serve for estimates.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Translation;

defined( 'ABSPATH' ) || exit;

final class Models {

	public const VERIFIED = '2026-10-31';

	public const RECOMMENDED = array(
		'anthropic' => 'claude-opus-5-5',
		'openai'    => 'gpt-6.1-sol',
	);

	/**
	 * @return array<string, array{label: string, input: float, output: float, note: string}>
	 */
	public static function known( string $provider ): array {
		$models = array(
			'anthropic' => array(
				'claude-opus-5-5'   => array(
					'label'  => 'Claude Opus 5.5',
					'input'  => 4.0,
					'output' => 20.0,
					'note'   => __( 'Recommended: the most natural translations, for a few dollars per language for a typical site.', 'gustinai-multilingual' ),
				),
				'claude-sonnet-5-5' => array(
					'label'  => 'Claude Sonnet 5.5',
					'input'  => 2.0,
					'output' => 10.0,
					'note'   => __( 'Half the price of Opus, very good quality.', 'gustinai-multilingual' ),
				),
				'claude-haiku-5-5'  => array(
					'label'  => 'Claude Haiku 5.5',
					'input'  => 0.10,
					'output' => 0.50,
					'note'   => __( 'Cheapest, for large volumes (catalogues, archives).', 'gustinai-multilingual' ),
				),
				'claude-fable-5-1'  => array(
					'label'  => 'Claude Fable 5.1',
					'input'  => 10.0,
					'output' => 50.0,
					'note'   => __( 'Most capable model, rarely needed for translation.', 'gustinai-multilingual' ),
				),
			),
			'openai'    => array(
				'gpt-6.1-sol'   => array(
					'label'  => 'GPT-6.1 Sol',
					'input'  => 2.0,
					'output' => 10.0,
					'note'   => __( 'Recommended: near-flagship quality at a moderate price.', 'gustinai-multilingual' ),
				),
				'gpt-6-astra'   => array(
					'label'  => 'GPT-6 Astra',
					'input'  => 10.0,
					'output' => 50.0,
					'note'   => __( 'OpenAI flagship, expensive for translation.', 'gustinai-multilingual' ),
				),
				'gpt-6.1-terra' => array(
					'label'  => 'GPT-6.1 Terra',
					'input'  => 1.0,
					'output' => 5.0,
					'note'   => __( 'Fast and economical, natural writing.', 'gustinai-multilingual' ),
				),
				'gpt-6-luna'    => array(
					'label'  => 'GPT-6 Luna',
					'input'  => 0.10,
					'output' => 0.50,
					'note'   => __( 'Cheapest, for large volumes (catalogues, archives).', 'gustinai-multilingual' ),
				),
			),
		);
		/**
		 * Filters the models offered in the settings (id => label, input, output, note).
		 */
		return (array) apply_filters( 'gaiml_known_models', $models[ $provider ] ?? array(), $provider );
	}

	/**
	 * @return array{input: float, output: float}|null
	 */
	public static function price( string $provider, string $model ): ?array {
		$known = self::known( $provider );
		if ( isset( $known[ $model ] ) ) {
			return array(
				'input'  => (float) $known[ $model ]['input'],
				'output' => (float) $known[ $model ]['output'],
			);
		}
		return null;
	}

	/**
	 * Estimated cost (USD) of translating $chars characters into one language.
	 * About 3.5 characters per token, ~25 % overhead for instructions and JSON,
	 * ~20 % extra output for reasoning tokens.
	 */
	public static function estimate( string $provider, string $model, int $chars ): ?float {
		$price = self::price( $provider, $model );
		if ( null === $price || $chars <= 0 ) {
			return null === $price ? null : 0.0;
		}
		$tokens = $chars / 3.5;
		$input  = $tokens * 1.25 + 900 * max( 1, $chars / 6000 );
		$output = $tokens * 1.2 + 40 * max( 1, $chars / 300 );
		return round( ( $input * $price['input'] + $output * $price['output'] ) / 1000000, 4 );
	}
}
