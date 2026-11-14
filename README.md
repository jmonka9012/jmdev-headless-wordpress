# JMDev Headless WordPress Backend

Custom WordPress backend for the JMDev Vue/SSG portfolio. WordPress manages
content; the frontend exports REST data and builds static pages separately.

The repository contains the Portfolio MU-plugin, tests, development configuration
and local maintenance tools. WordPress core, external plugins, uploads, database
exports, credentials and private migration reports are excluded.

## Requirements and scope

- WordPress and Advanced Custom Fields are required.
- `.ddev/config.yaml` describes the existing local development environment.
- Field definitions are registered in PHP; the Technology post-type definition
  is the remaining database-managed configuration at this checkpoint.
- This repository does not include the private content database. A clean-install
  bootstrap and fictional demo content are separate preparation tasks.

## Documentation

- [Portfolio MU-plugin](wordpress/wp-content/mu-plugins/portfolio-projects/README.md)
- [Local tools](tools/README.md)

The backend and frontend are two components of the same application. All changes
remain local until an explicit publication step.
