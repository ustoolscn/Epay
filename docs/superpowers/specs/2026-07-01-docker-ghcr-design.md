# Docker + GHCR Deployment Design

## Goal

Provide a repository-native deployment path for this PHP payment project using Docker and GitHub Actions:

- Build and publish the application image to GitHub Container Registry (GHCR)
- Deploy the application with `docker-compose`
- Keep MySQL external to the compose stack
- Preserve compatibility with the project's existing file-based database configuration

## Scope

This design covers:

- Application container image based on PHP and Apache
- Compose-based deployment for a single application service
- Environment-variable-driven database configuration
- GitHub Actions workflow for image build and push
- Deployment documentation for local and server usage

This design does not cover:

- Bundling MySQL into the compose stack
- TLS termination or CDN configuration
- Full production observability or autoscaling

## Current Project Constraints

- The project is a PHP application with document root at the repository root
- Existing rewrite rules are defined for Nginx and IIS
- Database access uses PDO MySQL
- Existing configuration is stored in `config.php`
- Sensitive directories such as `plugins` and `includes` should not be directly exposed

## Proposed Architecture

### Runtime

Use a single application container based on `php:apache`.

Reasoning:

- Matches the project's file layout and rewrite needs
- Avoids the extra moving parts of an Nginx plus PHP-FPM split
- Keeps deployment simple for a first containerized release

### Database

MySQL remains external to Docker Compose.

The application container receives database connection settings through environment variables:

- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASSWORD`
- `DB_PREFIX`

### Configuration Strategy

`config.php` will be updated to support an environment-variable-first strategy:

1. If environment variables are present, use them
2. Otherwise fall back to the existing hard-coded file configuration format

This preserves backward compatibility for non-Docker deployments while allowing clean container-based deployment.

## Container Design

### Dockerfile

The image will:

- Use a PHP Apache base image
- Enable Apache `mod_rewrite`
- Install required PHP extensions for this project, specifically `pdo_mysql`
- Copy the project into Apache web root
- Add an Apache site configuration that implements existing rewrite behavior

### Apache Rules

The Apache virtual host will reproduce current routing behavior:

- `/<name>.html` -> `index.php?mod=<name>`
- `/pay/<path>` -> `pay.php?s=<path>`
- `/api/<path>` -> `api.php?s=<path>`
- `/doc/<name>.html` -> `index.php?doc=<name>`

The config will also deny direct web access to:

- `/plugins`
- `/includes`

## Compose Design

### docker-compose.yml

Compose will define a single `app` service that:

- Pulls the image from GHCR
- Exposes a configurable HTTP port
- Injects database settings through environment variables
- Mounts optional persistent paths only where operationally useful

### Environment File

Provide `.env.example` documenting required variables:

- `APP_PORT`
- `IMAGE_NAME`
- `IMAGE_TAG`
- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASSWORD`
- `DB_PREFIX`

### Deployment Pattern

Server deployment flow:

1. Copy `docker-compose.yml` and `.env`
2. Set `IMAGE_NAME` to the GHCR repository image
3. Set `IMAGE_TAG` to either `latest` or a timestamp tag
4. Fill external MySQL connection settings
5. Run `docker compose pull`
6. Run `docker compose up -d`

## GitHub Actions Design

### Workflow Trigger

Build on:

- Push to the default working branches
- Git tags
- Manual dispatch

### Publish Target

Push to:

- `ghcr.io/<owner>/<repo>`

### Authentication

Use the repository's built-in `GITHUB_TOKEN` with package write permission for GHCR publishing.

### Tagging Strategy

Generated image tags will include:

- `latest`
- Build timestamp in `YYYYMMDD-HHmm` format
- Git tag name when the workflow is triggered by a Git tag

The timestamp should be generated in a deterministic workflow step and reused by metadata generation so all published tags for a run are consistent.

## Documentation Changes

`README.md` will be updated with:

- Overview of the Docker deployment flow
- GHCR publishing notes
- Required GitHub repository settings and permissions
- Server-side compose deployment instructions
- External MySQL configuration guidance
- Database initialization guidance using `install/install.sql`

## Testing and Verification

Implementation verification should include:

1. Validate Apache config syntax inside the container build
2. Build the Docker image locally
3. Confirm the container starts with environment-based DB settings
4. Confirm rewrite routes resolve to the intended PHP entry points
5. Confirm restricted directories are not directly accessible
6. Validate GitHub Actions workflow syntax as far as local static checks allow

## Risks and Mitigations

### Risk: Unknown PHP Extension Requirements

Some runtime paths may depend on extensions beyond `pdo_mysql`.

Mitigation:

- Start with the minimum known required extension set
- Build locally and inspect runtime errors
- Add extensions only when evidence shows they are needed

### Risk: Existing Install Flow Assumes Writable In-Place Config

The legacy installer may still attempt to write `config.php`.

Mitigation:

- Preserve the existing file shape
- Keep fallback config support intact
- Document recommended Docker-first configuration path clearly

### Risk: Timestamp Tags Alone Are Not Human-Meaningful Releases

Timestamp tags are operationally useful but not semantic release markers.

Mitigation:

- Keep Git tag passthrough support in the workflow
- Keep `latest` for straightforward deployments

## Implementation Steps

1. Add Docker runtime files (`Dockerfile`, Apache config, `.dockerignore`)
2. Update `config.php` for environment-variable-first loading
3. Add `docker-compose.yml` and `.env.example`
4. Add GHCR build and push workflow
5. Update `README.md`
6. Build and verify locally
