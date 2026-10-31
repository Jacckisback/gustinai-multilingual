<?php
/**
 * Unit tests of the extraction / restoration engine (no WordPress needed).
 *
 * Run: php tests/unit.php
 *
 * @package GustinAI\Multilingual
 */

// phpcs:ignoreFile

define( 'ABSPATH', __DIR__ . '/' );
define( 'GAIML_DIR', dirname( __DIR__ ) . '/' );

function sanitize_title( $title ) {
	$title = strtolower( trim( (string) $title ) );
	$title = preg_replace( '~[^a-z0-9]+~', '-', $title );
	return trim( $title, '-' );
}

require GAIML_DIR . 'includes/Autoloader.php';
\GustinAI\Multilingual\Autoloader::register();

use GustinAI\Multilingual\Translation\Bag;
use GustinAI\Multilingual\Translation\Html;
use GustinAI\Multilingual\Translation\Elementor;
use GustinAI\Multilingual\Translation\Placeholders;

$failures = 0;
$count    = 0;

function check( string $name, $expected, $actual ): void {
	global $failures, $count;
	++$count;
	if ( $expected === $actual ) {
		echo "  ok   $name\n";
		return;
	}
	++$failures;
	echo "  FAIL $name\n    expected: " . var_export( $expected, true ) . "\n    actual:   " . var_export( $actual, true ) . "\n";
}

/**
 * Fake model: uppercases the words, keeps placeholders, "en-" slugs.
 */
function fake_translate( Bag $bag ): array {
	$out = array();
	foreach ( $bag->items() as $id => $item ) {
		if ( 'slug' === $item['kind'] ) {
			$out[ $id ] = 'en-' . $item['text'];
			continue;
		}
		$out[ $id ] = preg_replace_callback(
			'~(<[^>]+>)|([^<]+)~u',
			static fn( $m ) => '' !== $m[1] ? $m[1] : mb_strtoupper( $m[2] ),
			$item['text']
		);
	}
	return $out;
}

function sorted( array $a ): array {
	sort( $a );
	return $a;
}

function texts( Bag $bag ): array {
	return array_values( array_map( static fn( $i ) => $i['text'], $bag->items() ) );
}

echo "Gutenberg paragraph with inline markup\n";
$html   = "<!-- wp:paragraph -->\n<p>Bonjour <strong>le monde</strong>, lisez <a href=\"https://example.com/contact/\" title=\"Nous écrire\">notre page</a>.</p>\n<!-- /wp:paragraph -->";
$bag    = new Bag();
$render = Html::extract( $html, $bag );
check( 'segments', array( 'Bonjour <g1>le monde</g1>, lisez <g2>notre page</g2>.', 'Nous écrire' ), sorted( texts( $bag ) ) );
check( 'untranslated render is identical', $html, $render( array() ) );
check(
	'translated render',
	"<!-- wp:paragraph -->\n<p>BONJOUR <strong>LE MONDE</strong>, LISEZ <a href=\"https://example.com/contact/\" title=\"NOUS ÉCRIRE\">NOTRE PAGE</a>.</p>\n<!-- /wp:paragraph -->",
	$render( fake_translate( $bag ) )
);

echo "Placeholders can move, invalid answers fall back to the source\n";
$bag    = new Bag();
$render = Html::extract( '<p>Le <em>chat</em> <b>noir</b></p>', $bag );
check( 'reordered placeholders', '<p>The <b>black</b> <em>cat</em></p>', $render( array( 's1' => 'The <g2>black</g2> <g1>cat</g1>' ) ) );
check( 'missing placeholder keeps source', '<p>Le <em>chat</em> <b>noir</b></p>', $render( array( 's1' => 'The black <g1>cat</g1>' ) ) );
check( 'crossed placeholders keep source', '<p>Le <em>chat</em> <b>noir</b></p>', $render( array( 's1' => 'The <g1>black <g2>cat</g1></g2>' ) ) );
check( 'model markup is escaped', '<p>&lt;script&gt;x&lt;/script&gt; <em>a</em> <b>b</b> &amp; c &amp; d</p>', $render( array( 's1' => '<script>x</script> <g1>a</g1> <g2>b</g2> & c &amp; d' ) ) );

echo "Wrapping tags, whitespace and line breaks stay outside the segment\n";
$bag    = new Bag();
$render = Html::extract( "<h2>  <strong>Nos services</strong>\n</h2><p>Ligne 1<br>Ligne 2</p>", $bag );
check( 'segments', array( 'Nos services', 'Ligne 1<x1/>Ligne 2' ), texts( $bag ) );
check( 'render', "<h2>  <strong>NOS SERVICES</strong>\n</h2><p>LIGNE 1<br>LIGNE 2</p>", $render( fake_translate( $bag ) ) );

echo "Code, scripts, translate=no and numbers are kept\n";
$html   = '<p>Utilisez <code>wp_head()</code> ici.</p><script>var a = "<p>Ne pas traduire</p>";</script><p translate="no">GustinAI</p><p>2026</p><pre>du code</pre><span class="x notranslate">Marque</span>';
$bag    = new Bag();
$render = Html::extract( $html, $bag );
check( 'segments', array( 'Utilisez <x1/> ici.' ), texts( $bag ) );
check( 'render', '<p>UTILISEZ <code>wp_head()</code> ICI.</p><script>var a = "<p>Ne pas traduire</p>";</script><p translate="no">GustinAI</p><p>2026</p><pre>du code</pre><span class="x notranslate">Marque</span>', $render( fake_translate( $bag ) ) );

echo "Images, buttons and form fields\n";
$html   = '<figure class="wp-block-image"><img src="/a.jpg" alt="Un mojito frais" class="x"/><figcaption>Le mojito</figcaption></figure><input type="submit" value="Envoyer"><input type="text" placeholder="Votre e-mail" value="pre-rempli">';
$bag    = new Bag();
$render = Html::extract( $html, $bag );
check( 'segments', array( 'Un mojito frais', 'Le mojito', 'Envoyer', 'Votre e-mail' ), texts( $bag ) );
check( 'render', '<figure class="wp-block-image"><img src="/a.jpg" alt="UN MOJITO FRAIS" class="x"/><figcaption>LE MOJITO</figcaption></figure><input type="submit" value="ENVOYER"><input type="text" placeholder="VOTRE E-MAIL" value="pre-rempli">', $render( fake_translate( $bag ) ) );

echo "Entities are decoded for the model and re-encoded safely\n";
$bag    = new Bag();
$render = Html::extract( '<p>L&rsquo;&eacute;t&eacute; &amp; Tom &lt;3</p>', $bag );
check( 'decoded text', array( 'L’été & Tom <3' ), texts( $bag ) );
check( 'encoded output', '<p>L’ÉTÉ &amp; TOM &lt;3</p>', $render( fake_translate( $bag ) ) );

echo "Internal links are localized\n";
$bag    = new Bag();
$render = Html::extract(
	'<p><a href="https://example.com/contact/">Contact</a> <a href="https://other.org/">Autre</a></p>',
	$bag,
	array( 'link' => static fn( $url ) => str_replace( 'https://example.com/', 'https://example.com/en/', $url ) )
);
check( 'links', '<p><a href="https://example.com/en/contact/">CONTACT</a> <a href="https://other.org/">AUTRE</a></p>', $render( fake_translate( $bag ) ) );

echo "Gutenberg block attributes\n";
$html   = '<!-- wp:search {"label":"Rechercher","buttonText":"Chercher","placeholder":"Mot-clé…","style":{"color":{"text":"var:preset|color|base"}},"className":"is-style-big"} /-->' . "\n" . '<!-- wp:navigation-link {"label":"À propos","url":"https://example.com/a-propos/","kind":"post-type","id":12} /-->';
$bag    = new Bag();
$render = Html::extract( $html, $bag, array( 'link' => static fn( $url ) => str_replace( 'https://example.com/', 'https://example.com/en/', $url ) ) );
check( 'segments', array( 'Rechercher', 'Chercher', 'Mot-clé…', 'À propos' ), texts( $bag ) );
check(
	'render',
	'<!-- wp:search {"label":"RECHERCHER","buttonText":"CHERCHER","placeholder":"MOT-CLÉ…","style":{"color":{"text":"var:preset|color|base"}},"className":"is-style-big"} /-->' . "\n" . '<!-- wp:navigation-link {"label":"À PROPOS","url":"https://example.com/en/a-propos/","kind":"post-type","id":12} /-->',
	$render( fake_translate( $bag ) )
);
check( 'untouched without translation', $html, Html::extract( $html, new Bag() )( array() ) );

echo "Divi 5 block attributes\n";
$attrs  = '{"module":{"meta":{"adminLabel":{"desktop":{"value":"Texte"}}}},"content":{"innerContent":{"desktop":{"value":"\\u003cp\\u003eBienvenue chez nous\\u003c/p\\u003e"}}}}';
$html   = '<!-- wp:divi/text ' . $attrs . ' /--><!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Réserver","linkUrl":"https://example.com/reserver/"}}}}} /-->';
$bag    = new Bag();
$render = Html::extract( $html, $bag, array( 'link' => static fn( $url ) => str_replace( 'https://example.com/', 'https://example.com/en/', $url ) ) );
check( 'segments', array( 'Bienvenue chez nous', 'Réserver' ), texts( $bag ) );
check(
	'render',
	'<!-- wp:divi/text {"module":{"meta":{"adminLabel":{"desktop":{"value":"Texte"}}}},"content":{"innerContent":{"desktop":{"value":"\\u003cp\\u003eBIENVENUE CHEZ NOUS\\u003c/p\\u003e"}}}} /--><!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"RÉSERVER","linkUrl":"https://example.com/en/reserver/"}}}}} /-->',
	$render( fake_translate( $bag ) )
);

echo "Divi 4 shortcodes\n";
$html   = '[et_pb_section fb_built="1" _builder_version="4.16"][et_pb_row][et_pb_column type="4_4"][et_pb_text admin_label="Intro" _builder_version="4.16"]<p>Nos cocktails maison</p>[/et_pb_text][et_pb_button button_url="https://example.com/carte/" button_text="Voir la carte" _builder_version="4.16"][/et_pb_button][et_pb_blurb title="Un %22classique%22" use_icon="on"]<p>Depuis 1990</p>[/et_pb_blurb][et_pb_code]<p>code</p>[/et_pb_code][/et_pb_column][/et_pb_row][/et_pb_section]';
$bag    = new Bag();
$render = Html::extract( $html, $bag, array( 'link' => static fn( $url ) => str_replace( 'https://example.com/', 'https://example.com/en/', $url ) ) );
check( 'segments', array( 'Nos cocktails maison', 'Voir la carte', 'Un "classique"', 'Depuis 1990' ), texts( $bag ) );
check(
	'render',
	'[et_pb_section fb_built="1" _builder_version="4.16"][et_pb_row][et_pb_column type="4_4"][et_pb_text admin_label="Intro" _builder_version="4.16"]<p>NOS COCKTAILS MAISON</p>[/et_pb_text][et_pb_button button_url="https://example.com/en/carte/" button_text="VOIR LA CARTE" _builder_version="4.16"][/et_pb_button][et_pb_blurb title="UN %22CLASSIQUE%22" use_icon="on"]<p>DEPUIS 1990</p>[/et_pb_blurb][et_pb_code]<p>code</p>[/et_pb_code][/et_pb_column][/et_pb_row][/et_pb_section]',
	$render( fake_translate( $bag ) )
);

echo "Shortcodes inside content\n";
$bag    = new Bag();
$render = Html::extract( '<p>Écrivez-nous : [contact-form-7 id="12" title="Formulaire"] [Lire la suite]</p>', $bag );
check( 'segments', array( 'Écrivez-nous : <x1/> [Lire la suite]' ), texts( $bag ) );
check( 'render', '<p>ÉCRIVEZ-NOUS : [contact-form-7 id="12" title="Formulaire"] [LIRE LA SUITE]</p>', $render( fake_translate( $bag ) ) );

echo "Elementor data\n";
$data   = array(
	array(
		'id'       => 'a1',
		'elType'   => 'container',
		'settings' => array( 'background_color' => '#fff', '_title' => 'Hero' ),
		'elements' => array(
			array(
				'id'         => 'b1',
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array( 'title' => 'Bienvenue', 'header_size' => 'h1', 'title_color' => '#000' ),
				'elements'   => array(),
			),
			array(
				'id'         => 'b2',
				'elType'     => 'widget',
				'widgetType' => 'text-editor',
				'settings'   => array( 'editor' => '<p>Des cocktails <strong>uniques</strong>.</p>' ),
				'elements'   => array(),
			),
			array(
				'id'         => 'b3',
				'elType'     => 'widget',
				'widgetType' => 'button',
				'settings'   => array(
					'text'      => 'Réserver',
					'link'      => array( 'url' => 'https://example.com/reserver/', 'is_external' => '' ),
					'__dynamic__' => array( 'text' => '[elementor-tag id="x" name="post-title"]' ),
					'size'      => 'md',
				),
				'elements'   => array(),
			),
			array(
				'id'         => 'b4',
				'elType'     => 'widget',
				'widgetType' => 'tabs',
				'settings'   => array(
					'tabs' => array(
						array( '_id' => 't1', 'tab_title' => 'Onglet un', 'tab_content' => '<p>Contenu un</p>' ),
						array( '_id' => 't2', 'tab_title' => 'Onglet deux', 'tab_content' => 'Contenu deux' ),
					),
					'image' => array( 'id' => 55, 'url' => 'https://example.com/wp-content/uploads/a.jpg' ),
					'eael_button_text' => 'Voir plus',
					'some_setting_text' => 'fadeInUp',
				),
				'elements'   => array(),
			),
		),
	),
);
$images = array();
$bag    = new Bag();
$render = Elementor::extract(
	$data,
	$bag,
	array(
		'link'       => static fn( $url ) => str_replace( 'https://example.com/', 'https://example.com/en/', $url ),
		'attachment' => static function ( $id ) use ( &$images ) {
			$images[] = $id;
		},
	)
);
check( 'segments', array( 'Bienvenue', 'Des cocktails <g1>uniques</g1>.', 'Réserver', 'Onglet un', 'Contenu un', 'Onglet deux', 'Contenu deux', 'Voir plus' ), texts( $bag ) );
check( 'images collected', array( 55 ), $images );
$out = $render( fake_translate( $bag ) );
check( 'heading', 'BIENVENUE', $out[0]['elements'][0]['settings']['title'] );
check( 'design kept', 'h1', $out[0]['elements'][0]['settings']['header_size'] );
check( 'editor html', '<p>DES COCKTAILS <strong>UNIQUES</strong>.</p>', $out[0]['elements'][1]['settings']['editor'] );
check( 'button link', 'https://example.com/en/reserver/', $out[0]['elements'][2]['settings']['link']['url'] );
check( 'dynamic kept', '[elementor-tag id="x" name="post-title"]', $out[0]['elements'][2]['settings']['__dynamic__']['text'] );
check( 'repeater', 'ONGLET DEUX', $out[0]['elements'][3]['settings']['tabs'][1]['tab_title'] );
check( 'repeater id kept', 't2', $out[0]['elements'][3]['settings']['tabs'][1]['_id'] );
check( 'identifier kept', 'fadeInUp', $out[0]['elements'][3]['settings']['some_setting_text'] );
check( 'untranslated equals source', $data, Elementor::extract( $data, new Bag() )( array() ) );

echo "Validation\n";
check( 'variables kept', true, Placeholders::valid( '%%title%% - Cocktails', '%%title%% - Cocktails EN' ) );
check( 'variables lost', false, Placeholders::valid( '%%title%% - Cocktails', 'Cocktails' ) );
check( 'empty answer', false, Placeholders::valid( 'Bonjour', '' ) );
check( 'tolerant spelling', '<p>A <em>b</em></p>', Html::extract( '<p>Le <em>chat</em></p>', $bag2 = new Bag() )( array( 's1' => 'A < G1 >b</g1 >' ) ) );
check( 'slug', 'summer-cocktails', Placeholders::slug( ' /Summer Cocktails/ ' ) );
check( 'looks like text', array( true, true, false, false, false, false, true ), array(
	Placeholders::looks_like_text( 'Contact' ),
	Placeholders::looks_like_text( '10 raisons de venir' ),
	Placeholders::looks_like_text( '10px' ),
	Placeholders::looks_like_text( 'is-style-outline' ),
	Placeholders::looks_like_text( 'contact', true ),
	Placeholders::looks_like_text( 'https://example.com' ),
	Placeholders::looks_like_text( '#1 en France' ),
) );

echo "\n$count checks, $failures failure(s)\n";
exit( $failures ? 1 : 0 );
