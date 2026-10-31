<?php
/**
 * Test content: run with `wp eval-file tests/e2e/setup.php` on a fresh French site.
 * Creates Gutenberg, Elementor and Divi pages, a blog post with categories,
 * tags, featured image and SEO fields, menus, and enables English and Spanish.
 *
 * @package GustinAI\Multilingual
 */

// phpcs:ignoreFile

use GustinAI\Multilingual\Languages;
use GustinAI\Multilingual\Settings;

Settings::update(
	array(
		'default_lang' => 'fr',
		'languages'    => array( Languages::entry( 'fr' ), Languages::entry( 'en' ), Languages::entry( 'es' ) ),
		'post_types'   => array( 'post', 'page', 'elementor_library', 'wp_block' ),
		'taxonomies'   => array( 'category', 'post_tag' ),
		'switcher_menu' => 'primary',
		'glossary'     => "GustinAI\ncocktail = es: cóctel",
	)
);

$home_url = home_url( '/' );

function gaiml_test_page( $title, $slug, $content, $parent = 0, $type = 'page' ) {
	return wp_insert_post(
		wp_slash(
			array(
				'post_type'    => $type,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => $content,
				'post_parent'  => $parent,
			)
		)
	);
}

$contact = gaiml_test_page( 'Contact', 'contact', "<!-- wp:paragraph -->\n<p>Écrivez-nous, nous répondons sous 24 h.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[contact-form-7 id=\"12\" title=\"Formulaire de contact\"]\n<!-- /wp:shortcode -->" );
$about   = gaiml_test_page( 'À propos', 'a-propos', "<!-- wp:paragraph -->\n<p>Nous créons des cocktails depuis 2010 à Madrid.</p>\n<!-- /wp:paragraph -->" );
$team    = gaiml_test_page( 'Notre équipe', 'equipe', "<!-- wp:paragraph -->\n<p>Une équipe de cinq passionnés.</p>\n<!-- /wp:paragraph -->", $about );
$blog    = gaiml_test_page( 'Blog', 'blog', '' );

$pattern = gaiml_test_page( 'Bandeau promo', 'bandeau-promo', "<!-- wp:paragraph {\"className\":\"promo\"} -->\n<p class=\"promo\">Offre spéciale : -10 % ce week-end</p>\n<!-- /wp:paragraph -->", 0, 'wp_block' );

$home = gaiml_test_page(
	'Accueil',
	'accueil',
	"<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Bienvenue à l’Atelier Cocktails</h1>\n<!-- /wp:heading -->\n\n"
	. "<!-- wp:paragraph -->\n<p>Des cocktails <strong>faits maison</strong>, servis avec le sourire. Consultez <a href=\"" . get_permalink( $contact ) . "\">notre page contact</a> ou lisez <a href=\"" . get_permalink( $about ) . "\" title=\"Qui sommes-nous ?\">notre histoire</a>.</p>\n<!-- /wp:paragraph -->\n\n"
	. "<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Mojito &amp; menthe fraîche</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>Spritz<br>à l’orange</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->\n\n"
	. "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"" . get_permalink( $contact ) . "\">Réserver une table</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->\n\n"
	. "<!-- wp:search {\"label\":\"Rechercher\",\"buttonText\":\"Chercher\",\"placeholder\":\"Un cocktail…\"} /-->\n\n"
	. "<!-- wp:block {\"ref\":$pattern} /-->\n\n"
	. "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>shaker.shake(10);</code></pre>\n<!-- /wp:code -->"
);

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $home );
update_option( 'page_for_posts', $blog );

$cocktails = wp_insert_term( 'Cocktails', 'category', array( 'slug' => 'cocktails', 'description' => 'Toutes nos <strong>recettes</strong> de cocktails.' ) );
$summer    = wp_insert_term( 'Recettes d’été', 'category', array( 'slug' => 'recettes-ete', 'parent' => $cocktails['term_id'] ) );

$attachment = wp_insert_attachment(
	array(
		'post_title'     => 'Mojito',
		'post_mime_type' => 'image/jpeg',
		'post_status'    => 'inherit',
		'guid'           => $home_url . 'wp-content/uploads/mojito.jpg',
	)
);
update_post_meta( $attachment, '_wp_attached_file', 'mojito.jpg' );
update_post_meta( $attachment, '_wp_attachment_image_alt', 'Un mojito bien frais' );

$post = wp_insert_post(
	wp_slash(
		array(
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_title'    => 'Les meilleurs cocktails de l’été',
			'post_name'     => 'meilleurs-cocktails-ete',
			'post_excerpt'  => 'Notre sélection de cocktails frais pour l’été.',
			'post_content'  => "<!-- wp:paragraph -->\n<p>L’été arrive : voici nos <em>cocktails</em> préférés. Réservez via <a href=\"" . get_permalink( $contact ) . "#form\">le formulaire</a>.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:image {\"id\":$attachment} -->\n<figure class=\"wp-block-image\"><img src=\"{$home_url}wp-content/uploads/mojito.jpg\" alt=\"Un mojito bien frais\" class=\"wp-image-$attachment\"/><figcaption class=\"wp-element-caption\">Le mojito de la maison</figcaption></figure>\n<!-- /wp:image -->",
			'post_category' => array( $summer['term_id'] ),
			'tags_input'    => array( 'Été' ),
		)
	)
);
set_post_thumbnail( $post, $attachment );
update_post_meta( $post, '_yoast_wpseo_metadesc', 'Découvrez nos 10 cocktails préférés pour l’été.' );
update_post_meta( $post, '_yoast_wpseo_title', '%%title%% %%sep%% Cocktails maison' );

$elementor = wp_insert_post(
	wp_slash(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Carte des cocktails',
			'post_name'    => 'carte',
			'post_content' => '<h2>Notre carte</h2><p>Des cocktails classiques et créatifs.</p>',
		)
	)
);
$elementor_data = array(
	array(
		'id'       => 'c1a2b3',
		'elType'   => 'container',
		'settings' => array( 'content_width' => 'boxed' ),
		'elements' => array(
			array(
				'id'         => 'h1a2b3',
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array( 'title' => 'Notre carte', 'header_size' => 'h2' ),
				'elements'   => array(),
			),
			array(
				'id'         => 't1a2b3',
				'elType'     => 'widget',
				'widgetType' => 'text-editor',
				'settings'   => array( 'editor' => '<p>Des cocktails <strong>classiques</strong> et créatifs. <a href="' . get_permalink( $contact ) . '">Contactez-nous</a>.</p>' ),
				'elements'   => array(),
			),
			array(
				'id'         => 'b1a2b3',
				'elType'     => 'widget',
				'widgetType' => 'button',
				'settings'   => array(
					'text' => 'Réserver',
					'link' => array( 'url' => get_permalink( $contact ), 'is_external' => '', 'nofollow' => '' ),
				),
				'elements'   => array(),
			),
			array(
				'id'         => 'i1a2b3',
				'elType'     => 'widget',
				'widgetType' => 'image',
				'settings'   => array( 'image' => array( 'id' => $attachment, 'url' => $home_url . 'wp-content/uploads/mojito.jpg' ) ),
				'elements'   => array(),
			),
		),
	),
);
update_post_meta( $elementor, '_elementor_edit_mode', 'builder' );
update_post_meta( $elementor, '_elementor_template_type', 'wp-page' );
update_post_meta( $elementor, '_elementor_version', '4.3.5' );
update_post_meta( $elementor, '_elementor_data', wp_slash( wp_json_encode( $elementor_data ) ) );

$divi = gaiml_test_page(
	'Page Divi',
	'page-divi',
	'[et_pb_section fb_built="1" _builder_version="4.27.4"][et_pb_row _builder_version="4.27.4"][et_pb_column type="4_4"][et_pb_text admin_label="Intro" _builder_version="4.27.4"]<p>Bienvenue sur notre page construite avec Divi.</p>[/et_pb_text][et_pb_button button_url="' . get_permalink( $contact ) . '" button_text="Nous écrire" _builder_version="4.27.4"][/et_pb_button][/et_pb_column][/et_pb_row][/et_pb_section]'
);

// Classic menu (Twenty Twenty-One).
$menu = wp_create_nav_menu( 'Menu principal' );
wp_update_nav_menu_item( $menu, 0, array( 'menu-item-object-id' => $about, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );
wp_update_nav_menu_item( $menu, 0, array( 'menu-item-object-id' => $elementor, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );
wp_update_nav_menu_item( $menu, 0, array( 'menu-item-object-id' => $cocktails['term_id'], 'menu-item-object' => 'category', 'menu-item-type' => 'taxonomy', 'menu-item-status' => 'publish' ) );
wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'Réserver', 'menu-item-url' => get_permalink( $contact ), 'menu-item-type' => 'custom', 'menu-item-status' => 'publish' ) );
wp_update_nav_menu_item( $menu, 0, array( 'menu-item-object-id' => $divi, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-title' => 'Divi (non traduite)' ) );
set_theme_mod( 'nav_menu_locations', array( 'primary' => $menu ) );

// Navigation block (Twenty Twenty-Five).
wp_insert_post(
	wp_slash(
		array(
			'post_type'    => 'wp_navigation',
			'post_status'  => 'publish',
			'post_title'   => 'Navigation',
			'post_content' => '<!-- wp:navigation-link {"label":"À propos","type":"page","id":' . $about . ',"url":"' . get_permalink( $about ) . '","kind":"post-type"} /--><!-- wp:navigation-link {"label":"Réserver","url":"' . get_permalink( $contact ) . '","kind":"custom"} /-->',
		)
	)
);

update_option( 'blogdescription', 'Cocktails faits maison à Madrid' );
flush_rewrite_rules();

echo wp_json_encode( compact( 'home', 'contact', 'about', 'team', 'blog', 'post', 'elementor', 'divi', 'pattern', 'attachment', 'menu' ) ) . "\n";
