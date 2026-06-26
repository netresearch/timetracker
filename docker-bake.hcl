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

variable "PHP_BASE_IMAGE" {
  default = "php:8.5-fpm"
}

variable "NODE_VERSION" {
  default = "24"
}

variable "COMPOSER_IMAGE" {
  default = "composer:2.10"
}

variable "XDEBUG_VERSION" {
  default = "3.5.3"
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
    PHP_BASE_IMAGE = PHP_BASE_IMAGE
    NODE_VERSION   = NODE_VERSION
    COMPOSER_IMAGE = COMPOSER_IMAGE
    XDEBUG_VERSION = XDEBUG_VERSION
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
