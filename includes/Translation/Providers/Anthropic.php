<?php
/**
 * Claude (Anthropic Messages API, raw HTTP).
 *
 * - API version 2026-10-21, no sampling parameters.
 * - output_config.format (JSON schema) guarantees a parseable answer,
 *   output_config.effort keeps reasoning short (translation needs little).
 * - The system prompt is cached (cache_control) for the requests of a job.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Translation\Providers;

use GustinAI\Multilingual\Translation\Prompt;

defined( 'ABSPATH' ) || exit;

final class Anthropic extends Provider {

	private const API         = 'https://api.anthropic.com/v1/';
	private const API_VERSION = '2026-10-21';

	public function id(): string {
		return 'anthropic';
	}

	public function name(): string {
		return 'Claude';
	}

	/**
	 * @return array<string, string>
	 */
	private function headers(): array {
		return array(
			'x-api-key'         => $this->key,
			'anthropic-version' => self::API_VERSION,
			'content-type'      => 'application/json',
		);
	}

	public function complete( string $system, string $user ) {
		for ( $attempt = 1; $attempt <= 4; $attempt++ ) {
			$caps = $this->caps();
			$body = array(
				'model'      => $this->model,
				'max_tokens' => 16000,
				'system'     => array(
					array(
						'type'          => 'text',
						'text'          => $system,
						'cache_control' => array( 'type' => 'ephemeral' ),
					),
				),
				'messages'   => array(
					array(
						'role'    => 'user',
						'content' => $user,
					),
				),
			);
			$output = array();
			if ( $caps['effort'] ) {
				$output['effort'] = $this->effort;
			}
			if ( $caps['format'] ) {
				$output['format'] = array(
					'type'   => 'json_schema',
					'schema' => Prompt::schema(),
				);
			}
			if ( $output ) {
				$body['output_config'] = $output;
			}

			$response = $this->http( 'POST', self::API . 'messages', $this->headers(), $body );
			if ( is_wp_error( $response ) ) {
				if ( $attempt < 2 ) {
					continue;
				}
				return $response;
			}
			$code    = $response['code'];
			$data    = $response['data'];
			$message = (string) ( $data['error']['message'] ?? '' );

			if ( 400 === $code ) {
				if ( $caps['effort'] && false !== stripos( $message, 'effort' ) ) {
					$this->disable( 'effort' );
					continue;
				}
				if ( $caps['format'] && preg_match( '~output_config|format|schema|structured~i', $message ) ) {
					$this->disable( 'format' );
					continue;
				}
			}
			if ( in_array( $code, array( 429, 500, 502, 503, 504, 529 ), true ) && $attempt < 3 && $response['retry_after'] <= 20 ) {
				self::pause( $response['retry_after'], $attempt );
				continue;
			}
			if ( 200 !== $code ) {
				return $this->error( $response, '' !== $message ? $message : __( 'unexpected response', 'gustinai-multilingual' ) );
			}

			$stop = (string) ( $data['stop_reason'] ?? '' );
			if ( 'max_tokens' === $stop ) {
				return new \WP_Error( 'gaiml_truncated', __( 'The answer was cut off (too long).', 'gustinai-multilingual' ) );
			}
			if ( 'refusal' === $stop ) {
				return new \WP_Error( 'gaiml_refusal', __( 'The model declined to translate this content.', 'gustinai-multilingual' ) );
			}
			$text = '';
			foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
				if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
					$text .= (string) ( $block['text'] ?? '' );
				}
			}
			$usage = (array) ( $data['usage'] ?? array() );
			return array(
				'text'  => $text,
				'usage' => array(
					'input'  => (int) ( $usage['input_tokens'] ?? 0 ) + (int) ( $usage['cache_creation_input_tokens'] ?? 0 ) + (int) ( $usage['cache_read_input_tokens'] ?? 0 ),
					'output' => (int) ( $usage['output_tokens'] ?? 0 ),
				),
			);
		}
		return new \WP_Error( 'gaiml_server', __( 'Claude did not answer, retry later.', 'gustinai-multilingual' ) );
	}

	public function models() {
		$response = $this->http( 'GET', self::API . 'models?limit=100', $this->headers(), null, 20 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== $response['code'] ) {
			return $this->error( $response, (string) ( $response['data']['error']['message'] ?? '' ) );
		}
		$out = array();
		foreach ( (array) ( $response['data']['data'] ?? array() ) as $model ) {
			if ( is_array( $model ) && ! empty( $model['id'] ) ) {
				$out[ (string) $model['id'] ] = (string) ( $model['display_name'] ?? $model['id'] );
			}
		}
		return $out;
	}
}
