# Revilo visual assets

Prepared on 9 October 2026; homepage banner updated on 10 October 2026. All assets are self-hosted in `public/assets`; the storefront does not need to contact a font or image CDN.

## User-supplied branding

These files are unchanged, byte-for-byte copies of the user's originals. SHA-256 hashes were compared to confirm this.

| Served path | Original | Dimensions | Transparency |
| --- | --- | --- | --- |
| `/assets/brand.png` | `C:/Users/eajde/OneDrive/Bureaublad/Revilo/Brand.png` | 2172 × 724 | Transparent |
| `/assets/hero-warm.png` | `C:/Users/eajde/OneDrive/Bureaublad/Revilo/homepage banner new.png` | 1672 × 941 | Opaque |
| `/assets/icon.png` | `C:/Users/eajde/OneDrive/Bureaublad/Revilo/icon.png` | 1254 × 1254 | Transparent |

The warmer banner has open space on the left for the homepage heading and call to action. Mobile background and readability overlays use matching cream tones. The blue wordmark and leaf/laptop motif establish the brand identity.

## Inter

- Font: `/assets/fonts/InterVariable.woff2` (variable upright font, weight range 100–900).
- Original: [Inter's official GitHub repository](https://github.com/rsms/inter).
- Download: [InterVariable.woff2](https://raw.githubusercontent.com/rsms/inter/master/docs/font-files/InterVariable.woff2).
- License: SIL Open Font License 1.1, included at `/assets/fonts/OFL.txt` from the repository's [LICENSE.txt](https://raw.githubusercontent.com/rsms/inter/master/LICENSE.txt).
- Copyright: 2016 The Inter Project Authors.
- Suggested CSS: `font-family: 'Inter', sans-serif; font-weight: 100 900; font-style: normal; font-display: swap` within a variable-font `@font-face` declaration.

## Demo catalogue model images

These are real, official manufacturer product images for the named models. They are **model reference images**, not photos of Revilo stock or evidence of an individual refurbished unit's condition. Show the product-page label “Model image” / “Modelafbeelding” / “Modellbild”. Unit-photo galleries should remain empty until actual inventory photographs are uploaded.

The original manufacturer copyrights remain with their owners. Manufacturer hosting does not establish an open license for resale photography. These assets support the local demonstration; live catalogue imagery should use Revilo-owned photographs or approved supplier media.

| Model | Served path | Dimensions | Presentation | Official source page |
| --- | --- | --- | --- | --- |
| Dell Latitude 5490 | `/assets/products/dell-latitude-5490.png` | 900 × 371 | Transparent, front view | [Dell Latitude 5490 support](https://www.dell.com/support/product-details/en-us/product/latitude-14-5490-laptop/overview) |
| Dell Latitude 5400 | `/assets/products/dell-latitude-5400.png` | 900 × 351 | Transparent, front view | [Dell Latitude 5400 support](https://www.dell.com/support/product-details/en-us/product/latitude-14-5400-laptop/overview) |
| HP EliteBook 840 G6 | `/assets/products/hp-elitebook-840-g6.png` | 960 × 720 | Transparent, front view | [HP EliteBook 840 G6 Certified Refurbished](https://www.hp.com/us-en/shop/pdp/hp-eb840g6-i7-16gb-256-crtfd-rfrbd-pc) |
| HP ProBook 450 G7 | `/assets/products/hp-probook-450-g7.png` | 318 × 308 | Manufacturer light-grey background, front view | [HP ProBook 450 G7 product card](https://www.hp.com/us-en/laptops/business/probook-400/product-card/hp-probook-450-g7.html) |
| Lenovo ThinkPad T480 | `/assets/products/lenovo-thinkpad-t480.png` | 900 × 639 | Transparent, angled view | [Lenovo ThinkPad T480](https://www.lenovo.com/us/en/p/laptops/thinkpad/thinkpadt/thinkpad-t480/22tp2tt4800) |
| Lenovo ThinkPad T14 Gen 1 Intel | `/assets/products/lenovo-thinkpad-t14.png` | 900 × 639 | Transparent, angled view | [Lenovo ThinkPad T14 Intel](https://www.lenovo.com/fr/fr/p/laptops/thinkpad/thinkpadt/22tpt14t4n1/22tpt14t4n1) |

Downloaded image URLs:

- Dell 5490: <https://i.dell.com/is/image/DellContent//content/dam/ss2/product-images/dell-client-products/notebooks/latitude-notebooks/14-5490/non-touch/notebooks-latitude-14-5490-nt-relsize-500-ng.psd?fmt=png-alpha&wid=900>
- Dell 5400: <https://i.dell.com/is/image/DellContent//content/dam/ss2/product-images/dell-client-products/notebooks/latitude-notebooks/14-5400/global-spi/ng/notebook-latitude-14-5400-nt-relsize-500-ng.psd?fmt=png-alpha&wid=900>
- HP 840 G6: <https://hp.widen.net/content/0pt98zcqae/png/0pt98zcqae.png?color=ffffff00&dpi=72&h=720&w=960>
- HP 450 G7: <https://www.hp.com/content/dam/sites/worldwide/personal-computers/commercial/probook-400/images/Group%201811.png>
- Lenovo T480: <https://p1-ofp.static.pub/medias/bWFzdGVyfHJvb3R8MzQxOTQ4fGltYWdlL3BuZ3xoZmEvaDA4LzE0MzMyNjkwMTM3MTE4LnBuZ3xjMjJjNmIyOGQxNTVkYzRiYThiNDVkNTViMGFiZmY3MDNjYzFkMzIzM2QyYTRhM2U4YTBiMDJmMjQ1NWNlOTVk/lenovo-laptop-thinkpad-t480-hero.png?height=900&width=900>
- Lenovo T14: <https://p4-ofp.static.pub/fes/cms/2023/06/28/wixxf24enffer0cfzar7cg3hdo9aoy356883.png?height=900&width=900>

Images were visually inspected and decoded successfully. Transparent assets work against white cards; use `object-fit: contain` to preserve each model's proportions. The HP ProBook image is lower resolution and should be displayed at approximately 300 CSS pixels wide or less. The Dell 5490 uses the unannotated official image variant, avoiding a manufacturer support thumbnail with an incorrect size overlay.

No generated laptop images or fabricated unit-condition photos are included. ASUS ExpertBook B1 and Acer TravelMate P2 images were omitted in favour of six accurately identified models.
## Google sign-in icon

The Google button uses the unmodified current Google G icon from https://developers.google.com/static/identity/images/g-logo.png, as linked in Google's [sign-in branding guidelines](https://developers.google.com/identity/branding-guidelines). It is used exclusively to identify Google authentication. The rest of the website and button text retain the requested Inter font.
