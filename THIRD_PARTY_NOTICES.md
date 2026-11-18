# Third-party notices

The Aloha Social server itself is licensed under AGPL-3.0-or-later; see
[LICENSE](LICENSE). This file records the separate licenses of assets that are
distributed with the server.

## Bundled fonts

The files in `public/ui/brand/fonts/` are redistributed unmodified under the
SIL Open Font License, Version 1.1:

| Asset | Project | License |
| --- | --- | --- |
| `Fredoka-Variable.woff2` | [Fredoka](https://github.com/hafontia/Fredoka) | SIL OFL 1.1 |
| `PublicSans-Variable.woff2`, `PublicSans-Italic.woff2` | [Public Sans](https://github.com/public-sans/public-sans) | SIL OFL 1.1 |
| `IBMPlexMono-Regular.woff2`, `IBMPlexMono-Medium.woff2`, `IBMPlexMono-SemiBold.woff2` | [IBM Plex](https://github.com/IBM/plex) | SIL OFL 1.1 |

The full SIL Open Font License text is available at
<https://openfontlicense.org/open-font-license-official-text/>. Font names are
reserved by their authors; the bundled files are not modified.

## Brand mark

`public/ui/brand/aloha-mark.svg` is an original Aloha Social asset and is
covered by this repository's AGPL-3.0-or-later license.

## PHP and JavaScript dependencies

Composer and npm dependencies retain their own licenses. Run
`composer licenses` and inspect `ui/package-lock.json` for the dependency
inventory of a particular build.
