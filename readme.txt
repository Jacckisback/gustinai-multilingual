=== GustinAI Multilingual ===
Contributors: thierrygustin
Tags: multilingual, translation, ai translation, hreflang, language switcher
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn any WordPress site into a fast, SEO & GEO-ready multilingual site, translated by Claude or ChatGPT models. Gutenberg, Elementor and Divi.

== Description ==

GustinAI Multilingual creates real translated pages (not on-the-fly translations), with translated URLs, categories, menus and SEO fields, and the hreflang annotations search engines need.

**AI translation that never breaks your layout.** Only texts are sent to the AI: markup is replaced by numbered markers, so Gutenberg blocks, Elementor data and Divi shortcodes stay byte-identical. If the AI damages a marker, the sentence stays in the original language instead of breaking the page.

**Works with your page builder.** Gutenberg (block attributes and synced patterns included), Elementor (all text widgets, links, images, templates), Divi 4 and Divi 5, the classic editor and custom HTML.

**SEO.** Subdirectory URLs (/en/, /es/), translated slugs, translated categories and tags with their hierarchy, hreflang + x-default, correct canonical and html lang, sitemaps with every language, Yoast SEO / Rank Math / SEOPress / The SEO Framework / AIOSEO fields translated, no duplicate content on untranslated URLs.

**GEO (AI answer engines).** llms.txt per language, schema.org inLanguage, workTranslation and translationOfWork, internal links that stay in the visitor's language.

**Your choice of AI.** Claude (Anthropic) or OpenAI models. Recommended: Claude Opus 5.5 or GPT-6.1 Sol, but any model of your account works. Cost estimate before translating, translation memory (identical texts translated once, only changed sentences re-translated), monthly usage report.

**Light, fast, secure.** No AI call while serving visitors, no front-end JavaScript, one indexed join to filter by language, API keys encrypted, nonces and capability checks everywhere, model output escaped.

== External services ==

This plugin sends the texts of your content to an AI provider to translate them, only when an administrator or editor asks for a translation (button, bulk translation, automatic update option, WP-CLI). Nothing is sent while visitors browse the site.

* Anthropic API (Claude), https://api.anthropic.com — texts to translate, the translation instructions, your site description and glossary. [Terms](https://www.anthropic.com/legal/commercial-terms), [Privacy policy](https://www.anthropic.com/legal/privacy).
* OpenAI API, https://api.openai.com — same data, sent with `store: false`. [Terms](https://openai.com/policies/business-terms/), [Privacy policy](https://openai.com/policies/privacy-policy/).

Only the provider selected in Multilingual > Settings > AI translation is used, with your own API key.

== Installation ==

1. Upload and activate the plugin.
2. Multilingual > Settings > Languages: add your languages.
3. AI translation: paste a Claude or OpenAI API key (or define GAIML_ANTHROPIC_API_KEY / GAIML_OPENAI_API_KEY in wp-config.php).
4. Bulk translation: estimate the cost, start.
5. Add the language switcher (block, shortcode [gaiml_switcher], menu location or gaiml_language_switcher()).

== Frequently Asked Questions ==

= Do my existing URLs change? =

No. The default language keeps its URLs; other languages get a prefix (/en/, /es/).

= Can I edit a translation by hand? =

Yes, translations are regular posts. A translation edited by hand is never overwritten by automatic updates.

= Does it work with page caching and CDNs? =

Yes. The language comes from the URL only (no cookie, no browser-language redirect), so every page can be cached.

= How much does a translation cost? =

About $5.80 per language for 100 pages of ~800 words with Claude Opus 5.5, $2.90 with Claude Sonnet 5.5 or GPT-6.1 Sol, $0.15 with Claude Haiku 5.5 or GPT-6 Luna (estimates). The plugin shows an estimate before you start.

== Changelog ==

= 1.0.0 =
* First release.
