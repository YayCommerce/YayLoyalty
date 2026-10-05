#!/bin/bash
#
# YayLoyalty dev, build and release script.
# Usage: ./scripts/run.sh <command>. `./scripts/run.sh help` lists the commands.
#

set -e

# Paths

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

# Output helpers

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

# In-place sed edit that works with both GNU and BSD sed.
sed_inplace() {
    local pattern=$1
    local file=$2
    if sed --version 2>&1 | grep -q GNU; then
        sed -i "$pattern" "$file"
    else
        sed -i '' "$pattern" "$file"
    fi
}

# Toolchain helpers

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

# Install or update admin-shell and regenerate vendor-prefixed/ through php-scoper.
# "prerelease" uses the shell's release updater when it has one.
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

# Version helpers

get_version() {
    local version
    version=$(grep -E "define\( 'YAYLOYALTY_VERSION'" "$MAIN_FILE" | sed -E "s/.*'([0-9]+\.[0-9]+\.[0-9]+[^']*)'.*/\1/")
    if [ -z "$version" ]; then
        print_error "Failed to extract version from $MAIN_FILE"
        exit 1
    fi
    echo "$version"
}

validate_build_type() {
    case $1 in
        pro|lite) ;;
        *)
            print_error "Unknown build type: '$1' (expected 'pro' or 'lite')"
            exit 1
            ;;
    esac
}

# Accept free/premium, the spelling of the sibling plugins' scripts, as aliases.
# Everything inside this plugin, BUILD_TYPE included, says lite/pro.
resolve_edition_alias() {
    case "$1" in
        free)    echo "lite" ;;
        premium) echo "pro" ;;
        *)       echo "$1" ;;
    esac
}

# A release version: X.Y.Z or X.Y.Z.W, with an optional -tag suffix.
validate_version() {
    [[ "$1" =~ ^[0-9]+(\.[0-9]+){2,3}(-[a-zA-Z0-9.]+)?$ ]]
}

# Escape the characters that are special on the replacement side of sed.
sed_escape() {
    echo "$1" | sed 's/[\/&]/\\&/g'
}

# Write the version into the plugin header, the YAYLOYALTY_VERSION define and readme.txt.
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

# Commands

cmd_dev() {
    local build_type
    build_type=$(resolve_edition_alias "${1:-pro}")
    validate_build_type "$build_type"

    update_shell
    resolve_pnpm
    install_admin_deps

    print_info "Make sure YAYLOYALTY_IS_DEVELOPMENT is true in wp-config.php"
    cd "$ADMIN_DIR"
    # The dev server only serves the admin SPA; build the launcher's CSS once so a fresh checkout's
    # storefront does not 404 it. Run 'pnpm dev:storefront' alongside to rebuild it on change.
    print_step "Building storefront launcher CSS..."
    $PNPM build:storefront
    print_step "Starting Vite dev server ($build_type: http://localhost:3030)..."
    # vite.config.ts hands BUILD_TYPE to the client as import.meta.env.VITE_IS_PRO, read through
    # IS_PRO_BUILD in src/lib/is-pro-build.ts. A Vite `define` doesn't substitute reliably in the dev server.
    BUILD_TYPE="$build_type" $PNPM dev
}

cmd_build() {
    local build_type
    build_type=$(resolve_edition_alias "${1:-pro}")
    validate_build_type "$build_type"

    resolve_pnpm
    install_admin_deps

    # The build script type-checks first. BUILD_TYPE is the whole SPA side of the split: Rollup inlines
    # IS_PRO_BUILD (src/lib/is-pro-build.ts) as a literal and drops the dead branch with everything only it reaches.
    # The same build then compiles the storefront launcher's CSS, one file for both editions.
    print_step "Building admin dashboard ($build_type: typecheck + build) and storefront CSS..."
    cd "$ADMIN_DIR"
    BUILD_TYPE="$build_type" $PNPM build
    print_success "Build complete! Output: $PLUGIN_DIR/assets/dist/admin/"
}

cmd_release() {
    local build_type="pro"
    local no_prompt=false
    local requested_version=""
    local arg
    for arg in "$@"; do
        case $arg in
            pro|lite|free|premium) build_type=$(resolve_edition_alias "$arg") ;;
            --no-prompt)           no_prompt=true ;;
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

    # The version is written to the tree here, once, so a Lite release run with --no-prompt after Pro
    # gets the same version.
    local version
    if [ "$no_prompt" = true ]; then
        version=${requested_version:-$(get_version)}
        [ -n "$requested_version" ] && update_version "$requested_version"
    else
        prompt_release_version "$requested_version"
        version=$(get_version)
    fi

    build_package "$build_type" "$version"
}

# Assemble, package and validate one build type. Pro and Lite share every step, so the two packages
# can't drift apart; Lite only adds deleting includes/Pro/ from the staged copy.
build_package() {
    local build_type=$1
    local version=$2
    validate_build_type "$build_type"

    local zip_name="${PLUGIN_SLUG}-${version}.zip"
    if [ "$build_type" = "lite" ]; then
        zip_name="${PLUGIN_SLUG}-lite-${version}.zip"
    fi

    echo ""
    print_info "Build type: $build_type"
    print_info "Version: $version"
    print_info "Output: release/$zip_name"
    echo ""

    update_shell prerelease

    # Autoload without dev packages. Safe before the Pro strip: the classmap covers only vendor-prefixed/src
    # and the adapter, and plugin classes load through yayloyalty.php's file_exists-guarded autoloader.
    cd "$PLUGIN_DIR"
    composer dump-autoload --no-dev --classmap-authoritative --quiet

    cmd_build "$build_type"

    # Both build types use the slug as the folder name, so upgrading Lite to Pro replaces the directory in place.
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

    # Only Composer's autoloader ships; no package is needed at runtime.
    mkdir -p "$temp_dir/vendor"
    cp "$PLUGIN_DIR/vendor/autoload.php" "$temp_dir/vendor/"
    cp -r "$PLUGIN_DIR/vendor/composer" "$temp_dir/vendor/"

    # The Lite strip, inside $temp_dir, never $PLUGIN_DIR. apps/ never ships, so the SPA's Pro code is
    # kept out by BUILD_TYPE=lite alone, which validate-build.php's sentinel check verifies.
    if [ "$build_type" = "lite" ]; then
        print_step "Stripping Pro code..."
        rm -rf "$temp_dir/includes/Pro"
    fi

    # Strip dev-only files. validate-build.php's dev-artifact check mirrors this list.
    rm -f "$temp_dir/includes/Engine/Register/RegisterDev.php"
    rm -rf "$temp_dir/assets/dist/admin/.vite" 2>/dev/null || true
    find "$temp_dir" -name ".DS_Store" -delete 2>/dev/null || true
    find "$temp_dir" -name "*.map" -delete 2>/dev/null || true
    find "$temp_dir" -name ".gitkeep" -delete 2>/dev/null || true

    write_manifest "$temp_dir" "$version" "$build_type"

    print_step "Creating ZIP archive..."
    cd "$RELEASE_DIR"
    rm -f "$zip_name"
    zip -r -q "$zip_name" "$PLUGIN_SLUG"
    rm -rf "$temp_dir"

    # Restore the dev autoload maps for local development.
    cd "$PLUGIN_DIR"
    composer dump-autoload --classmap-authoritative --quiet

    # A failed validation ends the release: a strip that misses a file fails silently, so a warning would be ignored.
    print_step "Validating package..."
    if ! php "$SCRIPT_DIR/validate-build.php" "$RELEASE_DIR/$zip_name" "$build_type"; then
        mv "$RELEASE_DIR/$zip_name" "$RELEASE_DIR/$zip_name.invalid"
        print_error "Validation failed — release aborted"
        print_info "Rejected package kept for inspection: release/$zip_name.invalid"
        exit 1
    fi

    local size
    size=$(du -h "$RELEASE_DIR/$zip_name" | cut -f1)

    echo ""
    print_success "Release package created!"
    print_info "Package: $RELEASE_DIR/$zip_name"
    print_info "Size: $size"
}

# Write release-manifest.json, the SHA256 of every packaged file. validate-build.php checks it both
# ways, so a file that survives a strip gets noticed.
write_manifest() {
    local dir=$1
    local version=$2
    local build_type=$3
    local manifest="$dir/release-manifest.json"

    print_step "Writing release manifest..."
    rm -f "$manifest"

    {
        echo "{"
        echo "  \"plugin\": \"$PLUGIN_SLUG\","
        echo "  \"version\": \"$version\","
        echo "  \"buildType\": \"$build_type\","
        echo "  \"generatedAt\": \"$(date -u +%Y-%m-%dT%H:%M:%SZ)\","
        echo "  \"files\": {"

        local first=true line hash rel
        while IFS= read -r line; do
            hash=${line%% *}
            rel=${line#*  }
            rel=${rel#./}
            if [ "$first" = true ]; then
                first=false
            else
                echo ","
            fi
            printf '    "%s": "%s"' "$rel" "$hash"
        # The manifest skips itself: the redirection truncates it before find runs, so its hash would be
        # an empty file's. validate-build.php skips it too.
        done < <(cd "$dir" && find . -type f ! -name "release-manifest.json" | sort | tr '\n' '\0' | xargs -0 shasum -a 256)

        echo ""
        echo "  }"
        echo "}"
    } > "$manifest"
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

    if [ -d "$PLUGIN_DIR/assets/dist/storefront" ]; then
        rm -rf "$PLUGIN_DIR/assets/dist/storefront"
        print_success "Removed assets/dist/storefront"
    fi

    read -p "Remove node_modules? (y/N): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]] && [ -d "$ADMIN_DIR/node_modules" ]; then
        rm -rf "$ADMIN_DIR/node_modules"
        print_success "Removed node_modules"
    fi

    print_success "Clean complete"
}

# Lists docs/backlog/: open items with their unticked boxes, then items whose boxes are all ticked.
cmd_backlog() {
    local dir="$PLUGIN_DIR/docs/backlog"
    local files=()
    local f

    for f in "$dir"/*.md; do
        if [ -e "$f" ]; then
            files+=("$f")
        fi
    done

    if [ ${#files[@]} -eq 0 ]; then
        print_info "Backlog empty"
        return 0
    fi

    local done_list=()
    local summary title ticked total

    for f in "${files[@]}"; do
        # One line "ticked<TAB>total<TAB>title", then the unticked boxes under ## Done when.
        summary=$(awk '
            /^# / && title == "" { title = substr($0, 3) }
            /^## / { in_done = ($0 == "## Done when") ; next }
            in_done && /^- \[[xX]\] / { ticked++; total++ }
            in_done && /^- \[ \] / { total++; open[total] = substr($0, 7) }
            END {
                printf "%d\t%d\t%s\n", ticked, total, title
                for (i = 1; i <= total; i++) if (i in open) print open[i]
            }
        ' "$f")
        ticked=$(printf '%s\n' "$summary" | head -1 | cut -f1)
        total=$(printf '%s\n' "$summary" | head -1 | cut -f2)
        title=$(printf '%s\n' "$summary" | head -1 | cut -f3-)

        if [ "$total" -gt 0 ] && [ "$ticked" -eq "$total" ]; then
            done_list+=("$(basename "$f")  $title")
            continue
        fi

        print_msg "$BLUE" "$(basename "$f")  [$ticked/$total]"
        echo "  $title"
        printf '%s\n' "$summary" | tail -n +2 | while IFS= read -r line; do
            if [ -n "$line" ]; then
                echo "    - [ ] $line"
            fi
        done
    done

    for f in "${done_list[@]+"${done_list[@]}"}"; do
        print_success "$f  (done, remove)"
    done
}

cmd_help() {
    echo "YayLoyalty Dev & Release Script"
    echo ""
    echo "Usage: ./scripts/run.sh [command]"
    echo ""
    echo "Commands:"
    echo "  dev [pro|lite]               Start Vite dev server with HMR (:3030) (default: pro)"
    echo "  build [pro|lite]             Build admin dashboard (default: pro)"
    echo "  release [pro|lite] [version] Create release ZIP in release/ (default: pro)"
    echo "  bump <version>               Bump version in plugin files"
    echo "  info                         Show current version info"
    echo "  clean                        Remove build artifacts"
    echo "  backlog                      List docs/backlog/ items and their open boxes"
    echo "  help                         Show this help message"
    echo ""
    echo "'dev'/'build'/'release' also accept 'free' and 'premium' as aliases"
    echo "for 'lite' and 'pro' (yay-reviews' spelling) — same effect either way."
    echo ""
    echo "Examples:"
    echo "  ./scripts/run.sh dev                     # Dev server, Pro"
    echo "  ./scripts/run.sh dev lite                # Dev server, Lite"
    echo "  ./scripts/run.sh dev free                # Same as 'dev lite'"
    echo "  ./scripts/run.sh release                 # Pro package (prompts version)"
    echo "  ./scripts/run.sh release 1.0.1           # Bump to 1.0.1 and package Pro"
    echo "  ./scripts/run.sh release lite --no-prompt # Lite package at the current version"
    echo ""
    echo "Releasing both: Pro first (it sets the version), then Lite with"
    echo "--no-prompt so both packages carry the same number."
    echo "Release validation and its env vars: see the header of scripts/validate-build.php."
}

# Main

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
        backlog) cmd_backlog "$@" ;;
        help|*)  cmd_help ;;
    esac
}

main "$@"
