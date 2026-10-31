<?php
/**
 * Engine resilience: `wp eval-file tests/e2e/engine.php` (with the mock mu-plugin).
 * A provider that truncates big batches and breaks the markup of one text:
 * batches shrink, the broken text stays in the original language, the page
 * markup stays valid.
 *
 * @package GustinAI\Multilingual
 */

// phpcs:ignoreFile

use GustinAI\Multilingual\Core\Translations;
use GustinAI\Multilingual\Translation\Queue;

remove_all_filters( 'gaiml_provider' );
add_filter(
	'gaiml_provider',
	static function () {
		return new class( 'flaky-model', 'x' ) extends \GustinAI\Multilingual\Translation\Providers\Provider {
			public function id(): string {
				return 'flaky';
			}
			public function name(): string {
				return 'Flaky';
			}
			public function complete( string $system, string $user ) {
				$data = json_decode( $user, true );
				$GLOBALS['gaiml_batches'][] = count( $data['items'] );
				if ( count( $data['items'] ) > 2 ) {
					return new WP_Error( 'gaiml_truncated', 'cut' );
				}
				$out = array();
				foreach ( $data['items'] as $item ) {
					$text  = false !== strpos( $item['text'], 'casse' ) ? 'Broken <g1>markup' : '[DE] ' . $item['text'];
					$out[] = array( 'id' => $item['id'], 'text' => $text );
				}
				return array( 'text' => wp_json_encode( array( 'translations' => $out ) ), 'usage' => array( 'input' => 1, 'output' => 1 ) );
			}
			public function models() {
				return array();
			}
		};
	}
);

// Start from an empty translation memory for German (it would serve the texts of a previous run).
$GLOBALS['wpdb']->delete( \GustinAI\Multilingual\Translation\Memory::table(), array( 'lang' => 'de' ) );

\GustinAI\Multilingual\Settings::update( array( 'languages' => array_merge( array_values( \GustinAI\Multilingual\Languages::all() ), array( \GustinAI\Multilingual\Languages::entry( 'de' ) ) ) ) );

$content = '';
for ( $i = 1; $i <= 6; $i++ ) {
	$content .= "<!-- wp:paragraph -->\n<p>Paragraphe numéro $i avec <strong>du gras</strong>.</p>\n<!-- /wp:paragraph -->\n\n";
}
$content .= "<!-- wp:paragraph -->\n<p>Ce texte <em>casse</em> les balises.</p>\n<!-- /wp:paragraph -->";
$id = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Test robustesse', 'post_content' => $content ) ) );

$GLOBALS['gaiml_batches'] = array();
Queue::enqueue( 'post', $id, array( 'de' ) );
for ( $i = 0; $i < 30 && Queue::stats()['pending'] + Queue::stats()['running'] > 0; $i++ ) {
	Queue::run( 5 );
}
$target = Translations::translation( 'post', $id, 'de' );
$out    = $target ? get_post_field( 'post_content', $target ) : '';
$ok     = 0;
$fail   = 0;
$check  = static function ( string $name, bool $pass ) use ( &$ok, &$fail ) {
	echo ( $pass ? '  ok   ' : '  FAIL ' ) . $name . "\n";
	$pass ? ++$ok : ++$fail;
};
$check( 'translation created', $target > 0 );
$check( 'batches shrank after truncation', max( $GLOBALS['gaiml_batches'] ) > 2 && end( $GLOBALS['gaiml_batches'] ) <= 2 );
$check( 'paragraphs translated', 6 === substr_count( $out, '[DE] Paragraphe' ) );
$check( 'broken answer kept in original language', false !== strpos( $out, 'Ce texte <em>casse</em> les balises.' ) );
$check( 'no broken markup', false === strpos( $out, '<g1>' ) && substr_count( $out, '<p>' ) === substr_count( $out, '</p>' ) );
$check( 'block delimiters intact', 7 === substr_count( $out, '<!-- wp:paragraph -->' ) && 7 === substr_count( $out, '<!-- /wp:paragraph -->' ) );
$check( 'queue empty', 0 === Queue::stats()['pending'] + Queue::stats()['failed'] );
echo "  batches: " . implode( ',', $GLOBALS['gaiml_batches'] ) . "\n";

wp_delete_post( $id, true );
if ( $target ) {
	wp_delete_post( $target, true );
}
\GustinAI\Multilingual\Settings::update( array( 'languages' => array_values( array_filter( \GustinAI\Multilingual\Languages::all(), static fn( $l ) => 'de' !== $l['code'] ) ) ) );
echo $fail ? "\n$fail failure(s)\n" : "\nAll engine checks passed\n";
