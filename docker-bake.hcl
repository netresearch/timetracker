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
  default = "22"
}

variable "COMPOSER_IMAGE" {
  default = "composer:2.8"
}

variable "XDEBUG_VERSION" {
  default = "3.5.0"
}

variable "PCOV_VERSION" {
  default = "1.0.12"
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
    PCOV_VERSION   = PCOV_VERSION
  }
}

# =============================================================================
# BUILD TARGETS
# =============================================================================

# Production image
target "app" {
  inherits = ["_common"]
  target   = "production"
  tags = [
    "${REGISTRY}/${IMAGE_NAME}:${TAG}",
    "${REGISTRY}/${IMAGE_NAME}:production",
  ]
  labels = {
    "org.opencontainers.image.title"       = "Netresearch TimeTracker"
    "org.opencontainers.image.description" = "Time tracking application"
    "org.opencontainers.image.vendor"      = "Netresearch DTT GmbH"
    "org.opencontainers.image.source"      = "https://github.com/netresearch/timetracker"
    "org.opencontainers.image.licenses"    = "AGPL-3.0"
  }
}

# Development image (with Xdebug, PCOV, dev tools)
target "app-dev" {
  inherits = ["_common"]
  target   = "dev"
  tags = [
    "${REGISTRY}/${IMAGE_NAME}:dev",
  ]
}

# Tools image (lightweight, for CI/static analysis)
target "app-tools" {
  inherits = ["_common"]
  target   = "tools"
  tags = [
    "${REGISTRY}/${IMAGE_NAME}:tools",
  ]
}

# E2E test image (with Playwright and browsers pre-installed)
target "app-e2e" {
  inherits = ["_common"]
  target   = "e2e"
  tags = [
    "${REGISTRY}/${IMAGE_NAME}:e2e",
  ]
}

# =============================================================================
# BUILD GROUPS
# =============================================================================

# Default: build production image
group "default" {
  targets = ["app"]
}

# All application images
group "all" {
  targets = ["app", "app-dev", "app-tools"]
}

# CI images (production + tools for testing)
group "ci" {
  targets = ["app", "app-tools"]
}
