#!/bin/bash
#
# YayLoyalty Dev & Release Script
# Usage: ./scripts/run.sh [command]
#
# Commands:
#   dev                - Update admin shell + start Vite dev server (HMR on :3030)
#   build              - Build admin dashboard for production
#   release [version]  - Create release ZIP package (prompts for version)
#   bump <version>     - Bump version number in plugin files
#   info               - Show current version info
#   clean              - Remove build artifacts
#   help               - Show this help message
#

set -e

# ─── Paths ──────────────────────────────────────────────────────────────

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
ADMIN_DIR="$PLUGIN_DIR/apps/admin"
RELEASE_DIR="$PLUGIN_DIR/release"
PLUGIN_SLUG="yayloyalty"
MAIN_FILE="$PLUGIN_DIR/$PLUGIN_SLUG.php"

if [ -z "$PLUGIN_DIR" ] || [ "$PLUGIN_DIR" = "/" ] || [ ! -f "$MAIN_FILE" ]; then
    echo "ERROR: Invalid plugin directory: '$PLUGIN_DIR'" >&2
    exit 1
fi

# ─── Output helpers ─────────────────────────────────────────────────────

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

print_msg()     { echo -e "${1}${2}${NC}"; }
print_step()    { print_msg "$BLUE" "→ $1"; }
print_success() { print_msg "$GREEN" "✓ $1"; }
print_error()   { print_msg "$RED" "✗ $1"; }
print_warning() { print_msg "$YELLOW" "⚠ $1"; }
print_info()    { print_msg "$CYAN" "ℹ $1"; }

# Portable in-place sed edit (macOS/Linux compatible)
sed_inplace() {
    local pattern=$1
    local file=$2
    if sed --version 2>&1 | grep -q GNU; then
        sed -i "$pattern" "$file"
    else
        sed -i '' "$pattern" "$file"
    fi
}

# ─── Toolchain helpers ──────────────────────────────────────────────────

# Resolve a working pnpm invocation into $PNPM.
# Some setups ship a broken corepack shim as `pnpm`; fall back to
# `corepack pnpm`, and as a last resort to the newest node under ~/.nvm.
resolve_pnpm() {
    if pnpm --version &> /dev/null; then
        PNPM="pnpm"
        return
    fi
    if corepack pnpm --version &> /dev/null; then
        PNPM="corepack pnpm"
        return
    fi
    local nvm_dir="$HOME/.nvm/versions/node"
    if [ -d "$nvm_dir" ]; then
        local newest
        newest=$(ls "$nvm_dir" | sort -V | tail -1)
        if [ -n "$newest" ]; then
            export PATH="$nvm_dir/$newest/bin:$PATH"
            if corepack pnpm --version &> /dev/null; then
                PNPM="corepack pnpm"
                print_warning "Using pnpm via corepack from node $newest"
                return
            fi
        fi
    fi
    print_error "pnpm is not available. Install it first: npm install -g pnpm"
    exit 1
}

install_admin_deps() {
    if [ ! -d "$ADMIN_DIR/node_modules" ]; then
        print_step "Installing admin app dependencies..."
        (cd "$ADMIN_DIR" && $PNPM install)
        print_success "Dependencies installed"
    fi
}

# Admin Shell — install/update and regenerate vendor-prefixed via php-scoper.
# Pass "prerelease" to use the release variant of the shell updater when present.
update_shell() {
    local mode=${1:-dev}
    print_step "Check Admin Shell"
    cd "$PLUGIN_DIR"
    mkdir -p "$PLUGIN_DIR/vendor-prefixed/src"
    composer update
    local bin="$PLUGIN_DIR/vendor/bin/yaycommerce-update"
    if [ "$mode" = "prerelease" ] && [ -f "$PLUGIN_DIR/vendor/bin/yaycommerce-prerelease" ]; then
        bin="$PLUGIN_DIR/vendor/bin/yaycommerce-prerelease"
    fi
    if [ -f "$bin" ]; then
        "$bin"
        print_success "Admin Shell updated"
    else
        print_warning "Admin Shell updater not found, skipped"
    fi
}

# ─── Version helpers ────────────────────────────────────────────────────

get_version() {
    local version
    version=$(grep -E "define\( 'YAYLOYALTY_VERSION'" "$MAIN_FILE" | sed -E "s/.*'([0-9]+\.[0-9]+\.[0-9]+[^']*)'.*/\1/")
    if [ -z "$version" ]; then
        print_error "Failed to extract version from $MAIN_FILE"
        exit 1
    fi
    echo "$version"
}

# Plugin release version: X.Y.Z, X.Y.Z.W or with a -tag suffix
validate_version() {
    [[ "$1" =~ ^[0-9]+(\.[0-9]+){2,3}(-[a-zA-Z0-9.]+)?$ ]]
}

# Escape characters that are special on the replacement side of sed
sed_escape() {
    echo "$1" | sed 's/[\/&]/\\&/g'
}

# Update version in main plugin file (header + constant) and readme.txt
update_version() {
    local new_version=$1
    local safe_version
    safe_version=$(sed_escape "$new_version")

    sed_inplace "s/^ \* Version: .*/ \* Version:           $safe_version/" "$MAIN_FILE"
    sed_inplace "s/define( 'YAYLOYALTY_VERSION', '[^']*' )/define( 'YAYLOYALTY_VERSION', '$safe_version' )/" "$MAIN_FILE"

    if [ -f "$PLUGIN_DIR/readme.txt" ]; then
        sed_inplace "s/^Stable tag: .*/Stable tag: $safe_version/" "$PLUGIN_DIR/readme.txt"
    fi

    print_success "Version updated to $new_version"
}

prompt_release_version() {
    local requested=$1
    local current_version
    current_version=$(get_version)

    if [ -n "$requested" ]; then
        update_version "$requested"
        return
    fi

    local new_version
    while true; do
        read -r -p "  Release version [$current_version]: " new_version
        new_version=${new_version:-$current_version}
        validate_version "$new_version" && break
        print_error "Invalid version. Use: X.Y.Z, X.Y.Z.W or X.Y.Z-tag"
    done

    if [ "$new_version" != "$current_version" ]; then
        update_version "$new_version"
    fi
}

# ─── Commands ───────────────────────────────────────────────────────────

cmd_dev() {
    update_shell
    resolve_pnpm
    install_admin_deps

    print_info "Make sure YAYLOYALTY_IS_DEVELOPMENT is true in wp-config.php"
    print_step "Starting Vite dev server (http://localhost:3030)..."
    cd "$ADMIN_DIR"
    $PNPM dev
}

cmd_build() {
    resolve_pnpm
    install_admin_deps

    # package.json build script runs `tsc --noEmit` before vite build
    print_step "Building admin dashboard (typecheck + build)..."
    cd "$ADMIN_DIR"
    $PNPM build
    print_success "Build complete! Output: $PLUGIN_DIR/assets/dist/admin/"
}

cmd_release() {
    local no_prompt=false
    local requested_version=""
    local arg
    for arg in "$@"; do
        case $arg in
            --no-prompt) no_prompt=true ;;
            *) [ -z "$requested_version" ] && requested_version="$arg" ;;
        esac
    done

    if [ -n "$requested_version" ] && ! validate_version "$requested_version"; then
        print_error "Invalid version format: $requested_version"
        echo "Use: X.Y.Z, X.Y.Z.W or X.Y.Z-tag"
        exit 1
    fi

    print_msg "$CYAN" "╔════════════════════════════════════════════╗"
    print_msg "$CYAN" "║       YayLoyalty Release Packager          ║"
    print_msg "$CYAN" "╚════════════════════════════════════════════╝"
    echo ""

    local version
    if [ "$no_prompt" = true ]; then
        version=${requested_version:-$(get_version)}
        [ -n "$requested_version" ] && update_version "$requested_version"
    else
        prompt_release_version "$requested_version"
        version=$(get_version)
    fi

    local zip_name="${PLUGIN_SLUG}-${version}.zip"

    echo ""
    print_info "Version: $version"
    print_info "Output: release/$zip_name"
    echo ""

    update_shell prerelease

    # Regenerate autoload without dev deps for a consistent release
    cd "$PLUGIN_DIR"
    composer dump-autoload --no-dev --classmap-authoritative --quiet

    cmd_build

    # Assemble package in a temp folder named after the plugin slug
    local temp_dir="$RELEASE_DIR/$PLUGIN_SLUG"
    rm -rf "$temp_dir"
    mkdir -p "$temp_dir"

    print_step "Copying plugin files..."
    cp "$MAIN_FILE" "$temp_dir/"
    cp "$PLUGIN_DIR/index.php" "$temp_dir/"
    cp "$PLUGIN_DIR/YayLoyaltyPluginAdapter.php" "$temp_dir/"
    cp "$PLUGIN_DIR/readme.txt" "$temp_dir/"

    cp -r "$PLUGIN_DIR/assets" "$temp_dir/"
    cp -r "$PLUGIN_DIR/includes" "$temp_dir/"
    cp -r "$PLUGIN_DIR/languages" "$temp_dir/"
    cp -r "$PLUGIN_DIR/vendor-prefixed" "$temp_dir/"

    # Composer autoload only (no packages needed at runtime)
    mkdir -p "$temp_dir/vendor"
    cp "$PLUGIN_DIR/vendor/autoload.php" "$temp_dir/vendor/"
    cp -r "$PLUGIN_DIR/vendor/composer" "$temp_dir/vendor/"

    # Strip dev-only artifacts
    rm -f "$temp_dir/includes/Engine/Register/RegisterDev.php"
    rm -rf "$temp_dir/assets/dist/admin/.vite" 2>/dev/null || true
    find "$temp_dir" -name ".DS_Store" -delete 2>/dev/null || true
    find "$temp_dir" -name "*.map" -delete 2>/dev/null || true
    find "$temp_dir" -name ".gitkeep" -delete 2>/dev/null || true

    print_step "Creating ZIP archive..."
    cd "$RELEASE_DIR"
    rm -f "$zip_name"
    zip -r -q "$zip_name" "$PLUGIN_SLUG"
    rm -rf "$temp_dir"

    # Restore dev autoload maps so local development still works
    cd "$PLUGIN_DIR"
    composer dump-autoload --classmap-authoritative --quiet

    local size
    size=$(du -h "$RELEASE_DIR/$zip_name" | cut -f1)

    echo ""
    print_success "Release package created!"
    print_info "Package: $RELEASE_DIR/$zip_name"
    print_info "Size: $size"
}

cmd_bump() {
    local new_version=$1
    if [ -z "$new_version" ]; then
        print_error "Usage: ./scripts/run.sh bump <version>"
        exit 1
    fi
    if ! validate_version "$new_version"; then
        print_error "Invalid version format. Use: X.Y.Z, X.Y.Z.W or X.Y.Z-tag"
        exit 1
    fi
    update_version "$new_version"
}

cmd_info() {
    print_info "Plugin: $PLUGIN_SLUG"
    print_info "Version: $(get_version)"
    print_info "Plugin dir: $PLUGIN_DIR"
}

cmd_clean() {
    print_step "Cleaning build artifacts..."

    if [ -d "$PLUGIN_DIR/assets/dist/admin" ]; then
        rm -rf "$PLUGIN_DIR/assets/dist/admin"
        print_success "Removed assets/dist/admin"
    fi

    read -p "Remove node_modules? (y/N): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]] && [ -d "$ADMIN_DIR/node_modules" ]; then
        rm -rf "$ADMIN_DIR/node_modules"
        print_success "Removed node_modules"
    fi

    print_success "Clean complete"
}

cmd_help() {
    echo "YayLoyalty Dev & Release Script"
    echo ""
    echo "Usage: ./scripts/run.sh [command]"
    echo ""
    echo "Commands:"
    echo "  dev                          Update admin shell + start Vite dev server (:3030)"
    echo "  build                        Build admin dashboard for production"
    echo "  release [version|--no-prompt] Create release ZIP in release/"
    echo "  bump <version>               Bump version in plugin files"
    echo "  info                         Show current version info"
    echo "  clean                        Remove build artifacts"
    echo "  help                         Show this help message"
    echo ""
    echo "Examples:"
    echo "  ./scripts/run.sh dev                    # Dev server with HMR"
    echo "  ./scripts/run.sh release                # Package (prompts version)"
    echo "  ./scripts/run.sh release 1.0.1          # Bump to 1.0.1 and package"
    echo "  ./scripts/run.sh release --no-prompt    # Package current version"
}

# ─── Main ───────────────────────────────────────────────────────────────

main() {
    local cmd=${1:-help}
    shift || true

    case $cmd in
        dev)     cmd_dev "$@" ;;
        build)   cmd_build "$@" ;;
        release) cmd_release "$@" ;;
        bump)    cmd_bump "$@" ;;
        info)    cmd_info "$@" ;;
        clean)   cmd_clean "$@" ;;
        help|*)  cmd_help ;;
    esac
}

main "$@"
