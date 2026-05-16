<?php
/**
 * Admin page for Wix Blog Importer.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MWI_Admin {

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function register_menu() {
        add_management_page(
            'Wix Blog Importer',
            'Wix Blog Importer',
            'manage_options',
            'wix-importer',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'tools_page_wix-importer' ) {
            return;
        }
        wp_enqueue_style(
            'mwi-admin-style',
            MWI_PLUGIN_URL . 'assets/admin.css',
            [],
            MWI_VERSION
        );
        wp_enqueue_script(
            'mwi-admin-script',
            MWI_PLUGIN_URL . 'assets/admin.js',
            [ 'jquery' ],
            MWI_VERSION,
            true
        );
        wp_localize_script( 'mwi-admin-script', 'MWI', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'mwi_import_nonce' ),
        ] );
    }

    public function render_page() {
        // Handle feed save / delete (standard form POST)
        if ( isset( $_POST['mwi_feeds_nonce'] ) && wp_verify_nonce( $_POST['mwi_feeds_nonce'], 'mwi_manage_feeds' ) && current_user_can( 'manage_options' ) ) {
            $feeds = get_option( 'mwi_saved_feeds', [] );

            if ( ! empty( $_POST['mwi_add_name'] ) && ! empty( $_POST['mwi_add_url'] ) ) {
                $feeds[] = [
                    'name' => sanitize_text_field( $_POST['mwi_add_name'] ),
                    'url'  => esc_url_raw( $_POST['mwi_add_url'] ),
                ];
                update_option( 'mwi_saved_feeds', $feeds );
            }

            if ( isset( $_POST['mwi_delete_index'] ) && is_numeric( $_POST['mwi_delete_index'] ) ) {
                $idx = (int) $_POST['mwi_delete_index'];
                array_splice( $feeds, $idx, 1 );
                update_option( 'mwi_saved_feeds', $feeds );
            }
        }

        $saved_feeds = get_option( 'mwi_saved_feeds', [] );
        ?>
        <div class="wrap mwi-wrap">
            <div class="mwi-header">
                <h1>🏺 Wix Blog Importer</h1>
                <p class="mwi-subtitle">Import your Wix blog posts into WordPress as drafts — content, images, categories, and tags included.</p>
            </div>

            <?php if ( ! empty( $saved_feeds ) ) : ?>
            <div class="mwi-card">
                <h2>Saved Feeds</h2>
                <table class="mwi-options-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="text-align:left;">Name</th>
                            <th style="text-align:left;">Feed URL</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $saved_feeds as $i => $feed ) : ?>
                        <tr>
                            <td><?php echo esc_html( $feed['name'] ); ?></td>
                            <td><code style="font-size:12px;"><?php echo esc_html( $feed['url'] ); ?></code></td>
                            <td style="white-space:nowrap;">
                                <button type="button"
                                    class="button button-primary mwi-load-feed"
                                    data-url="<?php echo esc_attr( $feed['url'] ); ?>">
                                    Load
                                </button>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this feed?');">
                                    <?php wp_nonce_field( 'mwi_manage_feeds', 'mwi_feeds_nonce' ); ?>
                                    <input type="hidden" name="mwi_delete_index" value="<?php echo (int) $i; ?>" />
                                    <button type="submit" class="button button-link-delete">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div class="mwi-card">
                <h2>Step 1 — Enter your Wix Feed URL</h2>
                <p>Your Wix RSS feed is usually at: <code>https://www.yoursite.com/blog-feed.xml</code><br>
                   You can also try <code>https://www.yoursite.com/feed.xml</code> or the Wix native feed path.</p>

                <div class="mwi-field-row">
                    <input
                        type="url"
                        id="mwi-feed-url"
                        placeholder="https://www.mannapottery.com/blog-feed.xml"
                        class="mwi-input"
                    />
                    <button id="mwi-test-feed" class="button button-secondary">Test Feed</button>
                </div>
                <div id="mwi-feed-status" class="mwi-status-box" style="display:none;"></div>

                <details style="margin-top:16px;">
                    <summary style="cursor:pointer;color:#2271b1;font-weight:600;">Save this feed for later…</summary>
                    <form method="post" style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <?php wp_nonce_field( 'mwi_manage_feeds', 'mwi_feeds_nonce' ); ?>
                        <input type="text"  name="mwi_add_name" placeholder="Feed name (e.g. Main Blog)" class="regular-text" required />
                        <input type="url"   name="mwi_add_url"  id="mwi-save-url" placeholder="Feed URL" class="regular-text" required />
                        <button type="submit" class="button button-secondary">Save Feed</button>
                    </form>
                </details>
            </div>

            <div class="mwi-card" id="mwi-selection-card" style="display:none;">
                <h2>Step 2 — Select Posts to Import</h2>
                <p>
                    <label>
                        <input type="checkbox" id="mwi-select-all" checked />
                        <strong>Select / deselect all</strong>
                    </label>
                    &nbsp;&nbsp;<span id="mwi-sel-count"></span>
                </p>
                <div id="mwi-selection-list" class="mwi-selection-list"></div>
                <div class="mwi-actions" style="margin-top:12px;">
                    <button id="mwi-confirm-selection" class="button button-primary" disabled>
                        Continue with selected →
                    </button>
                </div>
            </div>

            <div class="mwi-card" id="mwi-options-card" style="display:none;">
                <h2>Step 3 — Import Options</h2>

                <table class="mwi-options-table">
                    <tr>
                        <td><label for="mwi-post-status"><strong>Post Status</strong></label></td>
                        <td>
                            <select id="mwi-post-status">
                                <option value="draft" selected>Draft (recommended)</option>
                                <option value="publish">Published</option>
                                <option value="private">Private</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td><label for="mwi-import-images"><strong>Download & Re-host Images</strong></label></td>
                        <td>
                            <input type="checkbox" id="mwi-import-images" checked />
                            <span class="mwi-hint">Downloads all images to your WordPress media library and rewrites URLs.</span>
                        </td>
                    </tr>
                    <tr>
                        <td><label for="mwi-set-thumbnail"><strong>Set Featured Image</strong></label></td>
                        <td>
                            <input type="checkbox" id="mwi-set-thumbnail" checked />
                            <span class="mwi-hint">Uses the first image in each post as the featured image.</span>
                        </td>
                    </tr>
                    <tr>
                        <td><label for="mwi-import-cats"><strong>Import Categories</strong></label></td>
                        <td>
                            <input type="checkbox" id="mwi-import-cats" checked />
                        </td>
                    </tr>
                    <tr>
                        <td><label for="mwi-import-tags"><strong>Import Tags</strong></label></td>
                        <td>
                            <input type="checkbox" id="mwi-import-tags" checked />
                        </td>
                    </tr>
                    <tr>
                        <td><label for="mwi-skip-existing"><strong>Skip Already Imported Posts</strong></label></td>
                        <td>
                            <input type="checkbox" id="mwi-skip-existing" checked />
                            <span class="mwi-hint">Skips posts that have already been imported (matched by title).</span>
                        </td>
                    </tr>
                </table>

                <div class="mwi-actions">
                    <button id="mwi-start-import" class="button button-primary button-large">
                        🚀 Start Import
                    </button>
                    <span id="mwi-post-count" class="mwi-post-count"></span>
                </div>
            </div>

            <div class="mwi-card" id="mwi-progress-card" style="display:none;">
                <h2>Import Progress</h2>
                <div class="mwi-progress-bar-wrap">
                    <div class="mwi-progress-bar" id="mwi-progress-bar" style="width:0%"></div>
                </div>
                <p id="mwi-progress-label">0 / 0 posts imported</p>
                <div id="mwi-log" class="mwi-log"></div>
            </div>

            <div class="mwi-card mwi-summary-card" id="mwi-summary-card" style="display:none;">
                <h2>✅ Import Complete</h2>
                <div id="mwi-summary-content"></div>
                <a href="<?php echo esc_url( admin_url( 'edit.php?post_status=draft&post_type=post' ) ); ?>" class="button button-primary">
                    View Imported Drafts →
                </a>
            </div>
        </div>
        <?php
    }
}
