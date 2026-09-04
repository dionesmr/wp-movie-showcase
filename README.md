# Movie Showcase

Movie catalogue and showcase for WordPress.

**Author:** Diones Menqui Ramos — [LinkedIn](https://www.linkedin.com/in/diones-ramos-6a0616128/)

## Requirements

- PHP 8.1+
- WordPress 6.0+
- Composer

## Layout

```
wp-movie-showcase.php   bootstrap: requirements, autoload, i18n, lifecycle hooks
uninstall.php           option cleanup on delete
composer.json           PSR-4: DionesRamos\MovieShowcase\ -> app/
app/
  Plugin.php            composition root
  Controllers/          admin screen, REST routes, block registration
  Services/             OMDb client and API key storage
  Views/                markup and escaping
blocks/movie-search/    block.json, editor script, view script, styles
languages/              .pot/.po/.mo files
```