Front-end for GHIS/GHDI
=======================

Code for the Solr/XSLT-based front-end of
    German History in Documents and Images (GHDI)
and
    German History Intersections (GHIS)

License
-------
    Code for the Front-end of
        German History in Documents and Images (GHDI)
    and
        German History Intersections (GHIS)

    (C) 2020-2022 German Historical Institute Washington
        Daniel Burckhardt


    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as
    published by the Free Software Foundation, either version 3 of the
    License, or (at your option) any later version.

    You may run your copy of the code under the logos of GHIS/GHDI.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.

Third Party Code
----------------
This projects builds on numerous third-party projects under a variety of
Open Source Licenses. Please check `composer.json` for these dependencies.

The XSLT-Stylesheets are based on the files from
    https://github.com/haoess/dta-tools/tree/master/stylesheets

Installation
------------
Requirements

- PHP 7.3 or 7.4 (check with `php -v`)
  PHP 8 doesn't work yet (due to "solarium/solarium": "^5.1")
- composer (check with `composer -v`; if it is missing, see https://getcomposer.org/)
- Java 1.8 (for XSLT and Solr, check with `java -version`)

Adjust Local Settings

- vi .env.local (not commited)

Directory Permissions for cache and logs

- sudo setfacl -R -m u:www-data:rwX ./var
- sudo setfacl -dR -m u:www-data:rwX ./var

Generate `public/css/base.css` and `public/css/print.css`

- ./bin/console scss:compile

Development Notes
-----------------

### Local Web Server

- cd public
- php -S localhost:8000
- http://localhost:8000/

### Solr

in {ghdi|ghis}_{de|en}/conf/solr.xml

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

### Translate templates

    ./bin/console translation:update --force de

#### Site-specific translations

    ./bin/console translation:extract de --dir=./sites/ghdi/templates --output-dir=./sites/ghdi/translations

Terminology
-----------
in translation/messages+intl+icu.de.xlf and data/styles/translation.xml

* Title / Titel
* Abstract / Kurzbeschreibung
* Additional Source(s) / Weitere Quelle(n)
* Further Reading / Weiterführende Inhalte
* Keywords / Schlagworte

TODO
----
* Check https://github.com/bestit/flagception-bundle (e.g. for GHDI-Extra)
