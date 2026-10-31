<?php
/**
 * ChatGPT models (OpenAI Responses API, raw HTTP).
 *
 * - text.format json_schema (strict) guarantees a parseable answer,
 *   reasoning.effort keeps reasoning short.
 * - store: false, the requests are not kept by OpenAI for later retrieval.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Translation\Providers;

use GustinAI\Multilingual\Translation\Prompt;

defined( 'ABSPATH' ) || exit;

final class OpenAI extends Provider {

	private const API = 'https://api.openai.com/v1/';

	public function id(): string {
		return 'openai';
	}

	public function name(): string {
		return 'OpenAI';
	}

	/**
	 * @return array<string, string>
	 */
	private function headers(): array {
		return array(
			'Authorization' => 'Bearer ' . $this->key,
			'Content-Type'  => 'application/json',
		);
	}

	public function complete( string $system, string $user ) {
		for ( $attempt = 1; $attempt <= 4; $attempt++ ) {
			$caps = $this->caps();
			$body = array(
				'model'             => $this->model,
				'instructions'      => $system,
				'input'             => $user,
				'max_output_tokens' => 32000,
				'store'             => false,
			);
			if ( $caps['effort'] ) {
				$body['reasoning'] = array( 'effort' => $this->effort );
			}
			if ( $caps['format'] ) {
				$body['text'] = array(
					'format' => array(
						'type'   => 'json_schema',
						'name'   => 'translations',
						'schema' => Prompt::schema(),
						'strict' => true,
					),
				);
			}

			$response = $this->http( 'POST', self::API . 'responses', $this->headers(), $body );
			if ( is_wp_error( $response ) ) {
				if ( $attempt < 2 ) {
					continue;
				}
				return $response;
			}
			$code    = $response['code'];
			$data    = $response['data'];
			$error   = (array) ( $data['error'] ?? array() );
			$message = (string) ( $error['message'] ?? '' );
			$param   = (string) ( $error['param'] ?? '' );

			if ( 400 === $code ) {
				if ( $caps['effort'] && ( 0 === strpos( $param, 'reasoning' ) || false !== stripos( $message, 'reasoning' ) ) ) {
					$this->disable( 'effort' );
					continue;
				}
				if ( $caps['format'] && ( 0 === strpos( $param, 'text' ) || preg_match( '~json_schema|text\.format|response_format~i', $message ) ) ) {
					$this->disable( 'format' );
					continue;
				}
			}
			if ( in_array( $code, array( 429, 500, 502, 503, 504 ), true ) && $attempt < 3 && $response['retry_after'] <= 20
				&& 'insufficient_quota' !== ( $error['code'] ?? '' ) ) {
				self::pause( $response['retry_after'], $attempt );
				continue;
			}
			if ( 200 !== $code ) {
				if ( 'insufficient_quota' === ( $error['code'] ?? '' ) ) {
					$message = 'insufficient_quota: ' . $message;
				}
				return $this->error( $response, '' !== $message ? $message : __( 'unexpected response', 'gustinai-multilingual' ) );
			}

			if ( 'incomplete' === ( $data['status'] ?? '' ) ) {
				return new \WP_Error( 'gaiml_truncated', __( 'The answer was cut off (too long).', 'gustinai-multilingual' ) );
			}
			$text = '';
			foreach ( (array) ( $data['output'] ?? array() ) as $item ) {
				if ( ! is_array( $item ) || 'message' !== ( $item['type'] ?? '' ) ) {
					continue;
				}
				foreach ( (array) ( $item['content'] ?? array() ) as $content ) {
					if ( ! is_array( $content ) ) {
						continue;
					}
					if ( 'refusal' === ( $content['type'] ?? '' ) ) {
						return new \WP_Error( 'gaiml_refusal', __( 'The model declined to translate this content.', 'gustinai-multilingual' ) );
					}
					if ( 'output_text' === ( $content['type'] ?? '' ) ) {
						$text .= (string) ( $content['text'] ?? '' );
					}
				}
			}
			$usage = (array) ( $data['usage'] ?? array() );
			return array(
				'text'  => $text,
				'usage' => array(
					'input'  => (int) ( $usage['input_tokens'] ?? 0 ),
					'output' => (int) ( $usage['output_tokens'] ?? 0 ),
				),
			);
		}
		return new \WP_Error( 'gaiml_server', __( 'OpenAI did not answer, retry later.', 'gustinai-multilingual' ) );
	}

	public function models() {
		$response = $this->http( 'GET', self::API . 'models', $this->headers(), null, 20 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== $response['code'] ) {
			return $this->error( $response, (string) ( $response['data']['error']['message'] ?? '' ) );
		}
		$models = array();
		foreach ( (array) ( $response['data']['data'] ?? array() ) as $model ) {
			$id = is_array( $model ) ? (string) ( $model['id'] ?? '' ) : '';
			if ( '' === $id || ! preg_match( '~^(gpt-|o\d|chatgpt-)~', $id ) || preg_match( '~audio|realtime|tts|transcribe|image|search|embedding|moderation|instruct|codex|whisper|dall-e|computer~', $id ) ) {
				continue;
			}
			$models[ $id ] = (int) ( $model['created'] ?? 0 );
		}
		arsort( $models );
		$out = array();
		foreach ( array_keys( $models ) as $id ) {
			$out[ $id ] = $id;
		}
		return $out;
	}
}
