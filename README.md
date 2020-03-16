Front-end for GHIS
==================

Installation
------------
Adjust Local Settings

- vi .env.local (not commited)

Directory Permissions for cache and logs

- sudo setfacl -R -m u:www-data:rwX ./var
- sudo setfacl -dR -m u:www-data:rwX ./var


Development Notes
-----------------
Project Setup

- composer create-project symfony/website-skeleton:^4.4 ghis-frontend
- Remove ``"symfony/orm-pack": "*"``
- composer require symfony/polyfill-intl-messageformatter
- composer require nelmio/solarium-bundle
- composer require armin/scssphp-bundle
- composer require knplabs/knp-menu-bundle
- composer require gmo/iso-639

- add to config/packages/scssphp.yml

  scssphp:
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

TODO: maybe add highlight for certain fields
  <!--
  <copyField source="description_s" dest="highlight"/>
  <copyField source="text_t" dest="highlight"/>
  -->


Translate templates

    ./bin/console translation:update --force de
