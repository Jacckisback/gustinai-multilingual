<?php
/**
 * Test-only mu-plugin: a fake AI provider. Translations are "EN· original",
 * slugs "en-original", placeholders untouched. Copy to wp-content/mu-plugins/.
 *
 * @package GustinAI\Multilingual
 */

// phpcs:ignoreFile

add_filter(
	'gaiml_provider',
	static function () {
		return new class( 'mock-model', 'x' ) extends \GustinAI\Multilingual\Translation\Providers\Provider {
			public function id(): string {
				return 'mock';
			}
			public function name(): string {
				return 'Mock';
			}
			public function complete( string $system, string $user ) {
				preg_match( '~into [^()]+\(([a-zA-Z-]+)\)~', $system, $m );
				$code  = strtolower( $m[1] ?? 'xx' );
				$data  = json_decode( $user, true );
				$out   = array();
				foreach ( $data['items'] as $item ) {
					$text  = $item['text'];
					$out[] = array(
						'id'   => $item['id'],
						'text' => 'slug' === $item['kind'] ? $code . '-' . $text : strtoupper( $code ) . '· ' . $text,
					);
				}
				update_option( 'gaiml_mock_calls', (int) get_option( 'gaiml_mock_calls', 0 ) + 1, false );
				return array(
					'text'  => wp_json_encode( array( 'translations' => $out ) ),
					'usage' => array( 'input' => strlen( $user ) / 4, 'output' => strlen( $user ) / 4 ),
				);
			}
			public function models() {
				return array( 'mock-model' => 'Mock model' );
			}
		};
	}
);
