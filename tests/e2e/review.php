<?php
/**
 * Regression tests of the security and robustness review:
 * `wp eval-file tests/e2e/review.php` (with the mock mu-plugin, French default,
 * English and Spanish active).
 *
 * @package GustinAI\Multilingual
 */

// phpcs:ignoreFile

use GustinAI\Multilingual\Core\Links;
use GustinAI\Multilingual\Core\Query;
use GustinAI\Multilingual\Core\Translations;
use GustinAI\Multilingual\Settings;
use GustinAI\Multilingual\Translation\Engine;
use GustinAI\Multilingual\Translation\Queue;

global $wpdb;
require_once ABSPATH . 'wp-admin/includes/post.php';
wp_set_current_user( 1 );

$ok    = 0;
$fail  = 0;
$check = static function ( string $name, bool $pass, $info = '' ) use ( &$ok, &$fail ) {
	echo ( $pass ? '  ok   ' : '  FAIL ' ) . $name . ( $pass || '' === $info ? '' : ' — ' . ( is_string( $info ) ? $info : wp_json_encode( $info ) ) ) . "\n";
	$pass ? ++$ok : ++$fail;
};
$posts     = array();
$terms     = array();
$translate = static function ( int $id, string $lang ): array {
	Queue::enqueue( 'post', $id, array( $lang ), true );
	$last = array();
	for ( $i = 0; $i < 20; $i++ ) {
		$run = Queue::run( 5, array( 'type' => 'post', 'id' => $id ) );
		foreach ( $run['results'] as $result ) {
			if ( $result['id'] === $id && $result['lang'] === $lang ) {
				$last = $result;
			}
		}
		if ( ! Queue::job( 'post', $id, $lang ) || 'failed' === ( $last['status'] ?? '' ) ) {
			break;
		}
	}
	return $last;
};
$page = static function ( array $args ) use ( &$posts ): int {
	$id      = (int) wp_insert_post( wp_slash( $args + array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<p>Bonjour</p>' ) ) );
	$posts[] = $id;
	return $id;
};
$manual = static function ( int $from, string $lang ) use ( &$posts ): WP_Post {
	$_GET  = array( 'gaiml_lang' => $lang, 'gaiml_from' => $from );
	$draft = get_default_post_to_edit( 'page', true );
	$_GET  = array();
	$posts[] = $draft->ID;
	return $draft;
};

echo "Trashed translation\n";
$p   = $page( array( 'post_title' => 'Revue corbeille', 'post_name' => 'revue-corbeille' ) );
$en1 = (int) ( $translate( $p, 'en' )['target'] ?? 0 );
$posts[] = $en1;
$check( 'translation created', $en1 > 0 && 'publish' === get_post_status( $en1 ) );
wp_trash_post( $en1 );
$check( 'trashed translation counts as missing', 'missing' === Engine::status( $p, 'en' ) );
$en2 = (int) ( $translate( $p, 'en' )['target'] ?? 0 );
$posts[] = $en2;
$check( 'a new translation replaces it', $en2 > 0 && $en2 !== $en1 && 'publish' === get_post_status( $en2 ) && $en2 === Translations::translation( 'post', $p, 'en' ) );
$check( 'the trashed post left the group', Translations::group( 'post', $en1 ) !== Translations::group( 'post', $p ) );

echo "Manual translation\n";
$draft = $manual( $p, 'es' );
$check( 'auto-draft gets the requested language', 'es' === Translations::lang( 'post', $draft->ID ) );
$check( 'auto-draft does not take the slot', 0 === Translations::translation( 'post', $p, 'es' ) );
wp_update_post( array( 'ID' => $draft->ID, 'post_status' => 'draft', 'post_title' => 'Revisión manual' ) );
$check( 'linked on first save', $draft->ID === Translations::translation( 'post', $p, 'es' ) );
$check( 'link marker removed', '' === get_post_meta( $draft->ID, '_gaiml_link_to', true ) );
$other = $manual( $p, 'en' );
wp_update_post( array( 'ID' => $other->ID, 'post_status' => 'draft', 'post_title' => 'Duplicate' ) );
$check( 'no link when the language is taken', $en2 === Translations::translation( 'post', $p, 'en' ) && Translations::group( 'post', $other->ID ) !== Translations::group( 'post', $p ) );
$q = $page( array( 'post_title' => 'Sans langue' ) );
Translations::delete( 'post', $q );
$third = $manual( $q, 'en' );
wp_update_post( array( 'ID' => $third->ID, 'post_status' => 'draft', 'post_title' => 'No language' ) );
$check( 'original without a row gets the default language', 'fr' === Translations::lang( 'post', $q ) && $third->ID === Translations::translation( 'post', $q, 'en' ) );

echo "Concurrent creation\n";
$r          = $page( array( 'post_title' => 'Revue course' ) );
$competitor = 0;
$race       = static function ( $data ) use ( &$competitor, &$posts ) {
	if ( 0 === $competitor && 'EN· Revue course' === wp_unslash( $data['post_title'] ) ) {
		$competitor = -1;
		// Another request creates the English translation at the same moment.
		$competitor = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Concurrent' ) );
		$posts[]    = $competitor;
	}
	return $data;
};
add_filter( 'wp_insert_post_data', $race );
$result = $translate( $r, 'en' );
remove_filter( 'wp_insert_post_data', $race );
$copies = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title = 'EN· Revue course'" );
$check( 'the race is reported', 'failed' === ( $result['status'] ?? '' ), $result );
$check( 'no duplicate left behind', 0 === $copies && $competitor === Translations::translation( 'post', $r, 'en' ) );
Queue::forget( 'post', $r );

echo "Slugs not translated\n";
Settings::update( array( 'translate_slugs' => false ) );
$cat     = wp_insert_term( 'Revue catégorie', 'category', array( 'slug' => 'revue-categorie' ) );
$terms[] = (int) $cat['term_id'];
$s       = $page( array( 'post_title' => 'Revue slug', 'post_name' => 'revue-slug' ) );
$a       = $page( array( 'post_type' => 'post', 'post_title' => 'Revue article', 'post_name' => 'revue-article', 'post_category' => array( (int) $cat['term_id'] ) ) );
$s_en    = (int) ( $translate( $s, 'en' )['target'] ?? 0 );
$a_en    = (int) ( $translate( $a, 'en' )['target'] ?? 0 );
$posts[] = $s_en;
$posts[] = $a_en;
$cat_en  = Translations::translation( 'term', (int) $cat['term_id'], 'en' );
$terms[] = $cat_en;
Settings::update( array( 'translate_slugs' => true ) );
$check( 'page keeps the original slug', 'revue-slug' === get_post_field( 'post_name', $s_en ), get_post_field( 'post_name', $s_en ) );
$check( 'the shared slug resolves per language', $s_en === Query::post_by_path( 'revue-slug', 'en', 'page' ) && $s === Query::post_by_path( 'revue-slug', 'fr', 'page' ) );
$check( 'term keeps the original slug (suffixed)', $cat_en && 'revue-categorie-en' === get_term_field( 'slug', $cat_en ), $cat_en ? get_term_field( 'slug', $cat_en ) : 'no term' );

echo "Interrupted save\n";
update_post_meta( $s_en, '_gaiml_source_hash', 'pending' );
$wpdb->update( $wpdb->posts, array( 'post_content' => '<p>Partial</p>' ), array( 'ID' => $s_en ) );
clean_post_cache( $s_en );
$check( 'not taken for a hand edit', ! Engine::is_edited( $s_en ) && 'outdated' === Engine::status( $s, 'en' ) );

echo "Links\n";
$blog    = (int) get_option( 'page_for_posts' );
$blog_en = $blog ? Translations::translation( 'post', $blog, 'en' ) : 0;
$check( 'posts page is resolved', ! $blog || array( 'post', $blog ) === Links::resolve_object( Links::raw_home() . '/' . get_post_field( 'post_name', $blog ) . '/' ) );
if ( $blog_en && 'publish' === get_post_status( $blog_en ) ) {
	$check( 'link to the posts page is localized', get_permalink( $blog_en ) === Links::localize_url( Links::raw_home() . '/' . get_post_field( 'post_name', $blog ) . '/', 'en' ) );
}
$check( 'relative URL gets the prefix', '/en/contact/' === Links::add_lang( '/contact/', 'en' ) && '/en/' === Links::add_lang( '/', 'en' ) );
$check( 'relative URL loses the prefix', array( '/contact/', 'en' ) === Links::strip_lang( '/en/contact/' ) );

echo "Sitemaps\n";
$d = $page( array( 'post_title' => 'Seite', 'post_name' => 'revue-seite' ) );
$wpdb->update( Translations::table(), array( 'lang' => 'de' ), array( 'object_type' => 'post', 'object_id' => $d ) );
Translations::flush();
$urls = wp_list_pluck( wp_sitemaps_get_server()->registry->get_provider( 'posts' )->get_url_list( 1, 'page' ), 'loc' );
$check( 'content of a removed language is not listed', ! array_filter( $urls, static fn( $u ) => false !== strpos( $u, 'revue-seite' ) ) );
$check( 'active languages are listed', (bool) array_filter( $urls, static fn( $u ) => false !== strpos( $u, '/en/revue-slug/' ) ) && in_array( get_permalink( $s ), $urls, true ) );

echo "Language prefixes\n";
$_POST    = array(
	'languages'    => array(
		'fr' => array( 'slug' => 'fr' ),
		'en' => array( 'slug' => 'feed' ),
		'es' => array( 'slug' => 'category' ),
	),
	'default_lang' => 'fr',
);
$messages = array();
$method   = new ReflectionMethod( \GustinAI\Multilingual\Admin\SettingsPage::class, 'sanitize_languages' );
$method->setAccessible( true );
$result   = $method->invokeArgs( null, array( &$messages ) );
$_POST    = array();
$slugs    = wp_list_pluck( $result['languages'], 'slug', 'code' );
$check( 'WordPress routes are refused', 'en' === $slugs['en'] && 'es' === $slugs['es'] && 2 === count( $messages ), $slugs );

echo "Queue lock\n";
Queue::enqueue( 'post', $s, array( 'es' ) );
$claim   = new ReflectionMethod( Queue::class, 'claim' );
$release = new ReflectionMethod( Queue::class, 'release' );
$claim->setAccessible( true );
$release->setAccessible( true );
$job = $claim->invoke( null, array( 'type' => 'post', 'id' => $s ) );
// The lock expired and another worker took the job over.
$wpdb->update( Queue::table(), array( 'locked_until' => gmdate( 'Y-m-d H:i:s', time() + 999 ) ), array( 'id' => (int) $job->id ) );
$release->invoke( null, $job, array( 'status' => 'done' ), array() );
$check( 'a worker that lost its job does not delete it', null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Queue::table() . ' WHERE id = %d', (int) $job->id ) ) );
Queue::forget( 'post', $s );

foreach ( array_unique( array_filter( $posts ) ) as $id ) {
	wp_delete_post( (int) $id, true );
}
foreach ( array_unique( array_filter( $terms ) ) as $id ) {
	wp_delete_term( (int) $id, 'category' );
}
echo $fail ? "\n$fail failure(s)\n" : "\nAll review checks passed ($ok)\n";
