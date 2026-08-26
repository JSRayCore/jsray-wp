# WordPress.org assets

These are the plugin directory's assets, not the plugin's. They are uploaded to
the `assets/` folder of the WordPress.org SVN repository, which sits beside
`trunk/` rather than inside it — so none of this ships in the installable zip,
and `tools/build-plugin.sh` does not copy it.

| File | Where it appears |
|---|---|
| `banner-772x250.png` | Header of the plugin page |
| `banner-1544x500.png` | The same, on high-density displays |
| `icon-128x128.png` | Search results and the plugin card |
| `icon-256x256.png` | The same, on high-density displays |
| `screenshot-1.png` | Screenshots tab, in the order readme.txt lists them |
| `screenshot-2.png` | " |

The numbering is the contract: `screenshot-N.png` pairs with the Nth line under
`== Screenshots ==` in readme.txt. Adding a file without adding its line leaves
an unlabelled image on the page; `npm run check:versions` fails on a mismatch.

Banners are rendered from `banner.html` sources kept out of the repository —
regenerate by composing the lockup from `jsray-logo-hero-dark.svg` on `#1C1C1E`
with JSRay's own token colours. Icons are the brand mark scaled down.
