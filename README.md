# GustinAI Multilingual

**Le site WordPress multilingue, traduit par l'IA, sans sacrifier le SEO.** Une extension légère qui transforme n'importe quel site WordPress en site multilingue : vraies pages traduites, URL et slugs traduits, catégories et menus traduits, hreflang, llms.txt. Traduction avec Claude (Anthropic) ou les modèles de ChatGPT (OpenAI). Compatible Gutenberg, Elementor et Divi.

- Version : 1.0.0
- Prérequis : WordPress 6.5+ (testé sur 7.1.4), PHP 8.1+, permaliens « jolis » activés
- Licence : GPL-2.0-or-later
- Auteur : Thierry Gustin — [gustinai.com](https://gustinai.com)

---

## Pourquoi cette extension ?

| Problème classique | Réponse de GustinAI Multilingual |
|---|---|
| Traductions qui cassent la mise en page | L'IA ne voit jamais le HTML : seuls les textes sont envoyés, le balisage est remplacé par des repères (`<g1>…</g1>`). Blocs Gutenberg, données Elementor et shortcodes Divi restent identiques à l'octet près. |
| Traduction à la volée lente et coûteuse | Chaque traduction est une vraie page enregistrée : zéro appel IA pendant la visite, compatible avec tous les caches. |
| URL non traduites, catégories en français sur le site anglais | Slugs traduits (`/en/summer-cocktails/`), catégories et étiquettes traduites avec leur hiérarchie, même slug possible dans deux langues pour les pages et articles (`/contact/` et `/en/contact/`). |
| SEO multilingue mal configuré | hreflang + x-default, canonical, `<html lang>`, locale transmise aux extensions SEO (og:locale), sitemaps avec toutes les langues actives, champs Yoast / Rank Math / SEOPress / The SEO Framework / AIOSEO traduits. |
| Contenu invisible pour les IA | llms.txt par langue, données structurées `inLanguage` + `workTranslation` / `translationOfWork`, liens internes qui restent dans la langue. |
| Facture d'IA imprévisible | Coût estimé avant de lancer, mémoire de traduction (un texte identique n'est traduit qu'une fois, une page modifiée ne renvoie que les phrases modifiées), consommation du mois affichée. |
| Retouches manuelles écrasées | Une traduction retouchée à la main n'est jamais écrasée automatiquement. |

## Modèles recommandés

Les deux fournisseurs sont au choix ; n'importe quel modèle du compte peut être utilisé (liste chargée depuis l'API ou identifiant saisi à la main).

| Fournisseur | Modèle | Prix (entrée / sortie, par million de tokens) | Coût estimé pour 100 pages de ~800 mots, par langue |
|---|---|---|---|
| Claude | **Claude Opus 5.5** (recommandé) | 4 $ / 20 $ | ≈ 5,80 $ |
| Claude | Claude Sonnet 5.5 | 2 $ / 10 $ | ≈ 2,90 $ |
| Claude | Claude Haiku 5.5 | 0,10 $ / 0,50 $ | ≈ 0,15 $ |
| OpenAI | **GPT-6.1 Sol** (recommandé) | 2 $ / 10 $ | ≈ 2,90 $ |
| OpenAI | GPT-6.1 Terra | 1 $ / 5 $ | ≈ 1,45 $ |
| OpenAI | GPT-6 Luna | 0,10 $ / 0,50 $ | ≈ 0,15 $ |

Prix vérifiés le 31/10/2026 sur les pages officielles d'Anthropic et d'OpenAI. L'effort de réflexion est réglé sur « faible » par défaut : la traduction n'a pas besoin d'un long raisonnement, c'est plus rapide et moins cher.

## Fonctionnalités

### Langues et URL
- 57 langues prêtes à l'emploi (dont variantes : fr-CA, pt-BR, es-MX, de-CH…), langues RTL.
- Langue par défaut sans préfixe (vos URL actuelles ne changent pas), autres langues en sous-répertoire (`/en/`, `/es/`), préfixes modifiables.
- La langue vient uniquement de l'URL (pas de cookie, pas de redirection selon le navigateur) : chaque URL a une seule langue, pages 100 % cachables, Googlebot voit toutes les versions.
- Les textes de WordPress, du thème et des extensions passent dans la langue de la page (packs de langue téléchargés à l'ajout d'une langue).

### Traduction par IA
- Bouton « Traduire » dans l'éditeur, dans la liste des pages (+EN, +ES), dans la barre d'outils du site (pratique avec Elementor et Divi) et action groupée.
- Traduction en masse avec estimation du coût, barre de progression, reprise automatique en arrière-plan (WP-Cron) et WP-CLI.
- Contenu, titre, extrait, slug, catégories, étiquettes, champs SEO, textes alternatifs des images, titre du site, slogan, libellés de menus.
- Ton et vocabulaire : description du site, glossaire (termes à ne jamais traduire, traductions imposées par langue), vouvoiement / tutoiement.
- Mise à jour automatique (option) quand l'original change, sans écraser les retouches manuelles.
- Robuste : sortie JSON garantie par schéma, lots réduits automatiquement si une réponse est coupée, texte laissé dans la langue d'origine si l'IA casse un repère de balisage (jamais de HTML cassé).

### Constructeurs de pages
- **Gutenberg** : textes, attributs de blocs (recherche, boutons, navigation…), compositions synchronisées (le bloc pointe vers la composition traduite).
- **Elementor** : tous les widgets texte (titre, éditeur, bouton, onglets, accordéons, formulaires, carrousels…), liens, images ; CSS régénéré ; modèles Elementor (en-têtes, pieds de page, popups) remplacés par leur traduction.
- **Divi 4** (shortcodes) et **Divi 5** (blocs) : textes des modules, titres, boutons, liens. Le module « Code » n'est jamais traduit.
- Éditeur classique, HTML personnalisé, shortcodes de formulaires préservés.
- Ne traduit jamais : `<code>`, `<pre>`, `<script>`, `<style>`, `<svg>`, ni les éléments marqués `translate="no"` ou `class="notranslate"`.

### SEO
- `hreflang` sur chaque page avec toutes ses traductions + `x-default`, canonical juste, `<html lang="en-US">`.
- Slugs traduits (pages, articles, catégories, étiquettes), pages enfants rattachées au parent traduit.
- Sitemaps WordPress avec toutes les langues actives (le contenu d'une langue retirée n'y figure plus) ; Yoast SEO / Rank Math récupèrent les bonnes URL.
- Une URL `/en/` ne montre jamais un contenu non traduit (pas de contenu dupliqué) ; une URL erronée redirige vers la traduction de la langue demandée.

### GEO (moteurs de réponse IA)
- `llms.txt` par langue (`/llms.txt`, `/en/llms.txt`…), index Markdown des pages et articles.
- Données structurées : `inLanguage`, `workTranslation`, `translationOfWork` (ajoutées au graphe de Yoast SEO / Rank Math, ou graphe minimal sans extension SEO).
- Prompt de traduction orienté SEO/GEO : vocabulaire réellement recherché dans la langue cible, titres explicites, aucune information inventée.

### Menus et sélecteur de langue
- Menus classiques et blocs Navigation traduits automatiquement, sans dupliquer les menus ; éléments non traduits masqués (option).
- Sélecteur de langue : bloc, shortcode `[gaiml_switcher]` (Elementor, Divi), ajout automatique à un emplacement de menu, fonction `gaiml_language_switcher()`. Liens HTML simples (crawlables), sans JavaScript, sans drapeaux (un drapeau est un pays, pas une langue).

### Performance
- Une seule option chargée, aucun appel IA pendant la visite, aucun JavaScript côté visiteur, CSS du sélecteur (1 Ko) imprimé seulement s'il est affiché.
- Filtrage par langue en une jointure indexée ; langues des pages, termes et menus chargées par lots (≈ 10 requêtes indexées de plus par page, ~0 ms mesurables).

### Sécurité
- Nonces et vérification des droits sur chaque action ; un contributeur ne peut traduire que ce qu'il peut modifier et publier, et ne peut pas écraser une traduction qu'il n'a pas le droit de modifier.
- Clés API chiffrées en base (XSalsa20-Poly1305), jamais réaffichées ; encore mieux : constantes dans `wp-config.php`.
- La sortie de l'IA ne peut pas injecter de balisage ni de shortcode : seuls les repères attendus sont restaurés, tout le reste est échappé (crochets compris) ; les attributs HTML sont lus un par un, jamais réinterprétés.
- Requêtes SQL préparées, appels API uniquement vers `api.anthropic.com` et `api.openai.com`, requêtes OpenAI non stockées (`store: false`).

## Installation

1. Téléverser `dist/gustinai-multilingual-1.0.0.zip` dans **Extensions > Ajouter > Téléverser**, puis activer.
2. **Multilingue > Réglages > Langues** : ajouter les langues (la langue du site devient la langue par défaut).
3. **Traduction IA** : coller une clé Claude ou OpenAI, garder le modèle recommandé, décrire le site, compléter le glossaire, cliquer « Tester avec une phrase d'exemple ».
4. **Traduction en masse** : estimer le coût, lancer.
5. **SEO, GEO et affichage** : ajouter le sélecteur de langue à un menu (ou placer le bloc / le shortcode).

Clés dans `wp-config.php` (recommandé) :

```php
define( 'GAIML_ANTHROPIC_API_KEY', 'sk-ant-...' );
define( 'GAIML_OPENAI_API_KEY', 'sk-...' );
```

## WP-CLI

```bash
wp gaiml translate 12 34 --lang=en,es   # traduit des contenus et attend le résultat
wp gaiml translate 7 --type=term         # traduit une catégorie
wp gaiml bulk --lang=en                  # tout ce qui manque, pages d’abord (--queue-only : laisser WP-Cron traiter)
wp gaiml queue status|run|retry|clear
wp gaiml status                          # langues, contenus, consommation du mois
```

## Pour les développeurs

- Fonctions : `gaiml_current_language()`, `gaiml_default_language()`, `gaiml_languages()`, `gaiml_get_translation( $id, $lang, 'post'|'term' )`, `gaiml_post_language( $id )`, `gaiml_home_url( $lang )`, `gaiml_language_switcher( $args )`.
- REST : champs `gaiml_lang` et `gaiml_translations`, paramètre `?lang=en` sur toutes les routes.
- `WP_Query` : `'gaiml_lang' => 'en'` ou `'all'` ; `get_posts()` n'est filtré qu'avec `'suppress_filters' => false` (comme WPML).
- Filtres : `gaiml_post_types`, `gaiml_taxonomies`, `gaiml_translatable_meta` (champs personnalisés à traduire), `gaiml_meta_skip`, `gaiml_strings`, `gaiml_system_prompt`, `gaiml_known_models`, `gaiml_provider`, `gaiml_hreflang`, `gaiml_alternates`, `gaiml_llms_txt`, `gaiml_switcher_css`, `gaiml_exclude_post`.
- Actions : `gaiml_post_translated`, `gaiml_translations_changed`, `gaiml_loaded`.

Détails internes : [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Limites connues (v1)

- Sous-répertoires uniquement (pas de sous-domaines ni de domaines par langue).
- WooCommerce : ne pas cocher « Produits » (les stocks et prix ne sont pas synchronisés entre langues).
- Thèmes blocs : les parties de modèle personnalisées (en-tête, pied de page) ne sont pas traduites, hormis la navigation, le titre et le slogan ; Divi Theme Builder non pris en charge.
- Champs ACF : à déclarer via le filtre `gaiml_translatable_meta`.
- Divi 5 : prise en charge basée sur le format de blocs public (`innerContent`), à valider sur un site réel.

## Tests

```bash
php tests/unit.php                               # 52 tests du découpage (Gutenberg, Divi 4/5, Elementor, injections)
wp eval-file tests/e2e/providers.php             # 28 tests des connecteurs Claude / OpenAI (réponses simulées)
cp tests/e2e/mock-provider.php wp-content/mu-plugins/ && wp eval-file tests/e2e/setup.php && wp gaiml bulk
wp eval-file tests/e2e/engine.php                # robustesse (réponses coupées, balisage cassé)
wp eval-file tests/e2e/review.php                # 24 tests de non-régression (corbeille, concurrence, slugs, sitemaps, verrous)
```

Testé sur WordPress 7.1.4 + MariaDB 10.11 + PHP 8.3, Elementor 4.3.5, thèmes Twenty Twenty-Five (blocs) et Twenty Twenty-One (classique).

## Construire l'archive

```bash
bin/build.sh   # crée dist/gustinai-multilingual-1.0.0.zip
```
