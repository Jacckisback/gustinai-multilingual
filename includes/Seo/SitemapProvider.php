<?php
/**
 * Core sitemap of the language home pages (when the home shows the latest posts;
 * static front pages are listed with the pages).
 *
 * Loaded only when WordPress builds its sitemaps (WP_Sitemaps_Provider exists).
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Seo;

use GustinAI\Multilingual\Core\Links;
use GustinAI\Multilingual\Languages;

defined( 'ABSPATH' ) || exit;

final class SitemapProvider extends \WP_Sitemaps_Provider {

	public function __construct() {
		$this->name        = 'languages';
		$this->object_type = 'languages';
	}

	/**
	 * @param int    $page_num
	 * @param string $object_subtype
	 * @return array<int, array<string, string>>
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		$urls = array();
		foreach ( Languages::codes() as $lang ) {
			if ( ! Languages::is_default( $lang ) ) {
				$urls[] = array( 'loc' => Links::language_home( $lang ) );
			}
		}
		return $urls;
	}

	/**
	 * @param string $object_subtype
	 */
	public function get_max_num_pages( $object_subtype = '' ) {
		return 1;
	}
}
