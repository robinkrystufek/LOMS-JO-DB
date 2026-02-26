[![CC BY-NC-ND 4.0][cc-by-nc-nd-shield]][cc-by-nc-nd]

# [LOMS Judd–Ofelt Parameters Database][LOMSDB]

This repository hosts the interactive online database of experimentally reported Judd–Ofelt (JO) intensity parameters. The platform aggregates literature-derived Ω₂, Ω₄, and Ω₆ values together with compositional, spectroscopic, and publication metadata. It is designed to support validation, benchmarking, and meta-analysis of rare-earth optical materials.

The database integrates tightly with the LOMS Judd–Ofelt analysis suite, enabling cross-validation of calculated results against published datasets.

## Features

- Structured storage of JO parameters (Ω₂, Ω₄, Ω₆) with units and uncertainty notes
- Detailed composition handling (component-wise mol%, wt%, at%)
- Rare-earth ion concentration range support
- DOI-based publication metadata lookup and integration
- Advanced search and filtering:
  - Component-based filtering
  - Concentration range queries
  - Data quality and availability indicators (e.g., σFS, MD correction, RME inclusion)
- Multiple export formats:
  - CSV data export
  - Citation export (APA / BibTeX / RIS)
- LOMS JO file import & recalculation file support
- Firebase-based contributor authentication and review workflow

The system is built using a PHP + MySQL backend with a modular JavaScript front-end interface optimized for interactive filtering and structured data export.

For the live demo, visit the LOMS [database website][LOMSDB].

## Credits

LOMS Judd–Ofelt Parameters Database was created by J. Hrabovsky, P. Varak, and R. Krystufek, and improved by a growing list of contributors. Following libraries and their dependencies were used:

- CookieConsent v3 — https://github.com/orestbida/cookieconsent/blob/master/LICENSE
- Firebase JavaScript SDK — https://github.com/firebase/firebase-js-sdk
- Boxicons — https://github.com/atisawd/boxicons
- Font Awesome — https://github.com/FortAwesome/Font-Awesome

This project builds upon literature datasets contributed by the rare-earth spectroscopy community. These contributions are gratefully acknowledged, with full citation information provided for each entry. Component entries are cross-checked against the [refractiveindex.info][refractive-index-info] database, maintained by Mikhail Polyanskiy, and provide direct links to the corresponding records.

## License

This work is licensed under a [Creative Commons Attribution-NonCommercial-NoDerivatives 4.0 International License][cc-by-nc-nd]. See the [LICENSE][licence] file for more information.

[LOMSDB]: https://www.loms.cz/jo-db/
[licence]: https://github.com/robinkrystufek/LOMS-JO/blob/main/LICENSE
[cc-by-nc-nd]: https://creativecommons.org/licenses/by-nc-nd/4.0/
[cc-by-nc-nd-image]: https://licensebuttons.net/l/by-nc-nd/4.0/88x31.png
[cc-by-nc-nd-shield]: https://img.shields.io/badge/License-CC%20BY--NC--ND%204.0-lightgrey.svg
[refractive-index-info]: https://github.com/polyanskiy/refractiveindex.info-database
