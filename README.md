# Rezos

Plugin WordPress qui affiche les publications d'une **Page Facebook** et du **compte Instagram professionnel** qui lui est lié, via l'API Graph de Meta.
Stack : Composer, Symfony (HttpClient, OptionsResolver), Twig 3. PHP 8.1+, WordPress 6.0+.

## Installation

```bash
cd wp-content/plugins/rezos
composer install --no-dev --optimize-autoloader
```

Activez ensuite **Rezos** dans *Extensions*.

## Configuration (Réglages › Rezos)

1. **App Meta** : sur developers.facebook.com, créez une app de type *Business*, ajoutez le produit « Instagram » (API with Facebook Login). Copiez l'App ID et l'App Secret dans le plugin.
2. **Token** : dans l'Explorateur de l'API Graph, choisissez l'app, ajoutez `pages_show_list`, `pages_read_engagement`, `instagram_basic`, `business_management`, générez un *User Token* et collez-le dans le plugin.
   Le plugin l'échange contre un token long, puis récupère le **token de Page** (sans expiration) et l'ID Instagram lié.
3. Cliquez sur **Tester la connexion**.

> Tant que l'app est en mode *Développement*, seuls les comptes ayant un rôle sur l'app fonctionnent — ce qui suffit pour afficher vos propres publications.

## Utilisation

Shortcode :

```
[rezos]
[rezos source="instagram" limit="6" columns="3"]
[rezos source="facebook" layout="list" show_stats="true" excerpt="0"]
```

| Attribut | Valeurs | Défaut |
|---|---|---|
| `source` | `all`, `facebook`, `instagram`, `facebook,instagram` | `all` |
| `limit` | 1–50 | réglage (9) |
| `layout` | `grid`, `list` | `grid` |
| `columns` | 1–6 | 3 |
| `type` | `image`, `video`, `album`, `text` | tous |
| `show_text`, `show_date`, `show_stats` | `true` / `false` | true / true / false |
| `excerpt` | nb de caractères, 0 = complet | 140 |
| `template` | nom d'un template Twig | `feed.html.twig` |

Widget : *Apparence › Widgets › Rezos*.

## Personnaliser l'affichage

Les templates Twig du thème priment sur ceux du plugin. Copiez `templates/feed.html.twig` et/ou `templates/_post.html.twig` dans `wp-content/themes/<votre-thème>/rezos/` et modifiez-les.

Fonctions/filtres Twig disponibles : `__()`, `_n()`, `|wp_date`, `|time_ago`, `|esc_url`, `|rich_text`.

Hooks :
- `rezos/twig_paths` (filter) — chemins de recherche des templates ;
- `rezos/html` (filter) — HTML final `($html, $posts, $options)`.

## Cache et résilience

- Chaque source est mise en cache (transients) pendant la durée réglée (1 h par défaut).
- Si l'API renvoie une erreur, le dernier flux valide est servi ; l'erreur n'est visible que des administrateurs.
- Les URLs d'images Meta expirent au bout de quelques jours : gardez une durée de cache courte.

## Structure

```
rezos.php                 bootstrap WordPress
src/Plugin.php            construction des services + hooks
src/Settings.php          options
src/Api/                  GraphClient, FacebookProvider, InstagramProvider
src/Feed/FeedService.php  agrégation, tri, cache
src/Cache/FeedCache.php   transients + copie de secours
src/Front/                Shortcode, Widget, FeedRenderer (OptionsResolver)
src/Admin/SettingsPage.php
src/Twig/                 TwigFactory, WordPressExtension
scoper.inc.php            config PHP-Scoper
bin/build.sh              build préfixé + zip
bin/smoke-test.php        test du build
templates/                feed, _post, admin/*
assets/rezos.css
```

## Build distribuable (dépendances préfixées)

Installé avec `composer install`, le plugin charge Symfony et Twig dans l'espace de noms global : un autre plugin qui embarque d'autres versions peut entrer en conflit. Pour distribuer le plugin, construisez une version où ces dépendances sont préfixées avec [PHP-Scoper](https://github.com/humbug/php-scoper) (`Twig\Environment` → `RezosVendor\Twig\Environment`) :

```bash
bin/build.sh            # → build/rezos/ et build/rezos-<version>.zip
bin/build.sh --no-zip   # → build/rezos/ seulement
```

Le script télécharge PHP-Scoper dans `tools/`, installe les dépendances de production, préfixe le code (`scoper.inc.php`), régénère l'autoloader puis lance `bin/smoke-test.php`. Ce test charge le build avec un WordPress simulé, à côté d'une copie non préfixée de Twig, et vérifie le rendu du shortcode et les appels à l'API.

Points d'attention dans `scoper.inc.php` :
- l'espace de noms `Rezos\` et les symboles WordPress (`WP_*`, `wp_*`, `ABSPATH`…) ne sont pas préfixés ;
- les polyfills Symfony et `trigger_deprecation()` restent globaux (fonctions protégées par `function_exists()`) ;
- un *patcher* préfixe les noms de classes que Twig écrit dans le code des templates compilés. Sans lui, le rendu échoue.

Après une mise à jour de Twig ou de Symfony, relancez `bin/build.sh` : le test de fumée signale toute régression.
