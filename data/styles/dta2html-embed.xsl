<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
  xmlns:tei="http://www.tei-c.org/ns/1.0"
  exclude-result-prefixes="tei"
  version="2.0">

  <xsl:import href="dta2html.xsl"/>

  <xsl:output method="xml" doctype-system=""/>

  <!-- main match including source description -->
  <xsl:template match="/">
    <body>
      <xsl:if test="$titleplacement and /tei:TEI/tei:teiHeader/tei:fileDesc/tei:titleStmt/tei:title">
        <h2><xsl:apply-templates select="/tei:TEI/tei:teiHeader/tei:fileDesc/tei:titleStmt/tei:title/node()" /></h2>
      </xsl:if>

      <xsl:apply-templates/>

      <xsl:if test="/tei:TEI/tei:teiHeader/tei:fileDesc/tei:sourceDesc/tei:bibl">
        <div class="source-citation">
        <xsl:for-each select="/tei:TEI/tei:teiHeader/tei:fileDesc/tei:sourceDesc/tei:bibl">
          <p><xsl:apply-templates select="./node()"/></p>
        </xsl:for-each>
        </div>
      </xsl:if>

      <xsl:choose>
        <xsl:when test="/tei:TEI/tei:teiHeader/tei:fileDesc/tei:publicationStmt/tei:availability/tei:licence">
          <div class="license">
            <xsl:if test="/tei:TEI/tei:teiHeader/tei:fileDesc/tei:publicationStmt/tei:availability/tei:licence/@target">
              <xsl:attribute name="data-target"><xsl:value-of select="/tei:TEI/tei:teiHeader/tei:fileDesc/tei:publicationStmt/tei:availability/tei:licence/@target" /></xsl:attribute>
            </xsl:if>
            <xsl:apply-templates select="/tei:TEI/tei:teiHeader/tei:fileDesc/tei:publicationStmt/tei:availability/tei:licence" />
          </div>
        </xsl:when>
        <xsl:otherwise>
          <xsl:if test="/tei:TEI/tei:teiHeader/tei:fileDesc/tei:publicationStmt/tei:availability">
            <div class="license">
              <xsl:apply-templates select="/tei:TEI/tei:teiHeader/tei:fileDesc/tei:publicationStmt/tei:availability" />
            </div>
          </xsl:if>
        </xsl:otherwise>
      </xsl:choose>
    </body>
  </xsl:template>

</xsl:stylesheet>
