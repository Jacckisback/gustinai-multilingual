<?php
/**
 * Provider tests with simulated HTTP responses: `wp eval-file tests/e2e/providers.php`.
 * Checks request shapes (headers, structured outputs, effort), parsing,
 * capability fallback on 400 errors, truncation, refusals and model lists.
 *
 * @package GustinAI\Multilingual
 */

// phpcs:ignoreFile

use GustinAI\Multilingual\Translation\Prompt;
use GustinAI\Multilingual\Translation\Providers\Provider;

$GLOBALS['gaiml_t'] = array( 'requests' => array(), 'queue' => array() );
$fails              = 0;

function gaiml_respond( int $code, array $body, array $headers = array() ): void {
	$GLOBALS['gaiml_t']['queue'][] = array(
		'response' => array( 'code' => $code, 'message' => '' ),
		'body'     => wp_json_encode( $body ),
		'headers'  => $headers,
		'cookies'  => array(),
	);
}

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$GLOBALS['gaiml_t']['requests'][] = array( 'url' => $url, 'args' => $args, 'body' => json_decode( (string) ( $args['body'] ?? '' ), true ) );
		return array_shift( $GLOBALS['gaiml_t']['queue'] ) ?? new WP_Error( 'none', 'no canned response' );
	},
	10,
	3
);

function gaiml_check( string $name, bool $ok ): void {
	global $fails;
	echo ( $ok ? "  ok   " : "  FAIL " ) . $name . "\n";
	if ( ! $ok ) {
		++$fails;
	}
}

delete_option( 'gaiml_model_caps' );
$system = Prompt::system( 'fr', 'en' );
$user   = Prompt::user( array( '1' => array( 'text' => 'Bonjour <g1>le monde</g1>', 'kind' => 'text' ) ) );
$answer = wp_json_encode( array( 'translations' => array( array( 'id' => '1', 'text' => 'Hello <g1>world</g1>' ) ) ) );

echo "Anthropic\n";
$claude = Provider::make( 'anthropic', 'sk-ant-test', 'claude-opus-5-5' );
gaiml_respond( 200, array( 'type' => 'message', 'content' => array( array( 'type' => 'thinking', 'thinking' => '' ), array( 'type' => 'text', 'text' => $answer ) ), 'stop_reason' => 'end_turn', 'usage' => array( 'input_tokens' => 50, 'cache_read_input_tokens' => 900, 'output_tokens' => 30 ) ) );
$result = $claude->complete( $system, $user );
$req    = end( $GLOBALS['gaiml_t']['requests'] );
gaiml_check( 'endpoint', 'https://api.anthropic.com/v1/messages' === $req['url'] );
gaiml_check( 'headers', 'sk-ant-test' === $req['args']['headers']['x-api-key'] && '2026-10-21' === $req['args']['headers']['anthropic-version'] );
gaiml_check( 'model + max_tokens', 'claude-opus-5-5' === $req['body']['model'] && 16000 === $req['body']['max_tokens'] );
gaiml_check( 'cached system prompt', 'ephemeral' === $req['body']['system'][0]['cache_control']['type'] );
gaiml_check( 'structured output', 'json_schema' === $req['body']['output_config']['format']['type'] );
gaiml_check( 'effort low', 'low' === $req['body']['output_config']['effort'] );
gaiml_check( 'no sampling params', ! isset( $req['body']['temperature'] ) && ! isset( $req['body']['top_p'] ) );
gaiml_check( 'parsed answer', ! is_wp_error( $result ) && array( '1' => 'Hello <g1>world</g1>' ) === Prompt::parse( $result['text'] ) );
gaiml_check( 'usage counts cache', ! is_wp_error( $result ) && 950 === $result['usage']['input'] );

$haiku = Provider::make( 'anthropic', 'sk-ant-test', 'claude-haiku-4-5' );
gaiml_respond( 400, array( 'type' => 'error', 'error' => array( 'type' => 'invalid_request_error', 'message' => 'output_config.effort: effort is not supported for this model' ) ) );
gaiml_respond( 200, array( 'content' => array( array( 'type' => 'text', 'text' => $answer ) ), 'stop_reason' => 'end_turn', 'usage' => array() ) );
$result = $haiku->complete( $system, $user );
$req    = end( $GLOBALS['gaiml_t']['requests'] );
gaiml_check( 'retry without effort', ! is_wp_error( $result ) && ! isset( $req['body']['output_config']['effort'] ) && isset( $req['body']['output_config']['format'] ) );
gaiml_check( 'capability remembered', false === get_option( 'gaiml_model_caps' )['anthropic:claude-haiku-4-5']['effort'] );

gaiml_respond( 200, array( 'content' => array( array( 'type' => 'text', 'text' => '{"translations":[' ) ), 'stop_reason' => 'max_tokens', 'usage' => array() ) );
gaiml_check( 'truncation detected', 'gaiml_truncated' === $claude->complete( $system, $user )->get_error_code() );
gaiml_respond( 200, array( 'content' => array(), 'stop_reason' => 'refusal', 'stop_details' => array( 'type' => 'refusal', 'category' => 'cyber' ), 'usage' => array() ) );
gaiml_check( 'refusal detected', 'gaiml_refusal' === $claude->complete( $system, $user )->get_error_code() );
gaiml_respond( 401, array( 'type' => 'error', 'error' => array( 'type' => 'authentication_error', 'message' => 'invalid x-api-key' ) ) );
$error = $claude->complete( $system, $user );
gaiml_check( 'auth error is fatal', is_wp_error( $error ) && Provider::is_fatal( $error ) );
gaiml_respond( 404, array( 'type' => 'error', 'error' => array( 'type' => 'not_found_error', 'message' => 'model: claude-x' ) ) );
gaiml_check( 'unknown model', 'gaiml_model' === $claude->complete( $system, $user )->get_error_code() );
gaiml_respond( 200, array( 'data' => array( array( 'id' => 'claude-opus-5-5', 'display_name' => 'Claude Opus 5.5' ), array( 'id' => 'claude-haiku-5-5', 'display_name' => 'Claude Haiku 5.5' ) ) ) );
$models = $claude->models();
gaiml_check( 'models list', array( 'claude-opus-5-5' => 'Claude Opus 5.5', 'claude-haiku-5-5' => 'Claude Haiku 5.5' ) === $models );

echo "OpenAI\n";
$gpt = Provider::make( 'openai', 'sk-test', 'gpt-6.1-sol' );
gaiml_respond( 200, array( 'status' => 'completed', 'output' => array( array( 'type' => 'reasoning', 'summary' => array() ), array( 'type' => 'message', 'content' => array( array( 'type' => 'output_text', 'text' => $answer ) ) ) ), 'usage' => array( 'input_tokens' => 120, 'output_tokens' => 40 ) ) );
$result = $gpt->complete( $system, $user );
$req    = end( $GLOBALS['gaiml_t']['requests'] );
gaiml_check( 'endpoint', 'https://api.openai.com/v1/responses' === $req['url'] );
gaiml_check( 'bearer auth', 'Bearer sk-test' === $req['args']['headers']['Authorization'] );
gaiml_check( 'instructions + input', $system === $req['body']['instructions'] && $user === $req['body']['input'] );
gaiml_check( 'strict json schema', 'json_schema' === $req['body']['text']['format']['type'] && true === $req['body']['text']['format']['strict'] );
gaiml_check( 'reasoning effort', 'low' === $req['body']['reasoning']['effort'] );
gaiml_check( 'not stored', false === $req['body']['store'] );
gaiml_check( 'parsed answer', ! is_wp_error( $result ) && array( '1' => 'Hello <g1>world</g1>' ) === Prompt::parse( $result['text'] ) );

$old = Provider::make( 'openai', 'sk-test', 'gpt-4.1' );
gaiml_respond( 400, array( 'error' => array( 'message' => "Unsupported parameter: 'reasoning.effort' is not supported with this model.", 'type' => 'invalid_request_error', 'param' => 'reasoning.effort', 'code' => 'unsupported_parameter' ) ) );
gaiml_respond( 200, array( 'status' => 'completed', 'output' => array( array( 'type' => 'message', 'content' => array( array( 'type' => 'output_text', 'text' => $answer ) ) ) ), 'usage' => array() ) );
$result = $old->complete( $system, $user );
$req    = end( $GLOBALS['gaiml_t']['requests'] );
gaiml_check( 'retry without reasoning', ! is_wp_error( $result ) && ! isset( $req['body']['reasoning'] ) );
gaiml_respond( 200, array( 'status' => 'incomplete', 'incomplete_details' => array( 'reason' => 'max_output_tokens' ), 'output' => array() ) );
gaiml_check( 'incomplete detected', 'gaiml_truncated' === $gpt->complete( $system, $user )->get_error_code() );
gaiml_respond( 200, array( 'status' => 'completed', 'output' => array( array( 'type' => 'message', 'content' => array( array( 'type' => 'refusal', 'refusal' => 'I cannot help.' ) ) ) ) ) );
gaiml_check( 'refusal detected', 'gaiml_refusal' === $gpt->complete( $system, $user )->get_error_code() );
gaiml_respond( 429, array( 'error' => array( 'message' => 'You exceeded your current quota', 'type' => 'insufficient_quota', 'code' => 'insufficient_quota' ) ) );
$error = $gpt->complete( $system, $user );
gaiml_check( 'quota error is fatal (no retry)', is_wp_error( $error ) && 'gaiml_quota' === $error->get_error_code() );
gaiml_respond( 200, array( 'data' => array( array( 'id' => 'gpt-6.1-sol', 'created' => 3 ), array( 'id' => 'gpt-image-2', 'created' => 5 ), array( 'id' => 'text-embedding-3-large', 'created' => 1 ), array( 'id' => 'gpt-6-luna', 'created' => 4 ) ) ) );
gaiml_check( 'models filtered and sorted', array( 'gpt-6-luna' => 'gpt-6-luna', 'gpt-6.1-sol' => 'gpt-6.1-sol' ) === $gpt->models() );

delete_option( 'gaiml_model_caps' );
echo $fails ? "\n$fails failure(s)\n" : "\nAll provider checks passed\n";
