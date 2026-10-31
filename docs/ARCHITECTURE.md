# GustinAI Multilingual – Architecture et contrats internes

## Conventions

- PHP 8.1+, WordPress 6.5+ (testé sur 7.1.4). Namespace `GustinAI\Multilingual\`, autoload PSR-4 (`includes/Foo/Bar.php`).
- Style WordPress (tabulations, `array()`). Chaînes source en anglais, domaine `gustinai-multilingual` (fr_FR et es_ES fournis : `.po`, `.mo`, `.l10n.php`, `.json` pour le bloc).
- Préfixe `gaiml` partout : options, méta (`_gaiml_*`), tables, hooks, classes CSS.
- Chaque module = classe avec `public static function init(): void` qui n'enregistre que des hooks (liste dans `includes/Plugin.php`).
- Sécurité : nonces + `current_user_can()` sur chaque action, requêtes préparées, échappement à la sortie, aucun appel IA côté visiteur.

## Données

| Donnée | Stockage |
|---|---|
| Langue et groupe de traduction | table `gaiml_translations` : `(object_type, object_id)` → `(lang, group_id)`, `object_type` = `post` ou `term`, une seule ligne par langue et par groupe (`UNIQUE group_lang`). `group_id` = ID de l'original (groupes synthétiques ≥ 10^15 après un détachement). |
| Mémoire de traduction | table `gaiml_strings` : `md5(langue source | type | texte)` + langue cible → traduction ; `manual = 1` pour les corrections à la main (jamais écrasées). Types séparés : `slug`, `description`, le reste partagé. |
| File d'attente | table `gaiml_jobs` : une ligne par (objet, langue) ; `status` pending/running/failed (les jobs terminés sont supprimés), `locked_until` (verrou de 15 min et délai de nouvel essai ; un worker ne met à jour que le job dont il détient encore le verrou), `data` (état JSON : taille de lot, textes en échec). |
| Réglages | option autoloadée `gaiml_settings` (voir `Settings::defaults()`) ; clés API chiffrées dans `gaiml_secrets` (non autoloadée) ou constantes `GAIML_ANTHROPIC_API_KEY` / `GAIML_OPENAI_API_KEY`. |
| Suivi des traductions | méta de la traduction : `_gaiml_source_hash` (empreinte de l'original au moment de la traduction → « original modifié » ; `pending` pendant l'enregistrement, donc une sauvegarde interrompue sera refaite et non prise pour une retouche), `_gaiml_output_hash` (empreinte du texte généré → « modifié à la main »), `_gaiml_translated_at`. Traduction manuelle : `_gaiml_link_to` (ID de l'original) sur le brouillon automatique, rattachement au groupe au premier enregistrement seulement. |
| Alt des images | méta de la pièce jointe `_gaiml_alt_{lang}` (l'image est partagée entre langues). |
| Divers | `gaiml_usage` (tokens par mois et modèle), `gaiml_model_caps` (paramètres refusés par un modèle), `gaiml_links_version` (invalidation des correspondances de liens). |

Un contenu sans ligne de langue est traité comme étant dans la langue par défaut (rattrapage quotidien par `Install::backfill()`).

## Requête front

1. `Core\Router::init()` (au chargement de l'extension, avant `load_default_textdomain`) lit la langue dans l'URL (`/en/…`), ou dans `lang` / le référent pour l'AJAX, ou `?lang=` pour REST. Sitemaps et robots.txt : requête « neutre » ; les sitemaps listent le contenu des langues actives (`gaiml_lang = 'active'`).
2. Filtre `locale` → WordPress, le thème et les extensions chargent leurs traductions dans la langue de la page.
3. `Core\Links::home_url()` ajoute le préfixe à `home_url()` : WordPress retire lui-même ce chemin en analysant la requête, toutes les URL génériques (accueil, archives, recherche, flux) restent dans la langue. Les permaliens (`post_link`, `page_link`, `post_type_link`, `term_link`) utilisent la langue de l'objet.
4. `Core\Query` ajoute une jointure sur `gaiml_translations` (`posts_clauses`, `terms_clauses`, articles adjacents, archives), résout les chemins de pages dans la langue courante (slugs partagés), traduit `page_on_front` / `page_for_posts`, filtre les articles épinglés.
5. `Seo\Hreflang`, `Seo\Schema`, `Frontend\Switcher` s'appuient sur `Frontend\Alternates::current()` (URL de la page courante dans chaque langue, `exists` = vraie traduction publiée).
6. `Frontend\Menus` remplace à l'affichage les éléments de menu (classiques et blocs Navigation) par leur traduction ; `Frontend\Strings` traduit titre et slogan ; `Frontend\Media` traduit les alt ; `Core\Links::localize_content()` corrige les liens internes vers des pages traduites après coup.

## Traduction

```
Queue::enqueue(objet, langues) → Queue::run() → Engine::step()
  plan : Html / Attributes / Elementor extraient les textes dans un Bag
         (chaque texte → id ; chaque champ → closure qui reconstruit le champ)
  Translator::step : mémoire → textes manquants → 1 requête (≈ 6 000 caractères)
         → Placeholders::valid() → Memory::put_many()
  quand tout est traduit : Engine::save_post() / ensure_term()
```

- **Html** : tokenizer qui ne réécrit jamais le balisage. Les balises de bloc, commentaires Gutenberg et shortcodes de constructeurs découpent les segments ; les balises en ligne deviennent `<gN>…</gN>` / `<xN/>`. Attributs lus un par un à leur position (une valeur n'est jamais relue comme attribut) et remplacés par position : `alt`, `title`, `placeholder`, `aria-label`, `value` des boutons traduits, liens `href` localisés.
- **Attributes** : JSON des blocs Gutenberg (clés `label`, `placeholder`, `buttonText`, `*Text`, `*Label`…) et Divi 5 (`…innerContent.desktop.value`), réencodé comme `serialize_block_attributes()` ; jamais `style`, `layout`, `metadata`, `module`.
- **Elementor** : réglages texte des widgets et des répéteurs ; liens localisés ; images collectées pour traduire leur alt ; jamais `__dynamic__`, `__globals__`, HTML, CSS.
- **Placeholders** : validation (mêmes repères, bien imbriqués, variables `%%title%%` / `%s` conservées) et restauration ; tout autre `<` `>` `[` `]` produit par l'IA est encodé (aucun shortcode ne peut apparaître). Valeurs simples (réglages de constructeur, méta SEO) : refusées si la traduction ajoute un shortcode absent de l'original.
- **Providers** : `Anthropic` (Messages API, `anthropic-version: 2026-10-21`, `output_config.format` json_schema + `effort`, prompt système en cache) et `OpenAI` (Responses API, `text.format` json_schema strict, `reasoning.effort`, `store: false`). Un paramètre refusé (400) est retiré et mémorisé pour ce modèle. Erreurs fatales (clé, modèle, quota) → job en échec ; temporaires (429, 5xx) → nouvel essai avec délai.
- **Engine** : crée la traduction avec `wp_insert_post()` (langue imposée via `Content::with_language()` pour l'unicité des slugs par langue), copie les méta (sauf canonicals et caches), traduit les méta SEO, crée les termes manquants avec leur parent, rattache les pages enfants, purge le CSS Elementor. Une traduction à la corbeille ou abandonnée (brouillon automatique) compte comme manquante et est remplacée (`Engine::target()`). Si une autre requête a créé la même traduction au même moment, la copie est supprimée (pas de doublon). Slugs non traduits : la traduction reprend le slug de l'original (suffixe `-en` pour les termes, dont le slug est unique par taxonomie).

## Hooks

- Actions : `gaiml_loaded`, `gaiml_post_translated( $target_id, $source_id, $lang )`, `gaiml_translations_changed( $type, $id, $lang, $group )`. Cron : `gaiml_cron` (toutes les minutes tant que la file n'est pas vide).
- Filtres : `gaiml_post_types`, `gaiml_taxonomies`, `gaiml_translatable_meta`, `gaiml_meta_skip`, `gaiml_exclude_post`, `gaiml_strings`, `gaiml_system_prompt`, `gaiml_known_models`, `gaiml_provider`, `gaiml_alternates`, `gaiml_hreflang`, `gaiml_llms_txt`, `gaiml_switcher_css`.

## API entre modules

- `Core\Translations::lang()/lang_or_default()/group()/members()/translation()/set()/unlink()/prime()`
- `Core\Links::add_lang( $url, $lang )`, `strip_lang( $url )`, `language_home( $lang )`, `localize_url( $url, $lang )`
- `Core\Router::current()`, `context()`, `is_neutral()`, `switch_to( $lang )`
- `Translation\Engine::status( $source_id, $lang )` : missing, queued, failed, translated, outdated, edited, manual, source ; `Engine::target( $source_id, $lang )` : traduction utilisable (0 si absente, à la corbeille ou abandonnée)
- Requêtes : `'gaiml_lang' => 'en'` (une langue), `'all'` (aucun filtre), `'active'` (langues actives)
- `Translation\Queue::enqueue( $type, $id, $langs, $force )`, `run( $budget, $filter )`, `stats()`
- `Translation\Models::estimate( $provider, $model, $chars )`
- AJAX admin (nonce `gaiml_admin`) : `gaiml_translate`, `gaiml_process`, `gaiml_models`, `gaiml_test`, `gaiml_estimate`, `gaiml_bulk`, `gaiml_queue`, `gaiml_strings`.
