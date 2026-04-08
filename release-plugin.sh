#!/bin/bash

# WordPress Plugin Release Script for OpenPanel
# This script automates the process of releasing WordPress plugins to the SVN repository

# =============================================================================
# SETUP AND USAGE GUIDE
# =============================================================================
#
# INITIAL SETUP (First Time Only):
# --------------------------------
# 1. Make this script executable:
#    chmod +x release-plugin.sh
#
# 2. Set up your WordPress.org SVN credentials:
#    - Go to https://wordpress.org/support/users/[your-username]/
#    - Click "Account & Security" section
#    - Generate SVN password (username: openpanel)
#    - IMPORTANT: Use 'openpanel' as username, NOT your email
#
# 3. Initialize SVN repository:
#    ./release-plugin.sh init
#    (This checks out the SVN repo and creates directory structure)
#
# HOW TO RELEASE A NEW VERSION:
# ----------------------------
# 1. Make your code changes in the ./openpanel/ directory
#
# 2. Test your plugin thoroughly
#
# 3. Release new version (example for version 1.0.1):
#    ./release-plugin.sh release 1.0.1
#
#    This will:
#    - Update version numbers in openpanel.php and readme.txt
#    - Copy all files to SVN trunk
#    - Commit changes to trunk
#    - Create a release tag (tags/1.0.1)
#    - Ask for confirmation before each major step
#
# 4. Your plugin will be live at: https://wordpress.org/plugins/openpanel
#    (May take up to 72 hours to appear in search results)
#
# OTHER USEFUL COMMANDS:
# ---------------------
# ./release-plugin.sh status        # Check current versions and SVN status
# ./release-plugin.sh update-trunk  # Update trunk without creating a release
#
# DIRECTORY STRUCTURE:
# -------------------
# Your project should look like this:
# project-root/
# ├── release-plugin.sh     # This script
# ├── openpanel/           # Your plugin files
# │   ├── openpanel.php    # Main plugin file
# │   ├── readme.txt       # WordPress readme
# │   └── ...              # Other plugin files
# ├── assets/              # Plugin assets (banners, icons)
# └── openpanel-svn/       # SVN working directory (created by script)
#
# TROUBLESHOOTING:
# ---------------
# - If SVN asks for credentials, use username 'openpanel' (not email)
# - If 'svn: command not found', install with: brew install subversion
# - If permission denied, make sure script is executable: chmod +x release-plugin.sh
# - For SVN issues, check: https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/
#
# =============================================================================

set -e  # Exit on any error

# Configuration
PLUGIN_SLUG="openpanel"
PLUGIN_NAME="OpenPanel"
SVN_URL="https://openpanel@plugins.svn.wordpress.org/openpanel"
PLUGIN_DIR="./openpanel"
SVN_DIR="./openpanel-svn"
ASSETS_DIR="./assets"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Helper functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Function to display usage
usage() {
    echo "Usage: $0 [COMMAND] [OPTIONS]"
    echo ""
    echo "Commands:"
    echo "  init                    Initial setup - checkout SVN repository"
    echo "  release [VERSION]       Release a new version (e.g., 1.0.1)"
    echo "  update-trunk           Update trunk with latest changes (no release)"
    echo "  status                 Show current status and versions"
    echo "  help                   Show detailed setup and usage guide"
    echo ""
    echo "Examples:"
    echo "  $0 init                 # First time setup"
    echo "  $0 release 1.0.1        # Release version 1.0.1"
    echo "  $0 update-trunk         # Update trunk without releasing"
    echo "  $0 status               # Check current versions"
    echo "  $0 help                 # Show detailed guide"
    exit 1
}

# Function to show detailed help
show_help() {
    cat << 'EOF'

=============================================================================
OpenPanel WordPress Plugin Release Guide
=============================================================================

FIRST TIME SETUP:
-----------------
1. Make this script executable:
   chmod +x release-plugin.sh

2. Set up WordPress.org SVN credentials:
   • Go to your WordPress.org profile → "Account & Security"
   • Generate SVN password
   • Username: openpanel (NOT your email address)

3. Initialize SVN repository:
   ./release-plugin.sh init

RELEASING A NEW VERSION:
-----------------------
1. Make your changes in ./openpanel/ directory
2. Test your plugin thoroughly
3. Run: ./release-plugin.sh release X.Y.Z
   
   Example: ./release-plugin.sh release 1.0.1

   This automatically:
   • Updates version in openpanel.php and readme.txt
   • Copies files to SVN trunk
   • Commits to trunk
   • Creates release tag
   • Publishes to WordPress.org

WORKFLOW EXAMPLE:
----------------
# First time only
./release-plugin.sh init

# For each release
./release-plugin.sh status          # Check current state
./release-plugin.sh release 1.0.1   # Create release
./release-plugin.sh status          # Verify release

DIRECTORY STRUCTURE:
-------------------
project-root/
├── release-plugin.sh     # This script
├── openpanel/           # Your plugin files
├── assets/              # Banners, icons
└── openpanel-svn/       # SVN (auto-created)

IMPORTANT NOTES:
---------------
• Use semantic versioning (1.0.0, 1.0.1, 1.1.0, etc.)
• Always test before releasing
• Plugin appears at: https://wordpress.org/plugins/openpanel
• Search results update within 72 hours
• SVN username is 'openpanel' (case sensitive)

TROUBLESHOOTING:
---------------
• SVN command not found? Install: brew install subversion
• Permission denied? Run: chmod +x release-plugin.sh
• SVN auth issues? Check username is 'openpanel', not email

=============================================================================

EOF
}

# Function to get current version from plugin file
get_current_version() {
    grep "Version:" "$PLUGIN_DIR/openpanel.php" | head -1 | sed 's/.*Version: *\([0-9.]*\).*/\1/'
}

# Function to get stable tag from readme.txt
get_stable_tag() {
    grep "Stable tag:" "$PLUGIN_DIR/readme.txt" | sed 's/.*Stable tag: *\([0-9.]*\).*/\1/'
}

# Function to update version in plugin files
update_version() {
    local new_version=$1
    
    log_info "Updating version to $new_version in plugin files..."
    
    # Update version in main plugin file (header comment)
    sed -i.bak "s/Version: [0-9.]*/Version: $new_version/" "$PLUGIN_DIR/openpanel.php"
    
    # Update version constant in plugin file
    sed -i.bak "s/const VERSION *= *'[0-9.]*'/const VERSION = '$new_version'/" "$PLUGIN_DIR/openpanel.php"
    
    # Update stable tag in readme.txt
    sed -i.bak "s/Stable tag: [0-9.]*/Stable tag: $new_version/" "$PLUGIN_DIR/readme.txt"
    
    # Remove backup files
    rm -f "$PLUGIN_DIR/openpanel.php.bak" "$PLUGIN_DIR/readme.txt.bak"
    
    log_success "Version updated to $new_version"
}

# Function to validate version format
validate_version() {
    local version=$1
    if [[ ! $version =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        log_error "Invalid version format: $version. Use semantic versioning (e.g., 1.0.1)"
        exit 1
    fi
}

# Function to check if SVN directory exists
check_svn_setup() {
    if [ ! -d "$SVN_DIR" ]; then
        log_error "SVN directory not found. Run '$0 init' first."
        exit 1
    fi
}

# Function to initial SVN setup
init_svn() {
    log_info "Setting up SVN repository..."
    
    if [ -d "$SVN_DIR" ]; then
        log_warning "SVN directory already exists. Updating..."
        cd "$SVN_DIR"
        svn update
    else
        log_info "Checking out SVN repository..."
        svn co "$SVN_URL" "$SVN_DIR"
    fi
    
    cd "$SVN_DIR"
    
    # Create directories if they don't exist
    [ ! -d "trunk" ] && svn mkdir trunk
    [ ! -d "tags" ] && svn mkdir tags
    [ ! -d "assets" ] && svn mkdir assets
    
    log_success "SVN repository setup complete"
}

# Function to copy plugin files to SVN
copy_plugin_files() {
    log_info "Copying plugin files to SVN trunk..."
    
    # Ensure trunk directory exists (check from current directory)
    if [ ! -d "trunk" ]; then
        log_error "SVN trunk directory not found. Run '$0 init' first."
        exit 1
    fi
    
    # Clear trunk directory safely
    find "trunk" -mindepth 1 -delete 2>/dev/null || true
    
    # Copy plugin files (need to go back to project root)
    cp -r "../openpanel/"* "trunk/"
    
    # Copy assets if they exist
    if [ -d "../assets" ]; then
        cp "../assets/"* "assets/" 2>/dev/null || true
    fi
    
    # Add new files to SVN (we're already in SVN directory)
    svn add --force trunk/*
    svn add --force assets/* 2>/dev/null || true
    
    log_success "Files copied to SVN"
}

# Function to update trunk only
update_trunk() {
    check_svn_setup
    
    log_info "Updating trunk with latest changes..."
    
    cd "$SVN_DIR"
    svn update
    
    copy_plugin_files
    
    # Check for changes
    if svn status | grep -q "^[AM]"; then
        log_info "Changes detected. Committing to trunk..."
        svn ci -m "Update trunk with latest changes"
        log_success "Trunk updated successfully"
    else
        log_warning "No changes detected in trunk"
    fi
}

# Function to create a release
create_release() {
    local new_version=$1
    
    if [ -z "$new_version" ]; then
        log_error "Version number required for release"
        usage
    fi
    
    validate_version "$new_version"
    check_svn_setup
    
    local current_version=$(get_current_version)
    local stable_tag=$(get_stable_tag)
    
    log_info "Current version: $current_version"
    log_info "Stable tag: $stable_tag"
    log_info "New version: $new_version"
    
    # Ask for confirmation
    echo ""
    read -p "Create release $new_version? [y/N]: " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        log_info "Release cancelled"
        exit 0
    fi
    
    # Update version in files
    update_version "$new_version"
    
    # Update SVN
    cd "$SVN_DIR"
    svn update
    
    copy_plugin_files
    
    # Commit trunk
    log_info "Committing changes to trunk..."
    svn ci --username openpanel -m "Update to version $new_version"
    
    # Create tag
    log_info "Creating release tag $new_version..."
    svn cp trunk "tags/$new_version"
    svn ci --username openpanel -m "Tagging version $new_version"
    
    log_success "Release $new_version created successfully!"
    log_info "Plugin will be available at: https://wordpress.org/plugins/$PLUGIN_SLUG"
    log_warning "Note: It may take up to 72 hours for the new version to appear in search results"
}

# Function to show status
show_status() {
    local current_version=$(get_current_version)
    local stable_tag=$(get_stable_tag)
    
    echo ""
    echo "=== OpenPanel WordPress Plugin Status ==="
    echo "Plugin Directory: $PLUGIN_DIR"
    echo "SVN Directory: $SVN_DIR"
    echo "Current Version: $current_version"
    echo "Stable Tag: $stable_tag"
    echo "SVN URL: $SVN_URL"
    echo "Public URL: https://wordpress.org/plugins/$PLUGIN_SLUG"
    echo ""
    
    if [ -d "$SVN_DIR" ]; then
        log_success "SVN repository is set up"
        cd "$SVN_DIR"
        echo "Available tags:"
        ls tags/ 2>/dev/null | sed 's/^/  - /' || echo "  No tags found"
    else
        log_warning "SVN repository not set up. Run '$0 init' first."
    fi
}

# Main script logic
case "${1:-}" in
    "init")
        init_svn
        ;;
    "release")
        create_release "$2"
        ;;
    "update-trunk")
        update_trunk
        ;;
    "status")
        show_status
        ;;
    "help")
        show_help
        ;;
    *)
        usage
        ;;
esac
