# Movie Showcase

Movie catalogue and showcase for WordPress.

**Author:** Diones Menqui Ramos — [LinkedIn](https://www.linkedin.com/in/diones-ramos-6a0616128/)

## Requirements

- PHP 8.1+
- WordPress 6.0+
- Composer

## Development setup

The plugin runs inside the Docker environment of the parent repository:

```bash
composer install
wp plugin activate wp-movie-showcase
```

Admin: http://localhost:8747/wp-admin

## Layout

```
wp-movie-showcase.php   bootstrap: requirements, autoload, i18n, lifecycle hooks
uninstall.php           option cleanup on delete
composer.json           PSR-4: DionesRamos\MovieShowcase\ -> app/
app/
  Contracts/            service interfaces
  Controllers/          admin screens, routes, hooks
  Models/               custom post types, taxonomies, meta
  Services/             business logic and integrations
  Views/                templates and rendering
assets/css, assets/js   static files
languages/              .pot/.po/.mo files
```

## License

GPL-2.0-or-later
