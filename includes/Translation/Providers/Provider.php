<?php
/**
 * Base class of the AI providers.
 *
 * Requests are only made from the admin, WP-CLI or the background queue,
 * never while serving a visitor. Optional parameters (reasoning effort,
 * structured outputs) are dropped automatically for models that reject them,
 * so any model ID can be used.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Translation\Providers;

use GustinAI\Multilingual\Settings;

defined( 'ABSPATH' ) || exit;

abstract class Provider {

	protected string $model;

	protected string $key;

	protected string $effort;

	public function __construct( string $model, string $key, string $effort = 'low' ) {
		$this->model  = $model;
		$this->key    = $key;
		$this->effort = in_array( $effort, array( 'low', 'medium', 'high' ), true ) ? $effort : 'low';
	}

	abstract public function id(): string;

	abstract public function name(): string;

	/**
	 * Sends one translation request.
	 *
	 * @return array{text: string, usage: array{input: int, output: int}}|\WP_Error Raw answer text.
	 */
	abstract public function complete( string $system, string $user );

	/**
	 * Models available with this key: id => label.
	 *
	 * @return array<string, string>|\WP_Error
	 */
	abstract public function models();

	public function model(): string {
		return $this->model;
	}

	/**
	 * Provider configured in the settings (filterable, e.g. for tests).
	 */
	public static function current(): ?Provider {
		$provider = Settings::provider();
		$key      = Settings::api_key( $provider );
		$instance = null;
		if ( '' !== $key ) {
			$model    = Settings::model( $provider );
			$effort   = (string) Settings::get( 'effort' );
			$instance = 'openai' === $provider ? new OpenAI( $model, $key, $effort ) : new Anthropic( $model, $key, $effort );
		}
		/**
		 * Filters the provider used for translations.
		 *
		 * @param Provider|null $instance
		 */
		$instance = apply_filters( 'gaiml_provider', $instance );
		return $instance instanceof Provider ? $instance : null;
	}

	public static function make( string $provider, string $key, string $model = '' ): Provider {
		$model = '' !== $model ? $model : Settings::model( $provider );
		return 'openai' === $provider ? new OpenAI( $model, $key ) : new Anthropic( $model, $key );
	}

	/* ---------------------------------------------------------------------
	 * HTTP
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string, string>     $headers
	 * @param array<string, mixed>|null $body
	 * @return array{code: int, data: array<string, mixed>, retry_after: int}|\WP_Error
	 */
	protected function http( string $method, string $url, array $headers, ?array $body = null, int $timeout = 150 ) {
		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => $timeout,
			'redirection' => 0,
			'user-agent'  => 'GustinAI-Multilingual/' . GAIML_VERSION,
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'gaiml_network', $response->get_error_message() );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return array(
			'code'        => (int) wp_remote_retrieve_response_code( $response ),
			'data'        => is_array( $data ) ? $data : array(),
			'retry_after' => (int) wp_remote_retrieve_header( $response, 'retry-after' ),
		);
	}

	/**
	 * Maps an HTTP error to a WP_Error with a stable code.
	 *
	 * @param array{code: int, data: array<string, mixed>, retry_after: int} $response
	 */
	protected function error( array $response, string $message ): \WP_Error {
		$code = $response['code'];
		$map  = array(
			400 => 'gaiml_request',
			401 => 'gaiml_auth',
			402 => 'gaiml_quota',
			403 => 'gaiml_auth',
			404 => 'gaiml_model',
			413 => 'gaiml_request',
			429 => 'gaiml_rate',
		);
		$type = $map[ $code ] ?? ( $code >= 500 ? 'gaiml_server' : 'gaiml_request' );
		if ( 429 === $code && false !== stripos( $message, 'quota' ) ) {
			$type = 'gaiml_quota';
		}
		if ( 'gaiml_auth' === $type ) {
			// Provider messages can quote part of the key: never show them.
			$message = __( 'the API key is invalid or has no access to this model. Check it in Multilingual > Settings > AI translation.', 'gustinai-multilingual' );
		}
		/* translators: 1: provider name, 2: HTTP status, 3: error message from the provider. */
		return new \WP_Error( $type, sprintf( __( '%1$s error %2$d: %3$s', 'gustinai-multilingual' ), $this->name(), $code, $message ), array( 'retry_after' => $response['retry_after'] ) );
	}

	/**
	 * Whether an error is temporary (retry later) or needs a settings change.
	 */
	public static function is_fatal( \WP_Error $error ): bool {
		return in_array( $error->get_error_code(), array( 'gaiml_auth', 'gaiml_model', 'gaiml_quota', 'gaiml_no_provider' ), true );
	}

	/* ---------------------------------------------------------------------
	 * Model capabilities learnt from errors
	 * ------------------------------------------------------------------ */

	/**
	 * @return array{effort: bool, format: bool}
	 */
	protected function caps(): array {
		$all = get_option( 'gaiml_model_caps', array() );
		$key = $this->id() . ':' . $this->model;
		return array(
			'effort' => ! isset( $all[ $key ]['effort'] ) || (bool) $all[ $key ]['effort'],
			'format' => ! isset( $all[ $key ]['format'] ) || (bool) $all[ $key ]['format'],
		);
	}

	protected function disable( string $capability ): void {
		$all = get_option( 'gaiml_model_caps', array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$all[ $this->id() . ':' . $this->model ][ $capability ] = false;
		update_option( 'gaiml_model_caps', $all, false );
	}

	/**
	 * Short pause before retrying a rate-limited or overloaded request.
	 */
	protected static function pause( int $retry_after, int $attempt ): void {
		$seconds = $retry_after > 0 ? min( $retry_after, 20 ) : min( 2 * $attempt, 8 );
		sleep( max( 1, $seconds ) );
	}
}
