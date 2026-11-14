# JMDev Headless WordPress Backend

Custom WordPress backend for the JMDev Vue/SSG portfolio. WordPress manages
content; the frontend exports REST data and builds static pages separately.
The frontend is maintained in [JMDev](https://github.com/jmonka9012/JMDev).

The repository contains the Portfolio MU-plugin, tests, development configuration
and local maintenance tools. WordPress core, external plugins, uploads, database
exports, credentials and private migration reports are excluded.

## Requirements and scope

- WordPress and Advanced Custom Fields are required.
- `.ddev/config.yaml` describes the existing local development environment.
- ACF field definitions and the Technology post type are registered in PHP.
- This repository does not include the private content database. A clean-install
  bootstrap and fictional demo content are separate preparation tasks.

## Local checks

From this repository root:

```sh
php tools/run-tests.php
php tools/tests/import-post.php
```

For a read-only summary of the running local WordPress installation:

```sh
ddev exec wp --path=/var/www/html/wordpress eval-file /var/www/html/tools/inspect.php
```

The retained private-dataset importer is optional and is documented separately.
It is not required for normal editing and cannot bootstrap a public clone.

## Documentation

- [Portfolio MU-plugin](wordpress/wp-content/mu-plugins/portfolio-projects/README.md)
- [Local tools](tools/README.md)

The backend and frontend are two components of the same application. All changes
remain local until an explicit publication step.
