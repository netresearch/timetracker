# Netresearch TimeTracker - Docker Bake Configuration
#
# Single source of truth for all build configuration.
# Similar to package.json for JavaScript - versions and build config here.
# compose.yml is for runtime only (volumes, environment, ports).
#
# Usage:
#   docker bake              # Build production image
#   docker bake app-dev      # Build development image
#   docker bake app-tools    # Build tools image
#   docker bake all          # Build all images
#   docker bake --print      # Show build configuration

# =============================================================================
# DEPENDENCY VERSIONS (single source of truth)
# =============================================================================
#
# Every image is pinned by digest, so the same source rebuilds to the same
# image. NOTE: no bot updates this file - Dependabot's docker ecosystem reads
# Dockerfiles and its docker-compose ecosystem reads compose.yml, and neither
# parses HCL (Renovate, which has a customManager for it, is not installed on
# this repository). All five digests are therefore bumped by hand. The base
# stage runs `apt-get upgrade -y`, so OS-level CVEs are still picked up at
# build time while a digest sits still.

variable "PHP_BASE_IMAGE" {
  default = "php:8.5-fpm@sha256:14e08327efdcb7bb53f249b7f7c9ab50791f9ae77bae3585303d48a5d5a7409a"
}

# Node is copied out of the official image rather than installed from
# NodeSource. Keep the Debian release in step with PHP_BASE_IMAGE's (trixie).
variable "NODE_BASE_IMAGE" {
  default = "node:26-trixie-slim@sha256:65f816afd401c1c4de3293acc46dce115398152af4bdcd73c103b096988922d7"
}

variable "SYMFONY_CLI_IMAGE" {
  default = "ghcr.io/symfony-cli/symfony-cli:5.20.0@sha256:7b3e16d91ded7702769104f82c42643dbd82ebfa48cba824893ac2a71da9ae4f"
}

variable "BUN_IMAGE" {
  default = "oven/bun:1.3.14@sha256:e10577f0db68676a7024391c6e5cb4b879ebd17188ab750cf10024a6d700e5c4"
}

variable "COMPOSER_IMAGE" {
  default = "composer:2.10@sha256:a5f59b9fd2faf31218632be4809dc6491761085e8064c31dc3b84378c48c248b"
}

variable "XDEBUG_VERSION" {
  default = "3.5.3"
}

variable "APCU_VERSION" {
  default = "5.1.28"
}


# =============================================================================
# IMAGE METADATA
# =============================================================================

variable "REGISTRY" {
  default = "ghcr.io/netresearch"
}

variable "IMAGE_NAME" {
  default = "timetracker"
}

variable "TAG" {
  default = "latest"
}

# Git commit SHA, passed by CI to produce an immutable e2e-<sha> tag.
# Empty by default (local builds get only the floating :e2e tag).
variable "GIT_SHA" {
  default = ""
}

# Git ref (branch or tag) the production image was built from, surfaced on
# /ui/admin/status. Passed by CI; empty on a plain local build.
variable "GIT_REF" {
  default = ""
}

# Build timestamp (ISO 8601), surfaced on /ui/admin/status. Passed by CI.
variable "BUILD_DATE" {
  default = ""
}

# =============================================================================
# COMMON BUILD SETTINGS (inherited by all targets)
# =============================================================================

target "_common" {
  context    = "."
  dockerfile = "Dockerfile"
  args = {
    PHP_BASE_IMAGE    = PHP_BASE_IMAGE
    NODE_BASE_IMAGE   = NODE_BASE_IMAGE
    SYMFONY_CLI_IMAGE = SYMFONY_CLI_IMAGE
    BUN_IMAGE         = BUN_IMAGE
    COMPOSER_IMAGE    = COMPOSER_IMAGE
    XDEBUG_VERSION    = XDEBUG_VERSION
    APCU_VERSION      = APCU_VERSION
  }
}

# =============================================================================
# BUILD TARGETS
# =============================================================================

# Tag/label provider for the "app" target.
#
# In CI this stub is REPLACED by the bake file that docker/metadata-action
# generates (the workflow passes it via `files:`), so pushes get the full
# metadata tag set (semver from git tags, branch name, sha, production,
# latest). For local `docker buildx bake` runs the stub supplies the
# defaults that compose.yml expects.
target "docker-metadata-action" {
  tags = [
    "${REGISTRY}/${IMAGE_NAME}:${TAG}",
    "${REGISTRY}/${IMAGE_NAME}:production",
  ]
}

# Production image
target "app" {
  inherits = ["_common", "docker-metadata-action"]
  target   = "production"
  args = {
    APP_BUILD_REVISION = GIT_SHA
    APP_BUILD_REF      = GIT_REF
    APP_BUILD_DATE     = BUILD_DATE
  }
  labels = {
    "org.opencontainers.image.title"       = "Netresearch TimeTracker"
    "org.opencontainers.image.description" = "Time tracking application"
    "org.opencontainers.image.vendor"      = "Netresearch DTT GmbH"
    "org.opencontainers.image.source"      = "https://github.com/netresearch/timetracker"
    "org.opencontainers.image.licenses"    = "AGPL-3.0"
  }
}

# Development image (local development with Xdebug, dev tools)
target "app-dev" {
  inherits = ["_common"]
  target   = "dev"
  tags = [
    "${REGISTRY}/${IMAGE_NAME}:dev",
  ]
}

# Tools image (local development, lightweight static analysis)
target "app-tools" {
  inherits = ["_common"]
  target   = "tools"
  tags = [
    "${REGISTRY}/${IMAGE_NAME}:tools",
  ]
}

# E2E/CI image (used for all CI jobs: lint, test, e2e)
# Includes Xdebug (also the coverage driver), Playwright with pre-installed browsers
target "app-e2e" {
  inherits = ["_common"]
  target   = "e2e"
  tags = compact([
    "${REGISTRY}/${IMAGE_NAME}:e2e",
    GIT_SHA != "" ? "${REGISTRY}/${IMAGE_NAME}:e2e-${GIT_SHA}" : "",
  ])
}

# Profiling image (prod-like + Symfony profiler, admin-gated). Built by CI,
# never the default deployment — operators switch to :profiling on demand.
target "app-profiling" {
  inherits = ["_common"]
  target   = "profiling"
  # Bake the build provenance into the profiling image too (like the `app` target),
  # so /ui/admin/status shows the deployed commit/ref/date — the prod hot-deploy runs
  # :profiling-<sha>, not the release image, so without this the page reads "unknown".
  args = {
    APP_BUILD_REVISION = GIT_SHA
    APP_BUILD_REF      = GIT_REF
    APP_BUILD_DATE     = BUILD_DATE
  }
  tags = compact([
    "${REGISTRY}/${IMAGE_NAME}:profiling",
    GIT_SHA != "" ? "${REGISTRY}/${IMAGE_NAME}:profiling-${GIT_SHA}" : "",
  ])
}

# =============================================================================
# BUILD GROUPS
# =============================================================================

# Default: build production image
group "default" {
  targets = ["app"]
}

# All application images (for local development)
group "all" {
  targets = ["app", "app-dev", "app-tools", "app-e2e", "app-profiling"]
}

# CI images (production + e2e for all CI jobs)
group "ci" {
  targets = ["app", "app-e2e"]
}
