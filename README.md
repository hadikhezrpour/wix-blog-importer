# Wix Blog Importer

A WordPress plugin that migrates all your Wix blog posts into WordPress as drafts — including all images, categories, tags, and post dates.

---

## Installation

1. Copy the entire `wix-importer` folder into your WordPress plugins directory:
   ```
   /wp-content/plugins/wix-importer/
   ```
2. Log in to WordPress Admin → **Plugins** → Activate **Wix Blog Importer**.

---

## How to Use

1. In WordPress Admin, go to **Tools → Wix Blog Importer**.
2. Enter your Wix RSS feed URL. Common formats:
   - `https://www.yourdomain.com/blog-feed.xml`
   - `https://www.yourdomain.com/feed.xml`
   - `https://yourusername.wixsite.com/yoursite/blog-feed.xml`
3. Click **Test Feed** to verify the URL and see a preview of posts.
4. Choose your import options:
   - **Post Status** — Draft (recommended), Published, or Private
   - **Download & Re-host Images** — Downloads all post images into your WP Media Library and rewrites URLs
   - **Set Featured Image** — Uses the first image in each post as the featured image
   - **Import Categories / Tags** — Creates matching taxonomies in WordPress
   - **Skip Already Imported Posts** — Safe to re-run without creating duplicates
5. Click **Start Import** and watch the real-time progress log.
6. When complete, click **View Imported Drafts** to review and publish.

---

## Finding Your Wix Feed URL

### Option A — RSS Feed
Wix automatically generates an RSS feed. Try these URLs in your browser:
- `https://www.yoursite.com/blog-feed.xml`
- `https://www.yoursite.com/blog/feed.xml`
- `https://www.yoursite.com/rss.xml`

### Option B — Wix Blog Feed via API
If your Wix site is connected to a custom domain:
1. Open your Wix blog in a browser
2. In the URL add `/blog-feed.xml` to your domain root
3. You should see raw XML — that's the feed URL to use

---

## What Gets Imported

| Field               | Imported? |
|---------------------|-----------|
| Post title          | ✅        |
| Post content (HTML) | ✅        |
| Post excerpt        | ✅        |
| Post date           | ✅        |
| All images          | ✅ (re-hosted locally) |
| Featured image      | ✅        |
| Categories          | ✅        |
| Tags                | ✅        |
| Post slug/URL       | ✅        |

---

## Notes

- Posts are imported as **Drafts** by default so you can review before publishing.
- Images are downloaded to your WordPress Media Library — Wix CDN links are replaced with your WordPress URLs.
- The plugin processes posts in batches of 5 to avoid PHP timeouts.
- Running the import a second time will skip already-imported posts (if "Skip Already Imported" is checked).
- Feed data is cached for 30 minutes so batches don't re-fetch the full feed repeatedly.

---

## Troubleshooting

**Feed not found / XML parse error**
- Double-check the URL in your browser first. You should see raw XML.
- Make sure your Wix site is published and publicly accessible.
- Try removing `www.` or adding it.

**Images not downloading**
- Your WordPress server needs outbound HTTP access (most do).
- Check that `allow_url_fopen` is enabled in PHP, or that `wp_remote_get` works (it uses cURL as fallback).

**Import times out**
- The batch size is 5 posts. If you have many high-res images, individual batches may be slow. The plugin will auto-resume.
- Increase your PHP `max_execution_time` temporarily if needed (ask your host).

---

## Plugin Version

1.0.0 — Initial release
