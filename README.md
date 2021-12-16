Front-end for GHIS/GHDI
=======================

Installation
------------
Adjust Local Settings

- vi .env.local (not commited)

Directory Permissions for cache and logs

- sudo setfacl -R -m u:www-data:rwX ./var
- sudo setfacl -dR -m u:www-data:rwX ./var

Generate `public/css/base.css` and `public/css/print.css`

- ./bin/console scss:compile

Development Notes
-----------------
Project Setup

- composer create-project symfony/website-skeleton:^5.4 ghis-frontend
- Remove ``"symfony/orm-pack": "*"``
- composer require symfony/polyfill-intl-messageformatter
- composer require knplabs/knp-menu-bundle
- composer require gmo/iso-639
- composer require armin/scssphp-bundle
- add to config/packages/scssphp.yml

  scssphp:
    enabled: '%kernel.debug%'
    autoUpdate: '%kernel.debug%'
    assets:
        "css/base.css":
            src: "public/assets/scss/base.scss"
            sourceMap: true

Local Web Server
- cd public
- php -S localhost:8000
- http://localhost:8000/index.php/

Solr
in mycore/conf/solr.xml
  <!-- Only enabled in the "schemaless" data-driven example (assuming the client
       does not know what fields may be searched) because it's very expensive to index everything twice. -->
  <!-- <copyField source="*" dest="_text_"/> -->
  <copyField source="*_s" dest="_text_"/>
  <copyField source="*_ss" dest="_text_"/>
  <copyField source="*_t" dest="_text_"/>

add highlight and suggest for certain fields
  <copyField source="note_t" dest="highlight"/>
  <copyField source="body_t" dest="highlight"/>
  <copyField source="authors_ss" dest="suggest"/>

Translate templates

    ./bin/console translation:update --force de

Site-specific translations

    ./bin/console translation:extract de --dir=./sites/ghdi/templates --output-dir=./sites/ghdi/translations


Terminology
-----------
in translation/messages+intl+icu.de.xlf and data/styles/translation.xml

* Title / Titel
* Abstract / Kurzbeschreibung
* Additional Source(s) / Weitere Quelle(n)
* Further Reading / Weiterführende Inhalte
* Keywords / Schlagworte
