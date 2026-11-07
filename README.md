<h1 align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="assets/logo/rezos-logo-dark.svg">
    <img src="assets/logo/rezos-logo.svg" alt="Rézos" width="320">
  </picture>
</h1>

Plugin WordPress qui affiche les publications d'une ou plusieurs **Pages Facebook** et des **comptes Instagram professionnels** qui leur sont liés, via l'API Graph de Meta.
Stack : Composer, Symfony (HttpClient, OptionsResolver), Twig 3. PHP 8.1+, WordPress 6.0+.

## Installation

### Depuis une release (recommandé)

1. Téléchargez `rezos-<version>.zip` depuis la [dernière release](https://github.com/johnburnout/Rezos/releases/latest).
2. Dans WordPress : *Extensions › Ajouter › Téléverser une extension*, choisissez le zip, puis **Activer**.

Le zip contient déjà les dépendances, préfixées pour éviter les conflits avec d'autres plugins (voir [Build distribuable](#build-distribuable-dépendances-préfixées)) : Composer n'est pas nécessaire.

### Mises à jour

Depuis la 1.2.0, Rezos se met à jour comme les autres extensions : dès qu'une release est publiée sur GitHub, elle apparaît dans *Tableau de bord › Mises à jour* et dans *Extensions* (bouton **Mettre à jour**, lien vers les notes de version, mises à jour automatiques activables). WordPress vérifie deux fois par jour ; Rezos garde la réponse de GitHub 6 h en cache, « Rechercher à nouveau » dans *Mises à jour* force la vérification.

Seules les releases publiées qui contiennent le zip `rezos-<version>.zip` sont proposées (pas les brouillons ni les pré-versions). Les réglages sont conservés et migrés si besoin.

Pour passer d'une version antérieure à la 1.2.0, téléversez une fois le zip à la main : WordPress propose de remplacer l'extension existante.

### Depuis les sources (développement)

```bash
cd wp-content/plugins
git clone https://github.com/johnburnout/Rezos.git rezos
cd rezos
composer install --no-dev --optimize-autoloader
```

Activez ensuite **Rezos** dans *Extensions*. Dans ce mode, Symfony et Twig ne sont pas préfixés.

## Configuration (Réglages › Rezos)

1. **App Meta** : sur developers.facebook.com, créez une app de type *Business*, ajoutez le produit « Instagram » (API with Facebook Login). Copiez l'App ID et l'App Secret dans le plugin.
2. **Token** : dans l'Explorateur de l'API Graph, choisissez l'app, ajoutez `pages_show_list`, `pages_read_engagement`, `instagram_basic`, `business_management`, générez un *User Token* et collez-le dans le plugin.
   Le plugin l'échange contre un token long, puis liste **toutes les Pages accessibles** (avec leur compte Instagram lié). S'il y en a plusieurs, cochez celles à afficher. Chaque Page reçoit son propre **token de Page** (sans expiration).
3. Cliquez sur **Tester la connexion**.

Pour ajouter des Pages plus tard (y compris d'un autre compte Facebook), collez un nouveau token : les Pages proposées et décochées sont retirées, les autres Pages déjà connectées sont conservées. Chaque Page peut aussi être retirée individuellement.

> Tant que l'app est en mode *Développement*, seuls les comptes ayant un rôle sur l'app fonctionnent — ce qui suffit pour afficher vos propres publications.

## Utilisation

Shortcode :

```
[rezos]
[rezos source="instagram" columns="3" rows="2"]
[rezos featured="true" columns="4" rows="3"]
[rezos source="facebook" layout="list" show_stats="true" excerpt="0"]
[rezos page="123456789"]
[rezos page="@moncompte,123456789" source="instagram"]
```

Sans attribut `page`, le flux mélange toutes les Pages connectées, triées par date.

Les **rediffusions** sont masquées, quelle que soit la source affichée : une publication Facebook qui reprend une publication Instagram de la même Page (ou l'inverse) n'apparaît qu'une fois — la plus ancienne est gardée. Sont rapprochées deux publications au même texte (aux emojis, ponctuation et sauts de ligne près) publiées à moins de 24 h d'écart, ou dont l'une n'a pas de texte, à moins de 15 min d'écart.

Les **republications** sur un même réseau (même Page, même texte, à moins de 24 h d'écart) sont aussi masquées : seule la plus récente, souvent publiée pour corriger la première, est affichée.

| Attribut | Valeurs | Défaut |
|---|---|---|
| `page` | `all`, ou IDs de Pages et/ou comptes Instagram (`@nom`), séparés par des virgules | `all` |
| `source` | `all`, `facebook`, `instagram`, `facebook,instagram` | `all` |
| `columns` | publications par ligne, 1–6 | réglage (3) |
| `rows` | nombre de lignes, 1–10 | réglage (3) |
| `featured` | `true` / `false` : première ligne avec une seule publication, en pleine largeur | réglage (`false`) |
| `layout` | `grid`, `list` (une publication par ligne) | `grid` |
| `limit` | nombre total, 1–50 — remplace `columns` × `rows` | — |
| `type` | `image`, `video`, `album`, `text` | tous |
| `show_text`, `show_date`, `show_stats` | `true` / `false` | true / true / false |
| `show_account` | `true` / `false` / `auto` (nom de la Page ou `@compte`) | `auto` : affiché si plusieurs Pages |
| `excerpt` | nb de caractères, 0 = complet | 140 |
| `template` | nom d'un template Twig | `feed.html.twig` |

Le nombre de publications affichées découle de la grille : `columns` × `rows`, ou `1 + columns × (rows − 1)` avec `featured="true"`.

**Aperçu** : la section *Aperçu* de *Réglages › Rezos* affiche le rendu de n'importe quel shortcode `[rezos …]` (un lien « Aperçu » est aussi proposé pour chaque Page connectée).

Widget : *Apparence › Widgets › Rezos* (publications par ligne, lignes, première publication en pleine largeur, choix de la Page lorsque plusieurs sont connectées).

## Personnaliser l'affichage

Les templates Twig du thème priment sur ceux du plugin. Copiez `templates/feed.html.twig` et/ou `templates/_post.html.twig` dans `wp-content/themes/<votre-thème>/rezos/` et modifiez-les.

Fonctions/filtres Twig disponibles : `__()`, `_n()`, `|wp_date`, `|time_ago`, `|esc_url`, `|rich_text`.

Hooks :
- `rezos/twig_paths` (filter) — chemins de recherche des templates ;
- `rezos/html` (filter) — HTML final `($html, $posts, $options)`.

## Cache et résilience

- Chaque source de chaque Page est mise en cache (transients) pendant la durée réglée (1 h par défaut) : avec N Pages, au plus 2 × N appels à l'API par heure.
- Si l'API renvoie une erreur, le dernier flux valide est servi ; l'erreur n'est visible que des administrateurs.
- Les URLs d'images Meta expirent au bout de quelques jours : gardez une durée de cache courte.

## Structure

```
rezos.php                 bootstrap WordPress
src/Plugin.php            construction des services + hooks
src/Settings.php          options
src/Api/                  GraphClient, FacebookProvider, InstagramProvider
src/Feed/FeedService.php  agrégation (sources × Pages), tri, cache
src/Model/                Post, Account (Page connectée)
src/Cache/FeedCache.php   transients + copie de secours
src/Front/                Shortcode, Widget, FeedRenderer (OptionsResolver)
src/Admin/SettingsPage.php
src/Admin/Updater.php     mises à jour depuis les releases GitHub (Update URI)
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
