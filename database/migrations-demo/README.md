# Demo schema (squashed, SQLite-safe)

`0001_01_01_000000_demo_schema.php` is a Blueprint-only recreation of the
live SiteWorks schema, generated for this standalone demo so it can boot
on SQLite.

The demo container runs:

```
php artisan migrate --force --path=database/migrations-demo
```

Do not hand-edit the squashed migration. To regenerate it, from the
**private** SiteWorks platform checkout:

```
php artisan schema:squash
```

That command is not part of this public repo. Copy the resulting file
over `database/migrations-demo/0001_01_01_000000_demo_schema.php`.
