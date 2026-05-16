<?php
/**
 * Core importer logic for Wix Blog Importer.
 *
 * Handles:
 *  - Fetching & parsing the Wix RSS/Atom feed (with pagination)
 *  - Downloading remote images into the WP Media Library
 *  - Rewriting image URLs in post content
 *  - Creating WP posts as drafts
 *  - Category / tag assignment
 *  - Duplicate detection
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MWI_Importer {

    /** @var array Runtime options passed in from the AJAX handler */
    private $options = [];

    /** @var array Accumulated log lines */
    private $log = [];

    /** @var int Imported count */
    private $imported = 0;

    /** @var int Skipped count */
    private $skipped = 0;

    /** @var int Error count */
    private $errors = 0;

    // ------------------------------------------------------------------ //
    //  Public API
    // ------------------------------------------------------------------ //

    /**
     * Fetch the feed and return the post list (no import yet).
     * Used by the "Test Feed" AJAX call.
     *
     * @param  string $feed_url
     * @return array  { success, count, titles[], error }
     */
    public function test_feed( $feed_url ) {
        $items = $this->fetch_all_items( $feed_url );

        if ( is_wp_error( $items ) ) {
            return [
                'success' => false,
                'error'   => $items->get_error_message(),
            ];
        }

        $list = [];
        foreach ( $items as $idx => $item ) {
            $list[] = [
                'index' => $idx,
                'title' => (string) $item->title,
                'date'  => $this->get_item_date( $item ) ?: '',
                'url'   => $this->get_item_url( $item ),
            ];
        }

        return [
            'success' => true,
            'count'   => count( $items ),
            'items'   => $list,
        ];
    }

    /**
     * Run the full import.
     *
     * @param  string $feed_url
     * @param  array  $options  {
     *   post_status    string   draft|publish|private
     *   import_images  bool
     *   set_thumbnail  bool
     *   import_cats    bool
     *   import_tags    bool
     *   skip_existing  bool
     *   offset         int      which post to start from (for batching)
     *   batch_size     int      how many to process per request
     * }
     * @return array  { success, imported, skipped, errors, log[], done, total }
     */
    public function import( $feed_url, $options = [] ) {
        $this->options = wp_parse_args( $options, [
            'post_status'      => 'draft',
            'import_images'    => true,
            'set_thumbnail'    => true,
            'import_cats'      => true,
            'import_tags'      => true,
            'skip_existing'    => true,
            'offset'           => 0,
            'batch_size'       => 5,
            'selected_indices' => [], // empty = import all
        ] );

        // Fetch items (cached in transient to avoid re-fetching on every batch)
        $cache_key = 'mwi_feed_' . md5( $feed_url );
        $items     = get_transient( $cache_key );

        if ( false === $items ) {
            $items = $this->fetch_all_items( $feed_url );
            if ( is_wp_error( $items ) ) {
                return [
                    'success' => false,
                    'error'   => $items->get_error_message(),
                ];
            }
            // SimpleXMLElement cannot be serialized — store as XML strings instead.
            $cached = array_map( function( $item ) { return $item->asXML(); }, $items );
            set_transient( $cache_key, $cached, 30 * MINUTE_IN_SECONDS );
        } else {
            // Re-inflate XML strings back into SimpleXMLElement objects.
            // asXML() on a child node omits the parent's namespace declarations,
            // so we wrap each string in a root element that re-declares them.
            $ns_wrap = '<root '
                . 'xmlns:dc="http://purl.org/dc/elements/1.1/" '
                . 'xmlns:content="http://purl.org/rss/1.0/modules/content/" '
                . 'xmlns:atom="http://www.w3.org/2005/Atom" '
                . 'xmlns:media="http://search.yahoo.com/mrss/"'
                . '>';
            $items = array_filter( array_map( function( $xml_str ) use ( $ns_wrap ) {
                libxml_use_internal_errors( true );
                $doc = simplexml_load_string( $ns_wrap . $xml_str . '</root>' );
                libxml_clear_errors();
                if ( ! $doc ) return null;
                $children = $doc->children();
                return isset( $children[0] ) ? $children[0] : null;
            }, $items ) );
        }

        // Filter to only selected indices when a selection is provided
        $selected = array_map( 'intval', (array) $this->options['selected_indices'] );
        if ( ! empty( $selected ) ) {
            $items = array_values( array_filter( $items, function( $item, $idx ) use ( $selected ) {
                return in_array( $idx, $selected, true );
            }, ARRAY_FILTER_USE_BOTH ) );
        }

        $total  = count( $items );
        $offset = (int) $this->options['offset'];
        $batch  = (int) $this->options['batch_size'];
        $slice  = array_slice( $items, $offset, $batch );

        foreach ( $slice as $item ) {
            $this->import_item( $item );
        }

        $processed = $offset + count( $slice );
        $done      = ( $processed >= $total );

        // Clear cache when done
        if ( $done ) {
            delete_transient( $cache_key );
        }

        return [
            'success'   => true,
            'imported'  => $this->imported,
            'skipped'   => $this->skipped,
            'errors'    => $this->errors,
            'log'       => $this->log,
            'done'      => $done,
            'processed' => $processed,
            'total'     => $total,
        ];
    }

    // ------------------------------------------------------------------ //
    //  Feed Fetching
    // ------------------------------------------------------------------ //

    /**
     * Fetch ALL items from a feed, following paging links if present.
     *
     * @param  string $feed_url
     * @return array|WP_Error  Array of SimpleXMLElement items
     */
    private function fetch_all_items( $feed_url ) {
        $all_items  = [];
        $url        = $feed_url;
        $page_limit = 50; // safety cap
        $page       = 0;

        while ( $url && $page < $page_limit ) {
            $response = wp_remote_get( $url, [
                'timeout'    => 30,
                'user-agent' => 'WixImporter/1.0 (+https://mannapottery.com)',
            ] );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( $code !== 200 ) {
                return new WP_Error( 'feed_error', "Feed returned HTTP {$code}" );
            }

            $body = wp_remote_retrieve_body( $response );

            // Suppress XML parse warnings
            libxml_use_internal_errors( true );
            $xml = simplexml_load_string( $body );
            libxml_clear_errors();

            if ( false === $xml ) {
                return new WP_Error( 'xml_parse_error', 'Could not parse feed XML. Please check the URL.' );
            }

            // Detect RSS vs Atom
            $items = $this->extract_items( $xml );
            $all_items = array_merge( $all_items, $items );

            // Try to find a "next" paging link
            $url = $this->get_next_page_url( $xml, $url );
            $page++;
        }

        return $all_items;
    }

    /**
     * Extract item/entry nodes from RSS or Atom feed.
     */
    private function extract_items( SimpleXMLElement $xml ) {
        // RSS 2.0
        if ( isset( $xml->channel->item ) ) {
            return iterator_to_array( $xml->channel->item, false );
        }
        // Atom
        if ( $xml->getName() === 'feed' ) {
            $xml->registerXPathNamespace( 'atom', 'http://www.w3.org/2005/Atom' );
            $entries = $xml->xpath( '//atom:entry' );
            if ( $entries ) {
                return $entries;
            }
            // fallback without namespace
            if ( isset( $xml->entry ) ) {
                return iterator_to_array( $xml->entry, false );
            }
        }
        return [];
    }

    /**
     * Try to find the next page URL from Atom <link rel="next"> or RSS <atom:link>.
     */
    private function get_next_page_url( SimpleXMLElement $xml, $current_url ) {
        // Atom paging
        $xml->registerXPathNamespace( 'atom', 'http://www.w3.org/2005/Atom' );
        $next = $xml->xpath( '//atom:link[@rel="next"]/@href' );
        if ( ! empty( $next ) ) {
            $next_url = (string) $next[0];
            if ( $next_url !== $current_url ) {
                return $next_url;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ //
    //  Item Import
    // ------------------------------------------------------------------ //

    private function import_item( SimpleXMLElement $item ) {
        $title   = $this->get_item_title( $item );
        $url     = $this->get_item_url( $item );
        $excerpt = $this->get_item_excerpt( $item );
        $date    = $this->get_item_date( $item );
        $cats    = $this->get_item_categories( $item );
        $tags    = $this->get_item_tags( $item );
        $slug    = $this->get_item_slug( $item );

        if ( empty( $title ) ) {
            $this->log( 'warn', '⚠️ Skipped item with no title.' );
            $this->skipped++;
            return;
        }

        // Duplicate check
        if ( $this->options['skip_existing'] && $this->post_exists( $title ) ) {
            $this->log( 'skip', "⏭️ Skipped (already exists): <em>{$title}</em>" );
            $this->skipped++;
            return;
        }

        // Fetch full content from the article page; fall back to feed content
        $content = '';
        if ( $url ) {
            $content = $this->fetch_article_content( $url );
            if ( empty( $content ) ) {
                $this->log( 'warn', "⚠️ <em>{$title}</em>: could not extract content from <code>{$url}</code> — no matching selector found. Using feed summary." );
                $content = $this->get_item_content( $item );
            }
        } else {
            $this->log( 'warn', "⚠️ <em>{$title}</em>: no article URL in feed — using feed summary." );
            $content = $this->get_item_content( $item );
        }

        // Download & rewrite images; seed thumbnail from <enclosure> if no inline image found
        $thumbnail_id = 0;
        if ( $this->options['import_images'] ) {
            [ $content, $thumbnail_id ] = $this->process_images( $content, $title );
            if ( $thumbnail_id === 0 ) {
                $enclosure_url = $this->get_item_enclosure_image( $item );
                if ( $enclosure_url ) {
                    $this->log( 'info', "🖼️ <em>{$title}</em>: no inline images found — trying enclosure image." );
                    $thumbnail_id = $this->sideload_image( $enclosure_url, $title );
                    if ( $thumbnail_id === 0 ) {
                        $this->log( 'warn', "⚠️ <em>{$title}</em>: enclosure image also failed." );
                    }
                } else {
                    $this->log( 'warn', "⚠️ <em>{$title}</em>: no images found in content or enclosure." );
                }
            }
        }

        // Build post array
        $post_data = [
            'post_title'   => wp_strip_all_tags( $title ),
            'post_content' => $content,
            'post_excerpt' => wp_strip_all_tags( $excerpt ),
            'post_status'  => $this->options['post_status'],
            'post_type'    => 'post',
            'post_name'    => $slug,
        ];

        if ( $date ) {
            $post_data['post_date']     = $date;
            $post_data['post_date_gmt'] = get_gmt_from_date( $date );
        }

        $post_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $post_id ) ) {
            $this->log( 'error', "❌ Error importing <em>{$title}</em>: " . $post_id->get_error_message() );
            $this->errors++;
            return;
        }

        // Taxonomy
        if ( $this->options['import_cats'] && ! empty( $cats ) ) {
            $this->assign_terms( $post_id, $cats, 'category' );
        }
        if ( $this->options['import_tags'] && ! empty( $tags ) ) {
            $this->assign_terms( $post_id, $tags, 'post_tag' );
        }

        // Featured image
        if ( $this->options['set_thumbnail'] && $thumbnail_id > 0 ) {
            set_post_thumbnail( $post_id, $thumbnail_id );
        }

        $this->log( 'success', "✅ Imported: <em>{$title}</em> (Post #{$post_id})" );
        $this->imported++;
    }

    // ------------------------------------------------------------------ //
    //  Field Extractors
    // ------------------------------------------------------------------ //

    private function get_item_title( $item ) {
        return isset( $item->title ) ? (string) $item->title : '';
    }

    private function get_item_content( $item ) {
        // RSS: <content:encoded>
        $content_ns = $item->children( 'http://purl.org/rss/1.0/modules/content/' );
        if ( isset( $content_ns->encoded ) ) {
            return (string) $content_ns->encoded;
        }
        // Atom: <content>
        if ( isset( $item->content ) ) {
            return (string) $item->content;
        }
        // RSS fallback: <description>
        if ( isset( $item->description ) ) {
            return (string) $item->description;
        }
        return '';
    }

    private function get_item_excerpt( $item ) {
        // RSS: <description> (short)
        $dc = $item->children( 'http://purl.org/dc/elements/1.1/' );
        if ( isset( $item->description ) ) {
            $desc = (string) $item->description;
            // If it looks short (no HTML tags or < 300 chars), use as excerpt
            if ( strlen( strip_tags( $desc ) ) < 300 ) {
                return strip_tags( $desc );
            }
        }
        // Atom: <summary>
        if ( isset( $item->summary ) ) {
            return (string) $item->summary;
        }
        return '';
    }

    private function get_item_date( $item ) {
        $raw = '';
        if ( isset( $item->pubDate ) ) {
            $raw = (string) $item->pubDate;
        } elseif ( isset( $item->published ) ) {
            $raw = (string) $item->published;
        } elseif ( isset( $item->updated ) ) {
            $raw = (string) $item->updated;
        }
        if ( $raw ) {
            $ts = strtotime( $raw );
            if ( $ts ) {
                return date( 'Y-m-d H:i:s', $ts );
            }
        }
        return null;
    }

    private function get_item_categories( $item ) {
        $cats = [];
        // RSS <category> (non-tag ones)
        foreach ( $item->category as $cat ) {
            $domain = (string) $cat->attributes()['domain'];
            $val    = trim( (string) $cat );
            if ( $val && ( empty( $domain ) || strpos( $domain, 'cat' ) !== false ) ) {
                $cats[] = $val;
            }
        }
        return array_unique( $cats );
    }

    private function get_item_tags( $item ) {
        $tags = [];
        $media_ns = $item->children( 'http://search.yahoo.com/mrss/' );
        // Wix often puts tags in <media:keywords>
        if ( isset( $media_ns->keywords ) ) {
            $kw = (string) $media_ns->keywords;
            foreach ( explode( ',', $kw ) as $tag ) {
                $tag = trim( $tag );
                if ( $tag ) {
                    $tags[] = $tag;
                }
            }
        }
        return array_unique( $tags );
    }

    private function get_item_slug( $item ) {
        // Atom: <id> or <link>
        if ( isset( $item->link ) ) {
            $link = (string) $item->link;
            // Check for href attribute (Atom)
            $attrs = $item->link->attributes();
            if ( isset( $attrs['href'] ) ) {
                $link = (string) $attrs['href'];
            }
            if ( $link ) {
                return sanitize_title( basename( rtrim( parse_url( $link, PHP_URL_PATH ), '/' ) ) );
            }
        }
        // RSS <link>
        if ( isset( $item->link ) ) {
            $link = trim( (string) $item->link );
            if ( $link ) {
                return sanitize_title( basename( rtrim( parse_url( $link, PHP_URL_PATH ), '/' ) ) );
            }
        }
        return '';
    }

    private function get_item_url( $item ) {
        if ( isset( $item->link ) ) {
            // Atom uses href attribute
            $attrs = $item->link->attributes();
            if ( isset( $attrs['href'] ) ) {
                return (string) $attrs['href'];
            }
            $link = trim( (string) $item->link );
            if ( $link && filter_var( $link, FILTER_VALIDATE_URL ) ) {
                return $link;
            }
        }
        return '';
    }

    private function get_item_enclosure_image( $item ) {
        if ( isset( $item->enclosure ) ) {
            $attrs = $item->enclosure->attributes();
            $url   = (string) ( $attrs['url'] ?? '' );
            $type  = (string) ( $attrs['type'] ?? '' );
            if ( $url && strpos( $type, 'image' ) !== false ) {
                return $url;
            }
        }
        return '';
    }

    /**
     * Fetch the full article HTML and extract the main content block.
     * Tries a series of Wix-specific and general CSS-class selectors.
     *
     * @param  string $url  Full article URL
     * @return string       Inner HTML of the content node, or '' on failure
     */
    private function fetch_article_content( $url ) {
        $response = wp_remote_get( $url, [
            'timeout'    => 30,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return '';
        }

        $html = wp_remote_retrieve_body( $response );
        if ( empty( $html ) ) {
            return '';
        }

        libxml_use_internal_errors( true );
        $dom = new DOMDocument( '1.0', 'UTF-8' );
        $dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ) );
        libxml_clear_errors();

        $xpath = new DOMXPath( $dom );

        // Ordered from most-specific (Wix) to most-general
        $queries = [
            '//*[@data-hook="post-description"]',
            '//*[@data-hook="post-content"]',
            '//*[contains(@class,"blog-post-content")]',
            '//*[contains(@class,"post-content")]',
            '//*[contains(@class,"wixui-rich-text")]',
            '//article',
        ];

        foreach ( $queries as $query ) {
            $nodes = $xpath->query( $query );
            if ( ! $nodes || $nodes->length === 0 ) {
                continue;
            }
            $inner = '';
            foreach ( $nodes as $node ) {
                $inner .= $dom->saveHTML( $node );
            }
            // Only accept if there is meaningful text (not just nav/empty divs)
            if ( strlen( strip_tags( $inner ) ) > 100 ) {
                return $this->clean_content( $inner );
            }
        }

        return '';
    }

    /**
     * Strip everything except clean semantic HTML.
     * Removes div/span wrappers, all class/style/id attributes, and any
     * non-content tags. Text inside stripped tags is preserved.
     */
    private function clean_content( $html ) {
        $allowed = [
            'p'          => [],
            'br'         => [],
            'h1'         => [],
            'h2'         => [],
            'h3'         => [],
            'h4'         => [],
            'h5'         => [],
            'h6'         => [],
            'strong'     => [],
            'b'          => [],
            'em'         => [],
            'i'          => [],
            'u'          => [],
            'blockquote' => [],
            'pre'        => [],
            'code'       => [],
            'ul'         => [],
            'ol'         => [],
            'li'         => [],
            'table'      => [],
            'thead'      => [],
            'tbody'      => [],
            'tfoot'      => [],
            'tr'         => [],
            'th'         => [ 'colspan' => [], 'rowspan' => [] ],
            'td'         => [ 'colspan' => [], 'rowspan' => [] ],
            'a'          => [ 'href' => [], 'title' => [], 'target' => [] ],
            'img'        => [ 'src' => [], 'alt' => [], 'width' => [], 'height' => [] ],
        ];

        return wp_kses( $html, $allowed );
    }

    // ------------------------------------------------------------------ //
    //  Image Processing
    // ------------------------------------------------------------------ //

    /**
     * Find all <img> tags in content, download each image, replace src with
     * the new local WP URL. Returns [ $new_content, $first_attachment_id ].
     */
    private function process_images( $content, $post_title ) {
        if ( ! function_exists( 'media_handle_sideload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $thumbnail_id = 0;

        // Match all img src attributes (handles single and double quotes)
        preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches, PREG_SET_ORDER );

        $img_found   = 0;
        $img_ok      = 0;
        $img_skipped = 0;

        foreach ( $matches as $match ) {
            $original_tag = $match[0];
            $src          = $match[1];

            // Skip data URIs and already-local images
            if ( strpos( $src, 'data:' ) === 0 ) {
                $img_skipped++;
                continue;
            }
            if ( strpos( $src, home_url() ) === 0 ) {
                $img_skipped++;
                continue;
            }

            $img_found++;
            $attachment_id = $this->sideload_image( $src, $post_title );
            $new_url       = $attachment_id > 0 ? wp_get_attachment_url( $attachment_id ) : false;

            if ( $new_url ) {
                $img_ok++;
                $new_tag = preg_replace( '/src=["\'][^"\']+["\']/i', 'src="' . esc_url( $new_url ) . '"', $original_tag );
                $content = str_replace( $original_tag, $new_tag, $content );

                if ( $thumbnail_id === 0 ) {
                    $thumbnail_id = $attachment_id;
                }
            } else {
                $this->log( 'warn', "⚠️ Image not saved (attachment missing after sideload): <code>{$src}</code>" );
            }
        }

        if ( $img_found > 0 ) {
            $img_fail = $img_found - $img_ok;
            $msg      = "🖼️ <em>{$post_title}</em>: {$img_found} image(s) found — {$img_ok} imported";
            if ( $img_fail > 0 ) {
                $msg .= ", <strong>{$img_fail} failed</strong>";
            }
            $this->log( $img_fail > 0 ? 'warn' : 'info', $msg . '.' );
        } elseif ( $img_skipped === 0 ) {
            $this->log( 'info', "🖼️ <em>{$post_title}</em>: no &lt;img&gt; tags found in content." );
        }

        // Also handle Wix-style background images in style attributes
        preg_match_all( '/background-image:\s*url\(["\']?([^"\')\s]+)["\']?\)/i', $content, $bg_matches, PREG_SET_ORDER );
        foreach ( $bg_matches as $match ) {
            $src = $match[1];
            if ( strpos( $src, 'data:' ) === 0 || strpos( $src, home_url() ) === 0 ) {
                continue;
            }
            $attachment_id = $this->sideload_image( $src, $post_title );
            if ( $attachment_id > 0 ) {
                $new_url = wp_get_attachment_url( $attachment_id );
                $content = str_replace( $match[0], 'background-image: url("' . esc_url( $new_url ) . '")', $content );
                if ( $thumbnail_id === 0 ) {
                    $thumbnail_id = $attachment_id;
                }
            }
        }

        return [ $content, $thumbnail_id ];
    }

    /**
     * Download a remote image and add it to the WP media library.
     * Returns the attachment ID or 0 on failure.
     */
    /**
     * Wix serves images through transformation URLs that produce small/low-quality output.
     * Upgrade to the original full-resolution source URL before downloading.
     *
     * Handles two patterns:
     *   1. …/media/{id}.ext/v1/fill/w_N,h_N,…/file.ext  → …/media/{id}.ext
     *   2. …/media/{transformed_id}/{original_id}.ext     → …/media/{original_id}.ext
     */
    private function upgrade_wix_image_url( $url ) {
        if ( strpos( $url, 'static.wixstatic.com/media/' ) === false ) {
            return $url;
        }

        // Pattern 1: strip /v1/fill/… or /v1/fit/… suffix
        if ( preg_match( '#^(https://static\.wixstatic\.com/media/[^\s/?]+\.(jpe?g|png|gif|webp|svg))/v1/.+$#i', $url, $m ) ) {
            return $m[1];
        }

        // Pattern 2: last path segment is the original filename
        if ( preg_match( '#^https://static\.wixstatic\.com/media/[^/]+/([^\s/]+\.(jpe?g|png|gif|webp|svg))$#i', $url, $m ) ) {
            return 'https://static.wixstatic.com/media/' . $m[1];
        }

        return $url;
    }

    private function sideload_image( $url, $post_title = '' ) {
        $url = $this->upgrade_wix_image_url( $url );

        // Check cache to avoid re-downloading the same URL
        $cache_key = 'mwi_img_' . md5( $url );
        $cached_id = get_transient( $cache_key );
        if ( false !== $cached_id ) {
            // Verify the attachment still exists in the media library
            if ( wp_get_attachment_url( (int) $cached_id ) ) {
                return (int) $cached_id;
            }
            // Attachment was deleted — clear stale cache and re-download
            delete_transient( $cache_key );
            $this->log( 'warn', "⚠️ Cached attachment #{$cached_id} no longer exists — re-downloading." );
        }

        // Build a clean filename from the URL
        $filename = basename( parse_url( $url, PHP_URL_PATH ) );
        if ( empty( $filename ) || ! preg_match( '/\.(jpe?g|png|gif|webp|svg)$/i', $filename ) ) {
            $filename = sanitize_title( $post_title ) . '-' . substr( md5( $url ), 0, 6 ) . '.jpg';
        }

        // Download to temp file
        $tmp = download_url( $url, 30 );
        if ( is_wp_error( $tmp ) ) {
            $this->log( 'warn', "⚠️ Download failed: <code>{$url}</code> — " . $tmp->get_error_message() );
            return 0;
        }

        $file_array = [
            'name'     => $filename,
            'tmp_name' => $tmp,
        ];

        $id = media_handle_sideload( $file_array, 0, $post_title );

        // Clean up temp file on error
        if ( is_wp_error( $id ) ) {
            @unlink( $tmp );
            $this->log( 'warn', "⚠️ Sideload failed: <code>{$filename}</code> — " . $id->get_error_message() );
            return 0;
        }

        set_transient( $cache_key, $id, DAY_IN_SECONDS );
        return $id;
    }

    // ------------------------------------------------------------------ //
    //  Taxonomy
    // ------------------------------------------------------------------ //

    private function assign_terms( $post_id, $terms, $taxonomy ) {
        $term_ids = [];
        foreach ( $terms as $term_name ) {
            $term = term_exists( $term_name, $taxonomy );
            if ( ! $term ) {
                $term = wp_insert_term( $term_name, $taxonomy );
            }
            if ( ! is_wp_error( $term ) && isset( $term['term_id'] ) ) {
                $term_ids[] = (int) $term['term_id'];
            }
        }
        if ( ! empty( $term_ids ) ) {
            wp_set_post_terms( $post_id, $term_ids, $taxonomy, true );
        }
    }

    // ------------------------------------------------------------------ //
    //  Helpers
    // ------------------------------------------------------------------ //

    private function post_exists( $title ) {
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = 'post' LIMIT 1",
            $title
        ) );
        return ! empty( $exists );
    }

    private function log( $type, $message ) {
        $this->log[] = [ 'type' => $type, 'msg' => $message ];
    }
}
